<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The website's own LIFETIME total for one (site, listing, metric), exactly as
 * it last reported it.
 *
 * Never added to — assigned. It is the reconciliation surface: comparing
 * reported_total against SUM(listing_website_stats.metric_count) is how a lost
 * batch shows up as drift instead of quietly shrinking the numbers. It is also
 * what the UI shows as "all time", so a gap in the daily series never
 * under-reports the headline figure.
 *
 * Spec: .ai/specs/website-listing-stats.md §4.3
 */
class ListingWebsiteStatTotal extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'site',
        'property_id',
        'metric',
        'reported_total',
        'reported_at',
    ];

    protected $casts = [
        'reported_total' => 'integer',
        'reported_at'    => 'datetime',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
