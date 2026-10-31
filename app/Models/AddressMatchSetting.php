<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-agency strictness thresholds for the address scorer
 * (.ai/specs/structured-address-matching.md §9). No row = the DEFAULTS below.
 * Admin-only (settings page); deliberately NOT in the Setup Wizard (Johan's decision).
 */
class AddressMatchSetting extends Model
{
    protected $table = 'address_match_settings';

    protected $fillable = [
        'agency_id', 'rule_erf_exact', 'rule_scheme_exact', 'rule_street_exact',
        'possible_min_agreeing_columns', 'neighbour_suburb_credit', 'gps_radius_m', 'unit_missing_on_one_side',
        'fuzzy_threshold', 'auto_merge_min_confidence', 'auto_claim_min_confidence', 'same_address_sweep',
        'portal_group_link_enabled', 'portal_group_price_spread', 'portal_group_auto_link_min_confidence', 'twin_match_enabled', 'twin_price_tolerance_pct', 'twin_min_corroboration', 'twin_auto_link', 'complex_group_enabled', 'complex_min_land_m2', 'map_point_check_enabled', 'map_point_distance_m',
    ];

    protected $casts = [
        'rule_erf_exact'                => 'boolean',
        'rule_scheme_exact'             => 'boolean',
        'rule_street_exact'             => 'boolean',
        'possible_min_agreeing_columns' => 'integer',
        'gps_radius_m'                  => 'integer',
        'fuzzy_threshold'               => 'integer',
        'same_address_sweep'            => 'boolean',
        'portal_group_link_enabled'     => 'boolean',
        'twin_match_enabled'            => 'boolean',
        'twin_auto_link'                => 'boolean',
        'complex_group_enabled'         => 'boolean',
        'map_point_check_enabled'       => 'boolean',
    ];

    public const NEIGHBOUR_CREDITS = ['possible', 'ignore'];
    public const UNIT_MISSING_MODES = ['possible', 'different'];
    /** property-identity.md §6: the lowest confidence a match may have and still link automatically. */
    public const AUTO_MERGE_LEVELS = ['exact', 'high'];
    /** property-identity.md section 15 (C2): the lowest confidence at which a capture claims its Market Intelligence record on its own. */
    public const AUTO_CLAIM_LEVELS = ['exact', 'high'];

    /** The shipped defaults — one place, so the page, the scorer and the tests agree. */
    public const DEFAULTS = [
        'rule_erf_exact'                => true,
        'rule_scheme_exact'             => true,
        'rule_street_exact'             => true,
        'possible_min_agreeing_columns' => 2,
        'neighbour_suburb_credit'       => 'possible',
        'gps_radius_m'                  => 25,
        'unit_missing_on_one_side'      => 'possible',
        'fuzzy_threshold'               => 85,
        'auto_merge_min_confidence'     => 'high',
        'auto_claim_min_confidence'     => 'high',
        'same_address_sweep'            => true,
        // AT-520 4.4 (mic-work-tab.md sections 4 and 9)
        'portal_group_link_enabled'     => true,
        'portal_group_price_spread'     => 1.5,
        'portal_group_auto_link_min_confidence' => 'high',
        'twin_match_enabled'            => true,
        'twin_price_tolerance_pct'      => 1,
        'twin_min_corroboration'        => 2,
        'twin_auto_link'                => false,
        'complex_group_enabled'         => true,
        'complex_min_land_m2'           => 1000,
        'map_point_check_enabled'       => true,
        'map_point_distance_m'          => 150,
    ];

    /**
     * The effective settings for an agency as a plain array — saved values over the defaults,
     * every value clamped to its valid range so a bad row can never make the scorer loosen
     * past what the page allows.
     *
     * @return array{rule_erf_exact: bool, rule_scheme_exact: bool, rule_street_exact: bool, possible_min_agreeing_columns: int, neighbour_suburb_credit: string, gps_radius_m: int, unit_missing_on_one_side: string, fuzzy_threshold: int, auto_merge_min_confidence: string}
     */
    public static function forAgency(?int $agencyId): array
    {
        $out = self::DEFAULTS;
        if ($agencyId) {
            try {
                $row = static::query()->where('agency_id', $agencyId)->first();
            } catch (\Throwable $e) {
                $row = null; // table missing (older schema) — defaults
            }
            if ($row !== null) {
                foreach (array_keys(self::DEFAULTS) as $key) {
                    if ($row->{$key} !== null) {
                        $out[$key] = $row->{$key};
                    }
                }
            }
        }
        $out['possible_min_agreeing_columns'] = max(2, min(4, (int) $out['possible_min_agreeing_columns']));
        $out['gps_radius_m'] = max(5, min(100, (int) $out['gps_radius_m']));
        if (! in_array($out['neighbour_suburb_credit'], self::NEIGHBOUR_CREDITS, true)) {
            $out['neighbour_suburb_credit'] = self::DEFAULTS['neighbour_suburb_credit'];
        }
        if (! in_array($out['unit_missing_on_one_side'], self::UNIT_MISSING_MODES, true)) {
            $out['unit_missing_on_one_side'] = self::DEFAULTS['unit_missing_on_one_side'];
        }
        $out['fuzzy_threshold'] = max(60, min(100, (int) $out['fuzzy_threshold']));
        if (! in_array($out['auto_merge_min_confidence'], self::AUTO_MERGE_LEVELS, true)) {
            $out['auto_merge_min_confidence'] = self::DEFAULTS['auto_merge_min_confidence'];
        }
        if (! in_array($out['auto_claim_min_confidence'], self::AUTO_CLAIM_LEVELS, true)) {
            $out['auto_claim_min_confidence'] = self::DEFAULTS['auto_claim_min_confidence'];
        }
        $out['portal_group_price_spread'] = max(1.1, min(3.0, (float) $out['portal_group_price_spread']));
        if (! in_array($out['portal_group_auto_link_min_confidence'], self::AUTO_MERGE_LEVELS, true)) {
            $out['portal_group_auto_link_min_confidence'] = self::DEFAULTS['portal_group_auto_link_min_confidence'];
        }
        $out['twin_price_tolerance_pct'] = max(0, min(5, (int) $out['twin_price_tolerance_pct']));
        $out['twin_min_corroboration'] = max(1, min(4, (int) $out['twin_min_corroboration']));
        $out['complex_min_land_m2'] = max(100, min(100000, (int) $out['complex_min_land_m2']));
        $out['map_point_distance_m'] = max(25, min(1000, (int) $out['map_point_distance_m']));
        foreach (['rule_erf_exact', 'rule_scheme_exact', 'rule_street_exact', 'same_address_sweep', 'portal_group_link_enabled', 'twin_match_enabled', 'twin_auto_link', 'complex_group_enabled', 'map_point_check_enabled'] as $flag) {
            $out[$flag] = (bool) $out[$flag];
        }

        return $out;
    }
}
