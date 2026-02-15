<?php

namespace App\Services\Admin;

use App\Models\MonthlyTargetGoal;
use App\Models\Target;
use App\Models\User;

class MonthlyGoalService
{
    /**
     * Resolve effective monthly goal for a user.
     *
     * Priority:
     * 1) User goal (monthly_target_goals.user_id = user)
     * 2) Branch goal (user_id NULL, branch_id = user's branch)
     * 3) Company/global goal (user_id NULL, branch_id NULL)
     * 4) Fallback: existing per-user targets row (targets table)
     *
     * Returns: ['scope' => string, 'listings_target' => int, 'deals_target' => int, 'value_target' => float]
     */
    public function resolveForUser(User $user, string $period): array
    {
        $period = trim((string)$period);

        // 1) User goal
        $g = MonthlyTargetGoal::query()
            ->where('period', $period)
            ->where('user_id', (int)$user->id)
            ->orderByDesc('id')
            ->first();

        if ($g) {
            return [
                'scope' => 'user',
                'listings_target' => (int)$g->listings_target,
                'deals_target' => (int)$g->deals_target,
                'value_target' => (float)$g->value_target,
            ];
        }

        // 2) Branch goal
        $branchId = (int)($user->branch_id ?? 0);
        if ($branchId > 0) {
            $g = MonthlyTargetGoal::query()
                ->where('period', $period)
                ->whereNull('user_id')
                ->where('branch_id', $branchId)
                ->orderByDesc('id')
                ->first();

            if ($g) {
                return [
                    'scope' => 'branch',
                    'listings_target' => (int)$g->listings_target,
                    'deals_target' => (int)$g->deals_target,
                    'value_target' => (float)$g->value_target,
                ];
            }
        }

        // 3) Company/global goal
        $g = MonthlyTargetGoal::query()
            ->where('period', $period)
            ->whereNull('user_id')
            ->whereNull('branch_id')
            ->orderByDesc('id')
            ->first();

        if ($g) {
            return [
                'scope' => 'company',
                'listings_target' => (int)$g->listings_target,
                'deals_target' => (int)$g->deals_target,
                'value_target' => (float)$g->value_target,
            ];
        }

        // 4) Fallback to existing per-user targets row
        $t = Target::query()
            ->where('period', $period)
            ->where('user_id', (int)$user->id)
            ->first();

        if ($t) {
            return [
                'scope' => 'fallback_targets',
                'listings_target' => (int)$t->listings_target,
                'deals_target' => (int)$t->deals_target,
                'value_target' => (float)$t->value_target,
            ];
        }

        return [
            'scope' => 'none',
            'listings_target' => 0,
            'deals_target' => 0,
            'value_target' => 0.0,
        ];
    }
}
