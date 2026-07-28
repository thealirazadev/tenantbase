<?php

namespace TenantBase\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;
use TenantBase\Tenancy\Exceptions\TenancyHttpException;

/**
 * Resolves the tenant from the request subdomain. The tenants table is a
 * landlord table, so this lookup needs no context and reads no tenant data.
 */
class ResolveTenant
{
    public const ATTRIBUTE = 'tenantbase.tenant';

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $this->slug($request);

        if ($slug === null) {
            throw TenancyHttpException::unknownTenant();
        }

        $tenant = $this->tenantModel()->newQuery()->where('slug', $slug)->first();

        if ($tenant === null) {
            throw TenancyHttpException::unknownTenant();
        }

        if ($tenant->isSuspended()) {
            throw TenancyHttpException::tenantSuspended();
        }

        $request->attributes->set(self::ATTRIBUTE, $tenant);
        View::share('tenant', $tenant);

        Log::info('tenancy.resolved', ['tenant_id' => $tenant->getKey(), 'slug' => $slug]);

        return $next($request);
    }

    /**
     * The route group binds {tenant_slug}; forget it so controller actions do
     * not have to accept a parameter they never use.
     */
    private function slug(Request $request): ?string
    {
        $route = $request->route();
        $slug = $route?->parameter('tenant_slug');
        $route?->forgetParameter('tenant_slug');

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    private function tenantModel(): Model
    {
        /** @var class-string<Model> $class */
        $class = config('tenantbase.models.tenant');

        return new $class;
    }
}
