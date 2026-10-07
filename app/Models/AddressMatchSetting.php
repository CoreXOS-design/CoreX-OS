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
    ];

    protected $casts = [
        'rule_erf_exact'                => 'boolean',
        'rule_scheme_exact'             => 'boolean',
        'rule_street_exact'             => 'boolean',
        'possible_min_agreeing_columns' => 'integer',
        'gps_radius_m'                  => 'integer',
    ];

    public const NEIGHBOUR_CREDITS = ['possible', 'ignore'];
    public const UNIT_MISSING_MODES = ['possible', 'different'];

    /** The shipped defaults — one place, so the page, the scorer and the tests agree. */
    public const DEFAULTS = [
        'rule_erf_exact'                => true,
        'rule_scheme_exact'             => true,
        'rule_street_exact'             => true,
        'possible_min_agreeing_columns' => 2,
        'neighbour_suburb_credit'       => 'possible',
        'gps_radius_m'                  => 25,
        'unit_missing_on_one_side'      => 'possible',
    ];

    /**
     * The effective settings for an agency as a plain array — saved values over the defaults,
     * every value clamped to its valid range so a bad row can never make the scorer loosen
     * past what the page allows.
     *
     * @return array{rule_erf_exact: bool, rule_scheme_exact: bool, rule_street_exact: bool, possible_min_agreeing_columns: int, neighbour_suburb_credit: string, gps_radius_m: int, unit_missing_on_one_side: string}
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
        foreach (['rule_erf_exact', 'rule_scheme_exact', 'rule_street_exact'] as $flag) {
            $out[$flag] = (bool) $out[$flag];
        }

        return $out;
    }
}
