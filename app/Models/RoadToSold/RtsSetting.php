<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-454 — one row per (agency, track) of Road to Sold settings.
 * Spec: .ai/specs/road-to-sold.md §2.1 / §3.1.
 *
 * Soft delete: "put everything back to the defaults" archives the row (a missing/archived row means
 * defaults); the next save brings it back. The audit table next to it is append-only and has no soft delete.
 *
 * Read ONLY through App\Services\RoadToSold\SettingsService; that service owns
 * the "missing row = defaults, never write on read" rule.
 */
class RtsSetting extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const TRACK_SALES   = 'sales';
    public const TRACK_RENTALS = 'rentals';
    public const TRACKS        = [self::TRACK_SALES, self::TRACK_RENTALS];

    /** Anything that is not a known track is the sales track (absorb, never error). */
    public static function normaliseTrack(mixed $track): string
    {
        return in_array($track, self::TRACKS, true) ? $track : self::TRACK_SALES;
    }

    public const MISSED_SHOW   = 'show_missed';
    public const MISSED_ROLL   = 'roll_over';

    protected $table = 'rts_settings';

    protected $fillable = [
        'agency_id', 'track',
        'lock_agents_to_areas', 'half_day_leave_halves_target', 'area_refresh_warn_days', 'public_holiday_country', 'missed_items_mode',
        'selections', 'new_on_market_days', 'long_on_market_days', 'owner_known_quantity',
        'stuck_stock_min_days', 'stuck_stock_max_views', 'stuck_stock_max_leads',
        'hot_pocket_lookback_days', 'price_drop_lookback_days', 'stuck_stock_lookback_days',
        'no_answer_drop_after', 'hold_days',
        'summary_steps', 'summary_green_from_pct', 'summary_red_below_pct',
        'default_weekday_targets', 'default_monthly_targets', 'updated_by_user_id',
        'refresh_portals', 'buyer_match_tiers', 'active_buyer_states', 'on_market_statuses',
        'add_to_day_max', 'min_area_properties',
        'hot_pocket_weight_buyers', 'hot_pocket_weight_leads', 'hot_pocket_weight_sales',
        'summary_ahead_from_pct', 'capture_back_date_working_days', 'capture_needs_review',
        'levels_signoff_required', 'level_periods_required',
        'flag_min_sample', 'flag_below_pct', 'flag_window_days', 'suggest_window_weeks', 'suggest_growth_pct',
        'expired_competitor_lookback_days', 'prospect_stall_days', 'agent_start_required', 'source_for_colleagues',
        'claim_hold_days', 'claim_warn_days', 'claim_extension_days', 'claim_extensions_allowed', 'owner_requires_deed', 'default_callback_days', 'flag_offramp_excess_pct',
        'due_claimed_days', 'due_owner_days', 'due_contacted_days', 'due_held_days', 'fill_from_claims_first',
        'target_listings_per_month', 'target_window_days', 'target_min_sample', 'target_rates', 'target_overrides',
    ];

    protected $casts = [
        'lock_agents_to_areas'         => 'boolean',
        'half_day_leave_halves_target' => 'boolean',
        'area_refresh_warn_days'       => 'integer',
        'new_on_market_days'           => 'integer',
        'long_on_market_days'          => 'integer',
        'owner_known_quantity'         => 'integer',
        'stuck_stock_min_days'         => 'integer',
        'stuck_stock_max_views'        => 'integer',
        'stuck_stock_max_leads'        => 'integer',
        'hot_pocket_lookback_days'     => 'integer',
        'price_drop_lookback_days'     => 'integer',
        'stuck_stock_lookback_days'    => 'integer',
        'no_answer_drop_after'         => 'integer',
        'hold_days'                    => 'integer',
        'summary_green_from_pct'       => 'integer',
        'summary_red_below_pct'        => 'integer',
        'selections'                   => 'array',
        'summary_steps'                => 'array',
        'default_weekday_targets'      => 'array',
        'default_monthly_targets'      => 'array',
        'refresh_portals'              => 'array',
        'buyer_match_tiers'            => 'array',
        'active_buyer_states'          => 'array',
        'on_market_statuses'           => 'array',
        'add_to_day_max'               => 'integer',
        'min_area_properties'          => 'integer',
        'hot_pocket_weight_buyers'     => 'integer',
        'hot_pocket_weight_leads'      => 'integer',
        'hot_pocket_weight_sales'      => 'integer',
        'summary_ahead_from_pct'       => 'integer',
        'capture_back_date_working_days' => 'integer',
        'capture_needs_review'         => 'boolean',
        'levels_signoff_required'      => 'boolean',
        'level_periods_required'       => 'integer',
        'expired_competitor_lookback_days' => 'integer',
        'prospect_stall_days'          => 'integer',
        'agent_start_required'         => 'boolean',
        'claim_hold_days'              => 'integer',
        'claim_warn_days'              => 'integer',
        'claim_extension_days'         => 'integer',
        'claim_extensions_allowed'     => 'integer',
        'owner_requires_deed'          => 'boolean',
        'default_callback_days'        => 'integer',
        'flag_offramp_excess_pct'      => 'integer',
        'due_claimed_days'             => 'integer',
        'due_owner_days'               => 'integer',
        'due_contacted_days'           => 'integer',
        'due_held_days'                => 'integer',
        'fill_from_claims_first'       => 'boolean',
        'target_listings_per_month'    => 'integer',
        'target_window_days'           => 'integer',
        'target_min_sample'            => 'integer',
        'target_rates'                 => 'array',
        'target_overrides'             => 'array',
        'flag_min_sample'              => 'integer',
        'flag_below_pct'               => 'integer',
        'flag_window_days'             => 'integer',
        'suggest_window_weeks'         => 'integer',
        'suggest_growth_pct'           => 'integer',
    ];

    /** How many Market Intelligence properties the call list reads in one window. Not an agency setting: config/road-to-sold.php can override it. */
    public const SCAN_PAGE_SIZE = 200;

    /** Scalar defaults — the single source of truth (migration defaults mirror these). */
    /** The v4 start / pause switch is a column added by migration; until it exists the code runs as before (nothing is gated). */
    public static function hasAgentStartColumn(): bool
    {
        static $has = null;

        return $has ??= \Illuminate\Support\Facades\Schema::hasColumn((new static())->getTable(), 'agent_start_required');
    }

    /** The v4 allocation setting is a column added by migration; until it exists the default ("agency") is used. */
    public static function hasSourceForColleaguesColumn(): bool
    {
        static $has = null;

        return $has ??= \Illuminate\Support\Facades\Schema::hasColumn((new static())->getTable(), 'source_for_colleagues');
    }

    /** 23.15 rts.source_for_colleagues: whose buyers feed my stock after my own. */
    public const COLLEAGUE_SOURCES = ['off' => 'Only my own buyers', 'branch' => 'My own buyers, then my branch\'s', 'agency' => 'My own buyers, then my branch\'s, then the whole agency\'s'];

    /**
     * SCALAR_DEFAULTS limited to columns that exist. Two groups arrive by migration (the v4 start switch and the claim-hold settings); until
     * a migration has run, writing "all the defaults" must not name a column the table does not have yet.
     *
     * @return array<string,mixed>
     */
    public static function scalarDefaultsAvailable(): array
    {
        $out = self::SCALAR_DEFAULTS;
        if (! self::hasAgentStartColumn()) {
            unset($out['agent_start_required']);
        }
        if (! self::hasOwnerRequiresDeedColumn()) {
            unset($out['owner_requires_deed']);
        }
        if (! self::hasDefaultCallbackColumn()) {
            unset($out['default_callback_days']);
        }
        if (! self::hasOffRampFlagColumn()) {
            unset($out['flag_offramp_excess_pct']);
        }
        if (! self::hasClaimHoldColumns()) {
            foreach (['claim_hold_days', 'claim_warn_days', 'claim_extension_days', 'claim_extensions_allowed'] as $c) {
                unset($out[$c]);
            }
        }
        if (! self::hasSourceForColleaguesColumn()) {
            unset($out['source_for_colleagues']);
        }
        if (! self::hasConveyorColumns()) {
            foreach (['due_claimed_days', 'due_owner_days', 'due_contacted_days', 'due_held_days', 'fill_from_claims_first'] as $c) {
                unset($out[$c]);
            }
        }
        if (! self::hasTargetColumns()) {
            foreach (['target_listings_per_month', 'target_window_days', 'target_min_sample'] as $c) {
                unset($out[$c]);
            }
        }

        return $out;
    }

    /** v4 A6: the off-ramp training flag threshold is a column added by migration; until it exists the default of 25 applies. */
    public static function hasOffRampFlagColumn(): bool
    {
        static $has = null;

        return $has ??= \Illuminate\Support\Facades\Schema::hasColumn((new static())->getTable(), 'flag_offramp_excess_pct');
    }

    /** v4 A3: the default call-back days is a column added by migration; until it exists the default of 3 applies. */
    public static function hasDefaultCallbackColumn(): bool
    {
        static $has = null;

        return $has ??= \Illuminate\Support\Facades\Schema::hasColumn((new static())->getTable(), 'default_callback_days');
    }

    /** v4 A2: the owner-needs-a-deed switch is a column added by migration; until it exists the rule is on. */
    public static function hasOwnerRequiresDeedColumn(): bool
    {
        static $has = null;

        return $has ??= \Illuminate\Support\Facades\Schema::hasColumn((new static())->getTable(), 'owner_requires_deed');
    }

    /** The belt-target settings (23.29) are columns added by migration; until they exist the standard numbers apply. */
    public static function hasTargetColumns(): bool
    {
        static $has = null;

        return $has ??= \Illuminate\Support\Facades\Schema::hasColumn((new static())->getTable(), 'target_min_sample');
    }

    /** The conveyor settings (23.24: the next-step windows and the claims-first switch) are columns added by migration; until they exist the standard numbers apply. */
    public static function hasConveyorColumns(): bool
    {
        static $has = null;

        return $has ??= \Illuminate\Support\Facades\Schema::hasColumn((new static())->getTable(), 'due_claimed_days');
    }

    /** The v4 claim-hold settings (23.15) are columns added by migration; until they exist the claim rules run as before. */
    public static function hasClaimHoldColumns(): bool
    {
        static $has = null;

        return $has ??= \Illuminate\Support\Facades\Schema::hasColumn((new static())->getTable(), 'claim_hold_days');
    }

    public const SCALAR_DEFAULTS = [
        'lock_agents_to_areas'         => false,
        'half_day_leave_halves_target' => false,
        'area_refresh_warn_days'       => 7,
        'public_holiday_country'       => 'ZA',
        'add_to_day_max'               => 100,
        'min_area_properties'          => 1,
        'hot_pocket_weight_buyers'     => 1,
        'hot_pocket_weight_leads'      => 1,
        'hot_pocket_weight_sales'      => 1,
        'missed_items_mode'            => self::MISSED_SHOW,
        'new_on_market_days'           => 14,
        'long_on_market_days'          => 90,
        'owner_known_quantity'         => 3,
        'stuck_stock_min_days'         => 60,
        'stuck_stock_max_views'        => 30,
        'stuck_stock_max_leads'        => 2,
        'hot_pocket_lookback_days'     => 180,
        'price_drop_lookback_days'     => 30,
        'stuck_stock_lookback_days'    => 30,
        'no_answer_drop_after'         => 3,
        'hold_days'                    => 30,
        'summary_green_from_pct'       => 90,
        'summary_red_below_pct'        => 70,
        'summary_ahead_from_pct'       => 105,
        'capture_back_date_working_days' => 5,
        'capture_needs_review'         => false,
        'levels_signoff_required'      => true,
        'level_periods_required'       => 4,
        'expired_competitor_lookback_days' => 30,
        'prospect_stall_days'          => 14,
        'source_for_colleagues'        => 'agency',   // 23.15: off | branch | agency
        'agent_start_required'         => true,    // 23.13: an agent presses "Start my Road to Sold" before lists load
        'claim_hold_days'              => 30,      // 23.5: working days a claim is held while it is not worked (replaces the 48-hour rule)
        'claim_warn_days'              => 5,       // working days before the end the agent is told
        'claim_extension_days'         => 30,      // length of the one extension, in working days
        'claim_extensions_allowed'     => 1,       // extensions an agent may take (a manager can grant more)
        'owner_requires_deed'          => true,    // 23.6: the Owner station needs a deed record as well as the owner contact
        'flag_offramp_excess_pct'      => 25,      // 23.12: the training flag fires when an agent's off-ramp rate at a station is this percent above the branch / agency rate
        'default_callback_days'        => 3,       // 23.7: working days ahead the call-back date of a Market Intelligence "contacted" feedback is set
        'due_claimed_days'             => 5,       // 23.24: working days after the claim to find the owner (a property with no next-step date set is late after this)
        'due_owner_days'               => 3,       // 23.24: working days after the owner is found to make the first contact
        'due_contacted_days'           => 7,       // 23.24: days after the contact to book the evaluation
        'due_held_days'                => 2,       // 23.24: working days after the evaluation to give the result
        'fill_from_claims_first'       => true,    // 23.24: the day's list is filled from the agent's own claims before any other source
        'target_listings_per_month'    => 2,       // 23.29: Listings (Active) per agent per month when no monthly target is set anywhere
        'target_window_days'           => 90,      // 23.29: the rolling window the step-to-step conversion rates are read over
        'target_min_sample'            => 30,      // 23.29: records through a step before its real rate is trusted (below it: the default rate, labelled estimate)
        'flag_min_sample'              => 20,
        'flag_below_pct'               => 60,
        'flag_window_days'             => 30,
        'suggest_window_weeks'         => 8,
        'suggest_growth_pct'           => 10,
    ];

    /**
     * The agency-editable lists lane 2 reads (spec 9.11). Each: stored key => the code default list.
     * NULL in the column = this default. Choices below are the vocabularies those columns really hold.
     */
    public const LIST_DEFAULTS = [
        'refresh_portals'     => ['p24', 'pp'],
        'buyer_match_tiers'   => ['perfect', 'strong'],
        'active_buyer_states' => ['new', 'warm'],
        'on_market_statuses'  => ['active', 'for_sale', 'on_show'],
    ];

    /** @return array<string,array<string,string>> list column => value => plain label (the only values a save accepts) */
    public static function listChoices(): array
    {
        $statuses = [
            'active' => 'Active', 'for_sale' => 'For sale', 'to_let' => 'To let', 'under_offer' => 'Under offer',
            'on_show' => 'On show', 'prospecting' => 'Prospecting', 'expired' => 'Expired',
        ];

        return [
            'refresh_portals'     => ['p24' => 'Property24', 'pp' => 'Private Property'],
            'buyer_match_tiers'   => ['perfect' => 'Perfect', 'strong' => 'Strong', 'approximate' => 'Approximate'],
            'active_buyer_states' => ['new' => 'New', 'warm' => 'Warm', 'cold' => 'Cold'],
            'on_market_statuses'  => $statuses,
        ];
    }

    /** Default agency weekday targets (ISO 1=Mon … 7=Sun). Mon–Fri 5, weekend 0. */
    public const DEFAULT_WEEKDAY_TARGETS = [1 => 5, 2 => 5, 3 => 5, 4 => 5, 5 => 5, 6 => 0, 7 => 0];

    /**
     * The agency default for the monthly targets (agency-tracker-rehome.md 5.7): what an agent with no number of
     * their own is given when a manager presses "Give agents without a target the agency default". 0 = nothing
     * set. Neutral by design - no agency's figures are baked in.
     */
    public const DEFAULT_MONTHLY_TARGETS = ['points' => 0, 'deals' => 0, 'listings' => 0, 'value' => 0];
    public const MAX_MONTHLY_COUNT = 1000000;
    public const MAX_MONTHLY_VALUE = 999999999999;

    /**
     * Selection catalogue, in default order: key => label.
     * Keys are STORED — never rename (spec §2.1). Sales track now; the rentals
     * track gets its own catalogue when Road to Rented is built.
     *
     * @return array<string,string>
     */
    public static function selectionCatalogue(string $track = self::TRACK_SALES): array
    {
        return [
            'my_buyers_sourcing'     => 'My buyers need stock',
            'agency_buyers_sourcing' => 'Colleagues\' buyers need stock',
            'hot_pockets'            => 'Hottest pockets (long on market and reduced)',
            'price_drops'            => 'Price drops',
            'long_on_market'         => 'Long on market',
            'my_expired_stock'       => 'My expired stock',
            'expired_competitor'     => 'Expired competitor listings',
            'new_on_market'          => 'New on market',
            'owner_known'            => 'Owner already known',
            'my_stock_not_selling'   => 'My stock that is not selling',
        ];
    }

    /**
     * Summary-bar steps, in default order: key => label. Social shares are
     * deliberately NOT here (Johan: off, do not build).
     *
     * @return array<string,string>
     */
    public static function summaryStepCatalogue(string $track = self::TRACK_SALES): array
    {
        return [
            'calls_made'        => 'Calls made',
            'conversations'     => 'Conversations',
            'evaluations_booked' => 'Evaluations booked',
            'evaluations_done'   => 'Evaluations done',
            'mandates_signed'   => 'Mandates signed',
            'listings_live'     => 'Listings live',
        ];
    }

    /**
     * Turn whatever was posted/stored into a complete ordered list
     * [['key'=>…,'enabled'=>bool], …] over the catalogue.
     * ABSORB rules (spec §6): unknown keys dropped, duplicates collapsed,
     * missing keys appended DISABLED at the end, non-array input = defaults.
     *
     * @param  mixed                $raw       list of ['key'=>..,'enabled'=>..] (or key => bool map) or null
     * @param  array<string,string> $catalogue key => label
     * @return array<int,array{key:string,enabled:bool}>
     */
    public static function normaliseOrderedToggles(mixed $raw, array $catalogue): array
    {
        if (! is_array($raw) || $raw === []) {
            return array_map(
                fn (string $k) => ['key' => $k, 'enabled' => true],
                array_keys($catalogue)
            );
        }

        $out  = [];
        $seen = [];
        foreach ($raw as $i => $item) {
            if (is_array($item)) {
                $key     = isset($item['key']) ? trim((string) $item['key']) : '';
                $enabled = filter_var($item['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            } else {
                // key => bool map form
                $key     = is_string($i) ? trim($i) : '';
                $enabled = filter_var($item, FILTER_VALIDATE_BOOLEAN);
            }
            if ($key === '' || ! isset($catalogue[$key]) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[]      = ['key' => $key, 'enabled' => $enabled];
        }

        foreach (array_keys($catalogue) as $key) {
            if (! isset($seen[$key])) {
                $out[] = ['key' => $key, 'enabled' => false];
            }
        }

        return $out;
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
