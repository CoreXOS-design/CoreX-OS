<?php

namespace App\Services\DealV2;

use App\Models\Deal;
use App\Models\DealV2\DealStepInstance;

/**
 * AT-334 Phase 5 — order a new-model deal's steps by the FOLLOWS graph, and expose the
 * same order as an INDENTED TREE for the pipeline board.
 *
 * The pipeline is tree-shaped (steps branch), not a linear chain. The order is a
 * depth-first PRE-ORDER of the follows graph (parent = trigger_step_instance_id): visit
 * a step, then recurse into ITS dependents BEFORE the next sibling — so a dependency
 * chain reads contiguously, before the next parallel branch, never interleaved by date.
 * SIBLINGS (same predecessor) and ROOTS (no follows) tie-break by cascaded Due, then
 * days_offset, then existing position, then id.
 *
 * GUARDRAIL: reorderByFollows only touches composable (new-model) deals — old-model /
 * template deals are never renumbered. treeRows() is a pure read (no writes) used by the
 * board to draw the ├→ └→ │ connectors.
 */
class DealStepReorderService
{
    public function __construct(private DealDateCascade $cascade) {}

    /** Persist `position` (10-step increments) in follows-tree pre-order. New-model only. */
    public function reorderByFollows(Deal $deal): void
    {
        if (! $this->cascade->isNewModel($deal)) {
            return; // never reorder an existing (old-model) deal
        }

        $steps = DealStepInstance::where('dr1_deal_id', $deal->id)->whereNull('deleted_at')->get();
        if ($steps->isEmpty()) {
            return;
        }

        $pos = 0;
        foreach ($this->dfs($steps) as $node) {
            $pos += 10;
            $s = $node['step'];
            if ((int) $s->position !== $pos) {
                $s->forceFill(['position' => $pos])->saveQuietly();
            }
        }
    }

    /**
     * Render rows in tree order: [{id, depth, gutter}] where gutter is the box-drawing
     * connector prefix (monospace) for the row. Pure computation — no DB writes.
     *
     * @param  iterable<DealStepInstance>  $steps
     * @return array<int,array{id:int,depth:int,gutter:string}>
     */
    public function treeRows(iterable $steps): array
    {
        $collection = collect($steps)->filter(fn ($s) => $s->deleted_at === null)->values();
        $out = [];
        foreach ($this->dfs($collection) as $node) {
            $flags = $node['flags'];
            if ($node['isRoot']) {
                $gutter = '';
            } else {
                $gutter = '';
                foreach ($flags as $ancestorHasMore) {
                    $gutter .= $ancestorHasMore ? '│  ' : '   ';
                }
                $gutter .= $node['isLast'] ? '└→ ' : '├→ ';
            }
            $out[] = ['id' => (int) $node['step']->id, 'depth' => $node['depth'], 'gutter' => $gutter];
        }
        return $out;
    }

    /**
     * Depth-first pre-order over the follows graph.
     *
     * @param  \Illuminate\Support\Collection<int,DealStepInstance>  $steps
     * @return array<int,array{step:DealStepInstance,depth:int,isRoot:bool,isLast:bool,flags:array<int,bool>}>
     */
    private function dfs($steps): array
    {
        $byId     = $steps->keyBy('id');
        $children = [];   // predecessorId => [child steps]
        $roots    = [];   // steps with no (in-set) predecessor

        foreach ($steps as $s) {
            $pred = $s->trigger_step_instance_id;
            if ($pred && isset($byId[$pred])) {
                $children[$pred][] = $s;
            } else {
                $roots[] = $s;
            }
        }

        // Sibling / root ordering: Due asc (undated last) → offset → position → id.
        $sortSiblings = function (array $arr): array {
            usort($arr, function (DealStepInstance $a, DealStepInstance $b) {
                $ad = $a->due_date ? strtotime((string) $a->due_date) : PHP_INT_MAX;
                $bd = $b->due_date ? strtotime((string) $b->due_date) : PHP_INT_MAX;
                return [$ad, (int) $a->days_offset, (int) $a->position, (int) $a->id]
                   <=> [$bd, (int) $b->days_offset, (int) $b->position, (int) $b->id];
            });
            return $arr;
        };

        $out     = [];
        $visited = [];
        $walk = function (DealStepInstance $step, array $flags, bool $isLast, bool $isRoot)
            use (&$walk, &$out, &$visited, &$children, $sortSiblings): void {
            if (isset($visited[$step->id])) {
                return; // cycle guard
            }
            $visited[$step->id] = true;
            $out[] = [
                'step'   => $step,
                'depth'  => $isRoot ? 0 : count($flags) + 1,
                'isRoot' => $isRoot,
                'isLast' => $isLast,
                'flags'  => $flags,
            ];
            // Columns handed to THIS node's children: root contributes none (its children
            // start at column 0); otherwise carry a continuation bar iff this node has a
            // sibling still below it.
            $childFlags = $isRoot ? [] : array_merge($flags, [! $isLast]);
            $kids = $sortSiblings($children[$step->id] ?? []);
            $n = count($kids);
            foreach ($kids as $i => $kid) {
                $walk($kid, $childFlags, $i === $n - 1, false);
            }
        };

        $rootsSorted = $sortSiblings($roots);
        $rn = count($rootsSorted);
        foreach ($rootsSorted as $i => $root) {
            $walk($root, [], $i === $rn - 1, true);
        }

        // Cyclic / unreachable leftovers — appended as roots, never dropped.
        foreach ($steps as $s) {
            if (! isset($visited[$s->id])) {
                $visited[$s->id] = true;
                $out[] = ['step' => $s, 'depth' => 0, 'isRoot' => true, 'isLast' => true, 'flags' => []];
            }
        }

        return $out;
    }
}
