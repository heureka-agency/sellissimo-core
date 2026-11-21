<?php

namespace Sellissimo\Core;

use Illuminate\Validation\ValidationException;

class Inventory
{
    public static function tracked(array $line): bool
    {
        return !$line['digital'] && ($line['track_stock'] ?? true);
    }

    public function __construct(private Store $store) {}

    public function catalogLevels(string $site, $user, bool $includeArchived = false): \Illuminate\Support\Collection
    {
        $products = collect(app(Catalog::class)->stockProducts($site, $user));
        $rows = $this->store->table('stock')->pluck('available', 'sku');
        $skus = $includeArchived ? $products->keys()->merge($rows->keys())->unique() : $products->keys();

        return $skus->mapWithKeys(fn ($sku) => [$sku => [
            'sku' => (string) $sku, 'title' => $products->get($sku), 'available' => (int) $rows->get($sku, 0),
            'recorded' => $rows->has($sku),
        ]]);
    }

    /** Null means that the staff member may also manage warehouse-only and archived stock. */
    public function staffSkus(): ?array
    {
        if (StaffSites::storewide()) {
            return null;
        }
        $catalog = app(Catalog::class);
        $skus = [];
        foreach (StaffSites::handles() as $site) {
            $skus += $catalog->stockProducts($site, request()->user());
        }

        return array_map('strval', array_keys($skus));
    }

    public function authorizeStaffSku(string $sku): void
    {
        $allowed = $this->staffSkus();
        abort_unless($allowed === null || in_array($sku, $allowed, true), 404);
    }

    public function balances(string $sku, int $total): array
    {
        $other = $this->store->table('balances')->where('sku', $sku)->orderBy('location')->pluck('quantity', 'location')->map(fn ($n) => (int) $n)->all();

        return ['main' => $total - array_sum($other), ...$other];
    }

    private function lock(string $sku): int
    {
        $this->store->table('stock')->insertOrIgnore(['sku' => $sku, 'available' => 0]);

        return (int) $this->store->table('stock')->where('sku', $sku)->lockForUpdate()->firstOrFail()->available;
    }

    private function move(string $sku, string $location, int $delta, int $before, string $reason, ?string $actor, ?string $order = null, ?string $transfer = null): void
    {
        $after = $before + $delta;
        abort_if($after < 0 || $after > 999999999, 409, 'The location has insufficient stock or the new quantity exceeds the limit.');
        if ($location !== 'main') {
            $this->store->table('balances')->upsert([['sku' => $sku, 'location' => $location, 'quantity' => $after]], ['sku', 'location'], ['quantity']);
        }
        $this->store->table('movements')->insert(['sku' => $sku, 'location' => $location, 'delta' => $delta, 'balance' => $after,
            'reason' => $reason, 'actor' => $actor, 'order_id' => $order, 'transfer_id' => $transfer, 'created_at' => now()]);
    }

    public function setTotal(string $sku, int $quantity, array $input, string $actor): void
    {
        $this->store->db()->transaction(function () use ($sku, $quantity, $input, $actor) {
            if (($input['mode'] ?? null) === 'create' && ! $this->store->table('stock')->insertOrIgnore(['sku' => $sku, 'available' => 0])) {
                throw ValidationException::withMessages(['sku' => 'This SKU already has a stock level. Edit its existing row.']);
            }
            if (($input['mode'] ?? null) === 'edit' && ! $this->store->table('stock')->where('sku', $sku)->exists()) {
                throw ValidationException::withMessages(['sku' => 'This stock record no longer exists.']);
            }
            $before = $this->lock($sku);
            if (($input['mode'] ?? null) === 'edit' && $before !== (int) $input['previous']) {
                throw ValidationException::withMessages(['quantity' => 'Stock changed since you opened this item. Close and reopen it to see the latest quantity.']);
            }
            $this->change($sku, $quantity - $before, $input['reason'] ?? 'Stock count', $actor);
        }, 3);
    }

    // The caller owns the order or inventory transaction. Lock the SKU before touching its locations.
    public function change(string $sku, int $delta, string $reason, ?string $actor = null, ?string $order = null, ?string $location = null): array
    {
        $total = $this->lock($sku);
        abort_if($total + $delta < 0, 409, __('sellissimo::shop.insufficient_stock_for_sku', ['sku' => $sku]));
        abort_if($total + $delta > 999999999, 422, 'Stock exceeds the supported maximum.');
        $balances = $this->balances($sku, $total);
        $movements = [];
        if ($delta >= 0 || $location !== null) {
            $location ??= 'main';
            $movements[$location] = $delta;
            $this->move($sku, $location, $delta, $balances[$location] ?? 0, $reason, $actor, $order);
        } else {
            $needed = -$delta;
            foreach ($balances as $at => $available) {
                $take = min($needed, $available);
                if ($take > 0) {
                    $movements[$at] = -$take;
                    $this->move($sku, $at, -$take, $available, $reason, $actor, $order);
                    $needed -= $take;
                }
                if (! $needed) {
                    break;
                }
            }
        }
        $available = $total + $delta;
        $this->store->table('stock')->where('sku', $sku)->update(['available' => $available]);
        $threshold = max(0, (int) OperationalSettings::shared('low_stock_threshold', 'low_stock_threshold', 5));
        $topic = match (true) {
            $total > 0 && $available === 0 => 'stock.exhausted',
            $total > $threshold && $available > 0 && $available <= $threshold => 'stock.low',
            default => null,
        };
        if ($topic) {
            app(Webhooks::class)->activity($topic, (string) \Illuminate\Support\Str::uuid(), [
                'sku' => $sku, 'previous' => $total, 'available' => $available, 'threshold' => $threshold, 'order_id' => $order,
            ]);
        }
        return $movements;
    }

    // Record the change of owner at the original location without changing sellable stock.
    public function assignToOrder(string $sku, string $location, int $quantity, string $order, ?string $actor): void
    {
        $balances = $this->balances($sku, $this->lock($sku));
        $before = $balances[$location] ?? 0;
        $this->move($sku, $location, $quantity, $before, 'Draft reservation transferred', $actor);
        $this->move($sku, $location, -$quantity, $before + $quantity, 'Order reserved', $actor, $order);
    }

    public function release(string $order, string $sku, int $quantity): void
    {
        $allocations = $this->store->table('movements')->where('order_id', $order)->where('sku', $sku)->where('reason', 'Order reserved')
            ->groupBy('location')->select('location')->selectRaw('sum(delta) as reserved')->get();
        if ($allocations->isEmpty()) {
            $this->change($sku, $quantity, 'Reservation released', order: $order);
        } else {
            foreach ($allocations as $allocation) {
                $this->change($sku, -(int) $allocation->reserved, 'Reservation released', order: $order, location: $allocation->location);
            }
        }
    }

    public function releaseReserved(string $order, string $sku, int $quantity, string $actor, string $reason): void
    {
        $held = $this->store->table('movements')->where('order_id', $order)->where('sku', $sku)
            ->whereIn('reason', ParcelLocations::RESERVATIONS)->groupBy('location')
            ->select('location')->selectRaw('sum(delta) as net')->orderBy('location')->get();
        foreach ($held as $balance) {
            $part = min($quantity, max(0, -(int) $balance->net));
            if ($part) {
                $this->change($sku, $part, $reason, $actor, $order, $balance->location);
                $quantity -= $part;
            }
        }
        if ($quantity) {
            $this->change($sku,$quantity,$reason,$actor,$order);
        }
    }

    /** Change the warehouse picking the order while preserving the total available stock. */
    public function reassign(string $order, string $sku, string $from, string $to, int $quantity, string $actor): void
    {
        $balances = $this->balances($sku, $this->lock($sku));
        if (($balances[$to] ?? 0) < $quantity) {
            throw ValidationException::withMessages(['to' => __('The destination has insufficient available stock.')]);
        }
        $this->move($sku, $from, $quantity, $balances[$from] ?? 0, 'Fulfillment reassigned', $actor, $order);
        $this->move($sku, $to, -$quantity, $balances[$to], 'Fulfillment reassigned', $actor, $order);
    }

    public function countLocation(string $sku, string $location, int $count, int $previous, string $reason, string $actor): void
    {
        $this->store->db()->transaction(function () use ($sku, $location, $count, $previous, $reason, $actor) {
            $total = $this->lock($sku);
            $actual = $this->balances($sku, $total)[$location] ?? 0;
            if ($actual !== $previous) {
                throw ValidationException::withMessages(['previous' => 'Stock changed at this location. Reload the balances before saving.']);
            }
            if ($count !== $actual) {
                $this->change($sku, $count - $actual, $reason, $actor, location: $location);
            }
        }, 3);
    }

    public function transfer(array $input, string $actor): void
    {
        $this->store->db()->transaction(function () use ($input, $actor) {
            $sku = $input['sku'];
            $qty = (int) $input['quantity'];
            $total = $this->lock($sku);
            $identity = ['id' => $input['request_id'], 'sku' => $sku, 'source' => $input['from'], 'destination' => $input['to'], 'quantity' => $qty];
            if (! $this->store->table('transfers')->insertOrIgnore($identity)) {
                abort_unless($this->store->table('transfers')->where($identity)->exists(), 409, 'This transfer identifier has already been used.');

                return;
            }
            $balances = $this->balances($sku, $total);
            foreach ([$input['from'] => -$qty, $input['to'] => $qty] as $location => $delta) {
                $this->move($sku, $location, $delta, $balances[$location] ?? 0, 'Location transfer', $actor, transfer: $input['request_id']);
            }
        }, 3);
    }
}
