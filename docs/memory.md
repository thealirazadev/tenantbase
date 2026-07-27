# Project Memory - tenantbase

Running log of what is done, in progress, and decided. Update after every meaningful chunk of
work; log every non-obvious decision with its reason. Keep entries short and dated.

## Completed

- 2026-07-27 - Planning documentation created (README, PRD, architecture, rules, phases,
  design, testing, api-contracts, launch-checklist, memory). No code yet; implementation
  follows `docs/phases.md` after owner review.

## Project status

- Planning stage. Phase 1 (foundation, tenancy context, RLS, isolation proof) is next once the
  docs are approved.

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
