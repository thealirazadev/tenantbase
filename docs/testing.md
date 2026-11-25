# Testing - tenantbase

## Strategy

- **PostgreSQL only, everywhere.** Tests run against a dedicated PostgreSQL test database as
  the `tenantbase_app` role (`NOSUPERUSER NOBYPASSRLS`), never SQLite: a suite that skips RLS
  proves nothing about this product. A guard test asserts at boot that the connected role
  cannot bypass RLS and fails the whole run if it can (the classic mistake is running CI as the
  `postgres` superuser, which silently bypasses every policy).
- **The isolation proof is the flagship suite.** `IsolationProofTest` uses raw PDO statements,
  no Eloquent, to prove the database alone enforces isolation: context A sees only A's rows in
  every tenant-owned table; no context sees zero rows; inserts with a mismatched `tenant_id` or
  with no context throw. Every new tenant-owned table is added to its matrix in the same commit
  that creates the table.
- **Concurrency.** The last-owner guard's row locking was checked by hand with two owners
  demoting each other concurrently; the usage-limit race test (two connections competing for
  the last gauge slot, exactly one wins) arrives with metering.
- **RefreshDatabase transactions interact with tenant context.** Each test runs inside the
  trait's wrapping transaction; the middleware's begin/commit becomes a savepoint, and
  `set_config(..., true)` set inside a savepoint rolls back with it. Context can outlive a
  simulated request within one test, so `Tenancy::deactivate()` (which resets the GUC to `''`)
  runs in middleware finally-blocks and in the `afterEach` of every test file that activates a
  tenant directly.
- **No network in tests.** Nothing sends real mail or HTTP. When invitations and queued jobs
  land, they use `Mail::fake()` and `Queue::fake()` where dispatch is the assertion.
- **Factories** for Tenant, User, Membership, and Project; tenant-owned factories require an
  explicit tenant (no silently invented tenants).

### What the suite covers

Unit: `Tenancy::activate()` refuses to run outside a transaction.

Feature: auth (register, duplicate email, login, login throttle, logout, account screens kept
off tenant subdomains); resolution (subdomain, unknown 404, suspended 403, JSON envelope, guests
sent to central login); the tenancy context (activate, deactivate, `runAs` restore, bypass
setting, reason required, restore on exception); `SetTenantContext` (transaction opened, context
cleared, rollback when the action throws); membership RLS and the raw PDO isolation matrix;
`EnsureMembership`; provisioning atomicity (a failed owner insert leaves nothing), slug format,
reserved and duplicate slugs, and `tenant:create`; scope filtering, auto-fill, and
`MissingTenantContext`; route-binding 404 with the scope and RLS bypassed; project CRUD; the role
policy matrix and the last-owner guard; the members screen; the workspace picker and its logged
bypass.

Still to come with the features that need them: invitation lifecycle, usage limits and quota
boundaries, the JSON API, job context restoration, and cache isolation.

## Exact commands

```bash
# One-time: local role and databases (as a postgres superuser)
psql -U postgres -c "CREATE ROLE tenantbase_app LOGIN PASSWORD 'secret' NOSUPERUSER NOBYPASSRLS"
psql -U postgres -c "CREATE DATABASE tenantbase OWNER tenantbase_app"
psql -U postgres -c "CREATE DATABASE tenantbase_test OWNER tenantbase_app"

# App setup
composer install
cp .env.example .env            # DB_* points at tenantbase as tenantbase_app
php artisan key:generate
php artisan migrate

# Full test suite (phpunit.xml points at tenantbase_test)
php artisan test

# A single suite / filter
php artisan test tests/Feature/IsolationProofTest.php
php artisan test --filter=blocks_mismatched_insert

# Formatting
./vendor/bin/pint --test        # check (must pass)
./vendor/bin/pint               # fix
```

Running the full system locally: `php artisan serve` (with `APP_DOMAIN=localhost` subdomain
resolution needs `*.localhost`, which modern browsers resolve to 127.0.0.1 natively),
`php artisan queue:work`, and `php artisan schedule:work` when exercising the rollup.

## CI

`.github/workflows/ci.yml` runs on every push to `main` and every pull request targeting `main`,
as a matrix over PHP 8.3 and 8.4 on Ubuntu with a `postgres:16` service container. Steps:

1. Checkout, then setup-php with `pdo_pgsql` and `pgsql`, and pcov for coverage with
   `pcov.directory=.` so `app/` is instrumented as well as `src/`.
2. As the `postgres` superuser, create the `tenantbase_app` role (`NOSUPERUSER NOBYPASSRLS`)
   and the `tenantbase` and `tenantbase_test` databases. This is the only superuser step;
   everything after it connects as the app role.
3. `composer install --no-interaction --prefer-dist --no-progress`.
4. `cp .env.example .env` and `php artisan key:generate`.
5. `vendor/bin/pint --test`.
6. `php artisan test --coverage --min=90`, which fails the job below 90% line coverage.

## Before merging a change

1. `./vendor/bin/pint --test` clean.
2. `php artisan test` green on PostgreSQL, new tests included, and the isolation matrix
   updated if a tenant-owned table was added.
3. With pcov installed locally, `php artisan test --coverage --min=90` matches the CI gate.
