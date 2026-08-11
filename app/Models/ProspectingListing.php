<?php

namespace App\Models;

use App\Models\Prospecting\TrackedProperty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class ProspectingListing extends Model
{
    use BelongsToAgency, SoftDeletes;

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
    ];

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
}
