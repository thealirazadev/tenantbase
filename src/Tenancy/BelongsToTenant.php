<?php

namespace TenantBase\Tenancy;

use Illuminate\Database\Eloquent\Model;
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
}
