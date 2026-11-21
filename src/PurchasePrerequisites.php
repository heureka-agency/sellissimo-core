<?php

namespace Sellissimo\Core;

use Illuminate\Validation\ValidationException;
use Statamic\Facades\Entry;

class PurchasePrerequisites
{
    public function __construct(private Store $store) {}

    public function notice(array $product, string $site, ?string $customer): ?string
    {
        if (empty($product['prerequisite_id'])) {
            return null;
        }
        $required = Entry::find($product['prerequisite_id'])?->in($site);
        if (! $required || ! in_array($required->collectionHandle(), Catalog::collections(), true)) {
            return __('sellissimo::shop.prerequisite_unavailable');
        }
        if ($customer && $this->purchased($customer, $site, [$required->id()])) {
            return null;
        }
        $name = $required->status() === 'published' ? $required->value('title') : __('sellissimo::shop.required_product');
        return __('sellissimo::shop.'.($customer ? 'purchase_first' : 'sign_in_purchase'), ['product' => $name]);
    }

    public function check(array $product, string $site, ?string $customer): void
    {
        if ($message = $this->notice($product, $site, $customer)) {
            throw ValidationException::withMessages(['prerequisite' => $message]);
        }
    }

    public function eligibleInCatalog(array $products, string $site, ?string $customer): array
    {
        if (!$customer) {
            return [];
        }
        $ids = [];
        foreach (array_unique(array_column($products, 'prerequisite_id')) as $id) {
            $entry = Entry::find($id)?->in($site);
            if ($entry && in_array($entry->collectionHandle(), Catalog::collections(), true)) {
                $ids[$id] = $entry->id();
            }
        }
        return array_keys(array_intersect($ids, $this->purchased($customer, $site, array_values($ids))));
    }

    private function purchased(string $customer, string $site, array $required): array
    {
        if (!$required) {
            return [];
        }
        $purchased = [];
        $orders = $this->store->table('orders')->where('customer', $customer)->where('site', $site)
            ->where('status', 'paid')->select('id', 'receipt')->lazyById(100);
        foreach ($orders as $order) {
            foreach (json_decode($order->receipt, true, flags: JSON_THROW_ON_ERROR)['lines'] as $line) {
                $entry = $line['entry_id'] ?? null;
                if (!in_array($entry, $required, true) || isset($purchased[$entry])) {
                    continue;
                }
                $refunded = $this->store->table('refunds')->where('order_id', $order->id)
                    ->where('sku', $line['key'] ?? $line['sku'])->where('status', 'succeeded')->sum('amount');
                if (!$refunded || $refunded < $line['total']) {
                    $purchased[$entry] = $entry;
                    if (count($purchased) === count($required)) {
                        return $purchased;
                    }
                }
            }
        }
        return $purchased;
    }
}
