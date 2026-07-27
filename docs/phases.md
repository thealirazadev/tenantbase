# Phases - tenantbase

**Rule: phase N+1 does not start until the owner approves phase N.** Each phase ends green: app
runs, tests pass on PostgreSQL, Pint clean, logs show only documented keys. One commit per
feature/task, Conventional Commits, in the listed order.

The senior differentiators are hard requirements placed early: RLS with the per-request
transaction and the raw-SQL isolation proof land in Phase 1, not as a hardening afterthought.
Race-safe metering lands complete in Phase 4. None of these may slip.

---

## Phase 1 - Foundation: PostgreSQL, tenants, context, and the isolation proof

**Scope**: A user can register centrally, create a tenant, and reach its subdomain dashboard.
Tenant context is established per request (transaction + `set_config`), `memberships` is under
RLS, and a raw-SQL test suite proves the database blocks what Eloquent never even attempts.

### Tasks / definition of done

- App scaffolded on PHP 8.3 / Laravel 11; PostgreSQL for dev and tests; `database` drivers for
  queue/session/cache; `.env.example` current; `config/tenantbase.php` and `config/plans.php`
  hold all settings (limits included, even though enforcement waits for Phase 4).
- Documented database setup: `tenantbase_app` role (`LOGIN NOSUPERUSER NOBYPASSRLS`), dev and
  test databases owned by it; README carries the exact psql commands.
- Central-domain auth: register, login (IP-throttled), logout; session cookie on `.APP_DOMAIN`.
- Migrations: `tenants` (landlord), `memberships` with the canonical inlined RLS block.
- `src/Tenancy` package: service provider, `Tenancy` singleton with
  `activate`/`deactivate`/`assertContext`/`runAs`/`withoutTenancy` (transaction assertion,
  parameterized `set_config`, bypass GUC, `tenancy.bypass` logging).
- Middleware pipeline on the subdomain group: `ResolveTenant` (subdomain; unknown 404,
  suspended 403), `SetTenantContext` (begin/commit/rollback + GUC + reset in finally),
  `EnsureMembership` (403 for non-members).
- Signup flow: `POST /tenants` provisions tenant + owner membership + both gauge counter rows
  atomically (counters exist now; enforcement comes later); slug unique-violation mapped to a
  validation error; redirect to the new subdomain.
- Tenant picker on the central domain via the sanctioned `withoutTenancy` membership read.
- `tenant:create {name} {--slug=} {--plan=} {--owner-email=}` provisions identically to signup.
- `IsolationProofTest`: raw PDO (no Eloquent) proves context A sees only A's membership rows,
  no context sees zero rows, and inserts/updates with mismatched `tenant_id` throw; a guard
  test fails loudly if the connected role is superuser or `BYPASSRLS`.

### Verification checklist

- `php artisan test` green on PostgreSQL; `./vendor/bin/pint --test` clean.
- Manual: register → create tenant `acme` → land on `acme.APP_DOMAIN` dashboard; second user
  visiting `acme.APP_DOMAIN` gets 403; `nosuchtenant.APP_DOMAIN` gets 404; duplicate slug in a
  second signup gets a field error; reserved slug (`www`, `api`) rejected.
- psql as `tenantbase_app`: `BEGIN; SELECT set_config('app.tenant_id','1',true); SELECT * FROM
  memberships;` shows tenant 1 only; without the `set_config`, zero rows; an `INSERT` with a
  wrong `tenant_id` errors. `\d memberships` shows the policy and FORCE.
- Kill the DB connection mid-request (restart PostgreSQL): request 500s, log shows
  `tenancy.context_missing` or a transaction error, nothing leaks, next request is healthy.
- Logs during the manual run show only `tenancy.resolved`, `tenancy.bypass` (picker),
  `tenant.provisioned`, `auth.login_failed` where expected.

### Commits

1. `chore: scaffold laravel app with postgres and database queue`
2. `chore: add env example and tenantbase config`
3. `feat: add user registration and login`
4. `feat: add tenants migration and model`
5. `feat: add tenancy context manager`
6. `feat: add memberships migration with rls policy`
7. `feat: add tenant resolution middleware`
8. `feat: add tenant context transaction middleware`
9. `feat: add membership gate middleware`
10. `feat: add atomic tenant provisioning and signup`
11. `feat: add tenant picker on central domain`
12. `feat: add tenant create command`
13. `test: prove rls isolation with raw sql probes`

---

## Phase 2 - Tenant model layer: BelongsToTenant, projects, roles

**Scope**: The Eloquent net and the binding net join the RLS net. Projects become the template
tenant resource with full web CRUD behind role policies.

### Tasks / definition of done

- `BelongsToTenant` trait: global scope filtering by `Tenancy::id()`, `MissingTenantContext`
  when no context and no bypass, `tenant_id` auto-fill on create (never fillable from input),
  `resolveRouteBinding` tenant assertion (404 on mismatch).
- `projects` migration with the canonical RLS block, unique `(tenant_id, name)`, model,
  factory requiring an explicit tenant, added to the isolation proof matrix.
- Project CRUD screens (index, create, edit, delete) per `docs/design.md`; duplicate name per
  tenant maps to a field error.
- Policies: `ProjectPolicy` (all roles create/edit; delete owner/admin), `MembershipPolicy`
  (role changes and removal per the matrix; last-owner demotion/removal blocked at the
  mutation path).
- Members index screen: name, email, role, joined date.
- Tests: scope filters and auto-fill; `MissingTenantContext` on context-less queries; binding
  returns 404 for another tenant's project id even with the scope artificially disabled;
  policy matrix; last-owner invariant.

### Verification checklist

- Suite green, Pint clean. Two seeded tenants sharing one user: switching subdomains switches
  the visible projects completely.
- Manual: guess another tenant's project id in the URL → 404. Create two projects with the
  same name in one tenant → field error; same name across tenants → allowed.
- Member (role `member`) cannot see delete controls and gets 403 on a forged delete POST.
- Attempt to demote the only owner → friendly error, role unchanged.
- Isolation proof still green with `projects` in the matrix.

### Commits

1. `feat: add belongs to tenant trait with guarded scope`
2. `feat: add projects migration with rls policy`
3. `feat: add project crud screens`
4. `feat: enforce tenant match in route model binding`
5. `feat: add role policies and last owner guard`
6. `feat: add members index screen`
7. `test: cover scope binding roles and last owner`

---

## Phase 3 - Invitations

**Scope**: Owners and admins grow the team by email; tokens are hashed, expiring, single-use.

### Tasks / definition of done

- `invitations` migration with the canonical RLS block and the partial unique index
  `(tenant_id, email) WHERE accepted_at IS NULL`; model; factory.
- Create/revoke flows: invite form (email + role `admin`/`member`); re-inviting an address
  revokes the live invitation first; revoke action for pending invitations; pending list with
  expiry shown.
- `InvitationMail` with the accept URL; `log` mailer locally; send failure caught, logged, and
  surfaced as a retryable flash error (invitation row still exists; resend re-uses a new token).
- Accept flow on the tenant subdomain, outside `EnsureMembership`, IP-throttled: login or
  register first, then token hash lookup under RLS; expired/used → 410; email mismatch → 403;
  acceptance creates the membership in the request transaction and stamps `accepted_at`.
  (Member gauge increment joins in Phase 4; the code path is shaped for it now.)
- Tests: lifecycle (invite → mail → accept → member), revoke, expiry boundary, double-accept,
  wrong-account accept, re-invite replacing pending, partial unique index race (two concurrent
  invites, one row).

### Verification checklist

- Suite green, Pint clean. Manual: invite a fresh address → log mailer shows the link → open
  it in a private window → register → membership appears with the invited role.
- Accept the same link again → 410. Craft a random token → 410, throttled after repeats.
- Invite the same address twice → one pending row; revoke it → link dies.
- Logs show `invite.sent`, `invite.accepted`, `invite.revoked` with tenant ids.

### Commits

1. `feat: add invitations migration with rls policy`
2. `feat: add invitation model with hashed token`
3. `feat: send invitation mail`
4. `feat: add invite create revoke and pending list`
5. `feat: add invitation accept flow`
6. `test: cover invitation lifecycle and races`

---

## Phase 4 - Plans, race-safe metering, and the JSON API

**Scope**: Plan limits become real, enforced by single guarded statements, and the API ships
with Sanctum auth, header resolution, and the monthly quota.

### Tasks / definition of done

- `usage_counters` and `usage_rollups` migrations with RLS blocks, unique indexes, and the
  `value >= 0` check constraint; models.
- `UsageMeter`: guarded gauge increment/decrement and the quota upsert, exactly the SQL in
  `docs/architecture.md`; `PlanLimitExceeded` mapped to a friendly 422 (web flash / envelope).
- Enforcement wired in: project create/delete and membership create/remove (signup, invitation
  accept, member removal) run through the meter in their transactions; `null` limit skips.
- API (`/api/v1`): Sanctum bearer auth; `ResolveTenant` honors `X-Tenant` on the central
  domain for API routes only; mismatch with a subdomain → 400. Endpoints: `me`, `usage`,
  projects CRUD. Personal access tokens issued from a settings screen.
- `MeterApiCalls` middleware: quota upsert per request, 429 `api_quota_exceeded` when
  exhausted, `X-Quota-Remaining` on success.
- Usage page (web) and `GET /api/v1/usage`: gauges and current-month api_calls against limits.
- `tenant:usage {tenant?} {--reconcile}` and the scheduled monthly `usage:rollup` snapshot
  command (gauges into last month's rows), both logged.
- Tests: K-over-limit concurrency (parallel transactions on one counter row), quota boundary
  (999/1000/1001), month rollover creating a fresh row, rollback-on-500 not billing a call,
  reconcile fixing seeded drift, `X-Tenant` resolution and mismatch, envelope shapes.

### Verification checklist

- Suite green, Pint clean. Manual on the free plan (projects 3): create 3 projects, the 4th
  shows the limit message; delete one, create succeeds.
- Two parallel curl loops creating projects at the limit: total rows never exceed the limit.
- API: token via settings screen; `curl -H "Authorization: Bearer ..." -H "X-Tenant: acme"
  APP_DOMAIN/api/v1/projects` works; quota exhausted (temporarily set the plan quota to 3) →
  429 envelope; `X-Quota-Remaining` counts down.
- `tenant:usage acme` matches the database; corrupt a counter by hand, `--reconcile` fixes it
  and logs before/after.

### Commits

1. `feat: add usage counters migration with rls policy`
2. `feat: add usage rollups migration with rls policy`
3. `feat: add usage meter with guarded statements`
4. `feat: enforce project and member limits at creation`
5. `feat: add sanctum api with header tenant resolution`
6. `feat: meter api calls with monthly quota`
7. `feat: add usage page and api usage endpoint`
8. `feat: add tenant usage command with reconcile`
9. `feat: add scheduled monthly usage rollup`
10. `test: cover limit races quota boundaries and reconcile`

---

## Phase 5 - Tenant-aware queues, cache, tooling, and hardening

**Scope**: Background work and cache honor tenancy; operators get `tenant:run` and suspension;
the README becomes real.

### Tasks / definition of done

- `TenantAware` job trait (captures `Tenancy::id()` at construction; throws if dispatched
  without context) + `RestoreTenantContext` job middleware (verify tenant exists and is not
  suspended → transaction + activate → run → commit → deactivate; orphaned jobs fail
  permanently with `tenancy.job_orphaned`). A demo queued job (project export to storage)
  exercises it end to end.
- `Tenancy::cache()`: repository proxy prefixing keys `t{id}:`; used by the dashboard usage
  tiles; rule added to tests (two tenants, same logical key, different values).
- `tenant:run {tenant} {command*}` wraps artisan commands in `runAs`; documented caveat: the
  wrapped command holds one transaction.
- Suspension: `tenant:suspend` / `tenant:unsuspend` artisan commands set/clear `suspended_at`;
  resolution 403s, jobs orphan, scheduler skips; log `tenant.suspended`.
- Bypass audit: `tenancy.bypass` log includes caller file/line; a test greps the codebase and
  asserts every `withoutTenancy(` call site is in the sanctioned list from `docs/rules.md`.
- README finalized (real install/run/test, psql role setup, wildcard DNS note); branded 404/403
  pages; `docs/testing.md` commands verified as written.
- Tests: job context restoration (worker sees only its tenant), orphaned job (suspended and
  deleted tenant), dispatch-without-context failure, cache prefix isolation, suspension flows.

### Verification checklist

- App + `queue:work` + `schedule:work` run cleanly; full suite green; Pint clean.
- Manual: dispatch the export job from `acme`; the artifact contains only acme's projects.
  Suspend `acme` with a job queued: the job fails permanently with `tenancy.job_orphaned`;
  `acme.APP_DOMAIN` returns 403; unsuspend restores access.
- `tenant:run acme db:seed --class=DemoSeeder` seeds only acme (verify from a second tenant).
- Grep audit test passes; every bypass log line carries a reason.
- Full manual keyboard pass per `docs/design.md`; empty states on all indexes.

### Commits

1. `feat: add tenant aware job trait and context middleware`
2. `feat: add demo project export job`
3. `feat: add tenant scoped cache`
4. `feat: add tenant run command`
5. `feat: add tenant suspension commands and handling`
6. `feat: audit bypass call sites in tests`
7. `docs: finalize readme and testing commands`
8. `test: cover jobs cache and suspension`

---

## Backlog

- Tenant deletion (`tenant:delete` with export + grace period) - destructive; needs an owner
  decision on data retention first.
- Ownership transfer flow - low demand until real users ask.
- Sharded api_calls counter rows - only if a real tenant shows counter-row contention.
- Plan change UI (`tenant:plan` command first) - plans are operator-managed data in v1.
- Per-tenant database connection docs for read replicas - out of scope for single-box v1.
