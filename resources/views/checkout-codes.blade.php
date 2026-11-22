@php
    $acceptsVouchers = \Sellissimo\Core\StoreFeatures::enabled($site, 'vouchers');
    $previousCode = old('coupon_code', $receipt['code']);
    $codeAction = route('sellissimo.checkout.codes', ['cart' => $cart, 'step' => $step]);
    $formId = $step === 'review' && ($receipt['missing_billing_fields'] || $receipt['missing_shipping_fields'] || $receipt['stock_shortages']) ? 'ss-checkout-codes' : 'ss-checkout-step';
@endphp
@if($formId === 'ss-checkout-codes')<form id="{{ $formId }}" method="post" action="{{ $codeAction }}">@csrf</form>@endif
<details class="checkout-codes" @if($errors->hasAny(['discount','voucher']) || $receipt['code'] || !empty($receipt['voucher'])) open @endif>
    <summary>{{ __('sellissimo::shop.discount_code') }}@if($acceptsVouchers) · {{ __('Gift voucher code') }}@endif</summary>
    <div class="ss-form">
        <label>{{ __('sellissimo::shop.discount_code') }}
            <input form="{{ $formId }}" name="coupon_code" value="{{ is_string($previousCode) ? $previousCode : '' }}" maxlength="80" @if($errors->has('discount')) aria-invalid="true" aria-describedby="coupon-error" @endif>
        </label>
        @error('discount')<small class="field-error" id="coupon-error">{{ $message }}</small>@enderror
        @if($acceptsVouchers)
            <label>{{ __('Gift voucher code') }}
                <input form="{{ $formId }}" name="voucher_code" type="password" autocomplete="off" maxlength="80" @if($errors->has('voucher')) aria-invalid="true" aria-describedby="voucher-error" @endif>
            </label>
            @error('voucher')<small class="field-error" id="voucher-error">{{ $message }}</small>@enderror
        @endif
        <div class="ss-actions">
            @foreach(['apply' => 'apply_codes', ...($receipt['code'] ? ['remove_discount' => 'remove_discount'] : []), ...(!empty($receipt['voucher']) ? ['remove_voucher' => 'remove_voucher'] : [])] as $action => $label)
                <button type="submit" form="{{ $formId }}" formaction="{{ $codeAction }}" formmethod="post" formnovalidate name="code_action" value="{{ $action }}" class="ss-button--quiet">{{ __('sellissimo::shop.'.$label) }}</button>
            @endforeach
        </div>
        <small id="ss-codes-status" role="status" data-code-status></small>
    </div>
</details>
