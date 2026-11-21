@if ($paginator->hasPages())
<nav class="store-pagination" aria-label="{{ __('sellissimo::shop.pages') }}">
    @if ($url = $paginator->previousPageUrl())<a rel="prev" href="{{ $url }}">{{ __('sellissimo::shop.previous_page') }}</a>@endif
    <span aria-current="page">{{ __('sellissimo::shop.page_of', ['page' => $paginator->currentPage(), 'total' => $paginator->lastPage()]) }}</span>
    @if ($url = $paginator->nextPageUrl())<a rel="next" href="{{ $url }}">{{ __('sellissimo::shop.next_page') }}</a>@endif
</nav>
@endif
