@extends('sellissimo::layout')
@section('title', __('sellissimo::shop.your_cart'))
@push('head')
@include('sellissimo::cart-styles')
@endpush
@section('content')
<header class="ss-page-head">
    <h1>{{ __('sellissimo::shop.your_cart') }}</h1>
    @if($currencyChange || !empty($receipt['lines']) || $unavailable)
        <a href="{{ route('sellissimo.shop', ['site' => $site, ...$filters]) }}">{{ __('sellissimo::shop.continue_shopping') }}</a>
    @endif
</header>
@if($notice)<p role="status">{{ $notice }}</p>@endif
@include('sellissimo::retry-cart')
@if($currencyChange)
    <section class="ss-panel">@include('sellissimo::cart-currency')</section>
@elseif(!empty($receipt['lines']) || $unavailable)
    <div class="cart-layout">
        <section class="cart-lines" aria-label="{{ __('sellissimo::shop.your_cart') }}">@include('sellissimo::cart-lines')</section>
        @if(!empty($receipt['lines']))
            <aside class="ss-panel cart-summary" aria-labelledby="cart-summary-title">
                <h2 id="cart-summary-title">{{ __('sellissimo::shop.order_summary') }}</h2>
                <div class="ss-total">
                    <span>{{ __('sellissimo::shop.cart_subtotal') }}</span>
                    <strong>{{ \Sellissimo\Core\Money::display($receipt['subtotal'], $receipt['currency']) }}</strong>
                </div>
                <p class="ss-muted">{{ __('sellissimo::shop.cart_total_at_checkout') }}</p>
                @if(!$unavailable && empty($receipt['stock_shortages']))
                    <a class="ss-button ss-button--primary" href="{{ route('sellissimo.checkout.page', ['site' => $site]) }}">{{ __('sellissimo::shop.checkout') }}</a>
                @endif
            </aside>
        @endif
    </div>
@else
    <div class="ss-empty"><p>{{ __('sellissimo::shop.cart_is_empty') }}</p>
        <a class="ss-button ss-button--primary" href="{{ route('sellissimo.shop', ['site' => $site, ...$filters]) }}">{{ __('sellissimo::shop.browse_products') }}</a>
    </div>
@endif
@endsection
