@extends('sellissimo::layout')
@section('title', __('sellissimo::shop.recover_your_cart'))
@section('surface', 'ss-narrow')
@section('content')
<div class="ss-form-panel">
<h1>{{ __('sellissimo::shop.recover_your_cart') }}</h1>
<ul class="ss-recovery-lines">
    @foreach($items as $key => $item)
        @php($sku = is_array($item) ? $item['sku'] : $key)
        <li>{{ is_array($item) ? $item['qty'] : $item }} × {{ $titles[$key] ?? $sku }}
            @if(isset($titles[$key]) && $titles[$key] !== $sku)<small>{{ $sku }}</small>@endif
        </li>
    @endforeach
</ul>
<p>{{ __('sellissimo::shop.open_this_cart_on_this_device_to_review_current_prices_and_availability') }}</p>
<form class="ss-form" method="post" action="{{ $url }}">@csrf<button>{{ __('sellissimo::shop.open_saved_cart') }}</button></form>
</div>
@endsection
