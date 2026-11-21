<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Warehouse pick list') }}</title>
    @include('sellissimo::print-styles')
</head>
<body><main>
    <header><h1>{{ __('Warehouse pick list') }}</h1>
        <button type="button" onclick="window.print()">{{ __('Print') }}</button>
    </header>
    @foreach($groups as $code => $items)
        <section class="pick-location">
            <h2>{{ $locations[$code] ?? __('Untracked items') }}</h2>
            <table>
                <thead><tr>
                    <th scope="col">{{ __('SKU') }}</th><th scope="col">{{ __('Product') }}</th>
                    <th scope="col">{{ __('Orders') }}</th><th scope="col">{{ __('Quantity') }}</th>
                </tr></thead>
                <tbody>@foreach($items as $sku => $row)
                    <tr><td>{{ $sku }}</td><td>{{ $row['title'] }}</td>
                        <td>@foreach($row['orders'] as $number => $qty){{ $number }} × {{ $qty }}@unless($loop->last), @endunless @endforeach</td>
                        <td>{{ $row['quantity'] }}</td></tr>
                @endforeach</tbody>
            </table>
        </section>
    @endforeach
</main></body></html>
