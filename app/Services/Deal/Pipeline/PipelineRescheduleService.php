<?php

namespace App\Services\Deal\Pipeline;

use App\Models\Deal;
use App\Models\DealV2\DealActivityLog;
use App\Models\DealV2\DealStepInstance;
use App\Services\Deal\DealPipelineLockService;
use App\Services\Deal\Dr1PipelineService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Pipeline Dashboard Phase 2 — drag-to-reschedule with downstream cascade (spec §4.1).
 *
 * Moving step S to a new planned start slides the WHOLE tile (start + end, duration preserved). Every
 * step transitively DOWNSTREAM of S (trigger chain ∪ deps table) re-anchors to
 * max(predecessor.planned_end), preserving its own duration. Concurrent siblings that are NOT
 * downstream of S stay put. A dependent that is COMPLETED/SKIPPED, or whose date was set by hand
 * (planned_start_manual / due_date_manual), is HELD (not moved) and acts as a fixed anchor for anything
 * below it. This is the runtime re-anchor math (dependencyReadiness) applied to PLANNED dates.
 *
 * commit=false → a dry-run PREVIEW (no writes) for the confirmation dialog; commit=true → applies it.
 */
class PipelineRescheduleService
{
    public function __construct(
        private readonly DealPipelineLockService $lock,
        private readonly Dr1PipelineService $pipelines,
    ) {
    }

    /**
     * @return array{delta_days:int, moved:array<int,array>, held:array<int,array>, committed:bool}
     */
    public function reschedule(DealStepInstance $step, CarbonInterface $newStart, bool $commit, ?int $userId = null): array
    {
        $deal = $step->dr1Deal;
        $this->lock->assertStepUnlocked($step, "Reschedule step \"{$step->name}\"");

        if (in_array($step->status, ['completed', 'skipped'], true)) {
            throw new \DomainException('A completed or N/A step cannot be rescheduled.');
        }
        if (! $step->planned_start_date || ! $step->due_date) {
            throw new \DomainException('This step has no planned span to move.');
        }

        $newStart = Carbon::parse($newStart)->startOfDay();

        // Every live step of the deal, keyed by id, with predecessor sets (primary trigger ∪ deps).
        $steps = DealStepInstance::where('dr1_deal_id', $deal->id)
            ->orderBy('position')->orderBy('id')->get()->keyBy('id');
        $predsOf   = $this->predecessorMap($deal->id, $steps);
        $dependsOn = $this->dependentMap($predsOf);

        // Original span (start,end,duration) per step.
        $orig = [];
        foreach ($steps as $id => $s) {
            $start = $s->planned_start_date ? $s->planned_start_date->copy()->startOfDay() : null;
            $end   = $s->due_date ? $s->due_date->copy()->startOfDay() : null;
            $orig[$id] = [
                'start' => $start,
                'end'   => $end,
                'dur'   => ($start && $end) ? (int) $start->diffInDays($end, false) : 0,
            ];
        }

        $delta = (int) $orig[$step->id]['start']->diffInDays($newStart, false);

        // Working span map — start from current, then move S.
        $work = [];
        foreach ($orig as $id => $o) {
            $work[$id] = ['start' => $o['start'], 'end' => $o['end']];
        }
        $work[$step->id] = ['start' => $newStart, 'end' => $newStart->copy()->addDays($orig[$step->id]['dur'])];

        // The set that may move = S ∪ its transitive dependents; HELD ones anchor but never move.
        $downstream = $this->transitiveDependents($step->id, $dependsOn);
        $held = [];
        foreach ($downstream as $id) {
            $s = $steps[$id];
            $reason = null;
            if (in_array($s->status, ['completed', 'skipped'], true)) {
                $reason = $s->status === 'completed' ? 'completed' : 'N/A';
            } elseif ($s->planned_start_manual || $s->due_date_manual) {
                $reason = 'date set by hand';
            } elseif (! $orig[$id]['start'] || ! $orig[$id]['end']) {
                // Never been given a real start/due date (still only a display projection, per
                // PipelineTimelineService::projectedSpan) — nothing real to slide, and re-anchoring it
                // here would fabricate a committed due date as a side effect of dragging an unrelated
                // ancestor. Leave it for its own "Edit dates" or the natural cascade instead.
                $reason = 'not yet dated';
            }
            if ($reason) {
                $held[$id] = $reason;
            }
        }

        // Relaxation: re-anchor each movable dependent to max(predecessor.end), preserving its own
        // duration, until nothing changes. Converges on the DAG within depth passes.
        $movable = array_values(array_filter($downstream, fn ($id) => ! isset($held[$id])));
        $passes  = count($steps) + 1;
        for ($p = 0; $p < $passes; $p++) {
            $changed = false;
            foreach ($movable as $id) {
                $preds = $predsOf[$id] ?? [];
                if (empty($preds)) {
                    continue;
                }
                $anchor = null;
                foreach ($preds as $pid) {
                    if (! isset($work[$pid])) {
                        continue;
                    }
                    $anchor = $anchor === null ? $work[$pid]['end'] : $work[$pid]['end']->max($anchor);
                }
                if ($anchor === null) {
                    continue;
                }
                if (! $work[$id]['start']->equalTo($anchor)) {
                    $work[$id]['start'] = $anchor->copy();
                    $work[$id]['end']   = $anchor->copy()->addDays($orig[$id]['dur']);
                    $changed = true;
                }
            }
            if (! $changed) {
                break;
            }
        }

        // What actually moved (start OR end differs from original). $work/$orig hold a NULL start/end
        // for every step in the deal that has no real dates yet (undated steps outside the dragged
        // chain entirely, not just the held ones above) — Carbon's equalTo() has no null-safe form, so
        // compare null-aware instead of calling it on a possibly-null value.
        $sameDate = fn (?CarbonInterface $a, ?CarbonInterface $b) => $a === null ? $b === null : ($b !== null && $a->equalTo($b));
        $moved = [];
        foreach ($work as $id => $w) {
            if ($sameDate($w['start'], $orig[$id]['start']) && $sameDate($w['end'], $orig[$id]['end'])) {
                continue;
            }
            $moved[$id] = [
                'id'         => $id,
                'name'       => $steps[$id]->name,
                'old_start'  => $orig[$id]['start']->toDateString(),
                'new_start'  => $w['start']->toDateString(),
                'old_end'    => $orig[$id]['end']->toDateString(),
                'new_end'    => $w['end']->toDateString(),
                'is_dragged' => $id === $step->id,
            ];
        }

        if ($commit && $moved) {
            DB::transaction(function () use ($moved, $work, $steps, $step, $deal, $userId, $delta) {
                foreach ($moved as $id => $m) {
                    $s   = $steps[$id];
                    $due = $work[$id]['end'];
                    $attrs = [
                        'planned_start_date' => $work[$id]['start'],
                        'due_date'           => $due,
                        'current_rag'        => $this->pipelines->calculateRag($s, $due),
                    ];
                    // The dragged step is an explicit AGENT placement — pin it so re-projection /
                    // activation never clobbers it. Cascaded dependents are system-derived, so their
                    // manual flags stay off (a later real activation re-anchors them naturally).
                    if ($id === $step->id) {
                        $attrs['planned_start_manual'] = true;
                        $attrs['due_date_manual']      = true;
                    }
                    $s->forceFill($attrs)->save();
                }

                $others = count($moved) - 1;
                DealActivityLog::create([
                    'agency_id'             => $deal->agency_id,
                    'deal_id'               => null,
                    'dr1_deal_id'           => $deal->id,
                    'deal_step_instance_id' => $step->id,
                    'user_id'               => $userId,
                    'action'                => 'step_rescheduled',
                    'description'           => "Step \"{$step->name}\" rescheduled ({$this->signed($delta)} day"
                        . (abs($delta) === 1 ? '' : 's') . ')'
                        . ($others > 0 ? " — {$others} downstream step" . ($others === 1 ? '' : 's') . ' cascaded' : ''),
                    'created_at'            => now(),
                ]);
            });
        }

        return [
            'delta_days' => $delta,
            'moved'      => array_values($moved),
            'held'       => array_map(fn ($id) => [
                'id'     => $id,
                'name'   => $steps[$id]->name,
                'reason' => $held[$id],
            ], array_keys($held)),
            'committed'  => $commit && (bool) $moved,
        ];
    }

    /** @return array<int,int[]> step id => predecessor ids (primary trigger ∪ deps table). */
    private function predecessorMap(int $dealId, $steps): array
    {
        $map = [];
        foreach ($steps as $id => $s) {
            $map[$id] = [];
            if ($s->trigger_step_instance_id && isset($steps[$s->trigger_step_instance_id])) {
                $map[$id][] = (int) $s->trigger_step_instance_id;
            }
        }
        $rows = DB::table('deal_step_instance_dependencies')
            ->whereIn('deal_step_instance_id', $steps->keys()->all())
            ->get(['deal_step_instance_id', 'depends_on_step_instance_id']);
        foreach ($rows as $r) {
            $dep = (int) $r->deal_step_instance_id;
            $on  = (int) $r->depends_on_step_instance_id;
            if (isset($steps[$dep], $steps[$on]) && ! in_array($on, $map[$dep], true)) {
                $map[$dep][] = $on;
            }
        }
        return $map;
    }

    /** @return array<int,int[]> predecessor id => the ids that depend ON it (reverse edges). */
    private function dependentMap(array $predsOf): array
    {
        $rev = [];
        foreach ($predsOf as $id => $preds) {
            foreach ($preds as $pid) {
                $rev[$pid][] = $id;
            }
        }
        return $rev;
    }

    /** All steps transitively downstream of $rootId (breadth-first, cycle-safe). @return int[] */
    private function transitiveDependents(int $rootId, array $dependsOn): array
    {
        $seen  = [];
        $queue = $dependsOn[$rootId] ?? [];
        while ($queue) {
            $id = array_shift($queue);
            if (isset($seen[$id]) || $id === $rootId) {
                continue;
            }
            $seen[$id] = true;
            foreach ($dependsOn[$id] ?? [] as $next) {
                $queue[] = $next;
            }
        }
        return array_keys($seen);
    }

    private function signed(int $n): string
    {
        return ($n >= 0 ? '+' : '') . $n;
    }
}
