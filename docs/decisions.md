# Design decisions

## 1. Isolation is enforced by the database, with the ORM as a first net

Every tenant-owned table carries `tenant_id`, and three independent checks guard it:

1. The `BelongsToTenant` trait adds `TenantScope`, which filters every Eloquent query by the
   active tenant and throws `MissingTenantContext` when there is no context. Failing loudly is
   deliberate: an empty result would look like a real, empty workspace.
2. PostgreSQL row-level security re-checks every row against `app.tenant_id`, so a raw query, a
   disabled scope, or a model that forgot the trait still cannot read or write another tenant.
3. `resolveRouteBinding()` on the trait compares the bound model's `tenant_id` with the active
   tenant and turns a mismatch into a 404, which still holds inside the cross-tenant bypass.

The scope is convenience and early failure; the database is the check that cannot be forgotten.

## 2. Tenant identity travels in a transaction-scoped setting

`Tenancy::activate()` is the only code that sets `app.tenant_id`, and it uses
`select set_config(?, ?, true)`. `SET LOCAL` does not accept bind parameters, and a session-level
`SET` would survive on a pooled connection and leak into the next client's request. A
transaction-local setting dies with the transaction, which is safe behind a pooler in
transaction mode. `activate()` refuses to run outside a transaction, so `SetTenantContext` opens
one per tenant request. The trade-off is accepted on purpose: no streaming or long-poll endpoints
under tenant context, and application code uses nested `DB::transaction()` savepoints rather than
top-level commits. `runAs()` and `withoutTenancy()` open their own transaction when called from a
queue worker or Artisan, where none exists.

## 3. A failed request never commits, even when no exception escapes

Laravel's router converts an uncaught exception into a 5xx response before outer middleware sees
it, so a `catch` block in `SetTenantContext` alone would have committed the partial work of a
failed request. The middleware therefore rolls back whenever the response status is 500 or
higher, and still rolls back on any exception that does escape the pipeline.

## 4. Context cleanup never replaces the real error

`deactivate()`, `runAs()`, and `withoutTenancy()` reset their setting in `finally` blocks through
one helper that catches `QueryException` and logs `tenancy.context_missing`. Originally the reset
could throw: after a foreign key violation PostgreSQL aborts the transaction, the reset then
failed with "current transaction is aborted", and that message replaced the foreign key error
that actually explained the failure. An aborted or dropped transaction has already discarded the
setting, so swallowing the reset failure loses nothing.

## 5. One canonical RLS policy, inlined in each migration

Each tenant-owned table is created with `ENABLE` and `FORCE ROW LEVEL SECURITY` and a
`tenant_isolation` policy in the same migration, so the table never exists without its policy.
Details that make the policy correct:

- `current_setting(..., true)` returns NULL when the setting is missing, and `NULLIF(..., '')`
  maps the reset value to NULL too. Comparing with NULL is never true, so no context means no
  rows and no writes.
- `FORCE` makes the owning role obey the policy, so migrations and the app can share one role.
- The `app.tenancy_bypass` clause appears only in `USING`, not in `WITH CHECK`. The bypass can
  read across tenants but cannot insert or update a row for a tenant other than the active one.
  This is sound because the threat model is application bugs, not a compromised process: code
  that could set the bypass could set `app.tenant_id` as well.

The SQL is copied verbatim into every migration instead of coming from a shared helper. Editing a
helper would silently change what an already applied migration does on a fresh database.

## 6. `withoutTenancy()` is the single, logged escape hatch

Cross-tenant reads go through `withoutTenancy(string $reason, Closure $callback)`, which rejects
an empty reason, logs `tenancy.bypass` with the reason and current tenant id, sets the bypass for
the callback, and restores the previous state afterwards. The method name is the grep target for
audits. The only call site today is the central workspace picker, which by definition lists one
user's memberships across tenants and runs on the central domain where no tenant context exists.

## 7. `tenant_id` never comes from input

`tenant_id` is absent from every `$fillable`, and the trait fills it from the active context on
create. An explicitly set `tenant_id` is left alone so that RLS rejects it when it disagrees with
the context, rather than the trait quietly overwriting it. Eloquent factories build models
through mass assignment, which would drop the guarded column, so `MembershipFactory` overrides
`newModel()` to force-fill and throws when no tenant is given. Tests never get an invented tenant.

## 8. Tenancy middleware runs before route-model binding

With the default priority, `SubstituteBindings` ran before the tenant was resolved, so any route
that bound a tenant-owned model, such as `/members/{membership}`, failed with
`MissingTenantContext`. `TenancyServiceProvider` now registers `ResolveTenant`,
`SetTenantContext`, and `EnsureMembership` ahead of `SubstituteBindings` in the middleware
priority list, so binding happens inside the tenant transaction.

## 9. Provisioning is one atomic path shared by signup and the CLI

`Tenant::provision()` creates the tenant and its owner membership in one transaction, used by both
`POST /tenants` and `tenant:create`. A tenant without an owner would be unreachable and impossible
to remove from the UI, so either both rows exist or neither does. Slugs are validated against a
format and a reserved list, and the unique index has the final word: two signups racing for the
same slug pass validation, and the loser's `UniqueConstraintViolationException` becomes the same
"already taken" field error. Project names are handled the same way per workspace.
`tenant:create` only accepts an `--owner-email` that belongs to an existing user.

## 10. The last owner is protected with row locks, not a count

Demoting or removing an owner first locks every owner row in the workspace and fails with
`LastOwnerException` if no other owner remains. PostgreSQL does not allow `FOR UPDATE` together
with an aggregate, so the guard selects the owner ids with `lockForUpdate()` and counts in PHP.
Locking in id order makes two concurrent changes queue on the same locks instead of deadlocking,
so two owners demoting each other at the same moment always leave one owner. Policies hold the
role rules: only owners change roles or remove members, and `ProjectPolicy` defines only
`delete` (owners and admins), because create and edit are open to every member and
`EnsureMembership` already guarantees membership.

## 11. One JSON error envelope with stable codes

Resolution and membership failures throw `TenancyHttpException`, which carries an HTTP status and
a stable code (`unknown_tenant`, `tenant_suspended`, `forbidden`). JSON callers get
`{ "error": { "code": "...", "message": "..." } }`; for HTML callers `render()` returns null so the
branded error pages are used. Keeping construction in one class avoids drifting copies of the
envelope in each middleware.

## 12. Tests run only on PostgreSQL, as the restricted role

There is no SQLite path: a suite that skipped row-level security would test nothing that matters
here. The app and the tests connect as a `NOSUPERUSER NOBYPASSRLS` role, and a guard test fails
the run if the connected role is a superuser or can bypass RLS, because the `postgres` superuser
that CI images default to silently ignores every policy. `IsolationProofTest` uses raw PDO, with
no Eloquent at all, over a matrix of every tenant-owned table: each tenant sees only its own
rows, a query with no context sees no rows, and inserts for another tenant or with no context are
rejected. In CI, pcov is pointed at the whole project (`pcov.directory=.`), because by default
it instruments only `src/` when that directory exists, which left `app/` out and made coverage
look far lower than it was.
