<?php

namespace TenantBase\Tenancy;

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

        if ($this->app->runningInConsole()) {
            $this->commands([CreateTenantCommand::class]);
        }
    }
}
