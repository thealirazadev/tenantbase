# Design - tenantbase

Server-rendered Blade with one static stylesheet (`public/css/app.css`), no CSS framework, no
JavaScript build, no client-side state. The starter UI is deliberately plain: users of
tenantbase will restyle it, so clarity and correct states matter more than polish. Two shells
exist: the **central** shell (auth, tenant picker, create workspace) and the **tenant** shell
(everything on `{slug}.APP_DOMAIN`), which shows the tenant name and the current user's role in
the header at all times so it is always obvious whose data is on screen.

## Screens

**Central domain**
- `Login` / `Register` - single card, email + password; register links to login and back.
- `Tenant picker` (`/`) - list of the user's tenants (name, role, plan) as full-row links to
  each subdomain, plus a "Create a workspace" button. Empty state: "You are not a member of any
  workspace yet" with the create action.
- `Create workspace` - name and slug fields; the slug input shows the resulting URL preview
  (`{slug}.APP_DOMAIN`) as help text; reserved/duplicate slugs return field errors.

**Tenant subdomain**
- `Dashboard` (`/`) - three usage tiles (projects, members, API calls this month), each
  "used / limit" with a plain progress bar; recent projects table; tiles link to the usage page.
- `Projects` - index table (name, description, created by, created at) with create button;
  create/edit forms; delete behind an inline confirm form. Duplicate-name field error.
- `Members` - table (name, email, role badge, joined); role select + remove button per row,
  rendered only when the viewer's role permits; the last owner's row shows "sole owner" in
  muted text instead of controls.
- `Invitations` - pending list (email, role, expires, revoke) above the invite form (email,
  role select limited to admin/member).
- `Invitation accept` - a single card naming the tenant and role; if unauthenticated, the
  login/register forms render inline first. Expired/used tokens render a 410 page with a
  "request a new invitation" sentence.
- `Usage` - one table per resource: gauge value vs limit, current-month API calls vs quota,
  and a month-by-month history table from `usage_rollups`.
- `Settings` - API tokens: issue (name field), list (name, last used, created), revoke. The
  raw token is flashed exactly once after creation in a copy-me monospace block.

## Role visibility matrix

| Action | owner | admin | member |
|---|---|---|---|
| View dashboard, projects, members, usage | yes | yes | yes |
| Create/edit projects | yes | yes | yes |
| Delete projects | yes | yes | no |
| Invite / revoke invitations | yes | yes | no |
| Change roles / remove members | yes | no | no |
| Issue own API tokens | yes | yes | yes |

Controls the role cannot use are not rendered (server still enforces via policies; hiding is
courtesy, not security).

## Color & theme

Light theme only. Neutral grays, one accent, semantic states.

| Token | Hex | Use |
|---|---|---|
| `--bg` | `#f6f7f9` | Page background |
| `--surface` | `#ffffff` | Cards, tables |
| `--border` | `#d9dde3` | Borders, rules |
| `--text` | `#1f2933` | Body text |
| `--text-muted` | `#57606a` | Secondary text |
| `--accent` | `#0f766e` | Links, primary buttons, focus ring |
| `--accent-hover` | `#115e59` | Hover |
| `--danger` | `#b91c1c` | Destructive actions, errors |
| `--warn-bg` / `--warn-text` | `#fef3c7` / `#92400e` | Usage bar at 80%+, limit warnings |

Role badges: owner `#ede9fe`/`#5b21b6`, admin `#dbeafe`/`#1e40af`, member `#e5e7eb`/`#374151`.
All pairs meet WCAG AA; the label text always accompanies the color.

## Typography, spacing, components

- System font stack; monospace (`ui-monospace, Menlo, Consolas`) for slugs, tokens, and ids.
  Scale (rem): page title 1.5/600, section heading 1.125/600, body 0.9375, small 0.8125.
- Spacing on 4/8px steps; card padding 16; page gutter 24; max content width 1080px centered;
  radius 6px (badges 9999px); single subtle card shadow.
- Buttons (primary/secondary/danger), links, forms, tables, badges, and flash messages follow
  one pattern each, defined once and reused; every interactive element has default, hover,
  focus (2px accent outline, 2px offset), and disabled states.
- Forms: visible `<label>` per input, help text muted, error = danger border + message under
  the field, values repopulated on validation failure.
- Usage bars: plain `<div>` bars with width in percent, accent below 80%, warn scheme at 80%+,
  danger at 100%; the numeric "used / limit" text is always present (never color alone).
- Empty states on every index: one sentence plus the obvious next action.
- Destructive actions (delete project, remove member, revoke invitation) confirm via an inline
  no-JS confirm form, never `confirm()`.

## Accessibility baseline

- Semantic HTML: one `<h1>` per page, `<nav>`/`<main>` landmarks, real tables with
  `<th scope="col">`, real buttons and links, `<html lang="en">`.
- Fully keyboard-navigable; visible focus everywhere; no keyboard traps (no JS to create them).
- Contrast AA for all text; status and roles conveyed by text plus color.
- Layout readable at 320px: tables scroll inside their card, forms stack.

## Error pages

Branded minimal pages sharing the tenant or central shell: 403 (not a member / suspended,
naming which), 404 (unknown tenant or resource), 410 (invitation gone), 419, 500. API routes
never render HTML; they return the JSON envelope per `docs/api-contracts.md`.
