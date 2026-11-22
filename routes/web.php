<?php

use Illuminate\Support\Facades\Route;
use Sellissimo\Core\Http\PrivateResponse;
use Sellissimo\Core\Http\ShopController;
use Sellissimo\Core\Http\StorefrontAvailability;

Route::middleware([StorefrontAvailability::class, PrivateResponse::class, 'throttle:120,1'])->group(function () {

    $prefix = trim(config('sellissimo.prefix'), '/');
    $conflict = collect(Route::getRoutes()->getRoutes())->contains(fn ($route) => $route->uri() === $prefix || str_starts_with($route->uri(), $prefix.'/'));
    if (! $conflict && preg_match('~^[a-z0-9_-]+(?:/[a-z0-9_-]+)*$~D', $prefix)) {
        Route::prefix($prefix)->group(function () {
            Route::get('/', [ShopController::class, 'catalog'])->name('sellissimo.shop');
            Route::get('products/{sku}', [ShopController::class, 'product'])->where('sku', '[A-Za-z0-9][A-Za-z0-9_.-]{0,99}')->name('sellissimo.product');
            Route::post('products/{sku}/cart', [ShopController::class, 'addProduct'])->where('sku', '[A-Za-z0-9][A-Za-z0-9_.-]{0,99}')->name('sellissimo.product.add');
            Route::get('checkout/{step?}', [ShopController::class, 'checkoutPage'])->where('step', '[a-z][a-z0-9_]*')->name('sellissimo.checkout.page');
            Route::get('cart', [ShopController::class, 'showCart'])->name('sellissimo.cart.page');
            Route::post('cart', [ShopController::class, 'create'])->name('sellissimo.create');
            Route::get('cart/{cart}/items/{line}/edit', [ShopController::class, 'editItem'])->whereUuid('cart')->where('line', '[A-Za-z0-9:_.-]{1,100}')->name('sellissimo.item.edit');
            Route::post('cart/{cart}/currency', [ShopController::class, 'rebuild'])->whereUuid('cart')->name('sellissimo.cart.currency');
            Route::post('cart/{cart}/items', [ShopController::class, 'item'])->whereUuid('cart')->name('sellissimo.item');
            Route::post('cart/{cart}/quote', [ShopController::class, 'quote'])->whereUuid('cart')->name('sellissimo.quote');
            Route::post('cart/{cart}/checkout-codes/{step}', [ShopController::class, 'checkoutCodes'])->whereUuid('cart')->where('step', '[a-z][a-z0-9_]*')->name('sellissimo.checkout.codes');
            Route::post('cart/{cart}/billing-fields', [ShopController::class, 'digitalBilling'])->whereUuid('cart')->name('sellissimo.billing.fields');
            Route::post('cart/{cart}/checkout', [ShopController::class, 'checkout'])->whereUuid('cart')->name('sellissimo.checkout');
        });
    }
});
