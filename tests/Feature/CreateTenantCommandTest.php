<?php

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use TenantBase\Tenancy\Tenancy;

beforeEach(function (): void {
    $this->tenancy = app(Tenancy::class);
    $this->owner = User::factory()->create(['email' => 'ada@example.com']);
});

afterEach(function (): void {
    $this->tenancy->deactivate();
});

it('provisions a tenant identically to signup', function (): void {
    $this->artisan('tenant:create', [
        'name' => 'Acme Inc',
        '--slug' => 'acme',
        '--owner-email' => 'ada@example.com',
    ])->assertSuccessful();

    $tenant = Tenant::firstWhere('slug', 'acme');
    expect($tenant->name)->toBe('Acme Inc')
        ->and($tenant->plan)->toBe('free');

    $membership = $this->tenancy->runAs($tenant, fn () => Membership::first());
    expect($membership->user_id)->toBe($this->owner->id)
        ->and($membership->role)->toBe('owner');
});

it('slugifies the name when no slug is given', function (): void {
    $this->artisan('tenant:create', ['name' => 'Acme Inc', '--owner-email' => 'ada@example.com'])
        ->assertSuccessful();

    expect(Tenant::firstWhere('slug', 'acme-inc'))->not->toBeNull();
});

it('refuses an unknown plan and saves nothing', function (): void {
    $this->artisan('tenant:create', [
        'name' => 'Acme Inc',
        '--slug' => 'acme',
        '--plan' => 'enterprise',
        '--owner-email' => 'ada@example.com',
    ])->assertFailed();

    expect(Tenant::count())->toBe(0);
});

it('refuses a reserved or duplicate slug', function (string $slug): void {
    Tenant::factory()->create(['slug' => 'taken']);

    $this->artisan('tenant:create', [
        'name' => 'Acme Inc',
        '--slug' => $slug,
        '--owner-email' => 'ada@example.com',
    ])->assertFailed();

    expect(Tenant::where('slug', $slug)->count())->toBeLessThan(2);
})->with(['www', 'taken']);

it('refuses an owner email with no account', function (): void {
    $this->artisan('tenant:create', [
        'name' => 'Acme Inc',
        '--slug' => 'acme',
        '--owner-email' => 'nobody@example.com',
    ])->assertFailed();

    expect(Tenant::count())->toBe(0);
});
