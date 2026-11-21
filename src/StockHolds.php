<?php

namespace Sellissimo\Core;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StockHolds
{
    public function __construct(private Store $store, private Inventory $inventory) {}

    // The caller locks the owning cart or return. Keep allocations at their original locations.
    public function adjust(array $held, array $wanted, string $reason, ?string $actor, ?string $order = null): array
    {
        $skus = array_unique([...array_keys($held), ...array_keys($wanted)]);
        sort($skus, SORT_STRING);
        foreach ($skus as $sku) {
            $locations = $held[$sku] ?? [];
            $diff = ($wanted[$sku] ?? 0) - array_sum($locations);
            if ($diff > 0) {
                try {
                    $changes = $this->inventory->change($sku, -$diff, $reason, $actor, $order);
                } catch (HttpException $e) {
                    if ($e->getStatusCode() !== 409) {
                        throw $e;
                    }
                    throw ValidationException::withMessages(['stock' => $e->getMessage()]);
                }
                foreach ($changes as $location => $delta) {
                    $locations[$location] = ($locations[$location] ?? 0) - $delta;
                }
            } elseif ($diff < 0) {
                foreach ($locations as $location => $quantity) {
                    $release = min($quantity, -$diff);
                    $this->inventory->change($sku, $release, $reason, $actor, $order, $location);
                    $locations[$location] -= $release;
                    $diff += $release;
                    if ($diff === 0) {
                        break;
                    }
                }
            }
            $held[$sku] = array_filter($locations);
        }

        return array_filter($held);
    }

    public function saveDraft(object $draft, array $wanted, ?Carbon $until, ?string $actor): void
    {
        $held = $this->adjust(json_decode($draft->reserved_stock ?? '[]', true), $until ? $wanted : [], 'Draft stock reservation', $actor);
        $this->store->table('drafts')->where('id', $draft->id)->update([
            'reserved_stock' => $held ? json_encode($held, JSON_THROW_ON_ERROR) : null,
            'reserve_until' => $held ? $until : null,
        ]);
    }

    public function forCart(object $cart): ?object
    {
        if (empty($cart->field_scope) || empty($cart->id)) {
            return null;
        }

        return $this->store->table('drafts')->where('id', $cart->field_scope)->where('cart_id', $cart->id)->first();
    }

    public function assignDraft(object $cart, array $lines, string $order, ?string $actor): array
    {
        $draft = $this->forCart($cart);
        if (!$draft?->reserved_stock) {
            return [];
        }
        $held = json_decode($draft->reserved_stock, true);
        $covered = [];
        foreach ($lines as $line) {
            if (Inventory::tracked($line)) {
                $sku = $line['sku'];
                $covered[$sku] = min(array_sum($held[$sku] ?? []), ($covered[$sku] ?? 0) + $line['qty']);
            }
        }
        $held = $this->adjust($held, $covered, 'Draft reservation released', $actor);
        foreach ($held as $sku => $locations) {
            foreach ($locations as $location => $qty) {
                $this->inventory->assignToOrder($sku, $location, $qty, $order, $actor);
            }
        }
        $this->store->table('drafts')->where('id', $draft->id)->update(['reserved_stock' => null, 'reserve_until' => null]);

        return $covered;
    }

    // Release overdue holds before stock-sensitive operations, outside the caller's cart/order locks.
    public function expireDue(): void
    {
        if ($this->store->db()->transactionLevel() > 0) {
            return;
        }
        foreach ($this->store->table('drafts')->where('reserve_until', '<=', now())->select('id')->lazyById(100) as $row) {
            $this->expireDraft($row->id);
        }
    }

    public function expireDraft(string $id): void
    {
        $this->store->db()->transaction(function () use ($id) {
            $drafts = $this->store->table('drafts')->where('id', $id);
            $this->store->table('carts')->where('id', (clone $drafts)->value('cart_id'))->lockForUpdate()->first();
            $draft = $drafts->where('reserve_until', '<=', now())->lockForUpdate()->first();
            if ($draft) {
                $this->saveDraft($draft, [], null, null);
            }
        }, 3);
    }
}
