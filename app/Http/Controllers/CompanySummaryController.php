<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Worksheet;
use App\Models\CompanyExpense;
use App\Models\ListingTarget;
use App\Http\Controllers\WorksheetController;
use App\Models\PerformanceSetting;

class CompanySummaryController extends Controller
{
    public function index(Request $request)
    {
        
        $listingsPerSale = (float) PerformanceSetting::get('listings_per_sale', 5);
        if ($listingsPerSale <= 0) { $listingsPerSale = 5; }

$period = $request->query('period', now()->format('Y-m'));

        $worksheets = Worksheet::with('user')->where('period', $period)->get();

        $companyExpense = CompanyExpense::firstOrCreate(
            ['period' => $period],
            ['monthly_expenses' => 0]
        );

        $monthlyExpenses = (float) $companyExpense->monthly_expenses;

        // Load targets for this period (keyed by user_id)
        $targetsByUser = ListingTarget::where('period', $period)
            ->get()
            ->keyBy('user_id');

        $actual = ['listings'=>0.0,'cp_listings'=>0.0,'sales'=>0.0,'agency_gross'=>0.0,'company_income'=>0.0,'agent_net'=>0.0];
        $target = ['listings'=>0.0,'cp_listings'=>0.0,'sales'=>0.0,'agency_gross'=>0.0,'company_income'=>0.0,'agent_net'=>0.0];
        $recommended = ['listings'=>0.0,'cp_listings'=>0.0,'sales'=>0.0,'agency_gross'=>0.0,'company_income'=>0.0,'agent_net'=>0.0];

        foreach ($worksheets as $w) {
            $calc = WorksheetController::calculate($w);

            $commissionPerSale = (float) $calc['commission_per_sale'];
            $agentNetPerSale   = (float) $calc['agent_net_per_sale'];
            $companyPerSale    = (float) $calc['company_income_per_sale'];

            $cpFactor = ((float) $w->correctly_priced_percent) / 100;

            // ACTUAL
            $aListings = (float) $w->current_listings;
            $aCP = $aListings * $cpFactor;
            $aSales = $aCP / $listingsPerSale;

            $actual['listings'] += $aListings;
            $actual['cp_listings'] += $aCP;
            $actual['sales'] += $aSales;
            $actual['agency_gross'] += $aSales * $commissionPerSale;
            $actual['company_income'] += $aSales * $companyPerSale;
            $actual['agent_net'] += $aSales * $agentNetPerSale;

            // TARGET (from listing_targets for this period)
            $tListings = (float) ($targetsByUser->get($w->user_id)?->target_listings ?? 0);
            $tCP = $tListings * $cpFactor;
            $tSales = $tCP / $listingsPerSale;

            $target['listings'] += $tListings;
            $target['cp_listings'] += $tCP;
            $target['sales'] += $tSales;
            $target['agency_gross'] += $tSales * $commissionPerSale;
            $target['company_income'] += $tSales * $companyPerSale;
            $target['agent_net'] += $tSales * $agentNetPerSale;

            // RECOMMENDED
            $rListings = (float) $calc['total_listings_needed'];
            $rCP = $rListings * $cpFactor;
            $rSales = $rCP / $listingsPerSale;

            $recommended['listings'] += $rListings;
            $recommended['cp_listings'] += $rCP;
            $recommended['sales'] += $rSales;
            $recommended['agency_gross'] += $rSales * $commissionPerSale;
            $recommended['company_income'] += $rSales * $companyPerSale;
            $recommended['agent_net'] += $rSales * $agentNetPerSale;
        }

        return view('company.summary', [
            'period' => $period,
            'monthlyExpenses' => round($monthlyExpenses, 2),

            'actual' => [
                'listings' => round($actual['listings'], 2),
                'cp_listings' => round($actual['cp_listings'], 2),
                'sales' => round($actual['sales'], 2),
                'agency_gross' => round($actual['agency_gross'], 2),
                'company_income' => round($actual['company_income'], 2),
                'agent_net' => round($actual['agent_net'], 2),
                'cashflow' => round($actual['company_income'] - $monthlyExpenses, 2),
            ],

            'target' => [
                'listings' => round($target['listings'], 2),
                'cp_listings' => round($target['cp_listings'], 2),
                'sales' => round($target['sales'], 2),
                'agency_gross' => round($target['agency_gross'], 2),
                'company_income' => round($target['company_income'], 2),
                'agent_net' => round($target['agent_net'], 2),
                'cashflow' => round($target['company_income'] - $monthlyExpenses, 2),
            ],

            'recommended' => [
                'listings' => round($recommended['listings'], 2),
                'cp_listings' => round($recommended['cp_listings'], 2),
                'sales' => round($recommended['sales'], 2),
                'agency_gross' => round($recommended['agency_gross'], 2),
                'company_income' => round($recommended['company_income'], 2),
                'agent_net' => round($recommended['agent_net'], 2),
                'cashflow' => round($recommended['company_income'] - $monthlyExpenses, 2),
            ],
        ]);
    }
}
