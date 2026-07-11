# Split Branches — does it actually split? Verification audit

**Date:** 2026-07-11
**Auditor:** Claude (QA2)
**Question asked:** does the Split Branches feature work everywhere, and does it actually
isolate branches the way `.ai/specs/branch-isolation-spec.md` designed it to?

**Answer: no. The mechanism is sound; its coverage is not.** The core pillars isolate
correctly. Fifteen other models — including the deal pipeline, commission, rentals and
e-signed contracts — do not, and are readable across branches.

**Not a live incident.** `split_branches_enabled` is **off** for both real agencies (Home
Finders Coastal #1, Demo Agency #7), so nothing is leaking in production today. These are
**latent** failures that fire the moment anyone turns the toggle on — which the setup wizard
now invites every new agency to do at step 3.

---

## 1. How this was verified (not inferred)

Static analysis alone would only prove a trait is missing, not that data leaks. So the test
was empirical, against the real database, in a rolled-back transaction:

1. Create an agency with `split_branches_enabled = true`, and two branches — Margate and
   Port Shepstone.
2. Create a plain **Agent** in Margate. Confirm they do **not** hold `branches.view_all`
   (the documented bypass). Confirmed: they do not.
3. Plant one row per model in **Port Shepstone**, with the raw query builder — so we test the
   READ scope, not the create-time auto-stamp.
4. Log in as the Margate agent and ask each model, through normal Eloquent with all global
   scopes active, whether it can see the Port Shepstone row.

Anything visible is a leak. Foreign keys were resolved to real rows so that **no model came
back inconclusive** — all 22 tested returned a definite verdict.

Harness: `scratchpad/leak_test.php` (session-local; reproduce by re-running it).

---

## 2. Result: 7 isolated, 15 leaking

### Isolating correctly — the mechanism works

`Property` · `Contact` · `Presentation` · `Document` · `CalendarEvent` · `FicaSubmission` ·
`Deal (v1)`

`BranchScope` and `DealBranchScope` do exactly what the spec says. The design is not the
problem.

### LEAKING — Agent A reads Branch B's rows

| Model | Table | `branch_id` | Why it matters |
|---|---|---|---|
| **`DealV2\DealV2`** | `deals_v2` | **NOT NULL** | **The deal pipeline.** The table demands a branch and nothing enforces it on read. If DealV2 is the live pipeline, the feature is bypassed for the most sensitive object in the system. |
| **`CommissionLedger`** | `commission_ledger` | nullable | **Money.** One branch's agents can read another branch's commission rows. |
| **`Docuperfect\Document`** | `docuperfect_documents` | nullable | **Signed contracts.** |
| **`DealMoneyLine`** | `deal_money_lines` | nullable | Deal money splits — the parent `Deal` **is** scoped; its money lines are not. |
| **`CommandTask`** | `command_tasks` | nullable | Tasks — a pillar of the Command Center. |
| **`Rental`** | `rentals` | **NOT NULL** | Whole rentals module. |
| **`DocumentFiling`** | `document_filing_register` | **NOT NULL** | Inconsistent: `documents` is scoped, its filing register is not. |
| **`CalendarEventFeedback`** | `calendar_event_feedback` | nullable | Parent `CalendarEvent` is scoped; its feedback child is not. |
| `PropertyAuditLog` | `property_audit_log` | nullable | Audit trail of a scoped model, itself unscoped. |
| `CommercialEvaluation` | `commercial_evaluations` | nullable | |
| `ListingStock` | `listing_stocks` | nullable | |
| `Target`, `DailyActivity` | `targets`, `daily_activities` | nullable | Performance data. |
| `AgentApplication` | `agent_applications` | nullable | |
| `ToolHistoryEntry` | `tool_history_entries` | nullable | |

The pattern is consistent and tells the story: **the branch scope was applied to the models
that existed when it was built, and every model added since has been born unscoped.** Nothing
enforces the rule at review time, so the coverage decays with every new table.

---

## 3. Two further gaps

### 3a. Models with no branch dimension at all

`PortalLead`, `Prospecting\TrackedProperty`, `ProspectingClaim`, `ContactNote`,
`PropertyNote`, `Worksheet`, `ViewingPack`, `ContactMatch` have **no `branch_id` column**.
They are reachable only through a scoped parent, so their isolation depends on every single
query going through that parent. A direct `ContactNote::find($id)` is unscoped. This is a
design question, not a bug: either they get a `branch_id`, or the spec must state that they
inherit their parent's branch and that direct lookups are forbidden.

`TrackedProperty` deserves its own thought — it is a pillar (Non-negotiable #10) and holds
prospecting intelligence, which is precisely the thing branches compete over.

### 3b. ~180 bare `withoutGlobalScopes()` calls

`withoutGlobalScopes()` strips **every** global scope — `AgencyScope` *and* `BranchScope`.
Most of these were written to defeat `AgencyScope` before `BranchScope` existed, and silently
acquired a branch bypass the day the trait was added. There are **zero** targeted
`withoutGlobalScope(BranchScope::class)` calls, which is the tell.

Most are legitimate (console commands and public token-gated controllers have no auth user, so
`BranchScope` is a no-op anyway). The ones that run **with a logged-in agent** are the
concern — `CalendarController` alone has 13, and `CalendarVisibilityResolver` bypassing scopes
is a notable one. Also `routes/web.php:1277`, `:1415`, `:2487` — closure routes stripping all
scopes.

**The fix for nearly all of them is mechanical:** `withoutGlobalScopes()` →
`withoutGlobalScope(AgencyScope::class)`, which keeps the intent and restores branch
isolation. But each needs a human decision, so it should not be a blind sweep.

### 3c. Zero tests

`split_branches_enabled` is **never set to `true` in any test in the repo.** None of
`BranchScope`'s logic is covered: not the `branches.view_all` bypass, not the fail-closed
`whereRaw('1 = 0')` for a NULL-branch user, not `DealBranchScope`'s pivot query, not the
User-model self-visibility carve-out. The feature's entire enforcement layer is untested,
which is why the coverage could rot silently.

---

## 3d. WHAT WAS FIXED (2026-07-11, Johan's call: "test first, then the safe 10")

**Result: 7 isolated → 17 isolated. 15 leaking → 5 leaking.** Re-verified with the same
harness; 0 inconclusive.

**The decay-stopper (written first):** `tests/Feature/Branches/BranchSplitIsolationTest.php`.
Every model whose table carries `branch_id` must be branch-scoped, or listed in
`SHARED_BY_DESIGN` (spec §7 — configuration/directory data), or listed in
`PENDING_DECISION` (the debt register). A new model that forgets the trait lands in none of
the three and **fails the suite**. This is what stops the coverage rotting again — the
original failure was structural, not a one-off mistake. Plus behavioural tests: Split ON
hides another branch's property from a plain agent; Split OFF leaves the scope completely
inert.

**Branch scope attached to the 10 unambiguous models:** `DealV2`, `CommandTask`, `Rental`,
`DocumentFiling`, `Docuperfect\Document`, `DealMoneyLine`, `CalendarEventFeedback`,
`PropertyAuditLog`, `CommercialEvaluation`, `ListingStock`.

**A defect found while fixing it — child records.** `BelongsToBranch` auto-stamps `branch_id`
from the **acting user**. That is correct for a record a user creates in their own right, but
wrong for a child whose parent lives in another branch: a principal in Margate (who holds
`branches.view_all`, so may legitimately edit a Port Shepstone deal) would stamp the money
line "Margate" — and the Shepstone agents whose deal it is could then **not see their own
money line**. New trait `App\Models\Concerns\InheritsBranchFromParent` makes a child take its
parent's branch instead. Applied to `DealMoneyLine` (→ Deal), `CalendarEventFeedback`
(→ CalendarEvent) and `PropertyAuditLog` (→ Property). Proven: the money line stamps
Shepstone, not Margate, and the Shepstone agent can read it.

This is the same defect class as the `CommissionLedger` risk below — which is why that one
was **not** touched.

### Still leaking — awaiting Johan's call (`PENDING_DECISION` in the test)

| Model | The question |
|---|---|
| **`CommissionLedger`** | **Money, and the delicate one.** It cannot simply take `BelongsToBranch`: commission rows are written from queue/console context where there is no authenticated user, so the auto-stamp would no-op to NULL — and under Split, a NULL-branch row is invisible to anyone without `branches.view_all`. That would **hide agents' own commission from them.** It needs the **earning agent's** branch, set explicitly at write time. Needs a deliberate fix, not a trait. |
| `Target`, `ActivityTarget`, `MonthlyTargetGoal`, `DailyActivity`, `DailyActivityEntry` | Performance data — plausibly *meant* to be agency-wide so a principal can compare branches. Scoping them could hide data people rely on. |
| `AgentApplication` | Recruitment — agency-wide or branch? |
| `WhistleblowComplaint`, `AgencyComplianceProvision` | Compliance — plausibly officer-only by design, in which case branch scoping is the wrong axis entirely. |
| `ToolHistoryEntry`, `TvAccessCode`, `TvMessage`, `ListingImportRun`, `ListingSnapshot` | Low-stakes; classify and move on. |

Whichever way each goes, the answer belongs in the spec's shared-scope allowlist (§7) so the
next audit does not re-litigate it. The `PENDING_DECISION` list must only ever **shrink** —
there is a test asserting it does not grow.

---

## 4. Recommendation (original — items 1 and 2 now done)

**Do not let any agency turn Split Branches on until §2 is closed.** The wizard's step 3 now
actively offers the toggle, so this is reachable today.

Priority order:

1. **A regression test that turns Split ON** and asserts cross-branch invisibility for every
   branch-scoped model — table-driven, so a newly added model that forgets the trait fails the
   suite. This is the thing that stops the decay; write it first, and let it fail.
2. **Attach branch scoping to the 15 leaking models.** Mechanically this is
   `use BelongsToBranch`, but **it is not a blind sweep** — see the risk below.
3. **Triage the authenticated `withoutGlobalScopes()` calls** (§3b), converting to
   `withoutGlobalScope(AgencyScope::class)` where branch isolation should hold.
4. **Decide §3a** — branch dimension for notes/leads/tracked properties, or an explicit
   spec statement that they inherit their parent.
5. **Add a `shared_scope` allowlist test** so a model that is *deliberately* agency-wide
   (per spec §7) is recorded as such rather than looking like an oversight.

### The risk that stops this being a blind sweep

`BelongsToBranch` does two things: it adds the read scope **and** it auto-stamps `branch_id`
on create from the acting user's branch. For some of these models that second behaviour is
wrong or dangerous:

- **`CommissionLedger`** — commission rows are written by the engine, sometimes from
  queue/console context where there is no authenticated user. The auto-stamp would be a no-op,
  leaving `branch_id` NULL — and under Split, a NULL-branch row is invisible to everyone
  without `branches.view_all`. **That would hide agents' own commission from them.** The
  branch must be taken from the *earning agent*, not the acting user.
- **`Target` / `DailyActivity` / `AgentApplication` / whistleblow** — plausibly *meant* to be
  agency-wide. Scoping them could hide data people currently rely on.

So: models where branch ownership is unambiguous (`DealV2`, `CommandTask`, `Rental`,
`DocumentFiling`, `Docuperfect\Document`, `DealMoneyLine`, `CalendarEventFeedback`,
`PropertyAuditLog`, `CommercialEvaluation`, `ListingStock`) can take the trait directly.
`CommissionLedger` needs an explicit branch at write time. The rest need Johan's call on
whether they are branch data at all — and whichever way that goes, it belongs in the spec's
shared-scope allowlist (§7) so the next audit does not re-litigate it.
