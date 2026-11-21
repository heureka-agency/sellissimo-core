<?php

namespace Sellissimo\Core;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Statamic\Facades\Asset;
use Statamic\Facades\Blink;
use Statamic\Facades\Term;

class ProductDetails
{
    public static function offer($entry, array $variant, string $currency, int $regular): array
    {
        $sale = filter_var($variant['sale_prices'][$currency] ?? null, FILTER_VALIDATE_INT);
        $today = now()->toDateString();
        $start = $entry->value('sellissimo_sale_starts');
        $end = $entry->value('sellissimo_sale_ends');
        $active = $sale !== false && $sale >= 0 && $sale < $regular && (! $start || $start <= $today) && (! $end || $end >= $today);

        return ['price' => $active ? $sale : $regular, 'compare_at_price' => $active ? $regular : null];
    }

    public static function parcel($entry, array $variant): array
    {
        $measurements = [];
        foreach (['weight_kg', 'length_cm', 'width_cm', 'height_cm'] as $field) {
            $value = $variant[$field] ?? $entry->value('sellissimo_'.$field);
            if (is_numeric($value) && $value >= 0) {
                $measurements[$field] = (float) $value;
            }
        }

        return array_filter([...$measurements, 'country_of_origin' => $entry->value('sellissimo_origin'), 'hs_code' => $entry->value('sellissimo_hs_code')], fn ($value) => filled($value));
    }

    public static function presentation($entry, string $site): array
    {
        return [
            'entry_id' => $entry->id(), 'product_title' => $entry->value('title'),
            'summary' => $entry->value('sellissimo_summary'), 'description' => $entry->value('sellissimo_description'),
            'brand' => $entry->value('sellissimo_brand'), 'product_type' => $entry->value('sellissimo_product_type'),
            'images' => self::images($entry->value('sellissimo_gallery')),
            'categories' => self::terms($entry, 'sellissimo_categories', $site), 'tags' => self::terms($entry, 'sellissimo_tags', $site),
            'specifications' => collect($entry->value('sellissimo_specs'))->map(fn ($row) => Arr::only($row, ['name', 'value']))->values()->all(),
            'featured' => (bool) $entry->value('sellissimo_featured'),
            'seo_title' => $entry->value('sellissimo_seo_title'), 'seo_description' => $entry->value('sellissimo_seo_description'),
        ];
    }

    public static function images($paths): array
    {
        $images = [];
        foreach (Arr::wrap($paths) as $path) {
            if (! is_string($path) || str_contains($path, '::')) {
                continue;
            }
            $asset = Asset::find('sellissimo_products::'.$path);
            if ($asset && $asset->isImage()) {
                $images[] = ['url' => $asset->url(), 'alt' => $asset->get('alt') ?: $asset->title()];
            }
        }

        return $images;
    }

    public static function terms($entry, string $taxonomy, string $site): array
    {
        $labels = [];
        foreach (Arr::wrap($entry->value($taxonomy)) as $slug) {
            $key = Str::after($slug, '::');
            $labels[] = ['slug' => $key, 'title' => Blink::once("sellissimo.term.$taxonomy.$key.$site", fn () => Term::find($taxonomy.'::'.$key)?->in($site)?->title() ?: Str::headline($key))];
        }

        return $labels;
    }
}
