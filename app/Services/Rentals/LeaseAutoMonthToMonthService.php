<?php

namespace App\Services\Rentals;

use App\Models\Agency;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseSetting;
use App\Models\User;
use App\Notifications\LeaseMonthToMonthNotice;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * .ai/specs/leases.md §5.3 — Johan's ruling, 7 Oct 2026: when a lease reaches its end date and NO notice to vacate
 * has been recorded and NO renewal has been recorded, it goes month-to-month AUTOMATICALLY. That is the correct
 * behaviour.
 *
 * "No renewal on record" means: the lease has not been renewed (`renewed_lease_id`) AND no renewal term is in flight
 * against it — any draft chained to it by `previous_lease_id`, which includes a renewal whose agreement is out for
 * e-signing, one signed but not yet active, and one the agent has only just started. A renewal the agent cancelled is
 * not a renewal. A notice (from the tenant or the landlord, any outcome) is `notice_date`.
 *
 * What it does is exactly what the agent's own "Goes month-to-month" does (the flag set, the end date cleared) — no
 * other lease state moves, the property keeps its status (§12.5.3), nothing is expired. It adds one tenancy-log line
 * saying it was automatic and what the end date had been, and tells the agent. Idempotent: a month-to-month lease is
 * never a candidate again, and the conditions are re-checked under a row lock at the moment of the switch.
 *
 * "Reaches its end date" is the agency's own setting (LeaseSetting::monthToMonthAfterEndDaysFor, default 1 = the day
 * after). Always agency by agency, never one bulk query across agencies (the 4 Oct 2026 rule for lease commands).
 *
 * Reversal: the agent's "Reverse month-to-month" undoes the flag as it always has and leaves the lease with no end date;
 * the lease is then never a candidate (no end date), so a reversal is not fought.
 */
class LeaseAutoMonthToMonthService
{
    /**
     * The leases of one agency that are due to switch today (or on `$today`).
     *
     * @return Collection<int, Lease>
     */
    public function dueFor(Agency $agency, ?CarbonInterface $today = null, ?int $onlyLeaseId = null): Collection
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $cutoff = $today->copy()->subDays(LeaseSetting::monthToMonthAfterEndDaysFor($agency->id))->toDateString();

        return Lease::withoutGlobalScopes()
            ->where('agency_id', $agency->id)
            ->whereNull('deleted_at') // withoutGlobalScopes() also drops the soft-delete filter: an archived lease is never touched
            ->when($onlyLeaseId, fn ($q) => $q->whereKey($onlyLeaseId))
            ->where('status', Lease::STATUS_ACTIVE)
            ->where('is_month_to_month', false)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<=', $cutoff)
            ->whereNull('notice_date')
            ->whereNull('renewed_lease_id')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('leases as renewals')
                    ->whereColumn('renewals.previous_lease_id', 'leases.id')
                    ->where('renewals.status', Lease::STATUS_DRAFT)
                    ->whereNull('renewals.deleted_at');
            })
            ->orderBy('end_date')
            ->get();
    }

    /**
     * Switch one lease, if — under a lock, right now — it is still due. Returns whether it switched.
     */
    public function convert(Lease $lease, ?CarbonInterface $today = null): bool
    {
        $today = ($today ?? now())->copy()->startOfDay();

        $switched = DB::transaction(function () use ($lease, $today) {
            /** @var Lease|null $locked */
            $locked = Lease::withoutGlobalScopes()->whereKey($lease->id)->lockForUpdate()->first();
            if (! $locked || ! $this->isDue($locked, $today)) {
                return null;
            }

            $previousEnd = $locked->end_date->copy();
            $grace = LeaseSetting::monthToMonthAfterEndDaysFor($locked->agency_id);

            $locked->update(['is_month_to_month' => true, 'end_date' => null]);

            LeaseEvent::create([
                'lease_id' => $locked->id,
                'event_type' => LeaseEvent::TYPE_MONTH_TO_MONTH_SET,
                'description' => 'Lease went month-to-month automatically — it ended on ' . $previousEnd->format('j M Y')
                    . ' with no notice to vacate and no renewal on record',
                'actor_user_id' => null,
                'metadata' => ['automatic' => true, 'previous_end_date' => $previousEnd->toDateString(), 'after_end_days' => $grace],
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            return ['lease' => $locked->fresh(), 'previous_end' => $previousEnd];
        });

        if ($switched === null) {
            return false;
        }

        $this->tellAgent($switched['lease'], $switched['previous_end']);

        return true;
    }

    /** Every condition, as one question — what both the query above and the locked re-check mean. */
    public function isDue(Lease $lease, CarbonInterface $today): bool
    {
        if ($lease->trashed() || $lease->status !== Lease::STATUS_ACTIVE || $lease->is_month_to_month || ! $lease->end_date
            || $lease->notice_date !== null || $lease->renewed_lease_id !== null) {
            return false;
        }

        $cutoff = $today->copy()->startOfDay()->subDays(LeaseSetting::monthToMonthAfterEndDaysFor($lease->agency_id));
        if ($lease->end_date->copy()->startOfDay()->gt($cutoff)) {
            return false;
        }

        return ! Lease::withoutGlobalScopes()->where('previous_lease_id', $lease->id)->where('status', Lease::STATUS_DRAFT)->exists();
    }

    /** In-app note to the agent who owns the lease. Best effort: a failure here never undoes the switch. */
    private function tellAgent(Lease $lease, CarbonInterface $previousEnd): void
    {
        try {
            $agent = $lease->createdByUser ?? ($lease->property?->agent_id ? User::withoutGlobalScopes()->find($lease->property->agent_id) : null);
            $agent?->notify(new LeaseMonthToMonthNotice($lease, $previousEnd->toDateString()));
        } catch (\Throwable $e) {
            Log::warning('Auto month-to-month: could not tell the agent', ['lease_id' => $lease->id, 'error' => $e->getMessage()]);
        }
    }
}
