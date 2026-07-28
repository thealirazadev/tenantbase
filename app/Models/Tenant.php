<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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
