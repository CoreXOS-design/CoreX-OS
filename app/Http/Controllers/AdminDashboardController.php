<?php

namespace App\Http\Controllers;

use App\Models\CompanyExpense;
use App\Models\Worksheet;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    public function index(Request $request)
    {
        // Default: show the new Admin Control Centre dashboard (no heavy data).
        // Legacy cashflow view remains available via ?view=cashflow
        $view = strtolower((string) $request->query('view', 'control'));

        if ($view !== 'cashflow') {
            return view('admin.dashboard');
        }

        // ---- Legacy cashflow dashboard (kept intact) ----
        $period = $request->query('period', now()->format('Y-m'));

        // Fetch all worksheets for the selected period
        $worksheets = Worksheet::with('user')
            ->where('period', $period)
            ->get();

        // Get or create company expense record for this period
        $expense = CompanyExpense::firstOrCreate(
            ['period' => $period],
            ['monthly_expenses' => 0]
        );

        $agentRows = [];

        $totals = [
            'agency_gross_commission' => 0,  // before split
            'company_income' => 0,           // after split (company share)
            'agent_income' => 0,             // agent share
            'monthly_expenses' => (float) $expense->monthly_expenses,
            'cashflow' => 0,
            'agents_count' => 0,
        ];

        foreach ($worksheets as $w) {
            // Use existing worksheet calculator for sales needed and commission per sale
            $calc = \App\Http\Controllers\WorksheetController::calculate($w);

            $salesNeeded = (float) $calc['sales_needed_per_month'];
            $commissionPerSale = (float) $calc['commission_per_sale'];

            $agentSplit = ((float) $w->agent_split_percent) / 100;
            $companySplit = 1 - $agentSplit;

            // Monthly values for this agent, based on their captured targets
            $agentGrossCommission = $salesNeeded * $commissionPerSale; // total commission generated
            $companyIncome = $agentGrossCommission * $companySplit;
            $agentIncome = $agentGrossCommission * $agentSplit;

            $totals['agency_gross_commission'] += $agentGrossCommission;
            $totals['company_income'] += $companyIncome;
            $totals['agent_income'] += $agentIncome;

            $agentRows[] = [
                'name' => $w->user->name,
                'sales_needed_per_month' => $salesNeeded,
                'agent_split_percent' => (float) $w->agent_split_percent,
                'commission_per_sale' => $commissionPerSale,
                'company_income' => $companyIncome,
            ];
        }

        $totals['agents_count'] = count($agentRows);
        $totals['cashflow'] = $totals['company_income'] - $totals['monthly_expenses'];

        // Sort by highest company income contribution (optional)
        usort($agentRows, function ($a, $b) {
            return $b['company_income'] <=> $a['company_income'];
        });

        return view('admin.dashboard_cashflow', [
            'period' => $period,
            'expense' => $expense,
            'agentRows' => $agentRows,
            'totals' => $totals,
        ]);
    }

    public function saveExpenses(Request $request)
    {
        $data = $request->validate([
            'period' => ['required', 'string', 'max:7', 'regex:/^\d{4}-\d{2}$/'],
            'monthly_expenses' => ['required', 'numeric', 'min:0'],
        ]);

        CompanyExpense::updateOrCreate(
            ['period' => $data['period']],
            ['monthly_expenses' => $data['monthly_expenses']]
        );

        return redirect()
            ->route('admin.dashboard', ['period' => $data['period'], 'view' => 'cashflow'])
            ->with('status', 'Company expenses saved for ' . $data['period']);
    }
}
