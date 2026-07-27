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
