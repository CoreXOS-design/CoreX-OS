<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Monthly Goals moved (Agency Tracker re-home step 4): what the agency / a branch needs is Road to Sold settings ->
 * Budgets; what agents work towards is Road to Sold settings -> Targets (its totals are the old branch / company
 * roll-ups). The route names are kept so older links resolve; nothing is saved here any more.
 */
class MonthlyGoalController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('manage_targets'), 403);

        return redirect()->to(route('road-to-sold.settings.budgets.index', array_filter(['period' => $request->query('period')])), 301);
    }

    /** A stale Monthly Goals page posting after the move: say so plainly, save nothing. */
    public function save(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('manage_targets'), 403);

        return redirect()->route('road-to-sold.settings.budgets.index', array_filter(['period' => $request->input('period')]))
            ->with('error', 'Monthly Goals has moved. Nothing was saved from that page - set what the agency and each branch need here, and agents\' targets under Targets.');
    }
}
