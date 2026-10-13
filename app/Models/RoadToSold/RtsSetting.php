<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-454 — one row per (agency, track) of Road to Sold settings.
 * Spec: .ai/specs/road-to-sold.md §2.1 / §3.1.
 *
 * Read ONLY through App\Services\RoadToSold\SettingsService; that service owns
 * the "missing row = defaults, never write on read" rule.
 */
class RtsSetting extends Model
{
    use BelongsToAgency;

    public const TRACK_SALES   = 'sales';
    public const TRACK_RENTALS = 'rentals';

    public const MISSED_SHOW   = 'show_missed';
    public const MISSED_ROLL   = 'roll_over';

    protected $table = 'rts_settings';

    protected $fillable = [
        'agency_id', 'track',
        'lock_agents_to_areas', 'half_day_leave_halves_target', 'area_refresh_warn_days', 'missed_items_mode',
        'selections', 'new_on_market_days', 'long_on_market_days', 'owner_known_quantity',
        'stuck_stock_min_days', 'stuck_stock_max_views', 'stuck_stock_max_leads',
        'hot_pocket_lookback_days', 'price_drop_lookback_days', 'stuck_stock_lookback_days',
        'add_to_day_max', 'hot_pocket_weight_buyers', 'hot_pocket_weight_leads', 'hot_pocket_weight_sales',
        'buyer_match_tiers', 'buyer_active_states', 'on_market_statuses',
        'no_answer_drop_after', 'hold_days',
        'summary_steps', 'summary_green_from_pct', 'summary_red_below_pct',
        'default_weekday_targets', 'updated_by_user_id',
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
        'add_to_day_max'               => 'integer',
        'hot_pocket_weight_buyers'     => 'integer',
        'hot_pocket_weight_leads'      => 'integer',
        'hot_pocket_weight_sales'      => 'integer',
        'buyer_match_tiers'            => 'array',
        'buyer_active_states'          => 'array',
        'on_market_statuses'           => 'array',
        'no_answer_drop_after'         => 'integer',
        'hold_days'                    => 'integer',
        'summary_green_from_pct'       => 'integer',
        'summary_red_below_pct'        => 'integer',
        'selections'                   => 'array',
        'summary_steps'                => 'array',
        'default_weekday_targets'      => 'array',
    ];

    /** Scalar defaults — the single source of truth (migration defaults mirror these). */
    public const SCALAR_DEFAULTS = [
        'lock_agents_to_areas'         => false,
        'half_day_leave_halves_target' => false,
        'area_refresh_warn_days'       => 7,
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
        'add_to_day_max'               => 100,
        'hot_pocket_weight_buyers'     => 1,
        'hot_pocket_weight_leads'      => 1,
        'hot_pocket_weight_sales'      => 1,
        'no_answer_drop_after'         => 3,
        'hold_days'                    => 30,
        'summary_green_from_pct'       => 90,
        'summary_red_below_pct'        => 70,
    ];

    /**
     * Pick-lists the builder reads (JSON columns; NULL = the standard list). key => label, in the order shown.
     * The DEFAULT lists are the values the builder used before they were settings.
     */
    public const LIST_CATALOGUES = [
        'buyer_match_tiers' => [
            'perfect'     => 'Perfect match (90 and above)',
            'strong'      => 'Strong match (80 and above)',
            'approximate' => 'Approximate match (50 and above)',
        ],
        'buyer_active_states' => [
            'new'  => 'New',
            'warm' => 'Warm',
            'cold' => 'Cold',
        ],
        'on_market_statuses' => [
            'active'      => 'Active',
            'for_sale'    => 'For sale',
            'on_show'     => 'On show',
            'under_offer' => 'Under offer',
            'on_auction'  => 'On auction',
            'to_let'      => 'To let',
        ],
    ];

    public const LIST_DEFAULTS = [
        'buyer_match_tiers'   => ['perfect', 'strong'],
        'buyer_active_states' => ['new', 'warm'],
        'on_market_statuses'  => ['active', 'for_sale', 'on_show'],
    ];

    /** Default agency weekday targets (ISO 1=Mon … 7=Sun). Mon–Fri 5, weekend 0. */
    public const DEFAULT_WEEKDAY_TARGETS = [1 => 5, 2 => 5, 3 => 5, 4 => 5, 5 => 5, 6 => 0, 7 => 0];

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
            'my_buyers_sourcing'     => 'My buyers\' stock sourcing',
            'agency_buyers_sourcing' => 'Agency buyers\' stock sourcing',
            'hot_pockets'            => 'Hottest sales pockets',
            'price_drops'            => 'Price drops',
            'new_on_market'          => 'New on market',
            'long_on_market'         => 'Long on market',
            'owner_known'            => 'Owner already known',
            'my_expired_stock'       => 'My expired stock',
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
            'valuations_booked' => 'Valuations booked',
            'valuations_done'   => 'Valuations done',
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
