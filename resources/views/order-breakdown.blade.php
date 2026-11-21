@if(!empty($receipt['voucher']))
<p>{{ __('Gift voucher ending in :ending', ['ending' => $receipt['voucher']['ending']]) }} · {{ \Sellissimo\Core\Money::display($receipt['voucher']['amount'], $receipt['currency']) }}</p>
<p>{{ __('Other payment') }} · {{ \Sellissimo\Core\Money::display($receipt['amount_due'], $receipt['currency']) }}</p>
@endif
@php
    $money = fn ($amount) => \Sellissimo\Core\Money::display($amount, $receipt['currency']);
    $offers = $receipt['promotions'] ?? array_filter([$receipt['promotion'] ?? null]);
    $shipping = $receipt['shipping'] ?? null;
    $deliveryTax = $shipping['tax'] ?? 0;
    $charges = $receipt['charges'] ?? [];
    $chargeTax = array_sum(array_column($charges, 'tax'));
    $tax = $receipt['tax'] ?? 0;
    $discount = $receipt['discount'] ?? 0;
    $subtotal = $receipt['subtotal'] ?? ($receipt['total'] + $discount - $tax - (($shipping['amount'] ?? 0) - $deliveryTax) - array_sum(array_column($charges, 'subtotal')));
@endphp
<dl class="order-breakdown">
    <div><dt>{{ __('sellissimo::shop.cart_subtotal') }}</dt><dd>{{ $money($subtotal) }}</dd></div>
    @foreach($offers as $offer)
    <div><dt>{{ $offer['label'] }}
        <small>{{ !empty($offer['free_shipping']) ? __('sellissimo::shop.free_shipping') : (!empty($offer['automatic']) ? __('sellissimo::shop.automatic_discount') : __('sellissimo::shop.coupon_applied')) }}</small>
    </dt><dd>{{ !empty($offer['discount']) ? '−'.$money($offer['discount']) : $money(0) }}</dd></div>
    @endforeach
    @if($discount && !$offers)
    <div><dt>{{ __('sellissimo::shop.discount') }}</dt><dd>−{{ $money($discount) }}</dd></div>
    @endif
    @if($shipping || ($physical ?? false))
    <div><dt>{{ __('sellissimo::shop.delivery') }} @if($shipping)<small>{{ $shipping['label'] }}</small>@endif</dt>
        <dd>{{ !$shipping ? __('sellissimo::shop.delivery_pending') : ($shipping['amount'] ? $money($shipping['amount'] - $deliveryTax) : __('sellissimo::shop.free_delivery')) }}</dd></div>
    @endif
    @foreach($charges as $charge)
    <div><dt>{{ $charge['label'] }}</dt><dd>{{ $money($charge['subtotal']) }}</dd></div>
    @endforeach
    <div><dt>{{ __('sellissimo::shop.tax') }}</dt><dd>{{ $money($tax) }}</dd></div>
</dl>
@if(isset($shipping['tax']) || $chargeTax)
<details class="order-tax-details">
    <summary>{{ __('sellissimo::shop.tax_breakdown') }}</summary>
    <dl class="order-breakdown">
    <div><dt>{{ __('sellissimo::shop.merchandise_tax') }}</dt><dd>{{ $money($tax - $deliveryTax - $chargeTax) }}</dd></div>
    @if(isset($shipping['tax']))<div><dt>{{ __('sellissimo::shop.delivery_tax') }}</dt><dd>{{ $money($deliveryTax) }}</dd></div>@endif
    @if($chargeTax)<div><dt>{{ __('sellissimo::shop.charge_tax') }}</dt><dd>{{ $money($chargeTax) }}</dd></div>@endif
    </dl>
</details>
@endif
