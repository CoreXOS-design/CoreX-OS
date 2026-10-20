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
        'rental_inspection_photo_id',
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

    /**
     * Audit 2026-10-19 B32 - a photo this inventory SHARES with the inspection: no file of its own, it shows the inspection photo named by
     * `rental_inspection_photo_id`. The address a screen reads is the source photo's (a signed, expiring link); a source that has been archived
     * leaves the reference with no picture, and {@see scopeUsable()} keeps such a reference out of every list.
     */
    public function isReference(): bool
    {
        return $this->rental_inspection_photo_id !== null;
    }

    public function sourceInspectionPhoto(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionPhoto::class, 'rental_inspection_photo_id');
    }

    /**
     * What a screen puts in an `<img src>`. An own file stored on the private disk (a new upload) reads as a signed, expiring URL, like an
     * inspection photo; a legacy row still holding a public /storage URL reads as it always did; a reference reads as its source photo's address.
     */
    public function getStoragePathAttribute($value): ?string
    {
        if (($this->attributes['rental_inspection_photo_id'] ?? null) !== null) {
            return $this->sourceInspectionPhoto?->storage_path;
        }

        $images = app(\App\Services\Images\PrivateImageService::class);
        if (! $images->isPrivate($value)) {
            return $value;
        }

        return $images->signedUrl(
            $value,
            $this->agency_id,
            [['rental_inventory_photos', (int) $this->id], ['rental_inventories', (int) $this->rental_inventory_id]],
            RentalInspectionSetting::photoLinkMinutesFor($this->agency_id),
        );
    }

    /** The stored value - the private reference, the legacy public URL, or nothing for a shared photo. */
    public function rawStoragePath(): ?string
    {
        return $this->attributes['storage_path'] ?? null;
    }

    /**
     * Every photo a screen may show: an own photo, or a shared one whose inspection photo is still live. A shared photo whose source was
     * archived (or whose inspection was archived) drops out, so a tile with no picture is never drawn.
     */
    public function scopeUsable($query)
    {
        $table = $query->getModel()->getTable();

        return $query->where(function ($q) use ($table) {
            $q->whereNull($table . '.rental_inspection_photo_id')
                ->orWhereIn($table . '.rental_inspection_photo_id', function ($sub) {
                    $sub->select('rental_inspection_photos.id')->from('rental_inspection_photos')
                        ->join('rental_inspections', 'rental_inspections.id', '=', 'rental_inspection_photos.rental_inspection_id')
                        ->whereNull('rental_inspection_photos.deleted_at')
                        ->whereNull('rental_inspections.deleted_at');
                });
        });
    }

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
