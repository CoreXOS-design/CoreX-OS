<?php

namespace App\Services\DealV2;

use App\Models\Deal;
use App\Models\DealV2\DealStepInstance;
use Carbon\Carbon;

/**
 * AT-334 Phase 3 — the follows-based Due-date cascade for new-model deals.
 *
 *   step.Due = ( LATEST over predecessors of (Actual if captured, else Due) ) + step.offset
 *
 * Anchor = deals.deal_date, which auto-completes the first "Deal Signed" step (no
 * re-capture). Completing a step early/late re-baselines all downstream Dues.
 * A manually-set Due (due_date_manual) is NEVER overwritten. The predecessor SET is
 * resolved by DealDependencyResolver = the single "follows" pointer
 * (trigger_step_instance_id) UNION the AND-gate fan-in rows. For a single predecessor the
 * LATEST is that one predecessor, so this equals the prior single-follows behaviour exactly
 * (backward compatible). Applies ONLY to deals that carry the new model (a Granted marker /
 * condition_key) — existing deals are untouched.
 */
class DealDateCascade
{
    public function __construct(private readonly DealDependencyResolver $resolver = new DealDependencyResolver()) {}

    /** True when this deal was built by the composable-condition assembler. */
    public function isNewModel(Deal $deal): bool
    {
        return DealStepInstance::where('dr1_deal_id', $deal->id)
            ->where(fn ($q) => $q->where('is_grant_marker', true)->orWhereNotNull('condition_key'))
            ->exists();
    }

    /** Recompute + persist Due for every step of a new-model deal. Safe no-op otherwise. */
    public function recompute(Deal $deal): void
    {
        if (! $this->isNewModel($deal)) {
            return; // guardrail — never rewrite an existing (old-model) deal's dates
        }

        $anchor = $deal->deal_date ? Carbon::parse($deal->deal_date)->startOfDay() : Carbon::now()->startOfDay();

        $steps = DealStepInstance::where('dr1_deal_id', $deal->id)
            ->whereNull('deleted_at')->orderBy('position')->get()->keyBy('id');

        // The predecessor SET per step (primary follows ∪ AND-gate fan-in), resolved once.
        $preds = $this->resolver->predecessorMap($steps);

        $due = [];   // id => Carbon (resolved Due)
        $actual = fn (DealStepInstance $s) => $s->actual_date
            ? Carbon::parse($s->actual_date)->startOfDay()
            : ($s->completed_at ? Carbon::parse($s->completed_at)->startOfDay() : null);

        // Iterative resolve — handles any follows ordering (fwd/back references) and fan-in.
        $guard = 0;
        do {
            $progress = false;
            foreach ($steps as $id => $s) {
                if (isset($due[$id])) {
                    continue;
                }
                $predIds = array_values(array_filter($preds[$id] ?? [], fn ($p) => isset($steps[$p])));
                if (empty($predIds)) {
                    // No predecessor → baseline off the deal anchor.
                    $due[$id] = (clone $anchor)->addDays((int) $s->days_offset);
                    $progress = true;
                } elseif (! array_diff($predIds, array_keys($due))) {
                    // Every predecessor is resolved → base off the LATEST of them.
                    $base = null;
                    foreach ($predIds as $pid) {
                        $candidate = $actual($steps[$pid]) ?? $due[$pid];
                        if ($base === null || $candidate->greaterThan($base)) {
                            $base = $candidate;
                        }
                    }
                    $due[$id] = (clone $base)->addDays((int) $s->days_offset);
                    $progress = true;
                }
            }
            $guard++;
        } while ($progress && $guard < 50);

        foreach ($steps as $id => $s) {
            if ($s->due_date_manual || ! isset($due[$id])) {
                continue; // never clobber a manual Due; skip unresolved (cyclic) safely
            }
            $newDue = $due[$id]->toDateString();
            if ((string) $s->due_date !== $newDue) {
                $s->forceFill(['due_date' => $newDue])->saveQuietly();
            }
        }
    }
}
