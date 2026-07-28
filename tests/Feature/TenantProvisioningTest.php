<?php

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use TenantBase\Tenancy\Tenancy;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

afterEach(function (): void {
    app(Tenancy::class)->deactivate();
});

it('provisions a tenant with its owner membership and redirects to the subdomain', function (): void {
    $this->actingAs($this->user)
        ->post('/tenants', ['name' => 'Acme Inc', 'slug' => 'Acme'])
        ->assertRedirect('http://acme.tenantbase.test');

    $tenant = Tenant::firstWhere('slug', 'acme');
    expect($tenant)->not->toBeNull()
        ->and($tenant->plan)->toBe('free');

    $membership = app(Tenancy::class)->runAs($tenant, fn () => Membership::first());
    expect($membership->user_id)->toBe($this->user->id)
        ->and($membership->role)->toBe('owner');
});

it('rejects a duplicate slug with a field error', function (): void {
    Tenant::factory()->create(['slug' => 'acme']);

    $this->actingAs($this->user)
        ->post('/tenants', ['name' => 'Acme Inc', 'slug' => 'acme'])
        ->assertSessionHasErrors('slug');

    expect(Tenant::where('slug', 'acme')->count())->toBe(1);
});

it('rejects reserved slugs', function (string $slug): void {
    $this->actingAs($this->user)
        ->post('/tenants', ['name' => 'Acme Inc', 'slug' => $slug])
        ->assertSessionHasErrors('slug');
})->with(['www', 'api', 'admin']);

it('rejects malformed slugs', function (string $slug): void {
    $this->actingAs($this->user)
        ->post('/tenants', ['name' => 'Acme Inc', 'slug' => $slug])
        ->assertSessionHasErrors('slug');
})->with(['ab', '-acme', 'acme.inc', 'ACME_INC', str_repeat('a', 33)]);

it('leaves nothing behind when the owner membership cannot be written', function (): void {
    $ghost = User::factory()->make();
    $ghost->id = 987654;

    expect(fn () => Tenant::provision('Ghost Inc', 'ghost', 'free', $ghost))
        ->toThrow(QueryException::class);

    expect(Tenant::where('slug', 'ghost')->exists())->toBeFalse();
});

it('keeps workspace creation behind authentication', function (): void {
    $this->post('/tenants', ['name' => 'Acme Inc', 'slug' => 'acme'])
        ->assertRedirect('http://tenantbase.test/login');

    expect(Tenant::count())->toBe(0);
});
