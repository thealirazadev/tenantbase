<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\TenantSignupController;
use Illuminate\Support\Facades\Route;

/*
 * Central domain only. Tenant routes are bound to {slug}.APP_DOMAIN, so a
 * workspace subdomain can never serve the account screens.
 */
Route::domain(config('tenantbase.domain'))->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::get('/register', [RegisterController::class, 'create'])->name('register');
        Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:auth');

        Route::get('/login', [LoginController::class, 'create'])->name('login');
        Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:auth');
    });

    Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

    Route::middleware('auth')->group(function (): void {
        Route::view('/', 'home')->name('home');

        Route::get('/tenants/create', [TenantSignupController::class, 'create'])->name('tenants.create');
        Route::post('/tenants', [TenantSignupController::class, 'store'])->name('tenants.store');
    });
});
