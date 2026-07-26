# Branch-Split (`split_branches_enabled`) — re-verification

> Date: 2026-07-19 · Branch: QA2 · Method: independent re-read of the whole enforcement
> mechanism (scopes, trait, child-inheritance, middleware, permission gate, feature gate,
> role defaults) + confirmation that the 2026-07-18 fixes landed + a regression sweep since
> that audit. No code changed. Follows and confirms `branch-split-verification-2026-07-18.md`.

## Verdict

**The branch-split function is correct and does split branches correctly.** The isolation
*mechanism* is sound and — verified again — identical across every reader. The only open item
remains **coverage** (a tracked, capped debt register + an untracked satellite tier), not a
defect in the split itself. Nothing has regressed since 2026-07-18.

Note: the phpunit binary is not installed in this QA2 lane (`vendor/bin/phpunit` absent, no
`artisan test`), so `BranchSplitIsolationTest` could not be *executed* here. Verification below
is by source reading + fix confirmation. The behavioural test exists and asserts the right
things; it should be run on a lane with the test toolchain before any agency flips Split ON.

---

## 1. Core mechanism — re-verified correct

**Gate parity (the thing that must never diverge).** Four independent readers implement the
*identical* 5-step gate, confirmed line-by-line:

| Reader | File |
|--------|------|
| `BranchScope` (direct `branch_id`) | `app/Models/Scopes/BranchScope.php` |
| `DealBranchScope` (pivot) | `app/Models/Scopes/DealBranchScope.php` |
| `RequiresBranchAssignment` (middleware) | `app/Http/Middleware/RequiresBranchAssignment.php` |
| `PermissionService::getDataScope` | `app/Services/PermissionService.php:243-255` |

The gate, in all four: no auth → no-op · owner-role with no active agency override → no-op ·
`split_branches_enabled = false` → no-op · holder of `branches.view_all` → no-op (principal/
admin bypass) · else scope to `effectiveBranchId()`; NULL branch under Split ON → `whereRaw('1=0')`
(unassigned user sees nothing, middleware redirects with a banner).

**Inert when OFF (no over-isolation).** Every reader reads the flag with a default of **false**
(`BranchScope::splitBranchesEnabled`, the middleware's `first(['split_branches_enabled'])`,
`PermissionService`, and the `multi-branch` registry key). No reader defaults it on.

**One switch, not two.** `AgencyFeatureService::SWITCHBOARD_STORES['multi-branch']` resolves the
`agencies.split_branches_enabled` **column directly** — so the onboarding wizard toggle, the
Settings feature switchboard, and the isolation scopes can never disagree about the state.

**Write-side stamping.**
- `BelongsToBranch` auto-fills `branch_id` from `effectiveBranchId()` **only when blank** —
  explicit assignment always wins.
- `InheritsBranchFromParent` (declared *after* `BelongsToBranch` so its `creating` listener
  registers second and wins) overrides a child's stamp with its **parent's** branch, read
  `withoutGlobalScopes()` so a cross-branch parent still resolves. Applied to `DealMoneyLine`,
  `CalendarEventFeedback`, `PropertyAuditLog`. This prevents the "principal in Margate edits a
  Port Shepstone deal → money line stamped Margate → Shepstone agents can't see it" bug.

**`effectiveBranchId()`** (`app/Models/User.php:337`) honours the `view_as_branch_id` session
override, else `branch_id`. Every enforcer routes through it — so an admin's "act as branch"
context is respected uniformly.

**Role defaults are correct** (`config/corex-permissions.php` + `SyncPermissions.php:185-192`):
- `super_admin` = owner role → `hasPermission` short-circuits to true → BranchScope always bypassed.
- `admin` (principal) = **all-minus-exclude**; `branches.view_all` and `branches.edit_all` are
  NOT in the exclude list → admin holds both → principals see across all branches (spec §17.2). ✅
- `branch_manager` = `branches.switch` only, NOT `view_all` → branch-scoped by default (spec §5.5). ✅
- `agent` / `viewer` = neither → branch-scoped. ✅

**Live deal system.** `DealV2` uses `BelongsToBranch` and `deals_v2.branch_id` is **NOT NULL**
(`->constrained('branches')`) — set at capture, no NULL-orphan path. Legacy `Deal` uses the
`deal_branches` pivot via `DealBranchScope`; the originator pivot row is auto-attached on
`created` and re-synced on `branch_id` change. Co-branch attach is a real endpoint
(`admin.deals.branches.attach` → `DealBranchController::attach` → `Deal::attachCoBranch`).

---

## 2. 2026-07-18 fixes — confirmed landed

- **OVER-ISO-1** legacy-deal backfill: `database/migrations/2026_07_18_000004_backfill_legacy_deal_branches.php` present.
- **INCONSISTENCY-1** `ContactMatchController:88` now includes `&& !$user->hasPermission('branches.view_all')` in `$branchLimited`. ✅
- **INCONSISTENCY-2** `DealV2::scopeVisibleTo:301` now filters on `$user->effectiveBranchId()` (was raw `branch_id`). ✅

---

## 3. Regression sweep since 2026-07-18 — clean

- Only one branch-touching migration since the audit (the backfill itself). No new `branch_id`
  model was introduced that the decay-stopper would now catch.
- 31 models carry `BelongsToBranch`. The `PENDING_DECISION` (14) and `SHARED_BY_DESIGN` (4)
  registers in `BranchSplitIsolationTest` are unchanged; `test_known_gap_list_is_not_growing`
  caps the debt at 14.

---

## 4. Open items (unchanged from 2026-07-18 — coverage, not correctness)

These are **Johan's per-model design calls**, documented and tracked. They are NOT
blind-fixable and are the reason the split is "correct mechanism, incomplete coverage."

- **LEAK-1 — 14 `branch_id` models not yet scoped** (`PENDING_DECISION`). Highest:
  **`CommissionLedger` (MONEY)** — must take the *earning agent's* branch at write time, not
  `BelongsToBranch`'s acting-user stamp (rows are written from queue/console with no auth).
- **LEAK-2 — branch-owned satellites with NO `branch_id` column** (invisible to the
  decay-stopper). `ProspectingListing` (spec §4.3 said it should get `branch_id`; never done),
  `Worksheet`, the buyer-match models (`ContactMatch`, `PropertyBuyerMatch`,
  `ProspectingBuyerMatch`), and ~14 `DealV2` child records (step instances, remarks, activity
  logs, settlements…). Isolated only when reached *through* the scoped parent; a direct query
  leaks under Split ON. **Highest-value place to invest next** — and the decay-stopper should be
  extended to enumerate a curated list of agency tables that must justify having no `branch_id`.
- **Design note (new):** cross-branch *co-branch* sharing exists only on the **legacy `Deal`**
  pivot. `DealV2` (the live system) has a single NOT-NULL `branch_id` and no co-branch pivot —
  a DealV2 can belong to exactly one branch. If cross-branch deals are wanted in the live
  system, that is a schema/UX addition, not a bug.

## Bottom line

Turn the switch on → the scoped models isolate by branch; turn it off → one pool, no
over-isolation; principals see everything, branch users see their branch, unassigned users see
nothing. That is exactly the spec. The remaining work is extending *coverage* to the two tiers
above — a tracked backlog, not a correctness failure in the split.

---

## Remediation applied — 2026-07-19 (coverage pass)

Johan's ruling: scope the safe clusters now; do CommissionLedger via earning-agent stamping;
keep WhistleblowComplaint + AgencyComplianceProvision agency-wide. Done:

**Design key.** Every model scoped this pass has an owning `user_id`/`captured_by_user_id` or a
branch-carrying parent, so branch is inherited from that OWNER/PARENT via
`InheritsBranchFromParent` — **context-independent**, so a write from a queue/console/observer
with no authenticated user still stamps the right branch. This sidesteps the acting-user
NULL-stamp trap that made CommissionLedger dangerous to scope naively.

**New `branch_id` columns + `BelongsToBranch`/`InheritsBranchFromParent` (5 migrations, backfilled):**
- **DealV2 per-deal children** (LEAK-2): DealActivityLog, DealDocumentDistribution, DealRemark,
  DealStageMove, DealStepEscalation, DealStepInstance, DealV2Settlement (inherit ← DealV2 via
  `deal_id`); DealStepComment, DealStepDocument (inherit ← DealStepInstance).
  `DealDocumentAccessLog` deliberately left untouched — append-only, immutable POPIA evidence log
  documented as "not read-scoped; reads are always via an owned distribution."
- **Buyer-match models** (LEAK-2): ContactMatch (← Contact), PropertyBuyerMatch (← Property),
  ProspectingBuyerMatch (← ProspectingListing).
- **Top-level owned records** (LEAK-2): ProspectingListing (← capturing agent), Worksheet (← owner).

**Already had `branch_id`; trait added + NULLs backfilled from owner (LEAK-1):**
Target, ActivityTarget, DailyActivity, DailyActivityEntry (inherit ← User via `user_id`);
MonthlyTargetGoal (branch set explicitly at its firstOrCreate callsites).

**CommissionLedger (MONEY) — its own careful pass:** `BelongsToBranch` +
`InheritsBranchFromParent[User, user_id]` (the EARNING agent). Existing rows backfilled from the
earning agent's branch. **Tinker-verified in a no-auth context:** the row is stamped the earning
agent's branch, an agent in another branch cannot see it, and the earning agent still sees their
own commission — i.e. the "agents lose sight of their own commission" failure mode does NOT occur.

**Decay-stopper (`BranchSplitIsolationTest`):** WhistleblowComplaint + AgencyComplianceProvision
moved to `SHARED_BY_DESIGN` (Johan: agency-wide). `PENDING_DECISION` shrank 14 → 6; the ratchet
assertion tightened to `<= 6`. Remaining known gaps (real open questions, not silence):
AgentApplication (public-portal write, no auth at create), ToolHistoryEntry, TvAccessCode,
TvMessage (no `agency_id` column — structural), ListingImportRun, ListingSnapshot.

**Verification run here (no PHPUnit in this lane):** `php -l` clean on all 20 models + 5 migrations;
all 5 migrations ran + backfilled locally; `schema:dump` refreshed. A Tinker harness replicated
the decay-stopper (PASS — every `branch_id` model classified) and proved owner/earning-agent
stamping + read isolation for CommissionLedger, Worksheet, Target, DailyActivityEntry.

> **MUST run before merge/deploy** (this lane has no test runner):
> `tests/Feature/Branches/BranchSplitIsolationTest.php` + the broader suite on a test-capable
> lane, then deploy per non-negotiable #12 (migrate → sync-reference-data → clears → reload).
> The 5 backfill/column migrations must run on Staging/live before any agency with existing
> data flips Split ON.
