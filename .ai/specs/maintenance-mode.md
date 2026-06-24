# Maintenance Mode — System-Owners-Only Access (AT-93)

> Status: built on `AT-maintenance-mode`, pending Johan's full lock/unlock
> cycle test on `/corex-staging`.

## What this does and why

A single owner-only toggle that puts the whole CoreX platform into
maintenance mode. While ON, every user sees a branded "down for
maintenance" page **except System Owners**, who keep full normal access
to run final go-live checks. A second click lifts it. This is the go-live
cutover control — it lets an owner freeze the live site for everyone else
while verifying the deployment, then release it.

## Pillar connection

This is platform-infrastructure (cross-cutting), not a pillar data feature.
It gates access to the entire app — every pillar surface (Property, Contact,
Deal, Agent) is behind it for non-owners while ON. It reads/writes no pillar
data; it touches no tenant tables.

## System Owner definition (reused, not reinvented)

A System Owner is `User::isOwnerRole()` — the user's **real** role (not
View-As) whose `Role.is_owner = true`. This is the identical gate used by
the `owner_only` middleware (`app/Http/Middleware/OwnerOnly.php`) and every
platform-level area (P24 importer, dev-settings, developer-users, agency
switcher). No new flag, no new concept.

## Why NOT `php artisan down`

Laravel's built-in maintenance mode short-circuits in
`PreventRequestsDuringMaintenance` *before* routing and auth resolve, so it
cannot selectively let an authenticated owner through, and can block the
very route needed to lift it. CoreX maintenance mode is instead a gate that
runs **after** session boot — it knows who the user is and always lets
owners (and the auth/toggle routes) through.

## State storage — a flag file

Source of truth is a flag file: `storage/framework/corex-maintenance.flag`,
wrapped by `App\Services\MaintenanceMode`.

- **Why a file, not the DB/`DevSetting`:** the gate runs on every web
  request and the down-page must render even when the DB is stressed or
  down (the most likely thing to be stressed during a cutover). `is_file()`
  is a filesystem stat — zero DB/cache dependency. Instant, survives across
  requests, flippable from the UI with no redeploy.
- The file holds JSON metadata: `enabled_at`, `enabled_by`, optional
  `message` — used for the control-panel indicator and the down-page.

## Scope — GLOBAL

Maintenance mode is system-level (whole platform, all agencies). It is
deliberately **not** agency-scoped — this is for locking the live site at
go-live, not per-tenant. No `agency_id` anywhere in the mechanism.

## Architecture

| Concern | File |
|---------|------|
| State (flag file) | `app/Services/MaintenanceMode.php` |
| Gate | `app/Http/Middleware/MaintenanceGate.php` (appended to `web` group in `bootstrap/app.php`) |
| Toggle controller | `app/Http/Controllers/Admin/MaintenanceModeController.php` |
| Toggle routes | `routes/web.php` → `admin/maintenance/{enable,disable}` (POST, `owner_only`) |
| Toggle UI | `resources/views/admin/dev-settings/index.blade.php` (card at top) |
| Down-page | `resources/views/errors/maintenance.blade.php` (self-contained, 503) |
| Escape hatch | `app/Console/Commands/MaintenanceModeCommand.php` → `corex:maintenance on\|off\|status` |

### Middleware ordering

`MaintenanceGate` is **appended** to the `web` group, so it runs after
`StartSession` (the authenticated user resolves from the session) and
before app route handlers. The `auth` middleware is route-level, so the
gate also runs for guests. The api group is unaffected (mobile keeps
working). When maintenance is OFF the gate is a single `is_file()` stat —
no behaviour change.

### Always-exempt routes

So an owner can always sign in and lift maintenance, and a non-owner is
bounced back to the down-page after logging in, these are never blocked:
`login` (GET+POST), `logout`, `password.*`, `admin.maintenance.enable`,
`admin.maintenance.disable`, plus a path-prefix safety net for the unnamed
POST `/login` and `/up` health check.

## User flow

1. Owner → **Dev Settings** (sidebar) → Maintenance mode card at top.
2. Card shows current state: green "Site is LIVE" or amber "Site in
   MAINTENANCE — owners only" (+ who/when).
3. **Enable maintenance mode** → native confirm ("This will block ALL
   non-owner users… Continue?") → POST → flag written → success flash.
4. Non-owners and guests now get the branded 503 down-page everywhere
   except the auth routes. Owners browse normally.
5. **Go live (lift maintenance)** → POST → flag removed → site live for all.

## Safety — escape hatch (owner never locked out)

- `php artisan corex:maintenance off` — lifts maintenance with no UI
  dependency.
- `php artisan corex:maintenance on|status` — enable / inspect.
- Last resort: delete `storage/framework/corex-maintenance.flag` by hand.
- Owners always pass the gate; login/logout/toggle never blocked.

## Permissions

Owner-only, enforced two ways: `owner_only` route middleware + an explicit
`isOwnerRole()` guard in the controller (defence-in-depth). Consistent with
other platform-level owner tools (no separate permission key, matching the
P24 importer pattern).

## Navigation

Lives on the existing **Dev Settings** page (sidebar → Dev Settings,
already owner-only). No new nav node needed; reachable today.

## Robustness (input space)

- **Flag file unreadable / malformed JSON** → `meta()` returns `[]`, page
  still renders. Absorbed.
- **Toggle hit by a non-owner** → `owner_only` + controller guard → 403.
  Prevented.
- **Maintenance ON + JSON/AJAX caller** → structured 503 JSON, not an HTML
  page. Absorbed.
- **Double-enable / double-disable** → idempotent (write-over / unlink-if-
  exists). Absorbed.
- **Owner with an expired session during maintenance** → treated as guest →
  down-page, but login is reachable → signs in → through. No lock-out.

## Acceptance criteria

- [x] Owner + ON → full access, no down-page.
- [x] Non-owner + ON → branded 503 down-page, blocked from app.
- [x] Guest + ON → down-page, but `/login` reachable; owner login → through;
      agent login → back to down-page.
- [x] Toggle OFF → everyone normal.
- [x] `corex:maintenance off` lifts it without the UI.
- [x] login/logout/toggle never blocked.

## Files created / modified

**Created:** `app/Services/MaintenanceMode.php`,
`app/Http/Middleware/MaintenanceGate.php`,
`app/Http/Controllers/Admin/MaintenanceModeController.php`,
`app/Console/Commands/MaintenanceModeCommand.php`,
`resources/views/errors/maintenance.blade.php`,
`tests/Feature/MaintenanceModeTest.php`, this spec.

**Modified:** `bootstrap/app.php` (append gate to web group),
`routes/web.php` (toggle routes),
`app/Http/Controllers/Admin/DevSettingsController.php` (pass state),
`resources/views/admin/dev-settings/index.blade.php` (toggle card).
