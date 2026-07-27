# Engineering Rules - tenantbase

These rules are binding for every change in this repository and extend the workspace CLAUDE.md.

## Tenancy rules (the ones that keep isolation true)

- **Every tenant-owned table** ships `tenant_id bigint not null` FK, the canonical RLS block
  from `docs/architecture.md` (ENABLE + FORCE + the `tenant_isolation` policy), and a composite
  index leading with `tenant_id`, all **in the same migration** that creates the table. A
  tenant-owned table without RLS never exists, not even between two commits.
- **RLS SQL is inlined verbatim** in each migration, copy-pasted from the canonical block with
  only the table name changed. No shared helper, no loop over table names: a helper edit must
  never be able to change what an applied migration did.
- **One activation path**: only `Tenancy::activate()`/`deactivate()` touch `app.tenant_id`,
  always via parameterized `set_config(..., true)` inside an open transaction. No `SET` or
  `SET LOCAL` statements anywhere in app code; no session-level GUCs, ever (pooling safety).
- **No top-level transaction control in app code**: never `DB::commit()`, `DB::rollBack()`, or
  `DB::beginTransaction()` outside the tenancy middleware and job middleware. Use nested
  `DB::transaction()` closures, which become savepoints under the request transaction.
- **`withoutTenancy()` discipline**: requires a non-empty reason string, always logs
  `tenancy.bypass`, and is read-only for tenant data (enforced by `WITH CHECK`; deletes inside
  a bypass are forbidden by this rule). Sanctioned call sites: the tenant picker membership
  list, `tenant:usage` cross-tenant summary, and the usage rollup command's tenant iteration.
  Adding a call site needs owner approval and a docs update in the same commit.
- **New tenant-owned models** use `BelongsToTenant`, get a factory that requires an explicit
  tenant, and are added to the isolation proof test matrix in the same commit.
- **Landlord tables** (`tenants`, `users`, framework tables) never gain a `tenant_id`; anything
  else that gets one is tenant-owned and follows all rules above.
- **Counted resources**: creation and deletion of `projects` and `memberships` rows go through
  `UsageMeter`'s guarded statements in the same transaction, never through a separate
  check-then-insert. No other code path writes `usage_counters` or `usage_rollups` except
  reconcile and the rollup command.

## Conventions

- **Framework patterns**: controllers thin; validation in Form Requests; authorization in
  Policies; tenancy mechanics only in `src/Tenancy` (app code calls the facade-like `Tenancy`
  singleton, never `set_config` or scope internals directly).
- **Package boundary**: `src/Tenancy` (namespace `TenantBase\Tenancy`) must not reference app
  models except through configurable class names; the app depends on the package, never the
  reverse.
- **PostgreSQL only**: no driver-agnostic hedging and no SQLite paths; PostgreSQL features
  (partial indexes, `ON CONFLICT ... WHERE`, RLS) are used deliberately. Tests run on
  PostgreSQL, full stop.
- **Naming (PSR-12 + Laravel)**: models singular PascalCase; tables plural snake_case; the
  pivot-with-meaning is the `Membership` model on `memberships` (never `tenant_user`); roles
  are the lowercase strings `owner`, `admin`, `member`.
- **Commit format**: Conventional Commits, short imperative subject, lowercase after the
  prefix, e.g. `feat: add guarded gauge increment`. One commit per feature/task in the order
  listed in `docs/phases.md`; never batch features, never fragment one small feature.
- **Pin exact dependency versions**; `composer.lock` committed. Any dependency change is its
  own commit and needs approval first.
- **DB migration rule**: every schema change is a migration; applied migrations are never
  edited; model `$fillable`/`$casts` changes ship with the migration that introduces columns.

## Error handling & logging

- **Every fallible call handles failure**: database writes that can hit unique violations
  (slug, membership, invitation) catch and map them to validation errors; mail send failures
  are caught, logged, and surfaced as a friendly retryable error; job middleware failures fail
  the job explicitly.
- **Friendly user errors vs detailed logs**: users see envelope codes or flash messages; logs
  get tenant id, user id, exception class, never tokens, never passwords. No stack traces to
  users; `APP_DEBUG=false` outside local.
- **One JSON error envelope** (see `docs/api-contracts.md`):
  `{ "error": { "code": "...", "message": "..." } }` for every API and resolution error.
- **Structured logging with fixed keys**: `tenancy.resolved`, `tenancy.bypass`,
  `tenancy.job_orphaned`, `tenancy.context_missing`, `usage.limit_reached`,
  `usage.quota_exceeded`, `usage.reconciled`, `usage.rollup_completed`, `tenant.provisioned`,
  `tenant.suspended`, `invite.sent`, `invite.accepted`, `invite.revoked`, `auth.login_failed`.
  Context arrays always include `tenant_id` when a tenant is involved.

## Security

- **No hardcoded secrets**; `.env` git-ignored, `.env.example` carries dummies. Invitation
  tokens are stored as sha256 hashes; raw tokens exist only in the outbound email.
- **Database role**: every environment connects as a `NOSUPERUSER NOBYPASSRLS` role. Never
  develop or test against a superuser connection; the isolation suite asserts this.
- **Validate all input server-side** via Form Requests: slugs against format and the reserved
  list, roles against the enum, emails, pagination and filter params. Never trust the client
  for `tenant_id`: it is derived from context, never from request input, and is never fillable
  from a request payload.
- **Authorization is layered**: `EnsureMembership` gates the tenant group; Policies gate
  per-action role checks; the last-owner invariant is enforced in the mutation code path, not
  just hidden in the UI.
- **Rendering**: all user-entered strings (tenant names, project names, invite emails) are
  Blade-escaped; no `{!! !!}` for user data.
- **Rate limiting**: login and invitation-accept throttled by IP; API throttled per token in
  addition to the plan quota.
- **Queries**: Eloquent or parameterized `DB::select`/`DB::statement` only. The metering SQL
  binds every value.

## Simplicity / YAGNI-KISS

- Build only what the current phase requires. No multi-database abstraction, no plan-change UI,
  no billing stubs, no speculative "tenant features" flags.
- Prefer the framework mechanism (policies, form requests, job middleware, scheduler) over
  hand-rolled infrastructure; the Tenancy package exists only because three consumers (HTTP,
  queue, artisan) share it.
- If a solution exceeds ~150 lines, pause and justify before continuing.

## Boundaries - never do without asking the owner first

- No wholesale delete/rewrite of working files; targeted edits, destructive changes flagged.
- Do not change `docs/PRD.md` or `docs/architecture.md` without flagging and sign-off.
- No new dependency without approval (what, why, version, size).
- Ask when ambiguous; stop after two failed fix attempts and report instead of thrashing.
- Scope discipline: mid-phase requests not in the PRD get classified with the owner as current
  phase, new phase, or Backlog in `docs/phases.md`. Never silently absorb scope.
