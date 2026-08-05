<?php

namespace App\Services\Performance;

use App\Models\UserBranchHistory;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * AT-366 (Q4) — point-in-time branch attribution: which branch was an agent in
 * at a given moment, from user_branch_history. Falls back to the agent's current
 * branch for any moment before recorded history exists (the enabler only started
 * capturing moves now, so most historical activity resolves to current branch).
 *
 * Per-user history is memoised so a whole-cohort rollup issues one query per user.
 */
class BranchAttributionResolver
{
    /** @var array<int, Collection> */
    private array $historyCache = [];

    /**
     * @param  int  $userId
     * @param  CarbonInterface  $at              the activity moment
     * @param  int|null  $currentBranchId        the agent's branch NOW (caller-supplied to avoid a scope-bypass read)
     */
    public function branchAt(int $userId, CarbonInterface $at, ?int $currentBranchId): ?int
    {
        $moves = $this->history($userId);

        if ($moves->isEmpty()) {
            return $currentBranchId;
        }

        // Before the first recorded move → the branch they were in before it.
        if ($at->lt($moves->first()->moved_at)) {
            return $moves->first()->from_branch_id ?? $currentBranchId;
        }

        // The most recent move at or before the moment.
        $applicable = $moves->last(fn ($m) => $m->moved_at->lte($at));

        return $applicable ? $applicable->to_branch_id : $currentBranchId;
    }

    private function history(int $userId): Collection
    {
        return $this->historyCache[$userId] ??= UserBranchHistory::query()
            ->where('user_id', $userId)
            ->orderBy('moved_at')
            ->get();
    }
}
