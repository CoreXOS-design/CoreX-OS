<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-inventory.md §0b — a photo uploaded against one room of
 * the property. Soft-deletable, same as every other evidence record here.
 * §14 — `side` distinguishes a move-in photo (every one captured before
 * this column existed, and the default for every new one from the capture
 * screen) from a move-out photo (taken from the comparison screen, tagged
 * straight to a line) — same table, same storage pipeline, same tagging
 * pivot for both; never a second photo model.
 */
class RentalInventoryPhoto extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const SIDE_MOVE_IN = 'move_in';
    public const SIDE_MOVE_OUT = 'move_out';

    protected $fillable = [
        'agency_id',
        'rental_inventory_id',
        'property_room_id',
        'side',
        'storage_path',
        'file_size_bytes',
        'uploaded_by_user_id',
        'archived_by_user_id',
        'client_idempotency_key',
    ];

    protected $casts = [
        'file_size_bytes' => 'integer',
    ];

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(RentalInventory::class, 'rental_inventory_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(PropertyRoom::class, 'property_room_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** §0b — Johan's own example: a photo may carry several line-item tags (the TV, the couch...). */
    public function lines(): BelongsToMany
    {
        return $this->belongsToMany(RentalInventoryLine::class, 'rental_inventory_line_photos');
    }

    /**
     * §4a — adopting the same photo machinery rental-inspections already
     * built: soft-delete only, never a hard delete (non-negotiable #1).
     * Mirrors RentalInspectionPhoto::archive() exactly.
     */
    public function archive(User $by): void
    {
        $this->forceFill(['archived_by_user_id' => $by->id])->save();
        $this->delete();
    }
}
