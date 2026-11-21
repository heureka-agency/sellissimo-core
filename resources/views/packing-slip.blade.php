<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Packing slip') }} · {{ $slips[0]['number'] }}</title>
    @include('sellissimo::print-styles')
</head>
<body>
    <div class="print-actions"><button type="button" onclick="window.print()">{{ __(count($slips) > 1 ? 'Print packing slips' : 'Print packing slip') }}</button></div>
    @foreach($slips as $slip)
        @include('sellissimo::packing-slip-content', $slip)
    @endforeach
</body>
</html>
