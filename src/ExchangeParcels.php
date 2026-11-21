<?php

namespace Sellissimo\Core;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExchangeParcels
{
    public function __construct(private Store $store) {}

    public function history(array $ids)
    {
        return $this->store->table('exchange_parcels')->whereIn('return_id', $ids)->orderBy('dispatched_at')->orderBy('id')
            ->get(['id', 'return_id', 'origin', 'quantity', 'tracking', 'dispatched_at'])->groupBy('return_id');
    }

    public function alreadyDispatched(object $row, array $input): bool
    {
        $parcels = $this->store->table('exchange_parcels');
        $id = $input['operation_id'] ?? null;
        $existing = $id ? $parcels->where('id', $id)->first() : $parcels->where('return_id', $row->id)->where('tracking', $input['tracking'])->first();
        if (!$existing) {
            return false;
        }
        abort_unless($existing->return_id === $row->id && $existing->tracking === $input['tracking']
            && (!isset($input['message']) || $this->store->table('return_notices')->where('id', $existing->id)->value('message') === $input['message'])
            && (!isset($input['origin']) || $existing->origin === $input['origin'])
            && (!isset($input['quantity']) || $existing->quantity == $input['quantity']), 409);

        return true;
    }

    /** The return and order are locked. Stock was deducted when the replacement was reserved. */
    public function dispatch(object $order, object $row, array $input, array $held): array
    {
        $sent = (int) $this->store->table('exchange_parcels')->where('return_id', $row->id)->sum('quantity');
        $remaining = $row->quantity - $sent;
        $quantity = (int) ($input['quantity'] ?? $remaining);
        $reserved = $held[$row->replacement] ?? [];
        $locations = $this->store->table('locations')->orderBy('code')->pluck('code');
        $origin = $input['origin'] ?? collect($reserved)->filter(fn ($available) => $available >= $quantity)->keys()->first()
            ?? ($locations->contains('main') ? 'main' : $locations->first());
        // Older integrations can hold stock at a warehouse code that is no longer in the location directory.
        if ($reserved ? ($reserved[$origin] ?? 0) < $quantity : !$locations->contains($origin)) {
            throw ValidationException::withMessages(['origin' => __('Choose a warehouse that holds the requested replacement units.')]);
        }
        if ($quantity < 1 || $quantity > $remaining) {
            throw ValidationException::withMessages(['quantity' => __('Quantity exceeds the remaining replacement units.')]);
        }
        $this->record($order, $row, $input['operation_id'] ?? (string) Str::uuid(), $origin, $quantity, $sent, $input['tracking'], now());
        if ($reserved) {
            $reserved[$origin] -= $quantity;
            $held[$row->replacement] = array_filter($reserved);
        }

        return ['status' => $quantity === $remaining ? 'exchanged' : 'received', 'tracking' => $input['tracking'],
            'reserved_stock' => $quantity === $remaining ? null : json_encode($held, JSON_THROW_ON_ERROR)];
    }

    public function record(object $order, object $row, string $id, ?string $origin, int $quantity, int $sent, string $tracking, $date): void
    {
        $line = LineOptions::find(json_decode($order->receipt, true)['lines'], $row->line_key);
        $quote = json_decode($row->exchange_quote ?? '{}', true);
        $replacement = $quote['replacement_item'] ?? ['sku' => $row->replacement, 'title' => $row->replacement];
        $money = app(Refunds::class);
        $value = $quote['replacement_total'] ?? $money->returnEntitlement($order, $row);
        $tax = $quote['replacement_tax'] ?? (isset($quote['credit_tax']) ? $quote['credit_tax'] + $quote['tax'] : $money->returnEntitlement($order, $row, 'tax'));
        $savings = $money->returnEntitlement($order, $row, 'discount');
        $portion = fn ($amount) => intdiv($amount * ($sent + $quantity), $row->quantity) - intdiv($amount * $sent, $row->quantity);
        $merchandise = ['line_key' => $row->line_key, 'source' => Arr::only($line ?? ['sku' => $row->line_key, 'title' => $row->line_key], ['entry_id', 'sku', 'title', 'product_title']),
            'destination' => $replacement, 'net' => $portion($value - $tax), 'discount' => $portion($savings)];
        $this->store->table('exchange_parcels')->insert(['id' => $id, 'return_id' => $row->id, 'order_id' => $order->id, 'origin' => $origin,
            'quantity' => $quantity, 'tracking' => $tracking, 'merchandise' => json_encode($merchandise, JSON_THROW_ON_ERROR), 'dispatched_at' => $date]);
    }
}
