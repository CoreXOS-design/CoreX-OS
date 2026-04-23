# Branch Isolation — Build Spec

> Spec file: `.ai/specs/branch-isolation-spec.md`
> Status: **Approved — ready for build**
> Author: Johan (product architect) + Claude (solution design)
> Supersedes: N/A
> Related audit: [.ai/specs/branch-isolation-audit.md](branch-isolation-audit.md)

---

## 1. Purpose

Add a second data-partitioning layer beneath `agency_id` — `branch_id` — so that an agency operating multiple physical branches can optionally isolate each branch's data (contacts, properties, deals, documents, tasks, pipelines, reports, etc.) such that users in one branch cannot see data belonging to another branch.

Branch infrastructure already exists substantially (per audit): `branches` table, `users.branch_id`, `User::effectiveBranchId()`, `view_as_branch_id` session override, `branch_manager` role with scope `'branch'`, and `docuperfect_*_branches` pivot tables. This spec is a **Phase 2 enforcement and UX layer** on top of existing plumbing, not a greenfield build.

---

## 2. Guiding principles

- **Safe default.** Split Branches ships OFF for every existing agency. No principal wakes up to find their users suddenly segmented.
- **Principal opt-in, reversible.** The toggle can be flipped ON ↔ OFF freely at any time.
- **Branch identity always visible.** Even when Split is OFF, a branch-assigned user sees their branch name tagged under the agency name in the header — the concept is always present; the enforcement is what toggles.
- **Mirror the `AgencyScope` pattern.** `BranchScope` is a global scope that mirrors the existing agency-scoping mechanism. Minimise new abstractions; maximise pattern continuity.
- **Enforce by default, allowlist the exceptions.** Every model that has `agency_id` should eventually have `branch_id` and `BranchScope` unless it appears on the explicit shared-scope allowlist (§7).
- **No hard deletes.** CoreX Rule 14 — every branch action (archive, reassign, transfer) is reversible.
- **Investigate → report → approve → fix.** No build prompt in this spec gets executed without Johan approving the phase 1 investigation report first.

---

## 3. Scope summary

| Area | Decision |
|------|----------|
| Split toggle location | Agency Settings → new `split_branches_enabled` column on `agencies` (default false) |
| Toggle reversibility | Free ON ↔ OFF, any time |
| Shipping default | OFF for all existing agencies |
| Role permissions added | `branches.view_all`, `branches.switch`, `branches.edit_all` |
| Permission dependency | `edit_all` implies `view_all` (enforced in UI + policy) |
| Unassigned user (NULL `branch_id`) when Split = ON | Dashboard-only + persistent banner: "Please ask your manager to assign you to a branch." |
| Shared-scope allowlist | `document_templates`, `training_courses`, `kb_documents`, `announcements`, `commission plans` |
| Cross-branch deals | Many-to-many `deal_branches` pivot; both branches see the deal; child records (offers, viewings, deal-level documents, notes, tasks) inherit multi-branch visibility; buyer contact stays with originating agent's branch |
| User transfer between branches | All historical data follows the user (source of truth is `user_id`, not `branch_id` on the record) |
| PP / P24 syndication per branch | Per-branch toggle + credentials override in Branch Management; falls back to agency-level credentials when disabled |
| Portal Capture / mandate breach detection | Always agency-wide (compliance concern trumps branch isolation) |
| Ellie AI branch scope | Deferred — out of scope for this spec |
| Reports & dashboards | Per-branch; principal / `view_all` users get a branch selector |
| Branch archive/delete | Blocked if any users are assigned; modal wizard forces reassignment of all users to another branch first |
| Branch tag in header | Always shown for branch-assigned users, regardless of toggle state: `HFC — Margate`; principals / `view_all` users see `HFC — All Branches` or the currently-impersonated branch if `view_as_branch_id` is set |
| `branches.deleted_at` SoftDeletes bug | Fixed in Prompt A before any isolation work begins |

---

## 4. Data model changes

### 4.1 `agencies` table

Add:

```php
$table->boolean('split_branches_enabled')->default(false)->after('dashboard_settings_mode');
```

**Semantics:** when true, `BranchScope` and all branch-aware policies enforce isolation. When false, `BranchScope` is bypassed and the system behaves exactly as today.

### 4.2 `branches` table

- **Fix SoftDeletes bug (Prompt A):** add nullable `deleted_at` timestamp column with index.
- Add:
  - `syndication_override_enabled` (boolean, default false)
  - `pp_agency_id` (nullable string) — Private Property agency ID for this branch
  - `pp_credentials` (nullable JSON, encrypted cast) — PP SOAP credentials
  - `p24_agency_id` (nullable string) — Property24 agency ID
  - `p24_credentials` (nullable JSON, encrypted cast) — P24 credentials

When `syndication_override_enabled = true`, the syndication adapters read credentials from the branch record. When false, they fall back to the agency-level credentials as today.

### 4.3 Models gaining `branch_id`

Per audit, 13–14 models carrying `BelongsToAgency` do not yet have `branch_id`. Each gets a migration adding:

```php
$table->foreignId('branch_id')->nullable()->after('agency_id')->constrained('branches')->nullOnDelete();
$table->index(['agency_id', 'branch_id']);
```

**Backfill strategy:** NULL. Legacy records stay NULL. They become visible per the shared-scope / NULL-handling rules in §7.

Models affected (confirmed by audit §2 — final list to be locked during Prompt B's investigation phase):

- `contacts`
- `documents`
- `fica_submissions`
- `training_enrolments` (course templates stay agency-wide; enrolments scope to branch)
- `prospecting_listings`
- `tasks`
- `notes`
- `activities`
- `appointments`
- `viewings`
- `offers`
- `mandates`
- `commissions`
- `revenue_shares`

Models already carrying `branch_id` (confirmed by audit): `users`, plus the 3–4 others listed in the audit report — no migration needed, but `BranchScope` still needs to be attached.

### 4.4 New pivot table — `deal_branches`

```php
Schema::create('deal_branches', function (Blueprint $table) {
    $table->id();
    $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
    $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
    $table->enum('role', ['originator', 'co_branch'])->default('co_branch');
    $table->timestamps();
    $table->unique(['deal_id', 'branch_id']);
    $table->index('branch_id');
});
```

`role = 'originator'` marks the mandate-holding branch; `role = 'co_branch'` marks any additional branch whose agent has attached to the deal.

### 4.5 Permissions

Three new permissions registered via the existing Spatie / role-permission mechanism (audit §5 confirms the package in use):

- `branches.view_all`
- `branches.switch`
- `branches.edit_all`

Seeded and assigned to `principal` and `agency_admin` roles by default. `branch_manager` gets `branches.switch` only (can impersonate their own branch for testing, cannot view across all branches by default).

---

## 5. `BranchScope` — the core enforcement mechanism

Mirror `AgencyScope` exactly. The `apply()` method pseudocode:

```
apply(Builder, Model):
    user = auth()->user()
    if user is null: return  // no-op for system / console context
    if user->agency->split_branches_enabled is false: return  // flat mode
    if user has permission 'branches.view_all': return  // principal / admin bypass
    effectiveBranch = user->effectiveBranchId()  // honours view_as_branch_id override
    if effectiveBranch is null:
        Builder->whereRaw('1 = 0')  // unassigned user sees nothing
        return
    Builder->where(Model->getTable().'.branch_id', effectiveBranch)
```

Attached via `addGlobalScope` in the `booted()` method of each branch-scoped model, mirroring the `AgencyScope` attachment pattern.

**Shared-scope allowlist models (§7) do NOT attach `BranchScope`** — they remain agency-scoped only.

**Deal multi-branch exception:** the `Deal` model's `BranchScope` variant uses the `deal_branches` pivot rather than a direct `branch_id` column:

```php
Builder->whereHas('branches', function ($q) use ($effectiveBranch) {
    $q->where('branches.id', $effectiveBranch);
})
```

Child records (offers, viewings, deal-level documents) resolve their branch visibility through their parent deal's pivot, not their own `branch_id`.

---

## 6. Permission semantics

| Permission | Grants |
|------------|--------|
| `branches.view_all` | Bypasses `BranchScope` entirely. Sees all data in the agency regardless of `branch_id`. |
| `branches.switch` | Can open the "View as Branch" dropdown and set `view_as_branch_id` in session. Requires `view_all` to be meaningful — you can only switch to branches you can see. If a user has `switch` but not `view_all`, they can only switch to branches they're explicitly assigned to (edge case — UI should hide the dropdown in this scenario). |
| `branches.edit_all` | Can edit / update / archive records belonging to branches the user is not assigned to. Implies `view_all`. UI enforcement: when a role is granted `edit_all`, `view_all` is auto-checked and disabled. Policy enforcement: `update()` and `delete()` policy methods check `edit_all` OR record's `branch_id` matches user's `effectiveBranchId()`. |

**Role manager UI:** the permission matrix gains these three new rows under a "Branches" group. Granting `edit_all` with `view_all` unchecked shows an inline validation message and auto-checks `view_all`.

---

## 7. Shared-scope allowlist (always agency-wide)

These models stay agency-scoped only. `BranchScope` does NOT attach:

- `document_templates`
- `training_courses` (enrolments scope to branch; the course library itself does not)
- `kb_documents`
- `announcements`
- `commission_plans`
- `agencies`
- `branches` (the directory itself — all branch-assigned users see the branch list to support cross-branch deal attachment; deletion still follows §9)

---

## 8. NULL `branch_id` handling

**When Split = ON and a user has `branch_id = NULL`:**

- All navigation except Dashboard is hidden from the sidebar.
- Dashboard renders a persistent top banner: "Please ask your manager to assign you to a branch."
- All branch-scoped controllers return 403 with the same message via middleware.
- A `RequiresBranchAssignment` middleware attaches to every route group except Dashboard, Profile, and Logout.

**Records with `branch_id = NULL` in the database** (legacy rows or agency-wide imports):

- When Split = OFF: visible to everyone (current behaviour).
- When Split = ON: visible only to users with `branches.view_all`. Normal users do not see NULL-branch records.

---

## 9. Branch archive / delete flow

Branches use `SoftDeletes` (fixed in Prompt A). The "Delete" button triggers this flow:

1. Controller checks `Branch::users()->exists()`.
2. **If users are assigned** → return modal: "This branch has N users assigned. Reassign them before archiving." Modal renders a list of the N users each with a `<select>` of other branches in the agency. Submit routes each user to their new branch via a single transaction, then proceeds to archive. If the principal cancels or closes the modal, no archive happens.
3. **If no users are assigned** → standard soft-delete, recoverable by principal from an Archived Branches view.

Records belonging to the archived branch keep their `branch_id` (they're effectively orphaned to a soft-deleted branch). `BranchScope` + `Branch::withTrashed()` relationship means they remain invisible to normal users but recoverable by principal.

---

## 10. PP / P24 per-branch syndication

### 10.1 Branch Management UI

Each branch row in Branch Management gets an "Advanced" or "Syndication" tab with:

- Toggle: **Use dedicated portal accounts for this branch** → sets `syndication_override_enabled`
- When toggled ON, reveal:
  - Private Property agency ID (text input)
  - PP username / password (encrypted)
  - Property24 agency ID (text input)
  - P24 API key (encrypted)
- **Test Connection** buttons for each portal (hits the sandbox / ping endpoint)

### 10.2 Syndication adapter changes

Both adapters (PP SOAP service + P24 ExDev adapter) gain a credentials resolver:

```
resolveCredentials(Listing):
    branch = listing->property->branch  // or listing->branch if direct
    if branch && branch->syndication_override_enabled:
        return branch's credentials
    return agency's credentials  // current behaviour
```

**Critical constraint (from existing rules):** the P24 syndication adapter stays completely separate from PP — no shared files. This spec does not alter that separation; each adapter independently gains its own resolver.

### 10.3 Listings UI

When a listing's branch has `syndication_override_enabled = true`, the listing detail page's "Syndication" panel shows "This listing will syndicate under [Branch Name]'s dedicated PP / P24 accounts" rather than the agency defaults.

---

## 11. Cross-branch deal register

### 11.1 Attaching a second branch

On the Deal detail page, a new **"Attach Co-Branch"** action (visible to users with write access to the deal) opens a modal:

- Select another branch from the agency
- Select the co-branch agent (filtered to that branch's agents)
- Optional commission-split note

On submit, a `deal_branches` row is inserted with `role = 'co_branch'` and the co-branch agent gets a linked role on the deal via the existing deal-agents mechanism.

### 11.2 Visibility

Both the originator branch and any co-branches see the deal in their Deal Register. Child records (offers, viewings, deal-level documents, notes, tasks) inherit visibility through the deal.

### 11.3 Commission

Handled by the existing Commission & Revenue Share Engine — out of scope for this spec beyond confirming that `deal_branches.role` is available to the engine for reporting.

### 11.4 Buyer contact privacy

The buyer contact record stays scoped to the originating agent's branch. Co-branch users see the buyer's name inside the deal context but cannot open the contact card directly — attempting to does a `BranchScope` check on the contact and 403s. If cross-branch contact access is needed, the originating agent explicitly shares the contact via a future sharing mechanism (out of scope for this spec).

---

## 12. Header branch tag

Top nav layout change — **always present** for authenticated users:

```
[CoreX Logo]  Home Finders Coastal — Margate   [User Menu]
```

For users with `branches.view_all` and no active `view_as_branch_id`:

```
[CoreX Logo]  Home Finders Coastal — All Branches   [Switch Branch ▾]
```

For users with `branches.view_all` and an active `view_as_branch_id`:

```
[CoreX Logo]  Home Finders Coastal — Margate (viewing as)   [Exit Branch View]
```

The "Switch Branch" dropdown is only rendered if the user has `branches.switch` permission.

---

## 13. Reports & dashboards

**Principal / `view_all` users** see a branch selector on every report and dashboard:

- "All Branches" (default) — aggregate
- Each individual branch — filtered

When `view_as_branch_id` is set, the selector is pre-filled and locked to that branch until exited.

**Non-`view_all` users** see their own branch's data only. No selector shown.

---

## 14. Integrations — per-module treatment

| Module | Behaviour when Split = ON |
|--------|---------------------------|
| Private Property syndication | Scoped per branch when `syndication_override_enabled` (see §10). Otherwise agency-level. |
| Property24 syndication | Same pattern as PP (see §10). Separate adapter, separate resolver. |
| Portal Capture / mandate breach detection | Always agency-wide. Compliance concern. No branch scope applied. |
| Exclusive mandate breach alerts | Agency-wide visibility (same reasoning). |
| E-Signature V2 | Envelopes inherit `branch_id` from the originating document. `BranchScope` attaches to `esign_envelopes`. Templates stay agency-wide (shared-scope allowlist). |
| Commission & Revenue Share Engine | Branch-aware: commission records carry `branch_id`. Revenue-share rules stay on shared-scope allowlist (`commission_plans`). |
| Training LMS | Courses agency-wide (shared). Enrolments branch-scoped. Compliance dashboards per branch with principal aggregate. |
| FICA / Compliance | Submissions branch-scoped. Compliance RAG status aggregates per branch; principal sees all. |
| Agent onboarding kanban | Branch-scoped — each branch sees its own pipeline. Principal sees all. |
| Fault Report System | Agency-wide — faults can affect any user and principal must see them all. |
| Marketing Intelligence Centre | Per-branch where applicable (ad accounts can be branch-specific); global where not (newsletter templates). Detailed module-by-module scoping deferred to a follow-up spec. |
| Ellie AI | **Deferred.** Scoping decisions logged as open question for a future spec. Current behaviour unchanged. |

---

## 15. Build sequencing

Each prompt follows the **investigate → report → approve → fix** discipline. Each ends with `php -l`, `php artisan view:clear`, `scripts/dev-check.ps1`, and Tinker functional verification. No prompt is marked done until all pass.

| # | Prompt | Description |
|---|--------|-------------|
| A | SoftDeletes fix | Add `deleted_at` to `branches` table. Standalone. Already drafted. |
| B | Agency Settings toggle | Add `split_branches_enabled` column + UI in Agency Settings. Default OFF. |
| C | `BranchScope` scaffold | Create the scope class mirroring `AgencyScope`. Attach to the 3–4 models already carrying `branch_id`. Verify with Tinker that queries respect the scope when toggle is ON. |
| D | Branch-ID migrations | Add `branch_id` column to the ~13 models missing it. NULL backfill. One migration per model (or grouped in ≤3 migrations if schema-safe). |
| E | `BranchScope` attachment wave 2 | Attach `BranchScope` to all models migrated in Prompt D. Per-module Tinker verification. |
| F | Permissions + role manager UI | Seed `branches.view_all`, `branches.switch`, `branches.edit_all`. Update role manager UI with the three new checkboxes + `edit_all` → `view_all` auto-check logic. |
| G | Header branch tag + "View as branch" dropdown | UI rendering per §12, permission-gated. Uses existing `view_as_branch_id` session override. |
| H | NULL-branch middleware + unassigned-user banner | `RequiresBranchAssignment` middleware + dashboard banner per §8. |
| I | `deal_branches` pivot + cross-branch attach UI | Migration, model relationships, attach modal, deal-register visibility, policy updates for child records. |
| J | PP/P24 per-branch syndication | Migration, Branch Management UI tab, adapter resolver changes, listing UI indicator. PP and P24 in separate sub-prompts to preserve adapter separation. |
| K | Branch archive / reassignment wizard | Modal flow per §9, including the "cannot delete branch with users" guard. |
| L | Reports & dashboards branch selector | Per §13. |
| M | Integration sweep | Per-module verification per §14 — E-Signature envelope branch inheritance, compliance dashboards, FICA scoping, agent onboarding kanban scoping, etc. One sub-prompt per module. |
| N | Regression test pass | Full Tinker verification matrix — every branch-scoped model queried as each role type under Split = ON and Split = OFF. Report any unexpected visibility. |

Prompts K, L, M can run in parallel with later prompts once their dependencies (A–G at minimum) are complete.

---

## 16. Open questions (logged for future specs, not blocking this one)

- **Ellie AI branch scoping** — full decision for a follow-up spec.
- **Cross-branch contact sharing mechanism** — beyond current "buyer contact stays private" rule.
- **Branch-specific marketing ad accounts** (Meta / Google Ads) — full design in Marketing Intelligence spec.
- **Cross-agency referrals** (different agencies, not just different branches) — out of scope entirely.
- **Multi-agency principal** (one person principal of two agencies) — out of scope.

---

## 17. Acceptance criteria

The branch isolation feature is considered complete when:

1. A new agency can be created, Split Branches toggled ON, branches created, users assigned to branches, and verified that agents in Branch A cannot see Branch B's contacts / properties / deals / documents / tasks / notes.
2. Principal (with `view_all`) sees everything across all branches with Split = ON.
3. Principal can use "View as Branch" to impersonate a branch view and exit cleanly.
4. Cross-branch deal attachment works — deal appears in both branches' registers.
5. Branch with assigned users cannot be archived; reassignment modal forces user migration first.
6. PP / P24 per-branch syndication works — a listing owned by a branch with override enabled syndicates under that branch's credentials.
7. Flipping Split OFF → ON → OFF → ON returns the system to the expected state each time. No data loss.
8. Every Tinker scenario in Prompt N's regression matrix passes.
9. No new failures in `scripts/dev-check.ps1`.
10. The shared-scope allowlist (§7) remains agency-wide under all toggle states and all role types.

---

## 18. Rollout plan

1. Prompts A through G deployed to Staging only. Principal (Johan's wife) tests Split toggle in sandbox. No production agency flipped yet.
2. Prompts H through K merged behind the Split toggle staying OFF for all existing agencies. The code is live; the enforcement is dormant.
3. HFC's own account flips Split ON as the first test in production. Verified for a full week of real use.
4. Once stable, Split toggle is offered to other agencies as an opt-in feature.
5. No existing agency is flipped without explicit principal consent.

---

End of spec.
