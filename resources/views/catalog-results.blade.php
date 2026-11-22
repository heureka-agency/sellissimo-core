@if(app(\Sellissimo\Core\Registry::class)->configured('unit_price_providers') && $products)<p>{{ __('sellissimo::shop.catalog_price_note') }}</p>@endif
@if($products)
<div class="catalog-grid">
    @foreach($products as $product)
        @php($productUrl = route('sellissimo.product', ['sku' => $product['sku'], 'site' => $site, ...$filters]))
        <article class="catalog-product">
            @if($image = $product['images'][0] ?? null)
                <div class="catalog-image"><img src="{{ $image['url'] }}" alt="{{ $image['alt'] }}" loading="lazy"></div>
            @endif
            <h2><a href="{{ $productUrl }}">{{ $product['product_title'] }}</a></h2>
            @if($product['summary'])<p class="catalog-summary">{{ $product['summary'] }}</p>@endif
            <p class="catalog-price">
                @if($product['variant_count'] === 1 && $product['compare_at_price'] !== null)
                    <del>{{ \Sellissimo\Core\Money::display($product['compare_at_price'], $currency) }}</del>
                @endif
                {{ \Sellissimo\Core\Money::display($product['min_price'], $currency) }}@if($product['min_price'] !== $product['max_price']) – {{ \Sellissimo\Core\Money::display($product['max_price'], $currency) }}@endif
            </p>
            @if($product['variant_count'] > 1)
                <span class="catalog-availability">{{ __('sellissimo::shop.choose_options') }}</span>
            @elseif($product['digital'])
                <span class="catalog-availability">{{ __($product['downloadable'] ? 'sellissimo::shop.digital_download' : 'sellissimo::shop.service') }}</span>
            @else
                <span class="catalog-availability">{{ $product['available'] === null ? __('sellissimo::shop.available_to_order') : ($product['available'] ? __('sellissimo::shop.count_available', ['count' => $product['available']]) : __('sellissimo::shop.out_of_stock')) }}</span>
            @endif
        </article>
    @endforeach
</div>
{{ $page->links('sellissimo::pagination') }}
@else
<div class="ss-empty">
    <p>{{ $hasCatalogCriteria ? __('sellissimo::shop.no_matching_products') : __('sellissimo::shop.no_products_available') }}</p>
    @if($hasCatalogCriteria)
        <a class="ss-button" href="{{ route('sellissimo.shop', ['site' => $site]) }}">{{ __('sellissimo::shop.clear_filters') }}</a>
    @endif
</div>
@endif
