<main>
    <header>
        <div><h1>{{ __('Packing slip') }}</h1><p>{{ __('Order') }} {{ $number }} · {{ $date }}</p></div>
    </header>
    <div class="delivery">
        <section>
            <h2>{{ __('Ship to') }}</h2>
            <address>
                @foreach ([$address['name'] ?? null, $address['company'] ?? null, $address['street'] ?? null, $address['street2'] ?? null, trim(implode(' ', array_filter([$address['city'] ?? null, $address['region'] ?? null, $address['postal_code'] ?? null]))), $countryLabel, $address['phone'] ?? null] as $part)
                    @if (filled($part))<div>{{ $part }}</div>@endif
                @endforeach
            </address>
        </section>
        @if ($method || $tracking || !empty($origin))
            <section>
                <h2>{{ __('Delivery') }}</h2>
                @if (!empty($origin))<p>{{ __('Dispatch from') }} · {{ $origin }}</p>@endif
                @if ($method)<p>{{ $method }}</p>@endif
                @if ($tracking)<p>{{ __('Tracking number') }} {{ $tracking }}</p>@endif
            </section>
        @endif
    </div>
    @foreach($orderFields as $field)<p><strong>{{ $field['label'] }}</strong> {{ $field['value'] }}</p>@endforeach
    <table aria-label="{{ __('Items to pack') }}">
        <thead><tr><th scope="col">{{ __('Item') }}</th><th scope="col">{{ __('SKU') }}</th><th scope="col">{{ __('Quantity') }}</th></tr></thead>
        <tbody>
        @foreach ($lines as $line)
            <tr><td>{{ $line['title'] }}@foreach(\Sellissimo\Core\CommerceFields::visible($line['line_fields'] ?? []) as $field)<small>{{ $field['label'] }} · {{ $field['value'] }}</small>@endforeach @foreach($line['options'] ?? [] as $option)<small>{{ $option['label'] }} · {{ $option['value'] }}</small>@endforeach</td><td>{{ $line['sku'] }}</td><td>@if(isset($line['ordered'])){{ __(':qty to pack · :ordered ordered', ['qty' => $line['qty'], 'ordered' => $line['ordered']]) }}@else{{ $line['qty'] }}@endif</td></tr>
        @endforeach
        </tbody>
    </table>
    @if($lines->isEmpty())<p>{{ __('No items remaining to pack.') }}</p>@endif
@if(!empty($instructions))<p><strong>{{ __('Delivery instructions') }}</strong><br>{{ $instructions }}</p>@endif
</main>
