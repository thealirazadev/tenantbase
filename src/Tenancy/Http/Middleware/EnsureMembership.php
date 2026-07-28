<?php

namespace TenantBase\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;
use TenantBase\Tenancy\Exceptions\TenancyHttpException;
use TenantBase\Tenancy\Tenancy;

/**
 * Gates the tenant group: a valid session is not access. The lookup runs under
 * the active context, so row-level security already limits it to this tenant.
 */
class EnsureMembership
{
    public const ATTRIBUTE = 'tenantbase.membership';

    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw TenancyHttpException::forbidden();
        }

        $membership = $this->membershipModel()->newQuery()
            ->where('tenant_id', $this->tenancy->id())
            ->where('user_id', $user->getAuthIdentifier())
            ->first();

        if ($membership === null) {
            throw TenancyHttpException::forbidden();
        }

        $request->attributes->set(self::ATTRIBUTE, $membership);
        View::share('role', $membership->role);

        return $next($request);
    }

    private function membershipModel(): Model
    {
        /** @var class-string<Model> $class */
        $class = config('tenantbase.models.membership');

        return new $class;
    }
}
