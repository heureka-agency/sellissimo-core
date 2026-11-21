<?php

namespace Sellissimo\Core;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Statamic\Facades\Site;

class PaymentRetry
{
    public function __construct(private Store $store, private Carts $carts) {}

    public static function eligible(object $order): bool
    {
        return $order->status === 'expired' && !$order->paid_at && $order->cart_id
            && !in_array($order->gateway, ['historical', 'free', 'pay_on_delivery'], true)
            && Site::all()->has($order->site);
    }

    /** A reopened checkout in a retired currency must retain the purchased amounts. */
    public static function originalReceipt(object $cart): ?array
    {
        if (empty($cart->retry_order_id) || app(Catalog::class)->currency($cart->site) === $cart->currency) {
            return null;
        }
        $original = app(Store::class)->table('orders')->where('id', $cart->retry_order_id)
            ->where('site', $cart->site)->where('currency', $cart->currency)->firstOrFail(['receipt']);
        return json_decode($original->receipt, true, flags: JSON_THROW_ON_ERROR);
    }

    public function replacement(object $order): ?object
    {
        return $this->store->table('carts')->join('sellissimo_orders as replacement', 'replacement.cart_id', '=', 'sellissimo_carts.id')
            ->where('sellissimo_carts.retry_order_id', $order->id)
            ->first(['replacement.id', 'replacement.contact_revision', 'replacement.customer', 'replacement.cart_id', 'sellissimo_carts.token_hash']);
    }

    public function restore(Request $request, object $order): array
    {
        $buyer = $request->user()?->getAuthIdentifier();
        $saved = $request->session()->get('sellissimo.cart.'.$order->site);
        $remembered = $request->session()->get('sellissimo.retries.'.$order->id);
        [$key, $source] = $this->store->db()->transaction(function () use ($order, $saved, $remembered, $buyer) {
            $order = $this->store->table('orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
            abort_if($order->customer !== null && $order->customer !== (string) $buyer, 403, __('sellissimo::shop.retry_sign_in'));
            abort_unless(self::eligible($order), 409, __('sellissimo::shop.retry_unavailable'));
            $source = $this->store->table('carts')->where('id', $order->cart_id)->firstOrFail();
            $cart = $this->store->table('carts')->where('retry_order_id', $order->id)->lockForUpdate()->first();
            if ($cart) {
                abort_if($this->store->table('orders')->where('cart_id', $cart->id)->exists(), 409, __('sellissimo::shop.retry_already_placed'));
                // A signed receipt cannot grant access to a different person's edited basket.
                $proof = ($saved['id'] ?? null) === $cart->id ? $saved : $remembered;
                abort_unless(($cart->customer !== null && $cart->customer === (string) $buyer)
                    || ($cart->customer === null && ($proof['id'] ?? null) === $cart->id && hash_equals($cart->token_hash, hash('sha256', $proof['token'] ?? ''))),
                    409, __('sellissimo::shop.retry_already_open'));
                return [['id' => $cart->id, 'token' => $cart->customer === null ? $proof['token'] : null], $source];
            }

            $key = $this->carts->create($source->site);
            $customer = $buyer === null ? null : (string) $buyer;
            $receipt = json_decode($order->receipt, true, flags: JSON_THROW_ON_ERROR);
            $items = json_decode($source->items, true, flags: JSON_THROW_ON_ERROR);
            foreach ($items as &$item) {
                if (!is_array($item)) {
                    continue;
                }
                // Catalogue items use today's price. A bespoke service has only its merchant-quoted price and currency.
                if (!isset($item['service'])) {
                    unset($item['staff_price']);
                }
            }
            unset($item);
            $this->store->table('carts')->where('id', $key['id'])->update([
                'retry_order_id' => $order->id, 'currency' => $order->currency, 'customer' => $customer, 'items' => json_encode($items, JSON_THROW_ON_ERROR), 'line_count' => count($items),
                'line_titles' => $source->line_titles, 'field_scope' => CommerceFields::scope($source),
                'order_fields' => json_encode($this->fieldValues($source, $receipt), JSON_THROW_ON_ERROR),
            ]);
            if ($customer !== null) {
                $key['token'] = null;
            }
            $this->store->recordActivity($order->id, 'payment', 'Buyer reopened checkout after payment expired.');

            return [$key, $source];
        }, 3);

        $site = $source->site;
        if (($saved['id'] ?? null) !== $key['id']) {
            if (!$request->session()->has('sellissimo.before_retry.'.$site)) {
                $previous = $saved && !$this->store->table('orders')->where('cart_id', $saved['id'])->exists() ? $saved : null;
                $request->session()->put('sellissimo.before_retry.'.$site, ['cart' => $previous, 'quote' => $previous ? $request->session()->get('sellissimo.quote.'.$site) : null]);
            }
            $receipt = json_decode($order->receipt, true, flags: JSON_THROW_ON_ERROR);
            $answers = array_map(fn ($step) => array_map(fn ($field) => $field['value'], $step['fields']), $receipt['checkout_steps'] ?? []);
            $quote = [...Arr::only($receipt, ['address', 'shipping_address', 'shipping_instructions']), 'email' => $order->email,
                'discount' => $receipt['code'] ?? '', 'shipping' => $receipt['shipping_method'] ?? '', 'checkout_steps' => $answers];
            $request->session()->put('sellissimo.quote.'.$site, $quote);
        }
        $request->session()->put('sellissimo.retries.'.$order->id, $key);
        $request->session()->put('sellissimo.cart.'.$site, [...$key, 'retry' => $order->id, 'buyer' => $buyer]);
        $request->session()->flash('sellissimo.cart_notice', __('sellissimo::shop.retry_review'));

        return $key;
    }

    /** Older checkouts could supply order fields directly without first saving them on the cart. */
    private function fieldValues(object $source, array $receipt): array
    {
        $saved = json_decode($source->order_fields ?? '{}', true);
        $definitions = app(CommerceFields::class)->definitions('order', true);
        $values = [];
        foreach ($receipt['order_fields'] ?? [] as $handle => $field) {
            if (!isset($definitions[$handle])) {
                continue;
            }
            $options = $definitions[$handle]['options'];
            $raw = $saved[$handle] ?? null;
            if (isset($field['file_id'])) {
                $values[$handle] = $field['file_id'];
            } elseif ($raw !== null && ($options[$raw] ?? $raw) === $field['value']) {
                $values[$handle] = $raw;
            } elseif (in_array($definitions[$handle]['type'], ['select', 'radio'], true)) {
                $option = array_search($field['value'], $options, true);
                if ($option !== false) {
                    $values[$handle] = $option;
                }
            } else {
                $values[$handle] = $field['value'];
            }
        }

        return $values;
    }

    public static function finish(Request $request, string $site): void
    {
        $previous = $request->session()->pull('sellissimo.before_retry.'.$site);
        $request->session()->forget(['sellissimo.cart.'.$site, 'sellissimo.quote.'.$site]);
        if ($previous) {
            if (isset($previous['cart'])) {
                $request->session()->put('sellissimo.cart.'.$site, $previous['cart']);
            }
            if (isset($previous['quote'])) {
                $request->session()->put('sellissimo.quote.'.$site, $previous['quote']);
            }
        }
    }
}
