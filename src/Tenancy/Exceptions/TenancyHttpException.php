<?php

namespace TenantBase\Tenancy\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Carries a stable error code alongside the HTTP status. JSON callers get the
 * one error envelope; HTML callers fall through to the branded error pages.
 */
class TenancyHttpException extends HttpException
{
    public function __construct(int $status, public readonly string $errorCode, string $message)
    {
        parent::__construct($status, $message);
    }

    public static function unknownTenant(): self
    {
        return new self(404, 'unknown_tenant', 'That workspace does not exist.');
    }

    public static function tenantSuspended(): self
    {
        return new self(403, 'tenant_suspended', 'This workspace is suspended. Contact your administrator.');
    }

    public static function forbidden(): self
    {
        return new self(403, 'forbidden', 'You are not a member of this workspace.');
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return new JsonResponse([
            'error' => ['code' => $this->errorCode, 'message' => $this->getMessage()],
        ], $this->getStatusCode());
    }
}
