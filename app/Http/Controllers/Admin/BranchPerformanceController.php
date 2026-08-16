<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\MonthlyTargetGoal;
use App\Services\Admin\CompanyPerformanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class BranchPerformanceController extends Controller
{
    public function index(Request $request, CompanyPerformanceService $service, int $branchId)
    {
        $period = $request->query('period') ?: Carbon::now()->format('Y-m');

        // Ownership check (was: existence-only). Branch::find() goes through
        // Eloquent, so it runs under the BelongsToAgency global scope
        // (AgencyScope): for a non-owner it 404s outright on a branch
        // belonging to a foreign tenant, exactly like the plain "does this id
        // exist" check used to for a missing id. Owners bypass AgencyScope
        // (see AgencyScope::applyInner()) so they can still reach any
        // agency's branch page by design.
        $branch = \App\Models\Branch::find($branchId);
        abort_unless($branch, 404);

        // getBranchRollup()/getPeriodRollup() run raw DB::table() queries
        // that bypass Eloquent's AgencyScope entirely, so they need the
        // agency filter passed explicitly. Use the BRANCH's own agency_id
        // (not the acting user's effectiveAgencyId()) — that keeps this
        // correct for an owner (whose effectiveAgencyId() is typically null)
        // as well as for an in-agency admin/BM, and it's already guaranteed
        // to match the caller's tenant by the Branch::find() check above.
        $agencyId = $branch->agency_id !== null ? (int) $branch->agency_id : -1;

        // Same truth engine BM uses
        $rollup = $service->getBranchRollup($branchId, $period, $agencyId);

        // Ensure goal exists (same as BM)
        $branchGoal = MonthlyTargetGoal::firstOrCreate(
            ['branch_id' => $branchId, 'period' => $period],
            ['listings_target' => 0, 'deals_target' => 0, 'value_target' => 0, 'branch_budget' => 0]
        );

        // Income projection from agent targets (value sum -> income)
        $commissionRate = (float) config('performance.commission_rate', 0.075);
        $companyShare   = (float) config('performance.company_share', 0.50);

        $agentValueTargetSum = (float)($rollup['totals']['targets']['value'] ?? 0);
        $projectedIncome = $agentValueTargetSum * $commissionRate * $companyShare;

        $branchBudget = (float)($branchGoal->branch_budget ?? 0);
        $shortAmount = max($branchBudget - $projectedIncome, 0);
        $shortPct = ($branchBudget > 0) ? ($shortAmount / $branchBudget) * 100 : 0;

        $statusSummary = Deal::statusSummaryForBranch($branchId, $period);

        // Deal Register averages (same as BM PerformanceController)
        $stageFilter = [
            'pending'    => $request->boolean('st_pending', true),
            'granted'    => $request->boolean('st_granted', true),
            'registered' => $request->boolean('st_registered', true),
        ];
        $marketAverages = Deal::marketAveragesForBranch($branchId, $period, $stageFilter);

        // TV link (same shape BM uses)
        $tvUrl = url("/tv/branch/{$branchId}?token=" . env("TV_TOKEN") . "&period={$period}");

        // IMPORTANT: Use the BM view so Admin sees EXACTLY the BM branch screen
        return view('bm.performance', [
            'tvUrl' => $tvUrl,
            'statusSummary' => $statusSummary,
            'rollup' => $rollup,
            'branchGoal' => $branchGoal,
            'marketAverages' => $marketAverages,
            'budget' => [
                'branch_budget' => $branchBudget,
                'projected_income' => $projectedIncome,
                'short_amount' => $shortAmount,
                'short_pct' => $shortPct,
                'commission_rate' => $commissionRate,
                'company_share' => $companyShare,
            ],
        ]);
    }
}
