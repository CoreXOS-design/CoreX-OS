# Developer Users — Spec

> Status: **DRAFT — pending approval**
> Owner: Andre / Johan
> Created: 2026-05-23
> Related: `.ai/specs/multi-tenancy.md`, `.ai/specs/agency-admin-rule.md`

---

## Why this exists

System Owners (Andre, Johan) are platform identities, not agency members. Per existing rule (`2026_04_14_110000_detach_system_owners_from_agencies`), `users.agency_id` is NULL for any user whose role has `is_owner = true`. When they need to operate inside an agency they use the agency switcher, which sets `session('active_agency_id')`; `User::effectiveAgencyId()` returns that override, and `BelongsToAgency` stamps any rows they create with the switched-into agency id. They never persist as a member of that agency.

Today there is no admin surface to **see** the Developer/System Owner roster. They are intentionally excluded from agency user lists by `User::agencyMembers()`. This spec adds a single read-only page at **System → Developer Users**, visible to any owner regardless of which agency they're currently switched into.

A full Developer Role Manager (granular per-feature permissions) is out of scope.

---

## Rules

### R1. Global visibility for owners
The Developer Users list shows every user whose role has `is_owner = true`, **regardless of the viewer's current `active_agency_id`**. Unlike regular User Management (which is agency-scoped), Developer Users are platform-wide.

Implementation: opt out of the global `AgencyScope` for this query (`withoutGlobalScope(AgencyScope::class)`), filter strictly by owner-role names. This is the documented opt-in pattern (`AgencyScope.php:25`).

### R2. Owner-only access
Route gated by `owner_only` middleware. No new permission key — owners manage owners. Sidebar entry rendered only when `$isOwner` is true.

### R3. Phase 1 actions
- View list
- Toggle active/disabled

Out of scope for Phase 1: create, edit role/permissions, delete. Those land with the Developer Role Manager spec.

---

## Data model

No schema changes. Uses existing `users` + `roles`.

---

## UI / UX

Page at `/admin/developer-users`, named route `admin.developer-users.index`.

Columns: Name · Email · Role · Status · Actions (Toggle status).

Sidebar entry under the existing **SYSTEM DEVELOPER — System Owners only** group in `corex-sidebar.blade.php`, alongside Dev Settings / Importer.

---

## Files

**Create:**
- `app/Http/Controllers/Admin/DeveloperUserController.php`
- `resources/views/admin/developer-users/index.blade.php`

**Modify:**
- `routes/web.php` — owner-only group for `admin.developer-users.*`
- `resources/views/layouts/corex-sidebar.blade.php` — new entry inside the System Developer group

---

## Acceptance criteria

- [ ] `/admin/developer-users` loads for an owner in **any** agency context (default, or switched into A, or switched into B) and shows the same list
- [ ] Returns 403 for non-owner users
- [ ] Sidebar entry appears only for owners
- [ ] Toggle active/disabled persists
- [ ] `dev-check.ps1` passes with 0 new failures
- [ ] Route is registered and named

---

## Out of scope

- Create / edit / delete Developer Users from the UI
- Developer Role Manager (granular permissions)
- Audit log of Developer cross-agency actions
