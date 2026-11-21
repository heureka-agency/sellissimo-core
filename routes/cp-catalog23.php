<?php

use Illuminate\Support\Facades\Route;
use Sellissimo\Core\Http\OrderDocumentsController;
use Sellissimo\Core\Http\PrivateResponse;

Route::prefix('sellissimo')->middleware(PrivateResponse::class)->name('sellissimo.')->group(function () {
    Route::get('warehouse/pick-list', [OrderDocumentsController::class, 'pickList'])->middleware('can:fulfill sellissimo orders')->name('pick-list');
});
