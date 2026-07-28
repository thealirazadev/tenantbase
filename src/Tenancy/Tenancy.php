<?php

namespace TenantBase\Tenancy;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use TenantBase\Tenancy\Exceptions\MissingTenantContext;
use Throwable;

/**
 * The single activation path for tenant context. The PHP binding and the
 * PostgreSQL setting app.tenant_id are only ever written here, together,
 * inside an open transaction.
 */
class Tenancy
{
    private const TENANT_GUC = 'app.tenant_id';

    private const BYPASS_GUC = 'app.tenancy_bypass';

    private ?Model $tenant = null;

    private bool $bypassing = false;

    public function tenant(): ?Model
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function hasContext(): bool
    {
        return $this->tenant !== null;
    }

    public function bypassing(): bool
    {
        return $this->bypassing;
    }

    /**
     * Bind a tenant to the request and to the database session.
     */
    public function activate(Model $tenant): void
    {
        if ($this->connection()->transactionLevel() === 0) {
            throw MissingTenantContext::outsideTransaction();
        }

        $this->setSetting(self::TENANT_GUC, (string) $tenant->getKey());
        $this->tenant = $tenant;
    }

    /**
     * Clear both halves of the context. Safe to call from a finally block:
     * a lost connection is logged, never rethrown over the original error.
     */
    public function deactivate(): void
    {
        $this->tenant = null;

        try {
            if ($this->connection()->transactionLevel() > 0) {
                $this->setSetting(self::TENANT_GUC, '');
            }
        } catch (QueryException $e) {
            Log::warning('tenancy.context_missing', [
                'reason' => 'could not reset the tenant setting',
                'exception' => $e::class,
            ]);
        }
    }

    public function assertContext(string $subject): void
    {
        if ($this->tenant === null && ! $this->bypassing) {
            throw MissingTenantContext::forQuery($subject);
        }
    }

    /**
     * Run a callback in another tenant's context, restoring the previous one.
     * Opens a transaction when the caller has none (queue workers, artisan).
     */
    public function runAs(Model $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $ownsTransaction = $this->connection()->transactionLevel() === 0;

        if ($ownsTransaction) {
            $this->connection()->beginTransaction();
        }

        try {
            $this->activate($tenant);
            $result = $callback();

            if ($ownsTransaction) {
                $this->connection()->commit();
            }

            return $result;
        } catch (Throwable $e) {
            if ($ownsTransaction) {
                $this->connection()->rollBack();
            }

            throw $e;
        } finally {
            $this->tenant = $previous;

            if (! $ownsTransaction) {
                $this->setSetting(self::TENANT_GUC, (string) ($previous?->getKey() ?? ''));
            }
        }
    }

    /**
     * The one sanctioned escape hatch: read across tenants. Writes stay
     * blocked because the RLS policies carry no bypass in WITH CHECK.
     */
    public function withoutTenancy(string $reason, Closure $callback): mixed
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('withoutTenancy() requires a reason.');
        }

        Log::info('tenancy.bypass', ['reason' => $reason, 'tenant_id' => $this->id()]);

        $previous = $this->bypassing;
        $ownsTransaction = $this->connection()->transactionLevel() === 0;

        if ($ownsTransaction) {
            $this->connection()->beginTransaction();
        }

        try {
            $this->setSetting(self::BYPASS_GUC, '1');
            $this->bypassing = true;
            $result = $callback();

            if ($ownsTransaction) {
                $this->connection()->commit();
            }

            return $result;
        } catch (Throwable $e) {
            if ($ownsTransaction) {
                $this->connection()->rollBack();
            }

            throw $e;
        } finally {
            $this->bypassing = $previous;

            if (! $ownsTransaction) {
                $this->setSetting(self::BYPASS_GUC, $previous ? '1' : '');
            }
        }
    }

    /**
     * Parameterized and transaction scoped. SET LOCAL takes no bind
     * parameters, so set_config with is_local => true is the only safe form.
     */
    private function setSetting(string $name, string $value): void
    {
        $this->connection()->select('select set_config(?, ?, true)', [$name, $value]);
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection();
    }
}
