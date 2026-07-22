<?php

namespace App\Services\DealV2;

use App\Models\Deal;
use App\Models\DealV2\DealStepInstance;
use Carbon\Carbon;

/**
 * AT-334 Phase 3 — the follows-based Due-date cascade for new-model deals.
 *
 *   step.Due = (predecessor.Actual if captured, else predecessor.Due) + step.offset
 *
 * Anchor = deals.deal_date, which auto-completes the first "Deal Signed" step (no
 * re-capture). Completing a step early/late re-baselines all downstream Dues.
 * A manually-set Due (due_date_manual) is NEVER overwritten. Predecessor = the
 * step's trigger_step_instance_id ("follows"). Applies ONLY to deals that carry the
 * new model (a Granted marker / condition_key) — existing deals are untouched.
 */
class DealDateCascade
{
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

        $due = [];   // id => Carbon (resolved Due)
        $actual = fn (DealStepInstance $s) => $s->actual_date
            ? Carbon::parse($s->actual_date)->startOfDay()
            : ($s->completed_at ? Carbon::parse($s->completed_at)->startOfDay() : null);

        // Iterative resolve — handles any follows ordering (fwd/back references).
        $guard = 0;
        do {
            $progress = false;
            foreach ($steps as $id => $s) {
                if (isset($due[$id])) {
                    continue;
                }
                $predId = $s->trigger_step_instance_id;
                if (! $predId || ! isset($steps[$predId])) {
                    // No predecessor → baseline off the deal anchor.
                    $due[$id] = (clone $anchor)->addDays((int) $s->days_offset);
                    $progress = true;
                } elseif (isset($due[$predId])) {
                    $pred = $steps[$predId];
                    $base = $actual($pred) ?? $due[$predId];
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
