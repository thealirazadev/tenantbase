# Changelog

## Unreleased

### Added
- Phase 2: project CRUD screens, role policies (owner/admin/member matrix), members index
  with role changes and removal, and a tenant check in route-model binding (404 for another
  workspace's id, even with the scope bypassed).
- Last-owner guard that locks the owner rows, verified with concurrent mutual demotions
  against PostgreSQL.
- CI on PHP 8.3 and 8.4 with a 90% coverage gate.

### Fixed
- Route-model binding ran before the tenant context was set, so every `/{model}` URL failed
  with `MissingTenantContext`; the tenancy middleware now runs before `SubstituteBindings`.

## Phase 1

- PostgreSQL row-level security, tenants, memberships, tenant resolution and context,
  provisioning, `tenant:create`, and the raw-SQL isolation proof.
