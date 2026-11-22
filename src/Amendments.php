<?php

namespace Sellissimo\Core;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class Amendments
{
    public function __construct(private Store $store, private Pricing $pricing, private Registry $providers) {}

    public function pending(string $id): ?object
    {
        return $this->store->table('amendments')->where('order_id', $id)->whereIn('status', ['awaiting_payment', 'awaiting_refund'])->first();
    }

    public function version(object $order): string
    {
        return hash('sha256', $order->receipt.'|'.$order->delivery_override);
    }

    public function propose(object $order, array $data, bool $deliveryPreview = false): array
    {
        abort_unless($order->status === 'paid' && $order->tracking === null && ! app(CarrierLabels::class)->active($order->id)
            && ! $this->pending($order->id), 409, 'Only a paid, undispatched order without an open amendment or carrier label can be edited.');
        abort_if($this->store->table('refunds')->where('order_id', $order->id)->whereNull('amendment_id')->whereIn('status', ['pending', 'succeeded'])->exists(), 409, 'This order already has a separate refund.');
        abort_unless(hash_equals($this->version($order), $data['version']), 409, 'The order changed. Reopen it before editing.');
        $before = json_decode($order->receipt, true, flags: JSON_THROW_ON_ERROR);
        $original = collect($before['lines'])->keyBy(fn ($line) => $line['key'] ?? $line['sku']);
        $cart = $this->store->table('carts')->where('id', $order->cart_id)->first() ?? (object) ['id' => $order->cart_id, 'site' => $order->site, 'currency' => $order->currency, 'customer' => $order->customer];
        $cart->customer = $order->customer;
        $stored = json_decode($cart->items ?? '{}', true);
        $items = [];
        foreach ($data['items'] as $row) {
            $sku = strtoupper($row['sku']);
            $key = $row['key'] ?? '';
            $old = $original->get($key);
            if ($key !== '') {
                abort_unless($old, 422, 'Unknown original order line.');
            }
            $same = $old && $old['sku'] === $sku;
            $options = $row['options'] ?? ($same ? array_column($old['options'] ?? [], 'value', 'handle') : []);
            $fields = $row['line_fields'] ?? ($same ? ($stored[$key]['line_fields'] ?? []) : []);
            $originalOptions = $same ? array_column($old['options'] ?? [], 'value', 'handle') : [];
            $originalFields = $same ? ($stored[$key]['line_fields'] ?? []) : [];
            $unchanged = $same && $options == $originalOptions && $fields == $originalFields;
            ksort($options);
            ksort($fields);
            $newKey = $unchanged ? $key : LineOptions::key($sku, $options, $fields);
            abort_if(isset($items[$newKey]), 422, 'Combine matching lines before continuing.');
            $items[$newKey] = ['sku' => $sku, 'qty' => (int) $row['quantity'], 'options' => $options, 'line_fields' => $fields, ...($same && is_array($stored[$key] ?? null) ? \Illuminate\Support\Arr::only($stored[$key], ['staff_price', 'service']) : [])];
        }
        $cart->items = json_encode($items, JSON_THROW_ON_ERROR);
        $input = ['email' => $order->email, 'address' => $before['address'], 'shipping_address' => $data['shipping_address'] ?? DeliveryAddress::current($order, $before),
            'shipping' => $data['shipping'] ?? ($before['shipping_method'] ?? ''), 'discount' => $data['coupon'] ?? ($before['code'] ?? ''),
            'shipping_instructions' => $before['shipping_instructions'] ?? null];
        if (($before['shipping_method'] ?? '') === 'draft.custom' && isset($before['shipping'])) {
            $input['custom_shipping'] = ['label' => $before['shipping']['label'], 'amount' => $before['shipping']['original_amount'] ?? $before['shipping']['amount']];
            if (isset($before['shipping']['tax']) && ! isset($before['shipping']['original_amount'])) {
                $settings = app(TaxSettings::class)->forSite($order->site);
                if (! in_array($settings['shipping_class'] ?? '', app(PricingRules::class)->inclusiveClasses(), true)) {
                    $input['custom_shipping']['amount'] -= $before['shipping']['tax'];
                }
            }
        }
        $after = $this->pricing->calculate($cart, $input, ! $deliveryPreview, amendment: $order);
        $vouchers = fn ($receipt) => collect($receipt['lines'])->where('voucher_product', true)
            ->mapWithKeys(fn ($line) => [$line['key'] => [$line['qty'], $line['price']]])->sortKeys()->all();
        abort_unless($vouchers($before) === $vouchers($after), 422, __('Issued voucher products must be refunded through the original order.'));
        if (isset($before['voucher'])) {
            $after['voucher'] = $before['voucher'];
            $after['amount_due'] = max(0, $after['total'] - $after['voucher']['amount']);
        }
        if ($deliveryPreview) {
            return $after;
        }
        unset($after['options'], $after['billing_fields'], $after['missing_billing_fields'], $after['missing_shipping_fields'], $after['delivery_ready'], $after['stock_shortages']);

        return [$after, (int) $after['total'] - (int) $before['total'], $items];
    }

    public function balanceGateways(array $quote, int $difference, string $site): array
    {
        if ($difference <= 0) {
            return [];
        }
        unset($quote['amount_due'], $quote['voucher']);
        $available = $this->providers->availablePayments([...$quote, 'total' => $difference], $site);
        return array_values(array_diff($available, ['pay_on_delivery']));
    }

    public function save(string $orderId, array $data, string $actor): object
    {
        $id = $data['request_id'];
        $row = $this->store->db()->transaction(function () use ($orderId, $data, $actor, $id) {
            $order = $this->store->table('orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
            $requestHash = hash('sha256', serialize(Arr::sortRecursive($data)));
            if ($existing = $this->store->table('amendments')->where('id', $id)->first()) {
                abort_unless($existing->order_id === $orderId && hash_equals($existing->request_hash, $requestHash), 409);

                return $existing;
            }
            [$after,$difference,$items] = $this->propose($order, $data);
            abort_unless(hash_equals(Pricing::fingerprint($after), $data['digest']), 409, 'The amendment price changed. Review the new balance.');
            $gateway = $data['gateway'] ?? $order->gateway;
            if ($difference > 0) {
                abort_unless(in_array($gateway, $this->balanceGateways($after, $difference, $order->site), true), 422, 'Choose an online gateway to collect the balance.');
            }
            $before = json_decode($order->receipt, true);
            // Keep the exact new cart fields for subsequent amendments, including private upload IDs.
            $after['_cart_items'] = $items;
            foreach ($this->changes($before, $after) as $sku => $delta) {
                if ($delta > 0) {
                    app(Inventory::class)->change($sku, -$delta, 'Amendment reserved', $actor, $orderId);
                }
            }
            $this->store->table('amendments')->insert(['id' => $id, 'payment_start_state' => 'ready', 'order_id' => $orderId, 'status' => $difference < 0 ? 'awaiting_refund' : 'awaiting_payment',
                'previous' => $order->receipt, 'proposed' => json_encode($after, JSON_THROW_ON_ERROR), 'difference' => $difference, 'gateway' => $gateway,
                'request_hash' => $requestHash, 'author' => $actor, 'reason' => $data['reason'], 'created_at' => now()]);
            $row = $this->row($id);
            $this->store->recordActivity($orderId, 'amendment', 'Order amendment opened. '.$data['reason'], $actor);
            if ($difference === 0) {
                $this->apply($order, $row);
            } elseif ($difference < 0) {
                $manual = isset($data['refund_reference']) ? ['reason' => $data['reason'], 'reference' => $data['refund_reference'], 'author' => $actor] : null;
                app(Refunds::class)->reserveCredit($order, $row, $manual);
            }

            return $this->row($id);
        }, 3);
        if ($row->status === 'awaiting_refund') {
            foreach ($this->store->table('refunds')->where('amendment_id', $id)->where('status', 'pending')->pluck('id') as $refund) {
                app(Refunds::class)->process($refund);
            }
        }

        return $this->row($id);
    }

    public function row(string $id): object
    {
        return $this->store->table('amendments')->where('id', $id)->firstOrFail();
    }

    public function payment(object $row, object $order): object
    {
        return (object) ['id' => $row->id, 'total' => (int) $row->difference, 'gateway' => $row->gateway, 'site' => $order->site, 'currency' => $order->currency,
            'order_id' => $order->id, 'order_number' => $order->order_number, 'contact_revision' => $order->contact_revision,
            'payment_connection_id' => $row->payment_connection_id ?? null, 'payment_mode' => $row->payment_mode ?? null,
            'email' => $order->email, 'created_at' => $row->started_at ?? $row->created_at, 'session_id' => $row->session_id, 'payment_ref' => $row->payment_ref,
            'checkout_url' => $row->checkout_url, 'receipt' => json_encode(['return_url' => Checkout::link($order)], JSON_THROW_ON_ERROR)];
    }

    public function reconcile(string $id, bool $start = false): object
    {
        $row = $this->row($id);
        if ($row->status !== 'awaiting_payment') {
            return $row;
        }
        $order = $this->store->table('orders')->where('id', $row->order_id)->firstOrFail();
        if (! $row->started_at) {
            if (now()->gt(Carbon::parse($row->created_at)->addDay())) {
                return $this->outcome($id, ['status' => 'expired', 'unstarted' => true]);
            }
            if (! $start) {
                return $row;
            }
            $quote = json_decode($row->proposed, true, flags: JSON_THROW_ON_ERROR);
            if (!in_array($row->gateway, $this->balanceGateways($quote, (int) $row->difference, $order->site), true)) {
                throw new Contracts\PaymentUnavailable('Check the payment settings for this order amendment.');
            }
            $this->store->db()->transaction(function () use ($id, $order, $row) {
                $order = $this->store->table('orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
                if ($row->exchange_return ?? null) {
                    $request = $this->store->table('return_requests')->where('id', $row->exchange_return)->firstOrFail();
                    app(ExchangeBalances::class)->verifyCredit($order, $request);
                }
                $this->store->table('amendments')->where('id', $id)->where('status', 'awaiting_payment')->whereNull('started_at')->update(['started_at' => now()]);
            }, 3);
            $row = $this->row($id);
            if ($row->status !== 'awaiting_payment') {
                return $row;
            }
        }
        if (! $row->session_id) {
            $session = app(PaymentSessions::class)->start('amendments', $this->payment($row, $order));
            if ($session === null) {
                return $this->row($id);
            }
            if (($session['status'] ?? '') === 'expired') {
                return $this->outcome($id, $session);
            }
            $this->store->table('amendments')->where('id', $id)->whereNull('session_id')->update(['session_id' => $session['id'], 'checkout_url' => $session['url']]);
            $row = $this->row($id);
        }

        return $this->outcome($id, $this->providers->gateway($row->gateway)->outcome($this->payment($row, $order)));
    }

    private function outcome(string $id, array $outcome): object
    {
        return $this->store->db()->transaction(function () use ($id, $outcome) {
            $parent = $this->row($id)->order_id;
            $order = $this->store->table('orders')->where('id', $parent)->lockForUpdate()->firstOrFail();
            $row = $this->store->table('amendments')->where('id', $id)->lockForUpdate()->firstOrFail();
            $rejected = !empty($outcome['request_rejected']);
            if ($rejected && !PaymentSessions::canReleaseRejected($row)) {
                return $row;
            }
            if ($row->status !== 'awaiting_payment' || $outcome['status'] === 'pending' || (! empty($outcome['unstarted']) && $row->started_at)) {
                return $row;
            }
            if ($outcome['status'] === 'expired') {
                foreach ($this->changes(json_decode($row->previous, true), json_decode($row->proposed, true)) as $sku => $delta) {
                    if ($delta > 0) {
                        app(Inventory::class)->releaseReserved($order->id, $sku, $delta, $row->author, 'Amendment released');
                    }
                }
                $this->store->table('amendments')->where('id', $id)->update(['status' => 'expired', ...($rejected ? ['payment_start_state' => 'rejected'] : [])]);
                $this->store->recordActivity($parent, 'amendment', $rejected ? 'The payment provider declined to open the amendment checkout. Reservations released.' : 'Amendment payment expired. The original order is available for dispatch.');
            } else {
                throw_unless($outcome['status'] === 'paid' && $outcome['currency'] === $order->currency && $outcome['amount'] === (int) $row->difference && filled($outcome['reference']), 'Payment does not match the amendment.');
                $this->store->table('amendments')->where('id', $id)->update(['payment_ref' => $outcome['reference']]);
                $this->apply($order, $row);
            }

            return $this->row($id);
        }, 3);
    }

    public function creditSettled(object $order, string $id): void
    {
        $row = $this->row($id);
        if ($row->status === 'awaiting_refund' && (int) $this->store->table('refunds')->where('amendment_id', $id)->where('status', 'succeeded')->sum('amount') === -(int) $row->difference) {
            $this->apply($order, $row);
        }
    }

    private function apply(object $order, object $row): void
    {
        $after = json_decode($row->proposed, true);
        if ($order->voucher_id) {
            $returned = (int) $this->store->table('refunds')->where('order_id', $order->id)->whereNotNull('voucher_id')
                ->where('status', 'succeeded')->whereNotNull('amendment_id')->sum('amount');
            $previous = json_decode($order->receipt, true);
            $after['voucher'] = [...$previous['voucher'], 'amount' => max(0, (int) $order->voucher_amount - $returned)];
            $after['amount_due'] = max(0, $after['total'] - $after['voucher']['amount']);
        }
        if ($row->exchange_return ?? null) {
            $this->store->table('orders')->where('id', $order->id)->update(['receipt' => json_encode($after, JSON_THROW_ON_ERROR),
                'total' => (int) $order->total + max(0, (int) $row->difference)]);
            app(OrderQueueFields::class)->refresh($order->id);
            $this->markApplied($order->id, $row->id);
            $this->store->recordActivity($order->id, 'return', 'Exchange balance settled. '.$row->reason, $row->author);
            return;
        }
        $items = $after['_cart_items'];
        unset($after['_cart_items']);
        foreach ($this->changes(json_decode($row->previous, true), $after) as $sku => $delta) {
            if ($delta < 0) {
                app(Inventory::class)->releaseReserved($order->id, $sku, -$delta, $row->author, 'Amendment released');
            }
        }
        $this->store->table('carts')->where('id', $order->cart_id)->update(['items' => json_encode($items, JSON_THROW_ON_ERROR)]);
        $this->store->table('orders')->where('id', $order->id)->update(['receipt' => json_encode($after, JSON_THROW_ON_ERROR),
            'delivery_override' => null, 'total' => (int) $order->total + max(0, (int) $row->difference)]);
        app(OrderQueueFields::class)->refresh($order->id);
        $this->markApplied($order->id, $row->id);
        $this->store->recordActivity($order->id, 'amendment', 'Order items updated. '.$row->reason, $row->author);
    }

    private function markApplied(string $order, string $amendment): void
    {
        $this->store->table('amendments')->where('id', $amendment)->update(['status' => 'applied', 'applied_at' => now()]);
        $this->store->db()->afterCommit(fn () => event('sellissimo.order.amended', [$order, $amendment]));
    }

    private function changes(array $before, array $after): array
    {
        $delta = [];
        foreach ([[-1, $before], [1, $after]] as [$sign,$receipt]) {
            foreach ($receipt['lines'] as $line) {
                if (Inventory::tracked($line)) {
                    $delta[$line['sku']] = ($delta[$line['sku']] ?? 0) + $sign * $line['qty'];
                }
            }
        }
        ksort($delta);

        return array_filter($delta);
    }
}
