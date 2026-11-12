# Security policy

## Supported versions

Only the latest commit on `main` receives security fixes.

## Reporting a vulnerability

Please do not open a public issue. Use GitHub's private vulnerability reporting
(Security tab, "Report a vulnerability") or email thealirazadev@gmail.com with steps to
reproduce. You should get a reply within 72 hours. Cross-tenant data access of any kind is
treated as critical.

## Isolation model

- Every tenant-owned table has a PostgreSQL row-level security policy keyed to a
  transaction-scoped `app.tenant_id`; the app connects as a `NOSUPERUSER NOBYPASSRLS` role.
- The Eloquent `BelongsToTenant` scope fails loudly without a tenant context and fills
  `tenant_id` from the context, never from request input.
- Route-model binding rejects another tenant's id with a 404, even inside the logged
  `withoutTenancy()` bypass, which is read-only for tenant data.
- Role checks run in policies on the server; hidden controls are a courtesy, not the control.
- The last-owner rule locks the owner rows inside the request transaction, so concurrent
  demotions cannot leave a workspace without an owner.
