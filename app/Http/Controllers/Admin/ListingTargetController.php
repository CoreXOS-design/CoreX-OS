<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Listing Targets moved (Agency Tracker re-home step 4): an agent's listings target is the "New listings" number of
 * their monthly targets (Road to Sold settings -> Targets), where it is shown against the listings they actually
 * took on. Old rows were carried across by migration 2026_11_27_110000. Route names kept; nothing is saved here.
 */
class ListingTargetController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('manage_targets'), 403);

        return redirect()->to(route('road-to-sold.settings.targets.index', array_filter(['period' => $request->query('period')])) . '#monthly', 301);
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('manage_targets'), 403);

        return redirect()->to(route('road-to-sold.settings.targets.index', array_filter(['period' => $request->input('period')])) . '#monthly')
            ->with('error', 'Listing Targets has moved. Nothing was saved from that page - set the "New listings" number under Targets.');
    }
}
