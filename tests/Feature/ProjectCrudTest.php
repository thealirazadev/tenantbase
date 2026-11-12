<?php

use App\Models\Membership;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use TenantBase\Tenancy\Tenancy;

beforeEach(function (): void {
    $this->tenancy = app(Tenancy::class);
    $this->acme = Tenant::factory()->create(['slug' => 'acme']);
    $this->globex = Tenant::factory()->create(['slug' => 'globex']);
    $this->user = User::factory()->create(['name' => 'Ada']);

    foreach ([$this->acme, $this->globex] as $tenant) {
        $this->tenancy->runAs($tenant, fn () => Membership::factory()->owner()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $this->user->id,
        ]));
    }
});

afterEach(function (): void {
    $this->tenancy->deactivate();
});

function projectIn(Tenant $tenant, array $attributes = []): Project
{
    return app(Tenancy::class)->runAs($tenant, fn () => Project::factory()->create(
        ['tenant_id' => $tenant->id] + $attributes,
    ));
}

it('lists only the current workspace projects', function (): void {
    projectIn($this->acme, ['name' => 'Acme Rocket']);
    projectIn($this->globex, ['name' => 'Globex Laser']);

    $this->actingAs($this->user)->get('http://acme.tenantbase.test/projects')
        ->assertOk()->assertSee('Acme Rocket')->assertDontSee('Globex Laser');

    $this->actingAs($this->user)->get('http://globex.tenantbase.test/projects')
        ->assertOk()->assertSee('Globex Laser')->assertDontSee('Acme Rocket');
});

it('shows an empty state with the next action', function (): void {
    $this->actingAs($this->user)->get('http://acme.tenantbase.test/projects')
        ->assertOk()->assertSee('No projects yet');
});

it('creates a project in the current workspace and records the creator', function (): void {
    $this->actingAs($this->user)
        ->post('http://acme.tenantbase.test/projects', ['name' => 'Apollo', 'tenant_id' => $this->globex->id])
        ->assertRedirect('/projects');

    $project = $this->tenancy->runAs($this->acme, fn () => Project::query()->sole());
    expect($project->name)->toBe('Apollo')
        ->and($project->tenant_id)->toBe($this->acme->id)
        ->and($project->created_by)->toBe($this->user->id);
});

it('rejects a duplicate name in one workspace but allows it across workspaces', function (): void {
    projectIn($this->acme, ['name' => 'Apollo']);

    $this->actingAs($this->user)
        ->from('http://acme.tenantbase.test/projects/create')
        ->post('http://acme.tenantbase.test/projects', ['name' => 'Apollo'])
        ->assertSessionHasErrors(['name' => 'A project with that name already exists in this workspace.']);

    $this->actingAs($this->user)
        ->post('http://globex.tenantbase.test/projects', ['name' => 'Apollo'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/projects');
});

it('edits and deletes a project', function (): void {
    $project = projectIn($this->acme, ['name' => 'Old']);

    $this->actingAs($this->user)->get("http://acme.tenantbase.test/projects/{$project->id}/edit")
        ->assertOk()->assertSee('Old');

    $this->actingAs($this->user)
        ->put("http://acme.tenantbase.test/projects/{$project->id}", ['name' => 'New'])
        ->assertRedirect('/projects');
    expect($this->tenancy->runAs($this->acme, fn () => Project::query()->sole()->name))->toBe('New');

    $this->actingAs($this->user)
        ->delete("http://acme.tenantbase.test/projects/{$project->id}")
        ->assertRedirect('/projects');
    expect($this->tenancy->runAs($this->acme, fn () => Project::query()->count()))->toBe(0);
});
