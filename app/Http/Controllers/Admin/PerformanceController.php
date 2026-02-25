<?php

namespace App\Http\Controllers\Admin;

use App\Models\MonthlyTargetGoal;
use App\Models\Deal;
use App\Http\Controllers\Controller;
use App\Services\Admin\CompanyPerformanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PerformanceController extends Controller
{
    public function index(Request $request, CompanyPerformanceService $svc)
    {
        $period = $request->query('period');
        if (!$period) {
            $period = Carbon::now()->format('Y-m');
        }

        $rollup = $svc->getPeriodRollup($period);

        // Branch budgets (per period) for Admin branch cards
        $branchBudgets = MonthlyTargetGoal::query()
            ->where('period', $period)
            ->whereNotNull('branch_id')
            ->pluck('branch_budget', 'branch_id')
            ->toArray();

        // Attach budget onto each branch row in rollup
        if (isset($rollup['branches']) && is_array($rollup['branches'])) {
            foreach ($rollup['branches'] as $i => $b) {
                $bid = (int)($b['branch_id'] ?? 0);
                $rollup['branches'][$i]['branch_budget'] = (float)($branchBudgets[$bid] ?? 0);
            }
        }


        $statusSummary = Deal::statusSummaryForCompany($period);

        
        /* ADMIN_POINTS_STRUCT_PATCH */
        // Blade expects $r['points'] struct (company aggregated).
        // Monthly totals come from rollup totals; today_points computed from entries (enabled defs only).
        $start = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $end   = (clone $start)->endOfMonth();

        $today = Carbon::today();
        $daysInMonth = $start->daysInMonth ?? 30;
        $daysElapsed = $today->betweenIncluded($start, $end) ? max(1, $start->diffInDays($today) + 1) : 1;
        $daysLeft    = $today->betweenIncluded($start, $end) ? max(0, $today->diffInDays($end)) : 0;

        $pointsTarget = (float)($rollup['totals']['targets']['points'] ?? 0);
        $pointsActual = (float)($rollup['totals']['actuals']['points'] ?? 0);
        $pointsPct    = ($pointsTarget > 0) ? round(($pointsActual / $pointsTarget) * 100, 1) : 0.0;

        $pointsRemaining   = max(0.0, $pointsTarget - $pointsActual);
        $pointsPerDayNeeded = ($daysLeft > 0) ? round(($pointsRemaining / $daysLeft), 1) : $pointsRemaining;

        $pointsStatus = '—';
        if ($pointsTarget > 0) {
            $expectedByNow = ($pointsTarget / $daysInMonth) * $daysElapsed;
            if ($pointsActual >= $pointsTarget) $pointsStatus = 'Achieved';
            elseif ($pointsActual >= $expectedByNow * 1.05) $pointsStatus = 'Ahead';
            elseif ($pointsActual >= $expectedByNow * 0.95) $pointsStatus = 'On pace';
            else $pointsStatus = 'Behind';
        }

        // Today points (company): sum(e.value * d.weight) for enabled definitions (global + any branch definitions).
        $todayPoints = (float) \DB::table('daily_activity_entries as e')
            ->join('activity_definitions as d', 'd.id', '=', 'e.activity_definition_id')
            ->where('e.period', $period)
            ->where('e.activity_date', $today->toDateString())
            ->where('d.is_enabled', 1)
            ->sum(\DB::raw('e.value * d.weight'));

        $rollup['points'] = [
            'actual' => $pointsActual,
            'target' => $pointsTarget,
            'pct' => $pointsPct,
            'status' => $pointsStatus,
            'remaining' => $pointsRemaining,
            'per_day_needed' => $pointsPerDayNeeded,
            'today_points' => $todayPoints,
            'days_left' => $daysLeft,
        ];
        /* ADMIN_POINTS_STRUCT_PATCH_END */

        // -----------------------------
        // Listing Stock Stats (Company)
        // -----------------------------
        $domExpr  = "(julianday(date('now')) - julianday(date(coalesce(listed_at, created_at))))";
        $editExpr = "(julianday(date('now')) - julianday(date(coalesce(modified_at, created_at))))";

        $listingBase = \App\Models\ListingStock::query()
            ->where('source', 'propcon')
            ->where(function ($q) {
                $q->whereRaw("lower(coalesce(status,'')) like '%active%'")
                  ->orWhereRaw("lower(coalesce(status,'')) like '%for sale%'");
            });

        $totalListings = (clone $listingBase)->count();

        $avgDom = (clone $listingBase)
            ->selectRaw("avg(" . $domExpr . ") as v")
            ->value('v');
        $avgDom = $avgDom !== null ? (int) round($avgDom) : 0;

        $staleCount = (clone $listingBase)
            ->whereRaw($editExpr . " >= 14")
            ->count();

        $expiringSoonCount = (clone $listingBase)
            ->whereNotNull('expires_at')
            ->whereRaw("(julianday(date(expires_at)) - julianday(date('now'))) between 0 and 14")
            ->count();

        $expiredCount = (clone $listingBase)
            ->whereNotNull('expires_at')
            ->whereRaw("julianday(date(expires_at)) < julianday(date('now'))")
            ->count();

        $listingStats = [
            'total' => (int) $totalListings,
            'avg_days_on_market' => (int) $avgDom,
            'stale' => (int) $staleCount,
            'expiring_soon' => (int) $expiringSoonCount,
            'expired' => (int) $expiredCount,
        ];


        // Active TV codes for all branches (admin visibility)
        $tvCodes = \App\Models\TvAccessCode::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->whereNotNull('branch_id')
            ->with(['branch:id,name', 'creator:id,name'])
            ->orderBy('branch_id')
            ->get();

        // Active company TV code (branch_id IS NULL)
        $companyTvCode = \App\Models\TvAccessCode::forCompany()
            ->active()
            ->with(['creator:id,name'])
            ->first();

        $branches = \App\Models\Branch::orderBy('name')->get(['id', 'name']);

        return view('admin.performance', [
            'rollup' => $rollup,
            'listingStats' => $listingStats,
            'tvCodes' => $tvCodes,
            'companyTvCode' => $companyTvCode,
            'branches' => $branches,
'statusSummary' => $statusSummary,
        ]);
    }
}
