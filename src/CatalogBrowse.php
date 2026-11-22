<?php

namespace Sellissimo\Core;

use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Stache\Repositories\EntryRepository;

class CatalogBrowse
{
    public function __construct(private Catalog $catalog, private Store $store) {}

    private function attributeToken(string $name, string $value): string
    {
        return hash('sha256', json_encode([$name, $value], JSON_THROW_ON_ERROR));
    }

    public static function filters(array $input): array
    {
        $filters = validator($input, ['q' => 'nullable|string|max:150', 'category' => 'nullable|string|max:150',
            'in_stock' => 'nullable|boolean', 'featured' => 'nullable|boolean', 'price_min' => 'nullable|string|max:20', 'price_max' => 'nullable|string|max:20',
            'attributes' => 'nullable|array|max:24', 'attributes.*' => 'nullable|string|max:150', 'sort' => 'nullable|in:price_asc,price_desc',
            'per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1|max:1000000'])->validate();
        if (isset($filters['attributes'])) {
            $filters['attributes'] = array_filter($filters['attributes'], fn ($value) => filled($value));
        }

        return array_filter($filters, fn ($value) => filled($value));
    }

    public function state(string $site): object
    {
        $currency = $this->catalog->currency($site);
        $versions = [$currency, Catalog::collections(), StoreFeatures::enabled($site, 'vouchers')];
        if (Entry::getFacadeRoot() instanceof EntryRepository) {
            foreach (Catalog::collections() as $handle) {
                $versions[] = Stache::store('entries::'.$handle)->index('sellissimo_skus')->get('revision');
            }
        }
        $version = hash('sha256', serialize($versions));
        $pages = $this->store->table('catalog_pages')->where('site', $site);
        $state = (clone $pages)->first();
        $fresh = fn ($row) => $row && $row->version === $version && (! $row->expires || now()->timestamp < $row->expires);
        if (! $fresh($state)) {
            $state = $this->store->db()->transaction(function () use ($pages, $fresh, $site, $version, $currency) {
                $this->store->table('catalog_pages')->insertOrIgnore(['site' => $site]);
                $state = (clone $pages)->lockForUpdate()->first();
                if ($fresh($state)) {
                    return $state;
                }
                $entries = Entry::query()->whereIn('collection', Catalog::collections())->where('site', $site)->get();
                $next = $entries->filter(fn ($e) => $e->collection()->dated() && $e->date()?->isFuture())->map(fn ($e) => $e->date()->timestamp)->min();
                if ($entries->contains(fn ($entry) => $entry->value('sellissimo_sale_starts') || $entry->value('sellissimo_sale_ends'))) {
                    $midnight = now()->addDay()->startOfDay()->timestamp;
                    $next = min($next ?? $midnight, $midnight);
                }
                $facets = [];
                $categories = [];
                $batch = [];
                $this->store->table('catalog_listings')->where('site', $site)->delete();
                $position = 0;
                foreach ($this->catalog->listingRows($entries, $site, $currency) as $row) {
                    $categories += $row['categories'];
                    $tokens = [];
                    foreach ($row['attributes'] as $name => $value) {
                        $facets[$name][(string) $value] = (string) $value;
                        $tokens[] = $this->attributeToken((string) $name, (string) $value);
                    }
                    $row['attributes'] = json_encode($tokens, JSON_THROW_ON_ERROR);
                    $batch[] = [...$row, 'site' => $site, 'categories' => json_encode(array_keys($row['categories']), JSON_THROW_ON_ERROR), 'position' => $position++];
                    if (count($batch) === 125) {
                        $this->store->table('catalog_listings')->insert($batch);
                        $batch = [];
                    }
                }
                if ($batch) {
                    $this->store->table('catalog_listings')->insert($batch);
                }
                asort($categories);
                ksort($facets, SORT_NATURAL | SORT_FLAG_CASE);
                foreach ($facets as $name => $values) {
                    natcasesort($values);
                    $facets[$name] = array_values($values);
                }
                $pages->update(['version' => $version, 'expires' => $next, 'facets' => json_encode($facets, JSON_THROW_ON_ERROR), 'categories' => json_encode($categories, JSON_THROW_ON_ERROR)]);

                return $pages->first();
            }, 3);
        }

        return $state;
    }

    public function page(string $site, array $filters, bool $picker = false, bool $groupProducts = false): array
    {
        $state = $this->state($site);
        $currency = $this->catalog->currency($site);
        $query = $this->store->table('catalog_listings')->where('site', $site);
        if (! empty($filters['featured'])) {
            $query->where('featured', true);
        }
        if (filled($filters['q'] ?? null)) {
            $term = strtr(mb_strtolower(trim($filters['q'])), ['!' => '!!', '%' => '!%', '_' => '!_']);
            $query->whereRaw("words like ? escape '!'", ['%'.$term.'%']);
        }
        if (filled($filters['category'] ?? null)) {
            $query->whereJsonContains('categories', $filters['category']);
        }
        if (! empty($filters['in_stock'])) {
            $inStock = $this->store->table('stock')->where('available', '>', 0)->select('sku');
            $query->where(fn ($q) => $q->where('tracked', false)->orWhereIn('sku', $inStock));
        }
        $digits = Money::fractionDigits($currency);
        $bounds = [];
        foreach (['price_min' => '>=', 'price_max' => '<='] as $field => $operator) {
            if (filled($filters[$field] ?? null)) {
                $bounds[$field] = Money::parseDecimal($filters[$field], $digits, $field);
                $query->where('price', $operator, $bounds[$field]);
            }
        }
        if (isset($bounds['price_min'], $bounds['price_max']) && $bounds['price_min'] > $bounds['price_max']) {
            throw ValidationException::withMessages(['price_max' => __('sellissimo::shop.price_bounds')]);
        }
        foreach ($filters['attributes'] ?? [] as $name => $value) {
            if (filled($value)) {
                $query->whereJsonContains('attributes', $this->attributeToken((string) $name, (string) $value));
            }
        }
        $sort = $filters['sort'] ?? null;
        $perPage = $filters['per_page'] ?? 24;
        if ($groupProducts) {
            $query->selectRaw('min(position) as first_position, min(price) as low_price, max(price) as high_price')->groupBy('entry_id', 'featured');
            match ($sort) {
                'price_asc' => $query->orderBy('low_price'),
                'price_desc' => $query->orderByDesc('high_price'),
                default => $query->orderByDesc('featured'),
            };
            $page = $query->orderBy('first_position')->paginate($perPage, ['*'], 'page', $filters['page'] ?? null)->withQueryString();
            $matches = $page->getCollection()->keyBy('first_position');
            $byPosition = $this->store->table('catalog_listings')->where('site', $site)->whereIn('position', $matches->keys())->pluck('sku', 'position');
            $skus = $matches->keys()->map(fn ($position) => $byPosition[$position])->all();
        } else {
            match ($sort) {
                'price_asc' => $query->orderBy('price'),
                'price_desc' => $query->orderByDesc('price'),
                default => $query->orderByDesc('featured'),
            };
            $page = $query->orderBy('position')->paginate($perPage, ['sku'], 'page', $filters['page'] ?? null)->withQueryString();
            $skus = $page->getCollection()->pluck('sku')->all();
        }
        $items = $picker ? $this->catalog->products($site, false, $skus) : $this->catalog->browseProducts($site, $skus, $groupProducts);
        if ($groupProducts) {
            $families = collect($items)->groupBy('entry_id');
            foreach ($byPosition as $position => $sku) {
                if (! isset($items[$sku])) {
                    continue;
                }
                $family = $families[$items[$sku]['entry_id']];
                $items[$sku]['variant_count'] = $family->count();
                $items[$sku]['min_price'] = (int) $matches[$position]->low_price;
                $items[$sku]['max_price'] = (int) $matches[$position]->high_price;
            }
        }
        $page->setCollection(collect($skus)->map(fn ($sku) => isset($items[$sku]) ? ($picker ? Arr::only($items[$sku], ['sku', 'title']) : Arr::except($items[$sku], ['file'])) : null)->filter()->values());

        return [$page, collect(json_decode($state->categories, true, flags: JSON_THROW_ON_ERROR)), json_decode($state->facets ?? '[]', true, flags: JSON_THROW_ON_ERROR)];
    }

    public function invalidate(): void
    {
        $db = $this->store->db();
        if ($db->getDriverName() === 'sqlite' && $db->getDatabaseName() !== ':memory:' && ! is_file($db->getDatabaseName())) {
            return;
        }
        if ($db->getSchemaBuilder()->hasTable('sellissimo_catalog_pages')) {
            $this->store->table('catalog_pages')->update(['version' => null]);
        }
    }
}
