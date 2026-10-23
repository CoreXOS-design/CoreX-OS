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
    public function requirementFor(int $agencyId, ?int $branchId, string $period, string $track = RtsSetting::TRACK_SALES): array
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

        return [
            'requirement'      => $budget,
            'requiredPerAgent' => $agents > 0 ? round($budget / $agents, 2) : 0.0,
            'agents'           => $agents,
        ];
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
            ->where('period', $period)->whereNotNull('branch_id')->pluck('requirement', 'branch_id')
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
            $has = RtsBudget::query()->where('agency_id', $agencyId)->where('track', $track)
                ->where('scope_key', RtsBudget::keyForUser((int) $agent->id))->where('period', $period)->exists();
            if ($has) {
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
