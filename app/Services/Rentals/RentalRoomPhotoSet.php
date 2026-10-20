<?php

namespace App\Services\Rentals;

use App\Models\RentalInspection;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInventory;
use App\Models\RentalInventoryLine;
use App\Models\RentalInventoryPhoto;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Audit 2026-10-19 B32 - ONE photo set per room, shared by the inventory and the inspection.
 *
 * An agent used to photograph a room twice: once for the inspection, once for the inventory. A room photo now lives once, in the inspection
 * store (private, signed, GPS stripped), and the inventory SHARES it: the inventory keeps a reference row ({@see RentalInventoryPhoto::isReference()})
 * that shows the inspection photo. Everything that reads inventory photos - the capture strip, the tagging to a line, the move-in / move-out
 * comparison, the public page, the report - therefore reads both kinds through the same `rental_inventory_photos` rows, and one address
 * (`storage_path`) that resolves to the inspection photo's own signed link.
 *
 * This class is the one rule for WHICH inspection photos an inventory may share, and the one shape a photo is described in for the screens.
 * The rule: the inspection belongs to the same property and (when the inventory belongs to a tenancy) the same lease; the inspection is not
 * cancelled or archived; the photo is tagged to a room, not archived, and was not added after the report was signed; and the side matches -
 * the move-in side shares from the In inspection, the move-out side from the Out inspection.
 */
class RentalRoomPhotoSet
{
    /** The inspection type each inventory side shares from. */
    private const SIDE_INSPECTION_TYPE = [
        RentalInventoryPhoto::SIDE_MOVE_IN => RentalInspection::TYPE_IN,
        RentalInventoryPhoto::SIDE_MOVE_OUT => RentalInspection::TYPE_OUT,
    ];

    /**
     * The inspection photos this inventory could share for a side, newest last within a room, with the ones it already shares left out.
     *
     * @return Collection<int, RentalInspectionPhoto>
     */
    public function candidates(RentalInventory $inventory, string $side = RentalInventoryPhoto::SIDE_MOVE_IN, ?int $roomId = null, ?User $viewer = null): Collection
    {
        $type = self::SIDE_INSPECTION_TYPE[$side] ?? null;
        if ($type === null) {
            return collect();
        }

        $shared = RentalInventoryPhoto::query()
            ->where('rental_inventory_id', $inventory->id)
            ->whereNotNull('rental_inspection_photo_id')
            ->pluck('rental_inspection_photo_id');

        return RentalInspectionPhoto::query()
            ->with(['inspection:id,type,status,lease_id,property_id,completed_at', 'room:id,label'])
            ->whereHas('inspection', function ($q) use ($inventory, $type, $viewer) {
                $q->where('property_id', $inventory->property_id)
                    ->where('type', $type)
                    ->where('status', '!=', RentalInspection::STATUS_CANCELLED);
                if ($inventory->lease_id !== null) {
                    $q->where('lease_id', $inventory->lease_id);
                }
                if ($viewer !== null) {
                    $q->visibleTo($viewer);
                }
            })
            ->whereNotNull('property_room_id')
            ->whereNull('added_after_signing_at')
            ->when($roomId !== null, fn ($q) => $q->where('property_room_id', $roomId))
            ->whereNotIn('id', $shared)
            ->orderBy('property_room_id')->orderBy('taken_at')->orderBy('id')
            ->get();
    }

    /**
     * Share one inspection photo with the inventory (optionally tagging it to a line at the same time). Sharing the same photo again returns
     * the same row - an archived reference is restored, never duplicated.
     */
    public function share(RentalInventory $inventory, RentalInspectionPhoto $photo, User $by, string $side = RentalInventoryPhoto::SIDE_MOVE_IN, ?RentalInventoryLine $line = null, ?User $viewer = null): RentalInventoryPhoto
    {
        $eligible = $this->candidates($inventory, $side, (int) $photo->property_room_id, $viewer)->contains('id', $photo->id);

        $existing = RentalInventoryPhoto::withTrashed()
            ->where('rental_inventory_id', $inventory->id)
            ->where('rental_inspection_photo_id', $photo->id)
            ->first();

        if (! $eligible && ($existing === null || $existing->trashed())) {
            throw new \InvalidArgumentException('That photo is not one this inventory can use.');
        }

        return DB::transaction(function () use ($inventory, $photo, $by, $side, $line, $existing) {
            $reference = $existing;
            if ($reference === null) {
                $reference = RentalInventoryPhoto::create([
                    'agency_id' => $inventory->agency_id,
                    'rental_inventory_id' => $inventory->id,
                    'property_room_id' => $photo->property_room_id,
                    'rental_inspection_photo_id' => $photo->id,
                    'side' => $side,
                    'storage_path' => null,
                    'uploaded_by_user_id' => $by->id,
                ]);
            } elseif ($reference->trashed()) {
                $reference->restore();
                $reference->forceFill(['archived_by_user_id' => null, 'uploaded_by_user_id' => $by->id, 'side' => $side])->save();
            }

            if ($line !== null) {
                $line->photos()->syncWithoutDetaching([$reference->id => ['agency_id' => $line->agency_id]]);
            }

            return $reference;
        });
    }

    /** The one description of an inventory photo that the capture screen, the comparison and the JSON responses all use. */
    public function payload(RentalInventoryPhoto $photo): array
    {
        $payload = [
            'id' => $photo->id,
            'property_room_id' => $photo->property_room_id !== null ? (int) $photo->property_room_id : null,
            'storage_path' => $photo->storage_path,
            'lines' => $photo->lines->pluck('id')->values(),
            'from_inspection' => $photo->isReference(),
        ];
        if ($photo->isReference() && $photo->sourceInspectionPhoto) {
            $payload['taken_caption_short'] = $photo->sourceInspectionPhoto->taken_caption_short;
        }

        return $payload;
    }

    /** A candidate, described for the picker. */
    public function candidatePayload(RentalInspectionPhoto $photo): array
    {
        return [
            'id' => $photo->id,
            'property_room_id' => (int) $photo->property_room_id,
            'room' => $photo->room?->label,
            'storage_path' => $photo->storage_path,
            'taken_caption_short' => $photo->taken_caption_short,
        ];
    }
}
