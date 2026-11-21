<?php

use Illuminate\Support\Facades\Route;
use Sellissimo\Core\Http\PrivateResponse;
use Sellissimo\Core\Http\ShopController;

Route::name('sellissimo.form.')->middleware([PrivateResponse::class, 'throttle:120,1'])->group(function () {
    Route::post('cart/items/add', [ShopController::class, 'addFromForm'])->name('add');
    Route::post('cart', [ShopController::class, 'create'])->name('create');
    Route::post('cart/{cart}/currency', [ShopController::class, 'rebuild'])->whereUuid('cart')->name('currency');
    Route::post('cart/{cart}/items', [ShopController::class, 'item'])->whereUuid('cart')->name('item');
    Route::post('cart/{cart}/quote', [ShopController::class, 'quote'])->whereUuid('cart')->name('quote');
    Route::post('cart/{cart}/checkout', [ShopController::class, 'checkout'])->whereUuid('cart')->name('checkout');
});
