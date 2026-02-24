<?php

namespace App\Http\Controllers\BM;

use App\Http\Controllers\Controller;
use App\Services\Admin\CompanyPerformanceService;
use App\Services\Finance\CommissionCalculator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AgentPerformanceController extends Controller
{
    public function show(Request $request, CompanyPerformanceService $service, int $userId)
    {
        $u = $request->user();
        $role = strtolower(trim((string)($u?->role ?? '')));
        abort_unless($u && $role === 'branch_manager', 403);

        $bmBranchId = (int)($u->branch_id ?? 0);
        abort_unless($bmBranchId > 0, 403);

        $period = $request->query('period') ?: Carbon::now()->format('Y-m');
        abort_unless((bool)preg_match('/^\d{4}-\d{2}$/', $period), 422);

        // Source of truth: same service as BM Performance + Agent dashboard (read-only)
        $payload = $service->getAgentRollup($bmBranchId, $userId, $period);
        $agentRow = $payload['agent'];

        // Human branch name
        $branchName = DB::table('branches')->where('id', $bmBranchId)->value('name');

        // Deals list for this agent + period (for table)
        $start = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $end   = (clone $start)->endOfMonth();

        $deals = DB::table('deal_user')
            ->join('deals','deals.id','=','deal_user.deal_id')
            ->where('deal_user.user_id', (int)$userId)
            ->whereBetween('deals.deal_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('deals.deal_date','desc')
            ->select(
                'deals.id as deal_id',
                'deals.deal_date','deals.file_no','deals.deal_no','deals.property_address',
                'deals.property_value','deals.total_commission',
                'deals.listing_external','deals.listing_our_share_percent',
                'deals.selling_external','deals.selling_our_share_percent',
                'deal_user.side','deal_user.agent_split_percent','deal_user.agent_cut_percent'
            )
            ->get();

        // Compute per-deal income columns for WOW table (ex VAT, split, retained)
        $dealsComputed = $deals->map(function ($d) {
            $sideIncomeExVat = (float) CommissionCalculator::companyIncomeExVatForSide($d, $d->side ?? null);
            $vatRatePercent = (float) \App\Models\PerformanceSetting::get('vat_rate', 15);
            $vatRate = $vatRatePercent / 100;
            $grossIncVat = (float)($d->total_commission ?? 0);
            $grossExVat = ($grossIncVat > 0) ? ($grossIncVat / (1 + $vatRate)) : 0.0;


            $split = (float)($d->agent_split_percent ?? 0);
            if ($split < 0) $split = 0;
            if ($split > 100) $split = 100;

            $agentIncome = round($sideIncomeExVat * ($split / 100.0), 2);
            $companyRetained = round($sideIncomeExVat - $agentIncome, 2);

            $d->company_income_ex_vat = round($sideIncomeExVat, 2);
            $d->agent_income_ex_vat = $agentIncome;
            $d->company_retained_ex_vat = $companyRetained;

            return $d;
        });

        return view('bm.agent-performance', [
            'period' => $period,

            // Keep old keys for UI stability, but now sourced from service
            'agent' => (object)[
                'id' => (int)($agentRow['user_id'] ?? 0),
                'name' => (string)($agentRow['name'] ?? ''),
                'email' => (string)($agentRow['email'] ?? ''),
            ],
            'branchName' => $branchName,

            'targets' => [
                'deals' => (int)($agentRow['targets']['deals'] ?? 0),
                'listings' => (int)($agentRow['targets']['listings'] ?? 0),
                'value' => (float)($agentRow['targets']['value'] ?? 0),
                'points' => (float)($agentRow['targets']['points'] ?? 0),
            ],
            'actuals' => [
                'deals' => (int)($agentRow['actuals']['deals'] ?? 0),
                'sales_value' => (float)($agentRow['actuals']['sales_value'] ?? 0),
                'value' => (float)($agentRow['actuals']['value'] ?? ($agentRow['actuals']['sales_value'] ?? 0)),
                'daily_rows' => (int)($agentRow['actuals']['daily_rows'] ?? 0),
                'points' => (float)($agentRow['actuals']['points'] ?? 0),

                // Money-first truth (ex VAT)
                'company_income' => (float)($agentRow['actuals']['company_income'] ?? 0),
                'agent_income' => (float)($agentRow['actuals']['agent_income'] ?? 0),
                'company_retained' => (float)($agentRow['actuals']['company_retained'] ?? 0),
            ],
            'progress' => [
                'deals_pct' => (float)($agentRow['progress']['deals_pct'] ?? 0),
                'value_pct' => (float)($agentRow['progress']['value_pct'] ?? 0),
                'points_pct' => (float)($agentRow['progress']['points_pct'] ?? 0),
                'points_status' => (string)($agentRow['progress']['points_status'] ?? '—'),
                'points_per_day_needed' => (float)($agentRow['progress']['points_per_day_needed'] ?? 0),
            ],

            // New WOW blocks
            'momentum_7d' => $payload['momentum_7d'] ?? [],
            'activities_today' => $payload['activities_today'] ?? [],

            // Table data
            'deals' => $dealsComputed,
        ]);
    }
}
