@extends('sellissimo::layout')
@section('title', __('sellissimo::shop.your_cart'))
@push('head')
@include('sellissimo::cart-styles')
@endpush
@section('content')
<div id="sellissimo-cart">
    <div data-cart-content>@include('sellissimo::cart-content')</div>
    <p data-cart-status role="status" tabindex="-1"></p>
    <button type="button" data-cart-retry hidden>{{ __('sellissimo::shop.retry_cart_update') }}</button>
</div>
@include('sellissimo::cart-script')
@endsection
