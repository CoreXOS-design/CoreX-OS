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
 */
class RentalInventoryPhoto extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'rental_inventory_id',
        'property_room_id',
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

    /**
     * The six-move photo-tagging model (rental-inspections.md §22.1, adopted
     * here per §4a) — a room tag is exclusive (a photo has AT MOST one room,
     * null = untagged/tray), while a line-item tag is many-to-many (Johan's
     * own example: "the TV and the stand in one lounge photo"). A line
     * belongs to exactly one room, so a line tag only ever makes sense
     * against a photo already tagged to THAT line's room — moving (or
     * clearing) a photo's room supersedes any line tags that no longer
     * belong anywhere, the same "retagging clears the old context" rule
     * RentalInspectionPhoto::tagTo() applies to its own room+item pair.
     * A no-op re-tag to the SAME room leaves existing line tags untouched.
     */
    public function retagRoom(?int $roomId): void
    {
        if ((int) $this->property_room_id !== (int) $roomId) {
            $this->lines()->detach();
        }
        $this->forceFill(['property_room_id' => $roomId])->save();
    }

    /** Back to the untagged tray — clears the room AND every line tag (§22.1's "supersede" rule applied to null). */
    public function untagFromRoom(): void
    {
        $this->retagRoom(null);
    }
}
