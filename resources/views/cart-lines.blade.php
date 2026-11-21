@foreach($unavailable as $line)
    <form method="post" class="cart-unavailable" action="{{ route('sellissimo.item', ['cart' => $cart, ...$filters]) }}">
        @csrf
        <input type="hidden" name="return_to" value="cart">
        <input type="hidden" name="sku" value="{{ $line['sku'] }}">
        <input type="hidden" name="line_key" value="{{ $line['key'] }}">
        <input type="hidden" name="quantity" value="0">
        <button class="ss-button">{{ __('sellissimo::shop.remove') }} {{ $line['title'] }}</button>
    </form>
@endforeach
@if(!empty($receipt['lines']))

@foreach($receipt['stock_shortages'] as $sku => $available)<p role="alert">{{ __('sellissimo::shop.sku_has_count_available_reduce_the_quantity_or_remove_it_to_continue', ['sku'=>$sku, 'count'=>$available]) }}</p>@endforeach
@php
    $cartQuantities = collect($receipt['lines'])->groupBy('sku')->map(fn ($lines) => $lines->sum('qty'));
@endphp
@foreach($receipt['lines'] as $line)
@php
    $lineKey = $line['key'] ?? $line['sku'];
    $quantityError = old('line_key') === $lineKey && $errors->has('quantity');
    $quantityId = 'cart-quantity-'.$lineKey;
    $available = $receipt['stock_shortages'][$line['sku']] ?? null;
    $reduced = $available === null ? null : max(0, $line['qty'] - ($cartQuantities[$line['sku']] - $available));
@endphp
@if($quantityError)
    @section('sellissimo.error.quantity', $quantityId)
    @section('sellissimo.label.quantity', __('sellissimo::shop.quantity_for_title', ['title' => $line['title']]))
@endif
<article class="cart-line">
    <div class="row">
        <div><h2 class="cart-line-title">{{ $line['title'] }}</h2>
            @foreach(\Sellissimo\Core\CommerceFields::visible($line['line_fields'] ?? []) as $field)
                <small>{{ $field['label'] }} · {{ $field['value'] }}</small>
            @endforeach
            @foreach($line['options'] ?? [] as $option)
                <small>{{ $option['label'] }} · {{ $option['value'] }}</small>
            @endforeach
        </div>
        <span class="cart-line-price">{{ $line['qty'] }} × {{ \Sellissimo\Core\Money::display($line['price'],$receipt['currency']) }} · <strong>{{ \Sellissimo\Core\Money::display($line['total'],$receipt['currency']) }}</strong></span>
    </div>
    <div class="cart-actions">
        @if(!empty($line['options']) || $lineControls || !empty($line['can_edit_options']))
            <a href="{{ route('sellissimo.item.edit', ['cart'=>$cart, 'line'=>$line['key'] ?? $line['sku'], ...$filters]) }}">{{ __('sellissimo::shop.edit_choices') }}</a>
        @endif
        @if($reduced > 0)
        <form method="post" action="{{ route('sellissimo.item', ['cart'=>$cart, ...$filters]) }}">
            @csrf
            <input type="hidden" name="return_to" value="cart">
            <input type="hidden" name="sku" value="{{ $line['sku'] }}">
            <input type="hidden" name="line_key" value="{{ $line['key'] }}">
            <input type="hidden" name="quantity" value="{{ $reduced }}">
            <button class="ss-button">{{ __('sellissimo::shop.reduce_to', ['quantity'=>$reduced]) }}</button>
        </form>
        @endif
        <form method="post" action="{{ route('sellissimo.item', ['cart'=>$cart, ...$filters]) }}">
            @csrf
            <input type="hidden" name="return_to" value="cart">
            <input type="hidden" name="sku" value="{{ $line['sku'] }}">
            <input type="hidden" name="line_key" value="{{ $line['key'] }}">
            <label><span>{{ __('sellissimo::shop.quantity') }}</span>
                <input id="{{ $quantityId }}" type="number" min="0" max="999" required name="quantity" aria-label="{{ __('sellissimo::shop.quantity_for_title', ['title'=>$line['title']]) }}" value="{{ $quantityError ? old('quantity') : $line['qty'] }}" @if($quantityError) aria-invalid="true" aria-describedby="{{ $quantityId }}-error" @endif>
                @if($quantityError)<small class="field-error" id="{{ $quantityId }}-error">{{ $errors->first('quantity') }}</small>@endif
            </label>
            <button class="ss-button">{{ __('sellissimo::shop.update_quantity') }}</button>
        </form>
        <form method="post" action="{{ route('sellissimo.item', ['cart'=>$cart, ...$filters]) }}">
            @csrf
            <input type="hidden" name="return_to" value="cart">
            <input type="hidden" name="sku" value="{{ $line['sku'] }}">
            <input type="hidden" name="line_key" value="{{ $line['key'] }}">
            <input type="hidden" name="quantity" value="0">
            <button class="ss-button ss-button--quiet cart-remove" aria-label="{{ __('sellissimo::shop.remove') }} {{ $line['title'] }}">{{ __('sellissimo::shop.remove') }}</button>
        </form>
    </div>
</article>
@endforeach
@endif
