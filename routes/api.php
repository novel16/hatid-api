<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\BatchController;
use App\Http\Controllers\Api\V1\Directory\CustomerController;
use App\Http\Controllers\Api\V1\Directory\SellerController;
use App\Http\Controllers\Api\V1\SettingsController;
use Illuminate\Support\Facades\Route;

Route::pattern('customer', '[0-9]+');
Route::pattern('seller', '[0-9]+');
Route::pattern('address', '[0-9]+');
Route::pattern('batch', '[0-9]+');

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
        Route::apiResource('batches', BatchController::class)->only(['index', 'store', 'show']);
        Route::apiResource('customers', CustomerController::class);
        Route::post('customers/{customer}/restore', [CustomerController::class, 'restore'])->name('customers.restore');
        Route::post('customers/{customer}/addresses', [CustomerController::class, 'storeAddress'])->name('customers.addresses.store');
        Route::patch('customers/{customer}/addresses/{address}', [CustomerController::class, 'updateAddress'])->name('customers.addresses.update');
        Route::delete('customers/{customer}/addresses/{address}', [CustomerController::class, 'destroyAddress'])->name('customers.addresses.destroy');
        Route::post('customers/{customer}/addresses/{address}/restore', [CustomerController::class, 'restoreAddress'])->name('customers.addresses.restore');
        Route::apiResource('sellers', SellerController::class);
        Route::post('sellers/{seller}/restore', [SellerController::class, 'restore'])->name('sellers.restore');
    });

    Route::prefix('admin/auth')->name('admin.auth.')->group(function () use ($authenticatedRoutes): void {
        Route::post('login', [AuthController::class, 'adminLogin'])->middleware('throttle:login')->name('login');

        Route::middleware(['auth:sanctum', 'role:admin', 'abilities:admin:access', 'throttle:authenticated'])
            ->group($authenticatedRoutes);
    });
});
