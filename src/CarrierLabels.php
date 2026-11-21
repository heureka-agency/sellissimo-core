<?php

namespace Sellissimo\Core;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CarrierLabels
{
    public function __construct(private Store $store, private Registry $registry, private Fulfillment $fulfillment) {}

    public function book(string $orderId, array $data, $user): void
    {
        $id = $data['request_id'];
        $items = array_filter(array_map('intval', $data['items']));
        [$label, $shipment] = $this->store->db()->transaction(function () use ($orderId, $data, $items, $id) {
            $order = $this->store->table('orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
            abort_if(app(Amendments::class)->pending($order->id), 409, 'Settle the open order amendment before changing delivery.');
            $receipt = json_decode($order->receipt, true, flags: JSON_THROW_ON_ERROR);
            $handle = explode('.', $receipt['shipping_method'] ?? '')[0];
            $existing = $this->store->table('labels')->where('id', $id)->first();
            if ($existing) {
                abort_unless($existing->order_id === $orderId && $existing->origin === $data['origin'] && json_decode($existing->items, true) == $items, 409);
                if ($existing->status !== 'booking') {
                    return [$existing, null];
                }
            } else {
                abort_unless(isset($this->registry->enabled('carriers')[$handle]), 422, 'No enabled carrier for this delivery method.');
                abort_unless(in_array($order->status, ['paid', 'awaiting_collection'], true), 409);
                abort_if($this->active($orderId), 409, 'Dispatch or void the current label first.');
                $remaining = $this->fulfillment->quantities($order, $this->fulfillment->parcels($orderId));
                if (! $items || collect($items)->contains(fn ($qty, $key) => $qty < 1 || $qty > ($remaining[$key]['remaining'] ?? 0))) {
                    throw ValidationException::withMessages(['items' => 'Choose quantities awaiting shipment.']);
                }
                app(ParcelLocations::class)->origin($order, $this->fulfillment->parcels($orderId), $items, $data['origin']);
                $this->store->table('labels')->insert(['id' => $id, 'order_id' => $orderId, 'provider' => $handle, 'origin' => $data['origin'], 'items' => json_encode($items), 'status' => 'booking']);
            }
            $lines = [];
            foreach (Pricing::publicReceipt($receipt)['lines'] as $line) {
                $key = $line['key'] ?? $line['sku'];
                if (isset($items[$key])) {
                    $lines[] = [...$line, 'qty' => $items[$key]];
                }
            }
            $shipment = ['order_id' => $orderId, 'site' => $order->site, 'currency' => $order->currency,
                'origin' => $data['origin'], 'service' => $receipt['shipping_method'], 'items' => $lines,
                'address' => DeliveryAddress::current($order, $receipt), 'instructions' => $receipt['shipping_instructions'] ?? null];

            return [$this->store->table('labels')->where('id', $id)->firstOrFail(), $shipment];
        }, 3);
        if ($shipment === null) {
            return;
        }
        // Keep network calls outside database locks. A retry uses the same carrier booking identifier.
        try {
            $result = $this->registry->registered('carriers', $label->provider)->book($id, $shipment);
        } catch (Contracts\BookingRejected $e) {
            $this->store->table('labels')->where('id', $id)->where('status', 'booking')->delete();
            throw ValidationException::withMessages(['carrier' => $e->getMessage()]);
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['carrier' => 'The carrier has not confirmed the booking. Retry this request to recover the label.']);
        }
        $fields = Validator::make($result, ['reference' => 'required|string|max:250', 'tracking' => 'required|string|max:250', 'label_url' => 'required|url:https|max:4096', 'tracking_url' => 'nullable|url:https|max:4096'])->validate();
        $this->store->db()->transaction(function () use ($id, $fields, $orderId, $user) {
            if ($this->store->table('labels')->where('id', $id)->where('status', 'booking')->update([...$fields, 'status' => 'booked'])) {
                $this->store->recordActivity($orderId, 'label', 'Carrier label booked. Tracking '.$fields['tracking'], $user->email());
            }
        });
    }

    public function active(string $orderId): ?object
    {
        return $this->store->table('labels')->where('order_id', $orderId)->whereIn('status', ['booking', 'booked', 'voiding'])->first();
    }

    public function cancel(string $orderId, string $id, $user): void
    {
        $label = $this->store->db()->transaction(function () use ($orderId, $id) {
            $this->store->table('orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
            $label = $this->store->table('labels')->where('order_id', $orderId)->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($label->status, ['booked', 'voiding', 'void'], true), 409);
            if ($label->status === 'booked') {
                $this->store->table('labels')->where('id', $id)->update(['status' => 'voiding']);
            }

            return $label;
        }, 3);
        if ($label->status === 'void') {
            return;
        }
        try {
            $this->registry->registered('carriers', $label->provider)->void($id, $label->reference);
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['carrier' => 'The carrier has not confirmed cancellation. Retry to check this label.']);
        }
        $this->store->db()->transaction(function () use ($id, $orderId, $user) {
            if ($this->store->table('labels')->where('id', $id)->where('status', 'voiding')->update(['status' => 'void'])) {
                $this->store->recordActivity($orderId, 'label', 'Carrier label voided.', $user->email());
            }
        });
    }

    /** Called by a carrier plugin after authenticating its tracking notification. */
    public function updateTracking(string $provider, string $id, string $status, \DateTimeInterface $occurredAt, bool $delivered = false): void
    {
        Validator::make(['status' => $status], ['status' => 'required|string|max:150'])->validate();
        $time = CarbonImmutable::instance($occurredAt)->utc()->toDateTimeString();
        $this->store->db()->transaction(function () use ($provider, $id, $status, $time, $delivered) {
            $query = $this->store->table('labels')->where('id', $id)->where('provider', $provider);
            $orderId = (clone $query)->firstOrFail(['order_id'])->order_id;
            $this->store->table('orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
            $label = $query->lockForUpdate()->firstOrFail();
            if ($label->status === 'void' || ($label->tracking_updated_at && $label->tracking_updated_at >= $time)) {
                return;
            }
            $this->store->table('labels')->where('id', $id)->update(['tracking_status' => $status, 'tracking_updated_at' => $time,
                ...($delivered || strtolower(trim($status)) === 'delivered' ? ['delivered_at' => $time] : [])]);
            if (($delivered || strtolower(trim($status)) === 'delivered') && $this->store->table('parcels')->where('id', $id)->exists()) {
                app(Fulfillment::class)->confirmDelivery($orderId, $id, $time);
            }
            $this->store->recordActivity($label->order_id, 'tracking', $status, $provider);
        }, 3);
    }
}
