@extends('sellissimo::layout')
@section('title',__('sellissimo::shop.review_order_changes'))
@section('surface', 'ss-document')
@section('content')
<article class="ss-panel">
<h1>{{ __('sellissimo::shop.review_changes_to_order') }} {{ $number }}</h1><p>{{ $reason }}</p>
@include('sellissimo::order-lines')
@include('sellissimo::order-breakdown')
<p><strong>{{ __('sellissimo::shop.total') }} {{ \Sellissimo\Core\Money::display($receipt['total'], $receipt['currency']) }}</strong></p>
<form class="ss-form ss-block" method="post" action="{{ $url }}">@csrf<button>{{ __('sellissimo::shop.pay_balance_of') }} {{ $total }}</button></form>
</article>
@endsection
