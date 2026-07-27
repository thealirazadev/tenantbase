# Architecture - tenantbase

## App flow

```
Request to {slug}.APP_DOMAIN (web)  or  APP_DOMAIN/api/* with X-Tenant (API)
        ▼
ResolveTenant      central domain → central routes; one subdomain label → tenant by slug
                   (unknown 404, suspended 403); API + central host: X-Tenant header;
                   subdomain AND header disagree → 400 tenant_mismatch, never a guess
        ▼
SetTenantContext   DB::beginTransaction()  (the request transaction)
                   select set_config('app.tenant_id', ?, true)   (SET LOCAL semantics)
                   bind Tenancy::$tenant   (Eloquent scope + cache prefix read this)
                   after $next(): commit, or rollBack on exception; then reset context
        ▼
EnsureMembership   user has a memberships row for this tenant → role bound; else 403
        ▼
Controllers / Eloquent   (net 1: BelongsToTenant global scope)
        ▼
PostgreSQL               (net 2: RLS policy on every tenant-owned table)

Queue path: dispatch captures Tenancy::id() into the job payload → worker job middleware
re-verifies the tenant, opens a transaction, set_config, runs the job, commits. Same two nets.
```

## Threat model

RLS defends against **application bugs**: a forgotten `BelongsToTenant` trait, a raw
`DB::select`, a reporting query pasted in without scoping, a package querying tables directly.
It does not defend against a compromised application process, which could always call
`set_config` itself; consequently the bypass GUC (`app.tenancy_bypass`, below) does not weaken
the model, since anything able to set it could set `app.tenant_id` too. What matters is that no
code path reaches tenant data without the correct context or an explicit, logged bypass.

## Tech stack with rationale

- **PHP 8.3 / Laravel 11.x** - Routing, Eloquent, queued jobs, scheduler, first-class testing.
  Exact versions pinned at install time, `composer.lock` committed.
- **PostgreSQL 16, required everywhere including tests** - Row-level security is the core of
  the product; a test suite on SQLite would skip the very thing being sold. Trade-off: heavier
  local setup, mitigated by documented one-command role/database creation.
- **Dedicated database role** - The app connects as `tenantbase_app` (`LOGIN NOSUPERUSER
  NOBYPASSRLS`). Migrations run as the same role; owned tables still obey policies because
  every tenant-owned table sets `FORCE ROW LEVEL SECURITY`. A superuser connection bypasses
  RLS silently, so the launch checklist and a guard test probe for it.
- **Database drivers for queue, session, cache** - One PostgreSQL instance is the whole
  deployment. `SESSION_DOMAIN=.APP_DOMAIN` so one login spans central and tenant subdomains.
- **Laravel Sanctum (bundled)** - API tokens belong to users, not tenants; the tenant comes
  from resolution plus a per-request membership check.
- **Blade + one static CSS file** (no Node toolchain, see `docs/design.md`); **Pest on
  PHPUnit** against the real PostgreSQL test database; **Laravel Pint** for PSR-12.

## Tenant context lifecycle

The context is a pair: the PHP-side binding (`Tenancy::$tenant`) and the database-side GUC
(`app.tenant_id`). They are only ever set together, by exactly one code path,
`Tenancy::activate(Tenant $t)`, and cleared together by `Tenancy::deactivate()`.

**Why SET LOCAL and a per-request transaction.** A session-level `SET` survives the request
and, behind a connection pooler in transaction mode (pgbouncer), leaks to whatever client
borrows the connection next: a catastrophic cross-tenant bug. A transaction-scoped setting
(`set_config(name, value, is_local => true)`) vanishes at commit or rollback, which makes
pooling safe by construction, but requires an open transaction. So `SetTenantContext` begins
one transaction per request, sets the GUC inside it, and commits after the response is built
(rollback on exception). Consequences, accepted and documented:

- Streaming responses and long-polling endpoints cannot run under tenant context (they would
  hold a transaction open); none exist in this app.
- Application code never manages top-level transactions; nested `DB::transaction()` calls
  become savepoints, which is fine (rolling back a savepoint also rolls back a `set_config`
  made inside it).
- A dropped connection mid-request loses the transaction and the GUC: reconnected queries hit
  closed RLS and return zero rows (fail closed, not fail leaky), and `Tenancy::assertContext()`
  in the trait's scope turns that into a loud `MissingTenantContext` instead of an empty page.

**Setting the GUC is parameterized**: `select set_config('app.tenant_id', ?, true)` with the id
bound as a string; never string-interpolated `SET LOCAL`, which cannot take bind parameters.
`activate()` asserts `transactionLevel() > 0` first, because SET LOCAL semantics outside a
transaction silently do nothing.

**Queue workers** have no HTTP request. Dispatching inside tenant context stamps the job
payload with `tenant_id` (the `TenantAware` trait captures it in the constructor; dispatching
without context throws at dispatch time, not silently in the worker). The
`RestoreTenantContext` job middleware then: loads the tenant, fails the job permanently (no
retry, `tenancy.job_orphaned` log) if missing or suspended, opens a transaction, activates,
runs the job, commits or rolls back, deactivates. **Artisan**: `tenant:run {tenant} {command}`
wraps any artisan command in the same activate-in-transaction envelope; the wrapped command
holds one transaction for its whole run, acceptable for operator tasks.

## The three nets

1. **Eloquent global scope** (`BelongsToTenant`): adds `where tenant_id = ?` to every query,
   fills `tenant_id` on create, and throws `MissingTenantContext` when queried with no active
   tenant and no explicit bypass; fail loud beats returning someone's empty dashboard.
2. **Row-level security**: the database re-checks every row against `app.tenant_id`. Reads
   outside the context see nothing; writes with a mismatched `tenant_id` raise an error.
3. **Route-model-binding check**: the trait overrides `resolveRouteBinding()` to assert the
   resolved model's `tenant_id` equals the active tenant, throwing 404 on mismatch. This
   catches the day someone disables the scope for one query and forgets a binding.

## Canonical RLS policy

Every tenant-owned table gets this exact block in the same migration that creates it, with only
the table name substituted. The SQL is **inlined verbatim in each migration**, never generated
by a shared helper, so editing a helper can never retroactively change what an applied
migration did.

```sql
ALTER TABLE projects ENABLE ROW LEVEL SECURITY;
ALTER TABLE projects FORCE ROW LEVEL SECURITY;

CREATE POLICY tenant_isolation ON projects
    USING (
        tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint
        OR current_setting('app.tenancy_bypass', true) = '1'
    )
    WITH CHECK (
        tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint
    );
```

Notes that make this correct:

- `current_setting(..., true)` (missing_ok) returns NULL when unset; `NULLIF(..., '')` also
  maps the reset value to NULL. NULL comparison is false: **no context means no rows**.
- `WITH CHECK` has **no bypass clause**: even inside `withoutTenancy()` an INSERT or UPDATE
  must match a real tenant context, so the escape hatch is effectively read-only for tenant
  data (DELETE is only guarded by USING; deletes inside a bypass are forbidden by rule).
- `FORCE` makes the table owner obey the policy, so migrations and app share one role safely.

`withoutTenancy(string $reason, Closure $fn)` opens a transaction if none is active, sets
`app.tenancy_bypass = '1'` (transaction-scoped), disables the Eloquent scope for the closure,
logs `tenancy.bypass` with reason and caller, and restores everything in a `finally`. The
method name is the grep target for audits; sanctioned call sites are listed in `docs/rules.md`.

## Data model

Tables named here are the contract; the coding agent must not rename them. FKs to `tenants.id`
are `ON DELETE CASCADE`. "RLS" marks tenant-owned tables carrying the canonical policy.

### tenants (landlord, no RLS)
| Field | Type | Notes |
|---|---|---|
| id | bigint PK | the value stored in `app.tenant_id` |
| name | string | display name |
| slug | string(32), unique | subdomain label: `[a-z0-9][a-z0-9-]*`, 3-32 chars, reserved list in config |
| plan | string, default `free` | key into `config/plans.php`; no billing attached |
| suspended_at | datetime, nullable | suspended tenants resolve to 403; their jobs fail permanently |
| timestamps | | |

### users (landlord, no RLS)
`id` bigint PK, `name` string, `email` string unique (global identity; one account, many
tenants), `password` hashed string, timestamps.

### memberships (RLS)
| Field | Type | Notes |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint FK → tenants.id | |
| user_id | bigint FK → users.id, cascade | |
| role | string enum | `owner` \| `admin` \| `member` |
| timestamps | | |

Indexes: unique `(tenant_id, user_id)`; `(user_id)` for the tenant picker (a sanctioned
`withoutTenancy` read). Invariant: every tenant has at least one `owner` row at all times.

### invitations (RLS)
| Field | Type | Notes |
|---|---|---|
| id | bigint PK | |
| tenant_id | bigint FK → tenants.id | |
| email | string | invitee address; may not yet be a user |
| role | string enum | `admin` \| `member` (owners are promoted, never invited) |
| token_hash | string(64), unique | sha256 of the raw token; raw token exists only in the email link |
| invited_by | bigint FK → users.id, null on delete | |
| expires_at | datetime | now + `INVITATION_TTL_DAYS` |
| accepted_at | datetime, nullable | terminal; accepted rows kept for audit |
| timestamps | | |

Index: partial unique `(tenant_id, email) WHERE accepted_at IS NULL` - one live invitation per
address per tenant; re-inviting revokes the old row first.

### projects (RLS) - the demo tenant resource
`id` bigint PK, `tenant_id` bigint FK → tenants.id, `name` string, `description` text nullable,
`created_by` bigint FK → users.id (null on delete), timestamps. Indexes: unique
`(tenant_id, name)`; `(tenant_id, created_at)` for the index screen.

### usage_counters (RLS) - live gauges, the enforcement table
`id` bigint PK, `tenant_id` bigint FK → tenants.id, `resource` string enum (`projects` |
`members`), `value` bigint default 0 with check constraint `value >= 0`. Index: unique
`(tenant_id, resource)`. Rows are provisioned at tenant creation (`projects` 0, `members` 1),
so the guarded UPDATE below can rely on the row existing.

### usage_rollups (RLS) - monthly history plus live api_calls quota
`id` bigint PK, `tenant_id` bigint FK → tenants.id, `resource` string enum (`api_calls` |
`projects` | `members`), `period` date (first day of month, UTC), `value` bigint default 0.
Index: unique `(tenant_id, resource, period)`. `api_calls` rows are incremented live by the
metering middleware; gauge rows are snapshots written by the scheduled `usage:rollup` command.
Two tables instead of one nullable-period table: it avoids `NULLS NOT DISTINCT` tricks and
keeps gauge semantics (increment/decrement) separate from append-only history.

Framework tables (no tenant_id): `sessions`, `cache`, `jobs`, `failed_jobs`,
`personal_access_tokens`, `password_reset_tokens`.

## Usage metering: race-safe by construction

Limits come from `config/plans.php` (`null` = unlimited, guard skipped). Enforcement is one SQL
statement each, so two concurrent requests cannot both take the last slot.

**Gauges** (`projects`, `members`) - inside the same transaction as the row being created:

```sql
UPDATE usage_counters SET value = value + 1
 WHERE tenant_id = ? AND resource = ? AND value < ?   -- ? = plan limit
```

Zero affected rows means the limit is reached (or the counter row is missing, a
provisioning-invariant violation, logged as such): the request fails with `plan_limit_reached`
and rolls back. The row lock taken by the UPDATE serializes concurrent creators. Deletion
decrements with the mirror-image guarded UPDATE (`AND value > 0`) in the deleting transaction.

**Monthly quota** (`api_calls`) - one upsert in the metering middleware:

```sql
INSERT INTO usage_rollups (tenant_id, resource, period, value)
VALUES (?, 'api_calls', ?, 1)
ON CONFLICT (tenant_id, resource, period)
DO UPDATE SET value = usage_rollups.value + 1
 WHERE usage_rollups.value < ?                        -- ? = plan quota
RETURNING value
```

A returned row is the new count (exposed as `X-Quota-Remaining`); no row means the quota is
exhausted and the API returns 429. The insert arm handles the first call of a month with no
separate existence check. Drift can only come from bugs or manual SQL (the guarded updates
share their row-change transaction), so `tenant:usage --reconcile` recounts gauges from the
real tables in tenant context and corrects the counter, logging `usage.reconciled`.

## Key flows

**Signup / provisioning** (central domain, authenticated user):
1. `POST /tenants` validates name and slug (format, reserved list).
2. One transaction: insert the `tenants` row (landlord, no context needed); a slug
   unique-violation is caught and re-rendered as a validation error (the concurrent-signup race).
3. Still inside it, `Tenancy::activate($tenant)`, then insert the owner `memberships` row and
   both `usage_counters` rows: these pass RLS `WITH CHECK` because the context now matches.
4. Commit, deactivate, redirect to `https://{slug}.APP_DOMAIN/`. Any failure rolls back the
   whole set: no tenant without an owner, no tenant without counters.

**Invitation accept**:
1. Owner/admin submits email + role; any live invitation for that address is revoked first; a
   40-char random token is generated, its sha256 stored, and the raw token mailed as
   `https://{slug}.APP_DOMAIN/invitations/{token}`.
2. The link resolves the tenant by subdomain; the accept route sits behind tenant context but
   **outside** `EnsureMembership` (the invitee is not a member yet). Login or register first.
3. Hash the presented token, look it up under RLS; expired or already accepted → 410; email
   mismatch with the logged-in user → 403.
4. In the request transaction: guarded `members` gauge increment, insert membership, stamp
   `accepted_at`. A full tenant shows the friendly limit message.

**API call with metering**: Sanctum authenticates the bearer token; `ResolveTenant` picked the
tenant (subdomain or `X-Tenant`); `EnsureMembership` confirms membership; `MeterApiCalls` runs
the quota upsert (429 `api_quota_exceeded` when exhausted, `X-Quota-Remaining` on success). The
increment shares the request transaction, so a request that 500s rolls its increment back: the
counted call is the one that produced a response.

**Queued job**: dispatch in tenant context → payload carries `tenant_id` → worker middleware
verifies the tenant exists and is not suspended → transaction + activate → `handle()` → commit
→ deactivate. Missing/suspended tenant: fail permanently, log `tenancy.job_orphaned`.

**Landlord read** (tenant picker): after central login, list the user's memberships across
tenants via `Tenancy::withoutTenancy('tenant picker: list memberships for user', ...)`.
Read-only by rule and by `WITH CHECK`.

## Failure modes

| Failure | Handling |
|---|---|
| Forgotten `BelongsToTenant` on a model / raw SQL query | RLS filters reads to the active tenant, blocks context-less reads entirely, rejects mismatched writes. Data does not leak; behavior degrades to missing rows plus a loud test failure. |
| Query on a tenant model with no active context | `MissingTenantContext` thrown by the scope (500, logged), not an empty result that looks like real data. |
| `set_config` attempted with no open transaction | `Tenancy::activate()` asserts `transactionLevel() > 0` and throws; SET LOCAL semantics would otherwise silently no-op and leave RLS closed. |
| Connection pooler in transaction mode | Safe by design: the GUC is transaction-scoped and dies with the transaction; session-level `SET` is banned by rule. |
| DB connection dropped mid-request | Transaction and GUC are gone; reconnected queries hit closed RLS (zero rows) and the scope's assert turns that into an explicit error. Request fails; nothing leaks. |
| App code commits the request transaction early | Banned by rule (no top-level transaction control in app code); nested `DB::transaction` uses savepoints, which preserve the outer GUC. |
| Job for a deleted or suspended tenant | Job middleware fails the job permanently with `tenancy.job_orphaned`; no retry storm against a dead tenant. |
| Two concurrent creates at the limit | Guarded UPDATE serializes on the counter row; exactly `limit` rows exist afterward. Proven by a concurrency test. |
| Two concurrent signups, same slug | Unique index on `slug`; loser gets a validation error, one tenant exists. |
| Invitation token guessed / replayed / expired | 40-char random token, hashed at rest, single-use, TTL enforced, accept route IP-throttled; all failures return 410/403 without revealing which check failed. |
| Cache collision across tenants | Tenant cache keys are prefixed `t{id}:` via `Tenancy::cache()`; rule: tenant data never goes through the unprefixed store. |
| Hot counter row contention (one row per tenant/month) | Accepted at this scale; documented; sharded counter rows are backlog, not speculatively built. |
| Suspended tenant | Resolution returns 403, jobs fail permanently, scheduler skips it; data is retained untouched. |

## Correctness invariants

1. Every tenant-owned table has `tenant_id bigint not null` FK, the canonical RLS policy with
   `ENABLE` + `FORCE`, and a composite index leading with `tenant_id`.
2. `app.tenant_id` is only ever written by `Tenancy::activate()` / `deactivate()`, always via
   parameterized `set_config(..., true)`, always inside an open transaction; one activation
   path serves HTTP, queue, and artisan.
3. `withoutTenancy()` is the only bypass, requires a reason, always logs, and cannot write
   tenant rows (no bypass clause in `WITH CHECK`).
4. Usage limit checks and the rows they guard commit or roll back atomically; counters are
   adjusted only by the guarded statements and reconcile.
5. Provisioning is atomic: tenant + owner membership + gauge counters, or nothing.
6. Every tenant always has at least one owner; the last owner cannot be demoted or removed.
7. The database role in every environment (including CI) is `NOSUPERUSER NOBYPASSRLS`; the
   isolation suite fails if run as a role that bypasses RLS.

## Proposed folder / file tree

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Auth/{RegisterController,LoginController}.php     # central domain
│   │   ├── {TenantPickerController,TenantSignupController}.php   # central: picker, create
│   │   ├── {DashboardController,ProjectController}.php       # tenant home, web CRUD
│   │   ├── {MemberController,InvitationController}.php       # roles/remove, invite/revoke/accept
│   │   ├── UsagePageController.php
│   │   └── Api/{ProjectController,UsageController,MeController}.php
│   └── Requests/                                             # all validation lives here
├── Models/{User,Tenant,Membership,Invitation,Project}.php
├── Policies/{ProjectPolicy,MembershipPolicy,InvitationPolicy}.php
└── Mail/InvitationMail.php

src/Tenancy/                                # the internal package, namespace TenantBase\Tenancy
├── TenancyServiceProvider.php              # middleware aliases, commands, singleton binding
├── Tenancy.php                             # activate/deactivate/id/tenant/runAs/withoutTenancy/cache/assertContext
├── BelongsToTenant.php                     # scope + creating hook + binding check
├── TenantScope.php
├── UsageMeter.php                          # guarded increment/decrement/quota statements
├── Http/Middleware/{ResolveTenant,SetTenantContext,EnsureMembership,MeterApiCalls}.php
├── Jobs/{TenantAware.php,RestoreTenantContext.php}
├── Console/{CreateTenantCommand,RunInTenantCommand,TenantUsageCommand,UsageRollupCommand,
│            SuspendTenantCommand,UnsuspendTenantCommand}.php
└── Exceptions/{MissingTenantContext,TenantMismatch,PlanLimitExceeded}.php

config/{tenantbase.php, plans.php}          # domain, reserved slugs, invite ttl; plan limits
database/{migrations, factories, seeders}/  # every tenant-owned table + its inlined RLS block
routes/{web.php, tenant.php, api.php}       # central; subdomain group; /api/v1
tests/{Feature, Unit}/                      # incl. IsolationProofTest (raw PDO, no Eloquent)
```

## External dependencies and required env vars

External runtime services: PostgreSQL 16 and SMTP for invitation mail (`log` mailer locally).
Production runs web, `queue:work`, and a cron entry for `schedule:run` (monthly rollup).

| Variable | Purpose |
|---|---|
| `APP_KEY` / `APP_URL` / `APP_DEBUG` | Standard; `APP_DEBUG=false` outside local. |
| `APP_DOMAIN` | Central domain; tenants live at `{slug}.APP_DOMAIN`. |
| `DB_*` | PostgreSQL, connecting as the `tenantbase_app` role (NOSUPERUSER, NOBYPASSRLS). |
| `SESSION_DOMAIN` | `.APP_DOMAIN` so the session spans subdomains. |
| `SESSION_DRIVER` / `QUEUE_CONNECTION` / `CACHE_STORE` | `database`. |
| `MAIL_*` | Invitation mail; `log` locally. |
| `INVITATION_TTL_DAYS` | Invitation validity window (default 7). |

Config is read via `config/tenantbase.php` and `config/plans.php`; no `env()` outside `config/`.
