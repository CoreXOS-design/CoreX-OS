<?php

namespace App\Services\Rentals;

use App\Models\PropertyRoom;
use App\Models\RentalInspection;
use App\Models\RentalInspectionAuditLog;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInventory;
use App\Models\RentalInventoryPhoto;
use App\Models\User;
use App\Services\Audit\PropertyAuditService;
use App\Services\Images\PrivateImageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The bulk side of the photo screens (inventory + inspection): list a page of photos, select by ticking or by "all N", delete (soft), restore, and
 * MOVE between an inventory and an inspection or between rooms. One rule set for both screens.
 *
 * The move rule (investigated in .ai/specs/rental-inventory.md section 29): an inventory photo and an inspection photo name their file by the
 * same raw `storage_path` on the private disk, and the signed link is guarded by the photo ROW and its parent, not by the folder - so a move is a
 * new row on the other side holding the SAME stored file (no copy, no re-upload) and the source row soft-deleted. Both sides share one rooms
 * table (`property_rooms`), so a photo keeps its room. A photo shared FROM an inspection (a reference, no file of its own) is not moved: it is
 * already an inspection photo. Line tags (inventory) and item filing (inspection) do not exist on the other side, so a moved photo lands at room
 * level (or unassigned) and the agent files it to an item there.
 *
 * Everything here runs inside one transaction per action and never hard-deletes.
 */
class RentalPhotoBulkService
{
    public const PER_PAGE = 48;
    /** The most photos one action handles - a limit on the transaction, not on the screen (the agent presses again for the rest). */
    public const MAX_PER_ACTION = 2000;

    // ------------------------------------------------------------------------------------------------ queries

    /** @param array{room?:?string,from?:?string,to?:?string,deleted?:?bool,sort?:?string} $f */
    public function inventoryQuery(RentalInventory $inventory, array $f): Builder
    {
        $q = RentalInventoryPhoto::query()->where('rental_inventory_photos.rental_inventory_id', $inventory->id);
        if (! empty($f['deleted'])) {
            $q->onlyTrashed();
        } else {
            $q->usable();
        }

        return $this->filters($q, 'rental_inventory_photos', $f);
    }

    /** @param array{room?:?string,from?:?string,to?:?string,deleted?:?bool,sort?:?string} $f */
    public function inspectionQuery(RentalInspection $inspection, array $f): Builder
    {
        $q = RentalInspectionPhoto::query()->where('rental_inspection_photos.rental_inspection_id', $inspection->id);
        if (! empty($f['deleted'])) {
            $q->onlyTrashed();
        }

        return $this->filters($q, 'rental_inspection_photos', $f);
    }

    private function filters(Builder $q, string $table, array $f): Builder
    {
        $room = $f['room'] ?? null;
        if ($room === 'none') {
            $q->whereNull($table . '.property_room_id');
        } elseif ($room !== null && $room !== '' && ctype_digit((string) $room)) {
            $q->where($table . '.property_room_id', (int) $room);
        }
        if (! empty($f['from'])) {
            $q->where($table . '.created_at', '>=', \Carbon\Carbon::parse($f['from'])->startOfDay());
        }
        if (! empty($f['to'])) {
            $q->where($table . '.created_at', '<=', \Carbon\Carbon::parse($f['to'])->endOfDay());
        }

        return $q->orderBy($table . '.created_at', ($f['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc')->orderBy($table . '.id', ($f['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc');
    }

    /** @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int} */
    public function page(Builder $q, int $page, string $kind): array
    {
        $total = (clone $q)->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pages));
        $rows = $q->with($kind === 'inventory' ? ['room:id,label', 'lines', 'uploadedBy:id,name', 'sourceInspectionPhoto'] : ['room:id,label', 'uploadedBy:id,name'])
            ->forPage($page, self::PER_PAGE)->get();

        return ['items' => $rows->map(fn ($p) => $this->payload($p, $kind))->all(), 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => self::PER_PAGE];
    }

    /** @return array<string,mixed> */
    public function payload($photo, string $kind): array
    {
        $images = app(PrivateImageService::class);
        $isRef = $kind === 'inventory' && $photo->isReference();
        $full = $photo->storage_path;
        $raw = $isRef ? null : $photo->rawStoragePath();
        $thumb = $full;
        if ($raw !== null && $images->isPrivate($raw)) {
            $guards = $kind === 'inventory'
                ? [['rental_inventory_photos', (int) $photo->id], ['rental_inventories', (int) $photo->rental_inventory_id]]
                : [['rental_inspection_photos', (int) $photo->id], ['rental_inspections', (int) $photo->rental_inspection_id]];
            // a deleted photo is shown in the "Show deleted" list so it can be restored: its link is guarded by the parent record only (the photo row's
            // own guard would answer 404 for an archived row). Staff-only, signed, short-lived, and the list itself is gated by the screen's permissions.
            if ($photo->trashed()) {
                $guards = array_slice($guards, 1);
            }
            $thumb = $images->signedUrl($raw, $photo->agency_id, $guards, null, PrivateImageService::VARIANT_THUMB);
            $full = $photo->trashed() ? $images->signedUrl($raw, $photo->agency_id, $guards) : $full;
        }

        return [
            'id' => (int) $photo->id,
            'thumb' => $thumb,
            'full' => $full,
            'room_id' => $photo->property_room_id !== null ? (int) $photo->property_room_id : null,
            'room' => $photo->room?->label,
            'when' => $photo->created_at?->format('d M H:i'),
            'by' => $photo->uploadedBy?->name,
            'shared' => $isRef,                                   // an inventory photo that IS an inspection photo (cannot be moved)
            'tags' => $kind === 'inventory' ? $photo->lines->count() : ($photo->rental_inspection_observation_id ? 1 : 0),
            'deleted' => $photo->trashed(),
        ];
    }

    /**
     * The ids an action works on: the ticked ones, or (scope "filtered") every photo the current filter matches. Always inside the query given,
     * so a photo of another inventory/inspection - or another agency - can never be named.
     *
     * @return array{ids:list<int>,total:int}
     */
    public function resolve(Builder $q, string $scope, array $ticked): array
    {
        $table = $q->getModel()->getTable();
        $total = (clone $q)->count();
        if ($scope === 'filtered') {
            return ['ids' => (clone $q)->limit(self::MAX_PER_ACTION)->pluck($table . '.id')->map(fn ($i) => (int) $i)->all(), 'total' => $total];
        }
        $ids = array_values(array_unique(array_map('intval', $ticked)));

        return ['ids' => (clone $q)->whereIn($table . '.id', $ids)->pluck($table . '.id')->map(fn ($i) => (int) $i)->all(), 'total' => $total];
    }

    // ------------------------------------------------------------------------------------------------ actions

    /** @param list<int> $ids */
    public function deleteInventory(RentalInventory $inventory, array $ids, User $by): int
    {
        return DB::transaction(function () use ($inventory, $ids, $by) {
            $n = 0;
            foreach (RentalInventoryPhoto::whereIn('id', $ids)->where('rental_inventory_id', $inventory->id)->get() as $p) {
                $p->archive($by);
                $n++;
            }
            $this->auditInventory($inventory, 'inventory_photos_deleted', $n, $ids, $by, "Deleted {$n} inventory photo(s)");

            return $n;
        });
    }

    /** @param list<int> $ids */
    public function restoreInventory(RentalInventory $inventory, array $ids, User $by): int
    {
        return DB::transaction(function () use ($inventory, $ids, $by) {
            $n = 0;
            foreach (RentalInventoryPhoto::onlyTrashed()->whereIn('id', $ids)->where('rental_inventory_id', $inventory->id)->get() as $p) {
                $p->restore();
                $p->forceFill(['archived_by_user_id' => null])->save();
                $n++;
            }
            $this->auditInventory($inventory, 'inventory_photos_restored', $n, $ids, $by, "Restored {$n} inventory photo(s)");

            return $n;
        });
    }

    /** @param list<int> $ids */
    public function deleteInspection(RentalInspection $inspection, array $ids, User $by): int
    {
        return DB::transaction(function () use ($inspection, $ids, $by) {
            $n = 0;
            foreach (RentalInspectionPhoto::whereIn('id', $ids)->where('rental_inspection_id', $inspection->id)->get() as $p) {
                $p->archive($by);
                $n++;
            }
            RentalInspectionAuditLog::record($inspection, RentalInspectionAuditLog::EVENT_PHOTOS_ARCHIVED, "Deleted {$n} photo(s) (bulk)", null, ['photo_ids' => $ids], $by);

            return $n;
        });
    }

    /** @param list<int> $ids */
    public function restoreInspection(RentalInspection $inspection, array $ids, User $by): int
    {
        return DB::transaction(function () use ($inspection, $ids, $by) {
            $n = 0;
            foreach (RentalInspectionPhoto::onlyTrashed()->whereIn('id', $ids)->where('rental_inspection_id', $inspection->id)->get() as $p) {
                $p->restore();
                $p->forceFill(['archived_by_user_id' => null])->save();
                $n++;
            }
            RentalInspectionAuditLog::record($inspection, RentalInspectionAuditLog::EVENT_PHOTOS_RESTORED, "Restored {$n} photo(s) (bulk)", null, ['photo_ids' => $ids], $by);

            return $n;
        });
    }

    /**
     * Inventory photos -> an inspection of the SAME property: the same stored file on a new inspection row, the inventory row soft-deleted.
     *
     * @param  list<int>  $ids
     * @param  string|int|null  $room  'keep' (each photo keeps its room), 'none' (the inspection's untagged tray) or a room id of the property
     * @return array{moved:int,skipped_shared:int}
     */
    public function moveInventoryToInspection(RentalInventory $inventory, RentalInspection $target, array $ids, string|int|null $room, User $by): array
    {
        $roomId = $this->roomChoice($target->property_id, $room);

        return DB::transaction(function () use ($inventory, $target, $ids, $roomId, $room, $by) {
            $moved = 0;
            $shared = 0;
            $created = [];
            foreach (RentalInventoryPhoto::whereIn('id', $ids)->where('rental_inventory_id', $inventory->id)->get() as $p) {
                if ($p->isReference() || $p->rawStoragePath() === null) {
                    $shared++;
                    continue;
                }
                $target_room = $room === 'keep' || $room === null ? $this->validRoom($target->property_id, $p->property_room_id) : $roomId;
                $new = RentalInspectionPhoto::create([
                    'agency_id' => $target->agency_id, 'rental_inspection_id' => $target->id, 'property_room_id' => $target_room,
                    'storage_path' => $p->rawStoragePath(), 'file_size_bytes' => $p->file_size_bytes, 'uploaded_by_user_id' => $p->uploaded_by_user_id,
                    'tagged_at' => $target_room ? now() : null, 'tagged_by_user_id' => $target_room ? $by->id : null,
                    'created_at' => $p->created_at, 'taken_at' => $p->created_at, 'taken_at_source' => RentalInspectionPhoto::TAKEN_AT_SERVER,
                ]);
                $created[] = $new->id;
                $p->archive($by);
                $moved++;
            }
            $this->auditInventory($inventory, 'inventory_photos_moved', $moved, $ids, $by, "Moved {$moved} inventory photo(s) to inspection #{$target->id}", ['to_inspection_id' => $target->id, 'new_inspection_photo_ids' => $created]);
            RentalInspectionAuditLog::record($target, RentalInspectionAuditLog::EVENT_PHOTOS_MOVED, "{$moved} photo(s) moved in from inventory #{$inventory->id}", null, ['from_inventory_id' => $inventory->id, 'inventory_photo_ids' => $ids, 'inspection_photo_ids' => $created], $by);

            return ['moved' => $moved, 'skipped_shared' => $shared];
        });
    }

    /**
     * Inspection photos -> a draft inventory of the SAME property: the same stored file on a new inventory row (move-in side), the inspection photo
     * archived. Item filing (the observation) does not exist on an inventory and is dropped; the room is kept unless one is chosen.
     *
     * @param  list<int>  $ids
     * @return array{moved:int}
     */
    public function moveInspectionToInventory(RentalInspection $inspection, RentalInventory $target, array $ids, string|int|null $room, User $by): array
    {
        $roomId = $this->roomChoice($target->property_id, $room);

        return DB::transaction(function () use ($inspection, $target, $ids, $roomId, $room, $by) {
            $moved = 0;
            $created = [];
            foreach (RentalInspectionPhoto::whereIn('id', $ids)->where('rental_inspection_id', $inspection->id)->get() as $p) {
                $targetRoom = $room === 'keep' || $room === null ? $this->validRoom($target->property_id, $p->property_room_id) : $roomId;
                $new = RentalInventoryPhoto::create([
                    'agency_id' => $target->agency_id, 'rental_inventory_id' => $target->id, 'property_room_id' => $targetRoom,
                    'side' => RentalInventoryPhoto::SIDE_MOVE_IN, 'storage_path' => $p->rawStoragePath(),
                    'file_size_bytes' => $p->file_size_bytes, 'uploaded_by_user_id' => $p->uploaded_by_user_id,
                ]);
                $created[] = $new->id;
                $p->archive($by);
                $moved++;
            }
            RentalInspectionAuditLog::record($inspection, RentalInspectionAuditLog::EVENT_PHOTOS_MOVED, "{$moved} photo(s) moved out to inventory #{$target->id}", null, ['to_inventory_id' => $target->id, 'inspection_photo_ids' => $ids, 'inventory_photo_ids' => $created], $by);
            $this->auditInventory($target, 'inventory_photos_moved_in', $moved, $created, $by, "{$moved} photo(s) moved in from inspection #{$inspection->id}", ['from_inspection_id' => $inspection->id]);

            return ['moved' => $moved];
        });
    }

    /**
     * Inspection photos -> another room (or unassigned) of the same inspection. Item filing is cleared - the photo now sits at room level.
     *
     * @param  list<int>  $ids
     */
    public function moveInspectionToRoom(RentalInspection $inspection, array $ids, string|int|null $room, User $by): int
    {
        $roomId = $this->roomChoice($inspection->property_id, $room);

        return DB::transaction(function () use ($inspection, $ids, $roomId, $by) {
            $n = 0;
            foreach (RentalInspectionPhoto::whereIn('id', $ids)->where('rental_inspection_id', $inspection->id)->get() as $p) {
                $p->tagTo($roomId, null, $by);
                $n++;
            }
            RentalInspectionAuditLog::record($inspection, RentalInspectionAuditLog::EVENT_PHOTOS_MOVED, "{$n} photo(s) moved to " . ($roomId ? 'room #' . $roomId : 'unassigned'), null, ['photo_ids' => $ids, 'room_id' => $roomId], $by);

            return $n;
        });
    }

    // ------------------------------------------------------------------------------------------------ targets (what the move picker offers)

    /** The property's inspections, newest first, with whether each takes photos now. @return list<array<string,mixed>> */
    public function inspectionTargets(int $propertyId, User $viewer): array
    {
        return RentalInspection::query()->visibleTo($viewer)->where('property_id', $propertyId)
            ->where('status', '!=', RentalInspection::STATUS_CANCELLED)->orderByDesc('id')->limit(30)->get()
            ->map(function (RentalInspection $i) {
                $open = true;
                try {
                    $i->assertContentEditable();
                } catch (\Throwable) {
                    $open = false;
                }

                return ['id' => $i->id, 'label' => RentalInspection::typeName($i->type) . ' #' . $i->id . ' - ' . str_replace('_', ' ', $i->status) . ' - ' . ($i->created_at?->format('d M Y') ?? ''), 'open' => $open];
            })->all();
    }

    /** The property's inventories that can take photos (drafts), newest first. @return list<array<string,mixed>> */
    public function inventoryTargets(int $propertyId, User $viewer): array
    {
        return RentalInventory::query()->visibleTo($viewer)->where('property_id', $propertyId)->orderByDesc('id')->limit(30)->get()
            ->map(fn (RentalInventory $v) => ['id' => $v->id, 'label' => 'Inventory #' . $v->id . ' - ' . str_replace('_', ' ', $v->status) . ' - ' . ($v->created_at?->format('d M Y') ?? ''), 'open' => $v->isDraft()])->all();
    }

    /** @return list<array{id:int,label:string}> */
    public function rooms(int $propertyId): array
    {
        return PropertyRoom::where('property_id', $propertyId)->where('is_retired', false)->orderBy('sort_order')->orderBy('id')->get(['id', 'label'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'label' => (string) $r->label])->all();
    }

    // ------------------------------------------------------------------------------------------------ internals

    /** 'keep'/null -> null here (the caller keeps each photo's own room); 'none' -> null (unassigned); a room id must belong to the property. */
    private function roomChoice(int $propertyId, string|int|null $room): ?int
    {
        if ($room === null || $room === 'keep' || $room === 'none' || $room === '') {
            return null;
        }
        abort_unless(PropertyRoom::where('id', (int) $room)->where('property_id', $propertyId)->exists(), 422, 'That room does not belong to this property.');

        return (int) $room;
    }

    private function validRoom(int $propertyId, ?int $roomId): ?int
    {
        return $roomId !== null && PropertyRoom::where('id', $roomId)->where('property_id', $propertyId)->exists() ? $roomId : null;
    }

    /** One audit line per bulk action, on the property's history, with the ids. */
    private function auditInventory(RentalInventory $inventory, string $type, int $n, array $ids, User $by, string $summary, array $extra = []): void
    {
        $property = $inventory->property;
        if ($property === null) {
            return;
        }
        app(PropertyAuditService::class)->log($property, 'property', $type, $by, null, null, ['inventory_id' => $inventory->id, 'count' => $n, 'photo_ids' => array_values($ids)] + $extra, $summary);
    }
}
