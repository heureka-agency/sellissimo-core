<?php

namespace Sellissimo\Core;

use Illuminate\Validation\ValidationException;

class Cancellations
{
    public function __construct(private Store $store, private Refunds $refunds) {}

    public function cancel(string $id, array $input, $user, bool $retry = false): void
    {
        $this->store->db()->transaction(function () use ($id, $input, $user, $retry) {
            $order = $this->store->table('orders')->where('id', $id)->lockForUpdate()->firstOrFail();
            if (! $retry && $order->status === 'cancelled') {
                return;
            }
            $pending = $this->store->table('refunds')->where('order_id', $id)->where('status', 'pending')->exists();
            if ($retry) {
                abort_unless($order->status === 'cancelled', 409);
                if ($pending) {
                    return;
                }
            } else {
                abort_unless(in_array($order->status, ['paid', 'refunded'], true) && $order->gateway !== 'historical', 409);
                if ($order->tracking || $this->store->table('parcels')->where('order_id', $id)->exists() || app(CarrierLabels::class)->active($id)) {
                    throw ValidationException::withMessages(['order' => 'Cancellation requires an unshipped order. Void any active shipping label first.']);
                }
                if ($pending || app(Amendments::class)->pending($id)) {
                    throw ValidationException::withMessages(['order' => 'Settle pending refunds or amendments before cancelling this order.']);
                }
            }
            $manual = filled($input['reference'] ?? null) ? ['reference' => $input['reference'], 'reason' => $order->cancellation_reason ?? $input['reason'], 'author' => $user->email()] : null;
            $settled = $this->store->table('refunds')->where('order_id', $id)->where('status', 'succeeded')->sum('amount');
            if ($remaining = max(0, (int) $order->total - $settled)) {
                $this->refunds->reserveCancellation($order, $input['request_id'], $remaining, $manual);
            }
            if ($retry) {
                return;
            }
            if ($input['restock']) {
                $lines = collect(json_decode($order->receipt, true)['lines'])->filter(fn ($line) => Inventory::tracked($line))->groupBy('sku')->sortKeys();
                foreach ($lines as $sku => $items) {
                    app(Inventory::class)->releaseReserved($id, $sku, $items->sum('qty'), $user->email(), 'Cancellation restocked');
                }
            }
            $this->store->table('orders')->where('id', $id)->update([
                'status' => 'cancelled', 'cancellation_reason' => $input['reason'], 'cancel_notice_pending' => $input['notify'],
            ]);
            $this->store->recordActivity($id, 'cancellation', 'Order cancelled. '.$input['reason'].($input['restock'] ? ' Restock requested.' : ' Stock left unchanged.'), $user->email(), (string) $user->id());
            app(Webhooks::class)->activity('order.cancelled', $id, ['restock' => (bool) $input['restock']], $order);
        }, 3);

        foreach ($this->store->table('refunds')->where('order_id', $id)->where('status', 'pending')->pluck('id') as $refund) {
            $this->refunds->process($refund);
        }
        $this->sendNotice($id);
    }

    public function sendNotice(string $id): void
    {
        $this->store->db()->transaction(function () use ($id) {
            $order = $this->store->table('orders')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($order->cancel_notice_pending) {
                app(StoreMail::class)->send('cancelled', $order->site, $order->email, Checkout::link($order), ['order' => $order]);
                $this->store->table('orders')->where('id', $id)->update(['cancel_notice_pending' => false]);
            }
        });
    }
}
