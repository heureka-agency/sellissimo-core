@extends('sellissimo::layout')
@section('title', __('sellissimo::features.wishlist'))
@section('surface', 'ss-document')
@section('content')
<h1>{{ __('sellissimo::features.wishlist') }}</h1>
@forelse($items as $item)
<article class="row ss-panel"><div>
@if($item['url'])<a href="{{ $item['url'] }}">{{ $item['title'] }}</a>@else {{ $item['title'] ?? __('sellissimo::features.retired') }} @endif
<small>{{ $item['sku'] }}</small>
@if($item['title'])<p>{{ $item['price'] }} · {{ $item['available'] === 0 ? __('sellissimo::shop.out_of_stock') : __('sellissimo::shop.available_to_order') }}</p>@endif
</div><form method="post" action="{{ route('sellissimo.wishes', compact('site')) }}">@csrf
<input type="hidden" name="sku" value="{{ $item['sku'] }}"><button class="ss-button--quiet" name="action" value="remove" aria-label="{{ __('sellissimo::shop.remove') }} {{ $item['title'] ?: $item['sku'] }}">{{ __('sellissimo::shop.remove') }}</button></form></article>
@empty
<div class="ss-empty"><p>{{ __('sellissimo::features.wish_empty') }}</p>
    @if(\Sellissimo\Core\OperationalSettings::storefront($site))
        <a class="ss-button" href="{{ route('sellissimo.shop', compact('site')) }}">{{ __('sellissimo::shop.browse_products') }}</a>
    @endif
</div>
@endforelse
@endsection
