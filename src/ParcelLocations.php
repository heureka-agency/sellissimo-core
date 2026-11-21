<?php

namespace Sellissimo\Core;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ParcelLocations
{
    public const RESERVATIONS = ['Order reserved', 'Amendment reserved', 'Amendment released', 'Fulfillment reassigned'];

    public function __construct(private Store $store) {}

    /** Return the remaining order reservations indexed by SKU and location. Orders predating locations used Main. */
    public function available(object $order, Collection $parcels): array
    {
        if (in_array($order->status, ['cancelled', 'expired'], true)) {
            return [];
        }
        $lines = collect(json_decode($order->receipt, true)['lines'])->filter(fn ($line) => Inventory::tracked($line))
            ->keyBy(fn ($line) => $line['key'] ?? $line['sku']);
        if ($lines->isEmpty()) {
            return [];
        }
        $totals = $lines->groupBy('sku')->map(fn ($group) => (int) $group->sum('qty'));
        $reservations = [];
        $movements = $this->store->table('movements')->where('order_id', $order->id)->whereIn('reason', self::RESERVATIONS)
            ->groupBy('sku', 'location')->select('sku', 'location')->selectRaw('sum(delta) as movement')->orderBy('location')->get();
        foreach ($movements as $row) {
            if ($totals->has($row->sku) && $row->movement < 0) {
                $reservations[$row->sku][$row->location] = -(int) $row->movement;
            }
        }
        foreach ($totals as $sku => $total) {
            $missing = $total - array_sum($reservations[$sku] ?? []);
            if ($missing > 0) {
                $reservations[$sku]['main'] = ($reservations[$sku]['main'] ?? 0) + $missing;
            }
        }
        // Reserve the known shipment origins first; old manual parcels did not record an origin.
        $unknown = [];
        foreach ($parcels as $parcel) {
            $origin = $parcel->origin ?? $parcel->label_origin ?? null;
            foreach ($parcel->items as $key => $quantity) {
                if (!$lines->has($key)) {
                    continue;
                }
                $sku = $lines[$key]['sku'];
                $matched = $origin ? min($quantity, $reservations[$sku][$origin] ?? 0) : 0;
                if ($matched > 0) {
                    $reservations[$sku][$origin] -= $matched;
                }
                $unknown[$sku] = ($unknown[$sku] ?? 0) + $quantity - $matched;
            }
        }
        foreach ($unknown as $sku => $quantity) {
            foreach ($reservations[$sku] ?? [] as $origin => $available) {
                $used = min($available, $quantity);
                $reservations[$sku][$origin] -= $used;
                $quantity -= $used;
            }
        }
        return $reservations;
    }

    public function origin(object $order, Collection $parcels, array $items, ?string $requested): string
    {
        $reserved = $this->available($order, $parcels);
        $needed = [];
        foreach (json_decode($order->receipt, true)['lines'] as $line) {
            if (Inventory::tracked($line) && ($quantity = $items[$line['key'] ?? $line['sku']] ?? 0)) {
                $needed[$line['sku']] = ($needed[$line['sku']] ?? 0) + $quantity;
            }
        }
        $locations = $this->store->table('locations')->orderBy('code')->pluck('code');
        if ($requested !== null) {
            $locations = $locations->filter(fn ($location) => $location === $requested);
        } elseif ($locations->contains('main')) {
            $locations = $locations->reject(fn ($location) => $location === 'main')->prepend('main');
        }
        foreach ($locations as $location) {
            if (collect($needed)->every(fn ($qty, $sku) => ($reserved[$sku][$location] ?? 0) >= $qty)) {
                return $location;
            }
        }
        throw ValidationException::withMessages(['origin' => __('Choose quantities reserved at one dispatch location, or reassign the stock first.')]);
    }

    public function reassign(string $id, array $input, $user): void
    {
        $this->store->db()->transaction(function () use ($id, $input, $user) {
            $order = $this->store->table('orders')->where('id', $id)->lockForUpdate()->firstOrFail();
            $identity = ['id' => $input['request_id'], 'order_id' => $id, 'sku' => $input['sku'], 'source' => $input['from'],
                'destination' => $input['to'], 'quantity' => (int) $input['quantity']];
            if (!$this->store->table('order_reallocations')->insertOrIgnore($identity)) {
                abort_unless($this->store->table('order_reallocations')->where($identity)->exists(), 409);
                return;
            }
            abort_unless(in_array($order->status, ['paid', 'awaiting_collection'], true)
                && !app(CarrierLabels::class)->active($id) && !app(Amendments::class)->pending($id), 409);
            $allocation = $this->available($order, app(Fulfillment::class)->parcels($id));
            abort_unless(($allocation[$input['sku']][$input['from']] ?? 0) >= $input['quantity'], 409, __('The reservation changed. Reopen the order.'));
            $locations = $this->store->table('locations')->whereIn('code', [$input['from'], $input['to']])->count();
            abort_unless($locations === 2, 422);
            app(Inventory::class)->reassign($id, $input['sku'], $input['from'], $input['to'], (int) $input['quantity'], $user->email());
            $this->store->recordActivity($id, 'stock', "Reassigned {$input['quantity']} × {$input['sku']} from {$input['from']} to {$input['to']}.", $user->email());
        }, 3);
    }
}
