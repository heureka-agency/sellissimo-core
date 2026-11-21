@php($acceptsVouchers = \Sellissimo\Core\StoreFeatures::enabled($site, 'vouchers'))
<details class="checkout-codes" @if($errors->hasAny(['discount', 'voucher']) || !empty($receipt['code']) || !empty($receipt['voucher'])) open @endif>
    <summary>{{ __('sellissimo::shop.discount_code') }}@if($acceptsVouchers) · {{ __('Gift voucher code') }}@endif</summary>
    <div class="ss-form">
        @include('sellissimo::text-input', ['input' => ['name' => 'discount', 'label' => __('sellissimo::shop.discount_code'), 'value' => $receipt['code'], 'required' => false]])
        @if($acceptsVouchers)
        @include('sellissimo::text-input', ['input' => ['name' => 'voucher', 'label' => __('Gift voucher code'), 'type' => 'password', 'autocomplete' => 'off', 'maxlength' => 80, 'required' => false]])
        @if(!empty($receipt['voucher']))<label><input type="checkbox" name="remove_voucher" value="1">{{ __('Remove applied gift voucher') }}</label>@endif
        @endif
    </div>
</details>
