<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings\Prospecting;

use App\Http\Controllers\Controller;
use App\Models\AddressMatchSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Structured address matching — match-strictness settings
 * (.ai/specs/structured-address-matching.md §9). Admin-only (the Prospecting Setup route group's
 * `prospecting_setup.manage` gate), per agency, safe defaults, and deliberately NOT in the Setup
 * Wizard (Johan's decision, recorded in agency-onboarding-setup.md "Deliberately NOT in the wizard").
 *
 * The page always posts EVERY field (it is not a wizard step posting a subset), so an unticked
 * checkbox genuinely means "off" here.
 */
class AddressMatchingRulesController extends Controller
{
    public function edit(Request $request)
    {
        $agencyId = (int) ($request->user()->effectiveAgencyId() ?: 0);
        abort_if($agencyId === 0, 403);

        return view('settings.prospecting.address-matching', [
            'settings' => AddressMatchSetting::forAgency($agencyId),
            'defaults' => AddressMatchSetting::DEFAULTS,
        ]);
    }

    public function update(Request $request)
    {
        $agencyId = (int) ($request->user()->effectiveAgencyId() ?: 0);
        abort_if($agencyId === 0, 403);

        // "Back to the safe defaults" — writes the shipped values (never deletes the row).
        if ($request->boolean('reset')) {
            AddressMatchSetting::query()->updateOrCreate(['agency_id' => $agencyId], AddressMatchSetting::DEFAULTS);

            return back()->with('status', 'Address matching is back on the standard settings.');
        }

        $v = $request->validate([
            'possible_min_agreeing_columns' => ['required', 'integer', 'min:2', 'max:4'],
            'neighbour_suburb_credit'       => ['required', Rule::in(AddressMatchSetting::NEIGHBOUR_CREDITS)],
            'gps_radius_m'                  => ['required', 'integer', 'min:5', 'max:100'],
            'unit_missing_on_one_side'      => ['required', Rule::in(AddressMatchSetting::UNIT_MISSING_MODES)],
        ]);

        AddressMatchSetting::query()->updateOrCreate(['agency_id' => $agencyId], [
            'rule_erf_exact'                => $request->boolean('rule_erf_exact'),
            'rule_scheme_exact'             => $request->boolean('rule_scheme_exact'),
            'rule_street_exact'             => $request->boolean('rule_street_exact'),
            'possible_min_agreeing_columns' => (int) $v['possible_min_agreeing_columns'],
            'neighbour_suburb_credit'       => $v['neighbour_suburb_credit'],
            'gps_radius_m'                  => (int) $v['gps_radius_m'],
            'unit_missing_on_one_side'      => $v['unit_missing_on_one_side'],
        ]);

        return back()->with('status', 'Address matching settings saved.');
    }
}
