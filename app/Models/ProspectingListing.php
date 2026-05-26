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
        'first_seen_at',
        'last_seen_at',
        'price_changed_at',
        'is_active',
        'first_seen_email_date',
        'matched_property_id',
        'matched_at',
        'tracked_property_id',
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

    public function claimedBy()
    {
        return $this->activeClaim?->user;
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
