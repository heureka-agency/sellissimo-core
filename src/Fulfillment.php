<?php

namespace Sellissimo\Core;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Fulfillment
{
    public function __construct(private Store $store) {}

    public function parcels(string $order)
    {
        return $this->store->table('parcels')->leftJoin('sellissimo_labels as label', 'label.id', '=', 'sellissimo_parcels.id')
            ->where('sellissimo_parcels.order_id', $order)->select('sellissimo_parcels.*', 'label.tracking_status', 'label.origin as label_origin')->orderBy('number')->get()
            ->each(fn ($parcel) => $parcel->items = json_decode($parcel->items, true, flags: JSON_THROW_ON_ERROR));
    }

    public function quantities(object $order, $parcels, ?string $exceptRequest = null): array
    {
        $returned = $this->store->table('returns')->where('order_id', $order->id)->groupBy('line_key')
            ->select('line_key')->selectRaw('sum(quantity) as quantity')->pluck('quantity', 'line_key');
        $pending = $this->store->table('return_requests')->where('order_id',$order->id)->whereIn('status',['requested','approved'])
            ->when($exceptRequest,fn ($q)=>$q->where('id','!=',$exceptRequest))->groupBy('line_key')
            ->select('line_key')->selectRaw('sum(quantity) as qty')->pluck('qty','line_key');
        $lines = [];
        foreach (json_decode($order->receipt, true, flags: JSON_THROW_ON_ERROR)['lines'] as $line) {
            if ($line['digital']) {
                continue;
            }
            $key = $line['key'] ?? $line['sku'];
            $shipped = $parcels->sum(fn ($parcel) => $parcel->items[$key] ?? 0);
            $lines[$key] = ['sku' => $line['sku'], 'track_stock' => Inventory::tracked($line), 'remaining' => $line['qty'] - $shipped, 'returnable' => $shipped - (int) $returned->get($key, 0) - (int)$pending->get($key,0)];
        }

        return $lines;
    }

    public function ship(string $orderId, array $input, $user): string
    {
        return $this->store->db()->transaction(function () use ($orderId, $input, $user) {
            $order = $this->store->table('orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
            abort_if(app(Amendments::class)->pending($order->id), 409, 'Settle the open order amendment before changing delivery.');
            $parcels = $this->parcels($orderId);
            $explicit = isset($input['items']);
            $items = $explicit ? array_filter(array_map('intval', $input['items'])) : null;
            $previous = isset($input['request_id']) ? $parcels->firstWhere('id', $input['request_id']) : $parcels->firstWhere('tracking', $input['tracking']);
            if ($previous) {
                abort_unless($previous->tracking === $input['tracking'] && (! isset($input['tracking_url']) || $previous->tracking_url === $input['tracking_url']) && (! isset($input['origin']) || $previous->origin === $input['origin']) && (! $explicit || $items == $previous->items), 409);
                return $previous->id;
            }
            abort_unless(in_array($order->status, ['paid', 'awaiting_collection'], true), 409);
            $lines = $this->quantities($order, $parcels);
            abort_unless($lines, 422);
            $remaining = array_map(fn ($line) => $line['remaining'], $lines);
            $items ??= array_filter($remaining);
            if (! $items) {
                throw ValidationException::withMessages(['items' => 'Choose at least one unit to ship.']);
            }
            foreach ($items as $key => $count) {
                if ($count > ($remaining[$key] ?? 0)) {
                    throw ValidationException::withMessages(['items' => 'The selected quantity exceeds the units awaiting shipment.']);
                }
            }
            $label = app(CarrierLabels::class)->active($orderId);
            if ($label) {
                abort_unless($label->status === 'booked' && $label->id === ($input['request_id'] ?? null) && $label->tracking === $input['tracking'] && json_decode($label->items, true) == $items, 409, 'Use the booked label or void it first.');
                $this->store->table('labels')->where('id', $label->id)->update(['status' => 'dispatched']);
            }
            $origin = app(ParcelLocations::class)->origin($order, $parcels, $items, $label->origin ?? $input['origin'] ?? null);
            abort_if($label && isset($input['origin']) && $input['origin'] !== $origin, 409);
            $id = $input['request_id'] ?? (string) Str::uuid();
            $units = array_sum($items);
            $partial = array_sum($remaining) > $units;
            $queued = ($input['notify'] ?? false) ? now() : null;
            $this->store->table('parcels')->insert(['id' => $id, 'order_id' => $orderId, 'number' => $parcels->count() + 1, 'origin' => $origin, 'tracking' => $input['tracking'],
                'tracking_url' => $label->tracking_url ?? $input['tracking_url'] ?? null,
                'items' => json_encode($items, JSON_THROW_ON_ERROR), 'created_at' => now(), 'queued_at' => $queued,
                'paid_at_dispatch' => $order->status === 'paid',
                'delivered_at' => null]);
            $this->store->table('orders')->where('id', $orderId)->update(['partially_shipped' => $partial,
                'tracking' => $input['tracking'], 'delivery_queued_at' => $queued, 'delivery_emailed_at' => null]);
            $this->store->recordActivity($orderId, 'shipment', "Shipped $units ".Str::plural('unit', $units).'. Tracking number '.$input['tracking'], $user->name() ?: $user->email(), (string) $user->id());
            $details = ['parcel' => $id, 'origin' => $origin, 'tracking' => $input['tracking'], 'items' => $items];
            app(Webhooks::class)->enqueue('parcel.shipped', $order, $id, $details);
            if (!$partial) {
                app(Webhooks::class)->enqueue('order.shipped', $order, $orderId, $details);
            }
            $this->store->db()->afterCommit(function () use ($orderId, $id, $partial, $input) {
                event('sellissimo.parcel.shipped', [$orderId, $id]);
                if (! $partial) {
                    event('sellissimo.order.shipped', [$orderId, $input['tracking']]);
                }
            });
            $deliveredAt = $label->delivered_at ?? ($label && strtolower(trim($label->tracking_status ?? '')) === 'delivered' ? $label->tracking_updated_at : null);
            if ($deliveredAt) {
                $this->confirmDelivery($orderId, $id, $deliveredAt);
            }
            return $id;
        }, 3);
    }

    public function confirmDelivery(string $orderId, string $parcelId, string $time, ?string $actor = null): void
    {
        $this->store->db()->transaction(function () use ($orderId, $parcelId, $time, $actor) {
            $order = $this->store->table('orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
            $parcels = $this->store->table('parcels')->where('order_id', $orderId);
            $parcel = (clone $parcels)->where('id', $parcelId)->lockForUpdate()->firstOrFail();
            if ($parcel->delivered_at) {
                return;
            }
            (clone $parcels)->where('id', $parcelId)->update(['delivered_at' => $time]);
            $details = ['parcel' => $parcelId, 'number' => (int) $parcel->number, 'tracking' => $parcel->tracking, 'delivered_at' => $time];
            app(Webhooks::class)->activity('parcel.delivered', $parcelId, $details, $order);
            if (! $order->partially_shipped && ! (clone $parcels)->whereNull('delivered_at')->exists()) {
                app(Webhooks::class)->activity('order.delivered', $orderId, $details, $order);
            }
            if ($actor !== null) {
                $this->store->recordActivity($orderId, 'delivery', __('Parcel :number delivered.', ['number' => $parcel->number]), $actor);
            }
        }, 3);
    }

    public function receive(string $orderId, array $input, $user, ?string $exceptRequest = null): void
    {
        $this->store->db()->transaction(function () use ($orderId, $input, $user, $exceptRequest) {
            $order = $this->store->table('orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
            $key = $input['line_key'];
            $qty = (int) $input['quantity'];
            $restock = (int) $input['restocked'];
            $location = $restock ? ($input['location'] ?? null) : null;
            if ($restock && !$location) {
                $origins = $this->parcels($orderId)->filter(fn ($parcel) => isset($parcel->items[$key]))
                    ->map(fn ($parcel) => $parcel->origin ?? $parcel->label_origin ?? 'main')->unique();
                if ($origins->count() > 1) {
                    throw ValidationException::withMessages(['location' => __('Choose a receiving location.')]);
                }
                $location = $origins->first() ?? 'main';
            }
            if ($location && !$this->store->table('locations')->where('code', $location)->exists()) {
                throw ValidationException::withMessages(['location' => __('Choose a receiving location.')]);
            }
            if ($previous = $this->store->table('returns')->where('id', $input['request_id'])->first()) {
                abort_unless($previous->order_id === $orderId && $previous->line_key === $key && (int) $previous->quantity === $qty && (int) $previous->restocked === $restock && (!$restock || ($previous->location ?? 'main') === $location), 409);
                return;
            }
            $line = $this->quantities($order, $this->parcels($orderId),$exceptRequest)[$key] ?? null;
            if (! $line || $qty > $line['returnable'] || $restock > $qty || ($restock && !$line['track_stock'])) {
                throw ValidationException::withMessages(['quantity' => 'Check the delivered, returned and restocked quantities.']);
            }
            $this->store->table('returns')->insert(['id' => $input['request_id'], 'order_id' => $orderId,
                'line_key' => $key, 'quantity' => $qty, 'restocked' => $restock, 'location' => $location, 'created_at' => now()]);
            if ($restock) {
                app(Inventory::class)->change($line['sku'],$restock,'Return restocked', $user->email(), $orderId, $location);
            }
            app(Webhooks::class)->activity('return.received', $input['request_id'], [
                'return_id' => $input['request_id'], 'line_key' => $key, 'sku' => $line['sku'], 'quantity' => $qty, 'restocked' => $restock, 'location' => $location,
            ], $order);
            $this->store->recordActivity($orderId, 'return', "Received $qty × {$line['sku']}. Restocked $restock.", $user->name() ?: $user->email(), (string) $user->id());
        }, 3);
    }
}
