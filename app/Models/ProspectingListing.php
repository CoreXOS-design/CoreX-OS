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
     * listing number (properties.p24_ref / pp_ref). This is the EXACT identity
     * match, deliberately distinct from the fuzzy address-based matched_property_id
     * (which over-matches address collisions and other agencies' listings).
     *
     * portal_ref is stored prefixed ('P24-<num>' / 'PP-<ref>'); the property refs
     * are unprefixed, so we compare against SUBSTRING(portal_ref, 5|4). The
     * subquery correlates on the OUTER prospecting_listings row, so these scopes
     * must be used on an UNALIASED prospecting_listings query.
     */
    protected static function companyStockExistsSubquery($s): void
    {
        $s->selectRaw('1')->from('properties as p')
          ->whereColumn('p.agency_id', 'prospecting_listings.agency_id')
          ->whereNull('p.deleted_at')
          ->where(function ($w) {
              $w->whereRaw("(prospecting_listings.portal_source = 'p24' AND p.p24_ref IS NOT NULL AND p.p24_ref <> '' AND p.p24_ref = SUBSTRING(prospecting_listings.portal_ref, 5))")
                ->orWhereRaw("(prospecting_listings.portal_source = 'pp' AND p.pp_ref IS NOT NULL AND p.pp_ref <> '' AND p.pp_ref = SUBSTRING(prospecting_listings.portal_ref, 4))");
          });
    }

    public function scopeWhereCompanyStock($query)
    {
        return $query->whereExists(fn ($s) => static::companyStockExistsSubquery($s));
    }

    public function scopeWhereNotCompanyStock($query)
    {
        return $query->whereNotExists(fn ($s) => static::companyStockExistsSubquery($s));
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
