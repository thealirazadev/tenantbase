<?php

namespace App\Models;

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
}
