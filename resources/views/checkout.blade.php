@extends('sellissimo::layout')
@section('title', __('sellissimo::shop.checkout'))
@section('content')
@include('sellissimo::retry-cart')
@php($steps = array_keys($definitions))
@php($link = fn ($target) => route('sellissimo.checkout.page', ['site' => $site, 'step' => $target]))
<p class="ss-context"><a href="{{ route('sellissimo.cart.page', ['site'=>$site]) }}">{{ __('sellissimo::shop.your_cart') }}</a></p>
<h1>{{ __('sellissimo::shop.checkout') }}</h1>
@php($checkoutIdentity = \Sellissimo\Core\StoreIdentity::snapshot($site))
@if($checkoutIdentity)<div class="ss-checkout-identity">@include('sellissimo::store-identity', ['identity' => $checkoutIdentity])</div>@endif
<nav aria-label="{{ __('sellissimo::shop.checkout') }}"><ol class="checkout-steps">@foreach($steps as $index=>$target)
<li @if($target === $step) aria-current="step" @endif>@if($index < array_search($step, $steps))<a href="{{ $link($target) }}">{{ $definitions[$target]['label'] }}</a>@else{{ $definitions[$target]['label'] }}@endif</li>
@endforeach</ol></nav>
@foreach($receipt['country_errors'] ?? [] as $message)<p role="alert">{{ $message }}</p>@endforeach
@if($notice)<p role="status">{{ $notice }}</p>@endif
<div class="checkout-layout"><section class="checkout-content"><h2>{{ $definitions[$step]['label'] }}</h2>
@if($step === 'contact')
@if(auth()->guest())<p><a href="{{ route('sellissimo.account.access', ['site'=>$site, 'next'=>'checkout']) }}">{{ __('sellissimo::shop.sign_in') }}</a> · {{ __('sellissimo::shop.continue_as_guest') }}</p>@endif
<form id="ss-checkout-step" class="checkout-fields" method="post" enctype="multipart/form-data" action="{{ route('sellissimo.quote', $cart) }}">@csrf
<input type="submit" hidden disabled aria-hidden="true">
<input type="hidden" name="checkout_step" value="contact">
@include('sellissimo::text-input', ['input' => ['name' => 'email', 'id' => 'checkout-email', 'label' => __('sellissimo::shop.email'), 'type' => 'email', 'autocomplete' => 'email', 'value' => $contact ?? $recoveryEmail ?? auth()->user()?->email]])
<input type="hidden" name="recovery_consent" value="0"><label><span><input type="checkbox" name="recovery_consent" value="1" @checked(old('recovery_consent', (bool) $recoveryEmail))> {{ __('sellissimo::shop.email_me_a_link_if_i_leave_this_cart_behind') }}</span></label>
<fieldset>

<legend>{{ __('sellissimo::shop.billing_address') }}</legend>
@if($physical)
@include('sellissimo::address-inputs', ['field'=>'address','address'=>$receipt['address']])
@else
<div data-digital-billing="{{ route('sellissimo.billing.fields', $cart) }}">
@include('sellissimo::address-inputs', ['field'=>'address','address'=>$receipt['address'], 'onlyFields'=>session('sellissimo.billing_fields.'.$site, $receipt['billing_fields'])])
</div>
@endif
</fieldset>
@if($physical)
<div class="checkout-destination">
<input id="use-shipping-address" type="checkbox" name="use_shipping_address" value="1" aria-controls="shipping-fields"
    @checked(old('use_shipping_address', session()->hasOldInput() ? false : isset($receipt['shipping_address'])))>
<label for="use-shipping-address">{{ __('sellissimo::shop.ship_to_different_address') }}</label>
<fieldset id="shipping-fields"><legend>{{ __('sellissimo::shop.separate_shipping_address') }}</legend>
@include('sellissimo::address-inputs', ['field'=>'shipping_address','address'=>$receipt['shipping_address'] ?? []])</fieldset>
</div>
@include('sellissimo::text-input', ['input' => ['name' => 'shipping_instructions', 'label' => __('sellissimo::shop.delivery_instructions'), 'maxlength' => 500, 'required' => false, 'value' => $receipt['shipping_instructions'] ?? '']])

@endif
@if($orderControls)<fieldset><legend>{{ __('sellissimo::shop.order_details') }}</legend>@include('sellissimo::commerce-fields', ['fields'=>$orderControls, 'prefix'=>'order_fields', 'values'=>$fieldValues])</fieldset>@endif

<button>{{ __('sellissimo::shop.continue') }}</button></form>
@elseif($step === 'delivery')
<p>{{ implode(', ', array_filter($receipt['shipping_address'] ?? $receipt['address'])) }} · <a href="{{ $link('contact') }}">{{ __('sellissimo::shop.edit_address') }}</a></p>
@if(!$receipt['delivery_ready'])
<p role="status">{{ __('sellissimo::shop.delivery_pending') }} @if(empty(($receipt['shipping_address'] ?? $receipt['address'])['country'])){{ __('sellissimo::shop.enter_your_delivery_address_to_see_rates') }}@elseif(!$receipt['options']){{ __('sellissimo::shop.no_delivery_method_is_available_for_this_address_check_the_address_or_contact_the_store') }}@elseif(!$receipt['shipping']){{ __('sellissimo::shop.select_a_delivery_method_and_update_your_total') }}@else {{ __('sellissimo::shop.complete_your_delivery_address_and_update_your_total') }}@endif</p>
@endif
<form id="ss-checkout-step" class="checkout-fields" method="post" action="{{ route('sellissimo.quote', $cart) }}">@csrf
<input type="submit" hidden disabled aria-hidden="true">
<input type="hidden" name="checkout_step" value="delivery">
@include('sellissimo::checkout-methods', ['methodType' => 'shipping'])
@if($receipt['options'])<button>{{ __('sellissimo::shop.continue') }}</button>@endif</form>
@elseif(isset($definitions[$step]['fields']))
<form id="ss-checkout-step" class="checkout-fields" method="post" action="{{ route('sellissimo.quote', $cart) }}">@csrf
<input type="submit" hidden disabled aria-hidden="true">
<input type="hidden" name="checkout_step" value="{{ $step }}">
@include('sellissimo::checkout-step-fields', ['definition'=>$definitions[$step], 'handle'=>$step, 'quote'=>$receipt])
<button>{{ __('sellissimo::shop.continue') }}</button></form>
@else
<p>{{ $contact }} · <a href="{{ $link('contact') }}">{{ __('sellissimo::shop.edit_contact') }}</a></p>
<p>{{ implode(', ', array_filter($receipt['shipping_address'] ?? $receipt['address'])) }}</p>
@if($physical)<p>{{ $receipt['shipping']['label'] }} · <a href="{{ $link('delivery') }}">{{ __('sellissimo::shop.edit_delivery') }}</a></p>
@if(!empty($receipt['shipping_instructions']))<p>{{ $receipt['shipping_instructions'] }}</p>@endif
@endif
@foreach($receipt['checkout_steps'] ?? [] as $handle => $summary)
<h3><a href="{{ $link($handle) }}">{{ $summary['label'] }}</a></h3>
@foreach($summary['fields'] as $answer)<p><strong>{{ $answer['label'] }}</strong> {{ $answer['display'] }}</p>@endforeach
@endforeach
@foreach(\Sellissimo\Core\CommerceFields::visible($receipt['order_fields'] ?? []) as $field)<p><strong>{{ $field['label'] }}</strong> {{ $field['value'] }}</p>@endforeach
@if($receipt['missing_billing_fields'] || $receipt['missing_shipping_fields'])
<p role="alert">{{ __('sellissimo::shop.enter_billing_fields_to_calculate_your_total', ['fields'=>implode(', ', array_merge($receipt['missing_billing_fields'], $receipt['missing_shipping_fields']))]) }} <a href="{{ $link('contact') }}">{{ __('sellissimo::shop.edit_address') }}</a></p>
@elseif(!$receipt['stock_shortages'])
<form id="ss-checkout-step" class="checkout-fields" method="post" action="{{ route('sellissimo.checkout',$cart) }}">@csrf
<input type="submit" hidden disabled aria-hidden="true">
<input type="hidden" name="checkout_step" value="review"><input type="hidden" name="quote_token" value="{{ \Sellissimo\Core\Pricing::fingerprint($receipt) }}">
@if(($receipt['amount_due'] ?? $receipt['total']) > 0)
@include('sellissimo::checkout-methods', ['methodType' => 'gateway'])
@endif
@include('sellissimo::checkout-policies')
</form>
@endif
@endif</section>
<aside id="checkout-summary" class="checkout-summary" tabindex="-1" aria-labelledby="checkout-summary-heading"><h2 id="checkout-summary-heading">{{ __('sellissimo::shop.order_summary') }}</h2>
@include('sellissimo::order-lines')
@foreach($receipt['stock_shortages'] as $sku => $available)<p role="alert">{{ __('sellissimo::shop.sku_has_count_available_reduce_the_quantity_or_remove_it_to_continue', ['sku'=>$sku, 'count'=>$available]) }}</p>@endforeach
@include('sellissimo::order-breakdown')
@include('sellissimo::checkout-codes')
<strong class="ss-total">{{ __('sellissimo::shop.'.($receipt['delivery_ready'] && !$receipt['missing_billing_fields'] && !$receipt['missing_shipping_fields'] ? 'total' : 'estimated_total')) }} · {{ \Sellissimo\Core\Money::display($receipt['total'],$receipt['currency']) }}</strong>
@includeIf('sellissimo::trust', ['placement' => 'checkout'])
</aside></div>
<a class="checkout-mobile-total" href="#checkout-summary"><span>{{ __('sellissimo::shop.order_summary') }}</span><strong class="ss-total">{{ __('sellissimo::shop.'.($receipt['delivery_ready'] && !$receipt['missing_billing_fields'] && !$receipt['missing_shipping_fields'] ? 'total' : 'estimated_total')) }} · {{ \Sellissimo\Core\Money::display($receipt['total'],$receipt['currency']) }}</strong></a>
@include('sellissimo::checkout-codes-script')
@if($step === 'contact')
<script>
(() => {
    const form = document.querySelector('.checkout-fields');
    const digital = form.querySelector('[data-digital-billing]');
    form.classList.add('ss-address-autofill');
    let latest = 0;
    async function refresh(savedAddress) {
        const version = ++latest;
        const data = new FormData();
        for (const [key, value] of new FormData(form)) {
            if (key === '_token' || key === 'discount' || key.startsWith('address[')) data.set(key, value);
        }
        if (form.elements.email?.validity.valid) data.set('email', form.elements.email.value);
        if (savedAddress) data.set('saved_address', savedAddress);
        try {
            const response = await fetch(digital.dataset.digitalBilling, { method: 'POST', body: data, headers: { Accept: 'text/html' } });
            if (!response.ok || response.redirected) {
                form.classList.remove('ss-address-autofill');
                return;
            }
            const html = await response.text();
            if (version !== latest) return;
            const focus = digital.contains(document.activeElement) ? document.activeElement.name : null;
            const edits = [...new FormData(form)].filter(([key, value]) => data.has(key) && key.startsWith('address[') && data.get(key) !== value);
            digital.innerHTML = html;
            for (const [name, value] of edits) {
                if (form.elements[name]) form.elements[name].value = value;
            }
            if (focus && form.elements[focus]) form.elements[focus].focus();
        } catch {
            form.classList.remove('ss-address-autofill');
        }
    }
    form.addEventListener('change', event => {
        const picker = event.target;
        if (!picker.matches('[data-address-picker]')) {
            if (digital && digital.contains(picker) && picker.name) refresh();
            return;
        }
        if (!picker.value) return;
        if (digital) { refresh(picker.value); return; }
        const address = JSON.parse(picker.selectedOptions[0].dataset.address);
        const group = picker.dataset.addressPicker;
        for (const field of picker.closest('fieldset').querySelectorAll('input[name],select[name]')) {
            if (!field.name.startsWith(group + '[')) continue;
            const key = field.name.slice(group.length + 1, -1);
            field.value = address[key] ?? '';
        }
        picker.dispatchEvent(new Event('input', { bubbles: true }));
    });
    form.addEventListener('sellissimo:codes', () => { if (digital) refresh(); });
    if (digital && digital.querySelector('[data-region-country]').value) refresh();
})();
</script>
@endif
@if($step === 'contact' && $physical)
<script>
(() => {
    const alternate = document.getElementById('use-shipping-address');
    const form = alternate.form;
    function refresh() {
        for (const group of ['address', 'shipping_address']) {
            const country = form.elements[group + '[country]'];
            const isDestination = (group === 'shipping_address') === alternate.checked;
            const profile = country.selectedOptions[0]?.dataset.deliveryFields?.split(',') || [];
            const fields = profile.map(key => key === 'city_or_region' ? (form.elements[group + '[region]'].value ? 'region' : 'city') : key);
            for (const marker of country.closest('fieldset').querySelectorAll('[data-required-for]')) {
                const key = marker.dataset.requiredFor;
                const required = isDestination && fields.includes(key);
                marker.hidden = !required;
                form.elements[group + '[' + key + ']'].setAttribute('aria-required', String(required));
            }
        }
    }
    form.addEventListener('input', refresh);
    refresh();
})();
</script>
@endif
@endsection
