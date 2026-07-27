# Launch Checklist - tenantbase

Work top to bottom before going to production. Nothing is checked until verified in the target
environment.

## Environment & configuration

- [ ] Production `.env` from `.env.example` with real values; `APP_DEBUG=false`,
      `APP_ENV=production`; `APP_KEY` generated and backed up.
- [ ] `APP_DOMAIN` set; `SESSION_DOMAIN=.APP_DOMAIN`; `APP_URL` matches.
- [ ] Wildcard DNS (`*.APP_DOMAIN`) and a wildcard TLS certificate in place; HTTPS enforced.
- [ ] `DB_*` points at production PostgreSQL 16 as the `tenantbase_app` role; credentials
      stored securely, not in the repo.
- [ ] `MAIL_*` configured against a real provider; a test invitation actually arrives.
- [ ] Config/route/view caches warmed (`config:cache`, `route:cache`, `view:cache`).

## Database & isolation (the ones that matter most here)

- [ ] The runtime role verified `NOSUPERUSER NOBYPASSRLS` (`\du tenantbase_app` in psql).
- [ ] Migrations run cleanly (`migrate --force`); every tenant-owned table shows the
      `tenant_isolation` policy and `FORCE` in `\d <table>`.
- [ ] Manual psql probe as the runtime role: with `set_config('app.tenant_id', ...)` only that
      tenant's rows are visible; without it, zero rows; a mismatched insert errors.
- [ ] If a connection pooler is used, it runs in transaction or session mode and no
      session-level `SET` exists anywhere (grep confirms); GUCs are transaction-scoped only.
- [ ] Database backups scheduled and a restore tested at least once.

## Processes

- [ ] Queue worker under a supervisor, restarting on failure and on deploy
      (`queue:restart` in the deploy procedure).
- [ ] Cron entry for `php artisan schedule:run` every minute (drives the monthly rollup).

## Security

- [ ] No secrets committed; `.env` git-ignored; invitation tokens confirmed hashed at rest.
- [ ] Login, invitation-accept, and API throttles active; 429 envelope confirmed.
- [ ] A non-member session hitting a tenant subdomain gets 403; a member of tenant A guessing
      tenant B's project id gets 404.
- [ ] `X-Tenant` mismatch with a subdomain returns 400 in production config.
- [ ] User-entered names rendered escaped (create a project named with an HTML snippet).

## Reliability & observability

- [ ] Log aggregation receiving the documented `tenancy.*`, `usage.*`, `tenant.*`, `invite.*`
      keys; zero `tenancy.bypass` lines outside the sanctioned call sites.
- [ ] Kill-the-worker test: stop mid-job, restart, job completes in its tenant context.
- [ ] Suspend/unsuspend a canary tenant: 403 while suspended, queued job orphans with
      `tenancy.job_orphaned`, access restored after.
- [ ] Concurrency probe at the plan limit (parallel creates): row count never exceeds the
      limit; `tenant:usage --reconcile` reports no drift afterward.
- [ ] Quota probe: drive a canary tenant to its API quota; 429 envelope and
      `X-Quota-Remaining: 0` observed; rollup row matches.

## Quality gates

- [ ] `php artisan test` green in CI (PostgreSQL service, app role, not superuser).
- [ ] `./vendor/bin/pint --test` clean.
- [ ] `composer.lock` committed and matching the deployed build.
- [ ] Plan limits in `config/plans.php` reviewed with the owner before first real signup.
