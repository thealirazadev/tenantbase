<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * No Eloquent below this line. Everything runs through raw PDO so the suite
 * proves what PostgreSQL enforces on its own, independent of any application
 * scope. Table names come from the matrix, never from input.
 */
function proofPdo(): PDO
{
    return DB::getPdo();
}

function proofSetTenant(?int $tenantId): void
{
    $statement = proofPdo()->prepare('select set_config(?, ?, true)');
    $statement->execute(['app.tenant_id', $tenantId === null ? '' : (string) $tenantId]);
}

/**
 * @param  array<string, mixed>  $row
 */
function proofInsert(string $table, array $row): void
{
    $columns = implode(', ', array_keys($row));
    $placeholders = implode(', ', array_fill(0, count($row), '?'));

    proofPdo()
        ->prepare("insert into {$table} ({$columns}) values ({$placeholders})")
        ->execute(array_values($row));
}

function proofCount(string $table): int
{
    return (int) proofPdo()->query("select count(*) from {$table}")->fetchColumn();
}

/**
 * Runs a statement that the policy is expected to reject. The savepoint keeps
 * the surrounding transaction usable after PostgreSQL aborts the statement.
 */
function proofRejects(Closure $statement): bool
{
    proofPdo()->exec('savepoint isolation_proof');

    try {
        $statement();
        $rejected = false;
    } catch (PDOException) {
        $rejected = true;
    }

    proofPdo()->exec('rollback to savepoint isolation_proof');

    return $rejected;
}

/**
 * The isolation matrix. Every tenant-owned table joins this list in the same
 * commit that creates it.
 *
 * @var array<string, Closure(int, int): array<string, mixed>>
 */
$matrix = [
    'memberships' => fn (int $tenantId, int $userId): array => [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'role' => 'member',
        'created_at' => '2026-07-28 00:00:00',
        'updated_at' => '2026-07-28 00:00:00',
    ],
];

beforeEach(function (): void {
    $this->acme = Tenant::factory()->create(['slug' => 'acme']);
    $this->globex = Tenant::factory()->create(['slug' => 'globex']);
    $this->acmeUser = User::factory()->create();
    $this->globexUser = User::factory()->create();
});

afterEach(function (): void {
    proofSetTenant(null);
});

it('connects as a role that cannot bypass row level security', function (): void {
    $role = DB::selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = current_user');

    expect($role->rolsuper)->toBeFalse('the connected role is a superuser and silently bypasses every policy')
        ->and($role->rolbypassrls)->toBeFalse('the connected role has BYPASSRLS and silently bypasses every policy');
});

foreach ($matrix as $table => $buildRow) {
    it("shows only the active tenant rows in {$table}", function () use ($table, $buildRow): void {
        proofSetTenant($this->acme->id);
        proofInsert($table, $buildRow($this->acme->id, $this->acmeUser->id));

        proofSetTenant($this->globex->id);
        proofInsert($table, $buildRow($this->globex->id, $this->globexUser->id));

        proofSetTenant($this->acme->id);
        expect(proofCount($table))->toBe(1);

        proofSetTenant($this->globex->id);
        expect(proofCount($table))->toBe(1);
    });

    it("shows no {$table} rows without a context", function () use ($table, $buildRow): void {
        proofSetTenant($this->acme->id);
        proofInsert($table, $buildRow($this->acme->id, $this->acmeUser->id));

        proofSetTenant(null);

        expect(proofCount($table))->toBe(0);
    });

    it("rejects an insert into {$table} for another tenant", function () use ($table, $buildRow): void {
        proofSetTenant($this->acme->id);

        $rejected = proofRejects(fn () => proofInsert($table, $buildRow($this->globex->id, $this->globexUser->id)));

        expect($rejected)->toBeTrue();
    });

    it("rejects an insert into {$table} with no context at all", function () use ($table, $buildRow): void {
        proofSetTenant(null);

        $rejected = proofRejects(fn () => proofInsert($table, $buildRow($this->acme->id, $this->acmeUser->id)));

        expect($rejected)->toBeTrue();
    });

    it("rejects moving a {$table} row to another tenant", function () use ($table, $buildRow): void {
        proofSetTenant($this->acme->id);
        proofInsert($table, $buildRow($this->acme->id, $this->acmeUser->id));

        $rejected = proofRejects(function () use ($table): void {
            $statement = proofPdo()->prepare("update {$table} set tenant_id = ?");
            $statement->execute([$this->globex->id]);
        });

        expect($rejected)->toBeTrue();
    });

    it("carries enable and force row level security on {$table}", function () use ($table): void {
        $flags = DB::selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class where relname = ?',
            [$table]
        );
        $policy = DB::selectOne(
            'select polname from pg_policy where polrelid = ?::regclass and polname = ?',
            [$table, 'tenant_isolation']
        );

        expect($flags->relrowsecurity)->toBeTrue()
            ->and($flags->relforcerowsecurity)->toBeTrue()
            ->and($policy)->not->toBeNull();
    });
}
