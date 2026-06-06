# Spec — Soft Deletes Register (Admin)

> Status: Approved (design questions answered by Andre, 2026-06-06)
> Module owner: Admin
> Last updated: 2026-06-06

---

## 1. What this feature does and why

CoreX has a platform-wide **no-hard-deletes** policy (non-negotiable #1). Every
"Delete" a user sees is a soft delete (`deleted_at` via Laravel `SoftDeletes`).
Today there is no single place where an admin can see what has been archived or
bring it back — recovery means a developer running Tinker.

The **Soft Deletes Register** is one Admin screen that surfaces **every**
soft-deleted record across **all ~127 models** that use the `SoftDeletes`
trait, grouped by category, each category showing a live count of how many
records sit archived. An admin opens a category, sees the archived records, and
**restores** any of them with one click.

This is **restore-only**. There is no purge / force-delete button anywhere in
this UI — that fully honours non-negotiable #1 ("No hard deletes. Ever.").
Permanent purging remains a deliberate, separate console-only operation
(`php artisan db:purge-soft-deleted`), never exposed in the web UI.

## 2. Pillars

Reads from **all four pillars** and every other module: Property, Contact, Deal
(V1 + V2), User (Agent), plus Branch, Document, Template, Presentation,
Compliance, Prospecting, Payroll, Leave, Command Center, etc. It writes back by
**restoring** records into their pillar (clears `deleted_at`), and writes an
audit row recording who restored what and when.

## 3. Data model / migrations

One new table — the restore audit trail. We do **not** touch the 127 existing
tables (they already carry `deleted_at`).

`soft_delete_restorations`
| column                | type                  | notes |
|-----------------------|-----------------------|-------|
| id                    | bigint PK             | |
| model_type            | string                | FQCN of the restored model |
| model_id              | unsignedBigInteger    | PK of the restored record |
| model_label           | string, nullable      | human label snapshot at restore time |
| agency_id             | unsignedBigInteger, nullable | agency the record belonged to (for scoping/audit) |
| restored_by_user_id   | unsignedBigInteger    | who clicked restore |
| restored_at           | timestamp             | when |
| timestamps            |                       | |

Index: (`model_type`, `model_id`), (`agency_id`), (`restored_by_user_id`).

Model: `App\Models\SoftDeleteRestoration` (uses `BelongsToAgency` so the audit
trail is itself agency-scoped).

## 4. UI placement & navigation

- **Sidebar → Admin section → "Soft Deletes"** link (gated by the new
  `access_soft_deletes` permission). Added the same day per non-negotiable #2.
- Pages:
  - `/admin/soft-deletes` — category grid. Each card = one model category,
    showing the model label and its archived count.
  - `/admin/soft-deletes/{key}` — list of archived records for one model, each
    with a **Restore** button (with confirmation).

Counts shown **per category only** (and per model row) — no sidebar badge, to
avoid a count query on every page load.

## 5. User flow

1. Admin opens **Admin → Soft Deletes**.
2. Sees every model that has at least one archived record (and, optionally,
   models with zero — see Acceptance), grouped by category (Pillars first), each
   with a count.
3. Clicks a category → sees the archived records (label, id, when deleted, who).
4. Clicks **Restore** on a row → confirmation → record's `deleted_at` cleared,
   it reappears in its normal module. An audit row is written.
5. Restored row disappears from the register; the count drops.

## 6. Permissions

- New permission key **`access_soft_deletes`** (`type: access`, `section:
  admin`, `module: soft_deletes`) in `config/corex-permissions.php`.
- Synced into `nexus_permissions` via `php artisan corex:sync-permissions` →
  appears in Role Manager so an admin can grant it to **any other role**
  (this satisfies Andre's requirement: "sit under admin only but in role
  manager add the settings to be able to give access to other roles").
- Default-granted to `admin` (added to `role_defaults.admin.include`).
  `super_admin`/owner already gets it via the `*` wildcard.
- The link also sits inside the existing `@permission('sidebar.section.admin')`
  wrapper, so a role needs both `sidebar.section.admin` and
  `access_soft_deletes` to see it — identical to every other Admin link
  (Finance Engine, Role Manager, etc.).
- Routes gated with `->middleware('permission:access_soft_deletes')`.

## 7. Multi-tenancy & security (critical)

- Counts and record lists are produced with each model's **own global scopes
  still applied** — `Model::onlyTrashed()`. For agency-scoped models the
  `AgencyScope` therefore filters to the current user's agency automatically;
  an agency admin only ever sees / restores their **own** agency's archived
  records.
- **Non-owner users only see agency-scoped models** (those using
  `App\Models\Concerns\BelongsToAgency`). Global/system models (e.g. `Agency`,
  AI caches) are hidden from non-owners so they can never restore another
  tenant's data. **Owner roles** (`isOwnerRole()`) see all models.
- The restore endpoint resolves the model **only** from the discovered registry
  whitelist filtered for the current user — an arbitrary class name in the URL
  is rejected. The record is fetched via `onlyTrashed()->find($id)` under the
  model's scopes, so a cross-agency id 404s.
- No `withoutGlobalScope(AgencyScope::class)` anywhere (non-negotiable #7).

## 8. Files to create / modify

Create:
- `database/migrations/xxxx_create_soft_delete_restorations_table.php`
- `app/Models/SoftDeleteRestoration.php`
- `app/Services/Admin/SoftDeleteRegistryService.php`
- `app/Http/Controllers/Admin/SoftDeleteController.php`
- `resources/views/admin/soft-deletes/index.blade.php`
- `resources/views/admin/soft-deletes/show.blade.php`
- `tests/Feature/Admin/SoftDeletesRegisterTest.php`

Modify:
- `routes/web.php` — 3 routes under the auth group.
- `config/corex-permissions.php` — new permission + `role_defaults.admin`.
- `resources/views/layouts/corex-sidebar.blade.php` — Admin link + add
  `access_soft_deletes` to the section's `hasAnyPermission` gate.
- `database/schema/mysql-schema.sql` — re-dumped after the migration.

## 9. Discovery service contract

`SoftDeleteRegistryService`:
- `modelsFor(User $user): Collection` — scans `app/Models/**/*.php`, reflects
  each class, keeps concrete `Model` subclasses using `SoftDeletes`; filters to
  agency-scoped only for non-owners; returns entries `{ key, class, label,
  category, agency_scoped }`. `key` = relative class path with `\` → `.`.
- `categoriesWithCounts(User $user): Collection` — `modelsFor` + `onlyTrashed()
  ->count()` per model (each wrapped in try/catch — a model whose scope errors
  is skipped, never fatal), grouped by category, Pillars category first.
- `resolve(string $key, User $user): ?string` — key → FQCN, only if in the
  user's visible registry (whitelist). Returns null otherwise.
- `trashedRecords(string $class, int $perPage)` — `onlyTrashed()
  ->orderByDesc('deleted_at')->paginate()`, each decorated with a human label
  via an attribute-priority heuristic (`name`, `full_name`, `title`,
  `reference`, `deal_no`, `email`, … → `#id`).
- `restore(string $class, int $id, User $user): bool` — fetch via
  `onlyTrashed()->find($id)` (scoped), `restore()`, write a
  `SoftDeleteRestoration` audit row. Returns false if not found / not visible.

## 10. Acceptance criteria

1. **Admin → Soft Deletes** link visible to users with `access_soft_deletes`
   (and owners); hidden otherwise. Route 403s without the permission.
2. Index lists model categories. A model with N archived records shows the
   count N. Soft-deleting a Property then opening the register shows Property
   count incremented; restoring it decrements the count and the Property
   reappears in My Listings.
3. Agency isolation: a user of Agency A never sees Agency B's archived records;
   non-owners never see global/system models.
4. Restore writes a `soft_delete_restorations` row (model_type, model_id,
   restored_by_user_id, restored_at, agency_id).
5. **No purge/force-delete control exists** anywhere in the UI.
6. Discovery never fatals: a model whose `onlyTrashed()->count()` throws is
   silently skipped, the page still renders.
7. `php artisan corex:sync-permissions` lists `access_soft_deletes` in Role
   Manager.
8. `scripts/dev-check.ps1` passes with the new feature test green.

## 11. Out of scope (v1)

- Cascade restore of dependent child records (Laravel does not cascade-restore;
  the original delete did not cascade-soft-delete). Restore reverses the single
  record's `deleted_at`. Noted for a future enhancement if a real workflow needs
  it.
- Per-model "Restored" domain events — restore is an admin recovery action with
  no cross-pillar cascade requirement, so non-negotiable #9 does not apply.
  Revisit if a restore must trigger downstream recomputation.
