@extends('sellissimo::layout')
@section('title', __('sellissimo::shop.find_receipt'))
@section('surface', 'ss-narrow')
@section('content')
<div class="ss-form-panel">
<h1>{{ __('sellissimo::shop.find_receipt') }}</h1>
<p>{{ __('sellissimo::shop.recover_intro') }}</p>
@if(session('receipt_recovery_notice'))<p role="status">{{ __('sellissimo::shop.recover_notice') }}</p>@endif
<form class="checkout-fields" method="post" action="{{ route('sellissimo.receipt.recover.send') }}">
    @csrf
    <input type="hidden" name="site" value="{{ $site }}">
    @include('sellissimo::text-input', ['input' => ['name' => 'number', 'label' => __('sellissimo::shop.recovery_number'), 'maxlength' => 32]])
    @include('sellissimo::text-input', ['input' => ['name' => 'email', 'type' => 'email', 'autocomplete' => 'email', 'label' => __('sellissimo::shop.recovery_email'), 'maxlength' => 254]])
    <button type="submit">{{ __('sellissimo::shop.recover_submit') }}</button>
</form>
</div>
@endsection
