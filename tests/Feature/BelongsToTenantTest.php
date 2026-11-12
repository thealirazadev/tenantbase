<?php

use App\Models\Project;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use TenantBase\Tenancy\Exceptions\MissingTenantContext;
use TenantBase\Tenancy\Tenancy;

beforeEach(function (): void {
    $this->tenancy = app(Tenancy::class);
    $this->acme = Tenant::factory()->create(['slug' => 'acme']);
    $this->globex = Tenant::factory()->create(['slug' => 'globex']);
});

afterEach(function (): void {
    $this->tenancy->deactivate();
});

it('scopes queries to the active tenant', function (): void {
    $this->tenancy->runAs($this->acme, fn () => Project::factory()->create(['tenant_id' => $this->acme->id, 'name' => 'A']));
    $this->tenancy->runAs($this->globex, fn () => Project::factory()->create(['tenant_id' => $this->globex->id, 'name' => 'G']));

    expect($this->tenancy->runAs($this->acme, fn () => Project::query()->pluck('name')->all()))->toBe(['A']);
});

it('fills tenant_id from the context and never from input', function (): void {
    $project = $this->tenancy->runAs($this->acme, function (): Project {
        $project = new Project(['name' => 'Filled', 'tenant_id' => $this->globex->id]);
        $project->save();

        return $project;
    });

    expect($project->tenant_id)->toBe($this->acme->id);
});

it('fails loudly on a query without tenant context', function (): void {
    expect(fn () => Project::query()->count())->toThrow(MissingTenantContext::class);
    expect(fn () => (new Project(['name' => 'Orphan']))->save())->toThrow(MissingTenantContext::class);
});

it('lets the database reject an explicit tenant_id from another tenant', function (): void {
    $this->tenancy->runAs($this->acme, function (): void {
        $project = new Project(['name' => 'Forged']);
        $project->tenant_id = $this->globex->id;

        expect(fn () => $project->save())->toThrow(QueryException::class, 'row-level security');
    });
});
