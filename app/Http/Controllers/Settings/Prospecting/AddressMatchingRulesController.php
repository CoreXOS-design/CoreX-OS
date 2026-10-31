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
            'fuzzy_threshold'               => ['sometimes', 'integer', 'min:60', 'max:100'],
            'auto_merge_min_confidence'     => ['sometimes', Rule::in(AddressMatchSetting::AUTO_MERGE_LEVELS)],
            'auto_claim_min_confidence'     => ['sometimes', Rule::in(AddressMatchSetting::AUTO_CLAIM_LEVELS)],
            'portal_group_price_spread'     => ['sometimes', 'numeric', 'min:1.1', 'max:3'],
            'portal_group_auto_link_min_confidence' => ['sometimes', Rule::in(AddressMatchSetting::AUTO_MERGE_LEVELS)],
            'twin_price_tolerance_pct'      => ['sometimes', 'integer', 'min:0', 'max:5'],
            'twin_min_corroboration'        => ['sometimes', 'integer', 'min:1', 'max:4'],
            'complex_min_land_m2'           => ['sometimes', 'integer', 'min:100', 'max:100000'],
            'map_point_distance_m'          => ['sometimes', 'integer', 'min:25', 'max:1000'],
            'unit_split_min_adverts'        => ['sometimes', 'integer', 'min:3', 'max:30'],
            'unit_split_price_spread'       => ['sometimes', 'numeric', 'min:1.2', 'max:10'],
            'unit_split_land_size_gap_pct'  => ['sometimes', 'integer', 'min:2', 'max:50'],
        ]);

        $current = AddressMatchSetting::forAgency($agencyId);

        AddressMatchSetting::query()->updateOrCreate(['agency_id' => $agencyId], [
            'rule_erf_exact'                => $request->boolean('rule_erf_exact'),
            'rule_scheme_exact'             => $request->boolean('rule_scheme_exact'),
            'rule_street_exact'             => $request->boolean('rule_street_exact'),
            'possible_min_agreeing_columns' => (int) $v['possible_min_agreeing_columns'],
            'neighbour_suburb_credit'       => $v['neighbour_suburb_credit'],
            'gps_radius_m'                  => (int) $v['gps_radius_m'],
            'unit_missing_on_one_side'      => $v['unit_missing_on_one_side'],
            'fuzzy_threshold'               => (int) ($v['fuzzy_threshold'] ?? $current['fuzzy_threshold']),
            'auto_merge_min_confidence'     => $v['auto_merge_min_confidence'] ?? $current['auto_merge_min_confidence'],
            'auto_claim_min_confidence'     => $v['auto_claim_min_confidence'] ?? $current['auto_claim_min_confidence'],
            // a form that does not carry the switch (a partial post) never turns it off
            'same_address_sweep'            => $request->has('same_address_sweep_present') ? $request->boolean('same_address_sweep') : $current['same_address_sweep'],
        ] + $this->matchingRules($request, $v, $current));

        return back()->with('status', 'Address matching settings saved.');
    }

    /**
     * AT-520 4.4: the portal-group, twin, complex and map-point settings. A post that does not carry this block (`matching_rules_present`) never
     * changes them; each number falls back to the stored value when absent.
     *
     * @param  array<string,mixed> $v
     * @param  array<string,mixed> $current
     * @return array<string,mixed>
     */
    private function matchingRules(Request $request, array $v, array $current): array
    {
        $present = $request->has('matching_rules_present');
        $flag = fn (string $k) => $present ? $request->boolean($k) : $current[$k];

        return [
            'portal_group_link_enabled'  => $flag('portal_group_link_enabled'),
            'twin_match_enabled'         => $flag('twin_match_enabled'),
            'twin_auto_link'             => $flag('twin_auto_link'),
            'complex_group_enabled'      => $flag('complex_group_enabled'),
            'map_point_check_enabled'    => $flag('map_point_check_enabled'),
            'portal_group_price_spread'  => (float) ($v['portal_group_price_spread'] ?? $current['portal_group_price_spread']),
            'portal_group_auto_link_min_confidence' => $v['portal_group_auto_link_min_confidence'] ?? $current['portal_group_auto_link_min_confidence'],
            'twin_price_tolerance_pct'   => (int) ($v['twin_price_tolerance_pct'] ?? $current['twin_price_tolerance_pct']),
            'twin_min_corroboration'     => (int) ($v['twin_min_corroboration'] ?? $current['twin_min_corroboration']),
            'complex_min_land_m2'        => (int) ($v['complex_min_land_m2'] ?? $current['complex_min_land_m2']),
            'map_point_distance_m'       => (int) ($v['map_point_distance_m'] ?? $current['map_point_distance_m']),
            'unit_split_min_adverts'     => (int) ($v['unit_split_min_adverts'] ?? $current['unit_split_min_adverts']),
            'unit_split_price_spread'    => (float) ($v['unit_split_price_spread'] ?? $current['unit_split_price_spread']),
            'unit_split_land_size_gap_pct' => (int) ($v['unit_split_land_size_gap_pct'] ?? $current['unit_split_land_size_gap_pct']),
        ];
    }
}
