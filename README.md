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

## Planned features

All behavior below is planned, not yet built; implementation follows `docs/phases.md`.

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

- PHP 8.3 / Laravel 11.x, structured as a starter app (`app/`) plus a small internal Tenancy
  package (`src/Tenancy`, namespace `TenantBase\Tenancy`).
- PostgreSQL 16, required everywhere including tests: row-level security is the point. The app
  connects as a dedicated `NOSUPERUSER NOBYPASSRLS` role.
- Laravel Sanctum (bundled) for API tokens; database drivers for queue, session, and cache.
- Blade plus one static CSS file (no Node toolchain); Pest on PHPUnit; Laravel Pint.

## Documentation

| File | Contents |
|---|---|
| [docs/PRD.md](docs/PRD.md) | Problem, target user, core features, non-goals, success criteria. |
| [docs/architecture.md](docs/architecture.md) | Stack rationale, tenant context lifecycle, data model, RLS policy, flows, failure modes, invariants, layout. |
| [docs/rules.md](docs/rules.md) | Binding engineering rules, tenancy rules included. |
| [docs/phases.md](docs/phases.md) | Five implementation phases with exact commits and verification checklists. |
| [docs/design.md](docs/design.md) | Screens, role visibility matrix, tokens, states, accessibility. |
| [docs/testing.md](docs/testing.md) | Test strategy (PostgreSQL-only), the isolation proof, commands, CI plan. |
| [docs/api-contracts.md](docs/api-contracts.md) | Error envelope, resolution contract, every route, API and CLI examples. |
| [docs/launch-checklist.md](docs/launch-checklist.md) | Pre-production checks, isolation probes included. |
| [docs/memory.md](docs/memory.md) | Working log and decisions record. |

## Status

Planning stage: these documents are the complete specification and no application code exists
yet. Implementation proceeds one phase at a time per `docs/phases.md`, and each phase must pass
its verification checklist before the next begins.
