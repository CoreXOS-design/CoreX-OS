<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cached match score between a prospecting (off-market) listing and a
 * buyer contact. Written by PropertyMatchScoringService and read by the
 * prospecting UI / WhatsApp prospecting modal.
 *
 * Multi-tenancy is structural via BelongsToAgency. agency_id was added
 * in 2026_05_13_100003. See .ai/specs/unified-buyer-wishlist-spec.md.
 */
class ProspectingBuyerMatch extends Model
{
    use BelongsToAgency;

    protected $table = 'prospecting_buyer_matches';

    protected $fillable = [
        'prospecting_listing_id',
        'contact_id',
        'agency_id',
        'score',
        'tier',
        'source',
        'matched_features',
        'missing_features',
        'matched_at',
        'last_recompute_at',
        'agent_notified_at',
        'dismissed_at',
        'dismissed_by_user_id',
    ];

    protected $casts = [
        'score'             => 'integer',
        'matched_features'  => 'array',
        'missing_features'  => 'array',
        'matched_at'        => 'datetime',
        'last_recompute_at' => 'datetime',
        'agent_notified_at' => 'datetime',
        'dismissed_at'      => 'datetime',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(ProspectingListing::class, 'prospecting_listing_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by_user_id');
    }
}
