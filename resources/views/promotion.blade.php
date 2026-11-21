@foreach($promotions as $promotion)
<p><strong>{{ $promotion['label'] }}</strong> · {{ $promotion['free_shipping'] ? __('sellissimo::shop.free_shipping') : ($promotion['automatic'] ? __('sellissimo::shop.automatic_discount') : __('sellissimo::shop.coupon_applied')) }}</p>
@endforeach
