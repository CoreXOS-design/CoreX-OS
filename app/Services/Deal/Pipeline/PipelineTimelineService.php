<?php

namespace App\Services\Deal\Pipeline;

use App\Models\Deal;
use App\Models\DealV2\DealStepInstance;
use App\Services\Deal\Dr1PipelineService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pipeline Dashboard Phase 2 — the timeline read-model. Turns a deal's pipeline steps + events into a
 * positioned payload the timeline view renders: bars (steps as duration-width tiles, auto-stacked into
 * rows so overlaps never collide), gate diamonds (milestones), phase bands (derived BETWEEN milestone
 * gates — no column, spec decision 3), a today line, and the activity stream (from the event
 * normalizer). Pure read — no writes. Spec §3, §4.1.
 */
class PipelineTimelineService
{
    public const DAY_WIDTH = 26; // px per day (a hint the view uses for x = index * DAY_WIDTH)

    public function __construct(
        private readonly Dr1PipelineService $pipelines,
        private readonly PipelineEventService $events,
    ) {
    }

    /**
     * The APPROVED-MOCKUP shape (tmp/dr2_timeline_agreed.html): white step TILES positioned by date +
     * auto-stacked, gold milestone GATES, phase BANDS between gates, a date axis, today, and comments
     * (both the on-timeline pins and the footer feed). Reads the real dr1_deal_id steps + normalized
     * comment events. day 0 = the earliest planned start; every index is a whole day.
     */
    public function buildBoard(Deal $deal): array
    {
        $allSteps = DealStepInstance::where('dr1_deal_id', $deal->id)
            ->orderBy('position')->orderBy('id')->get();

        // Truly empty only when the deal has NO pipeline steps at all. A deal whose steps are undated
        // is NOT empty — those steps must still surface in the persistent Unscheduled tray below.
        if ($allSteps->isEmpty()) {
            return ['empty' => true, 'day_width' => 21];
        }

        // Only steps with BOTH a start and an end can sit on a date axis.
        $steps = $allSteps->filter(fn ($s) => $s->planned_start_date && $s->due_date)->values();

        // Deal-level comment target = the composer's anchor (Deal Signed), else the first step, so the
        // footer's "General (deal)" option always has a real step to attach a deal-scope note to.
        $anchor   = app(\App\Services\DealV2\DealLaneComposer::class)->board($allSteps)['anchor'] ?? null;
        $anchorId = $anchor ? (int) $anchor->id : (int) (optional($allSteps->first())->id ?? 0);

        // Steps that cannot be positioned on the axis (missing a start or end) go to the PERSISTENT
        // "Unscheduled" tray — ALWAYS populated, never silently dropped (spec §2/§3). This is populated
        // even when NO step is dated (deal 183: 17 undated steps must all show).
        $unscheduled = $allSteps
            ->filter(fn ($s) => ! ($s->planned_start_date && $s->due_date))
            ->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])
            ->values()->all();

        // No step is dated → there is no date axis to draw, but the deal is NOT empty: return an empty
        // axis (so the view still renders) and let the Unscheduled tray carry every step. Removing this
        // is the exact regression from 5228d94a, which early-returned empty and discarded all steps.
        if ($steps->isEmpty()) {
            $base = Carbon::now()->startOfDay();
            return [
                'empty'       => false,
                'day_width'   => 21,
                'base_date'   => $base->toDateString(),
                'today_day'   => 0,
                'days'        => 7,
                'phases'      => [],
                'miles'       => [],
                'mile_levels' => 1,
                'tiles'       => [],
                'row_count'   => 1,
                'anchor_id'   => $anchorId,
                'unscheduled' => $unscheduled,
                'comments'    => $this->boardComments($deal, fn ($d) => 0, 7),
            ];
        }

        $base   = $steps->reduce(fn ($c, $s) => ($c === null || $s->planned_start_date->lt($c)) ? $s->planned_start_date->copy() : $c)->startOfDay();
        $maxEnd = $steps->reduce(fn ($c, $s) => ($c === null || $s->due_date->gt($c)) ? $s->due_date->copy() : $c)->startOfDay();
        $idx    = fn (Carbon $d) => (int) $base->diffInDays($d->copy()->startOfDay(), false);
        $days   = max(7, $idx($maxEnd) + 5);

        $statusOf = function (DealStepInstance $s) {
            if ($s->status === 'completed') return 'done';
            if ($s->status === 'active') return 'active';
            return 'upcoming'; // not_started / overdue / skipped render as upcoming/grey
        };

        $tiles = [];
        $gates = [];
        foreach ($steps as $s) {
            $startI = $idx($s->planned_start_date);
            $endI   = $idx($s->due_date);
            if ($s->is_milestone) {
                $gates[] = [
                    'id'    => (int) $s->id,
                    'name'  => $s->name,
                    'day'   => $endI,
                    'state' => $s->status === 'completed' ? 'done' : ($s->status === 'active' ? 'active' : 'up'),
                ];
                continue;
            }
            $tiles[] = [
                'id'     => $s->id,
                'name'   => $s->name,
                'start'  => $startI,
                'dur'    => max(1, $endI - $startI),
                'status' => $statusOf($s),
                'star'   => (bool) $s->is_suspensive,
            ];
        }

        // Greedy interval row-packing: sort by start, drop each tile into the first row whose last tile
        // has already ended (no overlap); else a new row underneath. So overlapping tiles never collide.
        usort($tiles, fn ($a, $b) => [$a['start'], $a['start'] + $a['dur']] <=> [$b['start'], $b['start'] + $b['dur']]);
        $rowEnds = [];
        foreach ($tiles as &$t) {
            $placed = false;
            foreach ($rowEnds as $r => $end) {
                if ($t['start'] >= $end) {
                    $t['row'] = $r;
                    $rowEnds[$r] = $t['start'] + $t['dur'];
                    $placed = true;
                    break;
                }
            }
            if (! $placed) {
                $t['row'] = count($rowEnds);
                $rowEnds[] = $t['start'] + $t['dur'];
            }
        }
        unset($t);
        $rowCount = max(1, count($rowEnds));

        // Anti-overlap: stagger each milestone LABEL onto its own level when gates cluster near a date.
        $ms = collect($gates)->sortBy('day')->values();
        $labelGap = (int) ceil(130 / 21);
        $ends = [];
        $mileLevels = 1;
        $ms = $ms->map(function ($m) use (&$ends, &$mileLevels, $labelGap) {
            $lvl = 0;
            while (isset($ends[$lvl]) && $m['day'] < $ends[$lvl]) {
                $lvl++;
            }
            $ends[$lvl] = $m['day'] + $labelGap;
            $mileLevels = max($mileLevels, $lvl + 1);
            $m['lvl'] = $lvl;
            return $m;
        });

        // Phase bands derived between consecutive milestone gates (labelled by the gate they lead to).
        $phases = [];
        $prev = 0;
        foreach ($ms as $m) {
            if ($m['day'] > $prev) {
                $phases[] = ['name' => $m['name'], 'from' => $prev, 'to' => $m['day']];
            }
            $prev = $m['day'];
        }
        if ($days > $prev) {
            $phases[] = ['name' => 'After ' . ($ms->last()['name'] ?? ''), 'from' => $prev, 'to' => $days];
        }

        // Comments — the normalizer stream (step comments now; email/WhatsApp later), for the footer +
        // the on-timeline pins positioned by the date each was made.
        $comments = $this->boardComments($deal, $idx, $days);

        return [
            'empty'       => false,
            'day_width'   => 21,
            'base_date'   => $base->toDateString(),
            'today_day'   => $idx(Carbon::now()),
            'days'        => $days,
            'phases'      => $phases,
            'miles'       => $ms->all(),
            'mile_levels' => $mileLevels,
            'tiles'       => $tiles,
            'row_count'   => $rowCount,
            'anchor_id'   => $anchorId,
            'unscheduled' => $unscheduled,
            'comments'    => $comments,
        ];
    }

    /**
     * The board comment stream (footer feed + on-axis pins). Shared by the dated and the no-dated-steps
     * paths of buildBoard() so the footer feed is identical either way. $idx maps a Carbon date to a day
     * index on the axis (a no-op fn ($d) => 0 when there is no axis); $days clamps the pin position.
     */
    private function boardComments(Deal $deal, callable $idx, int $days): array
    {
        return $this->events->eventsForDeal($deal)->map(function ($e) use ($idx, $days) {
            return [
                'id'     => $e->sourceType . ':' . $e->sourceId,
                'target' => $e->isStepScoped() ? (int) $e->stepId : 'deal',
                'scope'  => $e->scope,
                'who'    => $e->authorName ?: 'System',
                'when'   => Carbon::parse($e->occurredAt)->format('j M'),
                'day'    => max(0, min($days, $idx(Carbon::parse($e->occurredAt)))),
                'text'   => $e->body,
                'type'   => $e->type,
            ];
        })->values()->all();
    }

    /**
     * The PHASED read-model (Johan's APPROVED vertical/sectioned layout — /tmp/dr2_phased_agreed.html):
     *
     *   Anchor (Deal Signed ★)
     *   Stage 1 · Suspensive Conditions   — one GROUP per condition (Bond / Cash / Sale / …), each a
     *                                        parallel track that must be met to grant the deal
     *   GRANTED gate                      — the convergence: deal becomes unconditional once every
     *                                        condition above is met
     *   Stage 2 · Transfer & Registration — the post-grant sequence (LaneComposer segments: full-width
     *                                        sequence points + concurrent bands), LOCKED until granted
     *
     * Membership is honest and data-driven: DealLaneComposer splits anchor / gate / stage2 off the real
     * predecessor graph (primary follows ∪ AND-gate fan-in); Stage 1 is whatever remains, grouped by
     * condition_key. Pure read — returns step IDs (the view maps them through the shared step-tile) plus
     * the LaneComposer stage-2 segment objects and the normalized comment feed the footer renders.
     */
    public function buildPhased(Deal $deal): array
    {
        $steps = DealStepInstance::where('dr1_deal_id', $deal->id)
            ->orderBy('position')->orderBy('id')->get();

        $comments = $this->commentFeed($deal);

        if ($steps->isEmpty()) {
            return ['empty' => true, 'comments' => $comments];
        }

        $composed = app(\App\Services\DealV2\DealLaneComposer::class)->board($steps);
        $anchor   = $composed['anchor'];
        $gate     = $composed['gate'];

        // Every step the composer placed in Stage 2 (flatten sequence + band lanes).
        $stage2Ids = [];
        foreach ($composed['stage2'] as $seg) {
            if (($seg['type'] ?? null) === 'sequence') {
                $stage2Ids[(int) $seg['step']->id] = true;
            } else {
                foreach ($seg['lanes'] ?? [] as $lane) {
                    foreach ($lane as $m) {
                        $stage2Ids[(int) $m->id] = true;
                    }
                }
            }
        }

        $exclude = $stage2Ids;
        if ($anchor) $exclude[(int) $anchor->id] = true;
        if ($gate)   $exclude[(int) $gate->id]   = true;

        // The remainder = everything the composer left out of anchor / gate / Stage 2. Only the steps
        // that actually belong to a suspensive CONDITION (condition_key set) form Stage 1 groups. A
        // remainder step with NO condition (e.g. a compliance step orphaned by a broken predecessor
        // edge, so it wasn't reachable from the gate) is post-grant transfer work — it merges into
        // Stage 2 by date rather than inventing a bogus "Other conditions" group.
        $remainder   = $steps->reject(fn (DealStepInstance $s) => isset($exclude[(int) $s->id]))->values();
        $stage1Steps = $remainder->filter(fn (DealStepInstance $s) => $s->condition_key !== null)->values();
        $orphans     = $remainder->reject(fn (DealStepInstance $s) => $s->condition_key !== null)->values();

        // Merge orphans into the Stage-2 segment list as their own sequence points, ordered with the
        // composer's segments by earliest due date so they read in the right place in the sequence.
        $stage2Segments = $composed['stage2'];
        foreach ($orphans as $o) {
            $stage2Segments[] = ['type' => 'sequence', 'step' => $o];
        }
        $segDue = function (array $seg): int {
            if (($seg['type'] ?? null) === 'sequence') {
                return $seg['step']->due_date ? strtotime((string) $seg['step']->due_date) : PHP_INT_MAX;
            }
            $min = PHP_INT_MAX;
            foreach ($seg['lanes'] ?? [] as $lane) {
                foreach ($lane as $m) {
                    if ($m->due_date) $min = min($min, strtotime((string) $m->due_date));
                }
            }
            return $min;
        };
        usort($stage2Segments, fn ($a, $b) => $segDue($a) <=> $segDue($b));

        $catalog   = app(\App\Services\DealV2\Dr2ConditionCatalog::class)->conditions();
        $groupMeta = [
            'bond'            => ['icon' => '🏦', 'order' => 1],
            'cash'            => ['icon' => '💰', 'order' => 2],
            'sale_of_another' => ['icon' => '🏠', 'order' => 3],
            '_general'        => ['icon' => '📋', 'order' => 8],
        ];
        $labelOverride = ['sale_of_another' => 'Sale of another property', '_general' => 'Other conditions'];

        // Count of cash payment steps across the WHOLE deal (they live in Stage 2), for the "· N payments" tag.
        $cashPayments = $steps->filter(fn ($s) => $s->condition_key === 'cash'
            && str_contains(strtolower((string) $s->name), 'payment'))->count();

        $stage1Groups = $stage1Steps
            ->groupBy(fn (DealStepInstance $s) => $s->condition_key ?: '_general')
            ->map(function ($group, $key) use ($catalog, $groupMeta, $labelOverride, $cashPayments) {
                $label = $labelOverride[$key] ?? ($catalog[$key]['label'] ?? ucfirst(str_replace('_', ' ', (string) $key)));
                $sub   = ($key === 'cash' && $cashPayments > 1) ? $cashPayments . ' payments' : null;
                $active = $group->contains(fn ($s) => ! in_array($s->status, ['completed', 'skipped'], true));
                return [
                    'key'      => $key,
                    'label'    => $label,
                    'sub'      => $sub,
                    'icon'     => $groupMeta[$key]['icon'] ?? '📋',
                    'order'    => $groupMeta[$key]['order'] ?? 7,
                    'active'   => $active,
                    'step_ids' => $group->sortBy('position')->pluck('id')->map(fn ($id) => (int) $id)->all(),
                ];
            })
            ->sortBy('order')->values()->all();

        // GRANTED — the deal is unconditional once every suspensive condition is met. Honour the deal's
        // own status and the gate step, and fall back to "all suspensive steps completed".
        $suspensive = $steps->where('is_suspensive', true);
        $granted = in_array($deal->status, ['granted', 'completed'], true)
            || ($gate && $gate->status === 'completed')
            || ($suspensive->isNotEmpty() && $suspensive->every(fn ($s) => $s->status === 'completed'));

        // Projected grant date = the latest due date across the suspensive conditions (the gate step's
        // own date isn't cascaded), else the gate's due date.
        $projected = $suspensive->filter(fn ($s) => $s->due_date)->max('due_date')
            ?? ($gate?->due_date);

        return [
            'empty'     => false,
            'flat'      => $gate === null && empty($stage1Groups), // old-model / non-composable fallback
            'anchor_id' => $anchor ? (int) $anchor->id : null,
            'gate'      => [
                'id'        => $gate ? (int) $gate->id : null,
                'granted'   => (bool) $granted,
                'projected' => $projected ? Carbon::parse($projected)->format('j M Y') : null,
            ],
            'stage1'    => ['groups' => $stage1Groups],
            'stage2'    => [
                'active'   => (bool) $granted,
                'segments' => $stage2Segments,
                'has'      => ! empty($stage2Segments),
            ],
            'all_ids'   => $steps->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'comments'  => $comments,
        ];
    }

    /**
     * The normalized comment feed (footer): who/when/text/scope/step, newest last. Shared by the phased
     * timeline + list footers. Deal-scope = a comment on the anchor/gate; step-scope = on a real step.
     */
    private function commentFeed(Deal $deal): array
    {
        return $this->events->eventsForDeal($deal)->map(function ($e) {
            return [
                'id'    => $e->sourceType . ':' . $e->sourceId,
                'step'  => $e->isStepScoped() ? (int) $e->stepId : null,
                'scope' => $e->scope,
                'who'   => $e->authorName ?: 'System',
                'when'  => Carbon::parse($e->occurredAt)->format('j M H:i'),
                'text'  => $e->body,
                'type'  => $e->type,
            ];
        })->values()->all();
    }

    public function build(Deal $deal): array
    {
        $steps = DealStepInstance::where('dr1_deal_id', $deal->id)
            ->orderBy('position')->orderBy('id')->get()
            ->filter(fn ($s) => $s->planned_start_date && $s->due_date)
            ->values();

        if ($steps->isEmpty()) {
            return ['empty' => true, 'day_width' => self::DAY_WIDTH];
        }

        // Date range across all step spans, padded a couple of days each side. Work in the app
        // timezone off the Carbon dates directly (NOT createFromTimestamp, which drifts by the tz
        // offset and yields fractional day indices); every index is a whole day.
        $minStart = $steps->reduce(fn ($c, $s) => ($c === null || $s->planned_start_date->lt($c)) ? $s->planned_start_date->copy() : $c);
        $maxEnd   = $steps->reduce(fn ($c, $s) => ($c === null || $s->due_date->gt($c)) ? $s->due_date->copy() : $c);
        $rangeStart = $minStart->copy()->startOfDay()->subDays(2);
        $rangeEnd   = $maxEnd->copy()->startOfDay()->addDays(2);
        $totalDays  = max(1, (int) $rangeStart->diffInDays($rangeEnd));

        $idx = fn (Carbon $d) => (int) $rangeStart->diffInDays($d->copy()->startOfDay(), false);

        // Split into bars (duration > 0) and point-steps (duration 0 → diamonds).
        $bars = [];
        $gates = [];
        foreach ($steps as $s) {
            $startI = $idx($s->planned_start_date);
            $endI   = $idx($s->due_date);
            $dur    = (int) $s->planned_start_date->copy()->startOfDay()->diffInDays($s->due_date->copy()->startOfDay(), false);
            $rag    = $this->pipelines->calculateRag($s);
            $common = [
                'id'            => $s->id,
                'name'          => $s->name,
                'start_index'   => $startI,
                'end_index'     => $endI,
                'duration_days' => $dur,
                'is_milestone'  => (bool) $s->is_milestone,
                'status'        => $s->status,
                'rag'           => $rag,
                'colour'        => Dr1PipelineService::ragColour($rag),
                'na'            => $s->status === 'skipped' && $s->na_reason,
                'blocked'       => method_exists($s, 'blockedByLabel') ? $s->blockedByLabel() : null,
                'draggable'     => ! in_array($s->status, ['completed', 'skipped'], true),
            ];

            if ($dur > 0) {
                $bars[] = $common + ['row' => 0]; // row assigned below
            }
            // A milestone is a gate diamond at its END date (achieved). A zero-width non-milestone
            // step also renders as a point so it never vanishes.
            if ($s->is_milestone || $dur === 0) {
                $gates[] = [
                    'id'           => $s->id,
                    'name'         => $s->name,
                    'index'        => $endI,
                    'is_milestone' => (bool) $s->is_milestone,
                ];
            }
        }

        // Greedy interval row-packing: sort by start, drop each bar into the first row whose last bar
        // has already ended (no overlap); else a new row underneath.
        usort($bars, fn ($a, $b) => [$a['start_index'], $a['end_index']] <=> [$b['start_index'], $b['end_index']]);
        $rowEnds = [];
        foreach ($bars as &$bar) {
            $placed = false;
            foreach ($rowEnds as $r => $end) {
                if ($bar['start_index'] >= $end) {
                    $bar['row'] = $r;
                    $rowEnds[$r] = $bar['end_index'];
                    $placed = true;
                    break;
                }
            }
            if (! $placed) {
                $bar['row'] = count($rowEnds);
                $rowEnds[] = $bar['end_index'];
            }
        }
        unset($bar);

        // Anti-overlap: stagger each gate's LABEL onto the first vertical level whose previous label at
        // that level has ended (≥ ~7rem before) — so milestones clustered near a date (Deal Signed /
        // Proof of Funds / Bond Approved all around day 0) read on separate lines instead of mashing.
        usort($gates, fn ($a, $b) => $a['index'] <=> $b['index']);
        $labelGapDays = (int) ceil(112 / self::DAY_WIDTH); // ~112px of label room
        $levelEndsAt  = []; // level => last index a label occupies
        $maxLevel     = 0;
        foreach ($gates as &$g) {
            $level = 0;
            while (isset($levelEndsAt[$level]) && $g['index'] < $levelEndsAt[$level]) {
                $level++;
            }
            $g['label_level'] = $level;
            $levelEndsAt[$level] = $g['index'] + $labelGapDays;
            $maxLevel = max($maxLevel, $level);
        }
        unset($g);

        // Phase bands DERIVED between milestone gates (decision 3). Boundaries = milestone end dates,
        // sorted; each band leads to the gate that closes it. A leading band covers the run-up to the
        // first gate; a trailing band covers work after the last.
        $bands = $this->bands($gates, $totalDays);

        // Activity stream — normalized events (comments now; email/WhatsApp later), positioned by date.
        $events = $this->events->eventsForDeal($deal)->map(function ($e) use ($idx, $totalDays) {
            $i = $idx(Carbon::parse($e->occurredAt));
            return [
                'key'         => $e->key(),
                'type'        => $e->type,
                'index'       => max(0, min($totalDays, $i)), // clamp into the axis; true date in tooltip
                'off_axis'    => $i < 0 || $i > $totalDays,
                'day'         => Carbon::parse($e->occurredAt)->toDateString(),
                'scope'       => $e->scope,
                'step_id'     => $e->stepId,
                'direction'   => $e->direction,
                'author'      => $e->authorName,
                'body'        => $e->body,
                'occurred_at' => Carbon::parse($e->occurredAt)->toDateTimeString(),
            ];
        })->values()->all();

        return [
            'empty'       => false,
            'range_start' => $rangeStart->toDateString(),
            'total_days'  => $totalDays,
            'day_width'   => self::DAY_WIDTH,
            'today_index' => $idx(Carbon::now()),
            'row_count'   => max(1, count($rowEnds)),
            'gates_levels' => $maxLevel + 1,
            'bars'        => $bars,
            'gates'       => $gates,
            'bands'       => $bands,
            'events'      => $events,
        ];
    }

    private function bands(array $gates, int $totalDays): array
    {
        $milestones = collect($gates)->where('is_milestone', true)
            ->sortBy('index')->unique('index')->values();
        if ($milestones->isEmpty()) {
            return [];
        }
        $bands = [];
        $prev = 0;
        foreach ($milestones as $m) {
            if ($m['index'] > $prev) {
                $bands[] = ['start_index' => $prev, 'end_index' => $m['index'], 'label' => '→ ' . $m['name']];
            }
            $prev = $m['index'];
        }
        if ($totalDays > $prev) {
            $last = $milestones->last();
            $bands[] = ['start_index' => $prev, 'end_index' => $totalDays, 'label' => 'After ' . $last['name']];
        }
        return $bands;
    }
}
