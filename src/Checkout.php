<?php

namespace Sellissimo\Core;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class Checkout
{
    public function __construct(private Store $store, private Pricing $pricing, private Registry $providers) {}

    public function begin(object $cart, array $input, ?string $customer, ?callable $verifyQuote = null): object
    {
        app(StockHolds::class)->expireDue();
        $order = $this->store->db()->transaction(function () use ($cart, $input, $customer, $verifyQuote) {
            $owner = $cart->customer;
            $cart = $this->store->table('carts')->where('id', $cart->id)->lockForUpdate()->firstOrFail();
            abort_unless($cart->customer === $owner, 409, 'This cart has moved to a customer account.');
            if ($existing = $this->store->table('orders')->where('cart_id', $cart->id)->first()) {
                return $existing;
            }
            abort_if($customer === null && !StoreSettings::forSection($cart->site, 'checkout', 'guest_checkout', config('sellissimo.guest_checkout', true)), 401, __('Sign in before placing an order.'));
            $cart->customer = $customer;
            $receipt = $this->pricing->calculate($cart, $input, true, placingOrder: true);
            if (($cart->retry_order_id ?? null) || ($cart->currency_review ?? false)) {
                abort_unless(isset($input['quote_token']), 409, __('sellissimo::shop.retry_review'));
            }
            if (array_key_exists('order_fields', $input)) {
                $this->store->table('carts')->where('id', $cart->id)->update(['order_fields' => json_encode($input['order_fields'], JSON_THROW_ON_ERROR)]);
            }
            if (isset($input['quote_token'])) {
                abort_unless(hash_equals(Pricing::fingerprint($receipt), $input['quote_token']), 409, __('sellissimo::shop.your_cart_price_or_promotion_changed_review_your_updated_total_before_placing_the_order'));
            }
            if ($verifyQuote) {
                $verifyQuote($receipt);
            }
            $due = $receipt['amount_due'] ?? $receipt['total'];
            $voucher = $receipt['voucher'] ?? null;
            $gateway = $due === 0 ? ($voucher ? 'voucher' : 'free') : $input['gateway'];
            if ($gateway === 'pay_on_delivery') {
                abort_unless(($receipt['shipping']['pay_on_delivery'] ?? false) === true, 422, __('sellissimo::shop.this_shipping_service_does_not_collect_payment_on_delivery'));
            }
            abort_unless($due === 0 || in_array($gateway, $this->providers->availablePayments($receipt, $cart->site), true), 422, __('sellissimo::shop.select_an_enabled_payment_gateway'));
            $id = (string) Str::uuid();
            if ($voucher) {
                app(GiftVouchers::class)->change($voucher['id'], 'hold:'.$id, 'hold', -$voucher['amount'], $id);
                unset($receipt['voucher']['id']);
            }
            $lines = collect($receipt['lines'])->sortBy('sku');
            $covered = app(StockHolds::class)->assignDraft($cart, $receipt['lines'], $id, $customer);
            foreach ($lines as $line) {
                if (! Inventory::tracked($line)) {
                    continue;
                }
                $sku = $line['sku'];
                $held = min($covered[$sku] ?? 0, $line['qty']);
                $covered[$sku] = ($covered[$sku] ?? 0) - $held;
                if ($held < $line['qty']) {
                    app(Inventory::class)->change($sku, $held - $line['qty'], 'Order reserved', $customer, $id);
                }
            }
            unset($receipt['delivery_ready'], $receipt['missing_shipping_fields'], $receipt['options'], $receipt['billing_fields'], $receipt['missing_billing_fields'], $receipt['stock_shortages']);
            if ($identity = StoreIdentity::snapshot($cart->site)) {
                $receipt['store_identity'] = $identity;
            }
            $receipt['access_days'] = (int) StoreSettings::forSection($cart->site, 'orders', 'access_days', config('sellissimo.access_days', 7));
            $receipt['return_url'] = URL::temporarySignedRoute('sellissimo.receipt', now()->addDay(), ['order' => $id]);
            $this->store->table('orders')->insert([
                'id' => $id, 'payment_start_state' => 'ready', 'order_number' => OrderNumber::allocate($this->store), 'cart_id' => $cart->id, 'site' => $cart->site, 'customer' => $customer,
                'coupon_code' => $receipt['code'] === '' ? null : $receipt['code'],
                'coupon_account_email_hash' => $receipt['code'] === '' ? null : CouponRedemptions::accountEmailHash($customer),
                'coupon_email_hash' => $receipt['code'] === '' ? null : CouponRedemptions::emailHash($input['email']),
                'voucher_id' => $voucher['id'] ?? null, 'voucher_amount' => $voucher['amount'] ?? 0, 'original_cash_amount' => $voucher ? $due : null,
                'email' => $input['email'], 'currency' => $cart->currency, 'total' => $receipt['total'],
                'receipt' => json_encode($receipt, JSON_THROW_ON_ERROR), 'gateway' => $gateway,
                'acceptance_notice_pending' => $gateway === 'pay_on_delivery',
                'status' => $gateway === 'pay_on_delivery' ? 'awaiting_collection' : 'pending', 'created_at' => now(),
            ]);

            $order = $this->order($id);
            app(Webhooks::class)->activity('order.placed', $id, ['gateway' => $gateway, 'payment_status' => $order->status], $order);
            app(OrderQueueFields::class)->refresh($id);
            Search\StoreRecords::changed("order/$id");
            return $order;
        }, 3);

        if ($order->acceptance_notice_pending) {
            $this->store->db()->afterCommit(function () use ($order) {
                try {
                    app(Receipts::class)->sendAcceptance($order->id);
                } catch (\Throwable $error) {
                    report($error);
                }
            });
        }
        if ($order->status !== 'pending' || $order->gateway === 'historical') {
            return $order;
        }
        if (in_array($order->gateway, ['free', 'voucher'], true)) {
            return $this->applyOutcome($order->id, ['status' => 'paid', 'amount' => 0, 'currency' => $order->currency, 'reference' => $order->gateway]);
        }
        if (! $order->session_id) {
            try {
                $this->startSession($order);
            } catch (Contracts\PaymentUnavailable $e) {
                // The provider may have accepted the request. The reconciler retries this same order.
                report($e);
            }
        }

        return $this->order($order->id);
    }

    public function order(string $id): object
    {
        return $this->store->table('orders')->where('id', $id)->firstOrFail();
    }

    private function startSession(object $order): void
    {
        $session = app(PaymentSessions::class)->start('orders', GiftVouchers::cashPayment($order));
        if ($session === null) {
            return;
        }
        if (($session['status'] ?? null) === 'expired') {
            $this->applyOutcome($order->id, $session);

            return;
        }
        $this->store->table('orders')->where('id', $order->id)->whereNull('session_id')->update([
            'session_id' => $session['id'], 'checkout_url' => $session['url'],
        ]);
    }

    public function reconcile(string $id): object
    {
        $order = $this->order($id);
        if ($order->status !== 'pending' || $order->gateway === 'historical') {
            return $order;
        }
        if (in_array($order->gateway, ['free', 'voucher'], true)) {
            return $this->applyOutcome($id, ['status' => 'paid', 'amount' => 0, 'currency' => $order->currency, 'reference' => $order->gateway]);
        }
        if (! $order->session_id) {
            $this->startSession($order);
            $order = $this->order($id);
            if ($order->status !== 'pending' || !$order->session_id) {
                return $order;
            }
        }

        return $this->applyOutcome($id, $this->providers->gateway($order->gateway)->outcome(GiftVouchers::cashPayment($order)));
    }

    public function settleDelivery(string $id, bool $collect): object
    {
        return $this->store->db()->transaction(function () use ($id, $collect) {
            $order = $this->store->table('orders')->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_unless($order->gateway === 'pay_on_delivery', 422);
            $status = $collect ? 'paid' : 'expired';
            if ($order->status === $status) {
                return $order;
            }
            abort_unless($order->status === 'awaiting_collection' && ($collect || (!$order->tracking && !app(CarrierLabels::class)->active($id))), 409, 'Only unshipped, unpaid orders can be cancelled.');
            $this->store->table('orders')->where('id', $id)->update(['status' => 'pending']);

            return $this->applyOutcome($id, ['status' => $status, 'amount' => (int) GiftVouchers::cashPayment($order)->total, 'currency' => $order->currency, 'reference' => $collect ? 'pay_on_delivery' : null]);
        }, 3);
    }

    private function applyOutcome(string $id, array $result): object
    {
        return $this->store->db()->transaction(function () use ($id, $result) {
            $order = $this->store->table('orders')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'pending' || $result['status'] === 'pending') {
                return $order;
            }
            $rejected = !empty($result['request_rejected']);
            if ($rejected && !PaymentSessions::canReleaseRejected($order)) {
                return $order;
            }
            if ($result['status'] === 'expired') {
                if ($order->voucher_id) {
                    app(GiftVouchers::class)->change($order->voucher_id, 'release:'.$id, 'release', (int) $order->voucher_amount, $id);
                }
                $physical = collect(json_decode($order->receipt, true)['lines'])->filter(fn ($line) => Inventory::tracked($line))->groupBy('sku')->sortKeys();
                foreach ($physical as $sku => $lines) {
                    app(Inventory::class)->release($id, (string)$sku, $lines->sum('qty'));
                }
            } else {
                throw_unless($result['status'] === 'paid' && (int) GiftVouchers::cashPayment($order)->total === $result['amount'] && $order->currency === $result['currency'], 'Payment does not match order.');
            }
            $this->store->table('orders')->where('id', $id)->update(['status' => $result['status'], 'paid_at' => $result['status'] === 'paid' ? now() : null, 'payment_ref' => $result['reference'] ?? null,
                ...($rejected ? ['payment_start_state' => 'rejected'] : [])]);
            $message = $result['status'] === 'expired' ? 'Checkout expired.' : ($order->gateway === 'free' ? 'Order completed without payment.' : 'Payment confirmed.');
            $this->store->recordActivity($id, 'payment', $rejected ? 'The payment provider declined to open checkout. Reservations released.' : $message);
            if ($result['status'] === 'paid') {
                if ($order->voucher_id) {
                    app(GiftVouchers::class)->change($order->voucher_id, 'redeem:'.$id, 'redeem', 0, $id);
                }
                app(GiftVouchers::class)->purchase($order, json_decode($order->receipt, true));
                app(Webhooks::class)->enqueue('order.paid', $order, $id);
                $this->store->db()->afterCommit(fn () => event('sellissimo.order.paid', [$id]));
            }

            Search\StoreRecords::changed("order/$id");
            return $this->order($id);
        }, 3);
    }

    public static function link(object $order): string
    {
        return OrderLinks::signed($order, 'sellissimo.receipt', now()->addDays(\Sellissimo\Core\OperationalSettings::accessDays($order)), ['order' => $order->id]);
    }
}
