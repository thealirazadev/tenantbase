<?php

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use TenantBase\Tenancy\Tenancy;

beforeEach(function (): void {
    $this->tenancy = app(Tenancy::class);
    $this->tenant = Tenant::factory()->create(['slug' => 'acme', 'name' => 'Acme Inc']);
    $this->member = User::factory()->create();
    $this->outsider = User::factory()->create();

    $this->tenancy->runAs($this->tenant, function (): void {
        Membership::factory()->owner()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->member->id,
        ]);
    });
});

afterEach(function (): void {
    $this->tenancy->deactivate();
});

it('lets a member through and exposes their role', function (): void {
    $this->actingAs($this->member)
        ->get('http://acme.tenantbase.test/')
        ->assertOk()
        ->assertSee('owner');
});

it('refuses a signed in non-member', function (): void {
    $this->actingAs($this->outsider)
        ->get('http://acme.tenantbase.test/')
        ->assertForbidden();
});

it('refuses a member of another workspace', function (): void {
    $other = Tenant::factory()->create(['slug' => 'globex']);
    $this->tenancy->runAs($other, function (): void {
        Membership::factory()->create([
            'tenant_id' => app(Tenancy::class)->id(),
            'user_id' => $this->outsider->id,
        ]);
    });

    $this->actingAs($this->outsider)
        ->get('http://acme.tenantbase.test/')
        ->assertForbidden();
});

it('returns the forbidden envelope to json callers', function (): void {
    $this->actingAs($this->outsider)
        ->getJson('http://acme.tenantbase.test/')
        ->assertForbidden()
        ->assertExactJson([
            'error' => ['code' => 'forbidden', 'message' => 'You are not a member of this workspace.'],
        ]);
});
