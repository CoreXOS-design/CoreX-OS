<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-454 — one row per manual correction of a MIC listing's listed date: who, when, old → new,
 * and the mandatory reason. History only; the live value is prospecting_listings.listed_date.
 */
class ProspectingListingDateCorrection extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id', 'prospecting_listing_id', 'user_id', 'old_date', 'new_date', 'reason',
    ];

    protected $casts = [
        'old_date' => 'date',
        'new_date' => 'date',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(ProspectingListing::class, 'prospecting_listing_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
