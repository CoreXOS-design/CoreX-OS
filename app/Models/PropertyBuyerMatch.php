<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cached match score between an internal-stock property and a buyer
 * contact. Written by PropertyMatchScoringService::recomputeForBuyer
 * and read by demand-intelligence queries.
 *
 * Multi-tenancy is structural via BelongsToAgency. agency_id was added
 * in 2026_05_13_100004. See .ai/specs/unified-buyer-wishlist-spec.md.
 */
class PropertyBuyerMatch extends Model
{
    use BelongsToAgency;

    protected $table = 'property_buyer_matches';

    /** The table has no created_at / updated_at columns — only computed_at. */
    public $timestamps = false;

    protected $fillable = [
        'property_id',
        'contact_id',
        'agency_id',
        'score',
        'tier',
        'breakdown',
        'missing_features',
        'computed_at',
    ];

    protected $casts = [
        'score'            => 'integer',
        'breakdown'        => 'array',
        'missing_features' => 'array',
        'computed_at'      => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
