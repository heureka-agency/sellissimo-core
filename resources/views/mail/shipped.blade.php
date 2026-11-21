@extends('sellissimo::mail.layout')
@section('details')
<p>{{ __('sellissimo::mail.tracking') }} @if($parcel->tracking_url ?? null)<a href="{{ $parcel->tracking_url }}">{{ $parcel->tracking }}</a>@else<strong>{{ $parcel->tracking }}</strong>@endif</p>
@foreach(json_decode($order->receipt, true)['lines'] as $line)
@if($qty = $parcel->items[$line['key'] ?? $line['sku']] ?? 0)
<p>{{ $qty }} × {{ $line['title'] }}@foreach($line['options'] ?? [] as $option) · {{ $option['value'] }}@endforeach</p>
@endif
@endforeach
@endsection
