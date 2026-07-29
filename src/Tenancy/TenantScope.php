<?php

namespace TenantBase\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The first of the two nets. Row-level security re-checks every row anyway;
 * this scope exists so a context-less query fails loudly instead of rendering
 * someone an empty dashboard that looks like real data.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenancy = app(Tenancy::class);

        // Inside the sanctioned bypass the caller is deliberately reading
        // across tenants; WITH CHECK still keeps that read-only.
        if ($tenancy->bypassing()) {
            return;
        }

        $tenancy->assertContext($model->getTable());

        $builder->where($model->qualifyColumn('tenant_id'), $tenancy->id());
    }
}
