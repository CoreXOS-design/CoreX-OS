<?php

declare(strict_types=1);

namespace App\Services\RoadToSold;

use App\Models\MonthlyTargetGoal;
use App\Models\User;
use App\Models\Worksheet;

/**
 * The read interface My Portal uses for budgets (agency-tracker-rehome.md section 4.3).
 *
 * Until the Road to Sold settings tables exist, the answers come from the existing stores - the
 * interface is what My Portal depends on, so moving the data later changes nothing above it:
 *   personal / business budget  <- worksheets.personal_net_target / business_net_target (one row per user per month)
 *   branch requirement          <- monthly_target_goals.branch_budget (one row per branch per month)
 * The "want" figure is not part of the budget any more (ruling A).
 *
 * READ ONLY. Never writes, never creates a month's row (the old Worksheet did, on a page visit).
 */
final class BudgetService
{
    public const SOURCE_USER = 'user';
    public const SOURCE_CARRIED = 'carried_forward';
    public const SOURCE_NONE = 'none';

    /**
     * @return array{personalBudget: float, businessBudget: float, total: float, source: string, setForPeriod: ?string}
     *
     * source 'user'            = the agent's own figures for that month.
     * source 'carried_forward' = the current month only, when nothing is set for it yet: the latest earlier
     *                            month's figures, which is what the old Worksheet copied forward on a visit.
     * source 'none'            = nothing set (a past month with no row is an empty state, never zeros shown as data).
     */
    public function forUser(User $u, string $period): array
    {
        $none = ['personalBudget' => 0.0, 'businessBudget' => 0.0, 'total' => 0.0, 'source' => self::SOURCE_NONE, 'setForPeriod' => null];
        [$row, $source] = $this->resolve($u, $period);
        if (! $row) {
            return $none;
        }

        return [
            'personalBudget' => (float) $row->personal_net_target,
            'businessBudget' => (float) $row->business_net_target,
            'total'          => (float) $row->personal_net_target + (float) $row->business_net_target,
            'source'         => $source,
            'setForPeriod'   => (string) $row->period,
        ];
    }

    /**
     * The stored budget row itself (it also carries the planning figures - average sale price, commission %, PAYE,
     * agent split, correctly-priced % - that today's requirement calculation works from), under the same rules as
     * forUser(): the month's own row, the current month carrying the latest earlier row forward, otherwise null.
     */
    public function worksheetFor(User $u, string $period): ?Worksheet
    {
        return $this->resolve($u, $period)[0];
    }

    /** @return array{0: ?Worksheet, 1: string} */
    private function resolve(User $u, string $period): array
    {
        $agencyId = (int) ($u->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return [null, self::SOURCE_NONE];
        }

        $q = Worksheet::query()->where('agency_id', $agencyId)->where('user_id', $u->id);
        $row = (clone $q)->where('period', $period)->first();
        $source = self::SOURCE_USER;

        if (! $row && $period === now()->format('Y-m')) {
            $row = (clone $q)->where('period', '<', $period)->orderByDesc('period')->first();
            $source = self::SOURCE_CARRIED;
        }
        if (! $row || ((float) $row->personal_net_target <= 0 && (float) $row->business_net_target <= 0)) {
            return [null, self::SOURCE_NONE];
        }

        return [$row, $source];
    }

    /**
     * What the branch needs and what that is per agent.
     *
     * @return array{requirement: float, requiredPerAgent: float, agents: int}
     */
    public function requirementFor(int $agencyId, ?int $branchId, string $period): array
    {
        $zero = ['requirement' => 0.0, 'requiredPerAgent' => 0.0, 'agents' => 0];
        if ($agencyId <= 0 || ! $branchId) {
            return $zero;
        }

        $goal = MonthlyTargetGoal::query()->where('agency_id', $agencyId)->where('branch_id', $branchId)
            ->where('period', $period)->first();
        $budget = (float) ($goal?->branch_budget ?? 0);
        if ($budget <= 0) {
            return $zero;
        }

        $agents = User::query()->where('agency_id', $agencyId)->where('branch_id', $branchId)
            ->where('is_active', 1)->where('counts_for_branch_split', 1)->count();

        return [
            'requirement'      => $budget,
            'requiredPerAgent' => $agents > 0 ? round($budget / $agents, 2) : 0.0,
            'agents'           => $agents,
        ];
    }
}
