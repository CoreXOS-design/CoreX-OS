<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class PropertySettingItem extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id','group', 'name', 'sort_order', 'is_default', 'active', 'title_type',
        // Build 3 — only relevant on group='condition_level' rows.
        'adjustment_pct',
    ];

    protected $casts = [
        'sort_order'     => 'integer',
        'is_default'     => 'boolean',
        'active'         => 'boolean',
        'adjustment_pct' => 'decimal:2',
    ];

    // Allowed groups
    const GROUP_CATEGORY        = 'category';
    const GROUP_TYPE            = 'property_type';
    const GROUP_STATUS          = 'property_status';
    const GROUP_MANDATE_TYPE    = 'mandate_type';
    // Build 3 — condition levels with adjustment_pct.
    const GROUP_CONDITION_LEVEL = 'condition_level';

    /** 'Average' is the baseline (0%) and cannot be deleted. The controller
     *  enforces this; the UI surfaces it so the agent knows. */
    public const CONDITION_BASELINE_NAME = 'Average';

    // title_type values (only meaningful on group='category' rows).
    // See .ai/specs/presentation-data-lineage.md §3-A — enforced by
    // MicSnapshotHydrator at comp selection so a vacant land subject
    // never gets compared against sectional title sales.
    public const TITLE_FULL       = 'full_title';
    public const TITLE_SECTIONAL  = 'sectional_title';
    public const TITLE_VACANT     = 'vacant_land';
    public const TITLE_OTHER      = 'other';

    public const TITLE_TYPES = [
        self::TITLE_FULL      => 'Full Title',
        self::TITLE_SECTIONAL => 'Sectional Title',
        self::TITLE_VACANT    => 'Vacant Land',
        self::TITLE_OTHER     => 'Other / Mixed',
    ];

    public function scopeGroup($query, string $group)
    {
        return $query->where('group', $group)->orderBy('sort_order')->orderBy('name');
    }

    /**
     * AT-352 — THE canonical default settings every agency starts with.
     *
     * Until this existed, nothing in CoreX provisioned property settings for an
     * agency created after a migration: the original sets were one-off backfills
     * (2026_03_05_300002 property_type, 2026_03_05_300003 category/status/mandate,
     * 2026_06_17_120000 condition_level), and no agency-creation hook touched the
     * table. A tenant onboarded after those ran opened Properties to an EMPTY
     * Status, Type, Category and Mandate dropdown and could not capture a listing
     * without configuring the system first — an empty required dropdown with no
     * explanation, which is exactly the silent dead-end STANDARDS forbids.
     *
     * Captured verbatim from those migrations so a fresh agency and a 2026-03
     * agency get identical vocabulary. Order in each array IS the sort order.
     *
     * Kept as data on the model (not a config file) to mirror
     * AgencyLeaveVisibilityMatrix::defaultRows(), the existing precedent that
     * AgencyObserver already consumes for exactly this purpose.
     *
     * @var array<string, list<array{name:string, title_type?:string, adjustment_pct?:float}>>
     */
    public const DEFAULT_ROWS = [
        self::GROUP_CATEGORY => [
            ['name' => 'Residential', 'title_type' => self::TITLE_FULL],
            ['name' => 'Commercial',  'title_type' => self::TITLE_OTHER],
            ['name' => 'Industrial',  'title_type' => self::TITLE_OTHER],
            ['name' => 'Retirement',  'title_type' => self::TITLE_FULL],
            ['name' => 'Holiday',     'title_type' => self::TITLE_FULL],
            ['name' => 'Project',     'title_type' => self::TITLE_OTHER],
        ],

        // The canonical set from 2026_05_14_130001_normalize_property_types —
        // NOT the pre-normalisation names, or a new agency would start with the
        // legacy vocabulary that migration exists to retire.
        self::GROUP_TYPE => [
            ['name' => 'House'],
            ['name' => 'Apartment / Flat'],
            ['name' => 'Townhouse'],
            ['name' => 'Vacant Land / Plot'],
            ['name' => 'Farm'],
            ['name' => 'Commercial Property'],
            ['name' => 'Industrial Property'],
        ],

        self::GROUP_STATUS => [
            ['name' => 'Sales Listing'],
            ['name' => 'For Sale'],
            ['name' => 'Reduced Price'],
            ['name' => 'Pending'],
            ['name' => 'Back on Market'],
            ['name' => 'Raised Price'],
            ['name' => 'Sold'],
            // AT-350. Sits immediately after 'Sold' because that is where an
            // agent looks for it. Its slug is Property::STATUS_SOLD_BY_3RD_PARTY.
            ['name' => 'Sold by 3rd Party'],
            ['name' => 'Under Offer'],
            ['name' => 'On Show'],
            ['name' => 'On Auction'],
            ['name' => 'Draft'],
            ['name' => 'Withdrawn'],
            ['name' => 'Unavailable'],
            ['name' => 'Archived'],
        ],

        self::GROUP_MANDATE_TYPE => [
            ['name' => 'Open'],
            ['name' => 'Joint'],
            ['name' => 'Sole'],
            ['name' => 'Dual'],
        ],

        self::GROUP_CONDITION_LEVEL => [
            ['name' => 'To Remodel',  'adjustment_pct' => -30.00],
            ['name' => 'To Renovate', 'adjustment_pct' => -15.00],
            ['name' => self::CONDITION_BASELINE_NAME, 'adjustment_pct' => 0.00],
            ['name' => 'Good',        'adjustment_pct' => 3.00],
            ['name' => 'Very Good',   'adjustment_pct' => 12.00],
            ['name' => 'Excellent',   'adjustment_pct' => 20.00],
            ['name' => 'Exceptional', 'adjustment_pct' => 38.00],
        ],
    ];

    /**
     * AT-352 — give an agency the default settings for any group it has none of.
     *
     * **Per GROUP, not per row, and that is the whole design.** An agency that has
     * curated its own statuses (renamed "For Sale" to "On the Market", deleted
     * "On Auction" because it never auctions) must NEVER have those choices
     * silently reinstated by a later deploy — SYSTEM.md §3 exists precisely so an
     * agency owns its own vocabulary. So a group with even ONE row is treated as
     * configured and left completely alone; only a group that is genuinely EMPTY
     * gets seeded. That makes this safe to run on every agency, repeatedly,
     * forever.
     *
     * Consequence worth knowing: an agency with a curated status list does not
     * receive "Sold by 3rd Party" from here. It receives it from
     * 2026_08_20_000001, which targets exactly the agencies this method skips.
     * The two are complementary by construction — together they cover every
     * agency, and neither can overwrite a tenant's own choices.
     *
     * Writes via the query builder rather than the model on purpose:
     * BelongsToAgency's `creating` hook force-stamps the ACTING user's effective
     * agency over an explicit agency_id, so seeding a brand-new agency from an
     * admin's session would silently file the rows under the admin's own agency
     * (the trap AgencyObserver documents for AgencyContactSettings).
     *
     * @param  list<string>|null  $groups  Restrict to these groups; null = all.
     * @return int  rows inserted
     */
    public static function provisionDefaultsFor(int $agencyId, ?array $groups = null): int
    {
        if ($agencyId <= 0) {
            return 0;   // No agency context — never stamp a sentinel. Rule 17.
        }

        $inserted = 0;
        $now      = now();

        foreach (self::DEFAULT_ROWS as $group => $rows) {
            if ($groups !== null && ! in_array($group, $groups, true)) {
                continue;
            }

            $alreadyConfigured = \Illuminate\Support\Facades\DB::table('property_setting_items')
                ->where('agency_id', $agencyId)
                ->where('group', $group)
                ->whereNull('deleted_at')
                ->exists();

            if ($alreadyConfigured) {
                continue;
            }

            $payload = [];
            foreach ($rows as $i => $row) {
                $insert = [
                    'agency_id'  => $agencyId,
                    'group'      => $group,
                    'name'       => $row['name'],
                    'sort_order' => $i,
                    'is_default' => 1,
                    'active'     => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                // Both columns are meaningful only on their own group; the others
                // take the schema default (title_type 'other', adjustment_pct null).
                if (isset($row['title_type'])) {
                    $insert['title_type'] = $row['title_type'];
                }
                if (array_key_exists('adjustment_pct', $row)) {
                    $insert['adjustment_pct'] = $row['adjustment_pct'];
                }

                $payload[] = $insert;
            }

            \Illuminate\Support\Facades\DB::table('property_setting_items')->insert($payload);
            $inserted += count($payload);
        }

        return $inserted;
    }
}
