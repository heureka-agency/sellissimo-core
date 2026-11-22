<?php

namespace Sellissimo\Core;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AccountCarts
{
    public function __construct(private Store $store, private Carts $carts, private Catalog $catalog) {}

    public function restore(Request $request, string $site, bool $create = false, ?string $customer = null): ?array
    {
        $sessionKey = 'sellissimo.cart.'.$site;
        $saved = $request->session()->get($sessionKey);
        $customer ??= $request->user()?->getAuthIdentifier();
        if (isset($saved['retry'])) {
            $active = $this->store->table('carts')->where('id', $saved['id'])->where('retry_order_id', $saved['retry'])->exists()
                && ! $this->store->table('orders')->where('cart_id', $saved['id'])->exists();
            if (($saved['buyer'] ?? null) === $customer && $active) {
                return $saved;
            }
            PaymentRetry::finish($request, $site);
            $saved = $request->session()->get($sessionKey);
        }
        if ($customer === null) {
            if ($saved && ! empty($saved['token'])) {
                return $saved;
            }

            return $create ? $this->carts->create($site) : null;
        }
        $currency = $this->catalog->currency($site);
        $scope = $this->accountScope((string) $customer, $site, $currency);
        $key = $this->store->db()->transaction(function () use ($scope, $site, $currency, $customer, $saved, $create, $request) {
            $mapping = $this->store->table('account_carts')->where('scope', $scope);
            $mapping->insertOrIgnore(['scope' => $scope]);
            $active = $mapping->lockForUpdate()->first()->cart_id;
            $target = $this->open($active);
            $guest = ! empty($saved['token']) ? $this->open($saved['id']) : null;
            if ($guest && $guest->site === $site && $guest->customer === null && $guest->currency !== $currency
                && hash_equals($guest->token_hash, hash('sha256', $saved['token']))) {
                return $saved;
            }
            if (! $target && ! $guest) {
                // Currency-specific mappings from other devices still belong to this account.
                $previous = $this->store->table('carts as c')->join('sellissimo_account_carts as a', 'a.cart_id', '=', 'c.id')
                    ->leftJoin('sellissimo_orders as o', 'o.cart_id', '=', 'c.id')->whereNull('o.id')
                    ->where('c.site', $site)->where('c.customer', (string) $customer)->where('c.currency', '!=', $currency)
                    ->where('c.line_count', '>', 0)->orderByDesc('c.active_at')->value('c.id');
                if ($previous) {
                    return ['id' => $previous, 'token' => null];
                }
            }
            if ($guest && ($guest->customer !== null || $guest->site !== $site || $guest->currency !== $currency || ! hash_equals($guest->token_hash, hash('sha256', $saved['token'])))) {
                $guest = null;
            }
            if (! $target && ! $guest && ! $create) {
                return null;
            }
            $id = $target->id ?? $guest->id ?? $this->carts->create($site)['id'];
            if ($target && $guest && $target->id !== $guest->id) {
                $items = json_decode($target->items, true);
                foreach (json_decode($guest->items, true) as $key => $item) {
                    $qty = (is_array($item) ? $item['qty'] : $item) + (is_array($items[$key] ?? null) ? $items[$key]['qty'] : ($items[$key] ?? 0));
                    if ($qty > 999) {
                        $request->session()->flash('sellissimo.cart_notice', 'Your account cart is saved separately. Reduce this cart’s quantities to combine them, or check out this cart first.');

                        return $saved;
                    }
                    $items[$key] = is_array($item) ? [...$item, 'qty' => $qty] : $qty;
                }
                if (count($items) > 100) {
                    $request->session()->flash('sellissimo.cart_notice', 'Your account cart is saved separately. Remove items to combine the carts, or check out this cart first.');

                    return $saved;
                }
                $titles = json_decode($target->line_titles ?? '{}', true) + json_decode($guest->line_titles ?? '{}', true);
                $this->store->table('carts')->where('id', $id)->update(['line_titles' => json_encode($titles, JSON_THROW_ON_ERROR), 'active_at' => now(), 'line_count' => count($items), 'items' => json_encode($items, JSON_THROW_ON_ERROR)]);
                $this->store->table('field_uploads')->where('scope', CommerceFields::scope($guest))->update(['scope' => CommerceFields::scope($target)]);
                if ($guest->recovered_at && ! $target->recovered_at) {
                    $this->store->table('carts')->where('id', $id)->update(['recovered_at' => $guest->recovered_at]);
                }
                if (! $target->order_fields && $guest->order_fields) {
                    $this->store->table('carts')->where('id', $id)->update(['order_fields' => $guest->order_fields]);
                }
                $this->store->table('carts')->where('id', $guest->id)->delete();
            }
            $this->store->table('carts')->where('id', $id)->update(['customer' => (string) $customer]);
            $mapping->update(['cart_id' => $id]);

            return ['id' => $id, 'token' => null];
        }, 3);
        if ($key !== $saved) {
            $request->session()->forget('sellissimo.quote.'.$site);
            $request->session()->put($sessionKey, $key);
        }

        return $key;
    }

    /** Read an authorized, open cart without restoring, creating or merging it. */
    public function storefrontCart(Request $request, string $site): ?object
    {
        $buyer = $request->user()?->getAuthIdentifier();
        $saved = $request->session()->get('sellissimo.cart.'.$site);
        $cart = null;
        if ($saved && (! isset($saved['retry']) || ($saved['buyer'] ?? null) === $buyer)) {
            $cart = $this->readForHeader($saved['id'], $saved['token'] ?? null, $buyer, $site);
            if (isset($saved['retry']) && $cart?->retry_order_id !== $saved['retry']) {
                $cart = null;
            }
        }
        if (! $cart && $buyer !== null) {
            $scope = $this->accountScope((string) $buyer, $site, $this->catalog->currency($site));
            $id = $this->store->table('account_carts')->where('scope', $scope)->value('cart_id');
            if ($id) {
                $cart = $this->readForHeader($id, null, $buyer, $site);
            }
        }
        if (! $cart || in_array($cart->items, ['{}', '[]'], true) || app(CartCurrency::class)->stale($cart)) {
            return null;
        }

        $cart->customer = $buyer;

        return $cart;
    }

    public function storefrontQuantity(Request $request, string $site): int
    {
        $cart = $this->storefrontCart($request, $site);

        return $cart ? app(Pricing::class)->cartQuantity($cart) : 0;
    }

    private function accountScope(string $customer, string $site, string $currency): string
    {
        return hash('sha256', json_encode([$customer, $site, $currency], JSON_THROW_ON_ERROR));
    }

    private function readForHeader(string $id, ?string $token, ?string $buyer, string $site): ?object
    {
        try {
            $cart = $this->carts->get($id, $token, $buyer);
        } catch (NotFoundHttpException) {
            return null;
        }

        return $cart->site === $site && ! $this->store->table('orders')->where('cart_id', $id)->exists() ? $cart : null;
    }

    private function open(?string $id): ?object
    {
        if (! $id) {
            return null;
        }
        $cart = $this->store->table('carts')->where('id', $id)->lockForUpdate()->first();

        return $cart && ! $this->store->table('orders')->where('cart_id', $id)->exists() ? $cart : null;
    }
}
