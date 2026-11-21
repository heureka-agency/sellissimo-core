@extends('sellissimo::layout')
@section('title', $product['seo_title'] ?: $product['title'])
@push('head')
<link rel="canonical" href="{{ \Sellissimo\Core\ProductMetadata::url($product['sku'], $site) }}">
<script type="application/ld+json">{!! json_encode(\Sellissimo\Core\ProductMetadata::schema($product, $variants, $site), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}</script>
@if($description = $product['seo_description'] ?: $product['summary'])<meta name="description" content="{{ $description }}">@endif
@endpush
@section('content')
<p class="ss-context"><a href="{{ route('sellissimo.shop', ['site' => $site, ...$filters]) }}">{{ __('sellissimo::shop.back_to_results') }}</a></p>
<div class="product-layout">
@if($product['images'])<div class="product-gallery">@foreach($product['images'] as $image)<a href="{{ $image['url'] }}"><img src="{{ $image['url'] }}" alt="{{ $image['alt'] }}" @if(!$loop->first) loading="lazy" @endif></a>@endforeach</div>@endif
<div class="product-info ss-panel">
<h1>{{ $product['product_title'] }}</h1>
@if($product['brand'] || $product['product_type'])<p>{{ implode(' · ', array_filter([$product['brand'], $product['product_type']])) }}</p>@endif
@if($product['summary'])<p>{{ $product['summary'] }}</p>@endif
@if($variants->count() > 1)
@foreach($choices as $attribute => $options)
<fieldset class="variant-options"><legend>{{ $attribute }}</legend><div class="product-variants">
@foreach($options as $option)
<a href="{{ route('sellissimo.product', ['sku'=>$option['sku'], 'site'=>$site, ...$filters]) }}" @if($option['selected']) aria-current="true" @endif>
{{ $option['label'] }}@if($option['available'] === 0) · {{ __('sellissimo::shop.out_of_stock') }}@endif</a>
@endforeach</div></fieldset>
@endforeach
@endif
@if(app(\Sellissimo\Core\Registry::class)->configured('unit_price_providers'))<p>{{ __('sellissimo::shop.catalog_price_note') }}</p>@endif
<p class="product-price">@if($product['compare_at_price'] !== null)<del>{{ \Sellissimo\Core\Money::display($product['compare_at_price'],$product['currency']) }}</del>@endif
<strong>{{ \Sellissimo\Core\Money::display($product['price'],$product['currency']) }}</strong>
@if($unit = $product['unit_price']) · {{ \Sellissimo\Core\Money::display($unit['amount'],$product['currency']) }} / {{ $unit['unit'] }}@endif</p>
<p>{{ $product['digital'] ? ($product['downloadable'] ? __('sellissimo::shop.digital_download') : __('sellissimo::shop.service_no_shipping_required')) : ($product['available'] === null ? __('sellissimo::shop.available_to_order') : ($product['available'] ? __('sellissimo::shop.count_available', ['count'=>$product['available']]) : __('sellissimo::shop.out_of_stock'))) }}</p>
@if($prerequisite)<p role="status">{{ $prerequisite }}</p>
@if(auth()->guest())<a href="{{ route('sellissimo.account.access', ['site'=>$site, 'next'=>'product', 'sku'=>$product['sku'], ...$filters]) }}">{{ __('sellissimo::shop.sign_in') }}</a>@endif
@elseif($product['available'] !== 0)
<form enctype="multipart/form-data" method="post" action="{{ route('sellissimo.product.add', ['sku'=>$product['sku'], ...$filters]) }}" class="product-buy">@csrf<input type="hidden" name="return_to" value="cart"><input type="hidden" name="site" value="{{ $site }}">
@foreach($product['option_fields'] as $key=>$f)
@if($errors->has('options.'.$key))
    @section('sellissimo.error.options.'.$key, 'product-option-'.$key)
    @section('sellissimo.label.options.'.$key, $f['label'])
@endif
<label>{{ $f['label'] }}<input id="product-option-{{ $key }}" name="options[{{ $key }}]" value="{{ old('options.'.$key) }}" maxlength="{{ $f['max_length'] }}" @required($f['required']) @if($errors->has('options.'.$key)) aria-invalid="true" aria-describedby="product-option-{{ $key }}-error" @endif>
@error('options.'.$key)<small id="product-option-{{ $key }}-error" class="field-error">{{ $message }}</small>@enderror</label>@endforeach
@include('sellissimo::commerce-fields', ['fields'=>$lineControls, 'prefix'=>'line_fields', 'values'=>[]])
@if($errors->has('quantity'))
    @section('sellissimo.error.quantity', 'product-quantity')
    @section('sellissimo.label.quantity', __('sellissimo::shop.quantity'))
@endif
<label>{{ __('sellissimo::shop.quantity') }}<input id="product-quantity" type="number" name="quantity" value="{{ old('quantity',1) }}" min="1" max="{{ min(999,$product['available'] ?? 999) }}" required @if($errors->has('quantity')) aria-invalid="true" aria-describedby="product-quantity-error" @endif>
@error('quantity')<small class="field-error" id="product-quantity-error">{{ $message }}</small>@enderror</label><div><button class="ss-button--primary">{{ __('sellissimo::shop.add_to_cart') }}</button></div>
</form>@endif
@include('sellissimo::product-saved-actions')
@if(!empty($product['final_sale']))<p>{{ __('sellissimo::shop.final_sale') }}</p>
@elseif(!$product['digital'] && $returnDays !== null)<p>{{ __('sellissimo::shop.product_return_window', ['days' => $returnDays]) }}</p>@endif

</div></div>
@if($product['description'])<section class="ss-panel"><h2>{{ __('sellissimo::shop.description') }}</h2>{!! \Illuminate\Support\Str::markdown($product['description'], ['html_input'=>'strip','allow_unsafe_links'=>false]) !!}</section>@endif
<section class="ss-panel"><h2>{{ __('sellissimo::shop.product_details') }}</h2><dl class="product-specs">
<dt>SKU</dt><dd>{{ $product['sku'] }}</dd>
@foreach(['barcode'=>__('sellissimo::shop.barcode'),'mpn'=>__('sellissimo::shop.part_number')] as $key=>$label)@if(filled($product[$key]))<dt>{{ $label }}</dt><dd>{{ $product[$key] }}</dd>@endif @endforeach
@foreach($product['attributes'] as $key=>$value)<dt>{{ $key }}</dt><dd>{{ $value }}</dd>@endforeach
@foreach($product['specifications'] as $row)<dt>{{ $row['name'] }}</dt><dd>{{ $row['value'] }}</dd>@endforeach
@foreach(['categories'=>__('sellissimo::shop.categories'),'tags'=>__('sellissimo::shop.tags')] as $key=>$label)@if($product[$key])<dt>{{ $label }}</dt><dd>{{ implode(', ',array_column($product[$key], 'title')) }}</dd>@endif @endforeach
</dl></section>
@if($product['related'])<section class="ss-panel"><h2>{{ __('sellissimo::shop.related_products') }}</h2><ul>@foreach($product['related'] as $item)<li><a href="{{ route('sellissimo.product', ['sku'=>$item['sku'],'site'=>$site, ...$filters]) }}">{{ $item['title'] }}</a></li>@endforeach</ul></section>@endif
@endsection
