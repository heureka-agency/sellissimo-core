<?php

use Illuminate\Support\Facades\Route;
use Sellissimo\Core\Http\CurrencySettingsController;
use Sellissimo\Core\Http\DraftController;

Route::get('settings/currency', [CurrencySettingsController::class, 'show'])->name('settings.currency');
Route::post('settings/currency', [CurrencySettingsController::class, 'save'])->name('settings.currency.save');
Route::post('drafts/{draft}/currency', [DraftController::class, 'rebuild'])->whereUuid('draft')->name('drafts.currency');
