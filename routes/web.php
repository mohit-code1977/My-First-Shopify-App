<?php

use App\Http\Controllers\ShopifyAuthController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\ShopifyProductController;
use App\Http\Controllers\ZohoAuthController;
use App\Http\Controllers\ZohoSyncController;
use Illuminate\Http\Request;



Route::get('/', function (Illuminate\Http\Request $request) {
    return redirect()->route('zoho.sync', $request->query());
});

// Route::get('/', function () {
//     return Inertia::render('Welcome', [
//         'canLogin' => Route::has('login'),
//         'canRegister' => Route::has('register'),
//         'laravelVersion' => Application::VERSION,
//         'phpVersion' => PHP_VERSION,
//     ]);
// });

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');


Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__ . '/auth.php';
Route::get('/install', [ShopifyAuthController::class, 'install']);
Route::get('/auth/callback', [ShopifyAuthController::class, 'callback']);
Route::get('/shopify/products', [ShopifyProductController::class, 'products']);

Route::get('/zoho/connect', [ZohoAuthController::class, 'connect']);
Route::get('/zoho/callback', [ZohoAuthController::class, 'callback']);

Route::get('/zoho/sync', [ZohoSyncController::class, 'index'])
    ->name('zoho.sync');

Route::get('/zoho/sync/history', [ZohoSyncController::class, 'history'])
    ->name('zoho.sync.history');

Route::post('/zoho/sync/{variant}', [ZohoSyncController::class, 'syncVariant'])
    ->name('zoho.sync.variant');

Route::post('/zoho/sync-all', [ZohoSyncController::class, 'syncAll'])
    ->name('zoho.sync.all');

Route::get('/zoho/settings', [ZohoSyncController::class, 'settings'])
    ->name('zoho.settings');

Route::post('/zoho/settings/disconnect', [ZohoSyncController::class, 'disconnect'])
    ->name('zoho.settings.disconnect');
