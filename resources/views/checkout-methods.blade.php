@php
    $delivery = $methodType === 'shipping';
    $methods = $delivery ? $receipt['options'] : array_fill_keys($gateways, []);
    $descriptions = config("sellissimo.checkout_method_descriptions.$site.$methodType", []);
    $selected = old($methodType, $delivery ? $receipt['shipping_method'] : null);
    $selected = is_string($selected) && array_key_exists($selected, $methods) ? $selected : array_key_first($methods);
@endphp
@if($methods)
<fieldset class="ss-checkout-methods">
    <legend>{{ __('sellissimo::shop.'.($delivery ? 'delivery' : 'payment_method')) }}</legend>
    @foreach($methods as $key => $option)
        @php($description = $descriptions[$key] ?? $option['description'] ?? ($delivery ? null : __('sellissimo::shop.'.($key === 'pay_on_delivery' ? 'payment_at_delivery' : 'payment_online'))))
        <label class="ss-checkout-method">
            <input type="radio" name="{{ $methodType }}" value="{{ $key }}" @checked($selected === $key) required>
            <svg aria-hidden="true" focusable="false" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                @if($delivery)<path d="m3 7 9-4 9 4v10l-9 4-9-4zM3 7l9 4 9-4M12 11v10"/>
                @elseif($key === 'pay_on_delivery')<path d="M3 7h16v12H3zM4 7V5l12-2 1 4m-1 5h5v4h-5z"/>
                @else<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20m-16 5h4"/>@endif
            </svg>
            <span><strong>{{ $delivery ? $option['label'] : ($key === 'pay_on_delivery' ? __('sellissimo::shop.pay_on_delivery') : \Illuminate\Support\Str::headline($key)) }}</strong>
            @if($description)<small>{{ $description }}</small>@endif</span>
            @if($delivery)<strong>{{ \Sellissimo\Core\Money::display($option['amount'], $receipt['currency']) }}</strong>@endif
        </label>
    @endforeach
</fieldset>
@endif
