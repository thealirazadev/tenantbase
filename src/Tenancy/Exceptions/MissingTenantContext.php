<?php

namespace TenantBase\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when tenant data is reached with no active tenant context. Failing
 * loudly beats returning an empty result that looks like real data.
 */
class MissingTenantContext extends RuntimeException
{
    public static function forQuery(string $subject): self
    {
        return new self("No active tenant context while accessing [{$subject}].");
    }

    public static function outsideTransaction(): self
    {
        return new self(
            'Tenant context requires an open transaction: set_config(..., true) is '.
            'transaction scoped and would silently do nothing.'
        );
    }
}
