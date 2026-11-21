@extends('sellissimo::layout')
@section('title',__('sellissimo::shop.continue_payment'))
@section('surface', 'ss-narrow')
@section('content')
<div class="ss-form-panel">
<h1>{{ __('sellissimo::shop.continue_payment') }}</h1><p>{{ __('sellissimo::shop.your_order_is_reserved_continue_to_open_the_payment_provider') }}</p>
<form class="ss-form" method="post" action="{{ $url }}">@csrf<button>{{ __('sellissimo::shop.continue_to_payment') }}</button></form>
</div>
@endsection
