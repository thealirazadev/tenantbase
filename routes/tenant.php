<?php

use Illuminate\Support\Facades\Route;

/*
 * Tenant subdomain: {slug}.APP_DOMAIN. The group is bound to the wildcard
 * domain in bootstrap/app.php; ResolveTenant turns the label into a tenant.
 */
Route::middleware(['auth', 'tenant.resolve', 'tenant.context'])->group(function (): void {
    Route::view('/', 'tenant.dashboard')->name('tenant.dashboard');
});
