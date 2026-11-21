<?php

namespace Sellissimo\Core;

use Illuminate\Support\Str;
use Sellissimo\Core\Http\AmendmentController;

class ExchangeBalances
{
    public function __construct(private Store $store, private Amendments $amendments, private Refunds $refunds) {}

    /** Freeze the replacement's merchandise price and tax. Delivery and order-wide fees have already been paid. */
    public function quote(object $order, object $request, string $replacement, array $line): array
    {
        $qty = (int) $request->quantity;
        $priorQuote = json_decode($request->exchange_quote ?? '{}', true);
        $original = $this->refunds->returnEntitlement($order, $request);
        $originalTax = $this->refunds->returnEntitlement($order, $request, 'tax');
        if ($request->status === 'received' && $priorQuote) {
            $original = $priorQuote['original_credit'] ?? $priorQuote['credit'];
            $originalTax = $priorQuote['original_tax'] ?? $priorQuote['credit_tax'];
        }
        $likeForLike = ['replacement_total' => $original, 'replacement_tax' => $originalTax, 'like_for_like' => true];
        if ($replacement === $line['sku']) {
            $likeForLike['replacement_item'] = \Illuminate\Support\Arr::only($line, ['entry_id', 'sku', 'title', 'product_title']);
            return $this->withCredit($order, $request, $line, $likeForLike, $original, $originalTax);
        }
        $receipt = json_decode($order->receipt, true);
        $cart = (object) ['id' => $order->cart_id, 'site' => $order->site, 'currency' => $order->currency, 'customer' => $order->customer,
            'items' => json_encode([$replacement => $qty], JSON_THROW_ON_ERROR)];
        $quote = app(Pricing::class)->calculate($cart, ['address' => $receipt['address'], 'shipping_address' => DeliveryAddress::current($order, $receipt), 'email' => $order->email]);
        $item = $quote['lines'][0];
        $identity = \Illuminate\Support\Arr::only($item, ['entry_id', 'sku', 'title', 'product_title']);
        $likeForLike['replacement_item'] = $identity;
        // Carry the purchased terms through a like-for-like exchange, including its original discount.
        if ($item['price'] === $line['price'] && $item['tax_class'] === $line['tax_class']) {
            return $this->withCredit($order, $request, $line, $likeForLike, $original, $originalTax);
        }
        return $this->withCredit($order, $request, $line, ['replacement_total' => $item['total'], 'replacement_tax' => $item['tax'],
            'title' => $item['title'], 'replacement_item' => $identity], $original, $originalTax);
    }

    private function withCredit(object $order, object $request, array $line, array $quote, int $original, int $originalTax): array
    {
        $refunded = $this->refunds->standaloneRefunded($order, $request);
        [$credit, $tax] = $this->refunds->exchangeCredit($order, $request, $line, $original, $originalTax, $refunded);
        return array_merge($quote, ['original_credit' => $original, 'original_tax' => $originalTax, 'standalone_refunded' => $refunded,
            'credit' => $credit, 'credit_tax' => $tax, 'difference' => $quote['replacement_total'] - $credit, 'tax' => $quote['replacement_tax'] - $tax]);
    }

    public function atReceipt(object $order, object $request, array $line): array
    {
        if (!$request->exchange_quote) {
            // Exchanges approved before balance pricing were accepted on like-for-like terms.
            $quote = $this->quote($order, $request, $line['sku'], $line);
            if ($request->replacement !== $line['sku']) {
                unset($quote['replacement_item']);
            }
            return $quote;
        }
        $quote = json_decode($request->exchange_quote, true);
        $tax = $this->refunds->returnEntitlement($order, $request, 'tax');
        $original = $this->refunds->returnEntitlement($order, $request);
        $quote['replacement_tax'] ??= $quote['tax'] + $quote['credit_tax'];
        if ($quote['like_for_like'] ?? false) {
            $quote['replacement_total'] = $original;
            $quote['replacement_tax'] = $tax;
        }
        return $this->withCredit($order, $request, $line, $quote, $original, $tax);
    }

    public function verifyCredit(object $order, object $request): void
    {
        $refunded = $this->refunds->standaloneRefunded($order, $request);
        $quote = json_decode($request->exchange_quote ?? '{}', true);
        abort_if($refunded !== (int) ($quote['standalone_refunded'] ?? 0), 409, __('sellissimo::shop.exchange_refund_review'));
    }

    private function settlementStatus(object $amendment): string
    {
        if ($amendment->status === 'applied' && $amendment->difference < 0) {
            $paid = $this->store->table('refunds')->where('amendment_id', $amendment->id)->where('status', 'succeeded')->sum('amount');
            if ((int) $paid !== -(int) $amendment->difference) {
                return 'awaiting_refund';
            }
        }
        return $amendment->status;
    }

    public function details(object $order, object $request): ?array
    {
        if (!$request->exchange_quote) {
            return null;
        }
        $quote = json_decode($request->exchange_quote, true);
        $row = $request->exchange_amendment ? $this->amendments->row($request->exchange_amendment) : null;

        $status = $row ? $this->settlementStatus($row) : ($quote['difference'] === 0 ? 'applied' : 'unsettled');
        return [...$quote, 'label' => __('sellissimo::shop.exchange_'.($status === 'unsettled' ? 'unsettled_status' : $status)), 'amount' => Money::display(abs($quote['difference']), $order->currency),
            'status' => $status,
            'pay_url' => $row?->status === 'awaiting_payment' ? AmendmentController::link($row) : null];
    }

    public function settled(object $request, object $order): bool
    {
        $this->verifyCredit($order, $request);
        $quote = json_decode($request->exchange_quote ?? '{}', true);

        return empty($quote['difference']) || ($request->exchange_amendment && $this->settlementStatus($this->amendments->row($request->exchange_amendment)) === 'applied');
    }

    public function start(string $id, array $input, string $actor): void
    {
        $parent = $this->store->table('return_requests')->where('id', $id)->value('order_id');
        $amendment = $this->store->db()->transaction(function () use ($parent, $id, $input, $actor) {
            $order = $this->store->table('orders')->where('id', $parent)->lockForUpdate()->firstOrFail();
            $requests = $this->store->table('return_requests')->where('id', $id);
            $request = $requests->lockForUpdate()->firstOrFail();
            abort_unless($request->status === 'received' && $request->replacement && $request->exchange_quote, 409);
            $this->verifyCredit($order, $request);
            $existing = $request->exchange_amendment ? $this->amendments->row($request->exchange_amendment) : null;
            if ($existing && $existing->status !== 'expired') {
                return $existing;
            }
            abort_unless($order->status === 'paid' && !$this->amendments->pending($parent), 409, __('sellissimo::shop.exchange_unsettled'));
            $quote = json_decode($request->exchange_quote, true);
            $difference = (int) $quote['difference'];
            abort_if($difference === 0, 409);
            $after = json_decode($order->receipt, true);
            $gateways = $this->amendments->balanceGateways($after, $difference, $order->site);
            $gateway = $input['gateway'] ?? (in_array($order->gateway, $gateways, true) ? $order->gateway : ($gateways[0] ?? $order->gateway));
            abort_if($difference > 0 && !in_array($gateway, $gateways, true), 422, __('sellissimo::shop.online_payment_is_currently_unavailable'));
            $reason = __('sellissimo::shop.exchange_adjustment', ['sku' => $request->replacement]);
            foreach ($after['lines'] as &$line) {
                if (($line['key'] ?? $line['sku']) !== $request->line_key) {
                    continue;
                }
                $line['exchange_base'] ??= ['total' => $line['total'], 'tax' => $line['tax']];
                $line['total'] += $difference;
                $line['tax'] += $quote['tax'];
                $line['subtotal'] += $difference - $quote['tax'];
                $line['exchanges'][$id] = ['sku' => $request->replacement, 'qty' => (int) $request->quantity, 'difference' => $difference];
                break;
            }
            unset($line);
            $after['subtotal'] += $difference - $quote['tax'];
            $after['tax'] += $quote['tax'];
            $after['total'] += $difference;
            $key = (string) Str::uuid();
            $this->store->table('amendments')->insert(['id' => $key, 'payment_start_state' => 'ready', 'exchange_return' => $id, 'order_id' => $parent,
                'status' => $difference > 0 ? 'awaiting_payment' : 'awaiting_refund', 'previous' => $order->receipt, 'proposed' => json_encode($after, JSON_THROW_ON_ERROR),
                'difference' => $difference, 'gateway' => $gateway, 'request_hash' => hash('sha256', $key), 'author' => $actor, 'reason' => $reason, 'created_at' => now()]);
            $requests->update(['exchange_amendment' => $key]);
            $row = $this->amendments->row($key);
            if ($difference < 0) {
                $manual = filled($input['reference'] ?? null) ? ['reference' => $input['reference'], 'reason' => $reason, 'author' => $actor] : null;
                $this->refunds->reserveCredit($order, $row, $manual);
            }
            $this->store->recordActivity($parent, 'return', $reason, $actor);
            app(ReturnNotices::class)->queue($id, ['action' => 'balance'], $request->replacement);

            return $row;
        }, 3);
        if ($this->settlementStatus($amendment) === 'awaiting_refund') {
            $manual = filled($input['reference'] ?? null) ? ['reference' => $input['reference'], 'reason' => $amendment->reason, 'author' => $actor] : null;
            $this->refunds->retryCredit($amendment, $manual);
        }
    }
}
