# DR2 Pipeline Rebuild — Phased Implementation Plan (AT-334)
> Roadmap for building `.ai/specs/dr2-pipeline-suspensive-conditions.md`. QA1 only.

## PROGRESS (resume here)
- ✅ Phase 0 — spec `a29a868c`; email-parties inline email modal `c5087d69`.
- ✅ Phase 1 — condition data model (3 tables + nullable cols) `0ffcb169` (additive).
- ✅ Phase 2+3 — DealStructureAssembler + Dr2ConditionCatalog + DealDateCascade `4a35f59c`.
- ✅ Phase 4 — Deal Structure tab + empty-state `85638384` (TESTABLE: empty deal → pick conditions → Build → pipeline assembles).
- ⏳ **NEXT: Phase 5** — Granted step + follows-selector (per-deal overrides). Then 6 (Restructure), 7 (trigger decoupling — FLAG F3), 8 (overdue flags).
- Side fix (not a phase): Email Parties resolves buyers/sellers from the DEAL's deal_contacts not the property `25c2d4a8`.
- Key facts to reload: new-model instances are DR1-anchored (`dr1_deal_id`, `deal_id`/deals_v2 null); assembler refuses if a pipeline exists (guardrail); cascade gated to new-model deals (is_grant_marker/condition_key). All on QA1 branch, pushed to origin/QA1. cc1's AT-294 also on origin/QA1 tip + migrated on /corex-qa1.

> **Migration guardrail:** additive changes (new tables, new nullable columns with safe
> defaults) proceed. Anything that ALTERs or risks EXISTING deal data —
> `deals` rows/status, `deal_money_lines`, existing `deal_step_instances` /
> `deal_pipeline_*` rows, or the live grant/trigger automation — **STOPS for Johan's go**.
> Each phase = one testable increment, committed, reported.

## Current state (already exists — the rebuild is mostly additive + rework, not ground-up)
- `deals`: `status` enum(active,granted,completed,cancelled,on_hold,declined) default active; `deal_date` (NOT NULL — the anchor); `deal_type` enum(bond,cash,sale_of_2nd) nullable; `granted_at`, `registration_date`, `deal_pipeline_template_id`, party provider/contact cols.
- `deal_pipeline_templates` (per agency, 3 defaults/type) + `deal_pipeline_steps` (template steps: position, is_milestone, is_suspensive, completion_type, trigger_type[on_creation|after_step|manual|on_date], trigger_step_id, days_offset, rag_*, status_trigger, negative_status_trigger, requires_bm_approval) + `deal_pipeline_step_dependencies`.
- `deal_step_instances` (PER-DEAL): deal_id/dr1_deal_id, pipeline_step_id, name, position, is_milestone, is_custom, is_suspensive, completion_type, status(not_started,active,completed,overdue,skipped), na_reason, trigger_type, **trigger_step_instance_id (a "follows" pointer ALREADY exists)**, days_offset, **due_date**, due_date_manual, activated_at, **completed_at** (= the "Actual"), completed_by_id, completion_data, current_rag, approval_status.
- Assembly: `DealPipelineService` copies template steps → instances on attach; `DealPipelineTemplateProvisioner` seeds the default template steps + their status_trigger='granted'/negative='declined'.
- Grant mechanism (CURRENT): completing a step whose `status_trigger='granted'` flips the deal (in `DealPipelineService::completeStep`) — the "hard-coded step marks granted" the spec replaces.
- UI: `dr2/pipeline.blade.php` (AT-331 tabbed right panel: Supplier Work Orders · Documents · Email Parties · Proforma) + `PipelineController`.
- No due-date CASCADE/re-baseline today (due_date set once from days_offset off the trigger step; no downstream recompute on early/late completion).

## Gap → target
1. Suspensive **conditions** as composable objects (cash Nx payments / bond +deposit / sale-of-another) driving assembly. *(new)*
2. Two dates **Due + Actual with column headings** + the follows-based **cascade + re-baseline**. *(rework — data mostly exists)*
3. **Granted = its own movable step** (replaces status_trigger tick). *(new marker + grant rework)*
4. Per-deal **follows-selector** (repoint a step's predecessor). *(UI over existing trigger_step_instance_id)*
5. **Restructure** (change conditions, waive removed steps greyed+reason+addendum, never delete). *(new)*
6. **Trigger decoupling** — grant = "finance secured" = ALL active conditions met/waived (not one step). *(rework of completeStep/grant)*
7. **Deal Structure tab** + empty-state ("Complete the deal structure to build your pipeline"). *(new tab in the AT-331 system)*
8. **Overdue flags** on DR2 register + My Deals (status 'overdue' + rag_* exist). *(surface)*

---

## Phases (build order)

### Phase 0 — spec + this plan + email modal ✅ (done)
Spec `a29a868c`, email modal `c5087d69`. Sanity-check gate before deep build.

### Phase 1 — Data model (ADDITIVE ONLY → proceed)
New tables + nullable cols; **no writes to existing rows**.
- **`deal_pipeline_conditions`** (template layer): id, pipeline_template_id, agency_id, `key`(cash|bond|sale_of_another|deposit), label, is_default, options_schema(json), timestamps. Defines which condition-packs a template offers.
- **`deal_pipeline_condition_steps`**: condition_id → pipeline_step_id (which template steps a condition contributes), position, is_grant_marker(bool). Maps steps to conditions.
- **`deal_conditions`** (per-deal): id, deal_id, agency_id, `key`, status(active|met|failed|waived) default active, options(json e.g. {payments:2, deposit:true}), waived_reason, addendum_ref, timestamps, deleted_at. The per-deal SET of conditions.
- **`deal_step_instances`** new nullable cols: `condition_key` (which condition contributed the step; null=base), `is_grant_marker` tinyint default 0, `actual_date` date null (explicit "Actual" heading; back-read from completed_at where null), `waived_reason` text null, `addendum_ref` varchar null. All nullable/defaulted → additive.
- Migrations: `schema:dump` after. Ships behind assembly that only activates for NEW deals / opt-in (Phase 2), so existing deals are untouched.
- **RISK: none to existing data** (new tables + nullable cols). Existing deals keep rendering via the current path. Proceed without a go.
- Test: migrate on QA1, models instantiate, `schema:dump` committed.

### Phase 2 — Pipeline assembly from conditions (mostly additive; gated)
- New `DealStructureAssembler` service: given a deal's active `deal_conditions` + the template's base steps + condition-step packs → produces the ordered instance set. Reuses `DealPipelineService` instance-write.
- Applies to **new deals / deals with no instances yet / on explicit (re)structure** — existing populated deals unchanged until restructured (Phase 6).
- Default condition mapping (from spec): bond=1 condition; cash=proof-of-funds(grants early)+payment(s); sale_of_another=subject-to milestone; deposit tick=+deposit step.
- **RISK: touching existing deals only if we backfill — we do NOT. New assembly path is opt-in.** Backfill of existing deals = separate, FLAGGED (see Phase 8 note). Proceed for new-deal path.
- Test: create a QA1 deal, pick conditions, assemble; verify instances match.

### Phase 3 — Dates: Due + Actual + follows cascade + re-baseline
- Column headings **Due / Actual** on the step board (missing now).
- `actual_date` editable; Actual defaults from completed_at.
- **Cascade service**: `step.due = (predecessor.actual ?? predecessor.due) + step.offset`, predecessor = `trigger_step_instance_id` (existing). Anchor = `deals.deal_date` auto-completes "Deal Signed" (first step). Completing early/late → recompute downstream dues (respect `due_date_manual` overrides — never clobber a manual due).
- **RISK: recompute WRITES due_date on existing instances of a deal when a step completes.** For a deal already on the OLD path this changes stored dues. → Gate the cascade to deals assembled by Phase 2 (or add a per-deal `uses_cascade` flag), so existing deals' dues are not rewritten. **If we want cascade on existing deals → FLAG for Johan.**
- Test: complete a step early/late on a Phase-2 deal → downstream dues shift; manual due preserved.

### Phase 4 — Deal Structure tab + empty-state
- New right-panel tab "Deal Structure" (slots into the AT-331 tab bar). Empty-state when no instances: "Complete the deal structure to build your pipeline". Pick conditions+options → save → assemble (Phase 2) → left board renders.
- **RISK: none** (new tab; new deals). Proceed.
- Test: empty deal → Structure tab → pick bond+deposit → pipeline appears on the left.

### Phase 5 — Granted step + follows-selector (per-deal overrides)
- Assembly injects a dedicated **Granted** marker step (`is_grant_marker=1`); default follows-position from setup, movable per deal.
- Per-deal **follows dropdown** on each step → repoints `trigger_step_instance_id` → re-cascade (Phase 3).
- **RISK: additive** (new marker step + editing an existing nullable pointer on Phase-2 deals). Grant *mechanism* change is Phase 6. Proceed for the UI/marker.
- Test: move Granted marker → deal grants at the new position; repoint a step's follows → dates re-cascade.

### Phase 6 — Restructure (waive + reason + addendum, greyed steps)
- "Restructure deal" button → re-opens Deal Structure → change active conditions with **mandatory reason + addendum ref** → recompose: completed steps stay; removed condition's steps → status 'waived' (or 'skipped'+waived_reason), **greyed but visible, never deleted**; new steps drop in; re-cascade.
- Add 'waived' to the display (reuse status enum 'skipped' + `waived_reason`/`addendum_ref` to AVOID an enum ALTER — see risk).
- **RISK: writes to an existing deal's instances (waive/add).** Only fires on an explicit user Restructure action (audited), not a migration — acceptable, but it is the first write-path to *existing populated* deals. Uses status 'skipped' to avoid an enum ALTER. **If we prefer a real 'waived' enum value → that ALTER is FLAGGED.**
- Test: restructure a deal (drop a condition) → its steps grey with reason+addendum; audit intact.

### Phase 7 — Trigger decoupling (finance-secured gate)
- Grant computed from **"all active suspensive conditions met/waived"** (per the deal's current conditions), not a single hard-coded `status_trigger` step. Milestones carry triggers; "Advance the deal" fires on finance-secured so restructure never orphans a trigger.
- Rework `DealPipelineService::completeStep` grant path → evaluate `deal_conditions`.
- **RISK: HIGH — changes the live grant/trigger automation.** Existing deals rely on the current status_trigger path. → **STOP + FLAG for Johan.** Mitigation: gate behind Phase-2 deals / a per-deal flag / feature flag so existing deals keep the current mechanism; new-model deals use finance-secured. Do not touch existing-deal automation without the go.
- Test: multi-condition deal grants only when the LAST active condition is met/waived.

### Phase 8 — Overdue flags (DR2 register + My Deals)
- Surface `overdue`/rag on the DR2 register + My Deals from the date model. Read-only compute; no schema.
- **RISK: none.** Proceed.
- Test: a deal with a past-due active step flags overdue on both screens.

---
## Cross-cutting flags for Johan (the STOP list)
- **F1 — deals.status enum**: do NOT ALTER (add Pending/Registered/waived). Map in display (active=Pending, completed=Registered) + use 'skipped'+waived_reason. If Johan wants real enum values → that migration is FLAGGED.
- **F2 — backfill of existing deals** into the condition model: NOT done automatically. If desired (so old deals get Structure/cascade) → mass write to existing rows = FLAGGED.
- **F3 — Phase 7 grant/trigger rework** touching existing-deal automation = FLAGGED; ship gated to new-model deals first.
- **F4 — deal_money_lines**: untouched by this plan; any change = FLAGGED.
