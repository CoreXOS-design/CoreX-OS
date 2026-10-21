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
     * `rental_inspection_photo_id`. The address a screen reads is a signed, expiring link to that photo's file; a source that has been archived
     * leaves a DRAFT inventory's reference with no picture, and {@see scopeUsable()} keeps such a reference out of every list.
     *
     * Audit M4: once the inventory is awaiting signatures or completed, its shared photos are part of signed evidence and no later archive of
     * the inspection photo (or its inspection) takes them out of it - {@see self::evidenceIsLocked()}. The relation therefore includes
     * archived sources; the address and the usable scope decide what is shown.
     */
    public function isReference(): bool
    {
        return $this->rental_inspection_photo_id !== null;
    }

    public function sourceInspectionPhoto(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionPhoto::class, 'rental_inspection_photo_id')->withTrashed();
    }

    /** Inventory statuses whose shared photos can no longer be taken out by archiving the inspection photo they point at. */
    public const LOCKED_INVENTORY_STATUSES = [RentalInventory::STATUS_AWAITING_SIGNATURE, RentalInventory::STATUS_COMPLETED];

    /** Audit M4 - is this photo's inventory awaiting signatures or completed (its shared photos are signed evidence)? */
    public function evidenceIsLocked(): bool
    {
        $status = RentalInventory::withoutGlobalScopes()->whereKey($this->rental_inventory_id)->value('status');

        return in_array($status, self::LOCKED_INVENTORY_STATUSES, true);
    }

    /**
     * What a screen puts in an `<img src>`. An own file stored on the private disk (a new upload) reads as a signed, expiring URL, like an
     * inspection photo; a legacy row still holding a public /storage URL reads as it always did; a reference reads as a signed link to its
     * source photo's file.
     *
     * A reference link is guarded by THIS reference row and its inventory (audit L7: archiving the reference revokes links already handed out,
     * checked when the link is served) and - while the inventory is still a draft - by the source photo and its inspection too, so archiving the
     * source kills it there. On a locked inventory (audit M4) the source guards are dropped: the picture stays.
     */
    public function getStoragePathAttribute($value): ?string
    {
        $images = app(\App\Services\Images\PrivateImageService::class);

        if (($this->attributes['rental_inspection_photo_id'] ?? null) !== null) {
            $source = $this->sourceInspectionPhoto;
            if ($source === null) {
                return null;
            }
            $raw = $source->rawStoragePath();
            $own = [['rental_inventory_photos', (int) $this->id], ['rental_inventories', (int) $this->rental_inventory_id]];
            $locked = $this->evidenceIsLocked();
            if ($source->trashed() && ! $locked) {
                return null;
            }
            if (! $images->isPrivate($raw)) {
                return $raw; // a legacy public file: no signed link to guard
            }
            $guards = $locked ? $own : array_merge($own, [['rental_inspection_photos', (int) $source->id], ['rental_inspections', (int) $source->rental_inspection_id]]);

            return $images->signedUrl($raw, $this->agency_id, $guards, RentalInspectionSetting::photoLinkMinutesFor($this->agency_id));
        }

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
                })
                // Audit M4 - a shared photo on an inventory that is awaiting signatures or completed stays, whatever happens to its source.
                ->orWhereIn($table . '.rental_inventory_id', function ($sub) {
                    $sub->select('rental_inventories.id')->from('rental_inventories')
                        ->whereIn('rental_inventories.status', self::LOCKED_INVENTORY_STATUSES)
                        ->whereNull('rental_inventories.deleted_at');
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
