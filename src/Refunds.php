<?php

namespace Sellissimo\Core;

class Refunds
{
    public function __construct(private Store $store, private Registry $providers) {}

    public function request(string $orderId, string $id, string $sku, int $amount, ?array $manual = null, ?string $returnId = null): void
    {
        $this->store->db()->transaction(function () use ($orderId, $id, $sku, $amount, $manual, $returnId) {
            $order = $this->store->table('orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
            if ($existing = $this->store->table('refunds')->where('id', $id)->first()) {
                $issued = $existing->group_id ? $this->store->table('refunds')->where('group_id',$existing->group_id)->sum('amount') : $existing->amount;
                abort_unless((bool) $existing->manual === (bool) $manual && (!$manual || ($existing->reference === $manual['reference'] && $existing->reason === $manual['reason'])), 409);
                abort_unless($existing->order_id === $orderId && $existing->return_request_id === $returnId && $existing->sku === $sku && ($sku === '@all' || (int) $issued === $amount), 409);
                return;
            }
            abort_if(app(Amendments::class)->pending($orderId), 409, 'Settle the open amendment before issuing another refund.');
            abort_unless($order->status === 'paid' && $order->total > 0, 422, 'Order cannot be refunded.');
            $receipt = json_decode($order->receipt, true);
            $reserved = $this->store->table('refunds')->where('order_id', $orderId)->whereIn('status', ['pending', 'succeeded']);
            $available = (int)$order->total - (int)(clone $reserved)->sum('amount');
            if ($returnId === null) {
                $exchanges = $this->store->table('return_requests')->where('order_id', $orderId)
                    ->whereNotNull('replacement')->whereIn('status', ['requested', 'approved', 'received']);
                if ($sku !== '@all') {
                    $exchanges->where('line_key', $sku);
                }
                abort_if($exchanges->exists(), 409, __('sellissimo::shop.refund_open_exchange'));
            }
            if ($sku === '@all') {
                $amount = $available;
                abort_unless($amount > 0, 422, 'No refundable balance remains.');
            } else {
                abort_if((clone $reserved)->where('sku', '@all')->exists(), 409, 'A full refund is in progress.');
                $line = LineOptions::find($receipt['lines'], $sku);
                $limit = match (true) {
                    $sku === '@shipping' => $receipt['shipping']['amount'] ?? 0,
                    str_starts_with($sku, '@charge:') => $receipt['charges'][substr($sku, 8)]['total'] ?? 0,
                    default => $line['total'] ?? 0,
                };
                $used = $reserved->where('sku', $sku)->sum('amount');
                abort_unless($amount > 0 && $amount <= min($available,$limit - $used), 422, 'Amount exceeds the refundable balance.');
            }
            if ($returnId !== null) {
                $request = $this->store->table('return_requests')->where('id', $returnId)->where('order_id', $orderId)
                    ->where('line_key', $sku)->lockForUpdate()->firstOrFail();
                $summary = $this->returnSummary($order, $request);
                abort_unless($summary && $amount > 0 && $amount <= $summary['due'], 409, 'Refresh the return to see its refundable balance.');
            }
            $this->reserve($order,$id,$sku,$amount,$manual,null,$returnId);
        }, 3);
        $this->processGroup($id);
    }

    public function reserveCancellation(object $order, string $id, int $amount, ?array $manual): void
    {
        abort_if($this->store->table('refunds')->where('id', $id)->exists(), 409, 'Reload the order before submitting a new refund attempt.');
        $this->reserve($order, $id, '@all', $amount, $manual, null);
    }

    public function reserveCredit(object $order, object $amendment, ?array $manual): void
    {
        $this->reserve($order,(string) \Illuminate\Support\Str::uuid(),'@amendment',-(int)$amendment->difference,$manual,$amendment->id);
    }

    public function retryCredit(object $amendment, ?array $manual): void
    {
        $this->store->db()->transaction(function () use ($amendment, $manual) {
            $order = $this->store->table('orders')->where('id', $amendment->order_id)->lockForUpdate()->firstOrFail();
            $status = $this->store->table('amendments')->where('id', $amendment->id)->value('status');
            abort_unless($amendment->difference < 0 && in_array($status, ['awaiting_refund', 'applied'], true), 409);
            $failed = $this->store->table('refunds')->where('amendment_id', $amendment->id)->where('status', 'failed');
            $amount = (int) (clone $failed)->sum('amount');
            if ($amount) {
                $failed->update(['status' => 'superseded']);
                $this->reserve($order, (string) \Illuminate\Support\Str::uuid(), '@amendment', $amount, $manual, $amendment->id);
            }
        }, 3);
        foreach ($this->store->table('refunds')->where('amendment_id', $amendment->id)->where('status', 'pending')->pluck('id') as $id) {
            $this->process($id);
        }
    }

    /** Allocate against the newest captured payments first, in minor currency units. */
    private function refundParts(object $order, int $amount): array
    {
        $charges = $this->store->table('amendments')->where('order_id', $order->id)->where('status', 'applied')->where('difference', '>', 0)
            ->orderByDesc('applied_at')->orderBy('id')->get();
        $charges->push((object) ['id' => null, 'gateway' => $order->gateway, 'difference' => (int) $order->total - (int) $charges->sum('difference')]);
        $voucherPart = 0;
        if ($order->voucher_id ?? null) {
            $charges->last()->difference = (int) $order->original_cash_amount;
            $reserved = $this->store->table('refunds')->where('order_id', $order->id)->whereIn('status', ['succeeded', 'pending']);
            $previous = (int) (clone $reserved)->sum('amount');
            $restored = (int) (clone $reserved)->whereNotNull('voucher_id')->sum('amount');
            $cash = (int) $charges->sum('difference');
            $gift = (int) $order->voucher_amount;
            $total = $cash + $gift;
            $target = \Brick\Math\BigInteger::of($previous + $amount)->multipliedBy($gift)
                ->plus(intdiv($total, 2))->quotient($total)->toInt();
            $voucherPart = max($amount - max(0, $cash - ($previous - $restored)),
                min($amount, $gift - $restored, max(0, $target - $restored)));
            $amount -= $voucherPart;
        }
        $used = $this->store->table('refunds')->where('order_id', $order->id)->whereNull('voucher_id')->whereIn('status', ['succeeded', 'pending'])
            ->select('capture_id')->selectRaw('sum(amount) as reserved')->groupBy('capture_id')->pluck('reserved', 'capture_id');
        $parts = [];
        foreach ($charges as $charge) {
            $available = max(0, $charge->difference - (int) $used->get($charge->id ?? '', 0));
            $part = min($amount, $available);
            if ($part > 0) {
                $parts[] = ['capture_id' => $charge->id, 'gateway' => $charge->gateway, 'amount' => $part];
                $amount -= $part;
            }
            if (!$amount) {
                break;
            }
        }
        if ($voucherPart > 0) {
            $parts[] = ['capture_id' => null, 'gateway' => 'voucher', 'amount' => $voucherPart, 'voucher_id' => $order->voucher_id];
        }
        return $parts;
    }

    public function canRefundOnline(object $order, int $amount): bool
    {
        if ($amount <= 0) {
            return false;
        }
        $parts = $this->refundParts($order, $amount);
        return array_sum(array_column($parts, 'amount')) === $amount
            && collect($parts)->every(fn ($part) => isset($part['voucher_id']) || $this->providers->supportsRefunds($part['gateway']));
    }

    private function reserve(object $order, string $id, string $sku, int $amount, ?array $manual, ?string $amendment, ?string $returnId = null): void
    {
        if ($manual && filled($manual['reference'] ?? null)) {
            abort_if($this->store->table('refunds')->where('order_id', $order->id)->where('provider_ref', $manual['reference'])->exists(),
                409, 'This provider refund is already recorded on the order.');
        }
        $parts = $this->refundParts($order, $amount);
        abort_unless(array_sum(array_column($parts, 'amount')) === $amount, 409, 'The available payment balance changed.');
        foreach ($parts as $index => $part) {
            abort_unless(isset($part['voucher_id']) || $manual || $this->providers->supportsRefunds($part['gateway']), 422, 'Record the offline refund with its reference, or use a refundable payment gateway.');
            $partId = $index === 0 ? $id : (string) \Illuminate\Support\Str::uuid();
            $this->store->table('refunds')->insert([
                'id' => $partId, 'voucher_id' => $part['voucher_id'] ?? null,
                'group_id' => $id, 'capture_id' => $part['capture_id'], 'amendment_id' => $amendment, 'return_request_id' => $returnId,
                'order_id' => $order->id, 'sku' => $sku, 'amount' => $part['amount'], 'status' => 'pending',
                'manual' => (bool) $manual, 'reference' => $manual['reference'] ?? null,
                'reason' => $manual['reason'] ?? null, 'recorded_by' => $manual['author'] ?? null,
            ]);
            app(GiftVouchers::class)->reservePurchaseRefund($order, $sku, $partId);
        }
    }

    private function processGroup(string $id): void
    {
        $rows = $this->store->table('refunds')->where(fn ($q) => $q->where('id',$id)->orWhere('group_id',$id))->pluck('id');
        foreach ($rows as $part) {
            $this->process($part);
        }
    }

    public function process(string $id): void
    {
        $refund = $this->store->table('refunds')->where('id', $id)->firstOrFail();
        if ($refund->status !== 'pending') {
            return;
        }
        $order = $this->store->table('orders')->where('id',$refund->order_id)->firstOrFail();
        if ($refund->amendment_id) {
            $amendment = app(Amendments::class)->row($refund->amendment_id);
            if ($amendment->exchange_return ?? null) {
                $request = $this->store->table('return_requests')->where('id', $amendment->exchange_return)->firstOrFail();
                app(ExchangeBalances::class)->verifyCredit($order, $request);
            }
        }
        if ($refund->voucher_id) {
            $this->settle($order, $refund, 'succeeded');
            return;
        }
        if ($refund->manual) {
            $this->settle($order,$refund,'succeeded');
            return;
        }
        $payment = $order;
        if ($refund->capture_id) {
            $amendments = app(Amendments::class);
            $payment = $amendments->payment($amendments->row($refund->capture_id),$order);
        } else {
            $payment = clone $order;
            if ($order->original_cash_amount !== null) {
                $payment->total = (int) $order->original_cash_amount;
            } else {
                $payment->total -= (int)$this->store->table('amendments')->where('order_id',$order->id)->where('status','applied')->where('difference','>',0)->sum('difference');
            }
        }
        $status = $this->providers->gateway($payment->gateway)->refund($payment,$refund);
        if ($status === 'pending') {
            return;
        }
        throw_unless(in_array($status, ['succeeded','failed'], true), 'Invalid refund status.');
        $this->settle($order,$refund,$status);
    }

    /** Import the current state of a verified refund on a known captured payment. */
    public function syncProviderRefund(object $order, ?string $captureId, string $reference, ?string $localId, int $amount, string $status): void
    {
        throw_unless($amount > 0 && in_array($status, ['pending', 'succeeded', 'failed'], true), 'Invalid provider refund.');
        $this->store->db()->transaction(function () use ($order, $captureId, $reference, $localId, $amount, $status) {
            $order = $this->store->table('orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
            $refunds = $this->store->table('refunds');
            $refund = (clone $refunds)->where('provider_ref', $reference)->first();
            if (!$refund) {
                $candidates = (clone $refunds)->where('order_id', $order->id)->where('capture_id', $captureId)
                    ->where('amount', $amount)->whereNull('provider_ref')->whereNull('voucher_id');
                if ($localId) {
                    $refund = (clone $candidates)->where('id', $localId)->where('manual', false)->first();
                }
                if (!$refund) {
                    $manual = (clone $refunds)->where('order_id', $order->id)->whereNull('voucher_id')->where('manual', true)
                        ->where('reference', $reference)->whereIn('status', ['pending', 'succeeded'])->limit(2)->get();
                    if ($manual->isNotEmpty()) {
                        throw_unless($manual->count() === 1 && $manual->first()->capture_id === $captureId
                            && (int) $manual->first()->amount === $amount, "Review the amount and capture of manual refund $reference before syncing it.");
                        $refund = $manual->first();
                    }
                }
                if ($refund) {
                    (clone $refunds)->where('id', $refund->id)->update(['provider_ref' => $reference]);
                } else {
                    $id = (string) \Illuminate\Support\Str::uuid();
                    $refunds->insert(['id' => $id, 'order_id' => $order->id, 'capture_id' => $captureId,
                        'sku' => '@provider', 'amount' => $amount, 'status' => 'pending', 'external' => true, 'provider_ref' => $reference]);
                    $refund = (clone $refunds)->where('id', $id)->firstOrFail();
                    $this->store->recordActivity($order->id, 'refund', 'Provider refund recorded · '.$reference.'.');
                }
            }
            throw_unless(!$refund->voucher_id && $refund->order_id === $order->id && $refund->capture_id === $captureId && (int) $refund->amount === $amount, 'Refund does not match its captured payment.');
            if ($refund->status === $status) {
                return;
            }
            // Stripe can report a failed bank transfer days after a refund first succeeded.
            if ($refund->status === 'succeeded' && $status === 'failed') {
                app(GiftVouchers::class)->restoreFailedPurchaseRefund($order, $refund->id);
                (clone $refunds)->where('id', $refund->id)->update(['status' => 'failed', 'settled_at' => null, 'reversed_at' => now(), 'notice_pending' => false]);
                if ($order->status === 'refunded') {
                    $this->store->table('orders')->where('id', $order->id)->update(['status' => 'paid']);
                }
                $this->store->recordActivity($order->id, 'refund', "Refund $reference failed after settlement. Review the payment before refunding again.");
                $this->store->db()->afterCommit(fn () => event('sellissimo.order.refund_reversed', [$order->id, $refund->id]));
                return;
            }
            if ($refund->status === 'pending' && $status !== 'pending') {
                $this->settle($order, $refund, $status);
            }
        }, 3);
    }

    private function settle(object $order, object $refund, string $status): void
    {
        $this->store->db()->transaction(function () use ($order, $refund, $status) {
            $order = $this->store->table('orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
            $changed = $this->store->table('refunds')->where('id', $refund->id)->where('status', 'pending')->update(['status' => $status, 'settled_at' => $status === 'succeeded' ? now() : null, 'notice_pending' => $status === 'succeeded']);
            if ($changed && $refund->external && !$refund->capture_id && $status === 'succeeded') {
                app(GiftVouchers::class)->externalPurchaseRefund($order, $refund);
            }
            if ($changed && $refund->voucher_id && $status === 'succeeded') {
                app(GiftVouchers::class)->change($refund->voucher_id, 'refund:'.$refund->id, 'refund', (int) $refund->amount, $order->id);
            }
            if ($changed && $status === 'failed') {
                app(GiftVouchers::class)->restoreFailedPurchaseRefund($order, $refund->id);
            }
            if ($changed) {
                $amount = Money::display($refund->amount, $order->currency);
                $message = $refund->manual
                    ? "Manual refund of $amount recorded. {$refund->reason} (reference {$refund->reference})."
                    : ($status === 'succeeded' ? "Refund issued for $amount." : "Refund failed for $amount.");
                $this->store->recordActivity($order->id, 'refund', $message, $refund->recorded_by);
            }
            if ($changed) {
                $this->store->db()->afterCommit(function () use ($refund) {
                    try {
                        $this->sendNotice($refund->id);
                    } catch (\Throwable $error) {
                        report($error);
                    }
                });
            }
            if ($changed && $status === 'succeeded') {
                $sum = $this->store->table('refunds')->where('order_id', $order->id)->where('status', 'succeeded')->sum('amount');
                if ($order->status !== 'cancelled' && !$refund->amendment_id && (int) $sum === (int) $order->total) {
                    $this->store->table('orders')->where('id', $order->id)->update(['status' => 'refunded']);
                }
                if ($refund->amendment_id) {
                    app(Amendments::class)->creditSettled($order,$refund->amendment_id);
                }
                app(Webhooks::class)->enqueue('order.refunded', $order, $refund->id, ['refund' => $refund->id, 'amount' => (int) $refund->amount]);
                $this->store->db()->afterCommit(fn () => event('sellissimo.order.refunded', [$order->id, $refund->id]));
            }
        }, 3);
    }

    public function sendNotice(string $id): void
    {
        $this->store->db()->transaction(function () use ($id) {
            $refund = $this->store->table('refunds')->where('id', $id)->firstOrFail();
            $order = $this->store->table('orders')->where('id', $refund->order_id)->lockForUpdate()->firstOrFail();
            $group = $this->store->table('refunds')->where('order_id', $order->id);
            if ($refund->group_id) {
                $group->where('group_id', $refund->group_id);
            } else {
                $group->where('id', $id);
            }
            $parts = $group->lockForUpdate()->get();
            if ($parts->contains('status', 'pending') || ! $parts->contains('notice_pending', true)) {
                return;
            }
            $amount = Money::display($parts->where('status', 'succeeded')->sum('amount'), $order->currency);
            app(StoreMail::class)->send('refund', $order->site, $order->email, Checkout::link($order), compact('order', 'amount'));
            $group->update(['notice_pending' => false]);
        });
    }

    public function returnEntitlement(object $order, object $request, string $component = 'total'): int
    {
        $line = LineOptions::find(json_decode($order->receipt, true)['lines'], $request->line_key);
        $received = $this->store->table('returns')->where('order_id', $order->id)->where('line_key', $request->line_key);
        // Older received requests lack a snapshot. Allocate rounding in receipt order.
        if ($receipt = (clone $received)->where('id', $request->id)->first()) {
            $received->where(fn ($q) => $q->where('created_at', '<', $receipt->created_at)
                ->orWhere(fn ($q) => $q->where('created_at', $receipt->created_at)->where('id', '<', $request->id)));
        }
        $prior = (int) $received->sum('quantity');
        $quantity = (int) ($line['qty'] ?? 0);
        if (!$quantity) {
            return 0;
        }
        $value = (int) ($line['exchange_base'][$component] ?? $line[$component] ?? 0);
        return intdiv($value * min($quantity, $prior + $request->quantity), $quantity)
            - intdiv($value * min($quantity, $prior), $quantity);
    }

    /** Refunds without an item allocation need review before we can promise a merchandise credit. */
    public function standaloneRefunded(object $order, object $request): int
    {
        $refunds = $this->store->table('refunds')->where('order_id', $order->id)
            ->whereNull('amendment_id')->whereNull('return_request_id')->whereIn('status', ['pending', 'succeeded'])
            ->whereIn('sku', [$request->line_key, '@provider', '@all'])->get(['sku', 'status', 'amount']);
        abort_if($refunds->contains(fn ($refund) => $refund->sku !== $request->line_key || $refund->status === 'pending'),
            409, __('sellissimo::shop.exchange_refund_review'));

        return (int) $refunds->sum('amount');
    }

    /** Divide an unlinked line refund over the original units still available to exchange. */
    public function exchangeCredit(object $order, object $request, array $line, int $original, int $originalTax, int $refunded): array
    {
        if (!$refunded || !$original) {
            return [$original, $originalTax];
        }
        $priorQuantity = (int) $this->store->table('returns')->where('order_id', $order->id)->where('line_key', $request->line_key)
            ->where('id', '!=', $request->id)->sum('quantity');
        $previous = $this->store->table('return_requests')->where('order_id', $order->id)->where('line_key', $request->line_key)
            ->where('id', '!=', $request->id)->whereNotNull('replacement')->whereIn('status', ['received', 'exchanged'])->pluck('exchange_quote');
        foreach ($previous as $snapshot) {
            $quote = json_decode($snapshot ?? '{}', true);
            $refunded -= max(0, ($quote['original_credit'] ?? 0) - ($quote['credit'] ?? 0));
        }
        $quantity = (int) $line['qty'];
        $gross = (int) ($line['exchange_base']['total'] ?? $line['total']);
        $remaining = $gross - intdiv($gross * min($quantity, $priorQuantity), $quantity);
        $balance = max(0, $remaining - max(0, $refunded));
        $credit = $remaining ? \Brick\Math\BigInteger::of($original)->multipliedBy($balance)->quotient($remaining)->toInt() : 0;
        $tax = \Brick\Math\BigInteger::of($originalTax)->multipliedBy($credit)->quotient($original)->toInt();

        return [$credit, $tax];
    }

    public function returnSummary(object $order, object $request): ?array
    {
        if ($request->status !== 'received' || $request->replacement) {
            return null;
        }
        $entitlement = (int) ($request->refund_entitlement ?? $this->returnEntitlement($order, $request));
        $refunds = $this->store->table('refunds')->where('order_id', $order->id)->get(['return_request_id', 'sku', 'status', 'amount']);
        $linked = $refunds->where('return_request_id', $request->id);
        $paid = (int) $linked->where('status', 'succeeded')->sum('amount');
        $pending = (int) $linked->where('status', 'pending')->sum('amount');
        $reserved = $refunds->whereIn('status', ['pending', 'succeeded']);
        $line = LineOptions::find(json_decode($order->receipt, true)['lines'], $request->line_key);
        $lineBalance = ($line['total'] ?? 0) - $reserved->where('sku', $request->line_key)->sum('amount');
        $exchanges = $this->store->table('return_requests')->where('order_id', $order->id)->where('line_key', $request->line_key)
            ->whereNotNull('replacement')->whereNotNull('exchange_quote')->whereIn('status', ['approved', 'received', 'exchanged'])
            ->get(['id', 'exchange_quote']);
        foreach ($exchanges as $exchange) {
            $quote = json_decode($exchange->exchange_quote, true);
            // An applied adjustment has already replaced the original credit with the replacement value on the receipt.
            $lineBalance -= isset($line['exchanges'][$exchange->id]) ? $quote['replacement_total'] : $quote['credit'];
        }
        $due = $order->status === 'paid' && !$reserved->contains('sku', '@all')
            ? max(0, min($entitlement - $paid - $pending, $order->total - $reserved->sum('amount'),
                $lineBalance)) : 0;
        $state = match (true) {
            $pending > 0 => 'refund_pending',
            $paid >= $entitlement && $paid > 0 => 'refund_settled',
            $due > 0 && $linked->contains('status', 'failed') => 'refund_failed',
            $due > 0 => 'refund_due',
            default => 'no_refundable_balance',
        };
        return ['state' => $state, 'label' => __('sellissimo::shop.'.$state), 'due' => $due, 'paid' => $paid, 'pending' => $pending,
            'dueFormatted' => Money::display($due, $order->currency), 'paidFormatted' => Money::display($paid, $order->currency),
            'pendingFormatted' => Money::display($pending, $order->currency)];
    }

    public function buyerSummary(object $order): array
    {
        $additional = (int) $this->store->table('amendments')->where('order_id', $order->id)->where('status', 'applied')->where('difference', '>', 0)->sum('difference');
        $refunds = $this->store->table('refunds')->where('order_id', $order->id)->where('status', 'succeeded')
            ->orderBy('settled_at')->orderBy('id')->get()->groupBy(fn ($row) => $row->group_id ?: $row->id)
            ->map(fn ($parts) => ['amount' => (int) $parts->sum('amount'), 'date' => $parts->max('settled_at'),
                ...($parts->whereNotNull('voucher_id')->isNotEmpty() ? ['to_voucher' => (int) $parts->whereNotNull('voucher_id')->sum('amount')] : [])])->values();
        $captured = $order->paid_at || in_array($order->status, ['paid', 'refunded'], true) || $refunds->isNotEmpty();
        return ['original' => (int) $order->total - $additional, 'additional' => $additional,
            'refunds' => $refunds->all(), 'net_paid' => $captured ? max(0, (int) $order->total - $refunds->sum('amount')) : 0];
    }

    public function downloadable(object $order, array $line): bool
    {
        if ($order->status !== 'paid' || ! ($line['downloadable'] ?? $line['digital'])) {
            return false;
        }
        if (($line['download_days'] ?? 0) > 0 && $order->paid_at && now()->gte(\Illuminate\Support\Carbon::parse($order->paid_at)->addDays($line['download_days']))) {
            return false;
        }
        if (($line['download_limit'] ?? 0) > 0 && app(Downloads::class)->count($order->id, $line) >= $line['download_limit']) {
            return false;
        }
        if ($this->store->table('download_blocks')->where('order_id', $order->id)->where('sku', $line['key'] ?? $line['sku'])->exists()) {
            return false;
        }
        $refunded = $this->store->table('refunds')->where('order_id', $order->id)->where('sku', $line['key'] ?? $line['sku'])->where('status', 'succeeded')->sum('amount');

        return ! $refunded || (int) $refunded < $line['total'];
    }
}
