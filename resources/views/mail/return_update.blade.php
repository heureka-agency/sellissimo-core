@extends('sellissimo::mail.layout')
@section('details')
<p>{{ __('sellissimo::mail.return_actions.'.$notice->action) }}</p>
@if($notice->replacement)<p>{{ __('sellissimo::shop.replacement_sku') }} · {{ $notice->replacement }}</p>@endif
@if($notice->message)<p>{{ $notice->message }}</p>@endif
@if($notice->tracking)<p>{{ __('sellissimo::mail.tracking') }} · {{ $notice->tracking }}</p>@endif
@endsection
