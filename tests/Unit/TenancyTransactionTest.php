<?php

use App\Models\Tenant;
use TenantBase\Tenancy\Exceptions\MissingTenantContext;
use TenantBase\Tenancy\Tenancy;

it('refuses to activate a tenant outside a transaction', function (): void {
    $tenant = new Tenant;
    $tenant->id = 1;

    expect(fn () => app(Tenancy::class)->activate($tenant))
        ->toThrow(MissingTenantContext::class);
});
