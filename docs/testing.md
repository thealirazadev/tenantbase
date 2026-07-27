# Testing - tenantbase

## Strategy

- **PostgreSQL only, everywhere.** Tests run against a dedicated PostgreSQL test database as
  the `tenantbase_app` role (`NOSUPERUSER NOBYPASSRLS`), never SQLite: a suite that skips RLS
  proves nothing about this product. A guard test asserts at boot that the connected role
  cannot bypass RLS and fails the whole run if it can (the classic mistake is running CI as the
  `postgres` superuser, which silently bypasses every policy).
- **The isolation proof is the flagship suite.** `IsolationProofTest` uses raw PDO statements,
  no Eloquent, to prove the database alone enforces isolation: context A sees only A's rows in
  every tenant-owned table; no context sees zero rows; inserts and updates with a mismatched
  `tenant_id` throw. Every new tenant-owned table is added to its matrix in the same commit
  that creates the table.
- **Concurrency tests are real.** The limit-race test opens two database connections, starts
  two transactions competing for the last gauge slot, and asserts exactly one wins. No
  sleep-and-hope; the guarded UPDATE's row lock is the thing under test.
- **RefreshDatabase transactions interact with tenant context.** Each test runs inside the
  trait's wrapping transaction; the middleware's begin/commit becomes a savepoint, and
  `set_config(..., true)` set inside a savepoint rolls back with it. Context can outlive a
  simulated request within one test, so `Tenancy::deactivate()` (which resets the GUC to `''`)
  runs in middleware finally-blocks and in the base test case's teardown.
- **Fakes over network**: `Mail::fake()` for invitations (plus one test rendering the mailable
  for the accept URL), `Queue::fake()` where dispatch is the assertion, direct
  `handle()`-with-middleware execution where job side effects are the assertion. No test sends
  real mail or HTTP.
- **Factories** for Tenant, User, Membership, Invitation, Project; tenant-owned factories
  require an explicit tenant (no silently invented tenants). Helpers: `actingAsMember($tenant,
  $role)` and `withTenant($tenant)` set up context the same way the middleware does.

### What to cover

Unit: slug validation (format, reserved list), invitation token hashing and expiry math, plan
lookup with `null` limits, `UsageMeter` SQL parameter shapes, cache key prefixing.

Feature: auth (register, login throttle, logout); resolution (subdomain, unknown, suspended,
`X-Tenant` on API only, subdomain/header mismatch 400); provisioning atomicity (forced failure
after tenant insert rolls everything back) and the duplicate-slug race; scope filtering,
auto-fill, `MissingTenantContext`; route-binding 404 with the scope disabled; policy matrix and
the last-owner guard; invitation lifecycle, revoke, expiry boundary, double-accept, concurrent
duplicate invites; limit races, quota boundary (999/1000/1001), month rollover, rollback
not billing a failed request, reconcile; API envelope shapes and `X-Quota-Remaining`; job
context restoration, orphaned jobs, dispatch-without-context; cache isolation; suspension; the
bypass call-site grep audit.

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

## CI plan

GitHub Actions on push and PR to `main`: a `postgres:16` service container; a setup step uses
the superuser only to create the `tenantbase_app` role and the test database, then everything
else connects as the app role (this split is load-bearing: tests must not run as superuser).
Steps: checkout, PHP 8.3 with `pdo_pgsql`, `composer install` (cached), `./vendor/bin/pint
--test`, `php artisan test`. The workflow lands in Phase 5 with the README finalization.

## Definition of "done" for a feature

1. `./vendor/bin/pint --test` clean.
2. `php artisan test` green on PostgreSQL, new tests included, isolation matrix updated if a
   table was added.
3. The feature's checklist items in `docs/phases.md` pass manually.

One commit per feature, in the order listed in `docs/phases.md`.
