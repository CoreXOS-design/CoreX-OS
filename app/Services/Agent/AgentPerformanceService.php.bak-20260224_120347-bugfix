<?php

namespace App\Services\Agent;

use App\Models\User;
use App\Models\PerformanceSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AgentPerformanceService
{
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
            $ws = \App\Models\Worksheet::where('user_id', $user->id)
                ->where('period', $month->format('Y-m'))
                ->first();

            if ($ws) {
                $calc = \App\Http\Controllers\WorksheetController::calculate($ws);

                $dealsTarget = (int) ceil((float)($calc['sales_needed_per_month'] ?? 0));
                $listingsTarget = (int) ceil((float)($calc['total_listings_needed'] ?? 0));

                $avgSalePlan = (float)($ws->avg_sale_price_admin ?? $ws->avg_sale_price ?? 0);
                $valueTarget = (float)($dealsTarget * $avgSalePlan);

                $derived['deals_needed']    = $dealsTarget;
                $derived['listings_needed'] = $listingsTarget;
                $derived['value_target']    = $valueTarget;

                // Sync into targets so dashboards use a single source (leave points_target untouched)
                DB::table('targets')->updateOrInsert(
                    ['period' => $month->format('Y-m'), 'user_id' => (int)$user->id],
                    [
                        'branch_id' => $user->branch_id,
                        'listings_target' => $listingsTarget,
                        'deals_target' => $dealsTarget,
                        'value_target' => $valueTarget,
                        'updated_at' => now(),
                    ]
                );
            }
        }


        // --- Actuals from deals (safe) ---
          // IMPORTANT: avoid double counting when agent appears twice (listing + selling) on same deal.
          $dealIdsSub = DB::table('deal_user')
              ->where('user_id', $user->id)
              ->select('deal_id')
              ->distinct();

          $q = DB::query()
              ->fromSub($dealIdsSub, 'du')
              ->join('deals', 'deals.id', '=', 'du.deal_id')
              ->whereBetween('deals.deal_date', [$start->toDateString(), $end->toDateString()])
              ->selectRaw('COUNT(*) as deals_count')
              ->selectRaw('COALESCE(SUM(deals.property_value),0) as sales_value')
              ->selectRaw('COALESCE(SUM(deals.total_commission),0) as total_commission')
              ->first();

          $actualDealsCount = (int)($q->deals_count ?? 0);
          $actualSalesValue = (float)($q->sales_value ?? 0);
          $totalCommission  = (float)($q->total_commission ?? 0);

          $avgSalePriceActual = $actualDealsCount > 0 ? ($actualSalesValue / $actualDealsCount) : 0.0;

          // Commission % (as written on deals): INCL VAT commission / selling price
          $effectiveCommissionPercent = $actualSalesValue > 0 ? (($totalCommission / $actualSalesValue) * 100.0) : 0.0;
          // --- ALL-TIME Actuals from deals (same rules, no date filter) ---
          $qAll = DB::query()
              ->fromSub($dealIdsSub, 'du_all')
              ->join('deals', 'deals.id', '=', 'du_all.deal_id')
              ->selectRaw('COUNT(*) as deals_count')
              ->selectRaw('COALESCE(SUM(deals.property_value),0) as sales_value')
              ->selectRaw('COALESCE(SUM(deals.total_commission),0) as total_commission')
              ->first();

          $actualDealsCountAll = (int)($qAll->deals_count ?? 0);
          $actualSalesValueAll = (float)($qAll->sales_value ?? 0);
          $totalCommissionAll  = (float)($qAll->total_commission ?? 0);

          $avgSalePriceAll = $actualDealsCountAll > 0 ? ($actualSalesValueAll / $actualDealsCountAll) : 0.0;

          // Commission % (as written on deals): INCL VAT commission / selling price
          $effectiveCommissionPercentAll = $actualSalesValueAll > 0 ? (($totalCommissionAll / $actualSalesValueAll) * 100.0) : 0.0;

        // --- Actuals from daily activities (V2) ---
        $period = $month->format('Y-m');

        // How many distinct days have entries this month (V2)
        $dailyRows = (int) DB::table('daily_activity_entries')
            ->where('user_id', $user->id)
            ->where('period', $period)
            ->distinct('activity_date')
            ->count('activity_date');


        $dailyRowsAllTime = (int) DB::table('daily_activity_entries')
            ->where('user_id', $user->id)
            ->distinct('activity_date')
            ->count('activity_date');


        // --- Points (weighted activity scoring) (V2) ---
        // Sum(value * weight) for enabled definitions visible to this user's branch.
        $pointsActual = (float) DB::table('daily_activity_entries as e')
            ->join('activity_definitions as d', 'd.id', '=', 'e.activity_definition_id')
            ->where('e.user_id', $user->id)
            ->where('e.period', $period)
            ->where('d.is_enabled', 1)
            ->where(function($q) use ($user) {
                $q->where('d.scope', 'global');
                if ($user->branch_id) {
                    $q->orWhere(function($q2) use ($user) {
                        $q2->where('d.scope', 'branch')
                           ->where('d.branch_id', (int)$user->branch_id);
                    });
                }
            })
            ->sum(DB::raw('e.value * d.weight'));

        // Points target comes from targets.points_target (V2)
        $pointsTarget = (float) (DB::table('targets')
            ->where('user_id', $user->id)
            ->where('period', $period)
            ->value('points_target') ?? 0);

        $pointsPct = $pointsTarget > 0 ? round(($pointsActual / $pointsTarget) * 100, 1) : null;

        // --- Points by date (V2) for Momentum strip ---
        $pointsByDate = DB::table('daily_activity_entries as e')
            ->join('activity_definitions as d', 'd.id', '=', 'e.activity_definition_id')
            ->where('e.user_id', $user->id)
            ->where('e.period', $period)
            ->where('d.is_enabled', 1)
            ->where(function ($q) use ($user) {
                $q->where('d.scope', 'global');
                if ($user->branch_id) {
                    $q->orWhere(function ($q2) use ($user) {
                        $q2->where('d.scope', 'branch')
                           ->where('d.branch_id', (int)$user->branch_id);
                    });
                }
            })
            ->groupBy('e.activity_date')
            ->selectRaw("e.activity_date as d, SUM(e.value * d.weight) as pts")
            ->pluck('pts', 'd')
            ->toArray();

        // Legacy momentum strip uses these; keep safe defaults to avoid crashes.
        $weights = $weights ?? [];
        $dailyCols = $dailyCols ?? [];


        // Keep legacy weights/daily maps untouched for now (agent points display uses V2 fields above).

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

        if ($user->branch_id) {
            $svc = app(\App\Services\Admin\CompanyPerformanceService::class);
            $branch = $svc->getBranchRollup((int)$user->branch_id, $month->format('Y-m'));
            $company = $svc->getPeriodRollup($month->format('Y-m'));

            $branchTotals = $branch['totals'] ?? null;
            $companyTotals = $company['totals'] ?? null;
        }

        // --- Daily breakdown (per day map) ---
        $dailyMap = [];
        $dailyRaw = DB::table('daily_activities')
            ->where('user_id', $user->id)
            ->whereBetween('activity_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw("activity_date, COUNT(*) as rows_count")
            ->groupBy('activity_date')
            ->get();

        $dailyMapCounts = [];

        foreach ($dailyRaw as $d) {
            $dailyMap[$d->activity_date] = [
                'daily_rows' => (int)$d->rows_count,
                'deals' => 0,
                'value' => 0,
            ];

            $dailyMapCounts[$d->activity_date] = (int)$d->rows_count;
        }
        // --- Daily rows by date (for Momentum strip) ---
        // Build per-day sums only for weighted columns that exist in daily_activities.
        $dailyRowsByDate = [];

        $sumCols = [];
        foreach ($weights as $key => $w) {
            if (!in_array($key, $dailyCols, true)) continue;
            $col = '"' . str_replace('"', '""', $key) . '"';
            $sumCols[] = "COALESCE(SUM($col),0) AS $key";
        }

        if (!empty($sumCols)) {
            $dailySums = DB::table('daily_activities')
                ->where('user_id', $user->id)
                ->whereBetween('activity_date', [$start->toDateString(), $end->toDateString()])
                ->selectRaw("activity_date, " . implode(', ', $sumCols))
                ->groupBy('activity_date')
                ->get();

            foreach ($dailySums as $row) {
                $d = $row->activity_date;
                $dailyRowsByDate[$d] = [];
                foreach ($weights as $key => $w) {
                    if (!in_array($key, $dailyCols, true)) continue;
                    $dailyRowsByDate[$d][$key] = (float)($row->$key ?? 0);
                }
            }
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
        ];
    }
}
