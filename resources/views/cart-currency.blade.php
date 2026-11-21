<h2>{{ __('Review your cart in :currency', ['currency' => $currencyChange['to']]) }}</h2>
<p>{{ __('This shop changed its selling currency from :from to :to. Review the available items and their new prices.', ['from' => $currencyChange['from'], 'to' => $currencyChange['to']]) }}</p>
<ul>
@foreach($currencyChange['lines'] as $line)
<li>{{ $line['qty'] }} × {{ $line['title'] }} · {{ $line['price'] }}</li>
@endforeach
@foreach($currencyChange['removed'] as $line)
<li>{{ __('Removed') }} · {{ $line['qty'] }} × {{ $line['title'] }}</li>
@endforeach
</ul>
<p>{{ __('Merchandise subtotal') }} · {{ $currencyChange['subtotal'] }}</p>
<form method="post" action="{{ route('sellissimo.cart.currency', ['cart' => $cart, ...$filters]) }}">@csrf
<input type="hidden" name="return_to" value="cart">
<input type="hidden" name="digest" value="{{ $currencyChange['digest'] }}">
<button class="ss-button ss-button--primary">{{ __('Rebuild cart and review checkout') }}</button></form>
