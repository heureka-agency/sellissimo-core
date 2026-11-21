{{ $brand }}
{{ __("sellissimo::mail.$type.message") }}
@isset($order)
{{ __('sellissimo::shop.order_number') }} {{ \Sellissimo\Core\OrderNumber::display($order) }}
@endisset
@isset($orderTotal)
{{ __('sellissimo::shop.total') }} {{ $orderTotal }}
@endisset
@isset($formattedBalance)
{{ __('sellissimo::shop.amount_due') }} {{ $formattedBalance }}
@endisset
@isset($productTitle)
{!! $productTitle !!}
@endisset
@isset($detail)
{{ $detail['number'] ?? $detail['sku'] }} · {{ isset($detail['status']) ? __('sellissimo::shop.'.$detail['status']) : __('sellissimo::shop.count_available', ['count' => $detail['available']]) }}
@endisset
@isset($unsubscribe)
{{ __('sellissimo::features.unsubscribe') }} {!! $unsubscribe !!}
@endisset
@isset($dueOnDelivery)
{{ __('sellissimo::mail.accepted.due') }} · {{ $dueOnDelivery }}
@endisset
@isset($code)
{!! $amount !!}
{!! $code !!}
@endisset
@if(isset($amount) && !isset($code))
{{ __('sellissimo::shop.refund') }} · {{ $amount }}
@endif
@isset($notice)
{{ __('sellissimo::mail.return_actions.'.$notice->action) }}
@if($notice->replacement)
{{ __('sellissimo::shop.replacement_sku') }} · {!! $notice->replacement !!}
@endif
@if($notice->message)
{!! $notice->message !!}
@endif
@if($notice->tracking)
{{ __('sellissimo::mail.tracking') }} · {!! $notice->tracking !!}
@endif
@endisset
@isset($parcel)
{{ __('sellissimo::mail.tracking') }} {{ $parcel->tracking }}
@if($parcel->tracking_url ?? null)
{!! $parcel->tracking_url !!}
@endif
@endisset

{{ __("sellissimo::mail.$type.action") }}
{!! $url !!}

@if(!empty($identity))
{{ implode(', ', array_filter(array_diff_key($identity, ['trading_name' => true]))) }}
@endif
