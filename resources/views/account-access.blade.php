@extends('sellissimo::layout')
@section('title', __('sellissimo::shop.sign_in'))
@section('surface', 'ss-narrow')
@section('content')
<div class="ss-form-panel">
<h1>{{ __('sellissimo::shop.sign_in') }}</h1>
@include('sellissimo::account-forms')
</div>
@endsection
