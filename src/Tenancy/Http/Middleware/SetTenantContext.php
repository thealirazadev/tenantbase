<?php

namespace TenantBase\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use TenantBase\Tenancy\Exceptions\MissingTenantContext;
use TenantBase\Tenancy\Tenancy;
use Throwable;

/**
 * Opens the request transaction that gives set_config(..., true) something to
 * live in. The setting dies with the transaction, which is what makes a
 * connection pooler in transaction mode safe here.
 */
class SetTenantContext
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->attributes->get(ResolveTenant::ATTRIBUTE);

        if (! $tenant instanceof Model) {
            throw MissingTenantContext::forQuery('the request pipeline');
        }

        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            $this->tenancy->activate($tenant);

            $response = $next($request);

            // Laravel's routing pipeline turns an uncaught exception into a 5xx
            // response before it reaches this middleware, so the status is what
            // decides the outcome. Failures are never persisted.
            if ($response->getStatusCode() >= 500) {
                $connection->rollBack();
            } else {
                $connection->commit();
            }

            return $response;
        } catch (Throwable $e) {
            $connection->rollBack();

            throw $e;
        } finally {
            $this->tenancy->deactivate();
        }
    }
}
