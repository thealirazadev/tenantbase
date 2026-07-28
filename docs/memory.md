# Project Memory - tenantbase

Running log of what is done, in progress, and decided. Update after every meaningful chunk of
work; log every non-obvious decision with its reason. Keep entries short and dated.

## Completed

- 2026-07-27 - Planning documentation created (README, PRD, architecture, rules, phases,
  design, testing, api-contracts, launch-checklist, memory). No code yet; implementation
  follows `docs/phases.md` after owner review.
- 2026-07-28 - Phase 1 implemented in the 13 commits listed in `docs/phases.md`, plus two
  follow-up commits (see the decisions log). Delivered: Laravel app on PostgreSQL with
  database drivers for queue, session and cache; `config/tenantbase.php` and `config/plans.php`;
  central-domain register, login (IP throttled, 5 per minute) and logout; `tenants` (landlord)
  and `memberships` (row-level security) migrations; the `TenantBase\Tenancy` package with the
  `Tenancy` singleton (`activate`, `deactivate`, `assertContext`, `runAs`, `withoutTenancy`);
  the subdomain middleware pipeline `ResolveTenant` + `SetTenantContext` + `EnsureMembership`;
  atomic provisioning shared by `POST /tenants` and `tenant:create`; the central tenant picker
  built on the sanctioned `withoutTenancy` read; and `IsolationProofTest`, a raw PDO suite that
  proves the database enforces isolation with no Eloquent involved.

## Project status

- Phase 1 complete and verified. Phase 2 (BelongsToTenant, projects, roles) has not been started
  and waits for owner approval of Phase 1.
- Verified on 2026-07-28: `php artisan test` green (64 tests, 136 assertions) against
  PostgreSQL 16 as `tenantbase_app`; `./vendor/bin/pint --test` clean; the psql probes from the
  Phase 1 checklist reproduced by hand (context 1 sees only tenant 1's membership rows, no
  context sees zero rows, a mismatched INSERT raises `new row violates row-level security
  policy`, and `\d memberships` shows `tenant_isolation` under forced row security); a full HTTP
  walkthrough (login, picker, `acme` dashboard as owner, 404 on an unknown subdomain, 403 for a
  signed-in non-member, duplicate and reserved slug field errors, second workspace created); and
  the database-down case (stop PostgreSQL mid-session: the request 500s with the connection
  error logged and nothing leaked, the next request after restart is 200). The only log keys
  emitted in those runs were `tenancy.resolved`, `tenancy.bypass`, `tenancy.context_missing`,
  `tenant.provisioned` and `auth.login_failed`.

## Decisions log

- 2026-07-27 - Transaction per request. `SET LOCAL`-style GUCs are the only pooling-safe way to
  carry tenant identity to RLS (session-level `SET` leaks across pooled clients), and they
  require an open transaction, so `SetTenantContext` wraps each request in one. Accepted costs:
  no streaming/long-poll endpoints under tenant context, and app code is banned from top-level
  transaction control (nested `DB::transaction` savepoints are fine). The alternative, plain
  session `SET` with a reset, was rejected because a missed reset or a pooler swap is exactly
  the class of silent cross-tenant bug this project exists to make impossible.
- 2026-07-27 - RLS threat model is application bugs, not a compromised app process. The
  `app.tenancy_bypass` GUC therefore does not weaken anything (code able to set it could set
  `app.tenant_id` too), and keeping the bypass out of `WITH CHECK` makes `withoutTenancy()`
  read-only for tenant rows: the escape hatch can look across tenants but never write them.
- 2026-07-27 - RLS policy SQL is inlined verbatim in every migration rather than emitted by a
  shared helper. A helper edit would retroactively change what an applied migration would do on
  a fresh database, violating the never-edit-applied-migrations rule in spirit. Copy-paste of a
  canonical block is the deliberate choice.
- 2026-07-27 - Tests run only against real PostgreSQL as a `NOSUPERUSER NOBYPASSRLS` role; no
  SQLite anywhere. This breaks with the sibling Laravel projects (SQLite dev/test), because
  here the database behavior IS the product; a suite that skipped RLS would be theater. A guard
  test fails the run if the connected role bypasses RLS, since the postgres superuser default
  in CI images silently disables every policy.
- 2026-07-27 - Usage storage split into `usage_counters` (live gauges for projects/members) and
  `usage_rollups` (monthly rows: api_calls incremented live, gauges snapshotted at month end).
  One table with a nullable period column would need PostgreSQL 15 `NULLS NOT DISTINCT` or a
  sentinel date to keep the unique key honest; two small tables with clean unique indexes and
  different write semantics (up/down vs append) are simpler to reason about and to guard. The
  enforcement statements are fixed in architecture: gauge limits via a guarded
  `UPDATE ... WHERE value < limit` on a pre-provisioned counter row (the row lock serializes
  racers), api_calls via a single `INSERT ... ON CONFLICT DO UPDATE ... WHERE value < quota
  RETURNING value`; check-then-insert patterns are banned because they lose the race between
  the check and the insert.
- 2026-07-28 - Laravel 12.x instead of the 11.x named in `docs/architecture.md`. Every published
  11.x release (up to v11.55.0, the last one) is covered by unfixed advisories, including
  CVE-2026-48019 (CRLF injection in the default email rule, affects all of `>=11.0.0,<12.0.0`)
  and the signed URL path confusion fixed only in 12.61.1; Composer refuses to install them.
  Shipping a security-focused starter on a framework with unpatched CVEs was the worse trade,
  so the app is pinned to `laravel/framework` 12.64.0 on PHP 8.3. Nothing else in the
  architecture changes: Laravel 12 keeps the same routing, Eloquent and middleware surface this
  design relies on. `docs/architecture.md` was deliberately not edited (it needs owner sign-off);
  this is flagged for that review.
- 2026-07-28 - Gauge counter rows are not created at provisioning yet. The Phase 1 task text
  mentions them, but the Phase 1 migration list and commit list contain neither a
  `usage_counters` migration nor a commit for one, and Phase 4 commit 1 is exactly
  `feat: add usage counters migration with rls policy`. Creating the table early would have
  meant an unlisted commit and a migration living outside its phase, so provisioning creates the
  tenant and the owner membership atomically and the counter inserts join the same transaction
  in Phase 4. `Tenant::provision()` is the single place that changes.
- 2026-07-28 - Two commits beyond the 13 listed. `docs: document database role and setup
  commands` exists because Phase 1's definition of done requires the README to carry the exact
  psql commands for the `tenantbase_app` role and the two databases, while the phase commit list
  has no slot for it and Phase 5 only "finalizes" the README. `fix: keep context cleanup from
  masking errors` corrects the defect described below; it was found after the tenancy context
  commit had already reached `origin/main`, and a separate fix commit is the honest record of
  that, rather than rewriting a published commit to look as if the bug never existed.
- 2026-07-28 - `SetTenantContext` decides commit or rollback from the response status, not only
  from a caught exception. Laravel's routing pipeline converts an uncaught exception into a 5xx
  response before upstream middleware sees it, so a catch block alone would have committed the
  work of a failed request. The middleware now rolls back on status >= 500 and still catches
  exceptions that escape the pipeline. This also gives Phase 4 the behaviour it needs: a request
  that 500s must not bill an API call.
- 2026-07-28 - Cleanup in `finally` blocks never throws. `deactivate()`, `runAs()` and
  `withoutTenancy()` reset their setting through one private helper that swallows a
  `QueryException` and logs `tenancy.context_missing`. A dropped or aborted transaction has
  already discarded the setting, so the reset failing is not news; letting it throw replaced the
  real error (for example the foreign key violation in the provisioning rollback test) with a
  meaningless "current transaction is aborted", which is exactly the debuggability loss the
  logging rules exist to prevent.
- 2026-07-28 - Added `TenancyHttpException` to the package's `Exceptions/` directory, which the
  architecture file tree does not list. Three call sites already need the one JSON error
  envelope with a stable code (unknown tenant, suspended tenant, non-member) and Phase 4 adds
  more. It extends `HttpException`, returns the envelope to JSON callers, and returns null for
  HTML callers so the branded error pages render. The alternative, repeating envelope
  construction in each middleware, would have broken the one-envelope rule the first time
  someone edited a copy.
- 2026-07-28 - `MembershipFactory` overrides `newModel()` to force-fill. `tenant_id` is
  deliberately absent from the model's `$fillable` so it can never arrive from a request
  payload, but Eloquent factories build models through mass assignment, which would silently
  drop it. Overriding the one method keeps the guard on the model and still lets tests state a
  tenant explicitly; the factory also throws when no tenant is given, per the rule that
  tenant-owned factories never invent one.
- 2026-07-28 - `tenant:create` requires `--owner-email` to match an existing user and fails
  otherwise. `docs/api-contracts.md` describes creating and mailing an owner invitation when it
  does not, but invitations arrive in Phase 3; building them early would have pulled a whole
  phase forward. The command's contract is otherwise exactly as documented.
- 2026-07-28 - `tenancy.bypass` logs the reason and tenant id only. Caller file and line are
  listed under Phase 5 with the bypass call-site grep audit, so they were left out rather than
  built ahead of their phase.
- 2026-07-28 - Local toolchain note, not a project decision: this machine has no PHP with
  `pdo_pgsql`, so the whole phase was built and verified inside a `php:8.3-cli` container with
  `pdo_pgsql` compiled in, against a dedicated `postgres:16` container on host port 5434. The
  committed `.env.example` uses the conventional port 5432; only the untracked local `.env`
  differs. Test tooling is Pest 4.7.5 on PHPUnit 12.5.30, pinned exactly with the lockfile
  committed.
