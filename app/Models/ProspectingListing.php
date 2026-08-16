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
        'tracked_property_id',
        'mandate_type',
        // MIC SOLD / OFF-MARKET + REF-TRACKING (cc2) — portal lifecycle
        'portal_status',
        'portal_status_changed_at',
        'off_market_at',
        // MIC SUBURB RECONCILE (cc2) — the capture session that last saw this listing
        'last_search_id',
    ];

    protected $casts = [
        'price'            => 'integer',
        'is_active'        => 'boolean',
        'first_seen_at'    => 'datetime',
        'last_seen_at'     => 'datetime',
        'price_changed_at'      => 'datetime',
        'first_seen_email_date' => 'datetime',
        'matched_at'            => 'datetime',
        'tracked_property_id'   => 'integer',
        'portal_status_changed_at' => 'datetime',
        'off_market_at'            => 'datetime',
        'last_search_id'           => 'integer',
    ];

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
     * Days the listing was (or has been) on-market — from first sighting to its
     * off-market date, or to now if still live. NULL when we never saw it first.
     */
    public function daysOnMarket(): ?int
    {
        if (! $this->first_seen_at) {
            return null;
        }
        $end = $this->off_market_at ?? now();
        return $this->first_seen_at->diffInDays($end);
    }

    /** Rows whose last portal-reported status is off-market. */
    public function scopeOffMarket($query)
    {
        return $query->whereIn('portal_status', self::OFF_MARKET_STATUSES);
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
}
