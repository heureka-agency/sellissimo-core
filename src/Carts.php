<?php

namespace Sellissimo\Core;

use Illuminate\Support\Str;

class Carts
{
    public function __construct(private Store $store, private Catalog $catalog) {}

    public function create(string $site): array
    {
        $id = (string) Str::uuid();
        $token = Str::random(64);
        $this->store->table('carts')->insert(['id' => $id, 'token_hash' => hash('sha256', $token), 'site' => $site, 'currency' => $this->catalog->currency($site), 'items' => '{}', 'active_at' => now(), 'created_at' => now()]);

        return ['id' => $id, 'token' => $token];
    }

    public function get(string $id, ?string $token, ?string $customer = null): object
    {
        $cart = $this->store->table('carts')->where('id', $id)->first();
        abort_unless($cart && ($cart->customer !== null ? $cart->customer === $customer : ($token && hash_equals($cart->token_hash, hash('sha256', $token)))), 404);

        return $cart;
    }

    public function set(object $cart, string $sku, int $quantity, ?array $options = null, ?string $lineKey = null, bool $increment = false, ?array $custom = null): void
    {
        $this->store->db()->transaction(function () use ($cart, $sku, $quantity, $options, $lineKey, $increment, $custom) {
            $owner = $cart->customer;
            $cart = $this->store->table('carts')->where('id', $cart->id)->lockForUpdate()->firstOrFail();
            abort_unless($cart->customer === $owner, 409, 'This cart has moved to a customer account.');
            abort_if($this->store->table('orders')->where('cart_id', $cart->id)->exists(), 409, 'Checkout already started.');
            if ($cart->currency !== $this->catalog->currency($cart->site)) {
                abort(409, empty($cart->retry_order_id) ? __('Review the currency change before editing this cart.')
                    : __('A payment retry in the original currency keeps its original items.'));
            }
            $items = json_decode($cart->items, true);
            abort_unless($quantity >= 0 && $quantity <= 999, 422);
            if ($lineKey !== null) {
                $existing = $items[$lineKey] ?? null;
                if ($quantity === 0 && $existing === null) {
                    return;
                }
                abort_unless($existing !== null && (is_array($existing) ? $existing['sku'] : $lineKey) === $sku, 422, 'Unknown cart line.');
                $custom = array_replace(is_array($existing) ? ($existing['line_fields'] ?? []) : [], $custom ?? []);
                $options = array_replace(is_array($existing) ? ($existing['options'] ?? []) : [], $options ?? []);
            }
            $titles = json_decode($cart->line_titles ?? '{}', true);
            if ($quantity === 0) {
                unset($items[$lineKey ?? $sku], $titles[$lineKey ?? $sku]);
            } else {
                $product = $this->catalog->products($cart->site, false, [$sku], $cart->currency)[$sku] ?? null;
                abort_unless($product, 422, 'SKU unavailable.');
                app(PurchasePrerequisites::class)->check($product, $cart->site, $cart->customer);
                $values = LineOptions::validate($product['option_fields'], $options ?? []);
                $custom = app(CommerceFields::class)->values('line', $custom ?? [], CommerceFields::scope($cart), false, true);
                $key = LineOptions::key($sku, $values, $custom);
                $titles[$key] = $product['title'];
                $moving = $lineKey !== null && $key !== $lineKey;
                if ($moving) {
                    unset($items[$lineKey], $titles[$lineKey]);
                }
                if ($increment || $moving) {
                    $previous = $items[$key] ?? 0;
                    $quantity += is_array($previous) ? $previous['qty'] : $previous;
                    abort_if($quantity > 999, 422, 'This quantity exceeds the limit per line.');
                }
                $items[$key] = $values || $custom ? ['sku' => $sku, 'qty' => $quantity, 'options' => $values, 'line_fields' => $custom] : $quantity;
            }
            abort_if(count($items) > 100, 422, 'Cart line limit reached.');
            $this->store->table('carts')->where('id', $cart->id)->update(['line_titles' => json_encode($titles, JSON_THROW_ON_ERROR), 'active_at' => now(), 'line_count' => count($items), 'items' => json_encode($items, JSON_THROW_ON_ERROR)]);
        }, 3);
    }
}
