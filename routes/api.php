<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\TokenController;
use Illuminate\Support\Facades\Route;

$authenticatedRoutes = static function (): void {
    Route::get('me', [AuthController::class, 'show'])->name('me');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
    Route::put('password', [PasswordController::class, 'change'])->name('password.update');
    Route::get('tokens', [TokenController::class, 'index'])->name('tokens.index');
    Route::delete('tokens/{token}', [TokenController::class, 'destroy'])->whereNumber('token')->name('tokens.destroy');
};

Route::prefix('v1')->name('api.v1.')->middleware('auth.no-store')->group(function () use ($authenticatedRoutes): void {
    Route::prefix('auth')->name('auth.')->group(function () use ($authenticatedRoutes): void {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:registration')->name('register');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
        Route::post('forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:password-recovery')->name('password.forgot');
        Route::post('reset-password', [PasswordController::class, 'reset'])->middleware('throttle:password-recovery')->name('password.reset');

        Route::middleware(['auth:sanctum', 'role:rider', 'abilities:rider:access', 'throttle:authenticated'])
            ->group($authenticatedRoutes);
    });

    Route::prefix('admin/auth')->name('admin.auth.')->group(function () use ($authenticatedRoutes): void {
        Route::post('login', [AuthController::class, 'adminLogin'])->middleware('throttle:login')->name('login');

        Route::middleware(['auth:sanctum', 'role:admin', 'abilities:admin:access', 'throttle:authenticated'])
            ->group($authenticatedRoutes);
    });
});
