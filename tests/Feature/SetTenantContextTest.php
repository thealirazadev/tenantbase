<?php

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use TenantBase\Tenancy\Tenancy;

beforeEach(function (): void {
    $this->tenancy = app(Tenancy::class);
    $this->user = User::factory()->create();
    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
});

afterEach(function (): void {
    $this->tenancy->deactivate();
});

function probeRoute(string $uri, Closure $action): void
{
    Route::middleware(['web', 'tenant.resolve', 'tenant.context'])
        ->domain('{tenant_slug}.tenantbase.test')
        ->get($uri, $action);
}

it('opens a transaction and sets the tenant setting for the request', function (): void {
    probeRoute('/context-probe', fn () => response()->json([
        'setting' => DB::selectOne("select current_setting('app.tenant_id', true) as value")->value,
        'transaction_level' => DB::transactionLevel(),
        'tenancy_id' => app(Tenancy::class)->id(),
    ]));

    $this->actingAs($this->user)
        ->getJson('http://acme.tenantbase.test/context-probe')
        ->assertOk()
        ->assertJson([
            'setting' => (string) $this->tenant->id,
            'tenancy_id' => $this->tenant->id,
        ])
        ->assertJsonPath('transaction_level', fn (int $level): bool => $level > 0);
});

it('clears the context after the response is built', function (): void {
    probeRoute('/context-probe', fn () => response()->noContent());

    $this->actingAs($this->user)->get('http://acme.tenantbase.test/context-probe');

    expect($this->tenancy->id())->toBeNull()
        ->and(DB::selectOne("select current_setting('app.tenant_id', true) as value")->value)->toBe('');
});

it('rolls the request transaction back when the action throws', function (): void {
    probeRoute('/context-probe', function (): void {
        Membership::factory()->create(['tenant_id' => app(Tenancy::class)->id()]);

        throw new RuntimeException('boom');
    });

    $this->actingAs($this->user)
        ->get('http://acme.tenantbase.test/context-probe')
        ->assertStatus(500);

    $survivors = $this->tenancy->runAs($this->tenant, fn (): int => Membership::count());

    expect($survivors)->toBe(0);
});
