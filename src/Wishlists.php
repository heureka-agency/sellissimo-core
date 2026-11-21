<?php

namespace Sellissimo\Core;

use Illuminate\Http\Request;

class Wishlists
{
    public function __construct(private Store $store, private Catalog $catalog) {}

    public function merge(Request $request, string $customer): void
    {
        foreach ($request->session()->get('sellissimo.wishes', []) as $site => $skus) {
            if (!StoreFeatures::enabled($site, 'wishlists')) {
                continue;
            }
            $this->store->db()->transaction(function () use ($customer, $site, $skus) {
                foreach ($skus as $sku) {
                    $this->save($customer, $site, $sku);
                }
            });
            $request->session()->forget('sellissimo.wishes.'.$site);
        }
    }

    private function save(string $customer, string $site, string $sku): void
    {
        $this->store->table('wishes')->insertOrIgnore(compact('customer', 'site', 'sku') + ['created_at' => now()]);
    }

    public function change(Request $request, string $site, string $sku, bool $add): void
    {
        if ($add) {
            abort_unless(isset($this->catalog->products($site, false, [$sku])[$sku]), 404);
        }
        if ($user = $request->user()) {
            $customer = (string) $user->getAuthIdentifier();
            if ($add) {
                $this->save($customer, $site, $sku);
            } else {
                $this->store->table('wishes')->where(compact('customer', 'site', 'sku'))->delete();
            }
            return;
        }
        $key = 'sellissimo.wishes.'.$site;
        $skus = $request->session()->get($key, []);
        if ($add && !in_array($sku, $skus, true)) {
            abort_if(count($skus) >= 250, 422, __('sellissimo::features.wish_limit'));
            $skus[] = $sku;
        }
        $request->session()->put($key, $add ? $skus : array_values(array_diff($skus, [$sku])));
    }

    public function items(Request $request, string $site): array
    {
        $skus = $request->user() ? $this->store->table('wishes')->where(['customer' => $request->user()->getAuthIdentifier(), 'site' => $site])
            ->orderBy('created_at')->orderBy('sku')->pluck('sku')->all() : $request->session()->get('sellissimo.wishes.'.$site, []);
        $products = $this->catalog->products($site, false, $skus);
        $items = [];
        foreach ($skus as $sku) {
            $product = $products[$sku] ?? null;
            $items[] = $product ? [
                'sku' => $sku, 'title' => $product['title'], 'available' => $product['available'],
                'price' => Money::display($product['price'], $product['currency']), 'url' => ProductMetadata::storefrontUrl($site, $product),
            ] : ['sku' => $sku, 'title' => null, 'url' => null];
        }
        return $items;
    }
}
