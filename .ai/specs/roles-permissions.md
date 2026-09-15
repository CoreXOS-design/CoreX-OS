# Roles & Permissions — Agency-Scoped — Spec

> Status: IMPLEMENTED on `Staging` 2026-06-23 (local verified). Prod rollout
> still gated on Johan sign-off + manual SQL backup (§7).
> Created: 2026-06-23
> Owner: Andre
> Severity: Security-critical (multi-tenancy). Treat regressions as P0.
> Related: `.ai/specs/multi-tenancy.md`, `.ai/specs/corex-domain-events-spec.md`

---

## 1. Problem — roles look agency-scoped, permissions are globally shared

Today the system is a hybrid that leaks role configuration across agencies:

- `roles` table **has** `agency_id` (nullable). The 5 defaults (`super_admin`,
  `admin`, `branch_manager`, `agent`, `viewer`) are seeded with
  `agency_id = NULL` (treated as global templates). `roles.name` carries a
  **global `UNIQUE` constraint**.
- `users.role` is a **string** FK pointing at `roles.name` (not an id).
- `role_permissions` is keyed by `(role, permission_key)` with **no
  `agency_id`**. `PermissionService::getPermissionsForRole($role)` resolves
  by role name only.

**Consequence:** when Agency A edits what its `admin` can do in Role Manager
(`savePermissions` does `RolePermission::where('role','admin')->forceDelete()`
then re-inserts), it rewrites the `admin` grants for **every** agency. Roles
appear isolated; permission grants are globally shared. This violates
non-negotiable #7 (multi-tenancy) for the role/permission layer.

Different agencies must be able to run different roles with different rules.

---

## 2. Target model (approved direction)

**Per-agency role copies + full per-agency role CRUD.**

| Concept | Rule |
|---------|------|
| **Owner roles** (`is_owner = true`) | Stay **global**, `agency_id = NULL`, singular. Platform identities. Not shown in Role Manager. Bypass all permission checks. Unchanged. |
| **All non-owner roles** | Become **per-agency**. Each agency owns its own copies (`agency_id` set) and may create / rename / delete its own roles independently of other agencies. |
| **Permission grants** (`role_permissions`) | Become **per-agency**: keyed by `(agency_id, role, permission_key)`. Each agency's customisations are isolated. |
| **Templates** | The global `agency_id = NULL` non-owner rows are retained **only as seed templates** for provisioning new agencies. They are never resolved for a real (agency-bound) user. |
| **User → role** | `users.role` string is unchanged. Resolution becomes `(users.role name + user.effectiveAgencyId())` → that agency's role copy. |

Pillar connection: **Agent** (`User` role/permissions) — and gates access to
every other pillar. No new pillar tables.

---

## 3. Data model / migrations

### 3.1 `roles` table
- Drop global `UNIQUE(name)`.
- Add composite `UNIQUE(name, agency_id)`.
  (MySQL treats NULLs as distinct, which is fine — owner roles stay NULL and
  singular; per-agency rows are unique within their agency.)
- `agency_id` stays nullable: `NULL` = owner-role/template; set = agency-owned.
- Add index `(agency_id, sort_order)`.

### 3.2 `role_permissions` table
- Add `agency_id` (unsigned big int, nullable FK → agencies, `nullOnDelete`).
- Drop `UNIQUE(role, permission_key)`; add `UNIQUE(role, permission_key, agency_id)`.
- Add index `(agency_id, role)`.

### 3.3 Data backfill migration (production — see §7 risk)
For every existing agency:
1. Clone each global non-owner role (`agency_id IS NULL`, `is_owner = false`)
   into an agency-scoped copy (`agency_id = <agency>`), preserving
   `name/label/description/color/sort_order/oversight_scope/can_be_deleted`.
2. Clone the matching `role_permissions` rows (by role name) into agency-scoped
   rows (`agency_id = <agency>`), preserving `scope`.
Owner roles + their (bypassed) grants are left global. The original global
non-owner rows remain as templates.

Run `php artisan schema:dump` after the migrations land (non-negotiable #12a)
and commit the snapshot in the same commit.

---

## 4. Resolution layer changes

### 4.1 `App\Services\PermissionService`
- `getPermissionsForRole(string $role, ?int $agencyId)` — cache key
  `"{agencyId}:{role}"`; query `where('role',$role)->where('agency_id',$agencyId)`.
- `getScopesForRole` — same agency filter.
- `getDataScope` / `userHasPermission` / `userHasAnyPermission` /
  `calendarScope` / `taskScope` — pass `$user->effectiveAgencyId()` through.
- `$seeded` check becomes agency-aware (an agency with zero grants must not
  silently fall through to "allow all" once another agency is seeded — gate on
  per-agency existence). Keep the genuinely-unseeded fresh-DB/test fallback.

### 4.2 `App\Models\Role`
- `allRoles(?int $agencyId)` — cache per agency; return owner roles (NULL) +
  the agency's own roles, ordered by `sort_order`.
- `roleNames(?int $agencyId)`, `ownerRole()` unchanged semantics.
- `clearCache()` clears the whole per-agency cache map.
- `scopeForAgency` retained for template/admin queries.

### 4.3 `App\Models\RolePermission`
- Add `agency_id` to `$fillable`.

### 4.4 Callers (audited list — 10 files)
`RoleManagerController`, `SettingsController` (CoreX + CommandCenter),
`User.php`, `UserManagementController`, `ContactGovernanceController`,
`DeveloperUserController`, `SyncPermissions`. Each call to the resolution
methods gets the caller's agency id threaded in. None may resolve permissions
without an agency context for an agency-bound user.

---

## 5. Role Manager (UI + controller)

`RoleManagerController` — every query scoped to
`auth()->user()->effectiveAgencyId()`:
- `index` — roles, grants, scope lookups all filtered by agency.
- `savePermissions` — delete+insert scoped to `(role, agency_id)`; stamp
  `agency_id` on inserts. Validate `role` against **this agency's** role names.
- `copyPermissions` — source/target validated within agency; rows stamped.
- `storeRole` — create with current `agency_id`; uniqueness checked within
  agency; seed from **this agency's** `agent` role.
- `updateRole` / `destroyRole` — operate only on roles owned by this agency
  (route-model binding + ownership guard; reject cross-agency `Role` ids with
  403/404). Reassign-on-delete limited to this agency's roles.

Owner (platform) acting without an active agency switcher: Role Manager
requires an active agency context — document that owners must switch into an
agency to edit its roles (consistent with multi-tenancy spec §3).

UI: no visible feature changes — same matrix, tabs, CRUD. Restyle rules N/A
(this is behavioural, not cosmetic). Navigation entry already exists
(`corex.role-manager`).

---

## 6. Agency provisioning (domain event — non-negotiable #9)

On agency creation, provision its role set. Use the events catalogue:
- Subscribe a listener to the agency-created event (add `AgencyCreated` to the
  catalogue if absent — confirm before inventing).
- Listener calls a `RoleProvisioningService::provisionForAgency(Agency)` that
  clones the template non-owner roles + their `role_defaults` grants from
  `config/corex-permissions.php` into the new agency.
- Idempotent: re-running must not duplicate.

`SyncPermissions` (`corex:sync-permissions`):
- `--merge-defaults` must fan out across **all** agencies — merge new config
  keys into every agency's matching role, never overwriting customisations.
- `--seed-defaults` (first install only) provisions every agency.
Update `.ai/specs/multi-tenancy.md` §"Permissions sync after deploy" to note
the per-agency fan-out. Future permission-seeding migrations MUST be
agency-aware (the 10 historical ones already ran; they become templates).

---

## 7. Production risk & rollout (REQUIRES JOHAN SIGN-OFF)

- Prod has **no automatic backups** (per session memory). A manual SQL dump of
  `roles` + `role_permissions` + `users.role` MUST be taken before the backfill
  migration runs on prod.
- Roll out: local → staging (verify isolation) → demo → prod.
- Backfill is forward-only; design `down()` to drop added columns/indexes but
  document that cloned data is not auto-removed.

---

## 8. Acceptance criteria

- [ ] Agency A editing its `admin` permissions does **not** change Agency B's
      `admin` (verify in tinker + Role Manager as two agencies).
- [ ] Agency A can create a role ("Rentals Manager") invisible to Agency B.
- [ ] Renaming/deleting a role in A leaves B's same-named role intact.
- [ ] A user of A resolves permissions from A's copy
      (`PermissionService::userHasPermission`).
- [ ] Owner roles remain global, singular, bypass checks, not in Role Manager.
- [ ] Creating a new agency auto-provisions the full role set + default grants.
- [ ] `corex:sync-permissions --merge-defaults` adds a new key to every
      agency's matching role without wiping customisations.
- [ ] No `role_permissions` row exists without an `agency_id` (except
      owner-role grants, which are bypassed anyway).
- [ ] Cross-agency `Role` id in Role Manager CRUD → 403/404.
- [ ] Single most-relevant test file passes (per non-negotiable #13 — no broad
      suite without go-ahead).

## 9. Files to create / modify

**Migrations (new):** alter `roles` unique; alter `role_permissions` + backfill.
**Modify:** `app/Models/Role.php`, `app/Models/RolePermission.php`,
`app/Services/PermissionService.php`,
`app/Http/Controllers/CoreX/RoleManagerController.php`,
plus the audited callers in §4.4.
**New:** `app/Services/RoleProvisioningService.php` + agency-created listener.
**Modify:** `app/Console/Commands/SyncPermissions.php` (per-agency fan-out),
`.ai/specs/multi-tenancy.md` (sync note).
**Tests:** one focused feature test for cross-agency isolation of role/permission edits.

---

## 10. Addendum (2026-09-07) — SyncPermissions silent-failure fix

Found while wiring the Rental Applications module's `role_defaults` entries
(a Rental Applications lane task, not a roles-permissions design change —
recorded here because the defect and fix are squarely inside
`app/Console/Commands/SyncPermissions.php`, one of this spec's own §9
files). Johan: "the platform wide bug - needs attention and get fixed. hate
silent fails."

**The defect.** `seedRoleDefaults()`/`mergeRoleDefaults()` both did:

```php
try {
    $roles = Role::all(['name', 'is_owner', 'agency_id']);
} catch (\Throwable $e) {
    $roles = collect(<synthetic template roles from role_defaults keys>);
}
```

`Role::all()` on a genuinely EMPTY `roles` table (the real state until Role
Manager or a seeder populates it — nothing seeds it automatically, and this
spec's own §8 acceptance criterion "Creating a new agency auto-provisions
the full role set + default grants" describes exactly the moment this bug
would fire) returns an empty `Collection`; it does not throw. The catch
never engaged, the synthetic fallback never ran, the per-role loop iterated
zero times, and the command printed a "0 new row(s) inserted" success line
and exited 0 — a fresh environment (or a fresh agency, or a QA1 reset)
looked healthy while every permission in CoreX was silently missing, for
every module, not just the one being actively developed at the time.

**The fix.**
- New shared `resolveRolesOrFail()`: treats an empty result the same as a
  thrown one, always tells the operator which path was taken (real DB rows
  vs. synthetic fallback) via `$this->warn()` — never silently — and
  returns `null` (a hard failure the caller must not ignore) only when even
  the config-driven fallback has nothing to offer (`role_defaults` itself
  empty).
- `seedRoleDefaults()`'s destructive `RolePermission::query()->forceDelete()`
  wipe-and-reseed now checks role resolution BEFORE the wipe, not after —
  the old code would have wiped every existing grant and replaced it with
  nothing, on the exact same empty-roles-table trigger.
- `mergeRoleDefaults()` now tracks pending-keys-vs-inserted-rows across the
  whole run and fails loudly (`$this->error()` + non-zero exit) if keys
  were genuinely pending but zero rows landed — a merge that finds nothing
  to do because everything is already correct is NOT this condition
  (that's the normal idempotent no-op); a merge that found real gaps and
  still inserted nothing is.
- `handle()` now propagates both methods' success/failure into the
  command's own exit code — a chained deploy script can no longer sail
  past a run that granted nothing.
- Also caught and surfaced (not separately hard-failed): a `role_defaults`
  entry that exists for a role but resolves to zero keys via
  `RoleDefaultsResolver::keysForDef()` (e.g. a typo'd `'inlcude'` instead of
  `'include'`) previously read identically to a healthy "up to date" role
  in the merge summary — now flagged as its own WARNING line naming the
  role, so a malformed config entry cannot hide behind a healthy-looking
  status.

**Proven, defect demonstrated before the fix** (per the project's "show
both behaviours" standard) in
`tests/Feature/Permissions/SyncPermissionsResilienceTest.php`: a faithful
reproduction of the exact pre-fix snippet against a real, genuinely empty
`roles` table returns an empty Collection without throwing (the root
cause, isolated); the fixed command recovers via the fallback and grants
correctly with loud warnings on that same empty table; the fixed command
fails loudly with a non-zero exit when even the fallback has nothing
(`role_defaults` forced empty); a normal populated run is unaffected and
remains idempotent on a second call. `RentalApplicationPermissionDefaultsTest.php`
(Rental Applications' own clean-state proof) re-confirmed passing under
the fixed code unchanged.

**Found and reported, not touched (report-only, outside this fix's
responsibility):** `corex:reconcile-role-grants` (the drift-reconciler
sibling command) does not share this exact `Role::all()`/try-catch
pattern — checked, not affected. `RoleDefaultsResolver::keysForDef()`
itself (shared by both `corex:sync-permissions` and
`corex:reconcile-role-grants`) silently returns `[]` for ANY unrecognised
`role_defaults` shape, not just the specific "entry exists but malformed"
case this fix now surfaces from the `SyncPermissions` side — a deeper fix
inside the shared resolver itself would need to consider `reconcile-role-grants`
too, which is outside this command's own responsibility per the scope
given for this task.
