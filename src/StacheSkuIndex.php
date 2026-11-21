<?php

namespace Sellissimo\Core;

use Statamic\Stache\Indexes\Index;

/** A reverse index, maintained by Statamic on file changes and quiet entry saves as well. */
class StacheSkuIndex extends Index
{
    public function getItems()
    {
        $index = ['revision' => bin2hex(random_bytes(16))];
        foreach ($this->store->getItemsFromFiles()->filter() as $entry) {
            $this->add($index, $entry);
        }

        return $index;
    }

    private function add(array &$index, $entry): void
    {
        $id = $entry->id();
        // Index local values. An inheriting localization resolves through its source entry.
        $variants = $entry->get('sellissimo_variants') ?: [['sku' => $entry->get('sellissimo_sku')]];
        foreach ($variants as $variant) {
            $sku = strtoupper(trim($variant['sku'] ?? ''));
            if ($sku !== '') {
                $index['entries'][$id][$sku] = true;
                $index['skus'][$sku][$id] = true;
            }
        }
    }

    private function remove($id): void
    {
        foreach ($this->items['entries'][$id] ?? [] as $sku => $_) {
            unset($this->items['skus'][$sku][$id]);
            if (! $this->items['skus'][$sku]) {
                unset($this->items['skus'][$sku]);
            }
        }
        unset($this->items['entries'][$id]);
    }

    public function forgetItem($key)
    {
        $this->load();
        $this->remove($key);
        $this->items['revision'] = bin2hex(random_bytes(16));
        $this->cache();
    }

    public function updateItem($item)
    {
        $this->updateItems([$item]);
    }

    public function updateItems($items)
    {
        $this->load();
        foreach ($items as $item) {
            $this->remove($item->id());
            $this->add($this->items, $item);
        }
        $this->items['revision'] = bin2hex(random_bytes(16));
        $this->cache();
    }

    public function owners(array $skus): array
    {
        $owners = [];
        foreach ($skus as $sku) {
            $owners += $this->items['skus'][$sku] ?? [];
        }

        return array_keys($owners);
    }
}
