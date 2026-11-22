@php
    $active = [];
    $urlFilters = array_filter(\Illuminate\Support\Arr::except($filters, ['page']), fn ($value) => filled($value));
    $urlFilters['attributes'] = array_filter($urlFilters['attributes'] ?? [], fn ($value) => filled($value));
    foreach (['q','category','in_stock','price_min','price_max','sort'] as $field) {
        if (!filled($filters[$field] ?? null) || ($field === 'in_stock' && !$filters[$field])) continue;
        $label = match ($field) {
            'category' => $categories->get($filters[$field], $filters[$field]),
            'in_stock' => __('sellissimo::shop.in_stock_only'),
            'price_min', 'price_max' => __('sellissimo::shop.'.$field).' '.$filters[$field].' '.$currency,
            'sort' => __('sellissimo::shop.'.$filters[$field]),
            default => $filters[$field],
        };
        $active[] = [$label, \Illuminate\Support\Arr::except($urlFilters, [$field])];
    }
    foreach ($urlFilters['attributes'] as $name => $value) {
        $other = $urlFilters;
        unset($other['attributes'][$name]);
        $active[] = [$name.' · '.$value, $other];
    }
@endphp
@if($active)<nav class="active-filters" aria-label="{{ __('sellissimo::shop.active_filters') }}">
    @foreach($active as [$label,$other])<a href="{{ route('sellissimo.shop', ['site'=>$site, ...$other]) }}" aria-label="{{ __('sellissimo::shop.remove') }} {{ $label }}">{{ $label }} ×</a>@endforeach
    <a href="{{ route('sellissimo.shop', ['site'=>$site]) }}">{{ __('sellissimo::shop.clear_filters') }}</a>
</nav>@endif
