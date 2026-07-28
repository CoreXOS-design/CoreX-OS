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

        // Every ACTIVE suspensive step. The Granted marker's date = the LATEST of these (Johan
        // rule): the deal grants only once every suspensive condition is due/met, so Stage-2
        // steps (which all chain through the marker) compute forward from grant and never predate
        // it. Derived from the suspensive SET directly — independent of the marker's follows/deps
        // wiring, which on a multi-suspensive deal (e.g. bond + cash) could under-project to the
        // earliest suspensive instead of the latest.
        $suspensiveIds = $steps->filter(fn ($s) => $s->is_suspensive)->keys()->map(fn ($i) => (int) $i)->all();

        $actual = fn (DealStepInstance $s) => $s->actual_date
            ? Carbon::parse($s->actual_date)->startOfDay()
            : ($s->completed_at ? Carbon::parse($s->completed_at)->startOfDay() : null);

        // Two maps: $computed = the Due we would WRITE (non-manual steps only); $out = the date a
        // step CONTRIBUTES to its successors = Actual (if captured) ELSE a manually-set Due (now
        // honoured) ELSE the computed Due. Propagating manual Dues — previously only Actuals
        // propagated — is what lets an editable condition date (e.g. a 14-day bond due) drive
        // every downstream step. A step with neither Actual nor manual Due outputs its computed
        // Due exactly as before, so the common case is unchanged.
        $computed = [];  // id => Carbon (the recomputed Due)
        $out      = [];  // id => Carbon (what this step contributes downstream)

        $manualDue = fn (DealStepInstance $s) => ($s->due_date_manual && $s->due_date)
            ? Carbon::parse($s->due_date)->startOfDay() : null;

        // Iterative resolve — handles any follows ordering (fwd/back references) and fan-in.
        $guard = 0;
        do {
            $progress = false;
            foreach ($steps as $id => $s) {
                if (isset($out[$id])) {
                    continue;
                }

                if ($s->is_grant_marker) {
                    // Wait until every suspensive step is resolved, then base off the LATEST.
                    if (! empty($suspensiveIds) && array_diff($suspensiveIds, array_keys($out))) {
                        continue;
                    }
                    $base = null;
                    foreach ($suspensiveIds as $sid) {
                        if ($base === null || $out[$sid]->greaterThan($base)) {
                            $base = $out[$sid];
                        }
                    }
                    $base = $base ?? (clone $anchor); // no suspensive condition → grants on signing
                    $computed[$id] = (clone $base)->addDays((int) $s->days_offset);
                    $out[$id] = $actual($s) ?? $computed[$id];
                    $progress = true;
                    continue;
                }

                $predIds = array_values(array_filter($preds[$id] ?? [], fn ($p) => isset($steps[$p])));
                if (empty($predIds)) {
                    // No predecessor → baseline off the deal anchor.
                    $computed[$id] = (clone $anchor)->addDays((int) $s->days_offset);
                } elseif (! array_diff($predIds, array_keys($out))) {
                    // Every predecessor is resolved → base off the LATEST of their contributions.
                    $base = null;
                    foreach ($predIds as $pid) {
                        if ($base === null || $out[$pid]->greaterThan($base)) {
                            $base = $out[$pid];
                        }
                    }
                    $computed[$id] = (clone $base)->addDays((int) $s->days_offset);
                } else {
                    continue; // predecessors not all resolved yet
                }

                $out[$id] = $actual($s) ?? $manualDue($s) ?? $computed[$id];
                $progress = true;
            }
            $guard++;
        } while ($progress && $guard < 50);

        foreach ($steps as $id => $s) {
            if ($s->due_date_manual || ! isset($computed[$id])) {
                continue; // never clobber a manual Due; skip unresolved (cyclic) safely
            }
            $newDue = $computed[$id]->toDateString();
            if ((string) $s->due_date !== $newDue) {
                $s->forceFill(['due_date' => $newDue])->saveQuietly();
            }
        }
    }
}
