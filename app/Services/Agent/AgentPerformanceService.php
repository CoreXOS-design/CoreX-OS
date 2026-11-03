<?php

namespace App\Services\Agent;

use App\Models\User;
use App\Services\Activity\ActivityPointsService;
use App\Models\PerformanceSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AgentPerformanceService
{
    /**
     * Canonical SQL CASE expression for split-aware sales value.
     * Each deal_user row contributes: property_value × side split %.
     * Requires deal_user and deals tables to be joined in the query.
     */
    public static function splitAwareSalesValueExpr(): string
    {
        return "CASE deal_user.side
            WHEN 'listing' THEN deals.property_value * deals.listing_split_percent / 100.0
            WHEN 'selling' THEN deals.property_value * deals.selling_split_percent / 100.0
            ELSE 0 END";
    }

    /**
     * Return ONLY safe, non-private fields for an agent monthly dashboard.
     * Never return personal_net_target/business_net_target/want_net_target/etc.
     */
    public function getMonthlySnapshot(User $user, Carbon $month): array
    {
        $start = $month->copy()->startOfMonth()->startOfDay();
        $end   = $month->copy()->endOfMonth()->endOfDay();

        // --- Derived targets (safe) ---
        // NOTE: We are conservative: if worksheet-based derivation isn't wired yet,
        // fall back to zeros (we’ll implement real derivation next).
        $derived = [
            'deals_needed'   => 0,
            'listings_needed'=> 0,
            'value_target'   => 0.0, // property value target in R
        ];

        // If you already store derived targets in 'targets' table, we can read them safely.
        // We'll attempt a best-effort fetch without assuming schema beyond common columns.
        $t = DB::table('targets')
            ->where('user_id', $user->id)
            ->where('period', $month->format('Y-m')) // common pattern; adjust later if needed
            ->first();

        if ($t) {
            // Only map if columns exist; avoid throwing.
            $derived['deals_needed']    = (int)($t->deals_target ?? 0);
            $derived['listings_needed'] = (int)($t->listings_target ?? 0);
            $derived['value_target']    = (float)($t->value_target ?? 0);
        }

        // If targets table doesn't yet have worksheet-derived values (older data),
        // fall back to worksheet calculation and optionally sync targets (do NOT touch points_target).
        if (
            (($derived['value_target'] ?? 0) <= 0) ||
            (($derived['deals_needed'] ?? 0) <= 0)
        ) {
            $ws = app(\App\Services\RoadToSold\BudgetService::class)->worksheetFor($user, $month->format('Y-m'));

            if ($ws) {
                $calc = \App\Http\Controllers\WorksheetController::calculate($ws);

                $dealsTarget = (int) ceil((float)($calc['sales_needed_per_month'] ?? 0));
                $listingsTarget = (int) ceil((float)($calc['total_listings_needed'] ?? 0));

                $avgSalePlan = (float)($ws->avg_sale_price_admin ?? $ws->avg_sale_price ?? 0);
                $valueTarget = (float)($dealsTarget * $avgSalePlan);

                $derived['deals_needed']    = $dealsTarget;
                $derived['listings_needed'] = $listingsTarget;
                $derived['value_target']    = $valueTarget;

                // Sync into targets so dashboards use a single source (leave points_target untouched).
                // Resolve the tenant via the canonical path (branch → direct agency_id) so a BM whose
                // agency is derived from their branch is written correctly. Global owner/super_admin
                // users have no agency context (agency_id NULL by design) — the targets row requires a
                // non-null agency_id, so for them we skip the persistence and just return the derived
                // values in-memory; the dashboard still renders.
                $agencyId = $user->effectiveAgencyId();
                if ($agencyId !== null) {
                    DB::table('targets')->updateOrInsert(
                        ['period' => $month->format('Y-m'), 'user_id' => (int)$user->id],
                        [
                            'agency_id' => $agencyId,
                            'branch_id' => $user->effectiveBranchId(),
                            'listings_target' => $listingsTarget,
                            'deals_target' => $dealsTarget,
                            'value_target' => $valueTarget,
                            'updated_at' => now(),
                        ]
                    );
                }
            }
        }


        // --- Actuals from deals (safe) ---
          // Split-aware: each deal_user row contributes property_value × side split %.
          // If agent is on both sides of a deal, both contributions are summed.
          $splitExpr = self::splitAwareSalesValueExpr();

          $q = DB::table('deal_user')
              ->join('deals', 'deals.id', '=', 'deal_user.deal_id')
              ->where('deal_user.user_id', $user->id)
              ->whereBetween('deals.deal_date', [$start->toDateString(), $end->toDateString()])
              ->whereRaw("COALESCE(deals.accepted_status,'') != 'D'")
              ->selectRaw('COUNT(DISTINCT deal_user.deal_id) as deals_count')
              ->selectRaw("COALESCE(SUM({$splitExpr}), 0) as sales_value")
              ->selectRaw("COALESCE(SUM(
                  CASE deal_user.side
                      WHEN 'listing' THEN deals.total_commission * deals.listing_split_percent / 100.0
                      WHEN 'selling' THEN deals.total_commission * deals.selling_split_percent / 100.0
                      ELSE 0 END
              ), 0) as total_commission")
              ->first();

          $actualDealsCount = (int)($q->deals_count ?? 0);
          $actualSalesValue = (float)($q->sales_value ?? 0);
          $totalCommission  = (float)($q->total_commission ?? 0);

          $avgSalePriceActual = $actualDealsCount > 0 ? ($actualSalesValue / $actualDealsCount) : 0.0;

          // Commission % ex VAT: strip VAT from commission before dividing by sale price
          $vatRatePercent = (float) \App\Models\PerformanceSetting::get('vat_rate', 15);
          $vatDiv = 1.0 + ($vatRatePercent / 100.0);
          $commissionExVat = $vatDiv > 0 ? ($totalCommission / $vatDiv) : 0.0;
          $effectiveCommissionPercent = $actualSalesValue > 0 ? (($commissionExVat / $actualSalesValue) * 100.0) : 0.0;

          // --- ALL-TIME Actuals from deals (same rules, no date filter) ---
          $qAll = DB::table('deal_user')
              ->join('deals', 'deals.id', '=', 'deal_user.deal_id')
              ->where('deal_user.user_id', $user->id)
              ->whereRaw("COALESCE(deals.accepted_status,'') != 'D'")
              ->selectRaw('COUNT(DISTINCT deal_user.deal_id) as deals_count')
              ->selectRaw("COALESCE(SUM({$splitExpr}), 0) as sales_value")
              ->selectRaw("COALESCE(SUM(
                  CASE deal_user.side
                      WHEN 'listing' THEN deals.total_commission * deals.listing_split_percent / 100.0
                      WHEN 'selling' THEN deals.total_commission * deals.selling_split_percent / 100.0
                      ELSE 0 END
              ), 0) as total_commission")
              ->first();

          $actualDealsCountAll = (int)($qAll->deals_count ?? 0);
          $actualSalesValueAll = (float)($qAll->sales_value ?? 0);
          $totalCommissionAll  = (float)($qAll->total_commission ?? 0);

          $avgSalePriceAll = $actualDealsCountAll > 0 ? ($actualSalesValueAll / $actualDealsCountAll) : 0.0;

          // Commission % ex VAT: strip VAT from commission before dividing by sale price
          $commissionExVatAll = $vatDiv > 0 ? ($totalCommissionAll / $vatDiv) : 0.0;
          $effectiveCommissionPercentAll = $actualSalesValueAll > 0 ? (($commissionExVatAll / $actualSalesValueAll) * 100.0) : 0.0;

        // --- Actuals from daily activities (V2) ---
        $period = $month->format('Y-m');

        // How many distinct days have entries this month (V2)
        $dailyRows = ActivityPointsService::distinctDays($user->effectiveAgencyId(), (int) $user->id, $period);

        $dailyRowsAllTime = ActivityPointsService::distinctDays($user->effectiveAgencyId(), (int) $user->id);


        // daily_activity_entries has agency_id but no automatic tenant scope on
        // this raw query-builder path. e.user_id already pins these queries to
        // the profiled $user, but the agency_id filter is added as defense in
        // depth, matching every other daily_activity_entries call site fixed
        // in this pass.
        $agencyId = $user->effectiveAgencyId();

        // --- Points (weighted activity scoring) (V2) ---
        // The one points rule and the one "what counts" filter live in
        // ActivityPointsService (count x weight; confirmed/overridden entries
        // from manual/auto sources; no filter on the activity definition).
        // Split into manual (captured by the agent) and automatic (credited by CoreX work): the same rows, the same
        // rule; the combined figure is manual + automatic.
        $pointsSplit = ActivityPointsService::sumSplit(
            ActivityPointsService::counted($agencyId)
                ->where('e.user_id', $user->id)
                ->where('e.period', $period)
        );
        $pointsActual = $pointsSplit['total'];

        // Points target comes from targets.points_target (V2)
        $pointsTarget = (float) (DB::table('targets')
            ->where('user_id', $user->id)
            ->where('period', $period)
            ->value('points_target') ?? 0);

        $pointsPct = $pointsTarget > 0 ? round(($pointsActual / $pointsTarget) * 100, 1) : null;

        // --- Points by date (V2) for Momentum strip ---
        $pointsByDateSplit = ActivityPointsService::sumByDateSplit(
            ActivityPointsService::counted($agencyId)
                ->where('e.user_id', $user->id)
                ->where('e.period', $period)
        );
        $pointsByDate = array_map(fn ($d) => $d['total'], $pointsByDateSplit);

        // --- Progress calculations (safe) ---
        $progress = [
            'deals_pct'   => $derived['deals_needed'] > 0 ? round(($actualDealsCount / $derived['deals_needed']) * 100, 1) : null,
            'value_pct'   => $derived['value_target'] > 0 ? round(($actualSalesValue / $derived['value_target']) * 100, 1) : null,
            'points_pct'  => $pointsPct,
        ];

        

        // --- Remaining + pacing ---
        $today = Carbon::today()->startOfDay();
        $endDay = $end->copy()->startOfDay();
        $daysLeft = (int)max(0, $today->diffInDays($endDay, false));
        $remainingDeals = max(0, $derived['deals_needed'] - $actualDealsCount);
        $remainingValue = max(0, $derived['value_target'] - $actualSalesValue);

        // --- Branch + company totals (privacy safe) ---
        $branchTotals = null;
        $companyTotals = null;

        // The rollup service runs raw DB::table() queries that only filter by agency when
        // it is handed one — omitting it summed every agency into the Company tile. With no
        // resolvable agency the tiles stay empty rather than fall back to "everyone".
        if ($user->branch_id && $agencyId) {
            $svc = app(\App\Services\Admin\CompanyPerformanceService::class);
            $branch = $svc->getBranchRollup((int)$user->branch_id, $month->format('Y-m'), (int) $agencyId);
            $company = $svc->getPeriodRollup($month->format('Y-m'), (int) $agencyId);

            $branchTotals = $branch['totals'] ?? null;
            $companyTotals = $company['totals'] ?? null;
        }

        // --- Daily breakdown (per day map) ---
        // Days on which this agent has counted activity, from the live entries table
        // (the old `daily_activities` table is abandoned and holds no rows).
        $dailyMap = [];
        $dailyMapCounts = [];

        $rowsByDate = ActivityPointsService::entryRowsByDateSplit(
            ActivityPointsService::counted($agencyId)
                ->where('e.user_id', $user->id)
                ->whereBetween('e.activity_date', [$start->toDateString(), $end->toDateString()])
        );

        foreach ($rowsByDate as $date => $rows) {
            $rowsCount = $rows['manual'] + $rows['automatic'];
            $dailyMap[$date] = [
                'daily_rows' => $rowsCount,
                'manual_rows' => $rows['manual'],
                'auto_rows' => $rows['automatic'],
                'deals' => 0,
                'value' => 0,
            ];

            $dailyMapCounts[$date] = $rowsCount;
        }

return [
            'month_label' => $month->format('F Y'),
            'range' => [
                'start' => $start->toDateString(),
                'end'   => $end->toDateString(),
            ],
            'derived_targets' => $derived,
            'actuals' => [
                'deals_count'  => $actualDealsCount,
                'deals_count_all_time' => $actualDealsCountAll,
                'sales_value'  => $actualSalesValue,
                'sales_value_all_time' => $actualSalesValueAll,
              'avg_sale_price_actual' => $avgSalePriceActual,
              'avg_sale_price_actual_all_time' => $avgSalePriceAll,
              'effective_commission_percent' => $effectiveCommissionPercent,
              'effective_commission_percent_all_time' => $effectiveCommissionPercentAll,
                'daily_rows'   => $dailyRows,
                'daily_rows_all_time' => $dailyRowsAllTime,
                'points_actual' => round($pointsActual, 2),
                'points_target' => round($pointsTarget, 2),
            ],
            'progress' => $progress,

            'period' => $month->format('Y-m'),
            'remaining' => [
                'deals' => $remainingDeals,
                'value' => $remainingValue,
                'days_left' => $daysLeft,
            ],
            'pace' => [
                'deals_per_day' => $daysLeft > 0 ? round($remainingDeals / $daysLeft, 2) : 0,
                'value_per_day' => $daysLeft > 0 ? round($remainingValue / $daysLeft, 0) : 0,
            ],
            'comparisons' => [
                'branch' => $branchTotals,
                'company' => $companyTotals,
            ],
'daily_map' => $dailyMap,
            'daily_map_counts' => $dailyMapCounts,
            'points_by_date' => $pointsByDate,
            'points_split' => $pointsSplit,
            'points_by_date_split' => $pointsByDateSplit,
        ];
    }
}
