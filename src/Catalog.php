<?php

namespace Sellissimo\Core;

use Illuminate\Validation\ValidationException;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class Catalog
{
    public function currency(string $site): string
    {
        abort_unless(Site::all()->has($site), 404, 'Unknown site.');
        $currency = StoreSettings::forSection($site, 'currency', 'currency', config("sellissimo.currencies.$site"));
        abort_unless(is_string($currency) && preg_match('/^[A-Z]{3}$/D', $currency), 422, 'Configure a currency for this site.');

        return $currency;
    }

    public static function collections(): array
    {
        return StoreSettings::forSection(StoreSettings::GLOBAL, 'catalog', 'product_collections', config('sellissimo.collections') ?: [config('sellissimo.collection')]);
    }

    private function entries(string $site)
    {
        return Entry::query()->whereIn('collection', self::collections())->where('site', $site)->get();
    }

    private function variants($entry): array
    {
        return $entry->value('sellissimo_variants') ?: [[
            'sku' => $entry->value('sellissimo_sku'), 'prices' => $entry->value('sellissimo_prices'),
            'download' => $entry->value('sellissimo_download'), 'download_limit' => $entry->value('sellissimo_download_limit'), 'download_days' => $entry->value('sellissimo_download_days'), 'label' => '', 'sale_prices' => $entry->value('sellissimo_sale_prices'),
            'barcode' => $entry->value('sellissimo_barcode'), 'mpn' => $entry->value('sellissimo_mpn'),
        ]];
    }

    private function sku(array $variant): string
    {
        return strtoupper(trim((string) ($variant['sku'] ?? '')));
    }

    private function skuIndex($entries): array
    {
        $index = [];
        foreach ($entries as $entry) {
            if (! $entry->published()) {
                continue;
            }
            foreach ($this->variants($entry) as $i => $variant) {
                $sku = $this->sku($variant);
                if ($sku !== '') {
                    $index[$sku][] = [$entry, ($variant['label'] ?? '') ?: 'row '.($i + 1)];
                }
            }
        }

        return $index;
    }

    /** Only published and uniquely identifiable SKUs can be offered at checkout. */
    public function currencyCoverage(string $site, string $currency): array
    {
        $entries = $this->entries($site);
        $owners = $this->skuIndex($entries);
        $rows = [];
        $vouchersEnabled = null;
        foreach ($entries as $entry) {
            $gift = (bool) $entry->value('sellissimo_gift_voucher');
            if ($entry->status() !== 'published' || ($gift && !($vouchersEnabled ??= StoreFeatures::enabled($site, 'vouchers')))) {
                continue;
            }
            $url = request()->user()?->can('edit', $entry) ? $entry->editUrl() : null;
            foreach ($this->variants($entry) as $variant) {
                $sku = $this->sku($variant);
                if (!preg_match('/^[A-Z0-9][A-Z0-9_.-]{0,99}$/D', $sku) || count($owners[$sku] ?? []) !== 1) {
                    continue;
                }
                $price = filter_var($variant['prices'][$currency] ?? null, FILTER_VALIDATE_INT);
                $priced = $price !== false && $price >= ($gift ? 1 : 0) && $price <= 99999999;
                $rows[] = [
                    'sku' => $sku, 'title' => trim($entry->value('title').' '.($variant['label'] ?? '')),
                    'url' => $url,
                    'priced' => $priced, 'free' => $priced && $price === 0,
                    'digital' => (bool) ($gift || $entry->value('sellissimo_service') || Downloads::paths($variant['download'] ?? null)),
                ];
            }
        }
        return $rows;
    }

    public function historicalProducts(string $site): array
    {
        $matches = [];
        foreach ($this->entries($site) as $entry) {
            foreach ($this->variants($entry) as $variant) {
                $sku = $this->sku($variant);
                $name = $entry->value('title');
                $title = trim($name.' '.($variant['label'] ?? ''));
                $matches[$sku][$title][] = ['id'=>$entry->id(),'title'=>$name];
            }
        }
        return $matches;
    }

    public function stockProducts(string $site, $user): array
    {
        $products = [];
        foreach ($this->entries($site) as $entry) {
            if ($entry->status() !== 'published' && ! $user->can('view', $entry)) {
                continue;
            }
            foreach ($this->variants($entry) as $variant) {
                $sku = $this->sku($variant);
                if ($entry->value('sellissimo_track_stock') !== false && ! $entry->value('sellissimo_service') && ! filled($variant['download'] ?? null) && preg_match('/^[A-Z0-9][A-Z0-9_.-]{0,99}$/D', $sku)) {
                    $products[$sku] = trim($entry->value('title').' '.($variant['label'] ?? ''));
                }
            }
        }
        natcasesort($products);

        return $products;
    }

    public function conflicts(string $site): array
    {
        return array_filter($this->skuIndex($this->entries($site)), fn ($rows) => count($rows) > 1);
    }

    public function validateEntry(EntrySaving $event): void
    {
        $entry = $event->entry;
        if (! in_array($entry->collectionHandle(), self::collections(), true)) {
            return;
        }
        if ($fields = $entry->get('sellissimo_options')) {
            $entry->set('sellissimo_options', LineOptions::withHandles($fields));
        }
        if (! $entry->published()) {
            return;
        }
        $index = $this->skuIndex($this->entries($entry->locale())->reject(fn ($other) => $other->id() === $entry->id()));
        $errors = [];
        $prerequisite = \Illuminate\Support\Arr::first(\Illuminate\Support\Arr::wrap($entry->value('sellissimo_prerequisite')));
        if ($prerequisite && $prerequisite === $entry->id()) {
            $errors['sellissimo_prerequisite'] = __('A product cannot require itself.');
        }
        $start = $entry->value('sellissimo_sale_starts');
        $end = $entry->value('sellissimo_sale_ends');
        if ($start && $end && $end < $start) {
            $errors['sellissimo_sale_ends'] = 'Choose an end date on or after the first day of the sale.';
        }
        if (filled($entry->value('sellissimo_unit')) !== filled($entry->value('sellissimo_unit_quantity'))) {
            $errors['sellissimo_unit_quantity'] = 'Choose a unit and enter the number of units per pack.';
        }
        foreach ($this->variants($entry) as $i => $variant) {
            $sku = $this->sku($variant);
            if ($sku === '') {
                continue;
            }
            foreach (($variant['sale_prices'] ?? []) as $currency => $price) {
                $base = $variant['prices'][$currency] ?? null;
                if (! is_numeric($base) || $price < 0 || $price >= $base) {
                    $handle = $entry->value('sellissimo_variants') ? "sellissimo_variants.$i.sale_prices" : 'sellissimo_sale_prices';
                    $errors[$handle] = "Enter a sale price below the regular price in $currency.";
                }
            }
            if ($other = $index[$sku][0] ?? null) {
                [$owner, $label] = $other;
                $field = $entry->value('sellissimo_variants') ? "sellissimo_variants.$i.sku" : 'sellissimo_sku';
                $errors[$field] = "SKU $sku is already used by {$owner->value('title')} ($label).";
                if ($owner !== $entry && $owner->id() && request()->user()?->can('edit', $owner)) {
                    $errors[$field.'.conflict_entry'] = $owner->editUrl();
                }
            }
            $index[$sku][] = [$entry, ($variant['label'] ?? '') ?: 'row '.($i + 1)];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function refreshEntry($event): void
    {
        $entry = $event->entry;
        if (Entry::getFacadeRoot() instanceof \Statamic\Stache\Repositories\EntryRepository || !in_array($entry->collectionHandle(), self::collections(), true)) {
            return;
        }
        $store = app(Store::class);
        if (!$store->db()->getSchemaBuilder()->hasTable('sellissimo_catalog_skus')) {
            return;
        }
        $store->db()->transaction(function () use ($entry, $store, $event) {
            app(CatalogBrowse::class)->invalidate();
            $store->table('catalog_skus')->where('entry_id', $entry->id())->delete();
            if ($event instanceof \Statamic\Events\EntrySaved) {
                $this->indexEntry($entry);
                foreach ($entry->descendants() as $child) {
                    $store->table('catalog_skus')->where('entry_id', $child->id())->delete();
                    $this->indexEntry($child);
                }
            }
        }, 3);
    }

    private function indexEntry($entry): void
    {
        if (!$entry->published()) {
            return;
        }
        $rows = [];
        foreach ($this->variants($entry) as $variant) {
            $sku = $this->sku($variant);
            if (preg_match('/^[A-Z0-9][A-Z0-9_.-]{0,99}$/D', $sku)) {
                $rows[$sku] = ['site' => $entry->locale(), 'entry_id' => $entry->id(), 'sku' => $sku];
            }
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            app(Store::class)->table('catalog_skus')->insert($chunk);
        }
    }

    public function rebuildIndex(): void
    {
        if (Entry::getFacadeRoot() instanceof \Statamic\Stache\Repositories\EntryRepository) {
            foreach (self::collections() as $collection) {
                \Statamic\Facades\Stache::store('entries::'.$collection)->index('sellissimo_skus')->update();
            }
            return;
        }
        $store = app(Store::class);
        $store->db()->transaction(function () use ($store) {
            app(CatalogBrowse::class)->invalidate();
            $store->table('catalog_skus')->delete();
            foreach (Site::all()->keys() as $site) {
                foreach ($this->entries($site) as $entry) {
                    $this->indexEntry($entry);
                }
            }
        }, 3);
    }

    private function matchingEntries(string $site, array $skus)
    {
        if (Entry::getFacadeRoot() instanceof \Statamic\Stache\Repositories\EntryRepository) {
            return collect(self::collections())->flatMap(fn ($collection) =>
                \Statamic\Facades\Stache::store('entries::'.$collection)->index('sellissimo_skus')->owners($skus))
                ->unique()->map(fn ($id) => Entry::find($id)?->in($site))->filter()->unique(fn ($entry) => $entry->id());
        }

        return app(Store::class)->table('catalog_skus')->where('site', $site)->whereIn('sku', $skus)
            ->distinct()->pluck('entry_id')->map(fn ($id) => Entry::find($id))
            ->filter(fn ($entry) => $entry && $entry->locale() === $site && in_array($entry->collectionHandle(), self::collections(), true));
    }

    public function listingRows($entries, string $site, string $currency): iterable
    {
        $owners = $this->skuIndex($entries);
        $vouchersEnabled = null;
        foreach ($entries as $entry) {
            if ($entry->status() !== 'published') {
                continue;
            }
            $gift = (bool) $entry->value('sellissimo_gift_voucher');
            if ($gift && !($vouchersEnabled ??= StoreFeatures::enabled($site, 'vouchers'))) {
                continue;
            }
            $categories = array_column(ProductDetails::terms($entry, 'sellissimo_categories', $site), 'title', 'slug');
            $tags = array_column(ProductDetails::terms($entry, 'sellissimo_tags', $site), 'title');
            $words = [$entry->value('title'), $entry->value('sellissimo_brand'), $entry->value('sellissimo_product_type'), ...$tags, ...array_values($categories)];
            foreach ($this->variants($entry) as $variant) {
                $sku = $this->sku($variant);
                $price = filter_var($variant['prices'][$currency] ?? null, FILTER_VALIDATE_INT);
                if (!preg_match('/^[A-Z0-9][A-Z0-9_.-]{0,99}$/D', $sku) || count($owners[$sku] ?? []) !== 1 || $price === false || $price < 0 || $price > 99999999) {
                    continue;
                }
                if ($gift && !$price) {
                    continue;
                }
                $attributes = array_filter($variant['attributes'] ?? [], fn ($value) => is_scalar($value) && filled($value));
                $digital = $gift || Downloads::paths($variant['download'] ?? null) || $entry->value('sellissimo_service');
                yield ['entry_id' => $entry->id(), 'sku' => $sku, 'featured' => (bool) $entry->value('sellissimo_featured'), 'categories' => $categories,
                    'attributes' => $attributes, 'price' => $gift ? $price : ProductDetails::offer($entry, $variant, $currency, $price)['price'],
                    'tracked' => !$digital && $entry->value('sellissimo_track_stock') !== false,
                    'words' => mb_strtolower(implode(' ', [...$words, $sku, $variant['label'] ?? '', $variant['barcode'] ?? '']))];
            }
        }
    }

    /** Resolve siblings and related entries only for the selected products. */
    public function browseProducts(string $site, array $skus, bool $siblings = false): array
    {
        if (!$skus) {
            return [];
        }
        $entries = $this->matchingEntries($site, $skus);
        $related = $entries->flatMap(fn ($entry) => $entry->value('sellissimo_related') ?: [])->unique()
            ->map(fn ($id) => Entry::find($id)?->in($site))
            ->filter(fn ($entry) => $entry && in_array($entry->collectionHandle(), self::collections(), true));
        $extra = $siblings ? $entries->concat($related) : $related;
        foreach ($extra as $entry) {
            foreach ($this->variants($entry) as $variant) {
                $skus[] = $this->sku($variant);
            }
        }

        return $this->products($site, true, array_values(array_unique($skus)));
    }

    public function products(string $site, bool $withDetails = true, ?array $skus = null, ?string $currency = null, bool $applyDownloadDefaults = true): array
    {
        $currency ??= $this->currency($site);
        if ($skus === []) {
            return [];
        }
        $entries = $skus === null ? $this->entries($site) : $this->matchingEntries($site, $skus);
        $wanted = $skus === null ? null : array_fill_keys($skus, true);
        $defaultDownloadLimit = $applyDownloadDefaults ? (int) StoreSettings::forSection($site, 'orders', 'download_limit', config('sellissimo.download_limit', 0)) : 0;
        $defaultDownloadDays = $applyDownloadDefaults ? (int) StoreSettings::forSection($site, 'orders', 'download_days', config('sellissimo.download_days', 0)) : 0;
        $index = $this->skuIndex($entries);
        $products = [];
        $vouchersEnabled = null;
        foreach ($entries as $entry) {
            if ($entry->status() !== 'published') {
                continue;
            }
            $gift = (bool) $entry->value('sellissimo_gift_voucher');
            if ($gift && !($vouchersEnabled ??= StoreFeatures::enabled($site, 'vouchers'))) {
                continue;
            }
            $prerequisite = \Illuminate\Support\Arr::first(\Illuminate\Support\Arr::wrap($entry->value('sellissimo_prerequisite')));
            $details = $withDetails ? ProductDetails::presentation($entry, $site) : [];
            foreach ($this->variants($entry) as $variant) {
                $sku = $this->sku($variant);
                if ($wanted !== null && !isset($wanted[$sku])) {
                    continue;
                }
                $price = filter_var($variant['prices'][$currency] ?? null, FILTER_VALIDATE_INT);
                if (! preg_match('/^[A-Z0-9][A-Z0-9_.-]{0,99}$/D', $sku) || count($index[$sku] ?? []) !== 1 || $price === false || $price < 0 || $price > 99999999) {
                    continue;
                }
                if ($gift && !$price) {
                    continue;
                }
                $offer = $gift ? ['price' => $price, 'compare_at_price' => null] : ProductDetails::offer($entry, $variant, $currency, $price);
                $files = $gift ? [] : Downloads::paths($variant['download'] ?? null);
                $downloadable = (bool) $files;
                $digital = $gift || $downloadable || (bool) $entry->value('sellissimo_service');
                $products[$sku] = [
                    'entry_id' => $entry->id(), 'product_title' => $entry->value('title'), 'sku' => $sku, 'title' => trim($entry->value('title').' '.($variant['label'] ?? '')),
                    ...($prerequisite ? ['prerequisite_id' => $prerequisite] : []),
                    ...($gift ? ['voucher_product' => true] : []), ...$offer, 'currency' => $currency, 'tax_class' => $entry->value('sellissimo_tax_class') ?: 'standard',
                    ...(!$digital && $entry->value('sellissimo_track_stock') === false ? ['track_stock' => false] : []),
                    'option_fields' => ($gift ? ['gift_recipient' => ['label' => __('Recipient email (defaults to buyer)'), 'required' => false, 'max_length' => 250]] : [])
                        + LineOptions::definitions($entry->value('sellissimo_options') ?: []),
                    'digital' => $digital, 'downloadable' => $downloadable, 'file' => count($files) > 1 ? $files : ($files[0] ?? null),
                    'download_limit' => max(0, filled($variant['download_limit'] ?? null) ? (int) $variant['download_limit'] : $defaultDownloadLimit),
                    ...($downloadable ? ['download_days' => max(0, filled($variant['download_days'] ?? null) ? (int) $variant['download_days'] : $defaultDownloadDays)] : []),
                    ...(!$digital && $entry->value('sellissimo_final_sale') ? ['final_sale' => true] : []),
                    'shipping_details' => $digital ? [] : ProductDetails::parcel($entry, $variant),
                ];
                if ($withDetails) {
                    $variantImages = ProductDetails::images($variant['image'] ?? []);
                    $unitCount = (float) $entry->value('sellissimo_unit_quantity');
                    $unit = $entry->value('sellissimo_unit');
                    $products[$sku] += [...$details, 'variant' => $variant['label'] ?? '', 'attributes' => $variant['attributes'] ?? [],
                        'images' => collect([...$variantImages, ...$details['images']])->unique('url')->values()->all(),
                        'barcode' => $variant['barcode'] ?? null, 'mpn' => $variant['mpn'] ?? null,
                        'unit_price' => $unit && $unitCount > 0 ? ['amount' => (int) round($offer['price'] / $unitCount), 'unit' => $unit, 'quantity' => $unitCount] : null,
                        'related_entries' => $entry->value('sellissimo_related') ?: [],
                    ];
                }
            }
        }
        $byEntry = $withDetails ? collect($products)->unique('entry_id')->keyBy('entry_id') : collect();
        $stock = app(Store::class)->table('stock')->when($skus !== null, fn ($query) => $query->whereIn('sku', $skus))->pluck('available', 'sku');
        foreach ($products as &$product) {
            if ($withDetails) {
                $product['related'] = collect($product['related_entries'])->unique()->reject(fn ($id) => $id === $product['entry_id'])
                    ->map(fn ($id) => $byEntry->get($id))->filter()->map(fn ($item) => ['sku' => $item['sku'], 'title' => $item['product_title']])->values()->all();
                unset($product['related_entries']);
            }
            $product['available'] = !Inventory::tracked($product) ? null : (int) $stock->get($product['sku'], 0);
        }

        return $products;
    }
}
