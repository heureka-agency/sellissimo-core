@extends('sellissimo::layout')
@section('title', __('sellissimo::features.restock'))
@section('surface', 'ss-narrow')
@section('content')
<div class="ss-form-panel">
<h1>{{ __('sellissimo::features.restock') }}</h1>
<p role="status">{{ $message }}</p>
@if($action)<form class="ss-form" method="post" action="{{ request()->fullUrl() }}">@csrf<button>{{ __('sellissimo::features.'.$action) }}</button></form>@endif
@if(\Sellissimo\Core\OperationalSettings::storefront($site))<p><a href="{{ route('sellissimo.shop', compact('site')) }}">{{ __('sellissimo::shop.shop') }}</a></p>@endif
</div>
@endsection
