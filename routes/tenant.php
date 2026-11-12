<?php

use App\Http\Controllers\MembershipController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

/*
 * Tenant subdomain: {slug}.APP_DOMAIN. The group is bound to the wildcard
 * domain in bootstrap/app.php; ResolveTenant turns the label into a tenant.
 */
Route::middleware(['auth', 'tenant.resolve', 'tenant.context', 'tenant.member'])->group(function (): void {
    Route::view('/', 'tenant.dashboard')->name('tenant.dashboard');

    Route::resource('projects', ProjectController::class)->except('show');

    Route::put('/members/{membership}', [MembershipController::class, 'update']);
    Route::delete('/members/{membership}', [MembershipController::class, 'destroy']);
});
