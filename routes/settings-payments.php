<?php

use Illuminate\Support\Facades\Route;
use Sellissimo\Core\Http\PaymentSettingsController;
use Sellissimo\Core\Http\PrivateResponse;

Route::prefix('sellissimo/settings/payments')->name('sellissimo.settings.payments')->middleware(PrivateResponse::class)->group(function () {
    Route::get('/', [PaymentSettingsController::class, 'index']);
    Route::post('/', [PaymentSettingsController::class, 'save'])->name('.save');
    Route::post('{provider}/connection', [PaymentSettingsController::class, 'connection'])->whereIn('provider', ['stripe', 'mollie'])->middleware('throttle:20,1')->name('.connection');
});
