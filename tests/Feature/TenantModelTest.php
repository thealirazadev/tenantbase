<?php

use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;

it('creates a tenant on the default plan', function (): void {
    $tenant = Tenant::factory()->create(['name' => 'Acme Inc', 'slug' => 'acme']);

    expect($tenant->plan)->toBe('free')
        ->and($tenant->isSuspended())->toBeFalse()
        ->and($tenant->url())->toBe('http://acme.tenantbase.test');
});

it('reports suspension', function (): void {
    expect(Tenant::factory()->suspended()->create()->isSuspended())->toBeTrue();
});

it('rejects a duplicate slug at the database level', function (): void {
    Tenant::factory()->create(['slug' => 'acme']);

    expect(fn () => Tenant::factory()->create(['slug' => 'acme']))
        ->toThrow(UniqueConstraintViolationException::class);
});
