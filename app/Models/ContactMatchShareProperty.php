<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-Core-Matches, share-history piece — one row per property that was
 * part of a share event's live match set, at the moment it was shared.
 * Written once, by ContactMatchShare::record() only, in the same
 * transaction as the share row itself. Never edited, never recalculated
 * when stock changes later — see the migration's own docblock.
 */
class ContactMatchShareProperty extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    protected $fillable = [
        'agency_id',
        'contact_match_id',
        'contact_match_share_id',
        'property_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function share(): BelongsTo
    {
        return $this->belongsTo(ContactMatchShare::class, 'contact_match_share_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
