<?php

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use TenantBase\Tenancy\Exceptions\MissingTenantContext;
use TenantBase\Tenancy\Tenancy;

function currentTenantSetting(): string
{
    return (string) DB::selectOne("select current_setting('app.tenant_id', true) as value")->value;
}

function currentBypassSetting(): string
{
    return (string) DB::selectOne("select current_setting('app.tenancy_bypass', true) as value")->value;
}

beforeEach(function (): void {
    $this->tenancy = app(Tenancy::class);
});

afterEach(function (): void {
    $this->tenancy->deactivate();
});

it('sets the database setting when a tenant is activated', function (): void {
    $tenant = Tenant::factory()->create();

    $this->tenancy->activate($tenant);

    expect(currentTenantSetting())->toBe((string) $tenant->id)
        ->and($this->tenancy->id())->toBe($tenant->id)
        ->and($this->tenancy->hasContext())->toBeTrue();
});

it('clears both halves of the context on deactivate', function (): void {
    $this->tenancy->activate(Tenant::factory()->create());
    $this->tenancy->deactivate();

    expect(currentTenantSetting())->toBe('')
        ->and($this->tenancy->id())->toBeNull();
});

it('throws when tenant data is reached without context', function (): void {
    expect(fn () => $this->tenancy->assertContext('memberships'))
        ->toThrow(MissingTenantContext::class);
});

it('does not throw while bypassing', function (): void {
    $this->tenancy->withoutTenancy('test: assert during bypass', function (): void {
        $this->tenancy->assertContext('memberships');
    });
})->throwsNoExceptions();

it('restores the previous tenant after runAs', function (): void {
    $first = Tenant::factory()->create();
    $second = Tenant::factory()->create();

    $this->tenancy->activate($first);

    $seen = $this->tenancy->runAs($second, fn (): string => currentTenantSetting());

    expect($seen)->toBe((string) $second->id)
        ->and($this->tenancy->id())->toBe($first->id)
        ->and(currentTenantSetting())->toBe((string) $first->id);
});

it('sets and restores the bypass setting and logs the reason', function (): void {
    Log::spy();

    $inside = $this->tenancy->withoutTenancy('test: sanctioned read', fn (): string => currentBypassSetting());

    expect($inside)->toBe('1')
        ->and(currentBypassSetting())->toBe('')
        ->and($this->tenancy->bypassing())->toBeFalse();

    Log::shouldHaveReceived('info')->with('tenancy.bypass', Mockery::type('array'))->once();
});

it('refuses a bypass without a reason', function (): void {
    expect(fn () => $this->tenancy->withoutTenancy('  ', fn () => null))
        ->toThrow(InvalidArgumentException::class);
});

it('restores the bypass setting when the callback throws', function (): void {
    expect(fn () => $this->tenancy->withoutTenancy('test: failing read', function (): void {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect(currentBypassSetting())->toBe('');
});
