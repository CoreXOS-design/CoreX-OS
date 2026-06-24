# Maintenance Mode — Per-Agency (AT-93)

> Status: re-scoped 2026-06-24 from a platform-wide gate to a per-agency,
> post-login gate. Built on `AT-maintenance-mode`.

## What this does and why

Maintenance mode is a **tenant-level** state: a System Owner can put one
agency into maintenance so that **only that agency's users** see a branded
"down for maintenance" screen (after they log in and their agency resolves),
while every other agency keeps working normally. The CoreX **login is never
taken down** — it stays reachable for all agencies at all times. System
Owners bypass the flag entirely, so the agency under maintenance is still
fully reachable for the work being done on it.

### Why the previous (platform-wide) design was wrong

The first cut used a global flag file + a `web`-group gate prepended before
`Authenticate`, which blocked every non-owner request regardless of agency —
so all agencies saw the splash. That is the wrong scope for tenant
maintenance. It has been removed entirely (no `php artisan down`, no global
flag, no before-auth gate).

## Pillar connection

Tenant infrastructure keyed on the **Agency** (the tenant boundary that the
Agent pillar and all pillar data hang off). The flag lives on the `agencies`
row; enforcement reads the authenticated user's resolved agency.

## State (per-agency, configurable — not hardcoded)

Columns on `agencies` (migration
`2026_07_03_100000_add_maintenance_mode_to_agencies_table.php`):

| Column | Type | Meaning |
|--------|------|---------|
| `maintenance_mode` | bool, default false | Is this agency in maintenance? |
| `maintenance_message` | text, nullable | Optional message shown on the splash |
| `maintenance_started_at` | timestamp, nullable | Stamped on entry, cleared on exit ("in maintenance since…") |

`Agency` helpers: `isInMaintenance()`, `enterMaintenance(?message)`,
`exitMaintenance()`. Reversible state flag — toggling off restores access.
No hard delete.

## Enforcement — after login, per agency

`App\Http\Middleware\AgencyMaintenanceGate`, appended to the `web` group (runs
after `StartSession`, so the authenticated user and their agency are
resolvable). Logic:

1. No authenticated user → pass. **Login/guests are never blocked.**
2. `User::isOwnerRole()` → pass (System Owner bypass).
3. Login / logout / password routes → pass (a maintenance-agency user can
   still sign out).
4. Resolve `User::effectiveAgencyId()`; load that `Agency`; if it
   `isInMaintenance()` → branded 503 splash (`errors/maintenance.blade.php`)
   with the agency's message. JSON callers get a structured 503.
5. Otherwise → pass (every other agency is unaffected).

The gate replaces the old global `MaintenanceGate`. No middleware-priority
manipulation — because it only ever acts on an authenticated non-owner, guests
fall through to the normal `auth` redirect to login.

## System Owner bypass

`isOwnerRole()` (role `is_owner = true`). Owners carry `agency_id = NULL` and
bypass `AgencyScope`; here they bypass the maintenance gate too, including when
switched into the maintenance agency via the agency switcher.

## Splash

Reuses `resources/views/errors/maintenance.blade.php` — self-contained
(no layout / Vite / DB), CoreX brand navy/teal, the agency's
`maintenance_message`, and the "System Owner? Sign in" link intact.

## Toggle — System Owner control (per nav rule)

On the owner-only **Agency Management** page (`settings/agencies`, sidebar →
"Agency Management"), each agency row has a **Maintenance / End maintenance**
control beside the existing Active toggle, and a "Maintenance" status badge.
Route: `POST settings/agencies/{agency}/toggle-maintenance` →
`AgencyController::toggleMaintenance` (owner-only middleware + the controller's
`owner_only` route group). Enabling prompts for an optional message.

Decision: **owner-only**, not agency-admin self-service — an agency admin is a
non-owner and also sees the splash, so they must not be able to lock (or
unlock) their own agency; only a System Owner controls it.

### Escape hatch (no UI dependency)

`php artisan corex:maintenance {agency} {on|off|status}` (agency = id / slug /
name), or `corex:maintenance` with no args to list every agency's state. An
owner can always lift an agency's maintenance from the CLI.

## Permissions / multi-tenancy

Owner-only (consistent with the other `settings/agencies` controls). The gate
reads `effectiveAgencyId()` per the multi-tenancy doctrine; `Agency` is a
catalog model (no `AgencyScope`), so `Agency::find()` in the gate is correct.

## Acceptance criteria

- [x] Login loads for a non-maintenance agency user → normal app.
- [x] Login loads for a maintenance agency user → splash AFTER login.
- [x] System Owner into the maintenance agency → bypasses, gets in.
- [x] The CoreX login URL is reachable in all cases (never a global splash).
- [x] Toggle on→off→on restores access each way.
- [x] Owner-only toggle (non-owner → 403).
- [x] Per-agency artisan escape hatch on/off.

## Files

**Added:** migration above; `app/Http/Middleware/AgencyMaintenanceGate.php`;
`Agency` maintenance columns/casts/helpers; `AgencyController::toggleMaintenance`
+ route; maintenance toggle/badge in `admin/agencies/index.blade.php`;
per-agency `corex:maintenance` command; `tests/Feature/MaintenanceModeTest.php`.

**Removed (old platform-wide cut):** `app/Http/Middleware/MaintenanceGate.php`,
`app/Services/MaintenanceMode.php`,
`app/Http/Controllers/Admin/MaintenanceModeController.php`, the global
`admin/maintenance` routes, the Dev Settings toggle card + controller state,
and the before-auth priority hook in `bootstrap/app.php`.

**Reused:** `resources/views/errors/maintenance.blade.php`.
