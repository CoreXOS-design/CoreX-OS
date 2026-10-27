<?php

declare(strict_types=1);

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsBudget;
use App\Models\RoadToSold\RtsSetting;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Support\Facades\DB;

/**
 * Budgets and requirements - the one place they are read AND written (agency-tracker-rehome.md 4.3, step 4).
 *
 * Storage is `rts_budgets` (one row per agency / track / who / month, three levels: agent, branch, agency).
 * The old homes - `worksheets` (personal / business budget), `monthly_target_goals.branch_budget` - were moved
 * here by migration 2026_11_27_110000 and are kept, read-only, never written again.
 *
 * READ side never writes and never creates a month's row (the old Worksheet did, on a page visit).
 * WRITE side is the only writer: it restores an archived row instead of colliding with it (BUILD_STANDARD 5a),
 * refuses anything outside the writer's agency, and never hard-deletes.
 * "Who may set whose budget" (own / branch / agency breadth) is decided by the controller from
 * VisibleAgents and the viewer's road_to_sold scope; this service double-checks the agency boundary.
 */
final class BudgetService
{
    public const SOURCE_USER = 'user';
    public const SOURCE_CARRIED = 'carried_forward';
    public const SOURCE_NONE = 'none';

    // ── reads ────────────────────────────────────────────────────────────────────────

    /**
     * @return array{personalBudget: float, businessBudget: float, total: float, source: string, setForPeriod: ?string}
     *
     * source 'user'            = the agent's own figures for that month.
     * source 'carried_forward' = the current month only, when nothing is set for it yet: the latest earlier
     *                            month's figures, which is what the old Worksheet copied forward on a visit.
     * source 'none'            = nothing set (a past month with no row is an empty state, never zeros shown as data).
     */
    public function forUser(User $u, string $period, string $track = RtsSetting::TRACK_SALES): array
    {
        $none = ['personalBudget' => 0.0, 'businessBudget' => 0.0, 'total' => 0.0, 'source' => self::SOURCE_NONE, 'setForPeriod' => null];
        [$row, $source] = $this->resolve($u, $period, $track);
        if (! $row) {
            return $none;
        }

        return [
            'personalBudget' => (float) $row->personal_budget,
            'businessBudget' => (float) $row->business_budget,
            'total'          => (float) $row->personal_budget + (float) $row->business_budget,
            'source'         => $source,
            'setForPeriod'   => (string) $row->period,
        ];
    }

    /**
     * The agent's budget and planning figures as the Worksheet-shaped row today's "what the budget needs"
     * calculation (WorksheetController::calculate) takes - built in memory, never saved. Same month / carry-forward
     * rules as forUser(); null when nothing is set.
     *
     * Planning figures an agency or agent never set stay empty (calculate() then says "cannot be worked out yet");
     * no figure is invented. The one bridge: a sale price the manager set on Worksheet Market
     * (worksheets.avg_sale_price_admin) still wins, because that tool keeps writing it until it moves (step 7).
     */
    public function worksheetFor(User $u, string $period, string $track = RtsSetting::TRACK_SALES): ?Worksheet
    {
        [$row] = $this->resolve($u, $period, $track);
        if (! $row) {
            return null;
        }

        $marketPrice = Worksheet::query()->where('agency_id', $row->agency_id)->where('user_id', $u->id)
            ->where('period', $period)->value('avg_sale_price_admin');

        $w = new Worksheet();
        $w->forceFill([
            'agency_id'                => $row->agency_id,
            'user_id'                  => $u->id,
            'period'                   => (string) $row->period,
            'personal_net_target'      => (float) $row->personal_budget,
            'business_net_target'      => (float) $row->business_budget,
            'want_net_target'          => 0,
            'avg_sale_price'           => (float) ($row->avg_sale_price ?? 0),
            'avg_sale_price_admin'     => $marketPrice !== null ? (float) $marketPrice : null,
            'commission_percent'       => (float) ($row->commission_percent ?? 0),
            'paye_percent'             => (float) ($row->paye_percent ?? 0),
            'agent_split_percent'      => (float) ($row->agent_split_percent ?? 0),
            'correctly_priced_percent' => (float) ($row->correctly_priced_percent ?? 0),
            'current_listings'         => 0,
        ]);

        return $w;
    }

    /** @return array{0: ?RtsBudget, 1: string} */
    private function resolve(User $u, string $period, string $track): array
    {
        $agencyId = (int) ($u->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return [null, self::SOURCE_NONE];
        }

        $q = RtsBudget::query()->where('agency_id', $agencyId)->where('track', $track)
            ->where('scope_key', RtsBudget::keyForUser((int) $u->id));
        $row = (clone $q)->where('period', $period)->first();
        // A month that only holds the agent's share of a branch total (spec 21.3) is not "their budget for the month".
        if ($row && $row->isShareOnly()) {
            $row = null;
        }
        $source = self::SOURCE_USER;

        if (! $row && $period === now()->format('Y-m')) {
            $row = (clone $q)->where('period', '<', $period)->orderByDesc('period')->first();
            $source = self::SOURCE_CARRIED;
        }
        if (! $row || ((float) $row->personal_budget <= 0 && (float) $row->business_budget <= 0)) {
            return [null, self::SOURCE_NONE];
        }

        return [$row, $source];
    }

    /**
     * What the branch needs and what that is per agent.
     *
     * @return array{requirement: float, requiredPerAgent: float, agents: int}
     */
    public function requirementFor(int $agencyId, ?int $branchId, string $period, string $track = RtsSetting::TRACK_SALES, ?int $userId = null): array
    {
        $zero = ['requirement' => 0.0, 'requiredPerAgent' => 0.0, 'agents' => 0];
        if ($agencyId <= 0 || ! $branchId) {
            return $zero;
        }

        $budget = $this->branchRequirement($agencyId, (int) $branchId, $period, $track);
        if ($budget <= 0) {
            return $zero;
        }

        $agents = User::query()->where('agency_id', $agencyId)->where('branch_id', $branchId)
            ->where('is_active', 1)->where('counts_for_branch_split', 1)->count();

        // The agent's own share, when the branch total was split to them by percentage (spec 21.3); otherwise the even split.
        $own = $userId ? $this->agentRequirement($agencyId, $userId, $period, $track) : 0.0;

        return [
            'requirement'      => $budget,
            'requiredPerAgent' => $own > 0 ? $own : ($agents > 0 ? round($budget / $agents, 2) : 0.0),
            'agents'           => $agents,
        ];
    }

    /** What the branch total was split to this agent for the month (0 when it was never split to them). */
    public function agentRequirement(int $agencyId, int $userId, string $period, string $track = RtsSetting::TRACK_SALES): float
    {
        if ($agencyId <= 0) {
            return 0.0;
        }

        return (float) RtsBudget::query()->where('agency_id', $agencyId)->where('track', $track)
            ->where('scope_key', RtsBudget::keyForUser($userId))->where('period', $period)->value('requirement');
    }

    /**
     * Every counting agent's share of a branch for a month (user id => amount), for the screen's starting percentages.
     *
     * @return array<int,float>
     */
    public function agentRequirements(int $agencyId, array $userIds, string $period, string $track = RtsSetting::TRACK_SALES): array
    {
        if ($agencyId <= 0 || $userIds === []) {
            return [];
        }

        return RtsBudget::query()->where('agency_id', $agencyId)->where('track', $track)->where('level', RtsBudget::LEVEL_AGENT)
            ->where('period', $period)->whereIn('user_id', $userIds)->pluck('requirement', 'user_id')
            ->map(fn ($v) => (float) $v)->all();
    }

    /**
     * The ids of the agents a branch total is split between: active, counted in the branch split (the same people
     * requirementFor() divides by).
     *
     * @return int[]
     */
    public function splitAgentIds(int $agencyId, int $branchId): array
    {
        return User::query()->where('agency_id', $agencyId)->where('branch_id', $branchId)
            ->where('is_active', 1)->where('counts_for_branch_split', 1)->orderBy('name')->orderBy('id')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function branchRequirement(int $agencyId, int $branchId, string $period, string $track = RtsSetting::TRACK_SALES): float
    {
        if ($agencyId <= 0) {
            return 0.0;
        }

        return (float) RtsBudget::query()->where('agency_id', $agencyId)->where('track', $track)
            ->where('scope_key', RtsBudget::keyForBranch($branchId))->where('period', $period)->value('requirement');
    }

    public function agencyRequirement(int $agencyId, string $period, string $track = RtsSetting::TRACK_SALES): float
    {
        if ($agencyId <= 0) {
            return 0.0;
        }

        return (float) RtsBudget::query()->where('agency_id', $agencyId)->where('track', $track)
            ->where('scope_key', RtsBudget::KEY_AGENCY)->where('period', $period)->value('requirement');
    }

    /**
     * What the agency needs for a month, and where that figure comes from (agency-tracker-rehome.md 4.3, Johan 23 Oct 2026 review):
     * the agency's OWN figure when one is set; otherwise the branches' requirements added up (the branch-less stray rows are never
     * part of the sum). Nothing is saved - a branch sum is a reading, never a stored number.
     *
     * @return array{own: float, branchSum: float, effective: float, source: string} source = 'own' | 'branches' | 'none'
     */
    public function agencyRequirementDetail(int $agencyId, string $period, string $track = RtsSetting::TRACK_SALES): array
    {
        $own = $this->agencyRequirement($agencyId, $period, $track);
        $sum = round(array_sum($this->branchRequirements($agencyId, $period, $track)), 2);
        [$effective, $source] = $own > 0 ? [$own, 'own'] : ($sum > 0 ? [$sum, 'branches'] : [0.0, 'none']);

        return ['own' => $own, 'branchSum' => $sum, 'effective' => $effective, 'source' => $source];
    }

    /**
     * Every branch's requirement for a month (branch id => amount), for the pages that print one per branch.
     *
     * @return array<int, float>
     */
    public function branchRequirements(int $agencyId, string $period, string $track = RtsSetting::TRACK_SALES): array
    {
        if ($agencyId <= 0) {
            return [];
        }

        return RtsBudget::query()->where('agency_id', $agencyId)->where('track', $track)->where('level', RtsBudget::LEVEL_BRANCH)
            ->where('period', $period)->where('branch_id', '>', 0)->pluck('requirement', 'branch_id')
            ->map(fn ($v) => (float) $v)->all();
    }

    /**
     * The branch row the Branch Performance pages print, in the shape they always read (`branch_budget`, and the
     * three goal figures). Read-only: the old pages created the row on a visit, this never does. The goal figures
     * are the branch's agents' monthly targets added up (Monthly Goals was folded into Targets; its own goal
     * columns held nothing but zero on every agency checked).
     */
    public function branchGoal(int $agencyId, int $branchId, string $period): object
    {
        $sum = $agencyId > 0 ? DB::table('targets')->where('agency_id', $agencyId)->where('branch_id', $branchId)
            ->where('period', $period)->whereNull('deleted_at')
            ->selectRaw('COALESCE(SUM(deals_target),0) d, COALESCE(SUM(listings_target),0) l, COALESCE(SUM(value_target),0) v')->first() : null;

        return (object) [
            'branch_id'       => $branchId,
            'period'          => $period,
            'branch_budget'   => $this->branchRequirement($agencyId, $branchId, $period),
            'deals_target'    => (int) ($sum->d ?? 0),
            'listings_target' => (int) ($sum->l ?? 0),
            'value_target'    => (float) ($sum->v ?? 0),
        ];
    }

    // ── writes ───────────────────────────────────────────────────────────────────────

    /**
     * Set an agent's budget for a month. `$personal` / `$business` null = leave that figure as it is (a form that
     * never rendered it must not zero it); a number sets it (0 allowed). `$planning` carries only the planning
     * figures the form posted: key => number, or key => null to clear that one; keys not present are left alone.
     *
     * @param  array<string, float|null> $planning
     */
    public function saveAgent(User $actor, User $agent, string $period, ?float $personal, ?float $business, array $planning = [], string $track = RtsSetting::TRACK_SALES): RtsBudget
    {
        $agencyId = $this->sameAgencyOrFail($actor, (int) $agent->agency_id);

        return DB::transaction(function () use ($actor, $agent, $period, $personal, $business, $planning, $track, $agencyId) {
            $row = $this->rowOrNew($agencyId, $track, RtsBudget::LEVEL_AGENT, RtsBudget::keyForUser((int) $agent->id), $period);
            $row->user_id = (int) $agent->id;
            $row->branch_id = $agent->branch_id;
            if ($personal !== null) {
                $row->personal_budget = $this->money($personal);
            }
            if ($business !== null) {
                $row->business_budget = $this->money($business);
            }
            foreach (RtsBudget::PLANNING as $key) {
                if (array_key_exists($key, $planning)) {
                    $row->{$key} = $planning[$key] === null ? null : round((float) $planning[$key], 2);
                }
            }
            $row->set_by_user_id = (int) $actor->id;
            $row->save();

            return $row;
        });
    }

    /** Set what a branch (or, with no branch, the whole agency) needs for a month. */
    public function saveRequirement(User $actor, ?int $branchId, string $period, float $amount, string $track = RtsSetting::TRACK_SALES): RtsBudget
    {
        $agencyId = (int) ($actor->effectiveAgencyId() ?: 0);
        abort_unless($agencyId > 0, 403, 'Switch into an agency first.');

        return DB::transaction(function () use ($actor, $branchId, $period, $amount, $track, $agencyId) {
            $isBranch = $branchId !== null && $branchId > 0;
            if ($isBranch) {
                $owns = DB::table('branches')->where('id', $branchId)->where('agency_id', $agencyId)->exists();
                abort_unless($owns, 404);
            }
            $row = $this->rowOrNew(
                $agencyId, $track,
                $isBranch ? RtsBudget::LEVEL_BRANCH : RtsBudget::LEVEL_AGENCY,
                $isBranch ? RtsBudget::keyForBranch($branchId) : RtsBudget::KEY_AGENCY,
                $period
            );
            $row->branch_id = $isBranch ? $branchId : null;
            $row->user_id = null;
            $row->requirement = $this->money($amount);
            $row->set_by_user_id = (int) $actor->id;
            $row->save();

            return $row;
        });
    }

    /**
     * Split a total by percentages, down one or two levels, and save every figure in ONE transaction (road-to-sold.md section 21.3).
     *
     *  - $branchId null  : $total is what the AGENCY needs; $branchPercents says how it splits to every branch of the agency.
     *  - $branchId given : $total is what that BRANCH needs.
     *  - $agentPercents  : branch id => (agent id => percent). For each branch present, that branch's amount splits to its agents.
     *
     * A level whose percentages do not add up to 100 refuses the whole save (nothing is written). A single branch (or a single agent)
     * is 100% without being told. The amounts are exact (SplitAllocator): the parts always add up to the total to the cent.
     *
     * @param  array<int,float|string>               $branchPercents
     * @param  array<int,array<int,float|string>>    $agentPercents
     * @return array{branches:array<int,float>,agents:array<int,float>}
     * @throws \Illuminate\Validation\ValidationException
     */
    public function saveSplit(User $actor, string $period, ?int $branchId, float $total, array $branchPercents, array $agentPercents, string $track = RtsSetting::TRACK_SALES): array
    {
        $agencyId = (int) ($actor->effectiveAgencyId() ?: 0);
        abort_unless($agencyId > 0, 403, 'Switch into an agency first.');
        $total = $this->money($total);

        return DB::transaction(function () use ($actor, $period, $branchId, $total, $branchPercents, $agentPercents, $track, $agencyId) {
            $branchNames = DB::table('branches')->where('agency_id', $agencyId)->whereNull('deleted_at')->orderBy('id')->pluck('name', 'id')->all();
            $branchAmounts = [];

            if ($branchId === null) {
                $ids = array_keys($branchNames);
                if ($ids === []) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['branches' => 'This agency has no branches to split between.']);
                }
                $pct = count($ids) === 1 ? [$ids[0] => 100.0] : $this->percentsFor($ids, $branchPercents, 'branches', 'The branches changed while you were on this page - reload and split again.');
                SplitAllocator::assertTallies($pct, 'branch', 'branches');
                $branchAmounts = SplitAllocator::allocate($total, $pct, 2);
                $this->saveRequirement($actor, null, $period, $total, $track);
                foreach ($branchAmounts as $bid => $amount) {
                    $this->saveRequirement($actor, (int) $bid, $period, (float) $amount, $track);
                }
            } else {
                abort_unless(isset($branchNames[$branchId]), 404);
                $branchAmounts = [$branchId => $total];
                $this->saveRequirement($actor, $branchId, $period, $total, $track);
            }

            $agentAmounts = [];
            foreach ($branchAmounts as $bid => $amount) {
                if (! isset($agentPercents[$bid])) {
                    continue; // this branch's agents were not split in this save
                }
                $ids = $this->splitAgentIds($agencyId, (int) $bid);
                if ($ids === []) {
                    continue;
                }
                $name = $branchNames[$bid] ?? 'branch';
                $pct = count($ids) === 1 ? [$ids[0] => 100.0] : $this->percentsFor($ids, (array) $agentPercents[$bid], "agents.{$bid}", "The agents of {$name} changed while you were on this page - reload and split again.");
                SplitAllocator::assertTallies($pct, "{$name} agents", "agents.{$bid}");
                foreach (SplitAllocator::allocate((float) $amount, $pct, 2) as $uid => $share) {
                    $this->saveAgentRequirement($actor, (int) $uid, $period, (float) $share, $track);
                    $agentAmounts[(int) $uid] = (float) $share;
                }
            }

            return ['branches' => array_map('floatval', $branchAmounts), 'agents' => $agentAmounts];
        });
    }

    /**
     * The posted percentages, checked to be for exactly the people the save covers.
     *
     * @param  int[]                       $ids
     * @param  array<int|string,mixed>     $posted
     * @return array<int,float>
     */
    private function percentsFor(array $ids, array $posted, string $field, string $changedMessage): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (! array_key_exists($id, $posted) || ! is_numeric($posted[$id])) {
                throw \Illuminate\Validation\ValidationException::withMessages([$field => $changedMessage]);
            }
            $out[$id] = (float) $posted[$id];
        }
        if (count(array_diff(array_map('intval', array_keys($posted)), $ids)) > 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([$field => $changedMessage]);
        }

        return $out;
    }

    /** The share of the branch total given to one agent. Their own budgets and planning figures are never touched. */
    public function saveAgentRequirement(User $actor, int $userId, string $period, float $amount, string $track = RtsSetting::TRACK_SALES): RtsBudget
    {
        $agent = User::query()->withoutGlobalScopes()->find($userId);
        abort_unless($agent, 404);
        $agencyId = $this->sameAgencyOrFail($actor, (int) $agent->agency_id);

        $row = $this->rowOrNew($agencyId, $track, RtsBudget::LEVEL_AGENT, RtsBudget::keyForUser($userId), $period);
        $row->user_id = $userId;
        $row->branch_id = $agent->branch_id;
        $row->requirement = $this->money($amount);
        $row->set_by_user_id = (int) $actor->id;
        $row->save();

        return $row;
    }

    /** Archive a row (soft). Returns false when it was already archived or is not in the writer's agency. */
    public function archive(User $actor, int $id): bool
    {
        $row = RtsBudget::query()->where('agency_id', (int) ($actor->effectiveAgencyId() ?: 0))->find($id);

        return $row ? (bool) $row->delete() : false;
    }

    public function restore(User $actor, int $id): bool
    {
        $row = RtsBudget::onlyTrashed()->where('agency_id', (int) ($actor->effectiveAgencyId() ?: 0))->find($id);
        if (! $row) {
            return false;
        }
        // Another live row for the same (who, month) may have been written since: never restore over it.
        $clash = RtsBudget::query()->where('agency_id', $row->agency_id)->where('track', $row->track)
            ->where('scope_key', $row->scope_key)->where('period', $row->period)->exists();
        if ($clash) {
            return false;
        }

        return (bool) $row->restore();
    }

    /**
     * Give each of the agents the figures they had in the previous month, for agents with no budget this month.
     * The explicit version of what the old Worksheet did silently on a visit. Returns how many were filled.
     *
     * @param  iterable<User> $agents
     */
    public function copyFromPreviousMonth(User $actor, iterable $agents, string $period, string $track = RtsSetting::TRACK_SALES): int
    {
        $prev = \Carbon\CarbonImmutable::createFromFormat('Y-m', $period)->subMonthNoOverflow()->format('Y-m');
        $n = 0;
        foreach ($agents as $agent) {
            $agencyId = (int) $agent->agency_id;
            $current = RtsBudget::query()->where('agency_id', $agencyId)->where('track', $track)
                ->where('scope_key', RtsBudget::keyForUser((int) $agent->id))->where('period', $period)->first();
            if ($current && ! $current->isShareOnly()) {
                continue;
            }
            $before = RtsBudget::query()->where('agency_id', $agencyId)->where('track', $track)
                ->where('scope_key', RtsBudget::keyForUser((int) $agent->id))->where('period', $prev)->first();
            if (! $before || ((float) $before->personal_budget <= 0 && (float) $before->business_budget <= 0)) {
                continue;
            }
            $planning = [];
            foreach (RtsBudget::PLANNING as $key) {
                $planning[$key] = $before->{$key} === null ? null : (float) $before->{$key};
            }
            $this->saveAgent($actor, $agent, $period, (float) $before->personal_budget, (float) $before->business_budget, $planning, $track);
            $n++;
        }

        return $n;
    }

    // ── internals ────────────────────────────────────────────────────────────────────

    /** The row for (who, month) - restoring an archived one rather than colliding with the unique index. */
    private function rowOrNew(int $agencyId, string $track, string $level, string $scopeKey, string $period): RtsBudget
    {
        $row = RtsBudget::withTrashed()->where('agency_id', $agencyId)->where('track', $track)
            ->where('scope_key', $scopeKey)->where('period', $period)->lockForUpdate()->first();

        if ($row) {
            if ($row->trashed()) {
                $row->restore();
            }

            return $row;
        }

        $row = new RtsBudget();
        $row->forceFill(['agency_id' => $agencyId, 'track' => $track, 'level' => $level, 'scope_key' => $scopeKey, 'period' => $period]);

        return $row;
    }

    private function sameAgencyOrFail(User $actor, int $agencyId): int
    {
        $mine = (int) ($actor->effectiveAgencyId() ?: 0);
        abort_unless($mine > 0 && $mine === $agencyId, 404);

        return $mine;
    }

    private function money(float $v): float
    {
        return round(min(max($v, 0.0), RtsBudget::MAX_MONEY), 2);
    }
}
