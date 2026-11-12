<?php

use App\Models\Membership;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use TenantBase\Tenancy\Tenancy;

beforeEach(function (): void {
    $this->tenancy = app(Tenancy::class);
    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    $this->users = [];
    $this->memberships = [];

    foreach (['owner', 'admin', 'member'] as $role) {
        $user = User::factory()->create();
        $this->users[$role] = $user;
        $this->memberships[$role] = $this->tenancy->runAs($this->tenant, fn () => Membership::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => $role,
        ]));
    }

    $this->project = $this->tenancy->runAs($this->tenant, fn () => Project::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]));
});

afterEach(function (): void {
    $this->tenancy->deactivate();
});

function roleOfMember(Membership $membership): string
{
    return app(Tenancy::class)->runAs(
        Tenant::query()->findOrFail($membership->tenant_id),
        fn () => Membership::query()->findOrFail($membership->id)->role,
    );
}

it('lets every role create and edit projects', function (string $role): void {
    $this->actingAs($this->users[$role])
        ->post('http://acme.tenantbase.test/projects', ['name' => "By {$role}"])
        ->assertRedirect('/projects');

    $this->actingAs($this->users[$role])
        ->put("http://acme.tenantbase.test/projects/{$this->project->id}", ['name' => "Edited by {$role}"])
        ->assertRedirect('/projects');
})->with(['owner', 'admin', 'member']);

it('lets owners and admins delete projects', function (string $role): void {
    $this->actingAs($this->users[$role])
        ->delete("http://acme.tenantbase.test/projects/{$this->project->id}")
        ->assertRedirect('/projects');
})->with(['owner', 'admin']);

it('hides delete from a member and refuses a forged delete', function (): void {
    $this->actingAs($this->users['member'])
        ->get('http://acme.tenantbase.test/projects')
        ->assertOk()->assertDontSee('btn-danger', false);

    $this->actingAs($this->users['member'])
        ->delete("http://acme.tenantbase.test/projects/{$this->project->id}")
        ->assertForbidden();

    expect($this->tenancy->runAs($this->tenant, fn () => Project::query()->count()))->toBe(1);
});

it('only lets owners change roles or remove members', function (string $role): void {
    $target = $this->memberships['member'];

    $this->actingAs($this->users[$role])
        ->put("http://acme.tenantbase.test/members/{$target->id}", ['role' => 'admin'])
        ->assertForbidden();
    $this->actingAs($this->users[$role])
        ->delete("http://acme.tenantbase.test/members/{$target->id}")
        ->assertForbidden();

    expect(roleOfMember($target))->toBe('member');
})->with(['admin', 'member']);

it('lets an owner promote and remove members', function (): void {
    $target = $this->memberships['member'];

    $this->actingAs($this->users['owner'])
        ->put("http://acme.tenantbase.test/members/{$target->id}", ['role' => 'admin'])
        ->assertRedirect('/members')->assertSessionHas('status', 'Role updated.');
    expect(roleOfMember($target))->toBe('admin');

    $this->actingAs($this->users['owner'])
        ->delete("http://acme.tenantbase.test/members/{$target->id}")
        ->assertRedirect('/members');
    expect($this->tenancy->runAs($this->tenant, fn () => Membership::query()->whereKey($target->id)->exists()))->toBeFalse();
});

it('rejects an unknown role', function (): void {
    $this->actingAs($this->users['owner'])
        ->put("http://acme.tenantbase.test/members/{$this->memberships['member']->id}", ['role' => 'superuser'])
        ->assertSessionHasErrors('role');
});

it('blocks demoting or removing the last owner with a friendly error', function (): void {
    $owner = $this->memberships['owner'];

    $this->actingAs($this->users['owner'])
        ->put("http://acme.tenantbase.test/members/{$owner->id}", ['role' => 'member'])
        ->assertRedirect('/members')
        ->assertSessionHas('error', 'A workspace needs at least one owner. Make someone else an owner first.');
    expect(roleOfMember($owner))->toBe('owner');

    $this->actingAs($this->users['owner'])
        ->delete("http://acme.tenantbase.test/members/{$owner->id}")
        ->assertSessionHas('error');
    expect(roleOfMember($owner))->toBe('owner');
});

it('lets an owner step down once another owner exists', function (): void {
    $owner = $this->memberships['owner'];
    $this->tenancy->runAs($this->tenant, fn () => Membership::query()->findOrFail($this->memberships['admin']->id)->changeRole('owner'));

    $this->actingAs($this->users['owner'])
        ->put("http://acme.tenantbase.test/members/{$owner->id}", ['role' => 'admin'])
        ->assertSessionHas('status', 'Role updated.');
    expect(roleOfMember($owner))->toBe('admin');
});

it('cannot change a member of another workspace', function (): void {
    $other = Tenant::factory()->create(['slug' => 'globex']);
    $foreign = $this->tenancy->runAs($other, fn () => Membership::factory()->create([
        'tenant_id' => $other->id,
        'user_id' => User::factory()->create()->id,
    ]));

    $this->actingAs($this->users['owner'])
        ->put("http://acme.tenantbase.test/members/{$foreign->id}", ['role' => 'owner'])
        ->assertNotFound();
});
