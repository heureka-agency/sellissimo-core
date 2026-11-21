@extends('sellissimo::layout')
@section('title', __('sellissimo::shop.claim_orders'))
@section('surface', 'ss-narrow')
@section('content')
<div class="ss-form-panel">
<h1>{{ __('sellissimo::shop.claim_orders') }}</h1>
@if(is_null($orders))
<p>{{ __('sellissimo::shop.invite_signin', ['email' => $email]) }}</p>
@include('sellissimo::account-forms')
@else
<p>{{ __('sellissimo::shop.invite_review', ['email' => $email]) }}</p>
<ul>@foreach($orders as $number)<li>{{ $number }}</li>@endforeach</ul>
<form class="ss-form" action="{{ $url }}" method="post">@csrf<button>{{ __('sellissimo::shop.add_to_my_account') }}</button></form>
@endif
</div>
@endsection
