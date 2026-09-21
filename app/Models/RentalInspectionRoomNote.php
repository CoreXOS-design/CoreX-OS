<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-inspections.md §17 — Johan, 2026-09-21, from Retha's real
 * paper out-inspection form: a free-text note for a whole ROOM, not any
 * single item — "3x nails in wall", "damp under windows in corner". Scoped
 * to (rental_inspection_id, property_room_id), never to PropertyRoom
 * itself (a permanent, cross-tenancy record) — this is what one specific
 * walkthrough found in one room.
 *
 * Immutable, same convention as RentalInspectionObservation (§3.3): never
 * edited, never deleted. A correction is a NEW row; "the room's current
 * note" is simply the latest one for this (inspection, room) pair.
 */
class RentalInspectionRoomNote extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    protected $fillable = [
        'agency_id',
        'rental_inspection_id',
        'property_room_id',
        'note',
        'created_by_user_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $note) {
            if (empty($note->created_at)) {
                $note->created_at = now();
            }
        });
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(RentalInspection::class, 'rental_inspection_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(PropertyRoom::class, 'property_room_id');
    }
}
