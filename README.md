# tenantbase

A production-grade single-database multi-tenant SaaS foundation for Laravel. Every tenant-owned
table carries `tenant_id`, and isolation is enforced twice: an Eloquent global scope and
PostgreSQL row-level security policies driven by a transaction-scoped session variable, so a
forgotten scope or a raw query cannot leak another tenant's data. Around that core it plans the
parts every SaaS rebuilds under pressure: tenant resolution, membership with roles and
invitations, race-safe per-plan usage metering, tenant-aware cache and queues, and operator
tooling.

## The problem it solves

Most Laravel multi-tenancy setups hang entirely on one Eloquent global scope. The day someone
writes a raw query, disables the scope for a report, or forgets the trait on a new model, tenant
A reads tenant B's data and nobody notices until a customer does. tenantbase treats that as a
database problem, not a convention problem: PostgreSQL row-level security re-checks every row on
every query, reads outside a tenant context return nothing, and writes with a mismatched tenant
are rejected by the database itself. The Eloquent scope remains as the first net and for
developer ergonomics; the database is the net that cannot be forgotten.

## Features

Built today: isolation, tenants, projects, roles, and members. Invitations, metering, the JSON
API, and the tenant-aware cache and queues are designed in `docs/architecture.md` and
`docs/api-contracts.md` but not yet built; see Status below.

- Double-enforced isolation: `BelongsToTenant` global scope plus RLS policies keyed to a
  per-request `app.tenant_id` set with `SET LOCAL` semantics inside the request transaction.
- A third net at route-model binding: resolving another tenant's id is a 404 even if the scope
  is disabled.
- Tenant resolution by subdomain (`{slug}.APP_DOMAIN`) with an `X-Tenant` header fallback for
  the API; disagreement between the two is a 400, never a guess.
- Users are global; memberships join them to tenants with `owner` / `admin` / `member` roles;
  email invitations with hashed, expiring, single-use tokens; the last owner is irremovable.
- Atomic tenant provisioning: tenant, owner membership, and usage counters in one transaction.
- Per-plan usage metering: live gauges for projects and members, a monthly quota for API calls,
  enforced by single guarded SQL statements so concurrent requests cannot overshoot a limit,
  plus a monthly rollup table for history.
- Tenant-aware cache (key prefixing) and queues (tenant id serialized with each job, restored
  and verified in the worker inside a transaction).
- A logged, greppable `withoutTenancy()` escape hatch for landlord queries, read-only for
  tenant data by construction.
- Demo `projects` resource (web CRUD and JSON API) exercising every net, limit, and role.
- Artisan tooling: `tenant:create`, `tenant:run`, `tenant:usage` (with reconcile),
  `tenant:suspend`/`unsuspend`, and a scheduled monthly usage rollup.
- A test suite whose flagship proves isolation with raw SQL, bypassing Eloquent entirely.

## Stack

- PHP 8.3 / Laravel 12.x, structured as a starter app (`app/`) plus a small internal Tenancy
  package (`src/Tenancy`, namespace `TenantBase\Tenancy`).
- PostgreSQL 16, required everywhere including tests: row-level security is the point. The app
  connects as a dedicated `NOSUPERUSER NOBYPASSRLS` role.
- Laravel Sanctum (bundled) for API tokens; database drivers for queue, session, and cache.
- Blade plus one static CSS file (no Node toolchain); Pest on PHPUnit; Laravel Pint.

## Database setup

The app never connects as a superuser: a superuser silently bypasses every row-level security
policy, which would disable the isolation this project exists to provide. Create a dedicated
role and two databases owned by it, running these as a PostgreSQL superuser once:

```bash
psql -U postgres -c "CREATE ROLE tenantbase_app LOGIN PASSWORD 'secret' NOSUPERUSER NOBYPASSRLS"
psql -U postgres -c "CREATE DATABASE tenantbase OWNER tenantbase_app"
psql -U postgres -c "CREATE DATABASE tenantbase_test OWNER tenantbase_app"
```

Migrations run as `tenantbase_app` as well. Owning the tables does not exempt the role from the
policies, because every tenant-owned table also sets `FORCE ROW LEVEL SECURITY`.

## Local setup

```bash
composer install
cp .env.example .env          # point DB_* at tenantbase as tenantbase_app
php artisan key:generate
php artisan migrate
php artisan serve
```

Tenants live at `{slug}.APP_DOMAIN`. With `APP_DOMAIN=localhost`, browsers resolve `*.localhost`
to 127.0.0.1 natively; for a custom domain, add a wildcard host entry or wildcard DNS record.

```bash
php artisan test                # full suite against tenantbase_test
./vendor/bin/pint --test        # code style
```

## Documentation

| File | Contents |
|---|---|
| [docs/architecture.md](docs/architecture.md) | Stack rationale, tenant context lifecycle, data model, RLS policy, flows, failure modes, invariants, layout. |
| [docs/decisions.md](docs/decisions.md) | Design decisions and the reasons behind them, including bugs found along the way. |
| [docs/design.md](docs/design.md) | Screens, role visibility matrix, tokens, states, accessibility. |
| [docs/testing.md](docs/testing.md) | Test strategy (PostgreSQL-only), the isolation proof, commands, CI. |
| [docs/api-contracts.md](docs/api-contracts.md) | Error envelope, resolution contract, every route, API and CLI examples. |

## Status

Built: the PostgreSQL foundation, central auth, tenant resolution and context, `memberships`
and `projects` under row-level security, the Eloquent scope and route-binding check, atomic
provisioning, `tenant:create`, project CRUD, role policies, the members screen with the
last-owner guard, and the raw SQL isolation proof.

Not built yet: invitations, usage metering, the JSON API, tenant-aware queues and cache, and the
remaining operator commands (`tenant:run`, `tenant:usage`, `tenant:suspend`, the usage rollup).
Their design is in `docs/architecture.md` and `docs/api-contracts.md`.
