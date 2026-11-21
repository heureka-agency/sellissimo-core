@if(!empty($identity))
<address>
    @if(!empty($identity['trading_name']))<strong>{{ $identity['trading_name'] }}</strong><br>@endif
    @if(!empty($identity['address_line'])){{ $identity['address_line'] }}<br>@endif
    @php($locality = implode(', ', array_filter([$identity['address_city'] ?? null, $identity['address_region'] ?? null, $identity['address_postcode'] ?? null, \Sellissimo\Core\Http\StoreCountries::name($identity['address_country'] ?? null)])))
    @if($locality){{ $locality }}<br>@endif
    @if(!empty($identity['contact_email']))<a href="mailto:{{ $identity['contact_email'] }}">{{ $identity['contact_email'] }}</a>@endif
</address>
@endif
