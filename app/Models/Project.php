<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use TenantBase\Tenancy\BelongsToTenant;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $description
 * @property int|null $created_by
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * tenant_id and created_by are deliberately absent: one comes from the
     * active context, the other from the authenticated user.
     */
    protected $fillable = ['name', 'description'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
