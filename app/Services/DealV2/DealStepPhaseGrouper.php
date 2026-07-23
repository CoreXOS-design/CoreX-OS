<?php

namespace App\Services\DealV2;

use App\Models\DealV2\DealStepInstance;
use Illuminate\Support\Collection;

/**
 * AT-334 Phase 5b — classify a new-model deal's steps into the PHASED board layout:
 *
 *   Anchor (Deal Signed)
 *   Stage 1 · Suspensive Conditions   — grouped by condition_key (bond/cash/sale/…),
 *                                        condition_key=NULL non-gate steps → "Compliance"
 *   GATE (Granted)                    — is_grant_marker
 *   Stage 2 · Transfer & Registration — everything DOWNSTREAM of Granted, flat/date-order,
 *                                        with condition_key steps (the cash payments) nested
 *                                        ONE level under their parent (Deeds Office).
 *
 * 100% field-driven (Option 1): anchor = the follows-root; gate = is_grant_marker; Stage 2 =
 * descendants of the gate in the follows graph; Stage 1 = the rest grouped by condition_key.
 * No name-matching. FICA (Seller) follows a Stage-2 step so it lands in Stage 2 with the COCs.
 */
class DealStepPhaseGrouper
{
    private const GROUP_LABELS = [
        'bond'            => 'Bond',
        'cash'            => 'Cash',
        'sale_of_another' => 'Sale of another property',
        'compliance'      => 'FICA / Compliance',
    ];
    private const GROUP_ORDER = ['bond' => 1, 'cash' => 2, 'sale_of_another' => 3, 'compliance' => 9];

    /**
     * @param  iterable<DealStepInstance>  $steps
     * @return array{anchor:?DealStepInstance,stage1:array<int,array{key:string,label:string,steps:array}>,gate:?DealStepInstance,stage2:array<int,array{step:DealStepInstance,children:array}>}
     */
    public function group(iterable $steps): array
    {
        /** @var Collection<int,DealStepInstance> $all */
        $all  = collect($steps)->filter(fn ($s) => $s->deleted_at === null)->values();
        $byId = $all->keyBy('id');

        $childrenOf = [];
        foreach ($all as $s) {
            $p = $s->trigger_step_instance_id;
            if ($p && isset($byId[$p])) {
                $childrenOf[$p][] = $s;
            }
        }

        // date/order comparator for a set of sibling steps
        $order = fn (Collection $c): Collection => $c->sort(function (DealStepInstance $a, DealStepInstance $b) {
            $ad = $a->due_date ? strtotime((string) $a->due_date) : PHP_INT_MAX;
            $bd = $b->due_date ? strtotime((string) $b->due_date) : PHP_INT_MAX;
            return [$ad, (int) $a->position, (int) $a->id] <=> [$bd, (int) $b->position, (int) $b->id];
        })->values();

        // GATE = the grant marker.
        $gate = $all->first(fn (DealStepInstance $s) => (bool) $s->is_grant_marker);

        // Stage 2 = descendants of the gate in the follows graph.
        $stage2Ids = [];
        if ($gate) {
            $stack = [$gate->id];
            while ($stack) {
                $id = array_pop($stack);
                foreach ($childrenOf[$id] ?? [] as $child) {
                    if (! isset($stage2Ids[$child->id])) {
                        $stage2Ids[$child->id] = true;
                        $stack[] = $child->id;
                    }
                }
            }
        }

        // ANCHOR = the follows-root that is neither the gate nor a condition step.
        $anchor = $all->first(function (DealStepInstance $s) use ($byId) {
            $p = $s->trigger_step_instance_id;
            $isRoot = ! $p || ! isset($byId[$p]);
            return $isRoot && ! $s->is_grant_marker && $s->condition_key === null;
        });

        // STAGE 1 = everything that is not the anchor, not the gate, not a Stage-2 descendant.
        $stage1Steps = $all->reject(function (DealStepInstance $s) use ($anchor, $gate, $stage2Ids) {
            return ($anchor && $s->id === $anchor->id)
                || ($gate && $s->id === $gate->id)
                || isset($stage2Ids[$s->id]);
        });

        $buckets = [];
        foreach ($stage1Steps as $s) {
            $buckets[$s->condition_key ?: 'compliance'][] = $s;
        }
        $stage1 = [];
        foreach ($buckets as $key => $list) {
            $stage1[] = [
                'key'   => $key,
                'label' => self::GROUP_LABELS[$key] ?? ucwords(str_replace('_', ' ', $key)),
                'steps' => $order(collect($list))->all(),
            ];
        }
        usort($stage1, fn ($a, $b) => (self::GROUP_ORDER[$a['key']] ?? 5) <=> (self::GROUP_ORDER[$b['key']] ?? 5));

        // STAGE 2 = descendants of the gate. condition_key steps (the payments) nest ONE level
        // under their parent; everything else is a flat, date-ordered sequence.
        $stage2Steps = $all->filter(fn (DealStepInstance $s) => isset($stage2Ids[$s->id]));
        $nested      = $stage2Steps->filter(fn (DealStepInstance $s) => $s->condition_key !== null);
        $flat        = $order($stage2Steps->filter(fn (DealStepInstance $s) => $s->condition_key === null));
        $flatIds     = $flat->pluck('id')->flip();

        $childrenByParent = [];
        foreach ($nested as $n) {
            $childrenByParent[$n->trigger_step_instance_id][] = $n;
        }

        $stage2 = [];
        foreach ($flat as $s) {
            $stage2[] = [
                'step'     => $s,
                'children' => $order(collect($childrenByParent[$s->id] ?? []))->all(),
            ];
        }
        // Fallback — a nested step whose parent isn't in the flat list is still rendered (flat),
        // never dropped.
        foreach ($nested as $n) {
            if (! isset($flatIds[$n->trigger_step_instance_id])) {
                $stage2[] = ['step' => $n, 'children' => []];
            }
        }

        return ['anchor' => $anchor, 'stage1' => $stage1, 'gate' => $gate, 'stage2' => $stage2];
    }
}
