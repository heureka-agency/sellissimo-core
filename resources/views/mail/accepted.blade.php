@extends('sellissimo::mail.layout')
@section('details')
<p>{{ __('sellissimo::mail.accepted.due') }} · {{ $dueOnDelivery }}</p>
@endsection
