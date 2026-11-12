<?php

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use TenantBase\Tenancy\Tenancy;

beforeEach(function (): void {
    $this->tenancy = app(Tenancy::class);
    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    $this->owner = User::factory()->create(['name' => 'Olive Owner', 'email' => 'olive@example.test']);
    $this->member = User::factory()->create(['name' => 'Max Member', 'email' => 'max@example.test']);

    $this->tenancy->runAs($this->tenant, function (): void {
        Membership::factory()->owner()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id]);
        Membership::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->member->id]);
    });

    $outsider = User::factory()->create(['name' => 'Gus Globex']);
    $globex = Tenant::factory()->create(['slug' => 'globex']);
    $this->tenancy->runAs($globex, fn () => Membership::factory()->owner()->create([
        'tenant_id' => $globex->id,
        'user_id' => $outsider->id,
    ]));
});

afterEach(function (): void {
    $this->tenancy->deactivate();
});

it('lists this workspace members with name, email, role and joined date', function (): void {
    $this->actingAs($this->member)->get('http://acme.tenantbase.test/members')
        ->assertOk()
        ->assertSee(['Olive Owner', 'olive@example.test', 'Max Member', 'max@example.test', now()->toDateString()])
        ->assertSee('badge-owner', false)
        ->assertDontSee('Gus Globex');
});

it('shows role controls only to an owner and marks the sole owner', function (): void {
    $this->actingAs($this->owner)->get('http://acme.tenantbase.test/members')
        ->assertOk()->assertSee('Change role')->assertSee('sole owner');

    $this->actingAs($this->member)->get('http://acme.tenantbase.test/members')
        ->assertOk()->assertDontSee('Change role')->assertDontSee('Remove');
});
