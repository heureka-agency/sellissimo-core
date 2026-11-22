@php($policy = \Sellissimo\Core\CheckoutPolicy::forSite($site))
@if($policy['fields'])
<input name="policy_version" type="hidden" value="{{ $policy['version'] }}">
@foreach($policy['fields'] as $handle => $field)
<label class="ss-checkout-consent">
    <input type="checkbox" name="consents[{{ $handle }}]" value="1" @required($field['required'])
        @checked(old('policy_version') === $policy['version'] && old('consents.'.$handle))
        @if($errors->has('consents.'.$handle)) aria-invalid="true" aria-describedby="ss-consent-{{ $handle }}-error" @endif>
    <span>{{ $field['label'] }} @isset($field['document'])<a href="{{ $field['document']['url'] }}" target="_blank" rel="noopener">{{ $field['document']['title'] }}</a>@endisset</span>
</label>
@error('consents.'.$handle)<small class="field-error" id="ss-consent-{{ $handle }}-error">{{ $message }}</small>@enderror
@endforeach
@error('policy_version')<p role="alert">{{ $message }}</p>@enderror
@endif
@foreach(\Sellissimo\Core\OperationalSettings::policyLinks($site) as $type => $url)
    @unless(isset($policy['fields'][str_replace('_page', '', $type)]))
    <a href="{{ $url }}">{{ $type === 'terms_page' ? __('Terms and conditions') : __('Privacy policy') }}</a>
    @endunless
@endforeach
@if(!$policy['available'])
    <p role="alert">{{ __('sellissimo::shop.policy_unavailable') }}</p>
@elseif(($receipt['amount_due'] ?? $receipt['total']) > 0 && !$gateways)
    <p role="status">{{ __('sellissimo::shop.online_payment_is_currently_unavailable') }}</p>
@else
    @if($receipt['total'] > 0 && $policy['payment_notice'])<p>{{ $policy['payment_notice'] }}</p>@endif
    <button type="submit">{{ __('sellissimo::shop.'.($receipt['total'] === 0 ? 'place_free_order' : ($policy['payment_obligation'] ? 'order_with_payment_obligation' : 'place_order'))) }}</button>
@endif
