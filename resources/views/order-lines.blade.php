@foreach($receipt['lines'] as $line)
<div class="row order-summary-line">
    <div>{{ $line['qty'] }} × {{ $line['title'] }}
        @foreach($line['exchanges'] ?? [] as $exchange)
        <small>{{ $exchange['qty'] }} × {{ __('sellissimo::shop.exchange_adjustment', ['sku'=>$exchange['sku']]) }} · {{ \Sellissimo\Core\Money::display($exchange['difference'], $receipt['currency']) }}</small>
        @endforeach
        @foreach($line['options'] ?? [] as $option)
        <small>{{ $option['label'] }} · {{ $option['value'] }}</small>
        @endforeach
        @foreach(\Sellissimo\Core\CommerceFields::visible($line['line_fields'] ?? []) as $field)
        <small>{{ $field['label'] }} · {{ $field['value'] }}</small>
        @endforeach
    </div>
    <span>{{ \Sellissimo\Core\Money::display($line['subtotal'] ?? ($line['total'] + ($line['discount'] ?? 0) - ($line['tax'] ?? 0)), $receipt['currency']) }}</span>
</div>
@endforeach
