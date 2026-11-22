<div data-catalog-results tabindex="-1">
    @include('sellissimo::catalog-active-filters')
    <p class="ss-muted catalog-count" role="status">{{ trans_choice('sellissimo::shop.product_count', $page->total(), ['count' => $page->total()]) }}</p>
    @include('sellissimo::catalog-results')
    @if(!$hasCatalogCriteria && empty($filters['sort']) && $page->onFirstPage())
        @include('sellissimo::shop-merchandising')
    @endif
</div>
