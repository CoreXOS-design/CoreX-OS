<?php

namespace App\Services\DealV2;

use App\Models\DealV2\DealStepInstance;
use Illuminate\Support\Facades\DB;

/**
 * AT-334 (concurrent-lanes rework) — the ONE place that resolves a new-model deal's
 * predecessor SET per step, so the cascade, the reorder tree, the phase grouper and the
 * lane/convergence composer all read the SAME graph.
 *
 * A step's predecessor set = the single primary "follows" pointer
 * (`trigger_step_instance_id`) UNION the additional AND-gate predecessors held in the
 * EXISTING `deal_step_instance_dependencies` table (AT-158 WS-V1). No new table: the
 * fan-in model already exists and its documented semantics ("activates only when its
 * primary trigger AND every additional dependency are complete; its clock starts from the
 * LATEST of those completions") is exactly the generalized cascade this rework needs.
 *
 * Backward compatible by construction: a single-follows step has no dependency rows, so
 * its set is the one-element {trigger} — identical to the old single-predecessor behaviour.
 * Only in-set, live (non-deleted) predecessors are returned; dangling edges are dropped.
 */
class DealDependencyResolver
{
    /**
     * Map every step to its predecessor ids (union of primary trigger + AND-gate deps),
     * restricted to the given live step set. Resolved in ONE query over the deps table.
     *
     * @param  iterable<DealStepInstance>  $steps  live steps of a single deal
     * @return array<int,int[]>  stepId => [predecessorId, ...]
     */
    public function predecessorMap(iterable $steps): array
    {
        $collection = collect($steps)->filter(fn ($s) => $s->deleted_at === null)->values();
        $ids   = $collection->pluck('id')->map(fn ($i) => (int) $i)->all();
        $inSet = array_flip($ids);

        // Additional AND-gate predecessors from the existing fan-in table (one query).
        // DB::table bypasses the global scope, but every id here is already deal-scoped,
        // so no cross-agency leak is possible.
        $extra = [];
        if (! empty($ids)) {
            $rows = DB::table('deal_step_instance_dependencies')
                ->whereIn('deal_step_instance_id', $ids)
                ->get(['deal_step_instance_id', 'depends_on_step_instance_id']);
            foreach ($rows as $r) {
                $dep = (int) $r->depends_on_step_instance_id;
                if (isset($inSet[$dep])) {
                    $extra[(int) $r->deal_step_instance_id][] = $dep;
                }
            }
        }

        $map = [];
        foreach ($collection as $s) {
            $set = [];
            $primary = $s->trigger_step_instance_id ? (int) $s->trigger_step_instance_id : null;
            if ($primary !== null && isset($inSet[$primary])) {
                $set[$primary] = true;
            }
            foreach ($extra[(int) $s->id] ?? [] as $dep) {
                $set[$dep] = true;
            }
            $map[(int) $s->id] = array_keys($set);
        }

        return $map;
    }
}
