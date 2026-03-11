<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PortalListing extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'source_site',
        'portal_listing_id',
        'canonical_url',
        'first_seen_at',
        'last_seen_at',
        'last_capture_id',
        'current_fields_json',
        'primary_image_url',
    ];

    protected $casts = [
        'first_seen_at'       => 'datetime',
        'last_seen_at'        => 'datetime',
        'current_fields_json' => 'array',
    ];

    public function lastCapture()
    {
        return $this->belongsTo(PortalCapture::class, 'last_capture_id');
    }

    public function observations()
    {
        return $this->hasMany(PortalListingObservation::class, 'portal_listing_id');
    }

    /**
     * Check if the listing has ever had a price change.
     */
    public function hasPriceChange(): bool
    {
        return $this->observations()
            ->whereNotNull('changed_fields_json')
            ->whereRaw("json_extract(changed_fields_json, '$.price') IS NOT NULL")
            ->exists();
    }

    /**
     * Get the latest price change observation.
     */
    public function latestPriceChange(): ?PortalListingObservation
    {
        return $this->observations()
            ->whereNotNull('changed_fields_json')
            ->whereRaw("json_extract(changed_fields_json, '$.price') IS NOT NULL")
            ->orderByDesc('observed_at')
            ->first();
    }
}
