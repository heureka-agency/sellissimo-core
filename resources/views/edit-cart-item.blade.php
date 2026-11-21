@extends('sellissimo::layout')
@section('title', __('sellissimo::shop.edit_choices'))
@section('surface', 'ss-narrow')
@section('content')
<p class="ss-context"><a href="{{ route('sellissimo.cart.page', ['site'=>$site, ...$filters]) }}">{{ __('sellissimo::shop.your_cart') }}</a></p>
<h1>{{ __('sellissimo::shop.edit_choices') }} · {{ $product['title'] }}</h1>
<form class="checkout-fields ss-panel" method="post" enctype="multipart/form-data" action="{{ route('sellissimo.item', ['cart'=>$cart, ...$filters]) }}">
    @csrf
    <input type="hidden" name="return_to" value="cart">
    <input type="hidden" name="sku" value="{{ $product['sku'] }}">
    <input type="hidden" name="line_key" value="{{ $key }}">
    @foreach($product['option_fields'] as $handle=>$field)
        @if($errors->has('options.'.$handle))
            @section('sellissimo.error.options.'.$handle, 'edit-option-'.$handle)
            @section('sellissimo.label.options.'.$handle, $field['label'])
        @endif
        <label>{{ $field['label'] }}<input id="edit-option-{{ $handle }}" name="options[{{ $handle }}]" value="{{ old('options.'.$handle, $options[$handle] ?? '') }}" maxlength="{{ $field['max_length'] }}" @required($field['required']) @if($errors->has('options.'.$handle)) aria-invalid="true" aria-describedby="edit-option-{{ $handle }}-error" @endif>
        @error('options.'.$handle)<small class="field-error" id="edit-option-{{ $handle }}-error">{{ $message }}</small>@enderror</label>
    @endforeach
    @include('sellissimo::commerce-fields', ['prefix'=>'line_fields'])
    @if($errors->has('quantity'))
        @section('sellissimo.error.quantity', 'edit-quantity')
        @section('sellissimo.label.quantity', __('sellissimo::shop.quantity'))
    @endif
    <label>{{ __('sellissimo::shop.quantity') }}<input id="edit-quantity" type="number" min="1" max="999" required name="quantity" value="{{ old('quantity', $quantity) }}" @if($errors->has('quantity')) aria-invalid="true" aria-describedby="edit-quantity-error" @endif>
    @error('quantity')<small class="field-error" id="edit-quantity-error">{{ $message }}</small>@enderror</label>
    <button>{{ __('sellissimo::shop.save_choices') }}</button>
</form>
@endsection
