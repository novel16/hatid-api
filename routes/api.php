<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\RiderController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\BatchController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\DeliveryController;
use App\Http\Controllers\Api\V1\Directory\CustomerController;
use App\Http\Controllers\Api\V1\Directory\SellerController;
use App\Http\Controllers\Api\V1\MapController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ParcelController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SettingsController;
use Illuminate\Support\Facades\Route;

Route::pattern('customer', '[0-9]+');
Route::pattern('seller', '[0-9]+');
Route::pattern('address', '[0-9]+');
Route::pattern('batch', '[0-9]+');
Route::pattern('parcel', '[0-9]+');
Route::pattern('remittance', '[0-9]+');

$authenticatedRoutes = static function (): void {
    Route::get('me', [AuthController::class, 'show'])->name('me');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
    Route::put('password', [AuthController::class, 'changePassword'])->name('password.update');
    Route::get('tokens', [AuthController::class, 'tokens'])->name('tokens.index');
    Route::delete('tokens/{token}', [AuthController::class, 'revokeToken'])->whereNumber('token')->name('tokens.destroy');
};

Route::prefix('v1')->name('api.v1.')->middleware('auth.no-store')->group(function () use ($authenticatedRoutes): void {
    Route::prefix('auth')->name('auth.')->group(function () use ($authenticatedRoutes): void {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:registration')->name('register');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-recovery')->name('password.forgot');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-recovery')->name('password.reset');

        Route::middleware(['auth:sanctum', 'role:rider', 'abilities:rider:access', 'throttle:authenticated'])
            ->group($authenticatedRoutes);
    });

    // Protect private rider features with the same real Bearer/role/ability checks as auth.
    Route::middleware(['auth:sanctum', 'role:rider', 'abilities:rider:access', 'throttle:authenticated'])->group(function (): void {
        Route::patch('settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
        Route::patch('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->name('notifications.read');
        Route::get('dashboard', [ReportController::class, 'dashboard'])->name('dashboard');
        Route::get('reports/earnings', [ReportController::class, 'index'])->name('reports.earnings');
        Route::get('reports/earnings/export', [ReportController::class, 'export'])->name('reports.earnings.export');
        Route::get('collections', [CollectionController::class, 'index'])->name('collections.index');
        Route::get('collections/sellers', [CollectionController::class, 'sellers'])->name('collections.sellers');
        Route::get('remittances', [CollectionController::class, 'history'])->name('remittances.index');
        Route::post('remittances', [CollectionController::class, 'store'])->name('remittances.store');
        Route::post('remittances/recovery', [CollectionController::class, 'recover'])->name('remittances.recovery');
        Route::get('remittances/{remittance}', [CollectionController::class, 'show'])->name('remittances.show');
        Route::get('map', [MapController::class, 'show'])->name('map.show');
        Route::post('map/recommendations', [MapController::class, 'recommend'])->name('map.recommend');
        Route::get('map/tiles/{z}/{x}/{y}', [MapController::class, 'tile'])->whereNumber(['z', 'x', 'y'])
            ->withoutMiddleware('throttle:authenticated')->middleware('throttle:map-tiles')->name('map.tile');
        Route::apiResource('batches', BatchController::class)->only(['index', 'store', 'show']);
        Route::apiResource('parcels', ParcelController::class)->only(['store', 'show']);
        Route::post('parcels/recovery', [ParcelController::class, 'recover'])->name('parcels.recovery');
        Route::get('deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
        Route::get('deliveries/{parcel}', [DeliveryController::class, 'show'])->name('deliveries.show');
        Route::post('deliveries/{parcel}/outcomes', [DeliveryController::class, 'outcome'])->name('deliveries.outcomes');
        Route::post('deliveries/{parcel}/recovery', [DeliveryController::class, 'recoverOutcome'])->name('deliveries.recovery');
        Route::post('deliveries/{parcel}/location', [DeliveryController::class, 'location'])->name('deliveries.location');
        Route::apiResource('customers', CustomerController::class);
        Route::post('customers/{customer}/restore', [CustomerController::class, 'restore'])->name('customers.restore');
        Route::post('customers/{customer}/addresses', [CustomerController::class, 'storeAddress'])->name('customers.addresses.store');
        Route::patch('customers/{customer}/addresses/{address}', [CustomerController::class, 'updateAddress'])->name('customers.addresses.update');
        Route::delete('customers/{customer}/addresses/{address}', [CustomerController::class, 'destroyAddress'])->name('customers.addresses.destroy');
        Route::post('customers/{customer}/addresses/{address}/restore', [CustomerController::class, 'restoreAddress'])->name('customers.addresses.restore');
        Route::apiResource('sellers', SellerController::class);
        Route::post('sellers/{seller}/restore', [SellerController::class, 'restore'])->name('sellers.restore');
    });

    /** Keep the existing admin login path and share authorization across the workspace. */
    Route::prefix('admin')->name('admin.')->group(function () use ($authenticatedRoutes): void {
        Route::post('auth/login', [AuthController::class, 'adminLogin'])->middleware('throttle:login')->name('auth.login');

        /** Require the same administrator role and token scope for auth and directory reads. */
        Route::middleware(['auth:sanctum', 'role:admin', 'abilities:admin:access', 'throttle:authenticated'])
            ->group(function () use ($authenticatedRoutes): void {
                Route::prefix('auth')->name('auth.')->group($authenticatedRoutes);
                Route::get('riders', [RiderController::class, 'index'])->name('riders.index');
            });
    });
});
