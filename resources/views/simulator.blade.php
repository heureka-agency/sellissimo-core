@extends('sellissimo::layout')
@section('title', __('sellissimo::shop.simulator'))
@section('surface', 'ss-narrow')
@section('content')
<div class="ss-form-panel">
<h1>{{ __('sellissimo::shop.simulator') }}</h1>
<p>{{ __('sellissimo::shop.simulator_notice') }}</p>
<p>{{ \Sellissimo\Core\OrderNumber::display($order) }} · {{ \Sellissimo\Core\Money::display($payment->amount, $payment->currency) }}</p>
@if ($payment->status === 'pending')
<form class="inline" method="post">@csrf
    @foreach (['paid', 'declined', 'expired'] as $outcome)
        <button type="submit" name="outcome" value="{{ $outcome }}">{{ __('sellissimo::shop.simulate_'.$outcome) }}</button>
    @endforeach
</form>
@else
<p>{{ __('sellissimo::shop.simulator_settled') }}</p>
<a href="{{ \Sellissimo\Core\Checkout::link($order) }}">{{ __('sellissimo::shop.order') }} {{ \Sellissimo\Core\OrderNumber::display($order) }}</a>
@endif
</div>
@endsection
