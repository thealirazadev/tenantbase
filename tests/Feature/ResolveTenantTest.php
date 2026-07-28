<?php

use App\Models\Tenant;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('resolves a tenant from the subdomain', function (): void {
    Tenant::factory()->create(['slug' => 'acme', 'name' => 'Acme Inc']);

    $this->actingAs($this->user)
        ->get('http://acme.tenantbase.test/')
        ->assertOk()
        ->assertSee('Acme Inc');
});

it('returns 404 for an unknown subdomain', function (): void {
    $this->actingAs($this->user)
        ->get('http://nosuchtenant.tenantbase.test/')
        ->assertNotFound();
});

it('returns 403 for a suspended tenant', function (): void {
    Tenant::factory()->suspended()->create(['slug' => 'acme']);

    $this->actingAs($this->user)
        ->get('http://acme.tenantbase.test/')
        ->assertForbidden();
});

it('returns the error envelope to json callers', function (): void {
    $this->actingAs($this->user)
        ->getJson('http://nosuchtenant.tenantbase.test/')
        ->assertNotFound()
        ->assertExactJson([
            'error' => ['code' => 'unknown_tenant', 'message' => 'That workspace does not exist.'],
        ]);
});

it('sends guests to the central login screen', function (): void {
    Tenant::factory()->create(['slug' => 'acme']);

    $this->get('http://acme.tenantbase.test/')
        ->assertRedirect('http://tenantbase.test/login');
});
