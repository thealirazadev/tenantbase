<?php

namespace App\Models;

use App\Exceptions\LastOwnerException;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use TenantBase\Tenancy\BelongsToTenant;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $role
 */
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * tenant_id is deliberately absent: it comes from the active context, never
     * from a request payload.
     */
    protected $fillable = ['user_id', 'role'];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public const ROLES = ['owner', 'admin', 'member'];

    /** The user's role in the active workspace, or null when they are not a member. */
    public static function roleOf(User $user): ?string
    {
        return static::query()->where('user_id', $user->getKey())->value('role');
    }

    public function changeRole(string $role): void
    {
        if ($this->isOwner() && $role !== 'owner') {
            $this->assertAnotherOwner();
        }

        $this->role = $role;
        $this->save();
    }

    public function removeFromWorkspace(): void
    {
        if ($this->isOwner()) {
            $this->assertAnotherOwner();
        }

        $this->delete();
    }

    /**
     * Locks this workspace's owner rows for the rest of the request transaction,
     * so two owners demoting each other at once cannot both pass the check.
     */
    private function assertAnotherOwner(): void
    {
        // Lock every owner row (this one included) in id order, so concurrent
        // changes queue on the same locks instead of deadlocking.
        $ownerIds = static::query()
            ->where('role', 'owner')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        if ($ownerIds->reject(fn (int $id): bool => $id === $this->getKey())->isEmpty()) {
            throw new LastOwnerException;
        }
    }
}
