@extends('sellissimo::layout')
@section('title',__('sellissimo::shop.confirm_order'))
@section('surface', 'ss-narrow')
@section('content')
<div class="ss-form-panel">
<h1>{{ __('sellissimo::shop.add_guest_order') }}</h1><p>{{ __('sellissimo::shop.link_order_id_to_your_purchase_history_and_downloads', ['id'=>$id]) }}</p>
<form class="ss-form" action="{{ $url }}" method="post">@csrf<button>{{ __('sellissimo::shop.add_to_my_account') }}</button></form>
</div>
@endsection
