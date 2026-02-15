<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\MonthlyTargetGoal;
use App\Services\Admin\CompanyPerformanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BranchPerformanceController extends Controller
{
    public function index(Request $request, CompanyPerformanceService $service, int $branchId)
    {
        $period = $request->query('period') ?: Carbon::now()->format('Y-m');

        // Basic branch existence check (safe)
        $branchName = DB::table('branches')->where('id', $branchId)->value('name');
        abort_unless($branchName, 404);

        // Same truth engine BM uses
        $rollup = $service->getBranchRollup($branchId, $period);

        // Ensure goal exists (same as BM)
        $branchGoal = MonthlyTargetGoal::firstOrCreate(
            ['branch_id' => $branchId, 'period' => $period],
            ['listings_target' => 0, 'deals_target' => 0, 'value_target' => 0, 'branch_budget' => 0]
        );

        // Income projection from agent targets (value sum -> income)
        $commissionRate = (float) config('performance.commission_rate', 0.05);
        $companyShare   = (float) config('performance.company_share', 0.50);

        $agentValueTargetSum = (float)($rollup['totals']['targets']['value'] ?? 0);
        $projectedIncome = $agentValueTargetSum * $commissionRate * $companyShare;

        $branchBudget = (float)($branchGoal->branch_budget ?? 0);
        $shortAmount = max($branchBudget - $projectedIncome, 0);
        $shortPct = ($branchBudget > 0) ? ($shortAmount / $branchBudget) * 100 : 0;

        $statusSummary = Deal::statusSummaryForBranch($branchId, $period);

        // TV link (same shape BM uses)
        $tvUrl = url("/tv/branch/{$branchId}?token=" . env("TV_TOKEN") . "&period={$period}");

        // IMPORTANT: Use the BM view so Admin sees EXACTLY the BM branch screen
        return view('bm.performance', [
            'tvUrl' => $tvUrl,
            'statusSummary' => $statusSummary,
            'rollup' => $rollup,
            'branchGoal' => $branchGoal,
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
