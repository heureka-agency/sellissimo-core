<?php

namespace Sellissimo\Core;

use Illuminate\Support\Arr;

final class CartCurrency
{
    public function __construct(private Store $store, private Catalog $catalog, private Pricing $pricing) {}

    public function stale(object $cart): bool
    {
        return empty($cart->retry_order_id) && $cart->currency !== $this->catalog->currency($cart->site);
    }

    /** The same calculation is repeated under lock when the buyer or draft editor confirms. */
    public function preview(object $cart): array
    {
        abort_unless($this->stale($cart), 409, __('This cart already uses its selling currency.'));
        $target = $this->catalog->currency($cart->site);
        $copy = clone $cart;
        $copy->currency = $target;
        $source = json_decode($cart->items, true, flags: JSON_THROW_ON_ERROR);
        $titles = json_decode($cart->line_titles ?? '{}', true);
        $items = [];
        $removed = [];
        $customPrices = [];
        foreach ($source as $key => $item) {
            if (is_array($item) && isset($item['service'])) {
                $removed[] = ['title' => $item['service'], 'qty' => $item['qty']];
                continue;
            }
            if (is_array($item) && isset($item['staff_price'])) {
                $customPrices[] = $titles[$key] ?? $item['sku'];
                unset($item['staff_price']);
            }
            $items[$key] = $item;
        }
        $copy->items = json_encode($items, JSON_THROW_ON_ERROR);
        $receipt = $this->pricing->calculate($copy, [], cartPreview: true);
        foreach ($receipt['unavailable'] ?? [] as $row) {
            $item = $items[$row['key']];
            $removed[] = ['title' => $row['title'], 'qty' => is_array($item) ? $item['qty'] : $item];
            unset($items[$row['key']]);
        }
        $available = $receipt['stock_shortages'];
        foreach ($receipt['lines'] as $line) {
            if (!array_key_exists($line['sku'], $available)) {
                continue;
            }
            $qty = min($line['qty'], max(0, $available[$line['sku']]));
            $available[$line['sku']] -= $qty;
            if ($qty === $line['qty']) {
                continue;
            }
            $removed[] = ['title' => $line['title'], 'qty' => $line['qty'] - $qty];
            if (!$qty) {
                unset($items[$line['key']]);
            } elseif (is_array($items[$line['key']])) {
                $items[$line['key']]['qty'] = $qty;
            } else {
                $items[$line['key']] = $qty;
            }
        }
        if ($removed) {
            $copy->items = json_encode($items, JSON_THROW_ON_ERROR);
            $receipt = $this->pricing->calculate($copy, [], cartPreview: true);
        }
        $lines = collect($receipt['lines'])->map(fn ($line) => ['title' => $line['title'], 'qty' => $line['qty'],
            'price' => Money::display($line['price'], $target)])->values()->all();
        $preview = ['from' => $cart->currency, 'to' => $target, 'lines' => $lines, 'removed' => $removed, 'customPrices' => $customPrices,
            'subtotal' => Money::display($receipt['subtotal'], $target)];
        $preview['digest'] = hash('sha256', json_encode([$cart->id, $cart->items, $target, $items, $preview, Pricing::fingerprint($receipt)], JSON_THROW_ON_ERROR));
        return ['preview' => $preview, 'items' => $items];
    }

    /** Caller owns a transaction and holds the cart lock. */
    public function apply(object $cart, string $digest, bool $draft = false): array
    {
        abort_if($this->store->table('orders')->where('cart_id', $cart->id)->exists(), 409);
        abort_unless($draft || !$this->store->table('drafts')->where('cart_id', $cart->id)->exists(), 409);
        $result = $this->preview($cart);
        abort_unless(hash_equals($result['preview']['digest'], $digest), 409, __('Prices or availability changed. Review the updated cart.'));
        if ($cart->customer !== null && !$draft) {
            $scope = hash('sha256', json_encode([(string) $cart->customer, $cart->site, $result['preview']['to']], JSON_THROW_ON_ERROR));
            $mapping = $this->store->table('account_carts')->where('scope', $scope);
            $mapping->insertOrIgnore(['scope' => $scope]);
            $target = $mapping->lockForUpdate()->first()->cart_id;
            abort_if($target && $target !== $cart->id && $this->store->table('carts')->where('id', $target)->exists()
                && !$this->store->table('orders')->where('cart_id', $target)->exists(), 409, __('Finish your existing cart in the new currency before rebuilding this cart.'));
            $this->store->table('account_carts')->where('cart_id', $cart->id)->update(['cart_id' => null]);
            $mapping->update(['cart_id' => $cart->id]);
        }
        $this->store->table('carts')->where('id', $cart->id)->update(['currency' => $result['preview']['to'],
            'items' => json_encode($result['items'], JSON_THROW_ON_ERROR), 'line_count' => count($result['items']),
            'line_titles' => json_encode(Arr::only(json_decode($cart->line_titles ?? '{}', true), array_keys($result['items'])), JSON_THROW_ON_ERROR),
            'currency_review' => !$draft, 'active_at' => now()]);
        return $result['preview'];
    }
}
