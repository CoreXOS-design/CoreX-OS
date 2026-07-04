# DR2 Capture-Parity Investigation — "a whole process and broken deal register I didn't ask for"

> READ-ONLY investigation. No code changed. DR1 (`deals` / `App\Models\Deal`) never touched.
> Verified against `origin/Staging` HEAD `7bd215a1` (a detached read-only worktree = exactly what Johan saw this morning) + live `hfc_staging` DB reads.
> Author: Claude (CC session), 2026-07-04. Feeds AT-158. HOLD — proposal only, Johan rules before any build.

---

## 0. Executive summary (the truth Johan asked for)

Johan opened Deal Register V2 on Staging and found a foreign capture "process," missing DR1 rules, empty pipeline templates, and no obvious way to set a pipeline up. Investigation confirms each complaint and root-causes them precisely:

1. **The foreign 5-step wizard is NOT this week's work.** `resources/views/deals-v2/create.blade.php` and the `DealV2Controller@store` capture handler are **100% dormant March-2026 scaffolding** (commit `641d2ee2`, 2026-03-31). Git blame: 79/79 capture lines from that commit; **zero** of this week's WS0–WS8 (AT-158) commits touched capture. The WS work bolted back-of-house machinery *around* the dormant module and lit it up — exposing the original 2026 capture UX to Johan for the first time.
2. **The "DR1 rules are missing" feeling is real but narrower than stated.** DR1 does **not** cap agents at "2 listing / 1 selling" — DR1 allows **unlimited agents on both sides** (symmetric multi-select). What DR2's *create wizard* does is cap at **1 listing + 1 selling** (single-`<select>`). So the true parity gap is "multiple agents per side," and DR2's own *edit* form already supports it — only the create wizard is stricter.
3. **The pipeline is empty because it was never seeded on staging — not because setup is broken.** Setup routes/controllers/nav/step-builder all work. The 3 spec-promised default templates (`DealPipelineTemplateSeeder`) never ran on `hfc_staging`. Johan's own "test" template (bond, 0 steps, created + soft-deleted this morning 05:48→05:49) is the only row that ever existed here.
4. **DR2 is closer to a DR1 clone than feared on money.** The March-2026 Phase-1 build already cloned DR1's commission/settlement engine (side splits, external-agency toggles, per-agent PAYE/deductions/sliding, settlement + payslip prints). The real divergence is **capture ergonomics** (wizard vs single form; forced contact-FK parties vs free-text; 1-agent-per-side) and a **pipeline-driven status + BM-approval workflow** DR1 never had — the "process I didn't ask for."
5. **This week's WS0–WS8 machinery is legitimate bolted-pipeline value and should be kept** (sync, supplier directory, document spine, distribution/secure-link/auto-COC, notifications/escalation, RAG thresholds, overview/board/CSV/iCal). The remediation is a **capture-layer re-clone + template provisioning**, not tearing DR2 down.

**Bottom line for Johan: DR2 does not need to be tossed. The foreign feel comes from one dormant screen (the wizard) plus an unseeded pipeline. Re-clone the capture screen to DR1's single-form ergonomics, restore multi-agent-per-side, decouple deal status from the pipeline, and seed the templates. The rest of DR2 (the reason to build V2 at all) stands.**

---

## A. DR1 AS-BUILT RULE INVENTORY (the parity contract)

DR1 = `deals`, `deal_user`, `deal_settlements`, `deal_money_lines`, `deal_logs`, `deal_branches`. It is one system also known as **Agency Tracker / Commission Engine**. Capture/edit engine: `Admin\DealController` (1238 lines). Agent view is read-only (`Agent\DealRegisterController`, 95 lines — index/log/addRemark only, no create/edit).

| # | DR1 rule | Evidence |
|---|----------|----------|
| A1 | Capture is a **single-page form**, 3 sections (Deal Details / Status & Registration / Sides-Splits-Agents). No wizard. Same form for create + edit. | `resources/views/admin/deals/form.blade.php:82,183,226`; `DealController.php:213,227` |
| A2 | **Only 4 hard-required fields**: `period`, `deal_date`, `property_value`, `total_commission`. Everything else nullable. No FormRequest — inline validation. | `DealController.php:335-368` |
| A3 | **Parties are free-text**, all nullable: `seller_name`, `buyer_name`, `attorney_name` (no contact FK). | `DealController.php:352-354`; `create_deals_table` migration |
| A4 | `deal_no` **system-generated** (max numeric / `D-####`, seed 1001), field disabled. | `DealController.php:242-262`; `form.blade.php:89` |
| A5 | **Unlimited agents per side, symmetric.** Both listing & selling are `<select multiple>`, validated `['array']` with **no max**. No 2/1 asymmetry anywhere. | `form.blade.php:261,302`; `DealController.php:364-365` |
| A6 | **≥1 agent required on a non-external side.** | `DealController.php:456,466-468` |
| A7 | Agents stored in one pivot `deal_user` discriminated by `side ENUM('listing','selling')`, unique `(deal_id,user_id,side)`. Detach-all-then-reattach on save, snapshotting cut%/PAYE. | `create_deal_user_table` migration; `DealController.php:521,553-572` |
| A8 | **Side split %** (`listing_split_percent`+`selling_split_percent`) must total **100 ±0.01** (default 50/50). | `DealController.php:446-452` |
| A9 | **Per-agent % overrides**: if any supplied on a side, all agents need a % and they must total 100 ±0.01; else equal split 100/count. | `DealController.php:470-489,536-537` |
| A10 | **External-agency toggle per side**: `{side}_external` forces our-share 0, skips agent requirement, creates an external payable; agency name free-text. | `DealController.php:461-464`; `DealMoneyLineRebuilder.php:46-60` |
| A11 | Commission captured **inc-VAT**, computed **ex-VAT** (VAT rate from `PerformanceSetting('vat_rate',15)`). `property_value` = selling price, not VAT-rated. | `Deal.php:239-249,607` |
| A12 | **Settlement engine**: per-agent `allocated→gross→paye→net→company`; PAYE method fixed/percentage; deductions; **balance checksum** must equal ex-VAT commission ±0.01. | `DealController.php:730-755,1183-1194` |
| A13 | **Cannot mark Paid unless settlement balances.** | `DealController.php:757-765` |
| A14 | **Two-axis status, free dropdown, no state machine**: `accepted_status` P/D/G/R + `commission_status` Not Paid/Paid/Loss. Any status selectable directly; changes logged, not blocked. | `form.blade.php:193-196,205-207`; `DealController.php:290-325` |
| A15 | **Paid = financial lock** (operational fields stay editable; commission/splits/agents frozen). | `DealController.php:19-22,405-444` |
| A16 | **Sliding scale** recomputed on transition into/out of Granted (`'G'`). | `DealController.php:576-584`; `SlidingScaleService::applyForDeal` |
| A17 | **No deal-type field.** Sale/rental not modelled; behaviour driven purely by side flags + splits. | `create_deals_table` + all ALTERs (no `deal_type` column) |
| A18 | **Scoping**: `Deal::scopeVisibleTo()` own/branch/all via `PermissionService::getDataScope`; `DealBranchScope` global scope (pivot `deal_branches`); `BelongsToAgency`. Branch users forced to own branch on save. | `Deal.php:744-769`; `DealBranchScope.php:66-91`; `DealController.php:375-377` |
| A19 | **Reporting spine**: every mutation rebuilds derived `deal_money_lines` + `RollupService::refreshPeriod` + fires `Deal\DealMoneyLineChanged`. Feeds TV, BM/Admin performance, agent commission, worksheets, Ellie. | `DealController.php:589,318,897`; `DealMoneyLineObserver.php:22-56` |
| A20 | Every mutation logged to `deal_logs`; latest remark also mirrored to `deals.remarks`. SoftDeletes on all DR1 tables (no hard delete). | `DealController.php:33-47,51-72` |

---

## B. DR2 AS-BUILT ANATOMY + PROVENANCE

DR2 = `deals_v2`, `deal_pipeline_templates/steps`, `deal_step_instances`, `deal_v2_agents/contacts`, `deal_v2_settlements`, `deal_step_documents`, plus this-week additions. Reached via sidebar group "deals-v2" (`corex-sidebar.blade.php:1507-1551`): New Deal / Deal Register / Pipeline Overview / Pipeline Setup / Supplier Directory.

### B.1 The capture flow (what Johan hit)
`GET /deals-v2/create` → `DealV2Controller@create` (`:169-215`) → `create.blade.php` (665 lines, `x-data="dealWizard()"`). A genuine **5-step wizard** with a step-rail `['Property','Contacts','Details','Pipeline','Confirm']` (`create.blade.php:33-49`):

- Step 1 Property — must select a CoreX property (Next gated). Auto-pulls linked contacts as sellers.
- Step 2 Contacts — must add ≥1 buyer AND ≥1 seller **as contact records** (Next gated `hasBuyer && hasSeller`). **No free-text party path** (contrast A3).
- Step 3 Details — deal-type radio (bond/cash/sale_of_2nd), price, commission %↔inc-VAT, split %, per-side external toggle + **single agent `<select>` per side** (`:272-278,303-309`).
- Step 4 Pipeline — pick template, review/adjust step offsets.
- Step 5 Confirm — `submitDeal()` POSTs to `deals-v2.store` → `DealPipelineService::createDeal()`.

### B.2 Provenance — two clean eras
- **Phase-1 dormant** = `641d2ee2` (2026-03-31): the wizard, `DealV2Controller` capture/store/validation, edit form, pipeline-setup + step controllers, settlement controller, `DealPipelineService::createDeal`, models, all `2026_03_30_3000xx`/`5000xx` migrations, and the register/detail/setup views. **This is the foreign capture UX.** Git blame on `create.blade.php` and `store()` (lines 217-295): all `641d2ee2`; no WS commit touched them.
- **This-week WS (all 2026-07-03, AT-158)**: WS0 engine hardening + `granted` status (`a2ff9342`); WS1 `DealSyncService` DR1↔DR2 (`cac51bab`); WS2 supplier directory (`680cc029`); WS3 document spine `documents.deal_id` (`286474ec`); WS4 distribution matrix + secure-link/OTP + auto-COC (`3eaee0b6`); WS5 comms archive on Property (`31a34dff`); WS6 `NotificationService` + escalation (`ff50a729`); WS7 two-threshold RAG (`85707f5c`); WS8 overview/board/CSV + iCal (`7498fca7`/`f95a0705`); permission doctrine (`5c36f944`). **None rebuilt capture.**

### B.3 GAP REGISTER — DR1 rule → DR2 status

| DR1 rule | DR2 status | Note / evidence |
|----------|-----------|-----------------|
| A1 single-form capture | **CONTRADICTED** | DR2 = 5-step wizard `create.blade.php`. Johan's #1 complaint. |
| A2 only 4 required fields (lazy-but-valid) | **CONTRADICTED (stricter)** | DR2 wizard gates require property + buyer + seller + agents before you can proceed. |
| A3 free-text parties | **CONTRADICTED** | DR2 forces pillar contact FKs (wizard step 2). Spec §1 intends this ("no freeform names") — but it removes DR1 flexibility. |
| A5 unlimited agents both sides | **CONTRADICTED (create UI only)** | DR2 create wizard = 1+1 single-select; DR2 **edit** form + `buildAgentsFromForm()` (`:703-733`) already support multiple. Fix is create-UI only. |
| A6 ≥1 agent non-external | **REPLICATED** | wizard `hasRequiredAgents`. |
| A8 side split 100% | **REPLICATED** | commission columns `2026_03_30_500001`. |
| A9 per-agent override / equal split | **PARTIAL** | edit form auto-split 100/count; create wizard single-agent = 100%. DR1's explicit override-totals-100 not in wizard. |
| A10 external-agency per side | **REPLICATED** | `listing_external`/`selling_external`/`*_our_share_percent`/`*_external_agency` columns present. |
| A11 inc-VAT capture / ex-VAT compute | **REPLICATED** | wizard live VAT calc. |
| A12 settlement engine + checksum | **REPLICATED** | `DealV2SettlementController` + `deal_v2_settlements` + print views (Phase-1 clone). |
| A14 two-axis free status | **CONTRADICTED / SUPERSEDED** | DR2 status = `active/completed/cancelled/on_hold/granted` **driven by pipeline step status-triggers + BM-approval gate** (spec §135). `commission_status` retained. The `accepted_status` P/D/G/R axis is replaced by workflow — the "process I didn't ask for." |
| A15 Paid financial lock | **PARTIAL/UNVERIFIED** | `commission_status default 'Not Paid'` present; lock semantics to confirm in re-clone. |
| A16 sliding scale on Granted | **PARTIAL** | `sliding_*` columns on `deal_v2_agents` present; runtime trigger to verify. |
| A17 no deal-type | **SUPERSET** | DR2 adds bond/cash/sale_of_2nd (no rental either). Not a regression. |
| A18 scoping own/branch/all | **REPLICATED (own scope)** | DR2 has its own scope + `branch_id`; parity to verify. |
| A19 reporting spine (money-lines/rollup/TV/BM/commission) | **MISSING** | DR2 does not feed `deal_money_lines`, `RollupService`, TV, BM performance, or agent commission. If DR2 replaces DR1, reporting must be repointed or fed via `DealSyncService` (WS1). Largest structural gap. |
| A20 deal_logs + soft deletes | **REPLICATED** | `deal_activity_log` + SoftDeletes. |

---

## C. PIPELINE SETUP — WHY EMPTY, WHY IT FELT UNUSABLE

**Verdict: setup is structurally sound and unprovisioned — not broken.**

### C.1 Where templates come from + what happened
- Source of truth is a seeder: `database/seeders/DealPipelineTemplateSeeder.php` — 3 templates with full steps (Standard Bond Sale 15 / Cash Sale 9 / Sale-of-2nd 16), seeded for the first admin's agency. Registered in `DatabaseSeeder.php:45`, `DemoDataSeeder.php:565` (env-gated), and `scripts/deploy.sh:366`.
- **It never ran on `hfc_staging`.** Staging is periodically cloned from live `nexus_os`, where DR2 has been HELD/unseeded throughout its build; a clone lands empty. The manual staging deploys used during DR2 work (`git pull + migrate + view:clear + config:clear`) **skip** the reference-seeder step (only full `scripts/deploy.sh` runs STEP 6, `deploy.sh:343-384`).
- **`deploy:sync-reference-data` does NOT include pipeline templates** — `SyncReferenceData.php:31-38` syncs only `CalendarEventClassSeeder` + `corex:sync-permissions --merge-defaults`. The AT-162 idempotent reference-carry mechanism doesn't carry templates.
- **No migration wiped anything.** Grep of every `deal_pipeline_*` migration `up()` found only `dropIfExists` inside `down()`. `deal_pipeline_steps` = 0 rows (0 trashed), `deals_v2` = 0 — templates simply never existed on staging.
- **Where Johan "previously saw" set-up pipelines**: a full seed (local `migrate:fresh --seed` or `demo:seed`) *does* create them. Those live on a locally-seeded build or the demo (`nexus_os_demo`), not on `hfc_staging`.
- **DB ground truth**: `deal_pipeline_templates` = one row `id=1, agency_id=1, name="test", bond, is_default=0, created_by_id=22, created 05:48:25, deleted 05:49:21` (Johan's session). `deal_pipeline_steps`/`deals_v2`/`deal_step_instances` = 0.

### C.2 The create-a-pipeline path (walked as super-admin)
- **Nav**: discoverable — `corex-sidebar.blade.php:1544-1546` "Pipeline Setup", gated `deals_v2.manage_pipeline` + `Route::has`.
- **Permissions**: super-admin passes. `manage_pipeline` defined `config/corex-permissions.php:423`, granted to admin `:692`. WS8 doctrine (`5c36f944`) **added** keys but did not rename/remove `manage_pipeline` — no lock-out.
- **Controllers**: `DealPipelineSetupController` index→create→store→edit→update→destroy→duplicate all present. `store()` (`:41-63`) creates template then **redirects to edit** ("Now add your pipeline steps").
- **Step builder works**: `pipeline-setup/edit.blade.php` empty state `:313-315`, "+ Add Step" `:98`, `addNewStep()` `:362-388`, `saveStep()` POST `:390-441`. Fully operable.
- **Why it *felt* unusable (two UX gaps, not defects):**
  1. The register empty-state (`index.blade.php:82-92`) offers **only "+ New Template"** — a blank slate. **No "Load standard templates" affordance** anywhere. With the seeder unrun, a super-admin has no in-app way to get the 3 spec templates; they must hand-build all 15/9/16 steps.
  2. Johan created "test", landed on an empty step list, faced building triggers/RAG/status-triggers per step from zero, and abandoned it (0 steps) → deleted.

### C.3 Flagged landmine (found, not asked — material)
`DealPipelineTemplateSeeder.php:15-16` runs `DealPipelineStep::query()->forceDelete()` + `DealPipelineTemplate::query()->forceDelete()` — **agency-blind hard delete, no `updateOrCreate`**. It contradicts `deploy.sh:356-357` ("all idempotent"), `DatabaseSeeder.php:38-41`, and Non-Negotiable #1. **The next `scripts/deploy.sh staging|production` run would force-delete every agency's pipeline templates + steps** (and orphan any deal step-instance references), recreating only the 3 defaults for `agency_id=1`. Must be rewritten to per-agency `updateOrCreate` **before** any deploy path runs it.

---

## D. REMEDIATION PROPOSAL (proposal only — Johan rules)

**Shape: DR2 is NOT tossed.** The foreign feel = one dormant screen (the wizard) + an unseeded pipeline + a status model DR1 never had. Re-clone the capture layer to DR1 ergonomics, provision templates, decouple status from pipeline — keep everything WS0–WS8 built.

### D.1 Keep / rework / discard this week's machinery
| WS | Verdict | Reason |
|----|---------|--------|
| WS0 engine hardening + tests | **KEEP AS-IS** | DR2 becomes canonical; needs the tests regardless. |
| WS1 `DealSyncService` (DR1↔DR2) | **KEEP** — central to closing gap A19. | It is the mechanism to feed DR1's reporting ledger (money-lines/TV/BM/commission) during transition. |
| WS2 supplier directory | **KEEP** | Serves pipeline provider roles (COC providers, attorneys). |
| WS3 document spine (`documents.deal_id`) | **KEEP** | OTP-on-deal + auto-file — core pipeline value. |
| WS4 distribution + secure-link/OTP + auto-COC | **KEEP** | The "red button" (OTP granted → auto-email appointed provider). Replaces hand-filled COC requests. |
| WS5 comms archive on Property | **KEEP** | Distributions visible on deal + contact + property. |
| WS6 notifications + escalation | **KEEP** | Pipeline clock value. |
| WS7 RAG thresholds | **KEEP** | Pipeline tile colours. |
| WS8 overview/board/CSV/iCal | **KEEP** | DR2's reporting surface. |
| Permission doctrine | **KEEP** | Role-Manager grouping. |
| **5-step wizard `create.blade.php` + `store()` step-gating** | **DISCARD / RE-CLONE** | Replace with DR1-style single-page capture. The only thing torn out. |
| **Single-agent-per-side in create wizard** | **REWORK** | Multi-select both sides (edit form already supports it). |

### D.2 Workstream plan (parity gates = Johan side-by-side QA)

**WS-R1 — Provision + de-risk templates (small, ~½ day).**
- Rewrite `DealPipelineTemplateSeeder` to per-agency `updateOrCreate`; remove the `forceDelete` landmine; add pipeline templates to `deploy:sync-reference-data` so they travel idempotently.
- Add a "Load standard templates" affordance to the register empty-state (clones the 3 defaults into the current agency; every agency can re-load).
- Seed `hfc_staging` so Johan immediately sees the 3 templates.
- **Parity gate:** Johan opens Pipeline Setup on staging → sees Standard Bond Sale (15) / Cash (9) / Sale-of-2nd (16), can add/edit steps. ✅

**WS-R2 — Capture-layer re-clone (the main build, ~2–3 days).**
- Replace the wizard with a **single-page capture form** cloning `admin/deals/form.blade.php` ergonomics: Deal Details / Status & Registration / Sides-Splits-Agents, plus **one inline "Pipeline" section** (template picker + step preview) — not a wizard step.
- **Multi-agent per side** on both listing and selling (match A5), wired to the existing `deal_v2_agents` pivot + `buildAgentsFromForm()`.
- Reduce required fields toward DR1's lazy-but-valid floor within pillar constraints (see decisions below).
- **Parity gate:** Johan captures the *same* deal in DR1 and DR2 side-by-side — identical field set, identical agent capability, identical status semantics — DR2 additionally attaching a pipeline. ✅

**WS-R3 — Reporting/status parity (~1–2 days).**
- Confirm `DealSyncService` mirrors DR2→DR1 shared fields (status, granted/registration dates, parties, commission totals) so `deal_money_lines`/TV/BM/agent-commission keep working — or repoint those readers to DR2.
- Decouple deal status from the pipeline per D.3 decision.
- **Parity gate:** a DR2 deal shows in agent performance / TV / commission exactly as a DR1 deal. ✅

### D.3 OPEN DECISIONS FOR JOHAN (these change the build; needed before WS-R2)
1. **Parties — pillar-linked vs free-text.** Spec §1 mandates contact FKs ("no freeform names"); DR1 allows free-text. Recommend **keep pillar-linked but frictionless** (inline quick-add-contact so it never feels like a gate). Or explicitly restore a free-text fallback to match DR1.
2. **Deal status — DR1 two-axis vs pipeline-driven.** Recommend **DR1 two-axis (`accepted_status` P/D/G/R + `commission_status`) stays the primary status**; the pipeline is a tracking overlay, and the BM-approval gate is **off by default** (opt-in per agency). This directly removes the "process I didn't ask for."
3. **Capture shape — single form (recommended) vs keep-wizard-but-restyle.** Recommend single form to match DR1 muscle memory.
4. **Reporting cutover — sync-mirror during transition vs repoint readers to DR2** (the AT-158 §8 permanent-mirror-vs-cutover question, still open).

### D.4 What gets DELETED
Only the wizard capture UX: the step-rail + per-step gating in `create.blade.php` and the wizard-specific `store()` flow. Everything else in DR2 (models, engine, settlement, pipeline setup, and all WS0–WS8 machinery) stays.

---

## Files referenced (all read-only)
DR1: `app/Http/Controllers/Admin/DealController.php`, `app/Http/Controllers/Agent/DealRegisterController.php`, `app/Models/Deal.php`, `DealSettlement.php`, `DealMoneyLine.php`, `DealLog.php`, `app/Models/Scopes/DealBranchScope.php`, `app/Services/DealMoneyLineRebuilder.php`, `resources/views/admin/deals/form.blade.php`.
DR2: `app/Http/Controllers/DealV2/*`, `app/Models/DealV2/*`, `app/Services/DealV2/DealPipelineService.php`, `resources/views/deals-v2/create.blade.php`, `.../form.blade.php`, `.../pipeline-setup/{index,create,edit}.blade.php`, `database/seeders/DealPipelineTemplateSeeder.php`, `app/Console/Commands/Deploy/SyncReferenceData.php`, `scripts/deploy.sh`, `config/corex-permissions.php`, `resources/views/layouts/corex-sidebar.blade.php`.
