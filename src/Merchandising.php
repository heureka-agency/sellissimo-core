<?php

namespace Sellissimo\Core;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Statamic\Facades\Entry;

class Merchandising
{
    public function __construct(private Store $store, private Catalog $catalog, private CatalogBrowse $browse, private PurchaseTrends $trends) {}

    public function featured(string $site, int $limit = 4): array
    {
        [$page] = $this->browse->page($site, ['featured' => true, 'in_stock' => true, 'page' => 1, 'per_page' => $this->limit($limit)], groupProducts: true);

        return array_map(fn (array $product) => $this->card($site, $product), $page->items());
    }

    public function popular(string $site, int $limit = 4, int $days = 90): array
    {
        $scores = $this->trends->scores($site, max(1, min(365, $days)))['popular'];
        if (! $scores) {
            return [];
        }
        arsort($scores, SORT_NUMERIC);

        return $this->cards($site, $this->availableListings($site), array_keys($scores), $limit);
    }

    /** @param list<string> $skus Current product or cart SKUs. */
    public function recommend(string $site, string $kind, array $skus, int $limit = 4, array $excludeIds = []): array
    {
        if (! $skus || ! in_array($kind, ['cross_sell', 'upsell'], true)) {
            return [];
        }
        $features = StoreFeatures::get($site)['features'];
        if (! in_array($kind, $features, true)) {
            return [];
        }
        $sources = $this->catalog->products($site, false, array_values(array_unique($skus)));
        if (! $sources) {
            return [];
        }
        $sourcesById = collect($sources)->groupBy('entry_id');
        $listings = $this->availableListings($site)->whereNotIn('entry_id', [...$sourcesById->keys(), ...$excludeIds]);
        $candidateIds = [];
        $profiles = [];
        $pairs = $kind === 'cross_sell' ? collect(StoreSettings::forSection($site, 'catalog', 'cross_sell_category_pairs', config("sellissimo.cross_sell_category_pairs.$site", [])))->groupBy('source') : collect();
        foreach ($sourcesById as $id => $variants) {
            $entry = Entry::find($id);
            $field = $kind === 'cross_sell' ? 'sellissimo_cross_sells' : 'sellissimo_upsells';
            foreach ($entry->value($field) ?: [] as $relatedId) {
                $localized = Entry::find($relatedId)?->in($site);
                if ($localized) {
                    $candidateIds[$localized->id()] = true;
                }
            }
            $categories = array_column(ProductDetails::terms($entry, 'sellissimo_categories', $site), 'slug');
            $profiles[$id] = [
                'categories' => $pairs->only($categories)->collapse()->pluck('target')->unique()->all(),
                'group' => mb_strtolower(trim((string) $entry->value('sellissimo_upsell_group'))),
                'type' => mb_strtolower(trim((string) $entry->value('sellissimo_product_type'))),
                'price' => $variants->min('price'),
            ];
        }
        $limit = $this->limit($limit);
        $manual = $this->cards($site, $listings, array_keys($candidateIds), $limit);
        if (count($manual) >= $limit || ! in_array('auto_'.$kind, $features, true)) {
            return $manual;
        }
        $automatic = $this->automatic($site, $kind, $profiles, $listings, array_keys($candidateIds), $limit - count($manual));

        return [...$manual, ...$automatic];
    }

    public function cartEntryIds(string $site): array
    {
        $request = request();
        if (! $request->hasSession() || ! $cart = app(AccountCarts::class)->storefrontCart($request, $site)) {
            return [];
        }
        $skus = [];
        foreach (json_decode($cart->items, true, flags: JSON_THROW_ON_ERROR) as $line => $quantity) {
            $skus[] = is_array($quantity) ? $quantity['sku'] : $line;
        }

        return array_column($this->catalog->products($site, false, $skus), 'entry_id');
    }

    private function limit(int $limit): int
    {
        return max(1, min(24, $limit));
    }

    private function availableListings(string $site): Builder
    {
        $this->browse->state($site);
        $inStock = $this->store->table('stock')->select('sku')->where('available', '>', 0);

        return $this->store->table('catalog_listings')->where('site', $site)
            ->where(fn (Builder $query) => $query->where('tracked', false)->orWhereIn('sku', $inStock));
    }

    /** Resolve current saleable variants while preserving the editorial or sales ranking. */
    private function cards(string $site, Builder $query, array $entryIds, int $limit): array
    {
        $cards = [];
        $limit = $this->limit($limit);
        foreach (array_chunk($entryIds, 100) as $batch) {
            $rows = (clone $query)->whereIn('entry_id', $batch)->orderBy('price')->orderBy('position')
                ->get(['entry_id', 'sku'])->unique('entry_id')->keyBy('entry_id');
            $skus = [];
            foreach ($batch as $id) {
                if ($row = $rows->get($id)) {
                    $skus[] = $row->sku;
                }
            }
            array_push($cards, ...$this->productCards($site, $skus, $limit - count($cards)));
            if (count($cards) === $limit) {
                break;
            }
        }

        return $cards;
    }

    private function productCards(string $site, array $skus, int $limit): array
    {
        $cards = [];
        foreach (array_chunk($skus, max(8, $limit)) as $batch) {
            $products = $this->catalog->products($site, true, $batch);
            $eligible = app(PurchasePrerequisites::class)->eligibleInCatalog($products, $site, request()->user()?->getAuthIdentifier());
            foreach ($batch as $sku) {
                $product = $products[$sku] ?? null;
                if (! $product || $product['available'] === 0 || (! empty($product['prerequisite_id']) && ! in_array($product['prerequisite_id'], $eligible, true))) {
                    continue;
                }
                $cards[] = $this->card($site, $product);
                if (count($cards) === $limit) {
                    return $cards;
                }
            }
        }

        return $cards;
    }

    private function card(string $site, array $product): array
    {
        return [
            ...Arr::only($product, ['entry_id', 'sku', 'title', 'product_title', 'summary', 'price', 'currency', 'compare_at_price', 'images', 'available', 'digital']),
            'url' => ProductMetadata::storefrontUrl($site, $product),
        ];
    }

    private function automatic(string $site, string $kind, array $profiles, Builder $query, array $manualIds, int $limit): array
    {
        $pairs = $kind === 'cross_sell' ? $this->trends->scores($site)['pairs'] : [];
        $categories = array_unique(array_merge(...array_column($profiles, 'categories')));
        $groups = $kind === 'upsell' ? array_filter(array_unique(array_column($profiles, 'group'))) : [];
        $types = array_filter(array_unique(array_column($profiles, 'type')));
        $pairIds = [];
        foreach (array_keys($profiles) as $id) {
            $pairIds += $pairs[$id] ?? [];
        }
        $pool = (clone $query)->select(['entry_id', 'sku', 'price', 'product_type', 'upsell_group', 'categories', 'position']);
        $types = $kind === 'upsell' ? $types : [];
        $signals = count($categories) + count($groups) + count($types) + count($pairIds);
        if ($signals === 0) {
            return [];
        }
        // For large baskets stream the site's eligible index instead of exceeding database parameter limits.
        if ($signals <= 250) {
            $pool->where(function (Builder $match) use ($categories, $groups, $types, $pairIds) {
                $match->whereIn('product_type', $types)->orWhereIn('upsell_group', $groups)->orWhereIn('entry_id', array_keys($pairIds));
                foreach ($categories as $category) {
                    $match->orWhereJsonContains('categories', $category);
                }
            });
        }
        $excluded = array_flip($manualIds);
        $best = [];
        foreach ($pool->orderBy('position')->cursor() as $row) {
            if (isset($excluded[$row->entry_id])) {
                continue;
            }
            $categories = json_decode($row->categories, true, flags: JSON_THROW_ON_ERROR);
            $score = 0;
            foreach ($profiles as $id => $source) {
                $sharedCategory = (bool) array_intersect($categories, $source['categories']);
                $sameType = $source['type'] !== '' && $source['type'] === $row->product_type;
                $sameGroup = $source['group'] !== '' && $source['group'] === $row->upsell_group;
                if ($kind === 'upsell') {
                    if ((int) $row->price > $source['price'] && ($sameType || $sameGroup)) {
                        $score = max($score, 1 + (int) $sameType + 2 * (int) $sameGroup);
                    }
                } else {
                    $score += ($pairs[$id][$row->entry_id] ?? 0) * 100 + (int) $sharedCategory;
                }
            }
            if ($score <= 0) {
                continue;
            }
            $rank = [$score, -(int) $row->price, -(int) $row->position];
            if (! isset($best[$row->entry_id]) || $rank > $best[$row->entry_id]['rank']) {
                $best[$row->entry_id] = ['rank' => $rank, 'sku' => $row->sku];
            }
        }
        uasort($best, fn (array $a, array $b) => $b['rank'] <=> $a['rank']);

        return $this->productCards($site, array_column($best, 'sku'), $limit);
    }
}
