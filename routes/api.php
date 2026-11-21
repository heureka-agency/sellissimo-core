<?php

use Illuminate\Support\Facades\Route;
use Sellissimo\Core\Http\BuyerController;
use Sellissimo\Core\Http\DraftController;
use Sellissimo\Core\Http\OrderClaimController;
use Sellissimo\Core\Http\PrivateResponse;
use Sellissimo\Core\Http\ShopController;
use Statamic\Http\Middleware\AuthGuard;

Route::middleware(['web', AuthGuard::class, PrivateResponse::class, 'throttle:120,1'])->prefix('api/sellissimo/v1')->group(function () {
    Route::match(['get', 'post'], 'simulated-payments/{payment}', \Sellissimo\Core\Http\SimulatedPaymentController::class)
        ->middleware('signed')->whereUuid('payment')->name('sellissimo.simulator');
    Route::match(['get', 'post'], 'drafts/{draft}/pay', [DraftController::class, 'pay'])->middleware('signed')->whereUuid('draft')->name('sellissimo.draft.pay');
    Route::match(['get', 'post'], 'carts/{cart}/recover', [\Sellissimo\Core\Http\CartRecoveryController::class, 'recover'])->whereUuid('cart')->middleware('signed')->name('sellissimo.recover');
    Route::post('carts/{scope}/artwork', [\Sellissimo\Core\Http\OrderArtworkController::class, 'upload'])->whereUuid('scope')->name('sellissimo.artwork.upload');
    Route::match(['get','post'], 'amendments/{amendment}/pay', [\Sellissimo\Core\Http\AmendmentController::class, 'pay'])->middleware('signed')->whereUuid('amendment')->name('sellissimo.amend.pay');
    Route::match(['get', 'post'], 'wishlist', [\Sellissimo\Core\Http\ShopFeaturesController::class, 'wishes'])->name('sellissimo.wishes');
    Route::post('back-in-stock', [\Sellissimo\Core\Http\ShopFeaturesController::class, 'subscribe'])->middleware('throttle:5,10,sellissimo-stock')->name('sellissimo.stock.subscribe');
    foreach (['confirm', 'unsubscribe'] as $action) {
        Route::match(['get', 'post'], "back-in-stock/{subscription}/$action", [\Sellissimo\Core\Http\ShopFeaturesController::class, 'consent'])
            ->whereUuid('subscription')->middleware('signed')->name('sellissimo.stock.'.$action);
    }
    Route::match(['get', 'post'], 'vouchers/balance', [\Sellissimo\Core\Http\GiftVoucherController::class, 'balance'])
        ->middleware('throttle:12,1,sellissimo-voucher-balance')->name('sellissimo.voucher.balance');
    Route::get('session', [ShopController::class, 'session'])->name('sellissimo.api.session');
    Route::get('catalog/{sku}', [ShopController::class, 'product'])->where('sku', '[A-Za-z0-9][A-Za-z0-9_.-]{0,99}')->name('sellissimo.api.product');
    Route::get('catalog', [ShopController::class, 'catalog'])->name('sellissimo.api.catalog');
    Route::post('carts', [ShopController::class, 'create'])->name('sellissimo.api.create');
    Route::match(['get', 'post'], 'carts/{cart}/currency', [ShopController::class, 'rebuild'])->whereUuid('cart')->name('sellissimo.api.currency');
    Route::put('carts/{cart}/items', [ShopController::class, 'item'])->whereUuid('cart')->name('sellissimo.api.item');
    Route::post('carts/{cart}/quote', [ShopController::class, 'quote'])->whereUuid('cart')->name('sellissimo.api.quote');
    Route::post('carts/{cart}/checkout', [ShopController::class, 'checkout'])->whereUuid('cart')->name('sellissimo.api.checkout');
    Route::match(['get', 'post'], 'account/invitations/{invitation}', [OrderClaimController::class, 'invitation'])->whereUuid('invitation')->middleware('signed')->name('sellissimo.invitation');
    Route::post('account/claims', [OrderClaimController::class, 'request'])->middleware(['auth', 'throttle:4,10'])->name('sellissimo.claim.request');
    Route::match(['get', 'post'], 'account/claims/{order}', [OrderClaimController::class, 'confirm'])->middleware(['auth', 'signed'])->whereUuid('order')->name('sellissimo.claim');
    Route::match(['get','post'],'account/addresses',[\Sellissimo\Core\Http\CustomersController::class,'account'])->name('sellissimo.addresses');
    Route::match(['get','post'],'orders/{order}/returns',[\Sellissimo\Core\Http\ReturnsController::class,'buyer'])->whereUuid('order')->name('sellissimo.buyer.returns');
    Route::get('orders/{order}/returns/{return}/label', [\Sellissimo\Core\Http\ReturnsController::class, 'label'])->whereUuid(['order', 'return'])->name('sellissimo.buyer.return-label');
    Route::get('receipts/recover', [\Sellissimo\Core\Http\ReceiptRecoveryController::class, 'form'])->name('sellissimo.receipt.recover');
    Route::post('receipts/recover', [\Sellissimo\Core\Http\ReceiptRecoveryController::class, 'send'])->middleware('throttle:5,10,sellissimo-receipt-recovery')->name('sellissimo.receipt.recover.send');
    Route::get('account/orders', [BuyerController::class, 'history'])->name('sellissimo.history');
    Route::get('account/access', [BuyerController::class, 'accountAccess'])->name('sellissimo.account.access');
    Route::post('account/logout', [BuyerController::class, 'logout'])->middleware('auth')->name('sellissimo.account.logout');
    Route::post('carts/{cart}/leave-retry', [BuyerController::class, 'leaveRetry'])->whereUuid('cart')->name('sellissimo.payment.retry.leave');
    Route::post('orders/{order}/retry-payment', [BuyerController::class, 'retry'])->whereUuid('order')->name('sellissimo.payment.retry');
    Route::get('orders/{order}', [BuyerController::class, 'receipt'])->whereUuid('order')->name('sellissimo.receipt');
    Route::get('orders/{order}/downloads/{sku}', [BuyerController::class, 'download'])->whereUuid('order')->name('sellissimo.download');
});
