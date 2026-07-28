# DR2 Deal Structure — Per-Condition Due Dates & Restructure

> **Status: Phase A + B APPROVED & BUILDING (Johan's decisions in, 2026-07-28). Restructure
> (C/D) HELD.** Companion to `.ai/specs/dr2-pipeline-suspensive-conditions.md` (the governing
> model & locked decisions). This spec adds two capabilities on top of that model:
> (A→B) per-condition **due-date capture** that drives step dates — **now building**, and
> (C→D) the **Restructure** flow (change conditions after creation) — **held for later**.
> A→B also carries the **Stage-2 grant-anchoring cascade fix** (§10).
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

## 4. Phase A→B — Per-condition due dates (JOHAN'S FINAL RULES)

### Johan's rules (2026-07-28)
- **Editable deal-signed (anchor) date.** The Deal Structure form lets the agent set/correct the
  real signed date (e.g. signed Friday, captured Tuesday). It persists to `deals.deal_date` and is
  the anchor the 30-day bond default and the whole cascade run from — the **signed** date, never the
  capture date.
- **BOND** → `bond_due` defaults to **deal-signed + 30 days**, EDITABLE (a seller may allow only 14).
- **DEPOSIT** (bond sub-option) → capture `deposit_due` ("deposit due by"). No default (blank ⇒ offset).
- **SUBJECT-TO-SALE** → capture `property_sold_due` ("property sold by"). No default (blank ⇒ offset).
- **CASH** (custom per deal, no fixed rules — working model):
  - A **toggle** `funds_mode`: **`available`** ("funds available now") **vs** **`proof_later`**
    ("proof of funds now, payment later").
  - `available` → **just a cash-payment step** (no proof step; not suspensive).
  - `proof_later` → a **Proof of Funds** step (suspensive, grants the deal) **PLUS** a later
    **Payment Received** step.
  - `proof_due` = when proof is given (proof_later only). `payments` = # payments (1–6).
    `payment_dues[i]` = when EACH payment is paid (one date per payment; a single date if payments=1).

### Phase A — capture (data + UI)
- Extend `Dr2ConditionCatalog::conditions()` option schema (metadata for the fields above).
- Render the inputs conditionally per ticked condition in `_deal-structure.blade.php` (editable
  signed date at top; bond due defaulting to signed+30 live via Alpine; deposit/proof/payment/
  property-sold dates shown only for their condition). Empty-state build form only (restructure held).
- Parse + validate in `PipelineController::saveStructure`; persist the signed date to `deals.deal_date`.
- **Store the captured intent in `deal_conditions.options`** (audit-friendly; survives a later restructure).

### Phase B — dates drive step dates
- The catalog tags each date-bearing step def with `manual_due` (bond_approved←bond_due,
  deposit←deposit_due, proof_funds←proof_due, payment_i←payment_dues[i], linked_sold←property_sold_due)
  and builds the cash steps per `funds_mode`.
- In `DealStructureAssembler::assemble`, when a step def carries `manual_due`, create it with
  `due_date` = that date and `due_date_manual = true`.
- `DealDateCascade` never overwrites a manual Due, and (per §10) now **propagates** manual Dues to
  successors, so an editable condition date drives every downstream step.

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

## 7. Decisions — RESOLVED (Johan, 2026-07-28)
1. **Cash timing rules** → RESOLVED: the `funds_mode` toggle model in §4 (available vs proof_later),
   with `proof_due`, `payments`, and per-payment `payment_dues[i]`.
2. **Bond default** → RESOLVED: yes, **deal-signed + 30 days**, captured as an editable manual due
   that overrides the offset-derived date. Deal-signed date itself is editable.
3. **Effective `deal_type`** → not in scope for A/B; leave null. (Revisit only if a report needs it.)

## 8. Acceptance criteria (when A–D are eventually built)
- **A/B:** Ticking bond shows a "bond due by" date defaulting to signed+30d; editing it and
  building sets `bond_approved.due_date` = that date with `due_date_manual=true`; downstream Dues
  re-cascade from it; deposit / subject-to-sale / cash dates behave per their captured values.
- **C:** `assemble(force=true)` recomposes without duplicating steps — completed steps survive,
  removed-condition steps are waived-and-greyed, new-condition steps appear, dates re-cascade.
- **D:** Restructure button opens the pre-filled form, enforces reason+addendum, records the audit
  trail, and flips a bond deal to cash (and back) correctly.

## 9. Files (A/B build; C/D held)
- `app/Services/DealV2/Dr2ConditionCatalog.php` — option-schema metadata; `manual_due` tags; cash `funds_mode` steps. **[A/B]**
- `app/Services/DealV2/DealStructureAssembler.php` — write `due_date` + `due_date_manual` from `manual_due`. **[B]**
- `app/Services/DealV2/DealDateCascade.php` — grant-anchoring + manual-Due propagation (§10). **[B + Stage-2 fix]**
- `app/Http/Controllers/Dr2/PipelineController.php` — `saveStructure`: parse dates, persist signed date. **[A]**
- `resources/views/dr2/_deal-structure.blade.php` — editable signed date + conditional date inputs. **[A]**
- `app/Models/DealV2/DealCondition.php` — `waive()`/`fail()`. **[D — held]**
- Restructure route/UI. **[D — held]**

## 10. Stage-2 grant-anchoring cascade fix (part of B)
**Symptom (deal 183):** Grant projected 26 Aug, but Documents Signed showed 8 Aug and Attorneys
Instructed 3 Aug — Stage-2 (Transfer & Registration) steps predated the grant.

**Root cause (in `DealDateCascade::recompute`):** two defects.
1. The grant marker's Due was derived from its *wired* predecessors and from *computed* offset dates.
   On a multi-suspensive deal (bond **and** cash), the marker under-projected to the earliest
   suspensive (Proof of Funds, 31 Jul) instead of the latest (Bond Approved, 26 Aug).
2. A manually-set Due did not **propagate** — only Actuals did — so an editable/manual bond due
   (26 Aug) never reached the grant marker or Stage 2.

**Fix (Johan's rule).**
- **Grant = the LATEST Due across ALL active suspensive steps** (`is_suspensive`), computed directly
  from the suspensive set — independent of the marker's follows/deps wiring. No suspensive
  condition ⇒ grants on signing (anchor).
- Each step **contributes downstream** its *output* = `actual` (if captured) ELSE a **manual Due**
  (now honoured) ELSE its computed Due. So editable condition dates drive successors.
- Because every Stage-2 step's predecessor chain routes through the grant marker, once the marker is
  correct, all Stage-2 steps compute forward from grant and **never predate it**.
- Backward compatible: a step with no manual Due and no Actual outputs its computed Due exactly as
  before; a correctly-wired single-suspensive deal is unchanged. Guarded to new-model deals only.

**Blast radius:** confined to `DealDateCascade::recompute`; affects only composable (new-model)
deals. Behavioural delta to flag: manual Dues now propagate to successors (previously islands) —
this is intended and required for editable condition dates.

**Grant-anchor semantics — CONFIRMED actual-aware (Johan, 2026-07-28).** When a suspensive
condition is COMPLETED EARLY, the grant anchors to its ACTUAL date, not its planned due — so a
met condition no longer holds the deal back to its original due. For every not-yet-granted deal
(all suspensive still pending) this is identical to "grant = latest suspensive due". Worked
example (deal 183): Bond Approved completed 27 Jul (due 26 Aug), Proof of Funds still pending
(due 31 Jul) → grant = 31 Jul (the binding pending condition), Stage-2 follows from there.

---

### Already done (context — the two quick fixes that preceded this spec)
- `0ceeef5c` — removed the redundant Deal Type radio from the deal-creation screen.
- `7ae2433b` — DR2 register label: "Attach" → "Pipeline" once steps exist (composable deals,
  which carry no `deal_pipeline_template_id`, were stuck on "Attach"). Both on QA1.
