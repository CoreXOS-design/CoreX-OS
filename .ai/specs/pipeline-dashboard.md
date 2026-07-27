# Pipeline Dashboard — spec

> **Status:** Phase 1 (foundation) + Phase 2 (timeline dashboard) BUILT on QA1. Phases 3–4 specced, not built.
> **Scope guard:** QA1 only throughout. Approved by Johan 2026-07-27 (parked feature, un-parked).
> Two views over ONE shared foundation; the agent picks a default and can switch.

## 1. What this is

A deal's DR2 pipeline gets two interchangeable presentations over one shared data foundation:

- **View 1 — TIMELINE DASHBOARD** (primary new view): a horizontal, left→right time axis (Gantt/
  calendar style). Tabs top, timeline middle, activity lane bottom. Each pipeline STEP is a
  kanban-style tile carrying the FULL action set (Complete · Edit dates · Sequence · Comment ·
  Remove) but **stretched horizontally to the width of its duration** (start→end). Concurrent steps
  that overlap in time **auto-stack** into rows (never visually overlap). Sequence flows left→right;
  milestones are gate markers; phases are labelled bands. Drag a tile horizontally to reschedule its
  start (snap to day); downstream dependents CASCADE with confirmation; concurrent siblings stay. A
  'today' line. An ACTIVITY LANE: a unified event stream positioned by DATE (comments now; email +
  WhatsApp later), each event scoped to a step OR the whole deal.
- **View 2 — LIST**: the same steps as a vertical list with grab-to-reorder, sequence-click + inline
  edit, and the SAME full action set.

Both render the SAME data. Existing surface (`dr2/pipeline.blade.php`, the vertical one-liner board)
stays; the two new views are additional view-modes over the same foundation.

## 2. The four locked decisions (Johan, 2026-07-27)

1. **Event model = read-model NORMALIZER** (not a physical `pipeline_events` table). Lower risk;
   satisfies "plug email/WhatsApp in later" via a source interface. A materialised table can come
   later WITHOUT changing the DTO contract.
2. **Add `planned_start_date`; `due_date` stays as the planned END.** Duration is DERIVED
   (`due_date − planned_start_date`). A drag/reschedule slides the WHOLE tile (moves start AND end,
   preserving duration). Duration is editable only via Edit-dates (set start + end explicitly). There
   is **no** separate independent deadline — `due_date` IS the planned end. The schema represents
   start+end explicitly so **edge-resize** (drag a tile edge to change duration) can be added later —
   that interaction is **deferred, not Phase 1**.
3. **Phase bands are DERIVED between milestone gates** — no new column. The band between
   "Deal Signed" and "Bond Granted" is the "Bond" phase, etc.
4. **List reorder = display `position` only.** Reordering the list must NOT rewire dependencies or
   dates — the dependency graph remains the single scheduling truth.

## 3. The shared foundation (this is Phase 1)

### 3.1 Step time-span — `planned_start_date` + `due_date` (= planned end)

Prior to this feature a step carried only `due_date` (a deadline) + `days_offset` (int gap from its
trigger) — **no start, no duration**. A timeline bar needs a real `[start → end]` per step, and it must
be persisted so it can be dragged.

- New column **`deal_step_instances.planned_start_date`** (date, nullable).
- New column **`deal_step_instances.planned_start_manual`** (bool, default false) — mirrors the
  existing `due_date_manual`; true once an agent sets/drag-moves the start, so re-projection never
  clobbers it. (Unused until Phase 2/3; added now so the foundation is complete.)
- **`due_date` is the planned END** (unchanged meaning; still drives RAG + notifications).
- **Duration = `due_date − planned_start_date`** (derived, never stored). Milestone / zero-offset step
  ⇒ `planned_start_date == due_date` ⇒ zero-width ⇒ rendered as a diamond, not a bar.

**The projection rule (deliberately simple + always consistent): `planned_start_date = due_date −
days_offset`.** Applied uniformly at:
- **createPipeline projection** — right after the existing primary-chain `due_date` projection.
- **activateStep** — when a step truly activates, `due_date` re-anchors to `baseDate + days_offset`
  where `baseDate` is the REAL anchor (`dependencyReadiness` passes the LATEST predecessor completion,
  i.e. fan-in-accurate). So `planned_start_date = baseDate` at activation — **fan-in-accurate exactly
  when it matters** (once predecessors have actually completed). Skipped when `planned_start_manual`.
- **Backfill command** for existing deals — `planned_start_date = due_date − days_offset` where
  `due_date` is set (`days_offset ≥ 0`, so start never lands after end; never negative-width).

**Why this rule (design note):** `planned_start = due_date − days_offset` is universally consistent,
always positive, milestone-safe, and — because `due_date` is projected primary-chain — a step's bar
butts up against its primary predecessor's end (a correct left→right cascade). The only imprecision is
that a *not-yet-activated* fan-in step's start ignores its secondary dependencies (it tracks the
primary chain); that resolves automatically to the true gate the moment the step activates
(`baseDate = max(predecessor.completed_at)`), and the Phase-2 drag-cascade re-anchors on
`max(predecessor.planned_end)` explicitly. We deliberately do NOT rewrite the `due_date` projection to
be fan-in-topological in Phase 1 (avoids regressing RAG/notifications on existing deals). Deferred as a
possible refinement.

### 3.2 Dependencies & concurrency (reused, no schema change)

- Dependencies already exist twice: `trigger_step_instance_id` (single primary predecessor) +
  `deal_step_instance_dependencies` (multi-parent AND-gate). The timeline/cascade read BOTH.
- **Concurrency is EMERGENT** — there is no concurrency column and none is added. Overlapping
  `[planned_start → due_date]` intervals are detected at render (greedy interval row-packing) and
  auto-stacked. Real demo data peaks at ~7 (deal 180) / ~10 (deal 168) simultaneously-open spans.
- **"Risk" = RAG** — `Dr1PipelineService::calculateRag()` + `ragColour()` already exist; reused
  verbatim for bar colour. No new column.

### 3.3 Unified EVENT model — read-model normalizer

A normalized event stream that surfaces comments now and plugs in email/WhatsApp later, WITHOUT a
physical unified table or dual-writes.

- **DTO `App\Support\Pipeline\PipelineEvent`** — immutable: `type` (comment|email|whatsapp|…),
  `occurredAt` (Carbon), `scope` (deal|step), `stepId` (?int), `direction` (?inbound|outbound),
  `authorId` (?int), `authorName` (?string), `body` (string), `sourceType`/`sourceId` (provenance).
- **Interface `App\Support\Pipeline\PipelineEventSource`** — `eventsForDeal(Deal $deal): Collection`
  (of `PipelineEvent`).
- **`App\Services\Deal\Pipeline\CommentEventSource`** (live today) — reads `deal_step_comments` for the
  deal's steps → `type=comment`, `scope=step`, `stepId`, `direction=null`, author=user,
  `occurredAt=created_at`.
- **`App\Services\Deal\Pipeline\PipelineEventService`** — aggregates all registered sources, returns a
  single chronological `Collection<PipelineEvent>` for a deal (ascending by `occurredAt`; consumers
  filter by scope/step and reverse as needed).
- **Plug-in point:** sources are registered in `AppServiceProvider` as the array passed to
  `PipelineEventService`. Adding email/WhatsApp later = add an `EmailEventSource` /
  `WhatsAppEventSource` (reading `communications` via the polymorphic `communication_links`, morph →
  Deal) to that array. **No DTO/contract change.** (Communications carry `direction` + `channel`
  already; their only gap is step-scoping — they default to `scope=deal` until a step link exists.)

Sources available to absorb (audit): `deal_step_comments` (step, live), `deal_activity_log`
(step-aware system events — optional future source), `communications` + `communication_links`
(email/WhatsApp, deal-scoped — Phase 4).

### 3.4 Per-agent view preference

- Table `pipeline_user_preferences` (`user_id` unique, `default_view` = timeline|list, timestamps),
  model `App\Models\DealV2\PipelineUserPreference` — mirrors the existing `CalendarUserPreference`
  pattern. Determines which view a user lands on; either view can switch live.

## 4. Phased plan

- **Phase 1 — shared foundation (THIS):** `planned_start_date` (+manual) + projection/activate/backfill;
  the event normalizer (DTO + interface + `CommentEventSource` + `PipelineEventService`); per-agent
  view preference. **No user-facing UI.** Proven on deals 180 & 168.
- **Phase 2 — Timeline dashboard (BUILT):** `GET deals-dr2/{deal}/pipeline/timeline` +
  `POST …/steps/{step}/reschedule` (JSON preview/commit — first JSON board endpoints).
  `PipelineTimelineService` assembles the payload: bars stretched to duration, greedy interval
  row-packing (overlaps auto-stack), milestone gate diamonds, phase bands derived between gates, a
  today line, and the activity lane from `PipelineEventService`. `PipelineRescheduleService` is the
  drag-cascade (§4.1) — dry-run preview → confirm dialog → commit. Tile actions reuse the existing
  `deals-dr2.pipeline.step.*` routes with `?from=timeline` so they return to the timeline
  (`PipelineController::pipelineRedirect`); "Open on board ↗" covers Edit-dates/N-A/sequence. View
  toggle Timeline | Board. Blade `dr2/pipeline-timeline.blade.php` (self-contained Alpine, no external
  gantt lib). Tests: `tests/Feature/Dr2/PipelineRescheduleTest.php` (cascade fan-in + sibling + held +
  frozen + render). Proven on deals 180 (concurrent) & 168 (linear).
- **Phase 3 — List view:** vertical list, grab-to-reorder (display `position` only — bulk position
  POST, adapting the template-editor drag pattern), inline edit, same action set via shared wrappers.
- **Phase 4 — per-agent default wiring + email/WhatsApp event sources** (register the two new sources
  on the normalizer; resolve deal via `communication_links`).

### 4.1 Phase-2 drag-cascade rule (recorded now; not built)

Moving step S by Δ days: `S.planned_start += Δ`, `S.due_date += Δ` (duration preserved). For every step
transitively dependent on S (trigger chain ∪ deps table), recompute
`planned_start = max(predecessor.planned_end + own gap)` and shift `due_date` equally. Steps NOT
downstream of S are untouched (concurrent siblings stay). A dependent with a manual date
(`planned_start_manual`/`due_date_manual`) is HELD (listed in the confirm dialog, not moved). COMPLETED
steps are frozen (not draggable). The confirm dialog previews affected steps + new dates before commit.
This is the runtime re-anchor math (`dependencyReadiness`) applied to PLANNED dates.

## 5. Files

**Phase 1 (this):**
- Migration: `..._add_planned_start_to_deal_step_instances.php` (planned_start_date, planned_start_manual)
- Migration: `..._create_pipeline_user_preferences_table.php`
- `app/Models/DealV2/DealStepInstance.php` — casts + `duration_days` / `planned_end_date` accessors
- `app/Models/DealV2/PipelineUserPreference.php`
- `app/Services/Deal/Dr1PipelineService.php` — planned_start in createPipeline + activateStep
- `app/Support/Pipeline/PipelineEvent.php`, `PipelineEventSource.php`
- `app/Services/Deal/Pipeline/CommentEventSource.php`, `PipelineEventService.php`
- `app/Console/Commands/BackfillPlannedStartDates.php`
- `app/Providers/AppServiceProvider.php` — register the normalizer sources
- Tests under `tests/Feature/Dr2/` (+ `tests/Unit/`).

## 6. Deliberately deferred / NOT in Phase 1

- Any user-facing UI (Phases 2–3).
- Edge-resize (drag a tile edge to change duration) — schema supports it (explicit start+end) but the
  interaction is Phase 2+.
- A physical `pipeline_events` table / cross-deal event analytics — normalizer suffices; can be
  materialised later without a DTO change.
- Fan-in-topological rewrite of the up-front `due_date` projection — resolves at activation/drag.
- Email/WhatsApp event sources — Phase 4 (interface + registration point exist now).

## 7. Proof (Phase 1, on QA1 deals 180 & 168)

- `planned_start_date` populated + sane on every step of deals 180 (concurrent-lanes demo, fan-in) &
  168 (linear); no negative-width spans; milestones zero-width.
- `PipelineEventService::eventsForDeal()` surfaces `deal_step_comments` as `PipelineEvent`s with
  `occurredAt` + `scope=step` + `stepId`.
- `PipelineUserPreference` round-trips a per-agent default view.
