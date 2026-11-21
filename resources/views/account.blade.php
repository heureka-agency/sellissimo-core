@extends('sellissimo::layout')
@section('title',__('sellissimo::shop.my_orders'))
@section('surface', 'ss-document')
@section('content')
<header class="ss-page-head">
    <h1>{{ __('sellissimo::shop.my_orders_and_downloads') }}</h1>
    <div class="ss-actions">
        <a class="ss-button" href="{{ route('sellissimo.addresses', ['site' => $site]) }}">{{ __('sellissimo::shop.manage_address_book') }}</a>
        <form method="post" action="{{ route('sellissimo.account.logout') }}">@csrf
            <input type="hidden" name="site" value="{{ $site }}">
            <button class="ss-button--quiet">{{ __('sellissimo::shop.sign_out') }}</button>
        </form>
    </div>
</header>
<div class="order-history">
@forelse($orders as $order)
    <article class="ss-panel">
        <header class="row">
            <h2><a href="{{ route('sellissimo.receipt', $order->id) }}">{{ __('sellissimo::shop.order') }} {{ $order->number }}</a></h2>
            <span class="ss-badge">{{ __('sellissimo::shop.'.$order->status) }}</span>
        </header>
        <p class="ss-muted"><time datetime="{{ \Illuminate\Support\Carbon::parse($order->created_at)->toDateString() }}">{{ \Illuminate\Support\Carbon::parse($order->created_at)->isoFormat('LL') }}</time></p>
        <dl class="ss-money-detail">
            <div><dt>{{ __('sellissimo::shop.original_total') }}</dt><dd>{{ \Sellissimo\Core\Money::display($order->payment_summary['original'], $order->currency) }}</dd></div>
            @if($order->payment_summary['additional'])
            <div><dt>{{ __('sellissimo::shop.additional_charges') }}</dt><dd>{{ \Sellissimo\Core\Money::display($order->payment_summary['additional'], $order->currency) }}</dd></div>
            @endif
            @if($order->payment_summary['refunds'])
            <div><dt>{{ __('sellissimo::shop.refund') }}</dt><dd>{{ \Sellissimo\Core\Money::display(array_sum(array_column($order->payment_summary['refunds'], 'amount')), $order->currency) }}</dd></div>
            @endif
            <div><dt>{{ __('sellissimo::shop.net_paid') }}</dt><dd>{{ \Sellissimo\Core\Money::display($order->payment_summary['net_paid'], $order->currency) }}</dd></div>
        </dl>
        @foreach($order->downloads as $download)
        <p><a class="ss-button" href="{{ $download['url'] }}">{{ __('sellissimo::shop.download') }} {{ $download['title'] }}</a></p>
        @endforeach
    </article>
@empty
    <p class="ss-empty">{{ __('sellissimo::shop.no_orders_yet') }}</p>
@endforelse
</div>
{{ $orders->links('sellissimo::pagination') }}
<section class="ss-panel">
    <h2>{{ __('sellissimo::shop.add_a_guest_purchase') }}</h2>
    <p>{{ __('sellissimo::shop.claim_by_number') }}</p>
    @if(session('sellissimo.notice'))<p role="status">{{ session('sellissimo.notice') }}</p>@endif
    <form class="ss-form" action="{{ route('sellissimo.claim.request') }}" method="post">@csrf
        <input type="hidden" name="site" value="{{ $site }}">
        <label>{{ __('sellissimo::shop.order_number') }}
            @if($errors->has('order')) @section('sellissimo.error.order', 'claim-order-number') @endif
            <input id="claim-order-number" name="order" value="{{ old('order', request()->query('claim')) }}" required @if($errors->has('order')) aria-invalid="true" aria-describedby="claim-number-error" @endif>
            @error('order')<small id="claim-number-error" class="field-error">{{ $message }}</small>@enderror
        </label>
        <button>{{ __('sellissimo::shop.request_confirmation') }}</button>
    </form>
</section>
@endsection
