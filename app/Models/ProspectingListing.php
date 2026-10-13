<?php

namespace App\Models;

use App\Models\Prospecting\TrackedProperty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\InheritsBranchFromParent;
class ProspectingListing extends Model
{
    use BelongsToBranch, InheritsBranchFromParent, BelongsToAgency, SoftDeletes;

    /**
     * Branch follows the capturing agent — set here (not the acting user) so a
     * queued/import capture with no auth still resolves the right branch.
     */
    protected function branchParent(): array
    {
        return [\App\Models\User::class, 'captured_by_user_id'];
    }

    protected $fillable = [
        'agency_id',
        'captured_by_user_id',
        'portal_source',
        'portal_ref',
        'portal_url',
        'address',
        'normalized_address',
        'property_group_id',
        'suburb',
        'district',
        'price',
        'bedrooms',
        'bathrooms',
        'garages',
        'property_size_m2',
        'erf_size_m2',
        'property_type',
        'agent_name',
        'agency_name',
        'thumbnail_path',
        'thumbnail_source_url',
        'thumbnail_blocked_reason',
        'first_seen_at',
        'last_seen_at',
        'price_changed_at',
        'is_active',
        'first_seen_email_date',
        'matched_property_id',
        'matched_at',
        // Possible-match (2026-08-22) — a SEPARATE, advisory-only signal from
        // matched_property_id above. See ComputePossibleStockMatchJob.
        'possible_property_id',
        'possible_match_verdict',
        'possible_match_candidate_ids',
        'possible_matched_at',
        'tracked_property_id',
        'mandate_type',
        // MIC SOLD / OFF-MARKET + REF-TRACKING (cc2) — portal lifecycle
        'portal_status',
        'portal_status_changed_at',
        'off_market_at',
        // MIC SUBURB RECONCILE (cc2) — the capture session that last saw this listing
        'last_search_id',
        // AT-454 — the advert's REAL listed date (portal's own), separate from first_seen_at
        'listed_date',
        'listed_date_source',
    ];

    protected $casts = [
        'price'            => 'integer',
        'is_active'        => 'boolean',
        'first_seen_at'    => 'datetime',
        'last_seen_at'     => 'datetime',
        'price_changed_at'      => 'datetime',
        'first_seen_email_date' => 'datetime',
        'matched_at'            => 'datetime',
        'possible_property_id'          => 'integer',
        'possible_match_candidate_ids'  => 'array',
        'possible_matched_at'           => 'datetime',
        'tracked_property_id'   => 'integer',
        'portal_status_changed_at' => 'datetime',
        'off_market_at'            => 'datetime',
        'last_search_id'           => 'integer',
        'listed_date'              => 'date',
    ];

    public const LISTED_DATE_SOURCE_PORTAL = 'portal';
    public const LISTED_DATE_SOURCE_AGENT  = 'agent';

    // ── Portal lifecycle status (MIC SOLD / OFF-MARKET + REF-TRACKING) ──
    public const PORTAL_STATUS_ACTIVE      = 'active';
    public const PORTAL_STATUS_UNDER_OFFER = 'under_offer';
    public const PORTAL_STATUS_SOLD        = 'sold';
    public const PORTAL_STATUS_WITHDRAWN   = 'withdrawn';

    /** Statuses that take a listing OUT of the active canvass pool. */
    public const OFF_MARKET_STATUSES = [
        self::PORTAL_STATUS_UNDER_OFFER,
        self::PORTAL_STATUS_SOLD,
        self::PORTAL_STATUS_WITHDRAWN,
    ];

    /** The full accepted status vocabulary (for validation / normalisation). */
    public const PORTAL_STATUSES = [
        self::PORTAL_STATUS_ACTIVE,
        self::PORTAL_STATUS_UNDER_OFFER,
        self::PORTAL_STATUS_SOLD,
        self::PORTAL_STATUS_WITHDRAWN,
    ];

    /** True when the last portal-reported status means the listing is off-market. */
    public function isOffMarketStatus(): bool
    {
        return in_array((string) $this->portal_status, self::OFF_MARKET_STATUSES, true);
    }

    /**
     * When this advert went on the market, as best we know: the portal's real listed date when we
     * have one (captured, or corrected by an agent), else the day CoreX first captured it.
     * AT-454 — the ONE definition behind "days on market", "new on market" and "long on market".
     */
    public function listedOn(): ?\Illuminate\Support\Carbon
    {
        if ($this->listed_date) {
            return $this->listed_date->copy()->startOfDay();
        }

        return $this->first_seen_at?->copy()->startOfDay();
    }

    /** True when listedOn() is the portal's real date, false when it is only our first sighting. */
    public function hasRealListedDate(): bool
    {
        return $this->listed_date !== null;
    }

    /**
     * Days the listing was (or has been) on-market — from its listed date (else first sighting) to
     * its off-market date, or to now if still live. NULL when we never saw it first.
     */
    public function daysOnMarket(): ?int
    {
        $start = $this->listedOn();
        if (! $start) {
            return null;
        }
        $end = $this->off_market_at ?? now();
        return (int) $start->diffInDays($end->copy()->startOfDay());
    }

    /**
     * Listed within the last $days days — "new on market". By the real listed date when we have one
     * (calendar-day compare, today included), else by first sighting EXACTLY as the old
     * `new_today` preset compared it (a timestamp against now − $days), so rows without a real
     * date behave as they always did.
     */
    public function scopeListedWithinDays($query, int $days, string $table = 'prospecting_listings')
    {
        $days = max(0, $days);

        return $query->where(function ($q) use ($days, $table) {
            $q->where(fn ($w) => $w->whereNotNull("{$table}.listed_date")
                    ->where("{$table}.listed_date", '>=', now()->subDays($days)->toDateString()))
              ->orWhere(fn ($w) => $w->whereNull("{$table}.listed_date")
                    ->where("{$table}.first_seen_at", '>=', now()->subDays($days)));
        });
    }

    /** Listed MORE than $days days ago — "long on market". Same two-way definition as above. */
    public function scopeListedBeforeDays($query, int $days, string $table = 'prospecting_listings')
    {
        $days = max(0, $days);

        return $query->where(function ($q) use ($days, $table) {
            $q->where(fn ($w) => $w->whereNotNull("{$table}.listed_date")
                    ->where("{$table}.listed_date", '<', now()->subDays($days)->toDateString()))
              ->orWhere(fn ($w) => $w->whereNull("{$table}.listed_date")
                    ->where("{$table}.first_seen_at", '<', now()->subDays($days)));
        });
    }

    public function dateCorrections()
    {
        return $this->hasMany(ProspectingListingDateCorrection::class, 'prospecting_listing_id');
    }

    /** Rows whose last portal-reported status is off-market. */
    public function scopeOffMarket($query)
    {
        return $query->whereIn('portal_status', self::OFF_MARKET_STATUSES);
    }

    /** The legacy text old captures stored when the tile showed no street address. */
    public const ADDRESS_PLACEHOLDER = 'Address not available';

    /**
     * THE one "has an address" rule for MIC listings (Road to Sold, piece 1).
     *
     * A listing has an address when EITHER
     *   (a) its own captured `address` text is real (not null, '' or the legacy placeholder), OR
     *   (b) the tracked property it sits on has a street name.
     *
     * (b) is what makes an address an agent corrected through
     * TrackedPropertyController::editAddress count: that edit writes the tracked property's
     * address history and the observer refreshes tracked_properties.street_name, but it never
     * touched prospecting_listings.address — so the Work tab filter (which only read the listing
     * text) kept calling a corrected listing "no address". It also lets a cross-portal twin
     * (P24 + PP advert of one property) share the address one of them carries.
     *
     * tracked_properties.street_name is the cache column, not tracked_property_addresses: that
     * history table holds a primary row for only a handful of properties, the cache is filled for
     * all of them. Every place that asks "has an address" must call this — never re-spell the
     * three-way text test.
     *
     * @param  string  $table  the listing table name/alias in the surrounding query
     */
    public function scopeWithAddress($query, string $table = 'prospecting_listings')
    {
        return $query->where(function ($q) use ($table) {
            $q->where(function ($t) use ($table) {
                $t->whereNotNull("{$table}.address")
                  ->where("{$table}.address", '<>', '')
                  ->where("{$table}.address", '<>', self::ADDRESS_PLACEHOLDER);
            })->orWhereExists(static::trackedStreetExists($table));
        });
    }

    /** Exact inverse of scopeWithAddress(). */
    public function scopeWithoutAddress($query, string $table = 'prospecting_listings')
    {
        return $query->where(function ($t) use ($table) {
            $t->whereNull("{$table}.address")
              ->orWhere("{$table}.address", '')
              ->orWhere("{$table}.address", self::ADDRESS_PLACEHOLDER);
        })->whereNotExists(static::trackedStreetExists($table));
    }

    /** Instance form of the same rule, for code that already holds a listing. */
    public function hasAddress(): bool
    {
        $text = trim((string) $this->address);
        if ($text !== '' && $text !== self::ADDRESS_PLACEHOLDER) {
            return true;
        }
        if (! $this->tracked_property_id) {
            return false;
        }

        return \Illuminate\Support\Facades\DB::table('tracked_properties')
            ->where('id', $this->tracked_property_id)
            ->whereNull('deleted_at')
            ->whereNotNull('street_name')->where('street_name', '<>', '')
            ->exists();
    }

    private static function trackedStreetExists(string $table): \Closure
    {
        return function ($s) use ($table) {
            $s->select(\Illuminate\Support\Facades\DB::raw(1))
                ->from('tracked_properties as tp_addr')
                ->whereColumn('tp_addr.id', "{$table}.tracked_property_id")
                ->whereNull('tp_addr.deleted_at')
                ->whereNotNull('tp_addr.street_name')
                ->where('tp_addr.street_name', '<>', '');
        };
    }

    /**
     * AT-380 — role-driven visibility scope for the Market Intelligence
     * canvassing pool. Honours the per-role Data Scope set in Role Manager
     * (market_intelligence.view → own | branch | all | none). Agency
     * isolation is already applied by BelongsToAgency, so this only narrows
     * within the current agency.
     *
     *   own    → listings captured by this user
     *   branch → listings captured by anyone in the user's branch. A user
     *            with NO single branch_id legitimately spans every branch
     *            (branches.view_all) — for them "branch" IS "all", so no
     *            extra narrowing; a genuinely unassigned user falls back to
     *            'own' (mirrors CalendarEvent::scopeVisibleTo()).
     *   all    → no extra narrowing (whole agency)
     *   none   → nothing (no access)
     *
     * 2026-08-20 — 'own' no longer filters on captured_by_user_id. Live data
     * (HFC, agency 1): 39,240 of 39,556 listings (99.2%) are captured_by the
     * account that ran the bulk import, not the individual agent working the
     * lead — only 2 of ~14 agents have ANY personally-captured rows at all.
     * captured_by_user_id records who ran the ingest, not who a listing is
     * relevant to; it was never a per-agent ownership signal for this table.
     * 'own' collapsed to zero results for every agent/office_admin as soon
     * as import volume swamped the tiny slice of real per-agent captures
     * (fine at ship time on 9,595 rows; broken today on 39,556). branch_id
     * is the only field on this table that actually reflects who a listing
     * is relevant to, so 'own' now resolves through the same branch-based
     * logic 'branch' already uses — 'own' stays the narrowest tier (still
     * falls back to the same captured_by_user_id-only carve-out for a truly
     * branchless, non-view-all user), it just no longer collapses to empty
     * for the common case of an agent in a real branch.
     */
    public function scopeVisibleTo($query, User $user, ?string $scope)
    {
        $branchScoped = fn ($q) => $user->effectiveBranchId()
            ? $q->where('branch_id', $user->effectiveBranchId())
            : ($user->hasPermission('branches.view_all')
                ? $q
                : $q->where('captured_by_user_id', $user->id));

        return match ($scope) {
            'all'           => $query,
            'branch', 'own' => $branchScoped($query),
            'none'          => $query->whereRaw('1 = 0'),
            default         => $branchScoped($query), // unset/null scope — same as 'own'
        };
    }

    public function agency()
    {
        return $this->belongsTo(Agency::class);
    }

    public function matchedProperty()
    {
        return $this->belongsTo(Property::class, 'matched_property_id');
    }

    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class, 'tracked_property_id');
    }

    public function capturedBy()
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }

    public function priceHistory()
    {
        return $this->hasMany(ProspectingPriceHistory::class);
    }

    public function claims()
    {
        return $this->hasMany(ProspectingClaim::class);
    }

    public function activeClaim()
    {
        return $this->hasOne(ProspectingClaim::class)->where('is_active', true);
    }

    /**
     * Tier-scored buyer matches against this listing. Used by the MIC
     * Opportunities tab (Phase D4) to surface strong-match counts per
     * tracked property (whereHas predicate joins on score ≥ 80).
     */
    public function buyerMatches()
    {
        return $this->hasMany(ProspectingBuyerMatch::class, 'prospecting_listing_id');
    }

    public function claimedBy()
    {
        return $this->activeClaim?->user;
    }

    /** True when this listing is flagged as held sole/exclusive by another agency. */
    public function isSoleOrExclusiveMandate(): bool
    {
        return in_array(strtolower((string) $this->mandate_type), ['sole', 'exclusive'], true);
    }

    /**
     * Company stock (Johan's model) — this scraped listing IS one of the agency's
     * OWN portal listings: its portal_ref exactly matches a property's P24/PP
     * listing number (properties.p24_ref / pp_ref). The EXACT identity match,
     * deliberately distinct from the fuzzy address-based matched_property_id.
     *
     * portal_ref is stored PREFIXED ('P24-<num>' / 'PP-<ref>'); the property refs
     * are unprefixed. Rather than a per-row correlated SUBSTRING subquery (which
     * is non-sargable and scans properties for every listing — it made the MIC
     * page time out), we precompute the agency's set of prefixed company-stock
     * refs ONCE and filter with an indexed whereIn/whereNotIn on portal_ref.
     * Memoised per agency per request.
     */
    protected static array $companyStockRefMapCache = [];

    /** ['P24-<num>' | 'PP-<ref>' => property id] for the agency. One query, chunked. */
    public static function companyStockRefMapFor(int $agencyId): array
    {
        if (!array_key_exists($agencyId, static::$companyStockRefMapCache)) {
            $map = [];
            \DB::table('properties')
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->select('id', 'p24_ref', 'pp_ref')
                ->orderBy('id')
                ->chunk(2000, function ($rows) use (&$map) {
                    foreach ($rows as $r) {
                        if (!empty($r->p24_ref)) $map['P24-' . $r->p24_ref] = (int) $r->id;
                        if (!empty($r->pp_ref))  $map['PP-' . $r->pp_ref]   = (int) $r->id;
                    }
                });
            static::$companyStockRefMapCache[$agencyId] = $map;
        }
        return static::$companyStockRefMapCache[$agencyId];
    }

    /** Prefixed portal_refs of the agency's own stock. */
    public static function companyStockRefsFor(int $agencyId): array
    {
        return array_keys(static::companyStockRefMapFor($agencyId));
    }

    /**
     * This listing IS our own on-market stock — its portal_ref exactly matches an
     * ON-MARKET property's P24/PP ref OR its normalized_address exactly matches an
     * on-market property's normalized address. Identity comes from the canonical
     * App\Services\Prospecting\OnMarketStockService (single source of truth, gated
     * to Property::scopeOnMarket()); an off-market property no longer suppresses
     * its matching listing — that listing is a legitimate canvass target again.
     */
    public function scopeWhereCompanyStock($query, int $agencyId)
    {
        return app(\App\Services\Prospecting\OnMarketStockService::class)
            ->applyIsStock($query, $agencyId);
    }

    /**
     * Inverse of scopeWhereCompanyStock — the canvass pool: exclude listings that
     * are our own on-market stock (ref OR normalized_address). NULL-safe: a listing
     * with a NULL normalized_address that isn't ref-matched STAYS in the pool
     * (a bare NOT IN would drop it on the NULL).
     */
    public function scopeWhereNotCompanyStock($query, int $agencyId)
    {
        return app(\App\Services\Prospecting\OnMarketStockService::class)
            ->applyNotStock($query, $agencyId);
    }

    /**
     * Normalize an address for cross-portal matching.
     * Strips punctuation, lowercases, collapses whitespace, appends suburb.
     */
    public static function normalizeAddress(?string $address, string $suburb = ''): ?string
    {
        if (!$address || $address === 'Address not available') {
            return null;
        }

        $addr = strtolower(trim($address));
        $addr = preg_replace('/[^a-z0-9\s]/', '', $addr);
        $addr = preg_replace('/\s+/', ' ', $addr);

        if ($suburb) {
            $suburb = strtolower(trim($suburb));
            $suburb = preg_replace('/[^a-z0-9\s]/', '', $suburb);
            $addr .= ' ' . $suburb;
        }

        return $addr;
    }

    /**
     * The REAL street number, read only from the address's real street segment —
     * never searched for anywhere in the free text. P24/PP convention is
     * "[complex/building name], [street number] [street name]", so the real
     * street segment is the LAST comma-separated part, not necessarily the first
     * number in the string (that first number is often a complex/unit number —
     * e.g. "14 Dumela Holiday Flats, 1 Marine Drive": "14" is the complex, "1"
     * is the real street number). Single source of truth for every caller that
     * needs to discriminate two addresses on the same street by number —
     * ProspectingStockMatchService::matchProspect() Pass 2 and the Pitch Now
     * collision check (EntryPointController -> MapProspectStatusService) both
     * call this so they can never drift apart on what counts as "the number".
     */
    public static function parseStreetNumber(?string $address): ?string
    {
        $segments = array_filter(array_map('trim', explode(',', (string) $address)));
        $streetSegment = $segments ? strtolower(end($segments)) : '';
        if (preg_match('/^(\d+)\b/', $streetSegment, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * The unit/door number, when the free-text address has one readable —
     * conservative by design (2026-08-22, matcher-accuracy build): the FIRST
     * comma-segment is where a unit/complex reference lives per the same
     * P24/PP convention parseStreetNumber() relies on ("[complex/unit],
     * [street]") — but unlike the street number, a unit reference is
     * genuinely ambiguous free text ("B1 Allesreg", "Unit 5, Parklands", "27
     * (2) Casa-Uvongo"). Only a clean leading alnum token is extracted; a
     * messy or absent one returns null rather than guess — the confidence
     * gate this feeds (ProspectingStockMatchService::findPossibleMatch())
     * treats "no unit parsed" as an honest POSSIBLE, never a forced CONFIDENT.
     */
    public static function parseUnitNumber(?string $address): ?string
    {
        $segments = array_filter(array_map('trim', explode(',', (string) $address)));
        if (count($segments) < 2) {
            return null; // no separate unit/complex segment at all
        }
        $first = reset($segments);
        $first = preg_replace('/^unit\s*/i', '', trim($first));
        $first = preg_replace('/^(?:no|door|flat)\.?\s*/i', '', $first);
        if (preg_match('/^([a-z]?\d+[a-z]?)\b/i', $first, $m)) {
            return $m[1];
        }
        return null;
    }
}
