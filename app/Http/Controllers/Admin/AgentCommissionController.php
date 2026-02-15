<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Http\Request;

class AgentCommissionController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(auth()->user()?->isEffectiveAdmin(), 403);

        $period = $request->query('period', now()->format('Y-m'));

        // Deals for period (actuals)
        $deals = Deal::where('period', $period)->get();

        // Worksheet splits for period (planned split % per agent)
        $worksheets = Worksheet::where('period', $period)->get()->keyBy('user_id');

        // Sum allocated per user for the period (this matches ZIP "Allocated Share" concept)
        $allocatedTotals = []; // user_id => amount
        foreach ($deals as $deal) {
            foreach ($deal->allocations() as $userId => $amount) {
                $allocatedTotals[$userId] = ($allocatedTotals[$userId] ?? 0) + (float) $amount;
            }
        }

        $rows = [];
        $totals = [
            'allocated' => 0.0,
            'agent_gross' => 0.0,
            'company' => 0.0,
            'missing_split_count' => 0,
        ];

        foreach ($allocatedTotals as $userId => $allocated) {
            $user = User::find($userId);
            if (!$user) continue;

            $allocated = (float) $allocated;

            $w = $worksheets->get($userId);
            $splitPercent = $w ? (float) $w->agent_split_percent : null;

            // ZIP-aligned: Agent Gross = Allocated * split%, Company = remainder
            $agentGross = null;
            $company = null;

            if ($splitPercent !== null) {
                $agentGross = $allocated * ($splitPercent / 100);
                $company = $allocated - $agentGross;

                $totals['agent_gross'] += $agentGross;
                $totals['company'] += $company;
            } else {
                $totals['missing_split_count']++;
            }

            $totals['allocated'] += $allocated;

            $rows[] = [
                'name' => $user->name,
                'allocated' => $allocated,
                'split_percent' => $splitPercent,
                'agent_gross' => $agentGross,
                'company' => $company,
                'has_split' => ($splitPercent !== null),
            ];
        }

        // Sort by allocated desc (like "biggest impact first")
        usort($rows, function ($a, $b) {
            return ($b['allocated'] <=> $a['allocated']);
        });

        return view('admin.agent-commission.index', [
            'period' => $period,
            'rows' => $rows,
            'totals' => $totals,
        ]);
    }
}
