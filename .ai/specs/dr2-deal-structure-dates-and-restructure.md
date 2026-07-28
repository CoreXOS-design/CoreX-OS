# DR2 Deal Structure — Per-Condition Due Dates & Restructure

> **Status: DRAFT — planning only. NOT approved for build.** Awaiting Johan's decisions
> (esp. the exact cash timing rules — see §7). No code for Phases A–D has been written.
> Companion to `.ai/specs/dr2-pipeline-suspensive-conditions.md` (the governing model &
> locked decisions). This spec adds two capabilities on top of that model:
> (A→B) per-condition **due-date capture** that drives step dates, and
> (C→D) the **Restructure** flow (change conditions after creation, e.g. bond→cash).
>
> Last updated by: lane-6 (Deal Structure), 2026-07-28.

---

## 1. Business requirement (why)

Two gaps in the shipped composable Deal Structure model:

1. **Due dates aren't captured at setup.** Today every step's Due date is derived purely
   from a fixed `days_offset` chained off the deal-signed anchor. Agents can't say
   "bond must be approved by <date>" up front — they'd have to hand-edit the step after
   the fact. Johan wants per-condition due-by dates captured when the structure is set,
   with sensible defaults, that then **drive** the matching pipeline step's Due date.

2. **Structure can't change after creation.** An agent sets up a Bond deal; the bank
   declines; the deal switches to Cash. Today the Deal Structure tab is one-shot — the
   assembler refuses to rebuild once a pipeline exists, and the "Restructure" button is a
   disabled "coming soon". Johan wants a real Restructure: change the active conditions
   (with a mandatory reason + addendum reference) and recompose the pipeline, preserving
   completed work and audit history.

### Pillars
- **Deal** (`Deal` / DR1 `deals`) — the transaction these conditions and dates hang off.
- **Contact / Property** — unchanged; parties and property already linked at capture.
- Reads from Deal; writes enriched condition + date + audit data back to Deal-owned tables.

---

## 2. Current model (as-built, verified 2026-07-28)

This is the DR2 **composable-suspensive-condition** model (tagged `AT-334`), a new-model
overlay alongside the older template-based pipeline.

### 2.1 Storage
| Table | Role | Key columns |
|---|---|---|
| `deal_conditions` | per-deal SET of active conditions (audit-bearing) | `deal_id`, `agency_id`, `key` (`cash\|bond\|sale_of_another\|deposit`), `status` (`active\|met\|failed\|waived`), `options` JSON, `waived_reason`, `addendum_ref`, SoftDeletes |
| `deal_step_instances` | the actual pipeline steps (shared table) | composable rows anchor via `dr1_deal_id` with `deal_id=null`, `pipeline_step_id=null`; AT-334 cols `condition_key`, `is_grant_marker`, `actual_date`, `due_date`, `due_date_manual`, `waived_reason`, `addendum_ref`, `days_offset`, `planned_start`, SoftDeletes |
| `deal_step_instance_dependencies` | AND-gate fan-in (multi-predecessor convergence) | written by assembler + drag-relink |
| `deal_pipeline_conditions` / `_condition_steps` | **scaffolded but UNUSED** DB catalog | latent infra for an agency-configurable catalog; runtime uses the PHP catalog instead |

Models: `app/Models/DealV2/DealCondition.php`, `app/Models/DealV2/DealStepInstance.php`.
Deal relationship for steps is **`pipelineSteps`** (hasMany via `dr1_deal_id`), not `stepInstances`.

### 2.2 Capture flow
`resources/views/dr2/_deal-structure.blade.php`
→ `POST deals-dr2.pipeline.structure`
→ `PipelineController::saveStructure` (`app/Http/Controllers/Dr2/PipelineController.php:144`) — parses `conditions[key][on|deposit|payments]`, clamps payments 1–6
→ `DealStructureAssembler::assemble($deal, $selections)` (`app/Services/DealV2/DealStructureAssembler.php:36`)
→ `DealDateCascade::recompute($deal)` (`app/Services/DealV2/DealDateCascade.php:36`).

### 2.3 Condition catalog
`app/Services/DealV2/Dr2ConditionCatalog.php` — hard-coded PHP, the live source of truth.
- `conditions()` — option schema the Structure tab renders: `bond`→`deposit`(bool), `cash`→`payments`(int 1–6), `sale_of_another`→(none).
- `baseSteps()` — 11-step common conveyancing spine on every deal: `otp` (Deal Signed, anchor, inserted already-completed from `deals.deal_date`), `attorneys`, `fica_buyer`, `fica_seller`, `elec_coc`, `beetle`, `rates`, `docs_signed`, `transfer_duty`, `lodgement`, `registration`. **FICA is baked into the base spine, not a selectable condition.**
- `conditionSteps($key,$opts)` — per-condition packs: `bond`→ `bond_app`(+3d) / `bond_approved`(+21d, suspensive) / `guarantees`(+10d) + optional `deposit` step; `cash`→ `proof_funds`(+3d, suspensive) + N `payment_i` steps; `sale_of_another`→ `linked_sold`(suspensive).
- `resolve()` — merges base + packs, appends the **Granted marker** that AND-gate converges on all suspensive steps.

### 2.4 Dates today — offset-only
`DealDateCascade::recompute` iterative fixpoint:
`step.Due = (LATEST over predecessors of (Actual if captured else Due)) + step.days_offset`,
anchored at `deals.deal_date`. A `due_date_manual` flag is **never overwritten** (`DealDateCascade.php:85`).
There is **no absolute per-condition due-date input anywhere**, and no "30 days from signed"
rule — bond approval currently lands at otp+3+21 = day 24. Cash captures `payments` count only,
no dates.

---

## 3. ⛔ Latent bug found during investigation (report-only, not yet fixed)

`DealStructureAssembler::assemble($deal, $selections, bool $force = false)` has a `$force`
parameter intended to bypass the "pipeline already exists" guardrail for restructure. It is
**never called with `force=true`** today (only caller: `saveStructure:173`, no force).

**The bug:** as written, the `force=true` path soft-deletes the `DealCondition`s and then
**creates a brand-new full set of `DealStepInstance` rows without deleting or reconciling the
existing ones.** The code comments claim it "preserves completed steps — handled there," but
**that reconciliation logic does not exist.** Calling `assemble(force=true)` today would
**duplicate the entire pipeline.**

Consequence for the plan: Restructure is not a flag flip. A real diff/merge recompose engine
must be built (Phase C) before any Restructure UI is wired (Phase D).

Supporting gaps (infra present but inert):
- `deal_conditions.waived_reason` / `addendum_ref` and `deal_step_instances.waived_reason` /
  `addendum_ref` columns exist — **nothing writes them**.
- `DealCondition::STATUSES` includes `waived` / `failed` and `isSatisfied()` treats `waived`
  as satisfied — but there is **no `waive()` / `fail()` method**.
- Both tables + the board's `onlyTrashed()` restore paths use SoftDeletes — a foundation for
  "removed condition's steps become greyed-but-visible, never deleted".

---

## 4. Phase A→B — Per-condition due dates

### Phase A — capture (data + UI)
- Extend `Dr2ConditionCatalog::conditions()` option schema with date fields:
  - `bond` → `bond_due_by` (**default = deal_date + 30 days**, editable)
  - `deposit` (bond sub-option) → `deposit_due_by`
  - `sale_of_another` → `property_sold_by`
  - `cash` → timing fields **per §7 open decision** (proof-of-funds-by / straight-payment-by / per-payment dates)
- Render date inputs in `_deal-structure.blade.php` (empty-state build form and, later, the Restructure form).
- Parse + validate in `PipelineController::saveStructure`.
- **Store the captured intent in `deal_conditions.options`** (audit-friendly source of truth;
  survives a later restructure recompose).

### Phase B — dates drive step dates
- In `DealStructureAssembler::assemble`, project each condition's captured `*_due_by` onto its
  target **suspensive** step: set that step's `due_date` and flag `due_date_manual = true`.
- Because `DealDateCascade` refuses to overwrite a manual due (`:85`), the captured date sticks
  while all downstream steps still re-cascade off it.
- Bond's 30-day default thus **replaces** the current offset-derived day-24 Due (see §7 Q2).
- Re-run `recompute` — existing cascade handles the rest. Low risk; no new date engine.

**A→B ships independently of restructure.**

---

## 5. Phase C→D — Restructure

### Phase C — idempotent recompose engine (prerequisite; also fixes §3)
Rewrite the `force=true` path in `DealStructureAssembler` as a **diff/merge**, not delete+recreate:
- **Keep** completed / in-progress steps.
- **Insert** steps for newly-added conditions.
- **Waive (not delete)** steps of removed conditions — greyed-but-visible via SoftDelete/restore
  + `waived_reason`.
- Re-cascade (`DealDateCascade`) + reorder (`DealStepReorderService`) after recompose — both exist.

### Phase D — Restructure flow (UI + audit)
- Enable the "Restructure" control (currently disabled text at `_deal-structure.blade.php:26`):
  re-open the structure form **pre-filled** with current selections.
- Require a **mandatory reason + addendum reference**.
- Write `waived_reason` / `addendum_ref` onto affected `deal_conditions` and steps; add
  `DealCondition::waive()` / `fail()`.
- Call the Phase-C recompose; record the change (candidate audit spine: `deal_stage_moves`).
- "Advance the deal" trigger fires on "all active conditions met/waived", so restructure never
  orphans a trigger (per the governing spec §Triggers).

**Sequencing: A→B first (independent feature). C→D second (C is a prerequisite AND fixes the §3 bug).**

---

## 6. Permissions
- Reuse `create_deals` (already gates `deals-dr2.pipeline.structure`). Restructure is a
  structure change → same `create_deals` gate. No new permission key anticipated; confirm at build.

## 7. Open decisions — REQUIRED before build (do not assume)
1. **Cash timing rules (blocking).** Exact branching: "proof-of-funds required to grant (by when)"
   **vs** "no proof = straight payment by when", **plus** per-payment due dates for multi-pay.
   This defines the cash option schema and its steps. Johan's precise rule set needed before Phase A.
2. **Bond default.** Confirm "30 days from deal-signed" should **override** the current
   offset-derived bond-approval date (day 24), captured as an editable manual due. (Plan assumes yes.)
3. **Effective `deal_type` after radio removal.** The creation-screen Deal Type radio was removed
   (commit `0ceeef5c`); `deal_type` is now null at creation. Confirm whether any downstream
   report/filter needs it derived from the composed conditions (small follow-up if so).

## 8. Acceptance criteria (when A–D are eventually built)
- **A/B:** Ticking bond shows a "bond due by" date defaulting to signed+30d; editing it and
  building sets `bond_approved.due_date` = that date with `due_date_manual=true`; downstream Dues
  re-cascade from it; deposit / subject-to-sale / cash dates behave per their captured values.
- **C:** `assemble(force=true)` recomposes without duplicating steps — completed steps survive,
  removed-condition steps are waived-and-greyed, new-condition steps appear, dates re-cascade.
- **D:** Restructure button opens the pre-filled form, enforces reason+addendum, records the audit
  trail, and flips a bond deal to cash (and back) correctly.

## 9. Files (anticipated — for the eventual build, NOT touched now)
- `app/Services/DealV2/Dr2ConditionCatalog.php` — option schema + date defaults.
- `app/Services/DealV2/DealStructureAssembler.php` — project dates onto steps (B); diff/merge recompose (C).
- `app/Services/DealV2/DealDateCascade.php` — unchanged (already honours `due_date_manual`).
- `app/Http/Controllers/Dr2/PipelineController.php` — parse dates (A); restructure action (D).
- `resources/views/dr2/_deal-structure.blade.php` — date inputs (A); enable Restructure form (D).
- `app/Models/DealV2/DealCondition.php` — `waive()` / `fail()` (D).
- Route: a restructure `POST` (D) — reuse or extend `deals-dr2.pipeline.structure`.

---

### Already done (context — the two quick fixes that preceded this spec)
- `0ceeef5c` — removed the redundant Deal Type radio from the deal-creation screen.
- `7ae2433b` — DR2 register label: "Attach" → "Pipeline" once steps exist (composable deals,
  which carry no `deal_pipeline_template_id`, were stuck on "Attach"). Both on QA1.
