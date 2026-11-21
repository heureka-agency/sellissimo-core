<?php

namespace Sellissimo\Core;

use Illuminate\Support\Collection;
use Statamic\Facades\Entry;

class ProductMetadata
{
    public static function url(string $sku, string $site): string
    {
        return route('sellissimo.product', ['sku' => $sku, 'site' => $site]);
    }

    public static function storefrontUrl(string $site, array $product): ?string
    {
        if (OperationalSettings::storefront($site)) {
            return self::url($product['sku'], $site);
        }
        $template = StoreSettings::forSection($site, 'catalog', 'product_url', config('sellissimo.product_urls.'.$site));
        if ($template) {
            return url(str_replace('{sku}', rawurlencode($product['sku']), $template));
        }
        return Entry::find($product['entry_id'])?->in($site)?->absoluteUrl();
    }

    public static function schema(array $product, Collection $variants, string $site): array
    {
        $url = self::url($product['sku'], $site);
        $description = $product['seo_description'] ?: $product['summary'];
        $common = array_filter(['description' => $description, 'brand' => $product['brand'] ? ['@type' => 'Brand', 'name' => $product['brand']] : null]);
        $node = ['@type' => 'Product', '@id' => $url.'#product', 'url' => $url, 'name' => $product['title'], 'sku' => $product['sku'],
            ...$common, 'offers' => ['@type' => 'Offer', 'url' => $url, 'priceCurrency' => $product['currency'],
                'price' => Money::decimal($product['price'], $product['currency']),
                'availability' => 'https://schema.org/'.($product['available'] === 0 ? 'OutOfStock' : 'InStock')]];
        if ($product['images']) {
            $node['image'] = array_map(fn ($image) => url($image['url']), $product['images']);
        }
        if ($product['mpn']) {
            $node['mpn'] = $product['mpn'];
        }
        $axes = [];
        foreach ($product['attributes'] as $name => $value) {
            if (!is_scalar($value) || blank($value)) {
                continue;
            }
            $key = strtolower(trim($name));
            $key = $key === 'colour' ? 'color' : $key;
            if (in_array($key, ['size', 'color', 'material', 'pattern'], true)) {
                $axes[] = 'https://schema.org/'.$key;
                $node[$key] = $value;
            } else {
                $node['additionalProperty'][] = ['@type' => 'PropertyValue', 'name' => $name, 'value' => $value];
            }
        }
        if ($variants->count() === 1) {
            return ['@context' => 'https://schema.org', ...$node];
        }

        $groupId = $site.':'.$product['entry_id'];
        $members = $variants->map(fn ($variant) => $variant['sku'] === $product['sku'] ? $node
            : ['@type' => 'Product', 'url' => self::url($variant['sku'], $site)])->all();

        return ['@context' => 'https://schema.org', '@type' => 'ProductGroup',
            '@id' => route('sellissimo.shop', ['site' => $site]).'#group-'.rawurlencode($product['entry_id']),
            'productGroupID' => $groupId, 'name' => $product['product_title'], ...$common,
            ...($axes ? ['variesBy' => array_values(array_unique($axes))] : []), 'hasVariant' => $members];
    }
}
