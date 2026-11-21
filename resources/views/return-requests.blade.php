@extends('sellissimo::layout')
@section('title',__('sellissimo::shop.returns_and_exchanges'))
@section('surface', 'ss-document')
@section('content')
<p class="ss-context">
    @if($receiptUrl)<a href="{{ $receiptUrl }}">{{ __('sellissimo::shop.back_to_receipt', ['number' => $number]) }}</a>
    @else<a href="{{ route('sellissimo.receipt.recover', ['site' => $site]) }}">{{ __('sellissimo::shop.find_receipt') }}</a>@endif
</p>
<header class="ss-page-head">
    <h1>{{ __('sellissimo::shop.returns_and_exchanges') }}</h1>
    <p>{{ __('sellissimo::shop.order') }} {{ $number }}</p>
</header>
@include('sellissimo::return-policy')
@foreach($requests as $item)
<section class="ss-panel"><h2>{{ __('sellissimo::shop.'.$item->status) }} · {{ $item->quantity }} × {{ $item->title }}</h2>
<p>{{ $item->reason }}</p>@if($item->replacement)<p>{{ __('sellissimo::shop.replacement_sku') }} {{ $item->replacement }}</p>@endif
@if($item->inbound_label_url)<p><a href="{{ $item->inbound_label_url }}" rel="noreferrer">{{ __('sellissimo::shop.return_label') }}</a></p>@endif
@if($item->inbound_tracking_url)<p><a href="{{ $item->inbound_tracking_url }}" rel="noreferrer">{{ __('sellissimo::shop.return_tracking') }} {{ $item->inbound_tracking }}</a></p>
@elseif($item->inbound_tracking)<p>{{ __('sellissimo::shop.return_tracking') }} · {{ $item->inbound_tracking }}</p>@endif
@if($balance = $item->balance)
    @if($balance['difference'] !== 0)<p>{{ __('sellissimo::shop.'.($balance['difference'] > 0 ? 'exchange_charge' : 'exchange_credit')) }} · {{ $balance['amount'] }} · {{ $balance['label'] }}</p>@endif
    @if($balance['pay_url'])<p><a href="{{ $balance['pay_url'] }}">{{ __('sellissimo::shop.pay_balance_of') }} {{ $balance['amount'] }}</a></p>@endif
@endif
@if($refund = $item->refund)
    <p><strong>{{ $refund['label'] }}</strong>
    @if($refund['due']) · {{ __('sellissimo::shop.amount_due') }} {{ $refund['dueFormatted'] }}@endif
    @if($refund['pending']) · {{ __('sellissimo::shop.refund_pending') }} {{ $refund['pendingFormatted'] }}@endif
    @if($refund['paid']) · {{ __('sellissimo::shop.refund_settled') }} {{ $refund['paidFormatted'] }}@endif</p>
@endif
@if(count($item->history))
<ol class="ss-timeline">@foreach($item->history as $event)<li>
{{ $event->label }}
@if($event->replacement && $event->action === 'substitute') · {{ $event->replacement }}@endif
@if($event->message)<p>{{ $event->message }}</p>@endif
</li>@endforeach</ol>
@endif
@if($item->staff_message && !collect($item->history)->contains('message', $item->staff_message))<p>{{ __('sellissimo::shop.store_message') }} · {{ $item->staff_message }}</p>@endif
@if($item->parcels->isNotEmpty())
<h3>{{ __('sellissimo::shop.replacement_tracking') }}</h3>
<ul>@foreach($item->parcels as $parcel)<li>{{ $parcel['quantity'] }} × {{ $item->replacement }} · {{ $parcel['tracking'] }}</li>@endforeach</ul>
@elseif($item->tracking)<p>{{ __('sellissimo::shop.replacement_tracking') }} · {{ $item->tracking }}</p>@endif</section>
@endforeach
@forelse($choices as $key=>$line)
@php($editing = old('line_key') === $key)
@php($formIndex = $loop->index)
@if($editing)
    @foreach(['quantity', 'reason', 'replacement'] as $field)
        @if($errors->has($field)) @section('sellissimo.error.'.$field, 'return-'.$field.'-'.$formIndex) @endif
    @endforeach
@endif
<section class="ss-panel"><h2>{{ __('sellissimo::shop.return') }} {{ $line['title'] }}</h2>
<form class="ss-form" method="post" action="{{ $url }}">@csrf<input type="hidden" name="request_id" value="{{ $requestId }}"><input type="hidden" name="line_key" value="{{ $key }}">
<label>{{ __('sellissimo::shop.quantity') }}<input type="number" id="return-quantity-{{ $formIndex }}" name="quantity" min="1" max="{{ $line['quantity'] }}" value="{{ $editing ? old('quantity', 1) : 1 }}" required @if($editing && $errors->has('quantity')) aria-invalid="true" aria-describedby="return-quantity-error" @endif>
@if($editing)@error('quantity')<small class="field-error" id="return-quantity-error">{{ $message }}</small>@enderror @endif</label>
<label>{{ __('sellissimo::shop.reason_for_return') }}<textarea id="return-reason-{{ $formIndex }}" name="reason" maxlength="1000" required @if($editing && $errors->has('reason')) aria-invalid="true" aria-describedby="return-reason-error" @endif>{{ $editing ? old('reason') : '' }}</textarea>
@if($editing)@error('reason')<small class="field-error" id="return-reason-error">{{ $message }}</small>@enderror @endif</label>
<label>{{ __('sellissimo::shop.resolution') }}<select id="return-replacement-{{ $formIndex }}" name="replacement" @if($editing && $errors->has('replacement')) aria-invalid="true" aria-describedby="return-replacement-error" @endif><option value="">{{ __('sellissimo::shop.return_for_refund_review') }}</option>@foreach($line['replacements'] as $option)<option value="{{ $option['sku'] }}" @selected($editing && old('replacement') === $option['sku'])>{{ __('sellissimo::shop.exchange_for') }} {{ $option['title'] }} · {{ $option['sku'] }} · {{ $option['price'] }}</option>@endforeach</select>
@if($editing)@error('replacement')<small id="return-replacement-error" class="field-error">{{ $message }}</small>@enderror @endif</label>
@if(count($line['replacements']))<small>{{ __('sellissimo::shop.exchange_pricing_note') }}</small>@endif
<button>{{ __('sellissimo::shop.request_return') }}</button></form></section>
@empty @if($requests->isEmpty())<p class="ss-empty">{{ __('sellissimo::shop.no_delivered_items_are_available_to_return') }}</p>@endif @endforelse
@endsection
