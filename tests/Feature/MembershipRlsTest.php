<?php

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use TenantBase\Tenancy\Tenancy;

beforeEach(function (): void {
    $this->tenancy = app(Tenancy::class);
    $this->acme = Tenant::factory()->create(['slug' => 'acme']);
    $this->globex = Tenant::factory()->create(['slug' => 'globex']);
});

afterEach(function (): void {
    $this->tenancy->deactivate();
});

it('shows only the active tenant rows', function (): void {
    $this->tenancy->runAs($this->acme, function (): void {
        Membership::factory()->create(['tenant_id' => $this->acme->id]);
    });
    $this->tenancy->runAs($this->globex, function (): void {
        Membership::factory()->create(['tenant_id' => $this->globex->id]);
    });

    $this->tenancy->activate($this->acme);

    expect(Membership::count())->toBe(1)
        ->and(Membership::first()->tenant_id)->toBe($this->acme->id);
});

it('shows no rows without a context', function (): void {
    $this->tenancy->runAs($this->acme, function (): void {
        Membership::factory()->create(['tenant_id' => $this->acme->id]);
    });

    expect(Membership::count())->toBe(0);
});

it('rejects an insert whose tenant disagrees with the context', function (): void {
    $user = User::factory()->create();
    $this->tenancy->activate($this->acme);

    expect(fn () => Membership::factory()->create([
        'tenant_id' => $this->globex->id,
        'user_id' => $user->id,
    ]))->toThrow(QueryException::class);
});

it('carries the policy and forced row level security', function (): void {
    $forced = DB::selectOne(
        'select relrowsecurity, relforcerowsecurity from pg_class where relname = ?',
        ['memberships']
    );
    $policy = DB::selectOne('select polname from pg_policy where polname = ?', ['tenant_isolation']);

    expect($forced->relrowsecurity)->toBeTrue()
        ->and($forced->relforcerowsecurity)->toBeTrue()
        ->and($policy)->not->toBeNull();
});
