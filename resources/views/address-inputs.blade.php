@php
    $tokens = ['name'=>'name', 'company'=>'organization', 'street'=>'address-line1', 'street2'=>'address-line2', 'city'=>'address-level2', 'region'=>'address-level1', 'postal_code'=>'postal-code', 'phone'=>'tel'];
    $section = $field === 'shipping_address' ? 'shipping' : 'billing';
    $policy = app(\Sellissimo\Core\CheckoutCountries::class);
    $countryOptions = isset($step) ? $policy->options($site, $section, true) : \Sellissimo\Core\Http\StoreCountries::labels(true);
    $defaultCountry = isset($step) ? ($policy->settings($site)['default_'.$section] ?? '') : '';
    $eligibleAddresses = array_filter($savedAddresses ?? [], fn ($entry) => isset($countryOptions[$entry['address']['country'] ?? '']));
    $restore = $useOldInput ?? true;
    $value = function ($key, $fallback = '') use ($restore, $field, $address) {
        $saved = $address[$key] ?? $fallback;
        $input = $restore ? old($field.'.'.$key, $saved) : $saved;
        return is_scalar($input) ? $input : '';
    };
    $regionCountry = $value('country', $defaultCountry);
    $regionList = 'regions-'.\Illuminate\Support\Str::uuid();
    $idPrefix = $idPrefix ?? $field;
    $pickerKey = 'saved_addresses.'.$field;
@endphp
@if(!empty($eligibleAddresses))
<div class="ss-saved-address">
    @if($errors->has($pickerKey))
        @section('sellissimo.error.'.$pickerKey, $idPrefix.'-saved')
        @section('sellissimo.label.'.$pickerKey, __('sellissimo::shop.saved_address'))
    @endif
    <label>{{ __('sellissimo::shop.saved_address') }}
        <select id="{{ $idPrefix }}-saved" name="saved_addresses[{{ $field }}]" data-address-picker="{{ $field }}" @if($errors->has($pickerKey)) aria-invalid="true" aria-describedby="{{ $idPrefix }}-saved-error" @endif>
            <option value="">{{ __('sellissimo::shop.choose_saved_address') }}</option>
            @foreach($eligibleAddresses as $saved)
                <option value="{{ $saved['id'] }}" @unless(isset($onlyFields)) data-address="{{ json_encode($saved['address']) }}" @endunless>{{ $saved['label'] }} · {{ isset($onlyFields) ? ($saved['address']['country'] ?? '') : ($saved['address']['street'] ?? '') }}</option>
            @endforeach
        </select>
        @error($pickerKey)<small class="field-error" id="{{ $idPrefix }}-saved-error">{{ $message }}</small>@enderror
    </label>
    <button type="submit" class="ss-button--quiet" name="use_saved_address" value="{{ $field }}" formnovalidate>{{ __('sellissimo::shop.use_this_address') }}</button>
</div>
@endif
@foreach(['name'=>__('sellissimo::shop.full_name'),'company'=>__('sellissimo::shop.company'),'street'=>__('sellissimo::shop.street_address'),'street2'=>__('sellissimo::shop.apartment_suite_or_floor'),'city'=>__('sellissimo::shop.city'),'region'=>__('sellissimo::shop.state_or_region'),'postal_code'=>__('sellissimo::shop.postal_code'),'phone'=>__('sellissimo::shop.phone_number')] as $key=>$label)
@continue(isset($onlyFields) && !in_array($key, $onlyFields, true))
@if($restore && $errors->has($field.'.'.$key))
    @section('sellissimo.error.'.$field.'.'.$key, $idPrefix.'-'.$key)
    @section('sellissimo.label.'.$field.'.'.$key, $label)
@endif
<label><span>{{ $label }} <span data-required-for="{{ $key }}" aria-hidden="true" hidden>*</span></span><input id="{{ $idPrefix }}-{{ $key }}" @if($key === 'region') list="{{ $regionList }}" data-region-picker @endif name="{{ $field }}[{{ $key }}]" autocomplete="{{ $section }} {{ $tokens[$key] }}" @if($restore && $errors->has($field.'.'.$key)) aria-invalid="true" aria-describedby="error-{{ $idPrefix }}-{{ $key }}" @endif value="{{ $value($key) }}" @if($key === 'phone') type="tel" @endif>
@if($restore)@error($field.'.'.$key)<small class="field-error" id="error-{{ $idPrefix }}-{{ $key }}">{{ $message }}</small>@enderror @endif</label>
@endforeach
<datalist id="{{ $regionList }}">@foreach(\Sellissimo\Core\PostalRegions::options($regionCountry ?? '') as $region)<option value="{{ $region[0] }}">{{ $region[1] }}</option>@endforeach</datalist>
@if($restore && $errors->has($field.'.country'))
    @section('sellissimo.error.'.$field.'.country', $idPrefix.'-country')
    @section('sellissimo.label.'.$field.'.country', __('sellissimo::shop.country'))
@endif
<label>{{ __('sellissimo::shop.country') }}<select id="{{ $idPrefix }}-country" data-region-country name="{{ $field }}[country]" autocomplete="{{ $section }} country" @if($restore && $errors->has($field.'.country')) aria-invalid="true" aria-describedby="error-{{ $idPrefix }}-country" @endif @required($field==='address')><option value="">{{ __('sellissimo::shop.choose_a_country') }}</option>
@foreach($countryOptions as $code=>$name)<option value="{{ $code }}" data-delivery-fields="{{ implode(',', \Sellissimo\Core\DeliveryFields::profiles()[$code] ?? []) }}" @selected($regionCountry === $code)>{{ $name }}</option>@endforeach
</select>
@if($restore)@error($field.'.country')<small class="field-error" id="error-{{ $idPrefix }}-country">{{ $message }}</small>@enderror @endif</label>

@once
@push('head')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const countries = {{ Illuminate\Support\Js::from(\Sellissimo\Core\PostalRegions::countries()) }};
    for (const select of document.querySelectorAll('[data-region-country]')) {
        const input = select.closest('fieldset')?.querySelector('[data-region-picker]');
        if (!input?.list) continue;
        function refresh() {
            const rows = countries[select.value] || [];
            input.list.replaceChildren(...rows.map(([value, label]) => new Option(label, value)));
        }
        select.addEventListener('change', () => { input.value = ''; refresh(); });
        select.closest('fieldset').querySelector('[data-address-picker]')?.addEventListener('input', refresh);
    }
});
</script>
@endpush
@endonce
