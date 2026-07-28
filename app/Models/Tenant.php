<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use TenantBase\Tenancy\Tenancy;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $plan
 * @property Carbon|null $suspended_at
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected $fillable = ['name', 'slug', 'plan'];

    /**
     * Signup and tenant:create share this one path. Either the tenant and its
     * owner membership both exist afterwards, or neither does: a tenant
     * without an owner would be unreachable and unremovable from the UI.
     */
    public static function provision(string $name, string $slug, string $plan, User $owner): self
    {
        $tenancy = app(Tenancy::class);

        $tenant = DB::transaction(function () use ($name, $slug, $plan, $owner, $tenancy): self {
            $tenant = self::create(['name' => $name, 'slug' => $slug, 'plan' => $plan]);

            return $tenancy->runAs($tenant, function () use ($tenant, $owner): self {
                $membership = new Membership(['user_id' => $owner->getKey(), 'role' => 'owner']);
                $membership->tenant_id = $tenant->getKey();
                $membership->save();

                return $tenant;
            });
        });

        Log::info('tenant.provisioned', [
            'tenant_id' => $tenant->id,
            'slug' => $tenant->slug,
            'plan' => $tenant->plan,
            'owner_id' => $owner->getKey(),
        ]);

        return $tenant;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function url(): string
    {
        return sprintf('%s://%s.%s', request()->getScheme(), $this->slug, config('tenantbase.domain'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['suspended_at' => 'datetime'];
    }
}
