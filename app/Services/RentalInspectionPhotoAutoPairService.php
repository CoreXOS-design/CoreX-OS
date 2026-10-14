<?php

namespace App\Services;

use App\Models\RentalInspection;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionPhotoMatchGroup;
use App\Models\RentalInspectionPhotoMatchGroupMember;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * .ai/specs/rental-inspections.md §24.5 (AT-433 Part B), RULING CHANGED
 * 2026-10-14 (Johan, property 21222) — see §24.5a. Pairs the photos of the
 * SAME inspection item across a predecessor/tail pair BY POSITION: the
 * 1st in-photo with the 1st out-photo, the 2nd with the 2nd, and so on
 * (upload order). Extras on whichever side has more are left unpaired and
 * keep showing "NO MATCH". The earlier rule ("exactly one photo per item
 * on EACH side, anything else proposes nothing") meant a real move-out —
 * 3 in-photos, 3 or 4 out-photos on Ceiling — never paired at all and the
 * Auto-pair button silently did nothing.
 *
 * The one exception that keeps the old cautious rule: photos tagged to a
 * ROOM but no item (key "<room>:none"). There is no item to say they are
 * the same thing, so they still pair only when there is exactly one each
 * side.
 *
 * "Never touched" (checked via withTrashed() in everTouched() below) means
 * this photo has never belonged to ANY match, active or removed — not just
 * "has no active group right now." This is what makes the rule safe to run
 * automatically on every view (§24.5's "runs on first view of an item's
 * comparison"): a photo an agent explicitly unmatched stays untouched by
 * later auto-pair runs, because it is no longer "never touched" — the
 * agent's own decision is respected, not overwritten the next time the
 * screen loads. Re-running after some pairs already exist (the explicit
 * "Auto-pair" button) only ever considers photos still in that
 * never-touched state, so it is idempotent by construction.
 *
 * Calls the exact same RentalInspectionPhotoMatchGroup::linkPhotos() the
 * click-based "Match" control and the drag gesture both call — one linking
 * primitive, three ways to trigger it, never a second write path.
 */
class RentalInspectionPhotoAutoPairService
{
    /**
     * @return Collection<int, RentalInspectionPhotoMatchGroup> every group created by this run
     */
    public function runFor(RentalInspection $predecessor, RentalInspection $tail, User $by): Collection
    {
        $predecessorCandidates = $this->candidatesByKey($predecessor);
        $tailCandidates = $this->candidatesByKey($tail);

        $created = collect();

        foreach ($tailCandidates as $key => $tailPhotos) {
            $predecessorPhotos = $predecessorCandidates->get($key);

            if (! $predecessorPhotos) {
                continue; // nothing on the other side to pair with
            }

            if (str_ends_with((string) $key, ':none')) {
                // Room-level photos (no item): no item identity, so only an unambiguous one-each-side pair.
                if ($predecessorPhotos->count() !== 1 || $tailPhotos->count() !== 1) {
                    continue;
                }
                $created->push(RentalInspectionPhotoMatchGroup::linkPhotos($tailPhotos->first(), $predecessorPhotos->first(), $by));

                continue;
            }

            // Same item: 1st with 1st, 2nd with 2nd ... — extras stay unpaired.
            $pairs = min($predecessorPhotos->count(), $tailPhotos->count());
            for ($i = 0; $i < $pairs; $i++) {
                $created->push(RentalInspectionPhotoMatchGroup::linkPhotos($tailPhotos->values()[$i], $predecessorPhotos->values()[$i], $by));
            }
        }

        return $created->unique('id')->values();
    }

    /**
     * How many photos are still without a twin, for the Auto-pair result
     * message: tagged photos on EITHER inspection, in a room/item that has
     * photos on both sides, that belong to no active match. A photo in an
     * item the other side has nothing for is not counted — there was never
     * anything for it to pair with.
     */
    public function unmatchedCount(RentalInspection $predecessor, RentalInspection $tail): int
    {
        $sides = [$predecessor, $tail];
        $byKey = [];
        foreach ($sides as $n => $inspection) {
            $photos = RentalInspectionPhoto::where('rental_inspection_id', $inspection->id)
                ->where(fn ($q) => $q->whereNotNull('property_room_id')->orWhereNotNull('rental_inspection_observation_id'))
                ->with('observation')
                ->get();
            foreach ($photos as $photo) {
                $byKey[$photo->matchKey()][$n][] = $photo->id;
            }
        }

        $candidateIds = [];
        foreach ($byKey as $perSide) {
            if (! empty($perSide[0]) && ! empty($perSide[1])) {
                array_push($candidateIds, ...$perSide[0], ...$perSide[1]);
            }
        }
        if (! $candidateIds) {
            return 0;
        }

        $paired = RentalInspectionPhotoMatchGroupMember::whereIn('rental_inspection_photo_id', $candidateIds)
            ->whereHas('group')
            ->pluck('rental_inspection_photo_id')
            ->unique()
            ->count();

        return count($candidateIds) - $paired;
    }

    /**
     * Every TAGGED, never-touched photo on this inspection, grouped by its
     * (room, item) key. Untagged photos (isUntagged()) carry no signal to
     * key off and are never candidates.
     *
     * Item identity is resolved via observation->rental_inspection_item_id,
     * not property_room_id/rental_inspection_observation_id directly —
     * rooms are property-scoped so property_room_id already matches across
     * inspections, but an observation is inspection-scoped; the ITEM it
     * points at is the property-wide id that is actually comparable between
     * the predecessor and the tail (RentalInspection.php's own "rooms/items
     * are property-wide" reasoning, §24.2).
     *
     * @return Collection<string, Collection<int, RentalInspectionPhoto>>
     */
    private function candidatesByKey(RentalInspection $inspection): Collection
    {
        return RentalInspectionPhoto::where('rental_inspection_id', $inspection->id)
            ->where(fn ($q) => $q->whereNotNull('property_room_id')->orWhereNotNull('rental_inspection_observation_id'))
            ->with('observation')
            ->orderBy('id')
            ->get()
            ->reject(fn (RentalInspectionPhoto $photo) => $this->everTouched($photo))
            ->groupBy(fn (RentalInspectionPhoto $photo) => $photo->matchKey());
    }

    /** Has this photo EVER belonged to a match, active or removed — not just "has no active group right now." */
    private function everTouched(RentalInspectionPhoto $photo): bool
    {
        return RentalInspectionPhotoMatchGroupMember::withTrashed()
            ->where('rental_inspection_photo_id', $photo->id)
            ->exists();
    }
}
