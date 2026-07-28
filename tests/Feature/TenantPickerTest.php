<?php

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use TenantBase\Tenancy\Tenancy;

beforeEach(function (): void {
    $this->tenancy = app(Tenancy::class);
    $this->user = User::factory()->create();
});

afterEach(function (): void {
    $this->tenancy->deactivate();
});

it('shows an empty state when the user has no workspace', function (): void {
    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertSee('You are not a member of any workspace yet.');
});

it('lists every workspace the user belongs to', function (): void {
    $acme = Tenant::factory()->create(['slug' => 'acme', 'name' => 'Acme Inc']);
    $globex = Tenant::factory()->create(['slug' => 'globex', 'name' => 'Globex']);
    $other = Tenant::factory()->create(['slug' => 'initech', 'name' => 'Initech']);

    foreach ([$acme, $globex] as $tenant) {
        $this->tenancy->runAs($tenant, function () use ($tenant): void {
            Membership::factory()->owner()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $this->user->id,
            ]);
        });
    }

    $this->tenancy->runAs($other, function () use ($other): void {
        Membership::factory()->create(['tenant_id' => $other->id]);
    });

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertSee('Acme Inc')
        ->assertSee('Globex')
        ->assertDontSee('Initech');
});

it('logs the sanctioned bypass with its reason', function (): void {
    Log::spy();

    $this->actingAs($this->user)->get('/')->assertOk();

    Log::shouldHaveReceived('info')
        ->with('tenancy.bypass', Mockery::on(
            fn (array $context): bool => $context['reason'] === 'tenant picker: list memberships for user'
        ))
        ->once();
});
