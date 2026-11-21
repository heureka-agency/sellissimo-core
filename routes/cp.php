<?php

use Illuminate\Support\Facades\Route;
use Sellissimo\Core\Http\AdminController;
use Sellissimo\Core\Http\CatalogImportController;
use Sellissimo\Core\Http\CustomersController;
use Sellissimo\Core\Http\DraftController;
use Sellissimo\Core\Http\OperationsController;
use Sellissimo\Core\Http\OrderDocumentsController;
use Sellissimo\Core\Http\PricingSettingsController;
use Sellissimo\Core\Http\PrivateResponse;
use Sellissimo\Core\Http\ReturnsController;
use Sellissimo\Core\StaffAccess;

Route::post('sellissimo/private-downloads', \Sellissimo\Core\Http\PrivateProductFileController::class)
    ->middleware(PrivateResponse::class)->name('sellissimo.private-downloads');

Route::prefix('sellissimo')->middleware([StaffAccess::class, PrivateResponse::class])->name('sellissimo.')->group(function () {
    require __DIR__.'/settings-currency.php';
    Route::get('/', [AdminController::class, 'index'])->name('dashboard');
    Route::post('/orders/{order}/contact', [\Sellissimo\Core\Http\OrderEditsController::class, 'contact'])->whereUuid('order')->name('contact');
    Route::post('/orders/{order}/fields', [\Sellissimo\Core\Http\OrderEditsController::class, 'fields'])->whereUuid('order')->name('fields');
    Route::post('/orders/views', [AdminController::class, 'saveView'])->name('views.save');
    Route::delete('/orders/views/{view}', [AdminController::class, 'deleteView'])->whereUuid('view')->name('views.delete');
    Route::get('/orders/packing-slips', [OrderDocumentsController::class, 'packingSlips'])->name('packing-slips');
    Route::post('/tax-preferences', [PricingSettingsController::class, 'preferences'])->name('tax-preferences');
    Route::post('/orders/{order}/labels', [AdminController::class, 'labels'])->whereUuid('order')->name('labels');
    Route::post('/orders/{order}/delivery-address', [AdminController::class, 'address'])->whereUuid('order')->name('address');
    Route::get('vouchers', [\Sellissimo\Core\Http\GiftVoucherController::class, 'index'])->name('vouchers');
    Route::post('vouchers', [\Sellissimo\Core\Http\GiftVoucherController::class, 'issue'])->name('vouchers.issue');
    Route::get('/discount-accounts', [PricingSettingsController::class, 'accounts'])->name('discount-accounts');
    Route::get('/settings/{resource}', [PricingSettingsController::class, 'index'])->whereIn('resource', ['discounts', 'tax-classes', 'tax-zones'])->name('rules');
    Route::post('/settings/{resource}', [PricingSettingsController::class, 'save'])->whereIn('resource', ['discounts', 'tax-classes', 'tax-zones'])->name('rules.save');
    Route::get('/webhooks', [\Sellissimo\Core\Http\WebhookController::class, 'index'])->name('webhooks');
    Route::post('/webhooks', [\Sellissimo\Core\Http\WebhookController::class, 'save'])->name('webhooks.save');
    Route::post('/webhooks/{delivery}/retry', [\Sellissimo\Core\Http\WebhookController::class, 'retry'])->whereUuid('delivery')->name('webhooks.retry');
    Route::post('/orders/{order}/amend', [\Sellissimo\Core\Http\AmendmentController::class, 'change'])->whereUuid('order')->name('amend');
    Route::get('/orders/{order}/amend/products', \Sellissimo\Core\Http\ProductPickerController::class)->whereUuid('order')->name('amend.products');
    Route::post('/orders/{order}/amend/delivery', [\Sellissimo\Core\Http\AmendmentController::class, 'change'])->whereUuid('order')->name('amend.delivery');
    Route::post('/orders/{order}/amend/artwork', [\Sellissimo\Core\Http\AmendmentController::class, 'artwork'])->whereUuid('order')->name('amend.artwork');
    Route::post('/orders/{order}/amend/preview', [\Sellissimo\Core\Http\AmendmentController::class, 'change'])->whereUuid('order')->name('amend.preview');
    Route::post('/amendments/{amendment}/email', [\Sellissimo\Core\Http\AmendmentController::class, 'email'])->whereUuid('amendment')->name('amend.email');
    Route::post('/amendments/{amendment}/retry', [\Sellissimo\Core\Http\AmendmentController::class, 'retry'])->whereUuid('amendment')->name('amend.retry');
    Route::get('/recovery', [\Sellissimo\Core\Http\CartRecoveryController::class, 'index'])->name('recovery');
    Route::post('/recovery/{cart}/remind', [\Sellissimo\Core\Http\CartRecoveryController::class, 'remind'])->whereUuid('cart')->name('recovery.remind');
    Route::post('/drafts/{scope}/artwork', [\Sellissimo\Core\Http\OrderArtworkController::class, 'upload'])->whereUuid('scope')->name('drafts.upload');
    Route::get('/orders/{order}/artwork/{file}', [\Sellissimo\Core\Http\OrderArtworkController::class, 'download'])->whereUuid(['order', 'file'])->name('artwork');
    Route::get('/drafts', [DraftController::class, 'index'])->name('drafts');
    Route::get('/drafts/products', \Sellissimo\Core\Http\ProductPickerController::class)->name('drafts.products');
    Route::get('/drafts/customers', [DraftController::class, 'customers'])->name('drafts.customers');
    Route::get('/drafts/new', [DraftController::class, 'edit'])->name('drafts.new');
    Route::get('/drafts/{draft}', [DraftController::class, 'edit'])->whereUuid('draft')->name('drafts.edit');
    Route::post('/drafts', [DraftController::class, 'save'])->name('drafts.save');
    Route::post('/drafts/quote', [DraftController::class, 'save'])->name('drafts.quote');
    Route::post('/drafts/{draft}/cancel', [DraftController::class, 'cancel'])->whereUuid('draft')->name('drafts.cancel');
    Route::get('catalog/import', [CatalogImportController::class, 'index'])->name('import');
    Route::get('catalog/export', [CatalogImportController::class, 'export'])->name('import.export');
    Route::get('catalog/import/template', [CatalogImportController::class, 'template'])->name('import.template');
    Route::post('catalog/import/preview', [CatalogImportController::class, 'preview'])->name('import.preview');
    Route::post('catalog/import/apply', [CatalogImportController::class, 'apply'])->name('import.apply');
    Route::get('inventory/locations', [OperationsController::class, 'inventory'])->name('locations');
    Route::post('inventory/locations', [OperationsController::class, 'createLocation'])->name('location.create');
    Route::post('inventory/count', [OperationsController::class, 'countLocation'])->name('location.count');
    Route::post('inventory/transfers', [OperationsController::class, 'transfer'])->name('transfer');
    Route::get('customers', [CustomersController::class, 'index'])->name('customers');
    Route::get('customers/{type}/{id}', [CustomersController::class, 'show'])->whereIn('type', ['account', 'guest'])->name('customer');
    Route::post('customers/guest/{id}/invite', [CustomersController::class, 'invite'])->whereUuid('id')->middleware('throttle:6,10')->name('customer.invite');
    Route::post('customers/{type}/{id}/addresses', [CustomersController::class, 'merchantSave'])->whereIn('type', ['account', 'guest'])->name('customer.address');
    Route::get('reports/sales', [OperationsController::class, 'sales'])->name('sales');
    Route::get('returns', [ReturnsController::class, 'index'])->name('returns');
    Route::post('returns/policy', [ReturnsController::class, 'policy'])->name('returns.policy');
    Route::post('returns/{id}/refund', [ReturnsController::class, 'refund'])->whereUuid('id')->name('returns.refund');
    Route::post('returns/{id}/balance', [ReturnsController::class, 'balance'])->whereUuid('id')->name('returns.balance');
    Route::post('returns/{id}', [ReturnsController::class, 'update'])->whereUuid('id')->name('returns.action');
    Route::get('/orders', [AdminController::class, 'index'])->name('orders');
    Route::get('/orders/export', [OrderDocumentsController::class, 'export'])->name('export');
    Route::get('/inventory', [AdminController::class, 'inventory'])->name('inventory');
    Route::post('/stock', [AdminController::class, 'stock'])->name('stock');
    Route::get('/orders/{order}', [AdminController::class, 'order'])->whereUuid('order')->name('order');
    Route::get('/orders/{order}/packing-slip', [OrderDocumentsController::class, 'packingSlip'])->whereUuid('order')->name('packing-slip');
    Route::post('/orders/{order}/notes', [AdminController::class, 'note'])->whereUuid('order')->name('note');
    Route::post('/orders/{order}/delivered', [AdminController::class, 'delivered'])->whereUuid('order')->name('delivered');
    Route::post('/orders/{order}/reassign', [AdminController::class, 'reassign'])->whereUuid('order')->name('reassign');
    Route::post('/orders/{order}/ship', [AdminController::class, 'ship'])->whereUuid('order')->name('ship');
    Route::post('/orders/{order}/returns', [AdminController::class, 'receive'])->whereUuid('order')->name('receive');
    Route::post('/orders/{order}/shipment-email', [AdminController::class, 'shipmentEmail'])->whereUuid('order')->name('shipment-email');
    Route::post('/orders/{order}/settle-delivery', [AdminController::class, 'settleDelivery'])->whereUuid('order')->name('settle-delivery');
    Route::post('/orders/{order}/cancel', [AdminController::class, 'cancel'])->whereUuid('order')->name('cancel');
    Route::post('/orders/{order}/cancel/refund', [AdminController::class, 'cancel'])->whereUuid('order')->name('cancel.retry');
    Route::post('/orders/{order}/payments/{gateway}/reconcile', [AdminController::class, 'reconcilePayments'])->whereUuid('order')->name('payments.reconcile');
    Route::post('/orders/{order}/refund', [AdminController::class, 'refund'])->whereUuid('order')->name('refund');
    Route::post('/orders/{order}/download-access', [AdminController::class, 'downloadAccess'])->whereUuid('order')->name('download-access');
    Route::post('/orders/{order}/receipt', [AdminController::class, 'resend'])->whereUuid('order')->name('resend');
});

require __DIR__.'/cp-catalog23.php';

Route::match(['get', 'post'], 'sellissimo/features', [\Sellissimo\Core\Http\StoreFeaturesController::class, 'settings'])
    ->middleware(['can:manage sellissimo settings', PrivateResponse::class])->name('sellissimo.features');
Route::get('sellissimo/stock-demand', [\Sellissimo\Core\Http\StoreFeaturesController::class, 'demand'])
    ->middleware(['can:manage sellissimo inventory', PrivateResponse::class])->name('sellissimo.stock-demand');

require __DIR__.'/settings.php';
require __DIR__.'/settings-payments.php';
