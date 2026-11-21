@extends('sellissimo::layout')
@section('title', __('sellissimo::shop.payment'))
@section('surface', 'ss-narrow')
@section('content')
<div class="ss-form-panel">
<h1>{{ __('sellissimo::shop.online_payment_is_currently_unavailable') }}</h1>
<p role="status">{{ __('sellissimo::shop.unavailable_payment_help') }}</p>
<div class="ss-actions"><a class="ss-button ss-button--primary" href="{{ $retryUrl }}">{{ __('sellissimo::shop.continue_payment') }}</a> <a class="ss-button" href="{{ $receiptUrl }}">{{ __('sellissimo::shop.order_receipt') }}</a></div>
</div>
@endsection
