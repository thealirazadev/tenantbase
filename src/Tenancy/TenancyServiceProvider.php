<?php

namespace TenantBase\Tenancy;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use TenantBase\Tenancy\Console\CreateTenantCommand;
use TenantBase\Tenancy\Http\Middleware\EnsureMembership;
use TenantBase\Tenancy\Http\Middleware\ResolveTenant;
use TenantBase\Tenancy\Http\Middleware\SetTenantContext;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Tenancy::class);
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('tenant.resolve', ResolveTenant::class);
        $router->aliasMiddleware('tenant.context', SetTenantContext::class);
        $router->aliasMiddleware('tenant.member', EnsureMembership::class);

        // Route-model binding must run inside the tenant context, or binding a
        // tenant-owned model hits the scope before there is a tenant to scope by.
        $kernel = $this->app->make(Kernel::class);
        if ($kernel instanceof HttpKernel) {
            foreach ([ResolveTenant::class, SetTenantContext::class, EnsureMembership::class] as $middleware) {
                $kernel->addToMiddlewarePriorityBefore(SubstituteBindings::class, $middleware);
            }
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CreateTenantCommand::class]);
        }
    }
}
