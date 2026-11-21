<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __("sellissimo::mail.$type.subject") }}</title>
</head>
<body style="margin:0;background:#f5f6f8;color:#20242a;font:16px/1.55 Arial,Helvetica,sans-serif">
    <table role="presentation" style="width:100%;border-collapse:collapse"><tr><td align="center" style="padding:24px 12px">
        <table role="presentation" style="width:100%;max-width:580px;border:1px solid #dce0e5;border-radius:10px;background:#ffffff;text-align:left"><tr>
            <td style="padding:24px 28px;border-bottom:1px solid #e4e7eb;font-size:18px;font-weight:bold">{{ $brand }}</td>
        </tr><tr><td style="padding:24px 28px">
            <h1 style="margin:0 0 12px;font-size:26px;line-height:1.25">{{ __("sellissimo::mail.$type.subject") }}</h1>
            <p style="margin:0 0 16px">{{ __("sellissimo::mail.$type.message") }}</p>
            @isset($order)
                <p style="margin:0 0 12px"><strong>{{ __('sellissimo::shop.order_number') }}</strong> {{ \Sellissimo\Core\OrderNumber::display($order) }}</p>
            @endisset
            @isset($orderTotal)
                <p style="margin:0 0 16px"><strong>{{ __('sellissimo::shop.total') }}</strong> {{ $orderTotal }}</p>
            @endisset
            @isset($formattedBalance)
                <p style="margin:0 0 16px"><strong>{{ __('sellissimo::shop.amount_due') }}</strong> {{ $formattedBalance }}</p>
            @endisset
            @yield('details')
            <p style="margin:24px 0 8px"><a href="{{ $url }}" style="display:inline-block;padding:11px 18px;border-radius:6px;background:#252a32;color:#fff;font-weight:bold;text-decoration:none">{{ __("sellissimo::mail.$type.action") }}</a></p>
        </td></tr>
        @if(array_filter(array_diff_key($identity ?? [], ['trading_name' => true])))
            <tr><td style="padding:16px 28px;border-top:1px solid #e4e7eb;font-size:14px;color:#4b5563">
                @include('sellissimo::store-identity', ['identity' => array_diff_key($identity, ['trading_name' => true])])
            </td></tr>
        @endif
        </table>
    </td></tr></table>
</body></html>
