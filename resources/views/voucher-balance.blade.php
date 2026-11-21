@extends('sellissimo::layout')
@section('title', __('Gift voucher balance'))
@section('surface', 'ss-narrow')
@section('content')
<div class="ss-form-panel">
<h1>{{ __('Gift voucher balance') }}</h1>
<form class="ss-form" method="post" action="{{ route('sellissimo.voucher.balance', ['site' => $site]) }}">@csrf
@include('sellissimo::text-input', ['input' => ['name' => 'voucher', 'label' => __('Gift voucher code'), 'type' => 'password', 'autocomplete' => 'off', 'maxlength' => 80]])
<button>{{ __('Check balance') }}</button></form>
@isset($balance)
    <div class="ss-balance-result" role="status">
        <span>{{ __('Gift voucher ending in :ending', ['ending' => $balance['ending']]) }}</span>
        <strong class="ss-total">{{ \Sellissimo\Core\Money::display($balance['amount'], $balance['currency']) }}</strong>
    </div>
@endisset
</div>
@endsection
