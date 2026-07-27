# API Contracts - tenantbase

Three surfaces exist: the session-authenticated **web app** (server-rendered HTML, central
domain plus tenant subdomains), the token-authenticated **JSON API** (`/api/v1`), and the
**artisan commands**. All are agreed here before any code is written.

Base URLs: central `https://APP_DOMAIN`, tenant `https://{slug}.APP_DOMAIN`. Timestamps are
ISO-8601 UTC. `period` values are the first day of a month (`2026-07-01`).

## Error envelope (all JSON errors)

```json
{
  "error": {
    "code": "plan_limit_reached",
    "message": "Your plan allows 3 projects. Delete one or upgrade to create more."
  }
}
```

`error.details` (object) is added only for validation failures: a map of field name to an array
of messages.

### Stable error codes

| HTTP | `error.code` | When |
|---|---|---|
| 400 | `tenant_mismatch` | Subdomain and `X-Tenant` header disagree on one request. |
| 401 | `unauthenticated` | Missing or invalid session/token. |
| 403 | `forbidden` | Authenticated but not a member, or role lacks the action. |
| 403 | `tenant_suspended` | The resolved tenant is suspended. |
| 404 | `unknown_tenant` | Subdomain or `X-Tenant` matches no tenant. |
| 404 | `not_found` | Resource missing or belongs to another tenant (indistinguishable by design). |
| 410 | `invitation_gone` | Invitation expired, revoked, or already accepted. |
| 422 | `validation_failed` | Input validation errors (`details` present). |
| 422 | `plan_limit_reached` | Gauge limit hit creating a project or member. |
| 429 | `api_quota_exceeded` | Monthly API call quota exhausted. |
| 429 | `rate_limited` | IP/token throttle (login, accept, API burst). |
| 500 | `server_error` | Unexpected error (details logged, never returned). |

Web (HTML) routes render the same conditions as branded pages or field errors; API routes
always return the envelope and never HTML.

## Tenant resolution contract

| Request host | `X-Tenant` header | Result |
|---|---|---|
| `{slug}.APP_DOMAIN` | absent | Tenant = slug. |
| `{slug}.APP_DOMAIN` | same slug | Tenant = slug (header redundant, allowed). |
| `{slug}.APP_DOMAIN` | different slug | 400 `tenant_mismatch`. |
| `APP_DOMAIN`, API route | present | Tenant = header value. |
| `APP_DOMAIN`, API route | absent | 404 `unknown_tenant` (tenant-scoped endpoints). |
| `APP_DOMAIN`, web route | any | Header ignored; central routes only. |

Unknown slug: 404 `unknown_tenant`. Suspended: 403 `tenant_suspended`. The resolved tenant id
is never taken from a request body or query string.

---

## Web routes (HTML)

All POST/PUT/DELETE carry CSRF; validation errors re-render with field errors; success
redirects with a flash message.

### Central domain

| Method | Path | Purpose | Access |
|---|---|---|---|
| GET/POST | `/register` | Create a user account. | Guests |
| GET/POST | `/login` | Session login; IP-throttled. | Guests |
| POST | `/logout` | End session. | Authenticated |
| GET | `/` | Tenant picker: my workspaces with roles. | Authenticated |
| GET | `/tenants/create` | Create-workspace form. | Authenticated |
| POST | `/tenants` | Provision tenant atomically; redirect to its subdomain. | Authenticated |

### Tenant subdomain (`{slug}.APP_DOMAIN`)

All routes below require an authenticated session plus membership, except the invitation accept
pair, which requires only authentication (the invitee is not a member yet). Role requirements
in parentheses; everything else is any member.

| Method | Path | Purpose |
|---|---|---|
| GET | `/` | Dashboard: usage tiles, recent projects. |
| GET | `/projects` | Projects index. |
| GET `/projects/create` · POST | `/projects` | Create (guarded by the projects gauge). |
| GET `/projects/{id}/edit` · PUT | `/projects/{id}` | Edit. |
| DELETE | `/projects/{id}` | Delete; decrements the gauge (owner/admin). |
| GET | `/members` | Members index. |
| PUT | `/members/{membership}` | Change role (owner; last-owner demotion blocked). |
| DELETE | `/members/{membership}` | Remove member (owner; last owner blocked). |
| GET | `/invitations` | Pending invitations + invite form (owner/admin). |
| POST | `/invitations` | Invite email + role; replaces a live invite for the address (owner/admin). |
| DELETE | `/invitations/{id}` | Revoke pending invitation (owner/admin). |
| GET | `/invitations/{token}` | Accept page (authenticated, non-member OK, IP-throttled). |
| POST | `/invitations/{token}/accept` | Accept: create membership (member gauge guarded). |
| GET | `/usage` | Usage vs limits + monthly history. |
| GET | `/settings/tokens` | API tokens list + issue form. |
| POST | `/settings/tokens` | Issue token; raw value flashed once. |
| DELETE | `/settings/tokens/{id}` | Revoke token. |
| GET | `/up` | Framework health check (public). |

---

## JSON API (`/api/v1`)

Authentication: `Authorization: Bearer <token>` (Sanctum personal access token, issued on the
settings screen). Tenant: subdomain or `X-Tenant` per the resolution contract. Membership is
verified per request; the token itself carries no tenant. Every tenant-scoped request is
metered against the monthly `api_calls` quota; successful responses include:

```
X-Quota-Remaining: 942
```

A request that fails with 5xx rolls back its increment (failures are not billed). Quota
exhaustion returns 429 `api_quota_exceeded` with `X-Quota-Remaining: 0`.

### GET /api/v1/me

```
curl -H "Authorization: Bearer $TOKEN" -H "X-Tenant: acme" https://APP_DOMAIN/api/v1/me
```

`200`:
```json
{
  "data": {
    "user": { "id": 7, "name": "Ada Lovelace", "email": "ada@example.com" },
    "tenant": { "id": 1, "slug": "acme", "name": "Acme Inc", "plan": "free" },
    "role": "owner"
  }
}
```

### GET /api/v1/projects

Query: `page` (default 1), `per_page` (default 25, max 100).

`200`:
```json
{
  "data": [
    {
      "id": 12,
      "name": "Apollo",
      "description": "Launch tooling",
      "created_by": { "id": 7, "name": "Ada Lovelace" },
      "created_at": "2026-07-20T09:14:03Z",
      "updated_at": "2026-07-21T16:40:11Z"
    }
  ],
  "meta": { "page": 1, "per_page": 25, "total": 3 }
}
```

### POST /api/v1/projects

```json
{ "name": "Zephyr", "description": "Wind tunnel data" }
```

`201`: the project object under `data`. Errors: `422 validation_failed` (missing name,
duplicate name in tenant), `422 plan_limit_reached` when the gauge is full:

```json
{
  "error": {
    "code": "plan_limit_reached",
    "message": "Your plan allows 3 projects. Delete one or upgrade to create more."
  }
}
```

### GET /api/v1/projects/{id}

`200` with the project object, or `404 not_found`; another tenant's id is also `404 not_found`,
indistinguishable from a missing one.

### PUT /api/v1/projects/{id}

Same body as create; `200` with the updated object.

### DELETE /api/v1/projects/{id}

`204` on success (gauge decremented); `403 forbidden` for role `member`.

### GET /api/v1/usage

`200`:
```json
{
  "data": {
    "plan": "free",
    "gauges": [
      { "resource": "projects", "used": 3, "limit": 3 },
      { "resource": "members", "used": 2, "limit": 3 }
    ],
    "period": "2026-07-01",
    "api_calls": { "used": 58, "limit": 1000 },
    "history": [
      { "period": "2026-06-01", "resource": "api_calls", "value": 730 },
      { "period": "2026-06-01", "resource": "projects", "value": 2 },
      { "period": "2026-06-01", "resource": "members", "value": 2 }
    ]
  }
}
```

---

## Artisan commands

All commands exit 0 on success and 1 on failure with the reason on stderr. Tenants are
addressed by slug or id.

### tenant:create

```
php artisan tenant:create "Acme Inc" --slug=acme --plan=free --owner-email=ada@example.com
```

Provisions atomically (tenant, counters, and, when `--owner-email` matches an existing user,
the owner membership; otherwise an owner invitation is created and mailed). `--slug` defaults
to a slugified name; `--plan` defaults to `free`.

```
Tenant created.
  id:     1
  slug:   acme
  url:    https://acme.APP_DOMAIN
  plan:   free
  owner:  ada@example.com (membership created)
```

Failures: duplicate or reserved slug, unknown plan; nothing is persisted on failure.

### tenant:run

```
php artisan tenant:run acme db:seed --class=DemoSeeder
```

Executes the wrapped artisan command inside the tenant's context (one transaction around the
whole run; RLS and the Eloquent scope both active). Output is the wrapped command's own,
prefixed by one line:

```
Running [db:seed --class=DemoSeeder] as tenant acme (id 1)...
```

Failures: unknown tenant, suspended tenant (refused; use `--force` deliberately), wrapped
command failure (transaction rolls back; exit code passed through).

### tenant:usage

```
php artisan tenant:usage acme
```

```
Tenant: acme (id 1)  plan: free
  projects   3 / 3
  members    2 / 3
  api_calls  58 / 1000   (period 2026-07-01)
```

With no tenant argument, prints one line per tenant (a sanctioned `withoutTenancy` read).
`--reconcile` recounts gauges from the source tables inside tenant context, corrects drift,
and reports it:

```
Reconciled projects for acme: counter 4 -> actual 3
```

### tenant:suspend / tenant:unsuspend

```
php artisan tenant:suspend acme
php artisan tenant:unsuspend acme
```

Sets/clears `suspended_at`; prints the new state. Suspended tenants: web/API 403
`tenant_suspended`, queued jobs fail permanently, scheduler skips.

### usage:rollup

Scheduled monthly (also runnable by hand). Snapshots each tenant's gauges into the closing
month's `usage_rollups` rows; `api_calls` rows already exist from live metering. Idempotent:
re-running for the same period upserts the same values.

```
php artisan usage:rollup
Rolled up 2 gauges for 14 tenants into period 2026-06-01.
```

---

## Access summary

Public: `GET /up`, central `/register` and `/login` pages. Session + membership: every tenant
subdomain route except the invitation accept pair (session only). Bearer token + membership +
quota: every `/api/v1` route. Artisan: operator-only by definition; `tenant:run` refuses
suspended tenants without `--force`.
