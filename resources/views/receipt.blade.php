@extends('sellissimo::layout')
@section('title',__('sellissimo::shop.order_receipt'))
@section('surface', 'ss-document')
@section('content')
@if(!empty($account_order))
<p class="ss-context"><a href="{{ route('sellissimo.history', ['site' => $site]) }}">{{ __('sellissimo::shop.my_orders') }}</a></p>
@endif
<header class="ss-page-head">
    <div class="receipt-head">
        <h1>{{ __('sellissimo::shop.order_receipt') }}</h1>
        <span class="ss-badge">{{ \Illuminate\Support\Facades\Lang::has('sellissimo::shop.'.$status) ? __('sellissimo::shop.'.$status) : ucfirst(str_replace('_',' ',$status)) }}</span>
    </div>
    <p><strong>{{ $number }}</strong></p>
    @include('sellissimo::store-identity', ['identity' => $receipt['store_identity'] ?? []])
</header>
@if($payment_rejected)<p role="alert">{{ __('sellissimo::shop.payment_start_rejected') }}</p>@endif
@if($payment_session_pending)
<p role="status">{{ __('sellissimo::shop.pending_payment_setup') }}</p>
<p><a class="ss-button" href="{{ request()->fullUrl() }}">{{ __('sellissimo::shop.refresh_payment_status') }}</a></p>
@endif
@if($payment_url)<p><a class="ss-button ss-button--primary" href="{{ $payment_url }}">{{ __('sellissimo::shop.return_to_payment') }}</a></p>@endif
@if(!empty($retry_placed))
<p role="status">{{ __('sellissimo::shop.retry_already_placed') }} @if($replacement_receipt_url)<a href="{{ $replacement_receipt_url }}">{{ __('sellissimo::shop.retry_latest_receipt') }}</a>@endif</p>
@endif
@if(!empty($retry_url))
<form class="ss-panel ss-form" method="post" action="{{ $retry_url }}">@csrf
    <p>{{ __('sellissimo::shop.retry_review') }}</p><button type="submit">{{ __('sellissimo::shop.retry_payment') }}</button>
</form>
@endif
<section class="ss-panel">
    <h2>{{ __('sellissimo::shop.order_summary') }}</h2>
    @include('sellissimo::order-lines')
    @include('sellissimo::order-breakdown')
    @if(!$payment_summary['refunds'])
    <p class="ss-total">{{ __('sellissimo::shop.total') }} {{ \Sellissimo\Core\Money::display($receipt['total'],$receipt['currency']) }}</p>
    @endif
    <dl class="order-breakdown" aria-label="{{ __('sellissimo::shop.net_paid') }}">
        @if($payment_summary['refunds'] || $payment_summary['additional'])
        <div><dt>{{ __('sellissimo::shop.original_total') }}</dt><dd>{{ \Sellissimo\Core\Money::display($payment_summary['original'], $receipt['currency']) }}</dd></div>
        @if($payment_summary['additional'])
        <div><dt>{{ __('sellissimo::shop.additional_charges') }}</dt><dd>{{ \Sellissimo\Core\Money::display($payment_summary['additional'], $receipt['currency']) }}</dd></div>
        @endif
        @foreach($payment_summary['refunds'] as $refund)
        <div><dt>{{ __('sellissimo::shop.refund') }} @if($refund['date'])<time>{{ \Illuminate\Support\Carbon::parse($refund['date'])->translatedFormat('M j, Y') }}</time>@endif</dt>
            <dd>−{{ \Sellissimo\Core\Money::display($refund['amount'], $receipt['currency']) }}</dd></div>
        @if(!empty($refund['to_voucher']))
        <div><dt>{{ __('Refunded to gift voucher') }}</dt><dd>{{ \Sellissimo\Core\Money::display($refund['to_voucher'], $receipt['currency']) }}</dd></div>
        @endif
        @endforeach
        @endif
        <div @if($payment_summary['refunds']) class="ss-total" @endif>
            <dt>{{ __('sellissimo::shop.net_paid') }}</dt>
            <dd>{{ \Sellissimo\Core\Money::display($payment_summary['net_paid'], $receipt['currency']) }}</dd>
        </div>
    </dl>
</section>
@php($orderFields = \Sellissimo\Core\CommerceFields::visible($receipt['order_fields'] ?? []))
@if(!empty($receipt['address']) || !empty($receipt['shipping_address']) || !empty($receipt['shipping_instructions']) || !empty($receipt['checkout_steps']) || $orderFields)
<section class="ss-panel">
    <h2>{{ __('sellissimo::shop.order_details') }}</h2>
    <div class="receipt-columns">
        @if(!empty($receipt['address']))
        <div><h3>{{ __('sellissimo::shop.billing_address') }}</h3><p>{{ implode(', ',array_filter($receipt['address'])) }}</p></div>
        @endif
        @if(!empty($receipt['shipping_address']))
        <div><h3>{{ __('sellissimo::shop.shipping_address') }}</h3><p>{{ implode(', ',array_filter($receipt['shipping_address'])) }}</p></div>
        @endif
        @if(!empty($receipt['shipping_instructions']))
        <div><h3>{{ __('sellissimo::shop.delivery_instructions') }}</h3><p>{{ $receipt['shipping_instructions'] }}</p></div>
        @endif
    </div>
    @foreach($receipt['checkout_steps'] ?? [] as $summary)
    <h3>{{ $summary['label'] }}</h3>
    @foreach($summary['fields'] as $answer)<p><strong>{{ $answer['label'] }}</strong> {{ $answer['display'] }}</p>@endforeach
    @endforeach
    @foreach($orderFields as $field)<p><strong>{{ $field['label'] }}</strong> {{ $field['value'] }}</p>@endforeach
</section>
@endif
@if($downloads || collect($receipt['lines'])->contains(fn ($line) => isset($line['downloads_remaining'])))
<section class="ss-panel">
    <h2>{{ __('sellissimo::shop.downloads') }}</h2>
    @foreach($receipt['lines'] as $line)
        @php($productName = $line['title'] ?? $line['sku'])
        @if(isset($line['downloads_remaining']))<p>{{ $productName }} · {{ __('sellissimo::shop.count_downloads_remaining', ['count'=>$line['downloads_remaining']]) }}</p>@endif
        @if($url = $downloads[$line['key'] ?? $line['sku']] ?? null)<p><a class="ss-button" href="{{ $url }}">{{ __('sellissimo::shop.download') }} {{ $productName }}@foreach($line['options'] ?? [] as $option) · {{ $option['value'] }}@endforeach</a></p>@endif
    @endforeach
</section>
@endif
@foreach($parcels as $parcel)
<section class="ss-panel">
    <h2>{{ __('sellissimo::shop.parcel') }} {{ $parcel['number'] }}</h2>
    <p>{{ __('sellissimo::shop.tracking_number') }} @if($parcel['tracking_url'] ?? null)<a href="{{ $parcel['tracking_url'] }}" rel="noreferrer">{{ $parcel['tracking'] }}</a>@else{{ $parcel['tracking'] }}@endif</p>
    @if(!empty($parcel['tracking_status']))<p class="ss-badge">{{ $parcel['tracking_status'] }}</p>@endif
    @foreach($receipt['lines'] as $line)
        @if($qty = $parcel['items'][$line['key'] ?? $line['sku']] ?? 0)<p>{{ $qty }} × {{ $line['title'] }} @foreach($line['options'] ?? [] as $option) · {{ $option['value'] }} @endforeach</p>@endif
    @endforeach
</section>
@endforeach
@include('sellissimo::return-policy')
<div class="ss-actions ss-block">
    @if($returns_url)<a class="ss-button" href="{{ $returns_url }}">{{ __('sellissimo::shop.request_or_track_a_return') }}</a>@endif
    @if(!empty($guest_order))<a href="{{ route('sellissimo.account.access', ['site'=>$site, 'claim'=>$number]) }}">{{ __('sellissimo::shop.add_a_guest_purchase') }}</a>@endif
</div>
<p><small>{{ __('sellissimo::shop.order_id') }} {{ $id }}</small></p>
@endsection
