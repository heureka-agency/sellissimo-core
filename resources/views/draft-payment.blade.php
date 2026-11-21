@extends('sellissimo::layout')
@section('title',__('sellissimo::shop.review_your_order'))
@section('surface', 'ss-document')
@section('content')
<article class="ss-panel">
<h1>{{ __('sellissimo::shop.review_your_order') }}</h1><p>{{ __('sellissimo::shop.receipt_for') }} {{ $email }}</p>
@include('sellissimo::order-lines', ['receipt' => $quote])
<p><strong>{{ __('sellissimo::shop.billing_address') }}</strong><br>{{ implode(', ', array_filter($quote['address'])) }}</p>
@if($quote['shipping'])<p><strong>{{ __('sellissimo::shop.shipping_address') }}</strong><br>{{ implode(', ',array_filter($quote['shipping_address'] ?? $quote['address'])) }}</p>@endif
@if(!empty($quote['shipping_instructions']))<p><strong>{{ __('sellissimo::shop.delivery_instructions') }}</strong><br>{{ $quote['shipping_instructions'] }}</p>@endif
@foreach(\Sellissimo\Core\CommerceFields::visible($quote['order_fields'] ?? []) as $field)<p><strong>{{ $field['label'] }}</strong> {{ $field['value'] }}</p>@endforeach
@include('sellissimo::order-breakdown', ['receipt' => $quote])
<h2>{{ __('sellissimo::shop.total') }} {{ \Sellissimo\Core\Money::display($quote['total'],$quote['currency']) }}</h2>
@if(\Sellissimo\Core\StoreFeatures::enabled($site, 'vouchers'))
<form class="ss-form ss-block" method="post" action="{{ $url }}">@csrf<input type="hidden" name="action" value="apply_voucher">
<label>{{ __('Gift voucher code') }}<input name="voucher" type="password" autocomplete="off" maxlength="80"></label>
<button class="ss-button--quiet">{{ __('Apply or clear gift voucher') }}</button></form>
@endif
<form class="ss-form ss-block" method="post" action="{{ $url }}">@csrf<input name="digest" type="hidden" value="{{ $digest }}">
@foreach($steps as $handle => $definition)
@if(isset($definition['fields']))<fieldset><legend>{{ $definition['label'] }}</legend>
@include('sellissimo::checkout-step-fields', compact('definition', 'handle', 'quote'))
</fieldset>@endif
@endforeach
@if(($quote['amount_due'] ?? $quote['total']) && $gateways)<label>{{ __('sellissimo::shop.payment_method') }}<select name="gateway" required>@foreach($gateways as $method)<option value="{{ $method }}">{{ $method === 'pay_on_delivery' ? __('sellissimo::shop.pay_on_delivery') : \Illuminate\Support\Str::headline($method) }}</option>@endforeach</select></label>@endif
@if(($quote['amount_due'] ?? $quote['total']) && !$gateways)<p>{{ __('sellissimo::shop.no_payment_method_is_currently_available_for_this_order') }}</p>@else<button>{{ __('sellissimo::shop.place_order') }}</button>@endif</form>
</article>
@endsection
