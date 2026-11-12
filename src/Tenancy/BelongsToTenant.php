<?php

namespace TenantBase\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use TenantBase\Tenancy\Exceptions\MissingTenantContext;

/**
 * Every tenant-owned model uses this trait. tenant_id is filled from the active
 * context on create and is never fillable, so it can never arrive from request
 * input.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            // An explicit tenant_id is left alone: RLS rejects it if it
            // disagrees with the context, which is the behaviour we want.
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            $tenantId = app(Tenancy::class)->id();

            if ($tenantId === null) {
                throw MissingTenantContext::forQuery($model->getTable());
            }

            $model->setAttribute('tenant_id', $tenantId);
        });
    }

    /**
     * The third net: a bound model must belong to the active tenant even when
     * the scope is off (the sanctioned bypass), so a guessed id is a 404.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $model = parent::resolveRouteBinding($value, $field);
        $tenantId = app(Tenancy::class)->id();

        if ($model !== null && $tenantId !== null && (int) $model->getAttribute('tenant_id') !== $tenantId) {
            throw (new ModelNotFoundException)->setModel(static::class, [$value]);
        }

        return $model;
    }
}
