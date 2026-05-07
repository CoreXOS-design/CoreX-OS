# Agency Admin Rule — Spec

> Status: **DRAFT — pending approval**
> Owner: Andre / Johan
> Created: 2026-05-07
> Related: `.ai/specs/multi-tenancy.md`

---

## Why this exists

Every agency in CoreX OS must have at least one Admin user at all times. Today an agency can be created with zero users, leaving it orphaned and unmanageable except by System Owner. This spec enforces the invariant **"every agency has ≥1 Admin"** structurally — at creation time, on Admin removal, and via permission-matrix cleanup.

Two permissions that should never have been delegable to agency-level users are also being pulled out of the matrix and made System-only:

- **Agencies** management (create / edit / delete agencies)
- **Importer** settings

These are platform-operator concerns, not tenant concerns.

---

## Pillars touched

- **Agent (User)** — Admin role assignment, lifecycle, last-Admin protection
- Indirectly: all pillars (an agency without an Admin cannot operate any pillar)

---

## Rules

### R1. New agency creation requires an Admin
When a System Owner / System Admin creates a new **live** agency, the flow is **not complete** until an Admin user is registered for that agency. Agency creation and Admin registration are atomic — if Admin registration is cancelled, the agency is not persisted.

Because a new agency has zero users, the only path is **Register New Admin** (inline user-creation form within the agency-creation wizard). The new Admin becomes the agency's first user.

**Demo agencies are exempt.** When the `is_demo` flag is checked at creation, the Admin section is hidden and skipped — the agency is created empty. Demo agencies are for showcasing/training/sales and do not need to be operable. The R3/R4 sole-Admin protections still apply *if* a demo agency later gains an Admin.

> Existing agencies (pre-rule) are **ignored** — no backfill, no forced prompt.

### R2. Admin gets full permission matrix
On creation, the new Admin is granted every permission key in the (post-cleanup) matrix. They can subsequently grant/revoke permissions for other users they create within their agency.

### R3. Last-Admin protection
If an agency has exactly one Admin, that Admin **cannot be deleted, demoted, or have their Admin role revoked** — by anyone, including themselves and System Owner. The UI surfaces this as a disabled action with a tooltip explaining why.

### R4. Admin handover flow
To remove or replace the sole Admin:
1. The current Admin (or a System Owner / System Admin) assigns a second user the Admin role.
2. Once ≥2 Admins exist, the original Admin can be demoted or deleted.

Only an **Admin of the same agency** or a **System Owner / System Admin** may assign the Admin role. Regular users cannot self-promote.

### R5. Permission matrix cleanup
Remove from the agency-level permission matrix entirely:
- `agencies.*` (all keys related to agency CRUD)
- `importer.*` / Importer Settings

These become **System Owner / System Admin only** — accessible from a System area, not the agency permission matrix. Sidebar entries gated accordingly.

---

## Data model

No new tables. Uses existing:
- `users` (`agency_id`, role)
- `agencies`
- `nexus_permissions` (table name unchanged per memory)

Add a model-level helper on `Agency`:
```php
public function admins(): HasMany   // users where role = admin
public function adminCount(): int
public function hasSoleAdmin(User $user): bool
```

---

## UI / UX

### Agency creation wizard (System Owner area)
Step 1: Agency details (name, branding, etc.)
Step 2: **Register First Admin** — required, cannot skip
  - First name, last name, email, mobile, password (or "send invite" toggle)
  - On submit: agency + admin created in a single DB transaction
Step 3: Confirmation

### User management (within agency)
- Admin list shows badge "Sole Admin — protected" when count = 1
- Demote / Delete actions on the sole Admin are disabled with tooltip:
  *"This is the only Admin for this agency. Assign another Admin before removing this user."*
- "Make Admin" action visible to existing Admins and System Owner / System Admin only

### Permission matrix
- `Agencies` row: removed
- `Importer` row: removed
- Both surfaced in a new **System Settings** area (sidebar: System → Agencies / Importer), gated to `system_owner` / `system_admin` roles only

---

## Permissions

Add / confirm:
- `system.agencies.manage` — System Owner / System Admin only
- `system.importer.manage` — System Owner / System Admin only
- `agency.admin.assign` — granted to Admin role + System roles
- `agency.admin.revoke` — granted to Admin role + System roles (subject to R3)

Remove from agency matrix:
- All `agencies.*` keys
- All `importer.*` keys

---

## Enforcement points (defence in depth)

1. **DB / model**: `User::deleting` and role-change observers throw `LastAdminException` if the action would leave the agency with zero Admins.
2. **Controller / FormRequest**: validation rejects demote/delete with a friendly error.
3. **UI**: actions disabled with tooltip — never relied on alone.
4. **Agency creation transaction**: `DB::transaction(fn() => [$agency, $admin] = ...)` — rollback on Admin failure.

---

## User flow — Create new agency (happy path)

1. System Owner: System → Agencies → **+ New Agency**
2. Fills agency details → Next
3. Fills first Admin details → Create
4. Transaction: agency row + admin user row + role assignment + full permission grant
5. Redirect to new agency dashboard, logged-in context unchanged (System Owner stays System Owner)
6. New Admin receives invite email (if invite mode) or login credentials

## User flow — Replace sole Admin

1. Current sole Admin opens Users → selects another user → **Make Admin**
2. System now has 2 Admins for that agency
3. Original Admin can now be demoted or deleted by either the new Admin or System Owner / System Admin

---

## Acceptance criteria

- [ ] Cannot complete agency creation without registering an Admin (transaction rolls back)
- [ ] New Admin has every (post-cleanup) permission granted
- [ ] Attempting to delete / demote the sole Admin returns a clear error (UI, controller, model — all three layers)
- [ ] Once a second Admin exists, the original can be removed
- [ ] `Agencies` and `Importer` rows do not appear in the agency permission matrix
- [ ] `Agencies` and `Importer` are accessible only to `system_owner` / `system_admin` roles
- [ ] Existing agencies without Admins are not modified or prompted (rule applies to **new** creations only)
- [ ] `dev-check.ps1` passes with 0 new failures
- [ ] All routes registered, named, discoverable in `/admin/api`

---

## Files to create / modify

**Modify:**
- `app/Http/Controllers/.../AgencyController.php` (or System area equivalent) — atomic create flow
- `app/Models/Agency.php` — admin helpers
- `app/Models/User.php` — `deleting` observer / role-change guard
- `database/seeders/CoreXPermissionSeeder.php` — remove `agencies.*` and `importer.*` from agency matrix; add `system.*` keys
- `resources/views/.../permissions/matrix.blade.php` — exclude removed rows
- `resources/views/components/corex-sidebar.blade.php` — gate System area, remove old links
- Agency creation views — multi-step wizard with required Admin step
- User management views — sole-Admin badge + disabled actions

**Create:**
- `app/Exceptions/LastAdminException.php`
- `app/Http/Requests/StoreAgencyWithAdminRequest.php`
- System area scaffolding for Agencies + Importer (controllers, routes, views) if not already isolated
- Tests: `tests/Feature/AgencyAdminRuleTest.php`

---

## Out of scope

- Backfilling Admins for existing agencies
- Multi-Admin invitation flows beyond the basic "Make Admin" action
- Admin-role audit log (covered separately if needed)
