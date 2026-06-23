# Admin Multi-Branch Manager — Build Spec

> Spec file: `.ai/specs/admin-multi-branch-manager.md`
> Status: **Draft — awaiting approval**
> Author: Andre + Claude (solution design)
> Related: [.ai/specs/branch-isolation-spec.md](branch-isolation-spec.md), [.ai/specs/deals.md](deals.md), [.ai/specs/manager-oversight.md](manager-oversight.md)
> Ticket: AT-78 (Fields for branches — check if points to correct place)

---

## 1. Purpose (business requirement)

Some agency **admins** physically run more than one branch — they are, in practice, the branch manager of several offices at once. But CoreX only models a single `branch_id` per user and resolves "the branch manager" purely from the `branch_manager` role. The result: when such an admin registers a deal (or appears on a deal document), the system presents them as a generic **admin**, never as the **branch manager of the branch the deal belongs to**. Their name, FFC, and signature don't land where "the branch manager" is printed.

This feature lets an admin:

1. **Self-assign** the branches they manage, on their own profile.
2. **Pick a default** branch that loads when they open CoreX.
3. **Switch** which branch they are "acting as manager of" from the topbar, at any time.
4. Be recorded and presented as the **named branch manager** of that branch on deals and deal documents they register while acting for it.

…all while their **account stays `admin`** with full, agency-wide visibility intact. This is an *identity / representation* layer, **not** an access-restriction layer.

### Explicit non-goals

- It does **not** narrow what the admin can see. Acting as a branch never hides other branches' data (decision confirmed with stakeholder 2026-06-22).
- It does **not** flip the admin's role to `branch_manager` (that would trigger `'branch'` data-scope via `PermissionService::getDataScope()` and strip admin-wide visibility). A new, lighter session context is introduced instead — see §5.
- It does **not** replace the existing `view_as_role` / impersonation debug tooling. That stays as-is for owner/`impersonate_users` testing.
- It does **not** retroactively re-attribute existing deals. Only deals registered while explicitly acting as a branch capture the acting manager.

---

## 2. Pillar connections

| Pillar | Reads | Writes back |
|--------|-------|-------------|
| **Agent** (`User`) | The admin's role, agency, and their self-assigned managed branches | New `user_managed_branches` rows; default-branch flag |
| **Deal** (`Deal`) | The branch a deal belongs to; the admin's acting-manager context | `deals.managed_by_user_id` — the named manager at registration time |
| **Property / Contact** | (unchanged — admin keeps agency-wide visibility) | — |

The feature is anchored on the **Agent** pillar (who manages which branch) and writes enriched data back to the **Deal** pillar (who the named manager is for a deal). This satisfies non-negotiable #4 (pillars are the spine).

---

## 3. Guiding principles

- **Identity, not isolation.** Acting as a branch changes representation and defaults; it never changes scope. Admin always sees everything.
- **Reuse existing primitives.** Build on `effectiveBranchId()`, the topbar switcher UI, and the `BranchSwitcherController` route shape rather than inventing parallel machinery. New only where genuinely new (the managed-branches pivot, the `acting_branch_manager_id` session key, and `deals.managed_by_user_id`).
- **No hard deletes.** Removing a managed branch detaches a pivot row; it does not destroy any deal attribution already written.
- **Capture, don't guess.** Because a branch can have several managers, "who is the manager for *this* deal" is captured at registration into a stored column — not re-derived from roles later.
- **Permissioned even when self-service.** Per non-negotiable #5, a permission key gates who sees the "Branches I Manage" panel, defaulting to admin/owner roles.

---

## 4. Data model changes

### 4.1 New pivot — `user_managed_branches`

```php
Schema::create('user_managed_branches', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
    $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
    $table->boolean('is_default')->default(false);
    $table->timestamps();
    $table->unique(['user_id', 'branch_id']);
    $table->index(['user_id', 'is_default']);
});
```

- One row per (admin, branch they manage). `unique(['user_id','branch_id'])` prevents duplicates.
- Exactly **one** row per user may have `is_default = true` — enforced in application logic (setting a new default clears the others in the same transaction). The default is the branch the switcher pre-selects on login.
- `agency_id` is denormalised for scoping/reporting and validation (a user can only manage branches in their own effective agency).
- **Not** reusing the existing `branch_assignments` table — it carries `unique(user_id)` (strictly 1:1) and represents the user's *home* branch, a different concept.
- `users.branch_id` is untouched — it remains the user's home branch. Managed branches are additive.

### 4.2 `deals` table — named manager capture

```php
$table->foreignId('managed_by_user_id')
      ->nullable()
      ->after('branch_id')
      ->constrained('users')
      ->nullOnDelete();
```

- Set at registration **only** when the registrant is explicitly acting as the deal's branch (see §5 + §6). NULL otherwise.
- Backfill: NULL for all existing deals. Existing deals continue to resolve the manager the way they do today (role-based) — no behaviour change for historical data.
- After adding the migration, run `php artisan schema:dump` and commit the refreshed `database/schema/mysql-schema.sql` in the same commit (non-negotiable #12a).

### 4.3 Permissions

One new key in `config/corex-permissions.php` (and the corresponding seeder):

- `branches.self_assign_managed` — "Self-Assign Managed Branches". Grants the holder the "Branches I Manage" profile panel and the multi-branch acting switcher. Seeded to `admin` and owner roles by default. `branch_manager` and `agent` do **not** get it (a branch manager already has exactly one branch).

This composes with the existing `branches.switch` / `branches.view_all` keys from the branch-isolation spec — it does not replace them.

---

## 5. The "acting as branch manager" context

**Decision (revised after live feedback 2026-06-23):** the "current branch" an
admin operates in is the **existing** branch-isolation context — the session
key `view_as_branch_id`, the same lever the "Switch Branch" control uses. We do
**not** invent a parallel branch-context key. An admin is "acting as" the
manager of whichever branch they are currently in, **if** they manage it.

| Concept | Source | Effect |
|---------|--------|--------|
| Current branch | `view_as_branch_id` (→ `effectiveBranchId()`) | The branch the admin is in. Set on login to their default managed branch, and by both the "Switch Branch" and "Acting as" controls. |
| Acting as manager of | `User::actingBranchManagerId()` = `effectiveBranchId()` **iff** `isManagerOfBranch()` | (a) Default branch for new deal registration; (b) the admin is recorded as `managed_by_user_id` on deals registered while in that branch; (c) topbar shows "Acting: <Branch>". |

**Why this does not break "keep admin-wide visibility":** admins hold
`branches.view_all`, and `BranchScope` is bypassed entirely for `view_all`
holders. So setting `view_as_branch_id` for an admin is **context only** — it
drives the header label, the deal-form default, and manager naming, but never
hides another branch's data. `PermissionService::getDataScope()` still returns
`'all'` for admins (we never set `view_as_role`), so data scope stays agency-wide.

> Rejected earlier approach: a separate `acting_branch_manager_id` key that did
> *not* touch `view_as_branch_id`. It left the admin's identity changed but the
> actual branch context (and the "Switch Branch" UI) untouched — so on login
> they were *labelled* a branch manager but were not *in* the branch and still
> had to Switch Branch manually. Unifying on `view_as_branch_id` fixes that.

### 5.1 Login default

The `Login` event listener (`AppServiceProvider`) reads
`defaultManagedBranchId()` and, when present, seeds `view_as_branch_id` so the
admin opens CoreX already in their default branch. `Logout` clears
`view_as_branch_id`. Users with no default managed branch are unaffected (no-op).

### 5.2 Helper on `User`

```php
public function managedBranches(): BelongsToMany; // via user_managed_branches
public function defaultManagedBranchId(): ?int;
public function isManagerOfBranch(int $branchId): bool;
public function actingBranchManagerId(): ?int;     // = effectiveBranchId() iff isManagerOfBranch()
```

`actingBranchManagerId()` returns the current branch only when the user manages
it — so deal-manager capture fires in a managed-branch context and nowhere else.

---

## 6. Deal registration behaviour

In `DealController@store` (and the form in `resources/views/admin/deals/form.blade.php`):

1. Admin visibility is unchanged — they still pick any branch (decision: keep admin-wide visibility).
2. The branch selector **defaults** to `acting_branch_manager_id` when set (instead of no preselection), so the common case is one click less.
3. On store, **if** the chosen `branch_id` equals the admin's current `acting_branch_manager_id` **and** the user `isManagerOfBranch(branch_id)`, set `deals.managed_by_user_id = auth()->id()`. Otherwise leave it NULL (recommended default per stakeholder — capture only when explicitly acting; see Open Question §11 if "automatic for any managed branch" is preferred later).
4. Editing a deal never silently overwrites an existing `managed_by_user_id`; changing it is an explicit action.

### 6.1 Resolving "the branch manager" of a deal

A single resolver used everywhere a deal prints/needs its branch manager (deal documents, PDFs, signature blocks, deal detail header):

```
Deal::branchManager():
    if managed_by_user_id is set: return that User
    else: return first User with role 'branch_manager' AND branch_id == deal.branch_id   // today's behaviour
```

This makes the admin the **named manager** (their name, FFC, signature) on the deals they registered while acting — exactly the reported gap — while leaving every other deal's resolution unchanged.

---

## 7. UI placement & navigation

Per non-negotiable #2 (every feature is navigable the same day) — both entry points already exist as navigable surfaces:

### 7.1 "Branches I Manage" panel (self-service)

- Location: the **Profile tab** of the agent portal, `resources/views/agent/portal.blade.php`, immediately after the "Admin Managed" read-only block (~line 492).
- Rendered only when the user holds `branches.self_assign_managed`.
- Contents: a checkbox list of the branches in the user's effective agency; each managed branch has a "Set as default" radio. A single Save submits the set.
- Save route: `PATCH agent.portal.managed-branches.update` → new `AgentPortalController@updateManagedBranches`, middleware `permission:branches.self_assign_managed`. Validates every submitted branch belongs to the user's effective agency; rebuilds the pivot in one transaction; enforces single `is_default`.

### 7.2 "Acting as" switcher (topbar)

- Location: reuse the existing branch switcher in `resources/views/layouts/corex-sidebar.blade.php` (~lines 222–268).
- For a user with `branches.self_assign_managed` and ≥1 managed branch, the dropdown lists: **"Administrator (all branches)"** (clears `acting_branch_manager_id`) + one entry per managed branch (sets it). Current selection shown as "Acting as: <Branch> Manager".
- Routes (mirror the existing switcher shape):
  - `POST /branch/acting/{branch}` → `actingManager` (sets `acting_branch_manager_id` after `isManagerOfBranch` check)
  - `POST /branch/acting/clear` → clears it
  - Both `->name(...)` registered and reachable; gated by `permission:branches.self_assign_managed`.
- This is **separate** from the existing isolation "Switch Branch" control (which sets `view_as_branch_id`). A user who has both permissions sees both; they do different things and are labelled distinctly to avoid confusion.

### 7.3 Admin-managed assignment (user-edit screen)

In addition to self-service, an admin managing users can assign another user's
managed branches + default from the **Role** tab of the admin user-edit screen
(`/admin/users/{user}/edit#role`, `resources/views/admin/users/create-edit.blade.php`).

- A **"Branches Managed"** block appears in the Role tab, shown only when the
  selected role is `admin`/`super_admin` (Alpine-toggled live as the role
  `<select>` changes). Same checkbox-list + default-radio as §7.1.
- Saved by `UserManagementController@update`: when the saved role is an admin
  role it calls `User::syncManagedBranches()`; when the user is demoted out of
  an admin role it clears their managed-branch rows (a non-admin can't be a
  multi-branch manager).
- **Agency correctness:** the controller passes the *edited user's* real agency
  to `syncManagedBranches()` — never `effectiveAgencyId()`, which is
  session-scoped to the editing admin and would be wrong here.

`User::syncManagedBranches(array $branchIds, ?int $defaultId, ?int $agencyId)`
is the single shared writer used by both the self-service panel (§7.1) and this
screen, so the validation/default rules can't drift between the two.

---

## 8. User flow (step by step)

1. Admin opens **My Portal → Profile**. Under "Admin Managed" they now see **"Branches I Manage"**.
2. They tick *Margate* and *Port Shepstone*, mark *Margate* as default, Save.
3. Next login, CoreX opens with the topbar showing **"Acting as: Margate Manager"** (the default).
4. They go to **Deals → Register**. The branch defaults to *Margate*. They complete and save the deal.
5. The deal stores `branch_id = Margate`, `managed_by_user_id = <this admin>`. On the deal document, the branch manager block shows **the admin's** name / FFC / signature.
6. They click the topbar switcher → **"Acting as: Port Shepstone Manager"**, register another deal — it attributes to them for Port Shepstone.
7. They click **"Administrator (all branches)"** → `acting_branch_manager_id` cleared. They're back to plain admin; new deals capture no manager unless they pick a branch they manage while acting. Throughout, they could always see every branch's data.

---

## 9. Files to create or modify

**Create**
- `database/migrations/xxxx_create_user_managed_branches_table.php`
- `database/migrations/xxxx_add_managed_by_user_id_to_deals_table.php`
- `app/Models/UserManagedBranch.php` (optional — pivot can be implicit; model only if logic warrants)
- (Tests) `tests/Feature/...` covering: pivot save/default enforcement, acting-switch sets/clears session, deal store captures `managed_by_user_id` only when acting, `Deal::branchManager()` resolution precedence.

**Modify**
- `app/Models/User.php` — `managedBranches()`, `defaultManagedBranchId()`, `actingBranchManagerId()`, `isManagerOfBranch()`, `syncManagedBranches()` (shared writer).
- `app/Http/Controllers/Admin/UserManagementController.php` — `update()` assigns/clears managed branches (admin-edit entry point, §7.3).
- `resources/views/admin/users/create-edit.blade.php` — "Branches Managed" block in the Role tab.
- `app/Models/Deal.php` — `managed_by_user_id` fillable + `branchManager()` resolver + `managedBy()` relation.
- `app/Http/Controllers/Agent/AgentPortalController.php` — `updateManagedBranches()`.
- `app/Http/Controllers/Admin/BranchSwitcherController.php` (or a new `ActingBranchManagerController`) — `actingManager()` / `clearActing()`.
- `app/Http/Controllers/Admin/DealController.php` — default branch from acting context; capture `managed_by_user_id` on store.
- `resources/views/agent/portal.blade.php` — "Branches I Manage" panel.
- `resources/views/layouts/corex-sidebar.blade.php` — "Acting as" switcher.
- `resources/views/admin/deals/form.blade.php` — default branch selection.
- Deal document / PDF templates that print the branch manager — switch to `Deal::branchManager()`.
- `config/corex-permissions.php` + permission seeder — `branches.self_assign_managed`.
- `routes/web.php` — acting + managed-branches routes.
- The session bootstrap hook — seed default acting branch on login.
- `database/schema/mysql-schema.sql` — re-dumped after the two migrations.

---

## 10. Acceptance criteria

1. An admin can open their own profile, tick multiple branches as "managed," set one default, and save — persisted in `user_managed_branches`, exactly one `is_default`.
2. A `branch_manager`/`agent` (without the permission) never sees the panel or the acting switcher.
3. On login, the admin's CoreX opens with the topbar showing "Acting as: <default branch> Manager".
4. The topbar switcher lists "Administrator (all branches)" + each managed branch; selecting one sets the acting context; clearing returns to plain admin.
5. While acting as Branch X, registering a deal for Branch X stores `managed_by_user_id = the admin`, and the deal's documents print the admin as the named branch manager (name + FFC + signature).
6. Registering a deal for a branch the admin does **not** manage, or while not acting, leaves `managed_by_user_id` NULL and resolves the manager via the existing role-based path.
7. At all times the admin retains agency-wide visibility — acting as a branch never hides another branch's deals/contacts/properties (verify `BranchScope` / `getDataScope()` are untouched by the acting context).
8. A stale/forged `acting_branch_manager_id` not in the user's managed set is ignored.
9. Removing a managed branch from the profile does not alter `managed_by_user_id` already written on past deals.
10. `php artisan schema:dump` refreshed; the single most relevant test file passes; no new failures introduced. (Full suite only on Johan's go-ahead — non-negotiable #13.)

---

## 11. Open questions

- **Capture trigger (recommended default chosen):** capture `managed_by_user_id` only when the admin is *explicitly acting* as the deal's branch. Alternative — capture automatically whenever the deal's branch is one the admin manages, even in plain-admin mode. Recommendation: explicit-only (predictable). Flagged for stakeholder confirmation before build.
- **Multiple admins managing the same branch:** the resolver names whoever registered the deal; for deals with no `managed_by_user_id`, role-based resolution still returns the first role-holder. If a branch needs a single canonical "head manager" independent of who registered, that's a follow-up (ties into `manager-oversight.md`'s `manager_user_id` concept).
- **Interaction with branch-isolation Split mode:** when an agency has `split_branches_enabled = true`, confirm the acting switcher and the existing isolation "Switch Branch" control read clearly side by side. May warrant merging the two controls in a later pass.

---

End of spec.
