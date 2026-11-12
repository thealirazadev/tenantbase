<?php

use App\Models\Membership;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use TenantBase\Tenancy\Tenancy;

beforeEach(function (): void {
    $this->tenancy = app(Tenancy::class);
    $this->acme = Tenant::factory()->create(['slug' => 'acme']);
    $this->globex = Tenant::factory()->create(['slug' => 'globex']);
    $this->user = User::factory()->create();

    $this->tenancy->runAs($this->acme, fn () => Membership::factory()->owner()->create([
        'tenant_id' => $this->acme->id,
        'user_id' => $this->user->id,
    ]));
    $this->foreign = $this->tenancy->runAs($this->globex, fn () => Project::factory()->create([
        'tenant_id' => $this->globex->id,
    ]));
});

afterEach(function (): void {
    $this->tenancy->deactivate();
});

it('returns 404 for another workspace project id in the URL', function (): void {
    $this->actingAs($this->user)
        ->get("http://acme.tenantbase.test/projects/{$this->foreign->id}/edit")
        ->assertNotFound();

    $this->actingAs($this->user)
        ->delete("http://acme.tenantbase.test/projects/{$this->foreign->id}")
        ->assertNotFound();

    expect($this->tenancy->runAs($this->globex, fn () => Project::query()->count()))->toBe(1);
});

it('refuses a foreign binding even with the scope and RLS bypassed', function (): void {
    $this->tenancy->runAs($this->acme, function (): void {
        $this->tenancy->withoutTenancy('binding net test', function (): void {
            // The bypass lets the row through both the scope and RLS; only the
            // binding check stands between a guessed id and the record.
            expect(Project::query()->find($this->foreign->id))->not->toBeNull();
            expect(fn () => (new Project)->resolveRouteBinding($this->foreign->id))
                ->toThrow(ModelNotFoundException::class);
        });
    });
});
