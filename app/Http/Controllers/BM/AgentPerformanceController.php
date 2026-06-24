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
        abort_unless($u && $u->hasPermission('view_performance'), 403);

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
            ->leftJoin('deal_money_lines as dml', function ($join) {
                $join->on('dml.deal_id', '=', 'deals.id')
                     ->on('dml.user_id', '=', 'deal_user.user_id')
                     ->on('dml.side', '=', 'deal_user.side')
                     ->whereNull('dml.deleted_at'); // exclude rebuilt-over (trashed) projection rows
            })
            ->where('deal_user.user_id', (int)$userId)
            ->whereBetween('deals.deal_date', [$start->toDateString(), $end->toDateString()])
            ->whereRaw("COALESCE(deals.accepted_status,'') != 'D'")
            ->orderBy('deals.deal_date','desc')
            ->select(
                'deals.id as deal_id',
                'deals.deal_date','deals.file_no','deals.deal_no','deals.property_address',
                'deals.property_value','deals.total_commission',
                'deals.listing_external','deals.listing_our_share_percent',
                'deals.selling_external','deals.selling_our_share_percent',
                'deal_user.side','deal_user.agent_split_percent','deal_user.agent_cut_percent',
                'dml.pool_share_ex_vat as dml_company_income',
                'dml.agent_gross_ex_vat as dml_agent_income',
                'dml.company_gross_ex_vat as dml_company_retained'
            )
            ->get();

        // Per-deal income columns: settlement truth from deal_money_lines (primary), inline calc fallback
        $dealsComputed = $deals->map(function ($d) {
            if (!is_null($d->dml_company_income)) {
                $d->company_income_ex_vat = round((float)$d->dml_company_income, 2);
                $d->agent_income_ex_vat = round((float)$d->dml_agent_income, 2);
                $d->company_retained_ex_vat = round((float)$d->dml_company_retained, 2);
            } else {
                $sideIncomeExVat = (float) CommissionCalculator::companyIncomeExVatForSide($d, $d->side ?? null);
                $cut = (float)($d->agent_cut_percent ?? 0);
                if ($cut < 0) $cut = 0;
                if ($cut > 100) $cut = 100;

                $agentIncome = round($sideIncomeExVat * ($cut / 100.0), 2);
                $companyRetained = round($sideIncomeExVat - $agentIncome, 2);

                $d->company_income_ex_vat = round($sideIncomeExVat, 2);
                $d->agent_income_ex_vat = $agentIncome;
                $d->company_retained_ex_vat = $companyRetained;
            }
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

                // Money-first truth (ex VAT) — from deal_money_lines settlement
                'company_income' => round((float)$dealsComputed->sum('company_income_ex_vat'), 2),
                'agent_income' => round((float)$dealsComputed->sum('agent_income_ex_vat'), 2),
                'company_retained' => round((float)$dealsComputed->sum('company_retained_ex_vat'), 2),
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
