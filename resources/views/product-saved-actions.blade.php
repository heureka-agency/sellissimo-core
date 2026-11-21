@php($enabledFeatures = \Sellissimo\Core\StoreFeatures::get($site)['features'])
@if(in_array('wishlists', $enabledFeatures, true))
<form class="inline ss-block" method="post" action="{{ route('sellissimo.wishes', compact('site')) }}">@csrf
<input type="hidden" name="sku" value="{{ $product['sku'] }}"><button class="ss-button--quiet" name="action" value="add">{{ __('sellissimo::features.save_item') }}</button>
<a href="{{ route('sellissimo.wishes', compact('site')) }}">{{ __('sellissimo::features.wishlist') }}</a></form>
@endif
@if($product['available'] === 0 && in_array('back_in_stock', $enabledFeatures, true))
<form class="product-buy" method="post" action="{{ route('sellissimo.stock.subscribe', compact('site')) }}">@csrf
<input type="hidden" name="sku" value="{{ $product['sku'] }}">@include('sellissimo::text-input', ['input' => ['name' => 'email', 'label' => __('sellissimo::shop.email'), 'type' => 'email', 'autocomplete' => 'email', 'value' => auth()->user()?->email]])
<button>{{ __('sellissimo::features.notify') }}</button></form>
@endif
