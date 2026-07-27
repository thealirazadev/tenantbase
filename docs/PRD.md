# Product Requirements - tenantbase

## What we're building

A production-grade single-database multi-tenant SaaS foundation for Laravel: a starter application
plus a small internal Tenancy package (`src/`). Every tenant-owned table carries `tenant_id`, and
isolation is enforced twice: an Eloquent global scope (`BelongsToTenant`) and PostgreSQL row-level
security policies driven by a transaction-scoped session variable, so a forgotten scope or a raw
query cannot leak another tenant's data. On top of that core it ships the pieces every SaaS
rewrites badly under deadline pressure: tenant resolution (subdomain, header fallback for the
API), membership with roles and email invitations, per-plan usage metering with race-safe limit
enforcement, tenant-aware cache and queues, and artisan tooling for operating tenants.

## Target user

A developer or small team starting a B2B SaaS on Laravel who wants tenant isolation that is
provably safe rather than convention-deep. They will rename `Project` to their own domain model
and keep the tenancy layer. Self-hosted, PostgreSQL-only by design; not a general-purpose package
and not a hosted service.

## Core features (prioritized)

1. **Double-enforced tenant isolation** - Every tenant-owned table has `tenant_id` plus a
   PostgreSQL row-level security policy keyed to `app.tenant_id`, set with `SET LOCAL` semantics
   inside a per-request transaction. Eloquent applies the same filter via the `BelongsToTenant`
   global scope. Either net alone is sufficient; both must fail for data to leak.

2. **Tenant resolution** - Middleware resolves the tenant from the request subdomain
   (`{slug}.APP_DOMAIN`); API requests to the central domain may instead send an `X-Tenant`
   header. Unknown or suspended tenants get a 404/403 with the standard error envelope. A
   subdomain and a disagreeing header on the same request is a 400, never a guess.

3. **Membership, roles, invitations** - Users are global; a `memberships` row joins a user to a
   tenant with a role (`owner`, `admin`, `member`). Owners and admins invite by email with an
   expiring single-use token; accepting creates the membership. The last owner can never be
   removed or demoted.

4. **Atomic tenant provisioning** - Signup creates the tenant, the owner membership, and the
   usage counter rows in one transaction. A failure anywhere rolls back everything; no
   half-provisioned tenants exist, ever.

5. **Per-plan usage metering** - Plans (`free`, `pro`) live in config with limits for counted
   resources: `projects` and `members` (live gauges) and `api_calls` (monthly). Limits are
   enforced at creation time with a single guarded SQL statement, so two concurrent requests
   cannot both take the last slot. A monthly rollup table keeps usage history.

6. **Tenant-aware cache and queues** - Cache keys for tenant data are prefixed with the tenant
   id. Queued jobs serialize the dispatching tenant's id; a job middleware restores and verifies
   the context in the worker, inside a transaction, so RLS holds in background work too.

7. **Demo resource: projects** - A minimal CRUD resource (web and JSON API) that exercises every
   net: global scope, RLS, route-model-binding tenant check, role policy, and the project gauge
   limit. It is the template users copy for their own models.

8. **Operator tooling** - `tenant:create` provisions from the CLI; `tenant:run` executes any
   artisan command inside a tenant's context; `tenant:usage` prints usage against plan limits and
   can reconcile gauge drift. All landlord queries in the codebase go through a logged, greppable
   `withoutTenancy()` escape hatch.

## Non-goals

- Multi-database or schema-per-tenant mode; single database with RLS is the design, not a tier.
- Billing or payments: metering and limits only. Stripe integration is explicitly out of scope.
- SSO, SAML, OAuth login, or 2FA; email + password auth only.
- Per-tenant domains (CNAME) or per-tenant theming; subdomains of one app domain only.
- Tenant deletion from the UI; suspension exists, deletion is an operator decision (backlog).
- Horizontal-scale infrastructure: Redis, Horizon, multi-region. Database drivers on one box.
- Realtime features (websockets, broadcasting).
- MySQL/SQLite support anywhere, including tests; RLS is the point and only PostgreSQL has it.

## Success criteria per core feature

- **Isolation** - A Pest suite that bypasses Eloquent entirely (raw PDO queries) proves: with
  tenant A's context, only A's rows are visible; with no context, zero rows; an insert or update
  whose `tenant_id` disagrees with the context is rejected by the database. Separately, with RLS
  hypothetically ignored, the global scope still filters every Eloquent query.
- **Resolution** - `acme.APP_DOMAIN` resolves tenant `acme`; an unknown slug is a 404 envelope; a
  suspended tenant is a 403 envelope; `X-Tenant` works on the central-domain API; header plus
  disagreeing subdomain is a 400; the header is ignored outside the API group.
- **Membership** - A non-member hitting a tenant subdomain gets 403 even with a valid session;
  roles gate actions per the matrix in `docs/design.md`; demoting or removing the last owner
  fails with a friendly error.
- **Provisioning** - Signup lands the user on their new tenant dashboard; the database shows the
  tenant, one owner membership, and gauge counters (`projects` 0, `members` 1) or, on any
  failure, none of them. Two concurrent signups with the same slug produce one tenant.
- **Metering** - With a limit of N, N+K concurrent create requests produce exactly N rows and K
  friendly failures. The API returns 429 with the envelope after the monthly quota; the rollup
  table matches observed usage; `tenant:usage --reconcile` fixes an artificially drifted gauge.
- **Cache/queues** - Two tenants caching under the same logical key read back their own values.
  A job dispatched in tenant context sees only that tenant's rows in the worker; a job whose
  tenant has been suspended or deleted fails permanently with a logged reason, not a retry loop.
- **Projects** - Guessing another tenant's project id in the URL is a 404 (binding check), and
  the same request would also be blocked by scope and RLS; project creation past the plan limit
  fails with the friendly limit message.
- **Tooling** - `tenant:create` provisions identically to signup; `tenant:run acme db:seed`
  seeds only acme's data; `tenant:usage` output matches the database; every `withoutTenancy()`
  call site in the codebase carries a reason string and emits a `tenancy.bypass` log line.
