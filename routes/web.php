<?php

use App\Http\Controllers\ShopifyAuthController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\ShopifyProductController;
use App\Http\Controllers\ZohoAuthController;
use App\Http\Controllers\ZohoSyncController;
use App\Http\Controllers\ShopifyWebhookController;



Route::get('/', function (Illuminate\Http\Request $request) {
    return redirect()->route('zoho.dashboard', $request->query());
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('/zoho/dashboard', [ZohoSyncController::class, 'dashboard'])
    ->name('zoho.dashboard');


Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__ . '/auth.php';
Route::get('/install', [ShopifyAuthController::class, 'install']);
Route::get('/auth/install', [ShopifyAuthController::class, 'install']);
Route::get('/auth/callback', [ShopifyAuthController::class, 'callback']);

Route::get('/zoho/callback', [ZohoAuthController::class, 'callback']);

Route::get('/zoho/products', [ZohoSyncController::class, 'products'])
    ->name('zoho.products');

Route::get('/zoho/sync', [ZohoSyncController::class, 'sync'])
    ->name('zoho.sync');

Route::get('/zoho/orders', [ZohoSyncController::class, 'orders'])
    ->name('zoho.orders');

Route::get('/zoho/refunds', [ZohoSyncController::class, 'refunds'])
    ->name('zoho.refunds');

Route::get('/zoho/customers', [ZohoSyncController::class, 'customers'])
    ->name('zoho.customers');

Route::get('/zoho/sync/history', [ZohoSyncController::class, 'history'])
    ->name('zoho.sync.history');

Route::get('/zoho/settings', [ZohoSyncController::class, 'settings'])
    ->name('zoho.settings');

Route::middleware(['shopify.auth'])->group(function () {
    Route::post('/api/zoho/connect', [ZohoAuthController::class, 'initiate']);
    Route::get('/api/zoho/sync', [ZohoSyncController::class, 'data']);
    Route::get('/api/zoho/orders', [ZohoSyncController::class, 'ordersData']);
    Route::get('/api/zoho/refunds', [ZohoSyncController::class, 'refundsData']);
    Route::get('/api/zoho/refunds/{id}', [ZohoSyncController::class, 'refundDetail']);
    Route::get('/api/zoho/customers', [ZohoSyncController::class, 'customersData']);
    Route::get('/api/zoho/sync/history', [ZohoSyncController::class, 'historyData']);
    Route::get('/api/zoho/settings', [ZohoSyncController::class, 'settingsData']);
    Route::get('/api/zoho/dashboard', [ZohoSyncController::class, 'dashboardData']);

    Route::get('/shopify/products', [ShopifyProductController::class, 'products']);

    Route::post('/zoho/sync', [ZohoSyncController::class, 'syncVariant'])
        ->name('zoho.sync.variant');

    Route::post('/zoho/sync-all', [ZohoSyncController::class, 'syncAll'])
        ->name('zoho.sync.all');

    Route::post('/zoho/sync-inventory', [ZohoSyncController::class, 'syncZohoInventory'])
        ->name('zoho.sync.inventory');

    Route::post('/zoho/sync-customer', [ZohoSyncController::class, 'syncCustomer'])
        ->name('zoho.sync.customer');

    Route::post('/zoho/sync-order', [ZohoSyncController::class, 'syncOrder'])
        ->name('zoho.sync.order');

    Route::post('/zoho/cancel-order', [ZohoSyncController::class, 'cancelOrder'])
        ->name('zoho.cancel.order');

    Route::post('/zoho/sync-invoice', [ZohoSyncController::class, 'syncInvoice'])
        ->name('zoho.sync.invoice');

    Route::post('/zoho/sync-payment', [ZohoSyncController::class, 'syncPayment'])
        ->name('zoho.sync.payment');

    Route::post('/zoho/sync-refund', [ZohoSyncController::class, 'syncRefund'])
        ->name('zoho.sync.refund');

    Route::post('/zoho/bulk-sync-orders', [ZohoSyncController::class, 'bulkSyncOrders'])
        ->name('zoho.bulk-sync-orders');

    Route::post('/zoho/bulk-sync-customers', [ZohoSyncController::class, 'bulkSyncCustomers'])
        ->name('zoho.bulk-sync-customers');

    Route::post('/zoho/bulk-sync-refunds', [ZohoSyncController::class, 'bulkSyncRefunds'])
        ->name('zoho.bulk-sync-refunds');



    Route::post('/zoho/settings/tax', [ZohoSyncController::class, 'saveTaxSettings'])
        ->name('zoho.settings.tax');

    Route::post('/zoho/settings/disconnect', [ZohoSyncController::class, 'disconnect'])
        ->name('zoho.settings.disconnect');
});

use App\Http\Controllers\ZohoWebhookController;

Route::post('/webhooks/products', [ShopifyWebhookController::class, 'productsUpdate'])
    ->name('shopify.webhooks.products');

Route::post('/webhooks/products/delete', [ShopifyWebhookController::class, 'productsDelete'])
    ->name('shopify.webhooks.products_delete');

Route::post('/webhooks/inventory-levels', [ShopifyWebhookController::class, 'inventoryLevelsUpdate'])
    ->name('shopify.webhooks.inventory_levels');

Route::post('/webhooks/customers', [ShopifyWebhookController::class, 'customersUpdate'])
    ->name('shopify.webhooks.customers');

Route::post('/webhooks/orders', [ShopifyWebhookController::class, 'ordersUpdate'])
    ->name('shopify.webhooks.orders');

Route::post('/webhooks/orders/cancelled', [ShopifyWebhookController::class, 'ordersCancelled'])
    ->name('shopify.webhooks.orders_cancelled');

Route::post('/webhooks/order-transactions', [ShopifyWebhookController::class, 'orderTransactionsCreate'])
    ->name('shopify.webhooks.order_transactions');

Route::post('/webhooks/refunds', [ShopifyWebhookController::class, 'refundsCreate'])
    ->name('shopify.webhooks.refunds');

Route::post('/webhooks/zoho/inventory', [ZohoWebhookController::class, 'inventoryUpdate'])
    ->name('zoho.webhooks.inventory');



