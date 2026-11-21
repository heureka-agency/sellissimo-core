@isset($productTitle)<p>{{ $productTitle }}</p>@endisset
@isset($detail)
@if(isset($detail['id']))<p>{{ $detail['number'] }} · {{ __('sellissimo::shop.'.$detail['status']) }}</p>
@else<p>{{ $detail['sku'] }} · {{ __('sellissimo::shop.count_available', ['count' => $detail['available']]) }}</p>@endif
@endisset
@isset($unsubscribe)<p><a href="{{ $unsubscribe }}">{{ __('sellissimo::features.unsubscribe') }}</a></p>@endisset
