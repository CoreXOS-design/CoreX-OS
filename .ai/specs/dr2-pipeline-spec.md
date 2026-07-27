# DR2 Pipeline — authoritative view spec

Two views only: TIMELINE and LIST. They MUST be visually distinct. The failure to avoid: making both look like vertical lists (that happened and Johan rejected it).

> This spec is self-contained. A session with zero prior memory can build the Timeline from this file
> alone. Every field, method, route and path below was verified against the live QA1 code on 2026-07-27.
> Do NOT rely on memory — re-verify against the code if anything looks stale.

---

## 0. SCOPE / CURRENT STATE (read this first)

The **LIST view is CORRECT and DONE** — vertical phased cards per `.ai/mockups/dr2_list_phased.html`
(Deal Signed anchor → Stage 1 condition groups → GRANTED gate → Stage 2). **Do NOT touch the List**
(its blade, controller, or its read-model wiring) unless it is proven broken.

The **TIMELINE view is WRONG**. It currently renders the *same* vertical `buildPhased()` phased layout
as the List, so the two views look identical — exactly the "both look like lists" failure Johan
rejected. **The job is to rebuild ONLY the Timeline** into the **horizontal date-Gantt** described by
`.ai/mockups/dr2_timeline_horizontal.html`. Nothing else in the pipeline changes.

The horizontal data layer already exists (dormant) — see §DATA MODEL. This is a view-layer rebuild plus
a one-line controller switch, not a from-scratch build.

---

## TIMELINE view = a real time/date-based timeline ("the timeline we had, fixed")
- Horizontal: a date axis along the top; each step is a TILE positioned by start date, width = its duration (work starts and ends at set points).
- Overlapping steps AUTO-STACK into separate rows so tiles NEVER overlap and labels never collide; the canvas scrolls.
- Behind the tiles: phase bands (Suspensive Conditions / Transfer & Registration); milestone gate diamonds; a red TODAY line.
- Each tile keeps the full action set (Complete/Reopen, Edit dates, Sequence, N/A, Remove, Comments).
- Reference mockup: .ai/mockups/dr2_timeline_horizontal.html — FIX the overlap; do NOT replace it with a vertical list.

## LIST view = vertical sectioned cards grouped by stage (the phased layout — correct FOR THE LIST)
- Top-to-bottom: Deal Signed anchor -> Stage 1 "Suspensive Conditions" (grouped tracks: Bond/Cash/Sale/FICA) -> GRANTED gate -> Stage 2 "Transfer & Registration".
- Each step = full-width card: dot, name, star, dates, status, "Waiting on..." note, full action grid; grab-to-reorder (display only).
- Stage 2 dimmed/locked until the deal is granted.
- Reference mockup: .ai/mockups/dr2_list_phased.html

## Shared by both views
- Deal-context tabs on TOP (Structure, Work Orders, Documents, Parties, Proforma): collapsible, default collapsed. Each expanded panel bounded to ~min(48vh,460px) and scrolls INTERNALLY — never pushes content off-screen (Johan's rule: "define a set area, inside it it scrolls").
- Comments footer that posts without error (the $days 500 is fixed; keep it fixed).
- No 500s. Prove BOTH views with real-browser screenshots compared to the two reference mockups before calling anything done. Real DR2 data (dr1_deal_id). QA1 only.

## What went wrong before (do not repeat)
The Timeline was rebuilt as the vertical sectioned layout, so Timeline and List both looked like lists. Timeline = horizontal date-based; List = vertical sectioned cards.

---

## 1. DATA MODEL — the exact fields, models, services (verified)

**Model:** `App\Models\DealV2\DealStepInstance` — one row per pipeline step. Anchored to the DR1 deal by
`dr1_deal_id` (NOT `deal_id`, which is the legacy deals_v2 twin). Fetch with:
`DealStepInstance::where('dr1_deal_id', $deal->id)->orderBy('position')->orderBy('id')->get()`.

Driving columns (all verified in the model's `$fillable`/`$casts`):

| Field | Type | Meaning / use |
|---|---|---|
| `planned_start_date` | date | Planned START of the step's span. **Tile left edge / x-position.** |
| `due_date` | date | Planned END of the span (Johan decision 2: `due_date` IS the planned end). **Tile right edge.** |
| `duration_days` | int accessor | = `due_date − planned_start_date` in whole days (accessor `durationDays()`, model L114). **Tile width.** |
| `days_offset` | int | Offset in days after the step it follows (`trigger_step_instance_id`). Shown as `+Nd` tag. |
| `is_milestone` | bool | Renders as a **gate diamond** on the axis (not a duration tile). |
| `is_suspensive` | bool | This step is a **suspensive condition** (must be met to grant the deal). |
| `condition_key` | string\|null | Stage-1 grouping key: `'bond'` \| `'cash'` \| `'sale_of_another'` \| null. |
| `is_grant_marker` | bool | The single **GRANTED gate** step (the Stage 1→2 boundary). |
| `status` | string | `'not_started'` \| `'active'` \| `'completed'` \| `'skipped'` \| `'overdue'`. |
| `position` | int | Display order (List reorder writes this; ties broken by `id`). |
| `trigger_step_instance_id` | fk | The step this one follows (predecessor edge). AND-gate fan-in also lives in `deal_step_instance_dependencies`. |
| `is_locked`, `is_custom` | bool | 🔒 lock badge / "+ custom" badge. |
| `actual_date`, `completed_at` | date/dt | Actual completion date (shown on done tiles). |
| `na_reason` | string | Reason when a step is marked N/A (`status='skipped'`). |

**RAG colour:** `App\Services\Deal\Dr1PipelineService::calculateRag(DealStepInstance $s, $dueDate = null): string`
and static `Dr1PipelineService::ragColour(string $rag): string`.

**Condition labels:** `App\Services\DealV2\Dr2ConditionCatalog::conditions()` → `['bond'=>['label'=>'Bond',…],
'cash'=>['label'=>'Cash',…], 'sale_of_another'=>['label'=>'Subject to sale of another property',…]]`.

**Stage membership composer (used by the List, available to the Timeline):**
`App\Services\DealV2\DealLaneComposer::board(iterable $steps): array` returns
`['anchor'=>?DealStepInstance, 'gate'=>?DealStepInstance, 'stage1'=>segments, 'stage2'=>segments]` where
`gate` = the `is_grant_marker` step, `stage2` = every step reachable (successor direction) from the gate,
`anchor` = the predecessor-less non-condition non-gate root (Deal Signed). Segments are
`['type'=>'sequence','step'=>DealStepInstance]` or `['type'=>'band','lanes'=>[[DealStepInstance,…],…]]`.

### THE REUSABLE HORIZONTAL DATA LAYER (already exists, dormant)

`App\Services\Deal\Pipeline\PipelineTimelineService` — a **pure read** service. It has THREE builders.
`buildPhased()` is what Timeline + List currently call (the vertical layout). The other two,
**`build()` and `buildBoard()`, are the ready-made horizontal read-models — they are wired to NOTHING
today. Reuse them.** Both only include steps that have BOTH `planned_start_date` AND `due_date`
(undated steps are excluded — see §GRANTED-GATE). `PipelineTimelineService::DAY_WIDTH = 26` px/day.

**`build(Deal $deal): array`** — the richer horizontal model. Returns:
- `empty` (bool), `range_start` (Y-m-d; = min planned_start − 2 days), `total_days` (int; to max due + 2 days),
  `day_width` (26), `today_index` (int day-offset of *now* from `range_start` — **the red TODAY line**),
  `row_count`, `gates_levels`.
- `bars[]` — the duration tiles (steps with duration > 0), each:
  `id, name, start_index, end_index, duration_days, is_milestone, status, rag, colour, na, blocked,
  draggable, row`. **`row`** is assigned by **greedy interval row-packing** (sort by start, drop each bar
  into the first row whose last bar has ended, else a new row) → non-overlapping stacked rows.
- `gates[]` — milestone / zero-duration points: `id, name, index` (end-date offset), `is_milestone`,
  `label_level` (staggered so clustered gate labels don't collide).
- `bands[]` — phase bands DERIVED between consecutive milestone gates: `start_index, end_index, label`
  (`'→ '+name` up to each gate; trailing `'After '+name`).
- `events[]` — normalized comment/activity stream: `key, type, index (clamped to axis), off_axis, day
  (Y-m-d), scope, step_id, direction, author, body, occurred_at`.

**`buildBoard(Deal $deal): array`** — the leaner tile model that matches the mockup's shape most directly.
Returns: `empty`, `day_width` (21), `base_date` (Y-m-d; day 0 = earliest planned_start), `today_day`,
`days` (= max(7, idx(maxEnd)+5)), and:
- `tiles[]`: `id, name, start` (day index), `dur` (days), `status` (`done`/`active`/`upcoming`), `star`.
- `miles[]`: `name, day, state` (`done`/`active`/`up`), `lvl` (stagger level).
- `phases[]`: `name, from, to` (bands between milestone gates).
- `comments[]`: `id, target` (step id \| `'deal'`), `scope, who, when` (`j M`), `day, text, type`.

> Verified live on deal 168: `buildBoard` → tiles=12, miles=7, phases=5, days=44, today_day=5.
> `build` → bars=14, gates=9, bands=6, row_count=7, today_index=7, total_days=43. Both non-empty.
> **Pick ONE builder** and render to it; `build()` carries rag/colour/row-packing, `buildBoard()` is
> closer to the mockup markup. Do not run both.

---

## 2. TIMELINE RENDER SPEC (match `.ai/mockups/dr2_timeline_horizontal.html`)

The mockup is the visual source of truth. Rebuild the Timeline blade to it:

1. **Date axis** across the top — one tick per week (label `d M`), full canvas width = `days`/`total_days` × `day_width` px. Canvas scrolls horizontally.
2. **Step tiles** positioned absolutely: `left = start_index × day_width`, `width = duration_days × day_width` (min a sensible floor so 1-day steps stay legible). Colour by `rag`/`status`.
3. **Greedy auto-stack into rows** so tiles never overlap and labels never collide — use the `row` already computed by `build()` (or replicate the pack for `buildBoard`). `top = ROW_TOP + row × ROW_HEIGHT`.
4. **Phase bands** behind the tiles (`bands[]` / `phases[]`) — faint vertical bands spanning `from→to`, labelled (Suspensive Conditions / Transfer & Registration etc., derived from milestone gates).
5. **Milestone diamonds** at each gate's `index`, with staggered labels (`label_level` / `lvl`).
6. **Red TODAY line** at `today_index` / `today_day` (hide if outside the range).
7. **Each tile keeps the full action set** — Complete/Reopen, Edit dates, Sequence, N/A, Remove, Comments — via the shared `dr2._pipeline-step-tile` partial OR inline actions posting to the existing `deals-dr2.pipeline.step.*` routes.
8. **Drag to reschedule** (optional but in the mockup): horizontal drag posts to `deals-dr2.pipeline.step.reschedule` (`POST /deals-dr2/{deal}/pipeline/steps/{step}/reschedule`, body `{ new_start: 'YYYY-MM-DD', commit: true }` → `PipelineTimelineController@reschedule` → `PipelineRescheduleService::reschedule($step, Carbon, bool $commit, ?int $userId)`; returns JSON `{ok, …}`, 423 if the pipeline is locked).
9. **Comments footer** — the `events[]`/`comments[]` feed + an add box that POSTs to `deals-dr2.pipeline.step.comment` (`POST /deals-dr2/{deal}/pipeline/steps/{step}/comment` → `PipelineController@addComment`, redirects with "Comment added"). Must post without a 500.

---

## 3. GRANTED-GATE + PROJECTED / PLANNED DATES

- **The GRANTED gate = the `is_grant_marker` step** (there is exactly one per composable deal; `DealLaneComposer::board()['gate']`). Everything reachable from it (successor direction) is Stage 2; the rest of the conditions are Stage 1.
- **"Granted" flips Stage 1 → Stage 2** when: `$deal->status ∈ ['granted','completed']` **OR** the gate step's `status === 'completed'` **OR** every `is_suspensive` step is `completed` (and there is at least one). This is the logic already implemented in `PipelineTimelineService::buildPhased()` — reuse it verbatim if the Timeline needs a granted flag/marker.
- **Projected grant date** = the **latest `due_date` across the `is_suspensive` steps** (the gate step's own date is not cascaded), falling back to the gate step's `due_date`.
- **Horizontal phase bands do NOT depend on the granted flag** — `build()`/`buildBoard()` derive bands purely from the **milestone gates** (between consecutive `is_milestone` end-dates). So the Timeline gets its Suspensive/Transfer banding for free from the read-model; the granted flag is only needed if you additionally want to dim/mark post-grant tiles.
- **Undated / orphan steps:** `build()`/`buildBoard()` **exclude** any step missing `planned_start_date` OR `due_date` (they cannot be positioned on a date axis). If a real deal has such steps, surface them in a small "unscheduled" strip or ensure dates exist — do NOT silently drop them without a visible note. (In the List's phased model, a condition-less step orphaned from the gate is instead merged into Stage 2 by `due_date`; that is a List concern, not the Timeline's.)

---

## 4. FILES TO MODIFY (exact paths)

**Rebuild / edit (Timeline only):**
- `resources/views/dr2/pipeline-timeline.blade.php` — **the main change.** Replace the current vertical phased markup with the horizontal date-Gantt (§2). It may carry its own scoped `<style>` block (the old horizontal CSS was overwritten and is gone).
- `app/Http/Controllers/Dr2/PipelineTimelineController.php` — in `show()`, switch the read-model from `$this->timeline->buildPhased($deal)` to `$this->timeline->build($deal)` (or `buildBoard($deal)`). Keep `reschedule()` and the `pipelineContext($deal)` call unchanged.
- `app/Services/Deal/Pipeline/PipelineTimelineService.php` — reuse `build()`/`buildBoard()` as-is; only extend if the chosen builder is missing something the mockup needs. Do NOT break `buildPhased()` (the List depends on it).

**Shared — edit only additively, never break the List:**
- `resources/views/dr2/_pipeline-context-tabs.blade.php` — the top tabs (keep on top, collapsible, default collapsed).
- `resources/views/dr2/_pipeline-surface-styles.blade.php` — shared CSS. You may ADD Timeline classes; do NOT remove classes the List uses (`.dr2-ph-*`, `.dr2-tile*`, `.dr2-band*`, `.dr2-lane*`, `.dr2-seq*`).
- `resources/views/dr2/_pipeline-step-tile.blade.php` — the uniform step tile (full 6-action set); reuse for tile actions if convenient.

**LEAVE ALONE (the List — it is correct):**
- `resources/views/dr2/pipeline-list.blade.php`
- `app/Http/Controllers/Dr2/PipelineListController.php`
- Do NOT change `buildPhased()` behaviour.

**Route (context, no change needed):** `GET /deals-dr2/{deal}/pipeline/timeline` → name `deals-dr2.pipeline.timeline` → `PipelineTimelineController@show` (routes/web.php ~L728). Middleware `permission:view_deals`.

---

## 5. ACCEPTANCE CRITERIA (screenshot-prove every one before "done")

Use a real browser on serving QA1, compare to `.ai/mockups/dr2_timeline_horizontal.html`, and screenshot:

1. **Timeline is visually DISTINCT from the List** — a horizontal date axis with tiles laid left→right, NOT vertical stacked cards. Put the two side by side; they must not look alike.
2. **Tiles positioned by date, width ∝ duration** — a longer `duration_days` step is a wider tile; tiles sit at their `start_index`.
3. **Overlapping tiles auto-stack into rows** — no tile overlaps another, no label collides (deal 168 packs into ~7 rows).
4. **Phase bands behind tiles + milestone diamonds + a red TODAY line** at `today_index`/`today_day`.
5. **No 500** on Timeline load for BOTH test deals (below).
6. **Comments footer posts without error** — add a comment → 302 → it appears in the feed (no `$days` regression).
7. **Real `dr1_deal_id` data, QA1 only.** No seeding, no fixtures.

**Test deals (verified on QA1 2026-07-27):**
- **Deal 168** — rich case: 19 steps, all dated, `is_grant_marker`=1, 3 suspensive, conditions `bond, cash, sale_of_another`; packs into 7 rows.
  `https://qatesting1.corexos.co.za/deals-dr2/168/pipeline/timeline`
- **Deal 180** — different shape: 19 steps, all dated, **no grant marker, no condition_key steps** (exercises the no-gate / plain-timeline path).
  `https://qatesting1.corexos.co.za/deals-dr2/180/pipeline/timeline`
- **List (leave unchanged — the "must differ" comparison):**
  `https://qatesting1.corexos.co.za/deals-dr2/168/pipeline/list`

Deploy to the serving `/corex-qa1` checkout (git pull → `php artisan view:clear route:clear config:clear` → reload php8.2-fpm). QA1 ONLY — never Staging/live without Johan's explicit go.
