<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Deal;
use App\Models\PerformanceSetting;
use App\Http\Controllers\WorksheetController;
use App\Models\Property;
use App\Models\Worksheet;
use App\Models\User;
use App\Services\Activity\ActivityPointsService;
use App\Services\Admin\CompanyPerformanceService;
use App\Services\PermissionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The figures behind the My Portal blocks "Targets & Budget", "Where am I vs where I should be" and
 * "My Activity" (agency-tracker-rehome.md sections 4.2, 5.2, 6).
 *
 * READ ONLY - it never writes (the old dashboard service saved derived targets as a side effect) and it
 * carries no agency constants: averages come from the agent's own deals, else the agency's, else the block
 * says there is not enough to calculate yet. Money comes from deal_money_lines (DealMoney), never from the
 * commission ledger. Visibility is enforced in the queries: own = the user's data identities (an assistant
 * maps to their agent), branch / agency only when the user's deal data scope allows it, always inside the
 * agency wall.
 */
final class MyPortalNumbers
{
    public const LENS_OWN = 'own';
    public const LENS_BRANCH = 'branch';
    public const LENS_AGENCY = 'agency';
    public const LENSES = [self::LENS_OWN => 'Me', self::LENS_BRANCH => 'My branch', self::LENS_AGENCY => 'The agency'];

    /** The deal-summary rows a user can pick (ruling A). Money rows are separate and own-only. */
    public const ROWS = [
        'avg_price'      => 'Average sale price',
        'avg_commission' => 'Average commission',
        'deal_count'     => 'Deals by status',
    ];

    private const RENTAL_TYPES = ['rental', 'to_let', 'to-let', 'lease'];

    // ── periods ──────────────────────────────────────────────────────────────────────

    /** @return array{0: string, 1: string} first and last day of a YYYY-MM month */
    public static function bounds(string $period): array
    {
        $m = CarbonImmutable::createFromFormat('!Y-m', $period);

        return [$m->startOfMonth()->toDateString(), $m->endOfMonth()->toDateString()];
    }

    /**
     * The months this agent has figures for, newest first (the "history" the month picker offers):
     * a budget row, a target row, a deal, or a daily-activity entry in that month.
     *
     * @return array<int, string>
     */
    public function historyMonths(User $u, int $limit = 24): array
    {
        $agencyId = (int) ($u->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return [];
        }
        $ids = $u->dataIdentityIds();

        $sources = [
            DB::table('worksheets')->where('agency_id', $agencyId)->where('user_id', $u->id)->whereNull('deleted_at')->select('period'),
            DB::table('targets')->where('agency_id', $agencyId)->where('user_id', $u->id)->whereNull('deleted_at')->select('period'),
            DB::table('daily_activity_entries')->where('agency_id', $agencyId)->whereIn('user_id', $ids)->select('period'),
            DB::table('deal_user')->join('deals', 'deals.id', '=', 'deal_user.deal_id')->where('deals.agency_id', $agencyId)
                ->whereNull('deals.deleted_at')->whereIn('deal_user.user_id', $ids)->selectRaw("DATE_FORMAT(deals.deal_date, '%Y-%m') as period"),
        ];
        $union = array_shift($sources);
        foreach ($sources as $s) {
            $union->union($s);
        }

        return DB::query()->fromSub($union, 'p')->whereNotNull('period')->distinct()->orderByDesc('period')->limit($limit)->pluck('period')->all();
    }

    // ── stock (CoreX properties, never the old stock import) ────────────────

    /** @return array{sales: int, rentals: int} the agent's active stock */
    public function stock(User $u): array
    {
        $base = fn () => Property::query()->ownStock()->onMarket()->ownListingsFor($u);

        return [
            'sales'   => (int) $base()->where(fn ($q) => $q->whereNull('listing_type')->orWhereNotIn('listing_type', self::RENTAL_TYPES))->count(),
            'rentals' => (int) $base()->whereIn('listing_type', self::RENTAL_TYPES)->count(),
        ];
    }

    /** Sales listings that went live in the month - the same rule Road to Sold counts as "listings live". */
    public function listingsLive(User $u, string $period): int
    {
        $agencyId = (int) ($u->effectiveAgencyId() ?: 0);
        [$from, $to] = self::bounds($period);

        return $agencyId <= 0 ? 0 : (int) DB::table('properties')
            ->where('agency_id', $agencyId)->whereNull('deleted_at')->where('listing_type', 'sale')
            ->whereIn('agent_id', $u->dataIdentityIds())->whereBetween('listed_date', [$from, $to])->count();
    }

    // ── deals ────────────────────────────────────────────────────────────────────────

    /** The lenses this user may look through, by their deal data scope. @return array<int, string> */
    public function lensesFor(User $u): array
    {
        $scope = PermissionService::getDataScope($u, 'deals');
        $lenses = [self::LENS_OWN];
        if (in_array($scope, ['branch', 'all'], true) && $u->effectiveBranchId()) {
            $lenses[] = self::LENS_BRANCH;
        }
        if ($scope === 'all') {
            $lenses[] = self::LENS_AGENCY;
        }

        return $lenses;
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Deal> deals of a lens in a month (Deal carries the agency wall) */
    private function dealsFor(User $u, string $lens, string $period)
    {
        [$from, $to] = self::bounds($period);
        $q = Deal::query()->whereBetween('deals.deal_date', [$from, $to]);

        if ($lens === self::LENS_OWN) {
            $q->whereIn('deals.id', fn ($s) => $s->select('deal_id')->from('deal_user')->whereIn('user_id', $u->dataIdentityIds()));
        } elseif ($lens === self::LENS_BRANCH) {
            $q->whereIn('deals.id', fn ($s) => $s->select('deal_user.deal_id')->from('deal_user')
                ->join('users', 'users.id', '=', 'deal_user.user_id')->join('deals as d2', 'd2.id', '=', 'deal_user.deal_id')
                ->whereRaw('COALESCE(d2.branch_id, users.branch_id) = ?', [(int) $u->effectiveBranchId()]));
        }

        return $q;
    }

    /**
     * Actuals only, no planning percentages. Declined deals are left out of prices, averages and money;
     * the status split shows all four (Pending, Granted, Registered, Declined).
     *
     * @return array{deals: int, avgPrice: ?float, avgCommissionPct: ?float, status: array{P:int,G:int,R:int,D:int}}
     */
    public function dealSummary(User $u, string $lens, string $period): array
    {
        if (! in_array($lens, $this->lensesFor($u), true)) {
            $lens = self::LENS_OWN;
        }
        $status = ['P' => 0, 'G' => 0, 'R' => 0, 'D' => 0];
        $rows = $this->dealsFor($u, $lens, $period)->selectRaw("COALESCE(deals.accepted_status, 'P') as st, COUNT(*) as n, SUM(deals.property_value) as pv, SUM(deals.total_commission) as tc")
            ->groupBy('st')->get();

        $count = 0;
        $value = 0.0;
        $commission = 0.0;
        foreach ($rows as $r) {
            $st = in_array($r->st, ['P', 'G', 'R', 'D'], true) ? $r->st : 'P';
            $status[$st] += (int) $r->n;
            if ($st !== 'D') {
                $count += (int) $r->n;
                $value += (float) $r->pv;
                $commission += (float) $r->tc;
            }
        }
        $vat = 1 + ((float) PerformanceSetting::get('vat_rate', 15) / 100);

        return [
            'deals'            => $count,
            'avgPrice'         => $count > 0 ? $value / $count : null,
            'avgCommissionPct' => $value > 0 ? (($commission / $vat) / $value) * 100 : null,
            'status'           => $status,
        ];
    }

    /**
     * The agent's own deals and sales value for the month, counted exactly as the old My Agent Dashboard did
     * (split-aware: each deal_user row counts the property value x its side's split %; declined deals left out).
     *
     * @return array{deals: int, salesValue: float}
     */
    public function ownActuals(User $u, string $period): array
    {
        $agencyId = (int) ($u->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return ['deals' => 0, 'salesValue' => 0.0];
        }
        [$from, $to] = self::bounds($period);
        $row = DB::table('deal_user')->join('deals', 'deals.id', '=', 'deal_user.deal_id')
            ->where('deals.agency_id', $agencyId)->whereNull('deals.deleted_at')
            ->whereIn('deal_user.user_id', $u->dataIdentityIds())->whereBetween('deals.deal_date', [$from, $to])
            ->whereRaw("COALESCE(deals.accepted_status, '') != 'D'")
            ->selectRaw('COUNT(DISTINCT deal_user.deal_id) as n')
            ->selectRaw('COALESCE(SUM(' . AgentPerformanceService::splitAwareSalesValueExpr() . '), 0) as v')->first();

        return ['deals' => (int) $row->n, 'salesValue' => (float) $row->v];
    }

    /**
     * The agent's own money for the month, from the money lines: gross, and after splits and deductions.
     * Pipeline = the agent's own net on deals not yet Registered or Paid (not tied to the month).
     *
     * @return array{gross: float, net: float, pipeline: float}
     */
    public function ownMoney(User $u, string $period): array
    {
        $agencyId = (int) ($u->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return ['gross' => 0.0, 'net' => 0.0, 'pipeline' => 0.0];
        }
        [$from, $to] = self::bounds($period);
        $lines = fn () => DB::table('deal_money_lines as l')->join('deals', 'deals.id', '=', 'l.deal_id')
            ->where('l.agency_id', $agencyId)->whereIn('l.user_id', $u->dataIdentityIds())
            ->whereNull('l.deleted_at') // a deal's money is rebuilt over time; the old rows are trashed, never counted
            ->whereNull('deals.deleted_at')->whereRaw("COALESCE(deals.accepted_status, 'P') != 'D'");

        $month = $lines()->whereBetween('deals.deal_date', [$from, $to])
            ->selectRaw('COALESCE(SUM(l.agent_gross_ex_vat),0) as gross, COALESCE(SUM(l.agent_net_ex_vat),0) as net')->first();
        // Not Registered and not Paid yet. A payslip-generated event does not exist yet; this is the stand-in.
        $pipeline = $lines()->whereRaw("COALESCE(deals.accepted_status, 'P') != 'R'")->whereRaw("COALESCE(deals.commission_status, '') != 'Paid'")
            ->sum('l.agent_net_ex_vat');

        return ['gross' => (float) $month->gross, 'net' => (float) $month->net, 'pipeline' => (float) $pipeline];
    }

    /**
     * The branch's and the agency's commission totals for the month - shown ONLY when the agency has switched
     * "Show agents their branch and agency commission totals" on (default off) AND the user's deal data scope
     * reaches that far. Decided here, in the query layer, not by hiding a link: a user whose scope is "own" gets
     * null whatever the setting says, and so does everyone while the setting is off.
     * Money lines of declined deals are left out, as everywhere else.
     *
     * @return array{branch: ?array{gross: float, net: float}, agency: ?array{gross: float, net: float}}|null
     */
    public function commissionTotals(User $u, string $period): ?array
    {
        $agencyId = (int) ($u->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0 || ! (bool) \App\Models\Agency::query()->whereKey($agencyId)->value('portal_show_agency_commission_totals')) {
            return null;
        }
        $allowed = $this->lensesFor($u);
        if (! in_array(self::LENS_BRANCH, $allowed, true) && ! in_array(self::LENS_AGENCY, $allowed, true)) {
            return null;
        }
        [$from, $to] = self::bounds($period);
        $lines = fn () => DB::table('deal_money_lines as l')->join('deals', 'deals.id', '=', 'l.deal_id')
            ->where('l.agency_id', $agencyId)->whereNull('l.deleted_at')->whereNull('deals.deleted_at')->whereRaw("COALESCE(deals.accepted_status, 'P') != 'D'")
            ->whereBetween('deals.deal_date', [$from, $to])
            ->selectRaw('COALESCE(SUM(l.agent_gross_ex_vat),0) as gross, COALESCE(SUM(l.agent_net_ex_vat),0) as net');
        $pack = fn ($r) => ['gross' => (float) $r->gross, 'net' => (float) $r->net];

        return [
            'branch' => in_array(self::LENS_BRANCH, $allowed, true) ? $pack($lines()->where('l.branch_id', (int) $u->effectiveBranchId())->first()) : null,
            'agency' => in_array(self::LENS_AGENCY, $allowed, true) ? $pack($lines()->first()) : null,
        ];
    }

    /**
     * What the budget needs: TODAY'S Worksheet calculation, unchanged, shown read-only (Johan, 20 Oct 2026:
     * "same as what we have there for now"). It is WorksheetController::calculate() itself, run on the agent's
     * stored budget row, so the planning figures on that row - average sale price (or the admin's override),
     * commission %, PAYE %, agent split % and correctly-priced % - drive it exactly as they do on the Worksheet,
     * and the two can never drift apart.
     *
     * Two things differ on purpose, both from the earlier rulings: the "want" figure is left out (ruling A drops it),
     * and the gap is taken against the agent's active sales stock from CoreX properties instead of the frozen
     * Propcon import (ruling A, current active listings). Everything else - net need, sales needed, listings
     * needed - is the Worksheet's own figure.
     *
     * @return array{state: string, netNeed?: float, salesNeeded?: float, listingsNeeded?: float, gap?: float,
     *               basis?: array{avgSalePrice: float, commissionPercent: float, payePercent: float, agentSplitPercent: float, correctlyPricedPercent: float}}
     */
    public function requirement(?Worksheet $row, int $salesStock): array
    {
        if (! $row || ((float) $row->personal_net_target + (float) $row->business_net_target) <= 0) {
            return ['state' => 'no_budget'];
        }

        $copy = $row->replicate();
        $copy->want_net_target = 0;     // not part of the budget any more (ruling A)
        $copy->user_id = null;          // calculate() then uses the row's own stock figure and never reads the frozen import
        $calc = WorksheetController::calculate($copy);

        if (($calc['sales_needed_per_month'] ?? 0) <= 0) {
            return ['state' => 'incomplete'];   // the row has no sale price / commission to work from
        }

        return [
            'state'          => 'ok',
            'netNeed'        => (float) $calc['net_need'],
            'salesNeeded'    => (float) $calc['sales_needed_per_month'],
            'listingsNeeded' => (float) $calc['total_listings_needed'],
            'gap'            => (float) $calc['total_listings_needed'] - $salesStock,
            'basis'          => [
                'avgSalePrice'           => (float) ($row->avg_sale_price_admin ?? $row->avg_sale_price),
                'commissionPercent'      => (float) $row->commission_percent,
                'payePercent'            => (float) $row->paye_percent,
                'agentSplitPercent'      => (float) $row->agent_split_percent,
                'correctlyPricedPercent' => (float) $row->correctly_priced_percent,
            ],
        ];
    }

    // ── points (daily activity) ──────────────────────────────────────────────────────

    /**
     * Points for the month, for today, and the last 14 days - the rows that count (confirmed / overridden,
     * scored sources) and the rule (count x weight) are both written once in ActivityPointsService, so this
     * block, the agent's daily page and the dashboards cannot disagree. Days are looked up by date, not by
     * month, so the strip stays correct across a month boundary.
     *
     * @return array{month: float, today: float, days: array<string, float>, target: int}
     */
    public function points(User $u, string $period, int $pointsTarget = 0): array
    {
        $agencyId = (int) ($u->effectiveAgencyId() ?: 0);
        $empty = ['month' => 0.0, 'today' => 0.0, 'days' => [], 'target' => $pointsTarget];
        if ($agencyId <= 0) {
            return $empty;
        }
        $today = CarbonImmutable::today();
        $from = $today->subDays(13)->toDateString();

        $base = fn () => ActivityPointsService::counted($agencyId)->whereIn('e.user_id', $u->dataIdentityIds());

        $month = ActivityPointsService::sum($base()->where('e.period', $period));
        $days = ActivityPointsService::sumByDate(
            $base()->where('e.activity_date', '>=', $from)->where('e.activity_date', '<=', $today->toDateString())
        );

        $strip = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = $today->subDays($i)->toDateString();
            $strip[$day] = (float) ($days[$day] ?? 0);
        }

        return ['month' => round($month, 2), 'today' => $strip[$today->toDateString()], 'days' => $strip, 'target' => $pointsTarget];
    }

    // ── you vs branch vs company (totals only) ────────────────────────────────────────

    /**
     * Sales value progress for the agent's branch and the whole agency, from the existing rollup service
     * (totals only, no individuals - what the old dashboard showed). Null when there is no branch / agency.
     *
     * @return array{branch: ?array, company: ?array}
     */
    public function comparison(User $u, string $period): array
    {
        $agencyId = (int) ($u->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return ['branch' => null, 'company' => null];
        }
        $svc = app(CompanyPerformanceService::class);
        $branchId = $u->effectiveBranchId();

        return [
            'branch'  => $branchId ? ($svc->getBranchRollup((int) $branchId, $period, $agencyId)['totals'] ?? null) : null,
            'company' => $svc->getPeriodRollup($period, $agencyId)['totals'] ?? null,
        ];
    }
}
