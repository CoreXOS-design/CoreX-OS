<?php

namespace App\Services\Rentals;

use App\Models\Rental;
use Carbon\Carbon;

class RentalWorksheetInclusionService
{
    /**
     * Calculate rentals inclusion totals for a branch over a period.
     *
     * Inclusion rule:
     * - rentals.is_active = 1
     * - lease_start_date <= period_end
     * - and (
     *      is_month_to_month = 1
     *      OR lease_end_date IS NULL
     *      OR lease_end_date >= period_start
     *   )
     *
     * Amount version selection rule (per rental):
     * - latest rental_amount_versions row where effective_from <= period_end
     *
     * Returns:
     * - active_rentals_count
     * - rental_assist_count
     * - total_commission_excl
     */
    public function calculateForBranchPeriod(int $branchId, $periodStart, $periodEnd): array
    {
        $start = Carbon::parse($periodStart)->startOfDay();
        $end = Carbon::parse($periodEnd)->endOfDay();

        $rentals = Rental::query()
            ->where('branch_id', $branchId)
            ->where('is_active', 1)
            ->whereDate('lease_start_date', '<=', $end->toDateString())
            ->where(function ($q) use ($start) {
                $q->where('is_month_to_month', 1)
                  ->orWhereNull('lease_end_date')
                  ->orWhereDate('lease_end_date', '>=', $start->toDateString());
            })
            ->with([
                'amountVersions' => function ($q) use ($end) {
                    $q->whereDate('effective_from', '<=', $end->toDateString())
                      ->orderByDesc('effective_from');
                }
            ])
            ->get();

        $activeCount = 0;
        $assistCount = 0;
        $totalCommissionExcl = 0.0;

        foreach ($rentals as $rental) {
            $version = $rental->amountVersions->first(); // already filtered <= period_end and ordered desc

            // If no version existed yet as of period end, exclude from sums (but still a rental record)
            if (!$version) {
                continue;
            }

            $activeCount++;

            if ((bool)($rental->is_rental_assist ?? false)) {
                $assistCount++;
            }

            $totalCommissionExcl += (float)($version->commission_excl ?? 0);
        }

        return [
            'active_rentals_count' => (int)$activeCount,
            'rental_assist_count' => (int)$assistCount,
            'total_commission_excl' => (float)$totalCommissionExcl,
        ];
    }

    /**
     * Calculate rentals inclusion totals for a specific user within a branch over a period.
     *
     * User rule:
     * - only rentals linked to user via rental_agents
     *
     * Split rule:
     * - commission_excl is split equally across all linked agents for that rental (agents_count)
     *
     * Returns:
     * - active_rentals_count
     * - rental_assist_count
     * - total_commission_excl (user portion)
     */
    public function calculateForUserBranchPeriod(int $userId, int $branchId, $periodStart, $periodEnd): array
    {
        $start = Carbon::parse($periodStart)->startOfDay();
        $end = Carbon::parse($periodEnd)->endOfDay();

        $rentals = Rental::query()
            ->where('branch_id', $branchId)
            ->where('is_active', 1)
            ->whereDate('lease_start_date', '<=', $end->toDateString())
            ->where(function ($q) use ($start) {
                $q->where('is_month_to_month', 1)
                  ->orWhereNull('lease_end_date')
                  ->orWhereDate('lease_end_date', '>=', $start->toDateString());
            })
            ->whereHas('agents', function ($q) use ($userId) {
                $q->where('users.id', $userId);
            })
            ->withCount('agents')
            ->with([
                'amountVersions' => function ($q) use ($end) {
                    $q->whereDate('effective_from', '<=', $end->toDateString())
                      ->orderByDesc('effective_from');
                }
            ])
            ->get();

        $activeCount = 0;
        $assistCount = 0;
        $totalCommissionExcl = 0.0;

        foreach ($rentals as $rental) {
            $version = $rental->amountVersions->first(); // filtered <= period_end and ordered desc
            if (!$version) {
                continue;
            }

            $activeCount++;

            if ((bool)($rental->is_rental_assist ?? false)) {
                $assistCount++;
            }

            $agentCount = (int)($rental->agents_count ?? 0);
            if ($agentCount < 1) $agentCount = 1;

            $totalCommissionExcl += ((float)($version->commission_excl ?? 0)) / $agentCount;
        }

        return [
            'active_rentals_count' => (int)$activeCount,
            'rental_assist_count' => (int)$assistCount,
            'total_commission_excl' => (float)$totalCommissionExcl,
        ];
    }

}
