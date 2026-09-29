<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-inventory.md §12 — an agent's explicit "I checked this
 * room, there is nothing in it" for one room of one inventory. Exists so the
 * completion gate can tell a genuinely-empty room apart from a room nobody
 * ever opened — the same distinction rental-inspections' "Mark room N/A"
 * makes, recorded here as its own row because an inventory room has no
 * checklist items to attach an observation to.
 */
class RentalInventoryRoomMark extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'rental_inventory_id',
        'property_room_id',
        'marked_empty_by_user_id',
        'marked_empty_at',
    ];

    protected $casts = [
        'marked_empty_at' => 'datetime',
    ];

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(RentalInventory::class, 'rental_inventory_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(PropertyRoom::class, 'property_room_id');
    }

    public function markedEmptyBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_empty_by_user_id');
    }
}
