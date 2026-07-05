# DR2 Vision Alignment Audit — Johan's Sunday-night vision vs the as-built engine

> **READ-ONLY.** No code changed anywhere. DR1 (`deals` / `App\Models\Deal`) never touched.
> Verified against `Staging` working tree (DR2 = **live-dormant**, `deals_v2` = 0 live rows, DR1 = 131). Author: Claude (CC session), 2026-07-05. Feeds **AT-158**. Governs Monday's rulings.
> Companion reference: `.ai/audits/2026-07-05-sa-conveyancing-canonical-reference.md` (canonical SA transfer process, cited). Prior: `.ai/audits/2026-07-04-dr2-capture-parity-investigation.md`.

---

## 0. Executive summary (the headline for Monday)

The single most important finding: **the deal-pipeline engine is NOT the absolute-offset model Johan suspected. It already implements the event-driven, relative-deadline model the vision describes — the core concept (vision point 3) is built and working.** Downstream steps compute their due date from the *actual completion date of their predecessor* (`DealPipelineService::activateDownstreamSteps()` → `activateStep($dependent, $completedStep->completed_at)`, `DealPipelineService.php:363-376,171-185`), not from a fixed anchor. Blocked (not-yet-triggered) steps carry no due date, stay grey, are excluded from the RAG sweep, the escalation ladder, and the daily digest — so nothing "falsely counts down" while it waits. That is exactly vision point 3.

What is genuinely missing is narrower and concentrated in three places:

1. **AND-gating across suspensive conditions (vision point 4)** — a step depends on exactly ONE predecessor (`trigger_step_instance_id` is a single FK). Fan-**out** (one step activates many) works; fan-**in** ("granted only when bond AND deposit both complete", "lodgement only when every certificate + clearance + guarantee is in") does **not** exist. Stage movement is driven by a single step's `status_trigger`, not a quorum of suspensive steps.
2. **Cash-flow purpose (vision point 5)** — there is **no forward cash-flow surface**. The data exists (`expected_registration` per deal + commission columns) but nothing projects expected commission by expected registration month. This is a net-new workstream.
3. **Feedback + role truth (vision point 1)** — capture was correctly re-cloned to a single-page form (WS-R2), but (a) the default permission grant still gives **agents** `deals_v2.create` (contradicts "the BM or admin captures"), and (b) there is **no deal-level feedback/comment thread** — agents "feed back" only by ticking steps; there is no equivalent of DR1's `addRemark`. Vision point 1 says "agents supply feedback on the deal as it progresses to sale and beyond" — the free-form channel for that does not exist.

Everything else — relative deadlines, RAG amber/red advisories, the escalation ladder that "advises the parties," the per-step extension mechanism, the templates' coastal certificate coverage — is built. The template **content**, however, is missing five real conveyancing steps (guarantees, bond-cancellation figures, transfer duty/SARS, document signing, levy/HOA consent) and has one materially wrong order (deposit sequenced after bond grant).

**Bottom line: DR2 is far closer to the vision than the "broken register I didn't ask for" reaction implied. The vision's *core mechanism* is already correct. Monday's work is (1) add fan-in AND-gating + a suspensive-condition concept, (2) design the cash-flow surface, (3) settle the capture-permission + feedback-thread gaps, and (4) correct the seeded template content. No teardown.**

---

## 1. ALIGNMENT SCORECARD

| # | Vision point | As-built truth (evidence) | Verdict |
|---|---|---|---|
| **1** | BM/admin **captures**; agents **supply feedback** as it progresses | Single-page capture re-cloned (WS-R2). BUT default role grant gives **agents** `deals_v2.create` (`config/corex-permissions.php:771`); vision wants BM/admin. **No deal-level feedback thread** — grep for comment/remark on DealV2 = empty; agents feed back only via step ticks + completion notes → `deal_activity_log` | **PARTIAL** |
| **2** | Pipeline = real SA conveyancing run like PM SaaS; each step has a deadline; **advises** parties; amber getting-critical, red act-now | RAG two-threshold engine (`calculateRag`, `DealPipelineService.php:412-434`); `deals:process-rag` every 15 min persists RAG + repaints calendar (`ProcessDealRag.php`); escalation ladder agent→BM→admin (`ProcessDealEscalations.php`, `NotificationService`); daily digest; per-step **extension** via `overrideDueDate` (`DealStepController.php:204-234`, reason + audit) | **ALIGNED** |
| **3** | **Event-driven relative deadlines** — the 14-day clock starts only when the predecessor is actually marked done; blocked work cannot count down early | `activateDownstreamSteps()` activates each dependent from `$completedStep->completed_at` (`DealPipelineService.php:363-376`); `activateStep` computes `due = fromDate + days_offset` (`:171-185`). Not-yet-triggered steps = `not_started`, no due_date, grey RAG (`DealStepInstance::calculateRag`, `:128-157`), excluded from RAG sweep/escalation/digest. Tracker even shows "Activates after '{predecessor}' + N days" (`show.blade.php:504-509`) | **ALIGNED** |
| **4** | DR1 stages (pending→granted→registered / →declined); step completions drive stage movement; **AND-gating** across suspensive conditions | Step→status linkage EXISTS: `status_trigger` fires `changeDealStatus` (`DealPipelineService.php:258-262,396-407`); DR1↔DR2 status map round-trips (`DealSyncService::v1StateToV2Status/v2StatusToV1`). BUT **no AND-gate** — single `trigger_step_instance_id` FK (no fan-in); one step alone flips `granted`; no "suspensive condition" concept. Declined path exists (`negative_status_trigger`→`cancelled` + `cancelDownstreamSteps`) but conflates declined with cancelled | **PARTIAL** |
| **5** | Purpose: the register **IS the agency's cash flow** — what they budget on | Overview has 6 KPI cards incl. "Total Pipeline Value" = `sum(purchase_price)` (`DealV2Controller.php:87-93`). `expected_registration` computed per deal (`recalculateExpectedRegistration`, `:474-498`). **No month-by-month expected-commission projection / cash-flow surface anywhere** | **MISSING** |

---

## 2. DETAILED AUDIT (A–G) — with file:line evidence

### A. DEADLINE MODEL — **RELATIVE, event-driven (not absolute). ALIGNED.**

**Finding: the suspicion that the engine uses absolute offsets from a deal anchor is incorrect for the operational due dates.**

- On completion, `activateDownstreamSteps($completedStep)` selects every step whose `trigger_step_instance_id == completedStep.id` and status is `not_started`, then activates each with `$fromDate = $completedStep->completed_at ?? now()` (`DealPipelineService.php:363-376`).
- `activateStep($step, $fromDate)` sets `due_date = $step->due_date ?? Carbon::parse($fromDate)->addDays($step->days_offset)` (`:171-185`). So a step's clock starts on its predecessor's **actual completion**, exactly as the vision describes ("the 14-day COC clock starts ONLY when the bond is actually marked granted").
- `on_creation` steps anchor to the deal's `offer_date` at creation (`:151-156`). This is the only deal-date anchor, and it applies only to the first step(s) — correct.
- The one place offsets are summed absolutely is `recalculateExpectedRegistration()` → `calculateChainDays()` (`:474-510`): it walks the trigger chain back from the registration milestone and sums `days_offset` from `offer_date` to **project** an expected registration date. This is a *display projection*, not the authoritative per-step due date, and is re-derived from the real registration step's `due_date` once that step activates (`:489-491`). Acceptable; note it is a naïve single-chain sum (ignores parallel branch lengths — see design note A-2).

**Extension mechanism — PRESENT (`DealStepController::overrideDueDate`, `:204-234`).** Requires `due_date` + a `reason`, gated on `deals_v2.override_dates`, recomputes RAG immediately, recalculates expected registration, and writes an audit row (`action='date_overridden'`, who = `auth()->id()`, old→new date, reason) to `deal_activity_log`. This satisfies Johan's "an extension is signed to give more time … RAG recalculates" — functionally. **Gaps to design (A-1):** it is framed as an admin "override," not a first-class **signed extension** (no attach-the-signed-addendum-document, no reason *taxonomy*, no counterparty/whose-extension capture beyond the actor, no cascade preview of downstream impact).

> **DESIGN — relative model is already correct; the deltas to add:**
> - **A-1 (Extension as a first-class object):** promote `overrideDueDate` into an **Extension** action: reason category (bond-extension, COC-delay, attorney-delay, buyer-request, other), optional signed-addendum `Document` link (rides the WS3 doc spine), "extended by N days" delta shown on the timeline, and a preview of which downstream steps shift. RAG recalculates (already does). Keep the raw override for admins as the escape valve.
> - **A-2 (Projection accuracy):** make `calculateChainDays` take the **longest** parallel branch to the registration milestone, not a single chain, so "expected registration" reflects the true critical path once fan-in (B/C) lands.
> - **A-3 (Recompute semantics on completion/extension):** already sound — downstream due dates are only *materialised* at activation from the predecessor's real completion, so an early/late completion automatically re-bases the chain. Already-sent notifications are not retracted (you cannot un-send an email); the next RAG sweep re-evaluates from the new due date. No change needed beyond documenting it.

### B. DEPENDENCY GATING — **sequential + parallel fan-out work; AND-join (fan-in) MISSING.**

- **Can a step count down / notify before its predecessor completes today? No.** A not-yet-triggered step is `not_started` with `due_date = null`; `calculateRag` returns grey for `not_started` and for null due date (`DealStepInstance.php:134-141`); `ProcessDealRag` only sweeps `['active','overdue']` (`ProcessDealRag.php:35-38`); `ProcessDealEscalations` only sweeps `overdue` (`:30`); `DealDailyDigest` explicitly filters `in_array($step->status, ['active','overdue'])` (`DealDailyDigest.php:50`). **Blocked steps are structurally inert.** This is vision point 3 satisfied.
- **Blocked-state display EXISTS:** the tracker renders "Activates after '{predecessor}' + {days_offset} days" for `after_step` not-started steps and "Waiting for trigger" for manual ones (`show.blade.php:502-509`). So a step does *not* falsely appear to count down — it shows its dependency. Better than the vision feared.
- **Parallel fan-out EXISTS:** because `activateDownstreamSteps` activates *all* dependents of a completed step, one predecessor (e.g. "Bond Approved") lights up Deposit + Attorney + all five COCs simultaneously (see the seeded template, §E). Concurrent SA reality (certificates + clearances in parallel) is representable.
- **AND-join (fan-in) MISSING — the real gap.** `trigger_step_instance_id` is a **single** FK (`DealStepInstance.php:82-90`). A step cannot declare "activate me only when steps X AND Y AND Z are all complete." In the seeded bond template this bites: **Deeds Office Lodgement** triggers off **Rates Clearance alone** (`Provisioner definitions()` step 14), so lodgement's clock starts — and can go red / be marked done — while COCs, FICA, guarantees and SARS are still outstanding. In reality lodgement is the hard convergence gate that requires *every* parallel leg complete.

> **DESIGN — B-1 (join/AND-gate):** add an optional **`deal_step_dependencies`** join table (`step_instance_id`, `depends_on_step_instance_id`) *alongside* the existing single-FK fast path (keep it for the common linear case; migrate the seeded chains lazily). A step with multiple dependencies activates only when **all** are `completed`. Template-configurable in the step builder ("this step waits on: ☑ Rates ☑ All COCs ☑ Guarantees"). Blocked display becomes "Waiting on: Rates Clearance, Electrical COC (2 of 4 done)".
> **DESIGN — B-2 (parallel groups):** expose an optional `parallel_group` label on template steps so the tracker can visually cluster the concurrent "preparation cluster" (FICA / figures / guarantees / certificates / clearances) instead of a flat list — cosmetic but matches how conveyancers think.
> Sized as **WS-V1 (Dependency & AND-gate engine)** — see §3.

### C. STAGE LINKAGE + AND-GATES — **linkage EXISTS; suspensive-condition AND-gate MISSING.**

- **Wiring from step completion → stage movement EXISTS** (contrary to "does any wiring exist"): a positive completion with a `status_trigger` calls `changeDealStatus($deal, $statusTrigger, …)` which writes `deals_v2.status` and stamps `actual_registration` when it hits `completed` (`DealPipelineService.php:258-262,396-407`). Seeded: "Bond Approved" → `granted`; "Registration" → `completed` (`Provisioner` steps 3 & 15). The DR1↔DR2 axis map round-trips (`DealSyncService.php:158-180`: DR1 `G`↔`granted`, `R`↔`completed`, `D`↔`cancelled`).
- **But it is single-step-drives-status, NOT a quorum.** Whichever step carries `status_trigger='granted'` flips the deal on its own completion. There is **no "suspensive condition" flag** and **no AND-gate**: the vision's "if the suspensive conditions are bond AND deposit, ONLY when both are done does the deal become granted" is not modelled. In the cash template a *single* "Deposit Paid" flips `granted`; in the bond template a *single* "Bond Approved" does. A bond-*and*-deposit deal cannot express "granted needs both."
- **Auto vs prompt:** today it is **auto** (fires inside `completeStep` on commit), unless the agency has opted into the BM-approval gate (`deal_v2_bm_approval_enabled`, default **off** per WS-R3 Ruling 2, `:191-194,210`), in which case the status is *held* pending BM approval. There is no lightweight "one-click prompt to move" middle option.
- **Declined path EXISTS** but is coarse: a negative outcome with `negative_status_trigger` sets the deal to `cancelled` and `cancelDownstreamSteps()` marks all remaining `not_started`/`active` steps `skipped` with an audit line (`:250-255,381-391`). DR1 distinguishes **Declined (`D`)** from other terminal states; DR2 collapses declined into `cancelled` (`DealSyncService.php:163-164` maps `D`→`cancelled`).

> **DESIGN — C-1 (suspensive-condition AND-gate):** add a boolean **`is_suspensive`** on template steps (and instances). Introduce a small resolver: when a suspensive step completes, check whether **all** `is_suspensive` steps for the deal are complete; only then move the deal to `granted` (deal becomes unconditional). This replaces the single-step `status_trigger='granted'` shortcut for multi-condition deals while leaving single-condition deals behaving as today.
> **DESIGN — C-2 (auto vs prompt — Johan's ruling):** offer three agency-configurable modes for the stage move: **(a) auto** (today's behaviour, off-BM-gate), **(b) one-click prompt** ("All suspensive conditions met — mark this deal Granted?" surfaced on the deal + as a notification), **(c) BM-approval hold** (today's opt-in gate). Recommend **(b) prompt as the default** — it keeps DR1's "status is a human decision" muscle memory (the capture-parity ruling that DR1 status stays primary) while surfacing the pipeline's readiness. **Johan decides.**
> **DESIGN — C-3 (declined ≠ cancelled):** add a distinct `declined` DR2 status (or a terminal-reason on `cancelled`) so DR1's `D` round-trips losslessly and the loss-ledger (G) can separate "deal fell through / declined" from "cancelled/withdrawn."
> Sized as **WS-V2 (Suspensive conditions + stage-move policy)** — see §3.

### D. ROLES TRUTH — **two real gaps against vision point 1.**

- **Who can capture (as-built) vs "BM or admin captures":** `deals_v2.create` is granted by default to the **agent** role block (`config/corex-permissions.php:771` — the same block that carries `view_own_stats`, `apply_for_leave`, agent tooling), as well as admin (`:691`). DR1 by contrast makes the **agent view read-only** — `Agent\DealRegisterController` is index/log/addRemark only; only `Admin\DealController` captures. So **DR2's default is more permissive than both DR1 and the vision.** Per CoreX doctrine this should be **agency-configurable** (Role Manager), but the shipped **default** contradicts the vision. → **Monday decision D-1.**
  - *Note:* the live promotion (AT-158 comment 2026-07-04) already stripped `deals_v2.view_overview` / `manage_pipeline` to admin/BM only on live; but `deals_v2.create` in the **default seed** still reaches agents. Confirm the live grant and decide the default.
- **Agents CAN feed back on own deals (partial):** step completion is gated `deals_v2.edit` **and** own-scope (`DealStepController::complete`, `:20-34` via `DealV2::visibleTo`), so an agent can tick/complete-with-reason/upload on their own deals; complete-with-reason writes a stamped reason (`:60-83`); everything lands in `deal_activity_log`. That is real feedback on *step progress*.
- **But there is NO free-form deal-level feedback/comment thread.** Grep for comment/remark/feedback across `app/Http/Controllers/DealV2/` and `app/Models/DealV2/` = **empty**. DR1 has `addRemark` → `deals.remarks` + `deal_logs`; DR2 has only the system-generated activity log. Vision point 1 — "agents supply feedback on the deal as it progresses to sale and **beyond**" — has no home. → **GAP D-2.**

> **DESIGN — D-1 (capture permission):** keep capture **agency-configurable** (doctrine), but set the **default** to BM + admin only (drop `deals_v2.create` from the default agent grant; agencies that want agent-capture toggle it in Role Manager). Recommend, Johan rules.
> **DESIGN — D-2 (deal feedback thread):** add a `deal_v2_comments` thread (author, body, optional step link, SoftDeletes, own/branch/all visibility) surfaced on the deal detail and rolled into the activity timeline — the DR1 `addRemark` analogue, so "agents supply feedback" has a first-class channel. Small, self-contained.
> Sized as **WS-V3 (Roles + feedback thread)** — see §3.

### E. TEMPLATE ACCURACY vs SA CONVEYANCING REALITY

Compared the three seeded templates (`DealPipelineTemplateProvisioner::definitions()`, `:192-251`) against the canonical process in `.ai/audits/2026-07-05-sa-conveyancing-canonical-reference.md` (STBB / Snymans / ooba / Barter McKellar / Ngoetjana / Cape Coastal Homes). **The templates are good on coastal certificate coverage but miss five real steps and mis-order the deposit.**

**Standard Bond Sale (15 steps) — per-step verdict:**

| Seeded step (offset) | Canonical | Verdict |
|---|---|---|
| 1 OTP Signed (d0, on_creation) | OTP | ✓ |
| 2 Bond Application (+3 ← OTP) | bond application | ✓ |
| 3 Bond Approved (+30 ← application → **granted**, BM-appr) | bond grant ~21–30 from OTP | ✓ order; ~33d from OTP is a touch high — tighten to ~25 |
| 4 Deposit Paid (+7 ← **Bond Approved**) | deposit ~1–3 days after **OTP** | ✗ **WRONG ORDER** — deposit is paid per OTP terms early, not after bond grant |
| 5 Attorney Instructed (+5 ← Bond Approved) | attorneys instructed (3 roles) | ⚠ collapses transfer/bond/cancellation attorney into one step |
| 6–7 FICA Buyer/Seller (+14 ← Attorney) | FICA to attorneys | ✓ |
| 8–12 Electrical / Gas / Electric-Fence / Beetle / Water COC (+30 ← Bond Approved) | compliance certificates | ✓ **good coastal coverage incl. Beetle**; but **Water Installation** is Cape-Town-only — should be **off by default** for KZN HFC |
| 13 Rates Clearance (+42 ← Attorney) | rates clearance | ⚠ no separate **levy clearance / HOA consent** step |
| 14 Deeds Office Lodgement (+7 ← **Rates only**) | lodgement — hard AND-gate on all legs | ✗ **depends on Rates alone** (see B-1); should require all COCs+FICA+guarantees+SARS |
| 15 Registration (+15 ← Lodgement → **completed**) | registration 7–10 working days | ✓ (~15 cal ≈ 10 working days) |

**MISSING steps (all three templates):** bond **cancellation figures** (from seller's existing bank), **guarantees issued** (the money-leg gate), **transfer duty / SARS receipt** (a lodgement prerequisite), **documents signed** by parties at the attorney (Power of Attorney), **levy clearance + HOA/estate consent** (parallel to rates, a lodgement prerequisite for ST/estate stock — common on the KZN coast). A **finances/payout** step is also absent (registration → completed with no payout milestone; commission is handled by the settlement engine separately, so this is optional).

**Cash Sale (9 steps):** deposit correctly ordered (+7 ← OTP); "Deposit Paid" flips `granted` — reasonable for cash. But only **Electrical COC** is seeded — a coastal cash sale still needs **Beetle** (and gas/fence where applicable). Missing SARS/transfer-duty, document-signing, levy/HOA — same as bond. → add Beetle to cash; add the missing spine steps.

**Sale of Second Property (16 steps):** adds "Linked Property Sold" (`auto_from_linked_deal`, manual trigger) gating the bond application — a genuinely nice touch. Inherits all the bond template's gaps (deposit order, missing guarantees/SARS/signing/levy, lodgement AND-gate).

**Recommended relative default offsets (all agency-configurable — doctrine):**

| Step | Trigger from | Default offset |
|---|---|---|
| OTP Signed | deal (on_creation) | 0 |
| Deposit Paid | **OTP** (move earlier) | +3 |
| Bond Application | OTP | +3 |
| Bond Approved (suspensive) | Bond Application | +21 (≈24–27d from OTP) |
| Attorneys Instructed | Bond Approved (or OTP for cash) | +3 |
| Bond Cancellation Figures *(new)* | Attorney Instructed | +5 |
| FICA (Buyer/Seller) | Attorney Instructed | +7 |
| Compliance certificates (Electrical/Beetle default; Gas/Fence/Water conditional) | Attorney Instructed | +14 |
| Guarantees Issued *(new)* | Bond Approved | +10 |
| Rates Clearance | Attorney Instructed | +21 |
| Levy / HOA Consent *(new, conditional)* | Attorney Instructed | +21 |
| Transfer Duty / SARS Receipt *(new)* | Documents Signed | +7 |
| Documents Signed *(new)* | guarantees + FICA in hand (**AND-gate**) | +3 |
| Deeds Office Lodgement | **all certs + clearances + guarantees + SARS (AND-gate)** | +5 (gate, not a countdown) |
| Registration | Lodgement | +10 (≈7–10 working days) |

> **DESIGN — E-1:** correct the seeded templates: move Deposit to trigger from OTP; add the five missing steps (cancellation figures, guarantees, SARS/transfer-duty, documents-signed, levy/HOA consent); mark Bond Approved (+ Deposit where applicable) `is_suspensive`; make Water-Installation COC off-by-default for HFC; add Beetle to the cash template; wire Lodgement as an AND-gate on the preparation cluster (needs B-1). **Because the provisioner is additive and never rewrites an existing template's steps (`Provisioner:130-134`), this is a *new template version*, not an in-place edit — see the migration decision in §4.**

### F. NOTIFICATIONS FIT — **ALIGNED. No notification fires for a blocked step.**

- `deals:process-rag` sweeps only `['active','overdue']` and skips deals not `active` (`ProcessDealRag.php:35-38,56`); it fires `notifyStepRagTransition` **only on an actual RAG change** and the `NotificationService` additionally guards per `(step, target-RAG)` (`:64-77`). A `not_started` step is never swept → never notified. ✓ (no defect against vision point 3).
- `deals:process-escalations` sweeps only `overdue` steps on `active` deals (`ProcessDealEscalations.php:30,49-54`); each rung recorded once in `deal_step_escalations` (idempotent). A blocked step cannot be overdue (no due date) → never escalates. ✓
- `DealDailyDigest` buckets only `active`/`overdue` steps (`:39,50`). Blocked steps excluded. ✓
- Because due dates are re-based at activation from the predecessor's real completion (A), notifications automatically track the recomputed dates — a step that activates late simply starts its amber/red windows late. The only "un-retractable" case is an email already sent before an extension; the next sweep re-evaluates. Acceptable; document it.

**Verdict: the notification runtime already respects the relative/blocked model. No changes required for F beyond what B/C introduce (an AND-gated step stays `not_started` until its quorum completes, so it inherits the same inert-until-triggered protection).**

### G. CASH FLOW — THE PURPOSE — **MISSING. Net-new workstream.**

- **What exists:** the WS8 overview has six KPI cards — Active Deals, Overdue Steps, Due This Week, Pending Registration, **Total Pipeline Value** (`sum(purchase_price)`), Avg Days to Registration (`DealV2Controller.php:87-93`) — plus a milestone board and CSV. `expected_registration` is computed per deal (`:474-498`) and shown in the table/detail (`index.blade.php:137`, `show.blade.php:93`). Commission math is cloned from DR1 (`commission_amount`, `commission_vat`, per-agent splits).
- **What's missing:** nothing turns this into the **forward cash-flow view** the vision names as the *purpose* — "this register IS the agency's cash flow, the thing they budget on." There is no month-by-month expected-commission projection, no confidence weighting by stage, no slippage view, no loss ledger.

> **DESIGN — G-1 (Cash Flow projection surface):** a new **Cash Flow** view under the DR2 group (nav + `deals_v2.view_cashflow` permission, own/branch/all scope):
> - **Month-by-month expected commission**: for each in-flight deal, place its **agency-share ex-VAT commission** (reuse the settlement/commission engine — not a re-derivation) in the bucket of its `expected_registration` month.
> - **Confidence weighting by stage**: weight each deal's contribution by pipeline state — e.g. `active/pending` ×0.6, `granted` (suspensive conditions met, unconditional) ×0.9, `lodged` ×0.98 — factors agency-configurable (doctrine). Show both gross-expected and confidence-weighted.
> - **Slippage visibility**: flag deals whose critical-path steps are red/overdue (their month is at risk) and show the month they'd slip to.
> - **Loss ledger**: declined/cancelled deals (needs C-3's distinct `declined`) roll into a "commission lost" tally — the vision's "unmanaged, it becomes losses."
> - **Caveat to note on the surface (WS1 mirror):** DR2 holds 0 live deals today; while DR1 is canonical, the cash-flow view must read DR2 **and** the DR1 mirror (or be explicit it covers DR2-captured deals only) until the §13 cutover. State this on the page, don't silently under-count.
> Sized as **WS-V4 (Cash Flow projection)** — see §3.

---

## 3. DESIGN PROPOSALS AS WORKSTREAMS (DR2 gate pattern)

Each closes with the CLAUDE.md done-checklist + a Johan verification gate. Sequenced so the engine gates (V1/V2) land before the surface that depends on them (V4).

| WS | Scope | Depends on | Verification gate |
|---|---|---|---|
| **WS-V1** — Dependency & AND-gate engine | `deal_step_dependencies` join table (multi-predecessor); step activates only when ALL deps complete; keep single-FK fast path; step-builder UI for "waits on…"; blocked display "Waiting on X, Y (n of m)"; optional `parallel_group` label (B-1, B-2, A-2 critical-path fix) | — | A step with two incomplete predecessors stays `not_started`/grey; completes only when both done; lodgement won't count down until every cert+clearance+guarantee+SARS is in; frozen-clock test |
| **WS-V2** — Suspensive conditions + stage-move policy | `is_suspensive` flag on template steps + instances; resolver moves deal to `granted` only when ALL suspensive steps complete (C-1); agency stage-move mode auto / **prompt (default)** / BM-hold (C-2); distinct `declined` status + lossless DR1 `D` round-trip (C-3) | WS-V1 | Bond-AND-deposit deal reaches `granted` only when both suspensive steps done; prompt appears; declined deal round-trips to DR1 `D`; sync parity 0 mismatches |
| **WS-V3** — Roles + feedback thread | Default capture = BM/admin (drop agent `deals_v2.create` from default; keep Role-Manager-configurable) (D-1); `deal_v2_comments` feedback thread on deal detail + timeline, scoped, SoftDeletes (D-2) | — | Agent without grant can't reach create (403, friendly); BM/admin can; agent can post feedback on own deal, sees it in timeline; deleted-comment renders |
| **WS-V4** — Cash Flow projection surface | New Cash Flow view: month × expected agency-share commission from in-flight deals; confidence weighting by stage (configurable); slippage flags; loss ledger; DR1-mirror caveat noted (G-1); Extension-as-first-class-object (A-1) can ride here or WS-V2 | WS-V1, WS-V2 (stage confidence + declined) | Each month bucket = direct query on a seeded SA deal set; a red critical-path step moves its deal to "at risk"; a declined deal appears in the loss ledger; scope switcher correct |
| **WS-V5** — Template content correction | Correct 3 templates as a **new template version** (deposit-after-OTP; add cancellation-figures, guarantees, SARS/transfer-duty, documents-signed, levy/HOA; suspensive flags; Water COC off-by-default; Beetle on cash; Lodgement AND-gate) (E-1); provisioner emits the new version without touching customised templates | WS-V1 (AND-gate), WS-V2 (suspensive) | Fresh agency provisions the corrected templates; an agency with customised templates is untouched; a captured bond deal walks OTP→…→registration in correct order with lodgement gated on all legs |

All five are additive to the existing DR2 engine — **no teardown, DR1 untouched.** Estimated: V1 ~1.5d, V2 ~1.5d, V3 ~1d, V4 ~2–3d, V5 ~1d (mostly config + a versioned provisioner).

---

## 4. ONE-PAGE DIGEST FOR JOHAN'S MONDAY RULINGS

**The good news first:** the thing you most suspected was wrong — **absolute deadlines** — is actually **already the event-driven relative model you described.** Downstream clocks start on the predecessor's real completion; blocked steps don't count down, don't notify, don't escalate. The RAG amber/red advisories, the agent→BM→admin escalation ladder, the per-step extension-with-reason, and the coastal certificate coverage are all built. DR2 is much closer to the vision than the Sunday-night reaction implied. **No teardown is warranted.**

**What's genuinely missing (and now designed above):**
1. **AND-gating / fan-in** — a step can only depend on one predecessor, so "granted needs bond AND deposit" and "lodgement needs every leg" can't be expressed (WS-V1 + WS-V2).
2. **Cash-flow surface** — the vision's stated *purpose*; the data exists, the view doesn't (WS-V4).
3. **Feedback thread + capture default** — no free-form agent feedback channel; agents get `deals_v2.create` by default (WS-V3).
4. **Template content** — five real conveyancing steps missing; deposit mis-ordered (WS-V5).

**DECISIONS NEEDED MONDAY:**

| # | Decision | Recommendation |
|---|---|---|
| **R1** | **Stage move on suspensive completion — auto vs prompt vs BM-hold?** | **Prompt (one-click) as default** — keeps DR1's "status is a human call" (capture-parity ruling) while surfacing readiness; auto & BM-hold remain agency options |
| **R2** | **Capture permission — who captures by default?** | **BM + admin by default**, agent-capture a Role-Manager toggle (matches the vision + DR1; DR2's current agent default is an over-grant) |
| **R3** | **Template corrections — go?** | **Yes**, ship as a **new template version** via the additive provisioner (never rewrite an agency's customised template) — deposit-after-OTP, add guarantees / cancellation-figures / SARS / signing / levy-HOA, Beetle on cash, Water-COC off-by-default, Lodgement AND-gated |
| **R4** | **Relative-deadline migration for already-seeded templates** | Only `agency_id=1` on staging has (Johan's deleted "test") — **no production DR2 templates exist yet** (deals_v2 = 0). So there is **nothing to migrate**: ship the corrected defaults as the first real version; no data backfill needed. Confirm no agency has hand-built templates before flipping the provisioner |
| **R5** | **Cash-flow workstream — go / no-go?** | **Go** — it is the vision's stated purpose; build after V1/V2 so stage-confidence + declined-loss are available. Note the DR1-mirror caveat on the surface until §13 cutover |
| **R6** | **AND-gate engine (WS-V1) — go?** | **Go** — it is the prerequisite for R1/R3/R5 and the one true structural gap; additive join table, keeps the linear fast path |
| **R7** | **Declined ≠ Cancelled** | **Add a distinct `declined` status** so DR1 `D` round-trips losslessly and the loss ledger separates fell-through from withdrawn |

**Unchanged constraints:** DR1 stays canonical and untouched; DR2 stays live-dormant until your side-by-side QA; every new element agency-configurable with a sensible default; no hard deletes; nav + permission gate per new surface.

---

## Files referenced (all read-only)
Engine: `app/Services/DealV2/DealPipelineService.php`, `.../NotificationService.php`, `.../DealSyncService.php`, `.../DealPipelineTemplateProvisioner.php`. Models: `app/Models/DealV2/DealStepInstance.php`, `DealPipelineStep.php`. Runtime: `app/Console/Commands/DealV2/{ProcessDealRag,ProcessDealEscalations,DealDailyDigest,DealParityCheck}.php`, `routes/console.php:193-205`. Controllers/views: `app/Http/Controllers/DealV2/{DealStepController,DealV2Controller}.php`, `resources/views/deals-v2/{show,index}.blade.php`. Config: `config/corex-permissions.php:418-430,691-694,771`. Reference: `.ai/audits/2026-07-05-sa-conveyancing-canonical-reference.md`. Prior: `.ai/audits/2026-07-04-dr2-capture-parity-investigation.md`, `.ai/specs/deal-register-v2-spec.md`.
