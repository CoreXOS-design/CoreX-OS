# CoreX OS — Shared Drive Spec

> Status: DRAFT — Awaiting approval (Johan/Andre)
> Module: Documents → Shared Drive (new standalone module)
> Author: Andre
> Last updated: 2026-06-19

---

## 1. What this feature does and why

A **Shared Drive** is a Google-Drive-style team file store living under the
**Documents** area. Any agency user with access can create nested folders and
upload, view, download, and (per permission) delete files. It is the agency's
shared filing cabinet for documents that are **not** tied to a specific
Contact/Property/Deal — policies, brochures, templates-as-PDF, branch SOPs,
marketing assets, supplier docs, etc.

**Why:** today the only document homes are the per-pillar drives (Contact
Drive / Property Drive, see `Spec document types drive.md`) and DocuPerfect.
There is no neutral, shared, folderable space for general agency files. Agents
email these around or keep them on personal machines. The Shared Drive gives
the agency one governed, permissioned, audit-tracked place for them.

### Relationship to the existing "Document Types, Drive & Filing" spec
This is a **separate module**. That spec files *signed/split* documents against
the **Contact** and **Property** pillars automatically. The Shared Drive is a
**manual, folder-organised** store not anchored to a single pillar record. They
do not share tables. (Future enhancement, out of scope here: a "Save to Shared
Drive" action from a filed document.)

---

## 2. Pillar connections

The Shared Drive is an **agency-level** store, so its primary spine connection
is the **Agent/Agency** pillar (every row carries `agency_id` via
`BelongsToAgency`; access is governed by the Agent's role permissions).

- **Reads from:** `User` (uploader, audit), `Agency` (tenant isolation).
- **Writes back to:** nothing in other pillars in v1 — it is a neutral store.
- Per non-negotiable #4, the module connects to the **Agent/Agency** pillar as
  its spine. It deliberately does **not** create per-Contact/Property islands —
  that is exactly what the existing Contact/Property Drive already does.

> Note: this satisfies "connect to at least one pillar" via Agency/Agent. If
> reviewers want a hard Contact/Property link in v1, that changes the data
> model (add nullable `contact_id`/`property_id` to folders) — flag at review.

---

## 3. Data model / migrations

Two new tables. Both tenant-owned (`agency_id`, `BelongsToAgency`,
`AgencyScope`), both soft-deleting (non-negotiable #1).

### `shared_drive_folders`
```
id                  bigint PK
agency_id           bigint  FK agencies  (BelongsToAgency)
parent_id           bigint  nullable FK shared_drive_folders (self) — null = root
name                string
created_by_user_id  bigint  FK users
created_at / updated_at
deleted_at          (soft delete)

INDEX (agency_id, parent_id)
INDEX (agency_id, deleted_at)
```
- Nested subfolders via self-referencing `parent_id` (unlimited depth).
- Unique folder name within the same `(agency_id, parent_id)` enforced in the
  controller (case-insensitive), not a DB unique (soft-deletes complicate a DB
  unique; controller check excludes trashed).

### `shared_drive_files`
```
id                  bigint PK
agency_id           bigint  FK agencies  (BelongsToAgency)
folder_id           bigint  nullable FK shared_drive_folders — null = drive root
original_name       string   (display name, e.g. "Branch SOP.pdf")
stored_path         string   (relative path on the storage disk)
mime_type           string nullable
extension           string(16) nullable
bytes               unsignedBigInteger default 0
uploaded_by_user_id bigint  FK users
created_at / updated_at
deleted_at          (soft delete)

INDEX (agency_id, folder_id)
INDEX (agency_id, deleted_at)
```

### Storage
- Disk: `local` (`storage/app/private`) — same as Document Library.
- Path convention: `shared_drive/{agency_id}/{YYYY}/{MM}/{slug}-{rand}.{ext}`.
- Files are served through the controller `download`/`view` routes (auth +
  permission gated) — never a public URL. Agency isolation is enforced by the
  global scope on the model lookup, so cross-tenant path access is impossible.

### Allowed types & size (server-enforced, not just UI)
- **Max 50 MB per file** (`max:51200` KB in validation). PHP limits already
  allow this (`upload_max_filesize=1000M` locally; confirm prod ≥ 50M).
- **Allowed:** PDF; Word (`doc`,`docx`); Excel (`xls`,`xlsx`,`csv`);
  PowerPoint (`ppt`,`pptx`); images (`jpg`,`jpeg`,`png`,`gif`,`webp`).
  Validated by both extension allow-list **and** MIME allow-list.
- **Inline view:** PDFs and images render in-browser (iframe / `<img>`).
  Office files are download-only (no inline preview in v1).

---

## 4. Permissions (Role Manager)

New permission group, module `shared_drive`, section `documents`, added to
`config/corex-permissions.php` (synced into Role Manager via
`corex:sync-permissions`). These are **role-level** and govern the whole drive
(per the agreed design — no per-folder ACLs in v1).

| key | label | type |
|-----|-------|------|
| `access_shared_drive`            | Access Shared Drive          | access |
| `shared_drive.view`              | View / browse & open files   | action |
| `shared_drive.upload`            | Upload files                 | action |
| `shared_drive.download`          | Download files               | action |
| `shared_drive.folders.create`    | Create folders               | action |
| `shared_drive.folders.delete`    | Delete folders               | action |
| `shared_drive.files.delete`      | Delete files                 | action |

**Mapping to the user's requirements:**
- "who can create folders / delete folders" → `folders.create` / `folders.delete`
- "who can upload and download pdfs" → `upload` / `download`
- "who can just view" → `view` only (no upload/download/delete)

> Note: `shared_drive.view` is intentionally **not** a `.view` data-scope key
> (no own/branch/all radios) because the Shared Drive is a single agency-wide
> space — scope would be meaningless. It is a plain action key.

**Enforcement (all three layers, per non-negotiable #5):**
1. Route middleware: `permission:access_shared_drive` on the group; per-action
   middleware on mutating routes (e.g. `permission:shared_drive.upload`).
2. Controller: `abort_unless($user->hasPermission('…'), 403)` in each method.
3. Blade: buttons gated with `@if(auth()->user()->hasPermission('…'))` so a
   view-only user never sees Upload/Delete/New-Folder buttons.

**`role_defaults` (fresh installs only):**
- `admin` / `branch_manager`: all seven keys.
- `agent`: `access_shared_drive`, `view`, `upload`, `download`,
  `folders.create` (agents organise, but not delete folders).
- `viewer`: `access_shared_drive`, `view`, `download`.

---

## 5. UI placement & navigation (non-negotiable #2)

- **Sidebar:** new sub-item **"Shared Drive"** inside the existing **Documents**
  expandable panel in `resources/views/layouts/corex-sidebar.blade.php`, gated
  by `@permission('access_shared_drive')`, active on `shared-drive.*`.
- The Documents `$activeGroup` detector is extended so `shared-drive.*` routes
  open the Documents panel.
- **URL:** `/documents/shared-drive` (kept under the documents prefix for a
  clean information architecture). Route names `documents.shared-drive.*`.

---

## 6. User flow

1. User clicks **Documents → Shared Drive** → lands on the drive root.
2. Page shows: breadcrumb (Shared Drive / …), folder grid, file list, and a
   toolbar with **New Folder** and **Upload** (each shown only if permitted).
3. **Create folder:** click New Folder → modal asks **Name** → POST → folder
   appears in the current directory. (Name required; duplicate-in-directory
   rejected with a friendly message.)
4. **Open folder:** click a folder → navigates into it (breadcrumb grows). Any
   depth supported.
5. **Upload:** click Upload (or drag-and-drop onto the list) → file picker →
   client checks type & ≤50 MB → POST multipart to current folder → file row
   appears with name, type icon, size, uploader, date.
6. **View:** click a PDF/image → opens in an in-app viewer (iframe/modal).
   Office files trigger a download instead.
7. **Download:** download icon on each row (if permitted) → streams the file.
8. **Delete:** trash icon on a file/folder (if permitted) → confirm → soft
   delete. Deleting a folder soft-deletes its descendants (folders + files)
   recursively. Recoverable by admin via the existing Soft Deletes Register.
9. Empty states: "This folder is empty" with contextual New Folder / Upload
   prompts.

---

## 7. Files to create or modify

**Create**
- `database/migrations/xxxx_create_shared_drive_folders_table.php`
- `database/migrations/xxxx_create_shared_drive_files_table.php`
- `app/Models/SharedDriveFolder.php` (BelongsToAgency, SoftDeletes, self-rel
  `parent`/`children`, `files`, `creator`)
- `app/Models/SharedDriveFile.php` (BelongsToAgency, SoftDeletes, `folder`,
  `uploader`)
- `app/Http/Controllers/Documents/SharedDriveController.php`
  (`index`, `storeFolder`, `destroyFolder`, `upload`, `view`, `download`,
  `destroyFile`)
- `app/Services/Documents/SharedDriveService.php` (path building, recursive
  soft-delete, duplicate-name check, type/size validation helpers)
- `resources/views/documents/shared-drive/index.blade.php`
- `tests/Feature/Documents/SharedDriveTest.php`

**Modify**
- `routes/web.php` — new `documents/shared-drive` route group + names.
- `config/corex-permissions.php` — 7 permission keys + `role_defaults`.
- `resources/views/layouts/corex-sidebar.blade.php` — nav item + `$activeGroup`.
- `database/schema/mysql-schema.sql` — re-dump after migrations (rule #12a).

**API catalogue (non-negotiable #7):** any JSON endpoints (e.g. async upload
progress, folder tree) live under `/api/v1/shared-drive/*` with `->name()` so
they appear in Admin → API. The page itself is server-rendered Blade; if all
interactions are standard form POSTs under `documents.shared-drive.*` no
separate `/api/v1` surface is required — decide at build per the "no hidden
JSON endpoints" rule.

---

## 8. Acceptance criteria

- [ ] A permitted user creates a folder; it persists and appears on reload.
- [ ] Nested subfolders work to ≥3 levels with a correct breadcrumb.
- [ ] Upload of a 49 MB PDF succeeds; a 51 MB file is rejected (server-side)
      with a clear message; a `.exe`/disallowed type is rejected server-side.
- [ ] PDF and image open in-app; Office file downloads.
- [ ] Download streams the original file with the original name.
- [ ] Soft-deleting a folder soft-deletes its descendant folders & files;
      none are hard-deleted; admin can recover from Soft Deletes Register.
- [ ] A `view`-only user sees no Upload/New-Folder/Delete/Download buttons and
      gets 403 if they POST to those routes directly.
- [ ] All seven permissions appear in Role Manager under Documents and toggle
      correctly.
- [ ] Agency A user cannot see or fetch Agency B folders/files (global scope) —
      covered by a multi-tenant isolation test.
- [ ] Sidebar item appears under Documents and highlights on the drive pages.
- [ ] `php -l`, `view:clear`, `route:clear`, `cache:clear` clean; the single
      `SharedDriveTest.php` passes (rule #13 — no broad suite without go-ahead).

---

## 9. Excluded from v1 (future)
- Per-folder / per-user sharing ACLs (role-level only in v1).
- Move/rename/copy files & folders, drag-to-reorganise.
- In-app preview for Office files; thumbnails.
- File versioning / revision history.
- "Save to Shared Drive" action from a filed document or e-sign output.
- Full-text search inside documents (filename search only in v1).
- Per-file download/activity audit log beyond uploader + timestamps.
