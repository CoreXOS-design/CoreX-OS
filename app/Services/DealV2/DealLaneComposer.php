<?php

namespace App\Services\DealV2;

use App\Models\DealV2\DealStepInstance;
use Illuminate\Support\Collection;

/**
 * AT-334 (concurrent-lanes rework) — compose a new-model deal's steps into the CLEAN
 * concurrent-lane board:
 *
 *   Anchor (Deal Signed)
 *   Stage 1 · Suspensive Conditions   — condition lanes converging on …
 *   GATE (Granted)                    — full-width bar (is_grant_marker)
 *   Stage 2 · Transfer & Registration — sequence points + concurrent bands
 *
 * Each stage is decomposed into an ordered list of SEGMENTS:
 *   ['type'=>'sequence','step'=>DealStepInstance]                       — full-width, blue rail
 *   ['type'=>'band','lanes'=>[[DealStepInstance,…],[…]]]                — dashed "◇ concurrent"
 *                                                                          band; each lane is a
 *                                                                          short vertical chain
 *
 * CONVERGENCE RULE (the crux): reading the stage's steps in topological order over the
 * predecessor SET (primary follows ∪ AND-gate fan-in, from DealDependencyResolver, restricted
 * to the stage), a step becomes a full-width SEQUENCE POINT when it is either
 *   (a) a FAN-OUT   — it has ≥2 in-stage successors (parallel lanes branch from it), or
 *   (b) a CONVERGENCE — its predecessor set == the set of all currently-open lane tails
 *                       (≥2 lanes), i.e. it waits on every parallel lane.
 * Otherwise the step extends a lane (its sole predecessor is a single-successor lane tail) or
 * opens a new lane in the current band. The Granted gate is rendered separately, so Stage-1
 * edges INTO the gate are excluded from the Stage-1 graph (the conditions read as lanes that
 * converge on the gate bar beneath them).
 *
 * Pure computation — no DB writes. Degrades gracefully on a single-follows graph (few merges).
 */
class DealLaneComposer
{
    public function __construct(private readonly DealDependencyResolver $resolver = new DealDependencyResolver()) {}

    /**
     * @param  iterable<DealStepInstance>  $steps  all live steps of one new-model deal
     * @return array{anchor:?DealStepInstance,gate:?DealStepInstance,stage1:array,stage2:array}
     */
    public function board(iterable $steps): array
    {
        /** @var Collection<int,DealStepInstance> $all */
        $all  = collect($steps)->filter(fn ($s) => $s->deleted_at === null)->values();
        $byId = $all->keyBy('id');
        $pred = $this->resolver->predecessorMap($all);   // id => [predId,...] (in-set)

        // successor map (invert pred)
        $succ = [];
        foreach ($pred as $id => $ps) {
            foreach ($ps as $p) {
                $succ[$p][] = (int) $id;
            }
        }

        // GATE = the grant marker.
        $gate = $all->first(fn (DealStepInstance $s) => (bool) $s->is_grant_marker);

        // STAGE 2 = everything reachable (successor direction) FROM the gate.
        $stage2Ids = [];
        if ($gate) {
            $stack = [(int) $gate->id];
            while ($stack) {
                $id = array_pop($stack);
                foreach ($succ[$id] ?? [] as $c) {
                    if (! isset($stage2Ids[$c])) {
                        $stage2Ids[$c] = true;
                        $stack[] = $c;
                    }
                }
            }
        }

        // ANCHOR = a follows-root that is neither the gate nor a condition step.
        $anchor = $all->first(function (DealStepInstance $s) use ($pred) {
            return empty($pred[(int) $s->id]) && ! $s->is_grant_marker && $s->condition_key === null;
        });

        // STAGE 1 = the rest (not anchor, not gate, not Stage 2).
        $stage1 = $all->reject(function (DealStepInstance $s) use ($anchor, $gate, $stage2Ids) {
            return ($anchor && $s->id === $anchor->id)
                || ($gate && $s->id === $gate->id)
                || isset($stage2Ids[(int) $s->id]);
        })->values();

        $stage2 = $all->filter(fn (DealStepInstance $s) => isset($stage2Ids[(int) $s->id]))->values();

        return [
            'anchor' => $anchor,
            'gate'   => $gate,
            // Stage 1 excludes the gate from its graph so conditions read as lanes that
            // converge on the gate bar (the gate edge is not an in-stage merge).
            'stage1' => $this->composeStage($stage1, $pred, $byId, $gate ? (int) $gate->id : null),
            'stage2' => $this->composeStage($stage2, $pred, $byId, null),
        ];
    }

    /**
     * Decompose one stage's step set into ordered sequence / band segments.
     *
     * @param  Collection<int,DealStepInstance>  $stageSteps
     * @param  array<int,int[]>                  $predAll     full predecessor map
     * @param  Collection<int,DealStepInstance>  $byId
     * @param  int|null                          $excludeId   a node whose edges are ignored (the gate)
     * @return array<int,array>
     */
    private function composeStage(Collection $stageSteps, array $predAll, Collection $byId, ?int $excludeId): array
    {
        if ($stageSteps->isEmpty()) {
            return [];
        }

        $inSet = $stageSteps->mapWithKeys(fn ($s) => [(int) $s->id => true])->all();

        // Predecessors restricted to this stage (and never the excluded gate).
        $pred = [];
        foreach ($stageSteps as $s) {
            $id = (int) $s->id;
            $pred[$id] = array_values(array_filter(
                $predAll[$id] ?? [],
                fn ($p) => isset($inSet[$p]) && $p !== $excludeId,
            ));
        }

        // In-stage out-degree (how many stage steps depend on this one).
        $outdeg = array_fill_keys(array_keys($inSet), 0);
        foreach ($pred as $id => $ps) {
            foreach ($ps as $p) {
                $outdeg[$p] = ($outdeg[$p] ?? 0) + 1;
            }
        }

        // Longest-path rank from stage roots (memoised).
        $rank = [];
        $rankOf = function (int $id) use (&$rankOf, &$rank, $pred): int {
            if (isset($rank[$id])) {
                return $rank[$id];
            }
            $rank[$id] = 0; // cycle guard
            $max = 0;
            foreach ($pred[$id] ?? [] as $p) {
                $max = max($max, $rankOf($p) + 1);
            }
            return $rank[$id] = $max;
        };
        foreach (array_keys($inSet) as $id) {
            $rankOf($id);
        }

        // Topological order: rank, then Due, then position, then id.
        $ordered = $stageSteps->sort(function (DealStepInstance $a, DealStepInstance $b) use ($rank) {
            $ad = $a->due_date ? strtotime((string) $a->due_date) : PHP_INT_MAX;
            $bd = $b->due_date ? strtotime((string) $b->due_date) : PHP_INT_MAX;
            return [$rank[(int) $a->id], $ad, (int) $a->position, (int) $a->id]
               <=> [$rank[(int) $b->id], $bd, (int) $b->position, (int) $b->id];
        })->values();

        $segments  = [];
        $band      = [];   // list of lanes; each lane = list of DealStepInstance
        $tailLane  = [];   // tailStepId => lane index within $band

        $flush = function () use (&$segments, &$band, &$tailLane) {
            if (! empty($band)) {
                $segments[] = ['type' => 'band', 'lanes' => $band];
            }
            $band = [];
            $tailLane = [];
        };

        foreach ($ordered as $s) {
            $id    = (int) $s->id;
            $preds = $pred[$id];
            $tails = array_keys($tailLane);

            $isConvergence = count($tails) >= 2
                && count($preds) === count($tails)
                && empty(array_diff($tails, $preds)); // preds ⊇ every open tail
            $isFanOut = ($outdeg[$id] ?? 0) >= 2;

            if ($isConvergence || $isFanOut) {
                $flush();
                $segments[] = ['type' => 'sequence', 'step' => $s];
                continue; // a sequence point is not a lane member; successors open a new band
            }

            if (count($preds) === 1 && isset($tailLane[$preds[0]]) && ($outdeg[$preds[0]] ?? 0) === 1) {
                // Extend the lane vertically (single-successor chain).
                $li = $tailLane[$preds[0]];
                unset($tailLane[$preds[0]]);
                $band[$li][] = $s;
                $tailLane[$id] = $li;
            } else {
                // Open a new lane in the current concurrent band.
                $band[] = [$s];
                $tailLane[$id] = count($band) - 1;
            }
        }
        $flush();

        return $segments;
    }
}
