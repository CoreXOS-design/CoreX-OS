# Staging → Live Release-Readiness Audit

**Date:** 2026-06-26
**Mode:** INVESTIGATION ONLY — no fetched-branch changes, no merges, no cherry-picks performed.
**Repo:** `CoreXOS-design/CoreX-OS` (origin). Fresh `git fetch --all --prune` taken.
**Question (Andre):** Of the work on `origin/Staging` not yet on `origin/main` (live), which is
finished and promotable, and which is in-progress / not-ready?

---

## 0. The headline — SHA count is misleading

- `git rev-list --count origin/main..origin/Staging` = **66 commits**
- `git rev-list --count origin/Staging..origin/main` = **0** → **Staging is a strict superset of main.**

But **most of those 66 commits are already live in content.** AT-93/91/94/41/83/81 were promoted
to live as **squashed cherry-picks** (different SHAs), so their original Staging commits still show
as "not on main" even though the code is identical. The reliable signal is the **two-dot tree
diff** (`git diff origin/main..origin/Staging`), not the commit list.

**Real content delta = 90 files, +5,854 / −1,806**, and it contains ONLY Andre's 7 feature groups
+ a FlowMap decommission. The backlog features are NOT in it — confirmed below.

---

## 1. ALREADY EFFECTIVELY LIVE (no-op for promotion)

Verified present on `origin/main` by content (`git grep` on the live branch), despite their
original Staging SHAs appearing in `main..Staging`:

| Feature | Live-branch evidence | Verdict |
|---|---|---|
| **AT-93** Maintenance mode | `maintenance_mode` in 7 files, `AgencyMaintenanceGate` in 5, `MaintenanceModeCommand.php` | **NO-OP — live** |
| **AT-91** WhatsApp Outreach Summary | `outreach-summary` in 10 files, `whatsapp-outreach-summary.md` spec | **NO-OP — live** |
| **AT-94** Compliance / Drive gate | `compliance_checks_disabled` in 4 files (globally OFF via DevSetting) | **NO-OP — live** |
| **AT-41** Guided tours (driver.js) | `driver.js` in 5 files | **NO-OP — live** |
| **AT-83 / AT-81** OG agent card / PENDING badge | `ds-orange` token in 6 files | **NO-OP — live** |

➡️ **Do not attempt to "promote" these.** They are already on main. Their presence in the SHA
delta is cherry-pick/merge noise. Roughly 50 of the 66 commits are these features + Andre's
interleaved `fix`/merge commits.

---

## 2. GENUINE DELTA — Andre's unmerged feature groups

All seven carry a **spec** (`.ai/specs/*`) **and** a **test file**, contain **no WIP markers**
(scan for `dd(`/`dump`/`FIXME`/"not for live" = clean; only HTML `placeholder=` and the `todo`
task-status enum matched), and were **last touched 2026-06-23/24** (nothing churning on the 26th).
Their migrations are **additive and already ran clean on the live-data clone** `hfc_staging`.

| # | Feature | Spec | Test (≈tests/asserts) | Migration(s) | Touches fragile area? | Verdict |
|---|---|---|---|---|---|---|
| **A** | **Agency-scoped roles & permissions rework** | `roles-permissions.md` | AgencyScopedRoles 5/14 | `make_roles_unique_per_agency`, `add_agency_id_to_role_permissions`, `backfill_agency_scoped_roles_and_permissions` (DATA) + `SyncPermissions` rewrite | **YES — AUTH pillar + data backfill** | **READY — HIGH CARE** |
| **B** | **Command Center visibility scope** (role-based Calendar/Tasks/Today) | `spec-command-center.md` | VisibilityScope 4/20 | `default_command_center_visibility_scope` (additive) | Depends on **A** (permission infra) | **READY (after A)** |
| **C** | **SharedDrive enhancements** | `shared-drive.md` | SharedDrive 13/54 | `create_shared_drives`, `create_shared_drive_access`, `add_drive_id_to_shared_drive_tables` (new tables) | No — isolated (Documents) | **READY** |
| **D** | **Agent deletion / deal reassignment** | `agent-delete-reassignment.md` | AgentDeleteDealReassignment 6/18 | `add_managed_by_user_id_to_deals` (nullable FK, NULL for all existing) | Touches `deals` (additive) | **READY** |
| **E** | **Admin multi-branch manager** | `admin-multi-branch-manager.md` | AdminMultiBranchManager 8/27 | `create_user_managed_branches` (new table) | Depends on **A** (role gating) | **READY (after A)** |
| **F** | **Rental images** (in/out inspection galleries) | `rental-images.md` | RentalImages 5/27 | `add_rental_images_json_to_properties` (nullable JSON) | Touches `properties` (additive) | **READY** |
| **G** | **ContactType / ContactTag + Promote-owner-to-seller listener** | (in `multi-tenancy.md`/contacts) | ContactTypeAssignment 15/49 | `seed_owner_other_contact_parents` (DATA seed) | Contact pillar (additive) | **READY** |
| **—** | **FlowMap tool removal** (decommission) | `flows-map.md` **deleted** | FlowMapTest deleted | none | Dead-tool removal | **READY — cleanup/NO-OP** |

**NOT READY: none.** No WIP, no partial builds, no stray experimental code was found in the genuine
delta. The "wholesale merge drags NOT-READY work into prod" risk that usually justifies
cherry-picking **does not materialise here** — the delta is entirely intended, finished, tested work.

---

## 3. Migration safety (all run on live's 5,539 props / real agents)

| Migration | Shape | Safety |
|---|---|---|
| `add_rental_images_json_to_properties` | nullable JSON column, additive | **SAFE** — no backfill, no default churn |
| `add_managed_by_user_id_to_deals` | nullable FK `nullOnDelete`, NULL for existing | **SAFE** — additive, no recompute of existing deals |
| `create_shared_drives` / `_access` / `add_drive_id` | new tables + nullable FK | **SAFE** — isolated |
| `create_user_managed_branches` | new table | **SAFE** |
| `default_command_center_visibility_scope` | seeds default scope | **SAFE** — additive default |
| `seed_owner_other_contact_parents` | seeds contact-type parents | **SAFE** — idempotent seed |
| `make_roles_unique_per_agency` + `add_agency_id_to_role_permissions` | schema change on `roles`/`role_permissions` | **CARE** — auth tables; unique constraint |
| `backfill_agency_scoped_roles_and_permissions` | **DATA migration** cloning global roles → per-agency | **CARE but well-built** — header declares NON-DESTRUCTIVE + IDEMPOTENT, clone-only-if-absent, nothing deleted/overwritten |

**Strong positive signal:** all 11 migrations **already ran cleanly on the live-data clone**
`hfc_staging` during its last refresh — the clone now shows `role_permissions.agency_id` present
(2,406 scoped grants), 10 agency-scoped roles, with only **2 agencies** and **5 global non-owner
template roles** → backfill blast radius is small and bounded. Prod (`nexus_os`) does **not** yet
have these, so the migrations WILL run on live — do it in a maintenance window.

---

## 4. Safe promotion path

**Key structural fact:** Staging ⊃ main, and the only delta is these finished, **interdependent**
features. B, E and app-wide role gating consume the **shared permission infrastructure** changed by
A (`PermissionService`, `RoleProvisioningService`, `SyncPermissions`). Andre's commits are
interleaved `fix`/merge commits, so **per-feature cherry-pick is NOT clean here** — you cannot lift
B or E without A, and isolating one feature's commits from the merge-heavy history is error-prone.

Given the delta is all-ready and interdependent, the cleanest **and** safest route is a **single
controlled release**, not a piecemeal cherry-pick:

1. **Validate on a fresh live clone first** (the gate, because A touches auth):
   `git checkout -b release/2026-06-26 origin/main` → `git merge --no-ff origin/Staging` → run
   migrations on a clone of `nexus_os` → smoke-test: (a) agents still log in, (b) permission gating
   resolves correctly per agency, (c) Command Center visibility, SharedDrive, multi-branch, rental
   images, agent-deletion, contact-type all behave. Tests: run the 7 feature test files (not the
   full suite — non-negotiable #13).
2. **Promote:** fast-forward/merge `release/2026-06-26` → `main`, push.
3. **Deploy in a maintenance window** (use AT-93 maintenance mode): `git pull` on `/corex` →
   `php artisan migrate --force` → `view:clear` + `config:clear` → `php artisan sync-permissions
   --merge-defaults` (role drift, per prior live promotes) → reload php8.3-fpm (live pool).
4. **Post-deploy verify:** login + a permission-gated page per role + one action in each new feature.

**If Johan wants to hold A (the roles rework):** there is **no clean cherry-pick** that keeps B/E/
app role-gating working without it. The only safe options are (a) promote the whole set together
after clone-validation (recommended), or (b) hold the *entire* delta until A is signed off. Do not
attempt to surgically extract C/D/F/G alone expecting them to be wholly independent of the
permission-infra changes without a clone test — verify on a clone first if going that route. (C, F
genuinely look standalone; D, G mostly; B, E do not.)

---

## 5. Risk flags (looks-ready, handle with care)

1. **🔴 A — Agency-scoped roles & permissions = AUTH pillar + live data backfill.** Biggest blast
   radius in the whole delta. Idempotent and already clean on the live clone, but a permission-model
   change must be functionally validated (login + per-agency gating) on a `nexus_os` clone before it
   touches prod. This is the one feature where "it has a passing test" is not sufficient sign-off.
2. **🟠 `properties` table touched** (rental_images_json) — additive/nullable, safe, but it is the
   Property pillar table; confirm no interaction with the AT-P24 importer/mapper already on main
   (the column is new, so no overlap expected — but the importer is the fragile shared area).
3. **🟠 `deals` table touched** (managed_by_user_id) — additive nullable FK; existing deals keep
   resolving the branch manager the role-based way. Low risk.
4. **🟡 SyncPermissions rewrite** — shared with every feature; run `sync-permissions
   --merge-defaults` post-deploy (drift backfilled on prior live promotes — known gotcha).
5. **🟢 NOT touched by this delta:** P24 syndication, the importer/mapper, the status model, e-sign
   pipeline. The AT-P24 hide-address/syndication work is already on main and is independent of this
   release. Good separation.

---

## 6. One-line verdict

The Staging→live delta is **clean and finished** — 7 specced, tested, additively-migrated feature
groups plus a dead-tool removal, **zero WIP**. Promote them as **one clone-validated release**
(per-feature cherry-pick is unsafe because they share auth/permission infrastructure), gating on a
functional validation of the **agency-scoped roles rework (A)** — the only high-blast-radius piece.
Everything labelled "backlog" (AT-93/91/94/41/83/81) is **already live** and needs no action.
