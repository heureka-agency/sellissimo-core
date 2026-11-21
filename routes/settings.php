<?php

use Illuminate\Support\Facades\Route;
use Sellissimo\Core\Http\PrivateResponse;
use Sellissimo\Core\Http\StoreSettingsController;

Route::prefix('sellissimo/settings')->middleware(PrivateResponse::class)->controller(StoreSettingsController::class)->name('sellissimo.settings')->group(function () {
    Route::get('/', 'index');
    Route::get('/sections/{section}', 'section')->name('.section');
    Route::patch('/sections/{section}', 'save')->name('.save');
    Route::post('/checks/{check}', 'check')->whereIn('check', ['email', 'storage'])->middleware('throttle:6,1')->name('.check');
    Route::post('/tax-preview', 'taxPreview')->name('.tax-preview');
});
