# Branch Archive & Agent Reassignment — Build Spec

> Spec file: `.ai/specs/branch-archive-reassignment.md`
> Status: **Built and promoted — QA2 → Staging (9b3e787c3) → Prod, 2026-09-16**
> Author: Andre (business ruling) + Claude (solution design), 2026-09-15
> Supersedes: `.ai/specs/branch-isolation-spec.md` §9 (Branch archive / delete flow)
> Related: `.ai/specs/branch-isolation-spec.md` (§4, §5, §11, §13), `.ai/specs/admin-multi-branch-manager.md`, AT-366 (agent branch-move history)

---

## 1. Purpose (business requirement)

An agency must be able to **close a branch** and move every agent on it to another branch, without losing or rewriting what happened while the branch was open.

The ruling that drives every decision below (Andre, 2026-09-15):

> **Deals carry on as loaded.** At loading time the agent was at branch A, and the register recorded the deal as branch A's. When the agent moves to branch B the register stays untouched. Any deal loaded against that agent *after* the move defaults to the branch the agent is in now.

Generalised: **the branch stamped on a record when it was created is the truth for that record, forever.** An agent's move changes only where their *future* work lands. Nothing historical is re-attributed, and nothing historical becomes invisible or nameless just because its branch was closed.

Today the Delete button on a branch with agents is a dead end (it says "reassign them first" with no way to do so), the confirm popup wrongly says "cannot be undone", the reassignment path skips the AT-366 move log, several branch reports read the agent's *current* branch rather than the deal's stamp (so a move would silently drag deal history to the new branch), and an archived branch renders as a blank name with no way to see or restore it.

### Explicit non-goals

- No hard delete of a branch, ever (CoreX non-negotiable #1).
- No moving of deals, properties, contacts, documents, targets, TV codes, activity columns or branch settings from the archived branch to the target branch. They stay stamped where they were created.
- No change to how a deal chooses its branch at creation (already the loader's effective branch — `DealController::store()`).
- No change to the Split Branches toggle, `BranchScope`, or `DealBranchScope` semantics.
- No per-record "re-attribute this deal to branch B" tool. If one is ever wanted it is its own spec.

---

## 2. Pillar connections

| Pillar | Reads | Writes back |
|--------|-------|-------------|
| **Agent** (`User`) | current `branch_id`, managed branches, login default | `users.branch_id`, `branch_assignments`, `user_managed_branches`, **`user_branch_history` (one dated row per moved agent)** |
| **Deal** | `deals.branch_id` / `deal_branches` originator stamp (reports) | nothing — untouched by design |
| **Property** | `properties.branch_id` (display of archived branch name) | nothing |
| **Contact** | `contacts.branch_id` (display) | nothing |

---

## 3. Guiding principles

1. **Stamp at creation is the truth.** Every branch report, rollup and register that attributes a deal to a branch reads the deal's own stamp, never the agent's current branch.
2. **Move forward, never backward.** Reassignment updates the agent's home branch and writes a dated move row. Future records land on the new branch automatically because creation already uses the agent's effective branch.
3. **Archived is a state, not a void.** An archived branch keeps its name everywhere it is referenced, shown with an "Archived" marker, and remains selectable as a *filter* in reports so a principal can look back at it. It is never offered as a *target* for new work.
4. **Reversible.** Archive is a soft delete. Restore brings the branch back exactly as it was, minus the agents (they stay where they were moved to; moving them back is a normal reassignment).
5. **All or nothing.** The wizard commits the moves and the archive in one transaction. A half-moved branch cannot exist.

---

## 4. Data model changes

No new tables. No new columns.

### 4.1 Existing structures used

| Structure | Role in this spec |
|-----------|-------------------|
| `branches.deleted_at` (SoftDeletes, already on model) | the archive flag |
| `users.branch_id` | agent's home branch — updated on move |
| `branch_assignments` (legacy 1:1 pivot) | kept in sync on move (already done) |
| `user_branch_history` (AT-366) | **must** receive one row per moved agent: `from_branch_id` = archived branch, `to_branch_id` = target, `moved_at` = now, `moved_by_user_id` = acting admin |
| `user_managed_branches` | rows for the archived branch removed; `is_default` re-pointed if it pointed at the archived branch |
| `deals.branch_id` + `deal_branches` (originator / co-branch) | the deal's stamp — the source of truth for attribution |

### 4.2 Why the move must go through the model, not a raw update

`BranchAssignmentController::deleteBranch()` currently reassigns with `User::where(...)->update([...])`. That is a query-builder write and **does not fire `UserObserver::updated()`**, so no `user_branch_history` row is written. The Performance & ROI report (AT-366, `BranchAttributionResolver`) would then credit all of the agent's pre-move *activity* to the new branch. The rebuild moves each agent via an Eloquent save (or writes the history row explicitly inside the same transaction) so the log is complete.

### 4.3 Permissions

Existing key `access_branch_assignments` gates the wizard, archive and restore. No new key. (Rule 10a: this is not an agency setting, so nothing goes to the Setup Wizard.)

---

## 5. Attribution rule for reports

Every place that answers "which branch does this deal belong to" reads the deal's stamp. The following currently read the **agent's current** `users.branch_id` and must switch to `deals.branch_id` (originator) / `deal_branches` (originator + co-branch, matching §11 of the isolation spec):

| Location | What it does today |
|----------|--------------------|
| `Deal::branchCommission()` (`app/Models/Deal.php` ~L348) | sums allocations for agents whose *current* branch matches |
| `Deal::statusSummaryForBranch()` (~L448) | picks deals by joining `users.branch_id` |
| `Deal::marketAveragesForBranch()` (~L598) | same join |
| `Deal::scopeVisibleTo()` (~L810) | branch-manager visibility via `users.branch_id` |
| `BranchRollupLegacyReader` (`app/Services/Finance/Legacy/…`) | header comment literally says "Branch = agent's users.branch_id (not deals.branch_id)" — reverse it |
| `CompanyPerformanceService` (~L599) | `users.branch_id` join |

The **agent-side** split (how much of a deal each agent earns) is unchanged. Only the **branch-side** roll-up changes: a deal's money rolls up to the branch stamped on the deal, credited to whichever agents are on it, regardless of where those agents sit today.

> **Built 2026-09-15 — the one rule, in one place.** `Deal::branchAttributionSql()` = `COALESCE(deals.branch_id, users.branch_id)` and `Deal::agentIdsAttributedTo($branchId)` are the only two definitions of "which branch does this deal's money roll up to". Every reader above uses one of them. The `COALESCE` keeps **legacy deals loaded before the stamp existed** (`branch_id NULL`) on the old agent-current-branch behaviour, so nothing historical moves. Co-branches (`deal_branches`, isolation spec §11) stay a **visibility** mechanism, exactly as §11.3 already says — a cross-branch deal's money rolls up to its originator branch, which is the selling agent's acting office under the AT-192 doctrine.
>
> Two deal **edit** screens (DR1 `DealController::edit`, DR2 `DealRegisterController::edit`) also include the deal's own branch in the branch select even when it is archived. Without that, saving an untouched edit would silently re-stamp the deal onto whatever branch the select fell back to.

Activity-based reports (Performance & ROI, buyer activity, period comparator) already use `BranchAttributionResolver` and need no change once the move log is written correctly (§4.2).

Targets, monthly goals and TV are branch-owned records already stamped with `branch_id`; they stay with the archived branch and are not touched.

---

## 6. UI placement & navigation

The wizard replaces the existing Delete form in all three places it is rendered:

- **Company Settings → Branches tab** (`resources/views/admin/company-settings/index.blade.php` ~L650)
- **Branch Assignments** admin page (`resources/views/admin/branch-assignments/index.blade.php` ~L57)
- **Agency edit → Branches tab** (`resources/views/admin/agencies/create-edit.blade.php`)

Button label changes from **Delete** to **Archive**. The browser `confirm()` that says "cannot be undone" is removed (it is untrue).

An **Archived branches** panel is added beneath the branch list on all three pages, listing archived branches with their archive date and a **Restore** button. Nav entry: same pages, same day (non-negotiable #2). Empty state: "No archived branches."

> **Built 2026-09-15 — deviation declared.** "Who archived" is not shown on the panel: §4 rules out new columns, and the actor is already recorded on the `Branch\BranchArchived` row in `domain_event_log`. If Johan wants it on screen, that is a one-line read from the event log, not a schema change.

The shared markup lives in two partials — `resources/views/admin/branches/_archive-wizard.blade.php` (the modal, its own form, included outside any other form) and `_archived-panel.blade.php` — so the three pages cannot drift.

---

## 7. User flow (step by step)

### 7.1 Archive a branch with agents

1. Admin clicks **Archive** on branch A.
2. Modal opens: *"Archive Kloof? 4 agents work from this branch. Choose where each one works from now."*
3. Table: one row per agent (name, role). Each row has a branch select listing every **active** branch in the agency except A. A "Move everyone to…" select at the top fills all rows at once; individual rows can still be changed afterwards.
4. Below the table a plain-language summary, always visible:
   - *"Stays with Kloof (archived): every deal, property, contact, document, target and activity recorded while the branch was open."*
   - *"Moves: the 4 agents. Anything they load from now on lands on their new branch."*
5. **Archive branch** is disabled until every agent has a target.
6. Submit → single transaction: for each agent, set home branch + legacy pivot + dated move row; clean up managed-branch rows (§7.3); soft-delete branch A.
7. Success flash: *"Kloof archived. 4 agents moved."* Admin lands back on the tab they came from (existing `branchContextRedirect()` behaviour).

### 7.2 Archive a branch with no agents

Modal shows the same "what stays" summary with an empty agent list and a single **Archive branch** button. Same soft delete.

### 7.3 Multi-branch managers (principal who works in both)

For each moved agent that also has `user_managed_branches` rows:
- The row for branch A is removed.
- If A was their `is_default`, the default becomes their target branch if they manage it, otherwise the first remaining managed branch, otherwise no default (existing "no default" behaviour applies).
- Any user whose session `view_as_branch_id` points at A is bounced to their home branch on next request (`User::branchOverrideStillAuthorized()` additionally requires the branch to be un-archived).

### 7.4 Looking back at an archived branch

- Anywhere a branch name is shown on a record (deal register, property, contact, document, targets, performance) the archived branch's name still renders, suffixed **(archived)**. No blank names.
- Branch filter dropdowns on reports list active branches first, then an **Archived** group. Archived branches are **never** listed in "create/move to" selects (new deal branch, reassignment targets, managed-branch picker, TV code branch, etc.).

### 7.5 Restore

1. Admin clicks **Restore** on an archived branch.
2. Confirm modal: *"Restore Kloof? The branch becomes active again with all its historical records. Agents stay where they are now — move them back from Branch Assignments if needed."*
3. Soft delete is reversed. No agents are moved. Flash: *"Kloof restored."*

---

## 8. Domain events (non-negotiable #9)

> **Built 2026-09-15 — deviation declared.** The catalogue already had `Agent\AgentBranchAssigned` (emitted by the user-edit screen on a manual move). Adding a second "agent moved branch" contract would have been exactly the ad-hoc duplication #9 forbids, so the existing event was extended instead of a new `AgentBranchChanged` being created.

| Event | Payload | Emitted by | Initial listeners |
|-------|---------|------------|-------------------|
| `Agent\AgentBranchAssigned` (existing, extended) | `user`, `branch` (new), `actorUserId`, **`reason`** (`'manual'` \| `'branch_archived'`), **`fromBranchId`** | `UserManagementController` (manual); `BranchAssignmentController::deleteBranch()` — one per moved agent, inside the archive transaction | `Agent\LogAgentEvent`, audit. The dated `user_branch_history` row is written by `UserObserver::updated()` on the Eloquent save, not by a listener |
| `Branch\BranchArchived` | `branch` (already trashed), `actorUserId`, `movedUserIds[]` | `BranchAssignmentController::deleteBranch()`, after the soft delete, inside the transaction | `Agent\LogAgentEvent`, audit |
| `Branch\BranchRestored` | `branch`, `actorUserId` | `BranchAssignmentController::restoreBranch()` | `Agent\LogAgentEvent`, audit |

Listeners are registered explicitly in `AppServiceProvider::boot()` (event discovery is off). Listeners stay synchronous; anything slow queues a Job carrying scalars.

---

## 9. Files to create or modify

| File | Change |
|------|--------|
| `app/Http/Controllers/Admin/BranchAssignmentController.php` | `deleteBranch()` → Eloquent-save moves + history row + managed-branch cleanup + events; new `restoreBranch()`; new `archived` listing data |
| `routes/web.php` | `POST /admin/branches/{branch}/restore` → `admin.branches.restore` (route-model binding must use `withTrashed()`) |
| `app/Models/User.php` | `branchOverrideStillAuthorized()` rejects archived branches; `defaultManagedBranchId()` ignores archived |
| `app/Models/Deal.php` | §5 methods read the deal stamp |
| `app/Services/Finance/Legacy/BranchRollupLegacyReader.php` | §5 |
| `app/Services/Admin/CompanyPerformanceService.php` | §5 |
| `app/Models/Property.php`, `app/Models/User.php`, `app/Models/Contact.php`, `app/Models/Docuperfect/Document.php` (+ any model with `branch()`) | `branch()` relation `->withTrashed()` so archived names render |
| `app/Models/Branch.php` | `displayName()` helper returning `"{name} (archived)"` when trashed; `scopeSelectable()` (active only) for target/create selects |
| `app/Events/Agent/AgentBranchChanged.php`, `app/Events/Branch/BranchArchived.php`, `app/Events/Branch/BranchRestored.php` | new |
| `app/Observers/UserObserver.php` | emit `AgentBranchChanged` alongside the existing history write |
| `app/Providers/AppServiceProvider.php` | register listeners |
| `resources/views/admin/branch-assignments/index.blade.php`, `resources/views/admin/company-settings/index.blade.php`, `resources/views/admin/agencies/create-edit.blade.php` | Archive wizard modal (Alpine), Archived branches panel, Restore |
| Report/filter blades that list branches | active-first + Archived group; target selects use `Branch::selectable()` |
| `.ai/specs/corex-domain-events-spec.md` | add the three events to the catalogue |
| `.ai/specs/branch-isolation-spec.md` §9 | one-line "superseded by this spec" note |
| `tests/Feature/Admin/BranchArchiveTest.php` | new — see §10 |

---

## 10. Acceptance criteria

1. Archiving a branch with agents is impossible without a target for every agent; the archive and all moves happen in one transaction or not at all.
2. After archiving, each moved agent has exactly one new `user_branch_history` row (from A → target, stamped with the acting admin).
3. A deal loaded before the move remains attributed to branch A in: deal register branch filter, branch commission, branch status summary, market averages, legacy branch rollup, company performance. The agent's own earnings on it are unchanged.
4. A deal loaded by a moved agent after the move is stamped with their new branch without any user action.
5. The archived branch's name renders with "(archived)" on every record that references it; no blank branch names anywhere.
6. The archived branch appears in report filters under an Archived group and never in any create/move-to select.
7. A multi-branch manager whose default was A logs in to a valid branch after the archive; no user can remain "viewing as" an archived branch.
8. Restore returns the branch to active with all historical records intact and moves no agents.
9. Branch with no agents archives in one click via the same modal.
10. `access_branch_assignments` gates archive and restore; other users get 403.
11. Feature tests cover 1–4, 7, 8 and 10. Existing branch-split tests still pass.

---

## 11. Open questions

None blocking. The one business question (where in-flight deals show after a move) was ruled on 2026-09-15: they stay where they were loaded.
