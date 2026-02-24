<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\CompanyPerformanceService;
use App\Services\Finance\CommissionCalculator;
use App\Services\Finance\FinanceReadModel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AgentPerformanceController extends Controller
{
    public function show(Request $request, int $userId, CompanyPerformanceService $service)
    {
        $period = $request->query('period') ?: Carbon::now()->format('Y-m');
        $start = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $end   = (clone $start)->endOfMonth();

        // Base agent identity
        $agent = DB::table('users')->where('id', $userId)->select('id','name','email','role','branch_id')->first();
        abort_unless($agent && strtolower(trim((string)($agent->role ?? ''))) === 'agent', 404);

        $branchName = null;
        if (!empty($agent->branch_id)) {
            $branchName = DB::table('branches')->where('id', $agent->branch_id)->value('name');
        }

        // Targets (include points_target so WOW view can show points target)
        $target = DB::table('targets')
            ->where('user_id', $agent->id)
            ->where('period', $period)
            ->select('deals_target','listings_target','value_target','points_target')
            ->first();

        $targets = [
            'deals'    => (int)($target->deals_target ?? 0),
            'listings' => (int)($target->listings_target ?? 0),
            'value'    => (float)($target->value_target ?? 0),
            'points'   => (float)($target->points_target ?? 0),
        ];

        // Deal actuals (count + sales value) — exclude declined
        $dealActuals = DB::table('deal_user')
            ->join('deals','deals.id','=','deal_user.deal_id')
            ->where('deal_user.user_id', $agent->id)
            ->whereBetween('deals.deal_date', [$start->toDateString(), $end->toDateString()])
            ->whereRaw("COALESCE(deals.accepted_status,'') != 'D'")
            ->selectRaw('COUNT(DISTINCT deal_user.deal_id) as deals_count, COALESCE(SUM(deals.property_value),0) as sales_value')
            ->first();

        $dealsDone  = (int)($dealActuals->deals_count ?? 0);
        $salesValue = (float)($dealActuals->sales_value ?? 0);

        // WOW rollup: points + momentum + activities_today (branch/team logic irrelevant here; it's per-agent)
        $wow = $service->getAgentRollup((int)($agent->branch_id ?? 0), (int)$agent->id, $period);
        $pts = $wow['points'] ?? [];

        // Deals list (for table) — exclude declined, join deal_money_lines for settlement truth
        $deals = DB::table('deal_user')
            ->join('deals','deals.id','=','deal_user.deal_id')
            ->leftJoin('users','users.id','=','deal_user.user_id')
            ->leftJoin('deal_money_lines as dml', function ($join) {
                $join->on('dml.deal_id', '=', 'deals.id')
                     ->on('dml.user_id', '=', 'deal_user.user_id')
                     ->on('dml.side', '=', 'deal_user.side');
            })
            ->where('deal_user.user_id', $agent->id)
            ->whereBetween('deals.deal_date', [$start->toDateString(), $end->toDateString()])
            ->whereRaw("COALESCE(deals.accepted_status,'') != 'D'")
            ->orderBy('deals.deal_date','desc')
            ->select(
                'deals.deal_date','deals.file_no','deals.deal_no','deals.property_address',
                'deals.property_value','deals.total_commission',
                'deals.listing_external','deals.listing_our_share_percent',
                'deals.selling_external','deals.selling_our_share_percent',
                'deals.listing_split_percent','deals.selling_split_percent',
                'deal_user.side','deal_user.agent_split_percent','deal_user.agent_cut_percent',
                'users.agent_cut_percent as user_default_split_percent',
                'dml.pool_share_ex_vat as dml_company_income',
                'dml.agent_gross_ex_vat as dml_agent_income',
                'dml.company_gross_ex_vat as dml_company_retained'
            )
            ->get()
            ->map(function ($d) {
                // Settlement truth from deal_money_lines (primary), inline calc fallback
                if (!is_null($d->dml_company_income ?? null)) {
                    $d->company_income_ex_vat = round((float)$d->dml_company_income, 2);
                    $d->agent_income_ex_vat = round((float)$d->dml_agent_income, 2);
                    $d->company_retained_ex_vat = round((float)$d->dml_company_retained, 2);
                } else {
                    $sideIncomeExVat = (float) CommissionCalculator::companyIncomeExVatForSide($d, $d->side ?? null);
                    $cut = (float)($d->agent_cut_percent ?? 0);
                    if ($cut < 0) $cut = 0;
                    if ($cut > 100) $cut = 100;

                    $agentIncome = round($sideIncomeExVat * ($cut / 100.0), 2);
                    $retained = round(max(0.0, $sideIncomeExVat - $agentIncome), 2);

                    $d->company_income_ex_vat = round($sideIncomeExVat, 2);
                    $d->agent_income_ex_vat = $agentIncome;
                    $d->company_retained_ex_vat = $retained;
                }
                return $d;
            });

        // Daily rows count for this period
        $dailyRowsCount = (int) DB::table('daily_activities')
            ->where('user_id', $agent->id)
            ->where('period', $period)
            ->count();

        // Money tiles: deal_money_lines settlement truth (already joined above, declined excluded)
        $moneyCompanyIncome   = (float) $deals->sum('company_income_ex_vat');
        $moneyAgentIncome     = (float) $deals->sum('agent_income_ex_vat');
        $moneyCompanyRetained = (float) $deals->sum('company_retained_ex_vat');
        $actuals = [
            'deals'            => $dealsDone,
            'sales_value'      => $salesValue,
            'value'            => $salesValue,
            'daily_rows'       => $dailyRowsCount,
            'points'           => (float)($pts['actual'] ?? 0),
            'company_income'   => round($moneyCompanyIncome, 2),
            'agent_income'     => round($moneyAgentIncome, 2),
            'company_retained' => round($moneyCompanyRetained, 2),
        ];

        $progress = [
            // value/deals progress is still useful for the WOW header bars
            'deals_pct' => $targets['deals'] > 0 ? round(($dealsDone / $targets['deals']) * 100, 1) : 0,
            'value_pct' => $targets['value'] > 0 ? round(($salesValue / $targets['value']) * 100, 1) : 0,

            // points progress (from service)
            'points_pct' => (float)($pts['pct'] ?? 0),
            'points_status' => (string)($pts['status'] ?? '—'),
            'points_per_day_needed' => (float)($pts['per_day_needed'] ?? 0),
        ];

        return view('admin.agent-performance', [
            'period' => $period,
            'agent' => $agent,
            'branchName' => $branchName,
            'targets' => $targets,
            'actuals' => $actuals,
            'progress' => $progress,
            'deals' => $deals,
            'momentum_7d' => $wow['momentum_7d'] ?? [],
            'activities_today' => $wow['activities_today'] ?? [],
        ]);
    }
}
