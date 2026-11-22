@php
    $storeSite = $site ?? request()->attributes->get('sellissimo.storefront_site');
    $siteRecord = $storeSite ? \Statamic\Facades\Site::all()->get($storeSite) : null;
    $storeName = $siteRecord
        ? \Sellissimo\Core\StoreSettings::forSection($storeSite, 'details', 'trading_name', $siteRecord->name())
        : config('app.name');
    $hasShop = $siteRecord && \Sellissimo\Core\OperationalSettings::storefront($storeSite);
    $features = $siteRecord ? \Sellissimo\Core\StoreFeatures::get($storeSite)['features'] : [];
    $siteParams = ['site' => $storeSite];
    $catalogParams = [...$siteParams, ...($filters ?? [])];
    $accountParams = $siteParams;
    if (!auth()->check() && request()->routeIs('sellissimo.checkout.page')) {
        $accountParams['next'] = 'checkout';
    } elseif (!auth()->check() && request()->routeIs('sellissimo.product')) {
        $accountParams = [...$catalogParams, 'next' => 'product', 'sku' => $product['sku']];
    }
    $accountRoot = request()->routeIs('sellissimo.history', 'sellissimo.account.access');
    $accountChild = auth()->check() && (request()->routeIs('sellissimo.addresses') || ($account_order ?? false));
    $cartChild = request()->routeIs('sellissimo.item.edit', 'sellissimo.checkout.page');
    $cartQuantity = 0;
    if ($hasShop) {
        $cartQuantity = request()->routeIs('sellissimo.shop', 'sellissimo.cart.page', 'sellissimo.checkout.page')
            ? array_sum(array_column($receipt['lines'] ?? [], 'qty'))
            : app(\Sellissimo\Core\AccountCarts::class)->storefrontQuantity(request(), $storeSite);
    }
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ $storeName }}</title>
    <style>@include('sellissimo::storefront-styles')</style>
    @stack('head')
</head>
<body class="ss-store">
<a class="ss-skip" href="#store-content">{{ __('sellissimo::shop.skip_to_content') }}</a>
<header class="ss-header">
    <div class="ss-frame ss-header-inner">
        @if($hasShop)
            <a class="ss-brand" href="{{ route('sellissimo.shop', $siteParams) }}">{{ $storeName }}</a>
        @else
            <strong class="ss-brand">{{ $storeName }}</strong>
        @endif
        @if($siteRecord)
        <nav class="ss-nav" aria-label="{{ __('sellissimo::shop.store_navigation') }}">
            @if($hasShop)
            <a href="{{ route('sellissimo.shop', $catalogParams) }}" data-catalog-context @if(request()->routeIs('sellissimo.shop', 'sellissimo.product')) aria-current="{{ request()->routeIs('sellissimo.shop') ? 'page' : 'true' }}" @endif>{{ __('sellissimo::shop.shop') }}</a>
            @endif
            @if(in_array('wishlists', $features, true))
            <a href="{{ route('sellissimo.wishes', $siteParams) }}" @if(request()->routeIs('sellissimo.wishes')) aria-current="page" @endif>{{ __('sellissimo::features.wishlist') }}</a>
            @endif
            <a href="{{ route(auth()->check() ? 'sellissimo.history' : 'sellissimo.account.access', $accountParams) }}" @if($accountRoot || $accountChild) aria-current="{{ $accountRoot ? 'page' : 'true' }}" @endif>{{ __(auth()->check() ? 'sellissimo::shop.account' : 'sellissimo::shop.sign_in') }}</a>
            @if($hasShop)
            <a data-cart-count class="ss-nav-cart" href="{{ route('sellissimo.cart.page', $catalogParams) }}" data-catalog-context @if(request()->routeIs('sellissimo.cart.page') || $cartChild) aria-current="{{ $cartChild ? 'true' : 'page' }}" @endif>{{ __('sellissimo::shop.cart_count', ['count' => $cartQuantity]) }}</a>
            @endif
        </nav>
        @endif
    </div>
</header>
<main id="store-content" tabindex="-1" class="ss-frame ss-main @yield('surface')">
    @if($errors->any())
    <div id="store-errors" class="ss-alert" role="alert" tabindex="-1" autofocus>
        <strong>{{ __('sellissimo::shop.review_fields') }}</strong>
        <ul>
            @foreach($errors->getMessages() as $field => $messages)
                @php($target = $__env->yieldContent('sellissimo.error.'.$field))
                @foreach(array_unique($messages) as $message)
                    <li>@if($target)<a href="#{{ $target }}">@hasSection('sellissimo.label.'.$field)@yield('sellissimo.label.'.$field) — @endif{{ $message }}</a>@else{{ $message }}@endif</li>
                @endforeach
            @endforeach
        </ul>
    </div>
    @endif
    @yield('content')
</main>
@if($siteRecord)
<footer class="ss-footer">
    <div class="ss-frame ss-footer-inner">
        <span>{{ $storeName }}</span>
        <a href="{{ route('sellissimo.receipt.recover', $siteParams) }}">{{ __('sellissimo::shop.find_receipt') }}</a>
        @if(in_array('vouchers', $features, true))
        <a href="{{ route('sellissimo.voucher.balance', $siteParams) }}">{{ __('Gift voucher balance') }}</a>
        @endif
    </div>
</footer>
@endif
</body>
</html>
