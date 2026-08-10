<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A price write the MIC import guard REFUSED because it was an implausible
 * order-of-magnitude jump vs the stored price (dropped-zero / wrong-figure
 * misparse). Kept for review — the good price was preserved, not overwritten.
 */
class ProspectingPriceAnomaly extends Model
{
    protected $fillable = [
        'prospecting_listing_id',
        'agency_id',
        'portal_source',
        'portal_ref',
        'stored_price',
        'rejected_price',
        'jump_factor',
        'search_url',
        'status',
        'reviewed_at',
        'reviewed_by_user_id',
    ];

    protected $casts = [
        'stored_price'   => 'integer',
        'rejected_price' => 'integer',
        'jump_factor'    => 'decimal:2',
        'reviewed_at'    => 'datetime',
    ];

    public function listing()
    {
        return $this->belongsTo(ProspectingListing::class, 'prospecting_listing_id');
    }
}
