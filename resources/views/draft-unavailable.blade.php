@extends('sellissimo::layout')
@section('title',__('sellissimo::shop.order_needs_an_update'))
@section('surface', 'ss-narrow')
@section('content')
<div class="ss-form-panel">
<h1>{{ __('sellissimo::shop.this_order_needs_an_update') }}</h1><p>{{ $reason }}</p><p>{{ __('sellissimo::shop.contact_the_shop_for_an_updated_payment_link') }}</p>
</div>
@endsection
