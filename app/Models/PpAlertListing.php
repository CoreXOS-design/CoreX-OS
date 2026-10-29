<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\HasCanonicalPortalRef;
use App\Support\Property\PortalRef;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A Private Property e-mail alert advert, per agency (property-identity.md section 15, C1). The twin of P24Listing. */
class PpAlertListing extends Model
{
    use SoftDeletes;
    use BelongsToAgency;
    use HasCanonicalPortalRef;

    protected $table = 'pp_alert_listings';

    protected $fillable = [
        'agency_id', 'pp_listing_number', 'asking_price', 'original_price', 'property_type', 'suburb', 'area', 'bedrooms', 'bathrooms',
        'garages', 'is_mandated', 'listing_status', 'pp_url', 'first_seen_date', 'last_seen_date', 'times_seen',
    ];

    protected $casts = [
        'asking_price' => 'decimal:2',
        'original_price' => 'decimal:2',
        'is_mandated' => 'boolean',
        'first_seen_date' => 'date',
        'last_seen_date' => 'date',
        'times_seen' => 'integer',
    ];

    /** @return array<string, ?string> */
    public function canonicalRefValues(): array
    {
        return ['canonical_ref' => PortalRef::normalise($this->pp_listing_number, PortalRef::PP)];
    }

    public function priceChanges(): HasMany
    {
        return $this->hasMany(PpAlertPriceChange::class, 'listing_id');
    }
}
