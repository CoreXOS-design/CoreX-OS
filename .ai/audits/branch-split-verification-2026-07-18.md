# Branch-Split (`split_branches_enabled`) — verification audit

> Date: 2026-07-18 · Branch: QA2 · Method: read the scope/trait/middleware mechanism +
> a coverage sweep of every `branch_id` model and the pillar satellites, cross-checked
> against `.ai/specs/branch-isolation-spec.md` and the pre-build audit
> (`branch-isolation-audit.md`). No code changed.

## Verdict

**The setting works correctly. The enforcement *mechanism* is sound and consistent
everywhere it is applied.** The limitation is **coverage** — a set of models have not yet
opted into branch scoping. Most of that is already a *deliberate, tracked, capped debt
register* owned by Johan; the genuinely new risk is a second tier of branch-owned data the
register cannot see.

### What is correct (verified)
- **Gate parity.** `BranchScope`, `DealBranchScope`, and `RequiresBranchAssignment` implement
  the identical 5-step gate: no auth → no-op; owner-role without an active agency override →
  no-op; `split_branches_enabled` OFF → no-op; holder of `branches.view_all` → no-op
  (principal/admin bypass); else filter to `effectiveBranchId()`, and NULL branch under split
  ON → `whereRaw('1=0')` (unassigned sees nothing).
- **Inert when OFF.** The flag is read with a default of **false** in every reader (scopes,
  middleware, `PermissionService`, `AgencyFeatureService` `multi-branch`, both blades). No
  reader defaults it on. `test_split_off_leaves_everything_visible` asserts it. No
  over-isolation when the setting is off.
- **The `multi-branch` feature toggle and the `split_branches_enabled` setting are the same
  switch** — the registry key resolves the agency column directly, so the onboarding/settings
  toggle and the isolation scopes never disagree.
- **Write-side stamping.** `BelongsToBranch` auto-fills `branch_id` from `effectiveBranchId()`
  only when blank; `InheritsBranchFromParent` overrides child stamps with the parent's branch
  (read `withoutGlobalScopes()` so a cross-branch parent still resolves). `DealV2.branch_id`
  is NOT NULL and set at capture. No NULL-orphan on audited create paths; no raw-insert branch
  orphaning outside test fixtures.
- **Correctly scoped** (trait + `branch_id` column present): Property, Contact, DealV2, legacy
  Deal (via the `deal_branches` pivot), Document, UserDocument, Docuperfect\Document,
  Presentation, FicaSubmission, CalendarEvent, CommandTask, ListingStock, ViewingPack, Rental,
  DealMoneyLine, CommercialEvaluation, PropertyAuditLog, plus compliance/leave/payroll models.
  No branch-scoped model is missing its `branch_id` column (no broken-scope gap).
- **Decay-stopper test** (`tests/Feature/Branches/BranchSplitIsolationTest.php`) fails the suite
  if any model on a `branch_id` table is neither scoped, SHARED_BY_DESIGN, nor in the capped
  known-gap register.

## Findings

### A. Tracked debt — 14 `branch_id` models not yet scoped (LEAK-1) — Johan's call
`BranchSplitIsolationTest::PENDING_DECISION`. Each is a table with a `branch_id` column whose
model lacks the trait, so under split ON a Margate agent can read Port Shepstone rows. The list
is capped (`test_known_gap_list_is_not_growing`) and documented as "awaiting Johan's call."
Highest: **`CommissionLedger` (MONEY)** — cannot simply take `BelongsToBranch` (written from
queue/console with no auth → would stamp NULL and hide agents' own commission); needs the
earning agent's branch stamped explicitly at write time. Others: Target, ActivityTarget,
MonthlyTargetGoal, DailyActivity, DailyActivityEntry, AgentApplication,
Compliance\WhistleblowComplaint (may be officer-only by design), Compliance\AgencyComplianceProvision,
ToolHistoryEntry, TvAccessCode, TvMessage, ListingImportRun, ListingSnapshot.
→ **Recommendation:** these are design decisions per model. Do NOT mass-add the trait blindly
(especially CommissionLedger). Triage with Johan; scope + backfill one cluster at a time, each
with its own test.

### B. UNMONITORED leaks — branch-owned satellites with NO `branch_id` column (LEAK-2) — most important new finding
The decay-stopper test only enumerates models that already have a `branch_id` column, so these
are invisible to it and to the register:
- **`ProspectingListing`** — spec §4.3 says it should gain `branch_id`; never implemented.
  Prospecting/tracked-property intelligence leaks fully cross-branch under split ON.
- **`Worksheet`** — agent worksheets, `agency_id`+`user_id`, no `branch_id`.
- **`ContactMatch`, `PropertyBuyerMatch`, `ProspectingBuyerMatch`** — buyer/property matching.
  `ContactMatchController` does *manual* branch filtering, but the models are unscoped, so any
  other query path leaks.
- **DealV2 child records** (~14 under `app/Models/DealV2/`: DealStepInstance, DealRemark,
  DealActivityLog, DealStepDocument, DealStepComment, DealStageMove, DealV2Settlement, …) — no
  `branch_id`, no `InheritsBranchFromParent`. Isolated only when reached *through* the scoped
  parent; a direct query (activity feeds, step lists) leaks under split ON.
→ **Recommendation:** decide branch-vs-shared per model with Johan; for the branch ones, add
`branch_id` (+ `InheritsBranchFromParent` for the DealV2 children) and **extend the
decay-stopper test to also enumerate a curated list of agency-data tables that MUST justify
having no `branch_id`**, so this tier stops being invisible.

### C. Over-isolation — legacy deals with an empty `deal_branches` pivot (OVER-ISO-1)
`Deal` uses the pivot scope and only auto-attaches the originator pivot row on `created`. There
is no backfill populating `deal_branches` for pre-existing deals, so when an agency flips split
ON, plain agents lose sight of their own historical deals until a pivot row exists (the code
comment concedes it). Impact depends on how live the legacy `deals` table still is vs `deals_v2`.
→ **Recommendation:** a one-off backfill migration attaching each legacy deal's `branch_id` (or
its originator's branch) into `deal_branches`. Small, safe, worth doing before any agency with
legacy deals turns split on.

### D. Two minor gate inconsistencies
- **`ContactMatchController` (`:84-86`)** computes `$branchLimited = $splitOn && $branchId`
  without the `branches.view_all` check, so an admin/principal acting-as-branch gets their
  agent-filter dropdown clamped while `BranchScope` still shows them all data. Dropdown-only,
  but it interprets the flag differently from every other enforcer. (Has a design dimension:
  should acting-as-branch scope the dropdown or not? Align to the canonical gate = don't clamp
  for `view_all` holders.)
- **`DealV2::scopeVisibleTo` (`:297-298`)** filters on raw `$user->branch_id`, ignoring the
  `view_as_branch_id` override, where the canonical gate uses `effectiveBranchId()`. Low impact
  (the global `BranchScope` is authoritative); it is a second divergent reading of "the user's
  branch."
→ **Recommendation:** both are small alignment fixes to the canonical gate; safe to apply with
a nod, but each carries a small acting-as-branch design nuance worth confirming.

## Bottom line
The branch-split *setting itself does what it should* — turn it on and the scoped models
isolate by branch; turn it off and everything is one pool, no over-isolation. The remaining
work is **coverage**, and most of it is already a deliberate, Johan-owned debt register — not a
bug to blind-fix. The one thing the codebase is NOT currently tracking is the LEAK-2 satellite
tier (no `branch_id` column), which is the highest-value place to invest next.
