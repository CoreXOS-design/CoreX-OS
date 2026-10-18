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
 * Which photos are still up for pairing is decided PER PAIR of inspections
 * (§56a, 2026-10-14, property 21222 — a chain of three: In, Out, then a
 * next inspection). A match group is a SET of photos across any number of
 * inspections, so a predecessor photo that already sits in a group with an
 * EARLIER inspection's photo is exactly the one the new tail must join —
 * the old "any membership at all = touched" test shut every such photo out
 * and the new tail's photos only ever found the leftover, so the strip read
 * [NO MATCH][NO MATCH][NO MATCH][photo].
 *   - A TAIL photo is up for pairing only if it has never belonged to ANY
 *     match, active or removed (withTrashed() in everTouched()).
 *   - A PREDECESSOR photo is up for pairing unless it has ever shared a
 *     group — active or removed — with a photo of THIS tail
 *     (everPairedWithTail()). Pairs with other inspections do not count.
 * Both tests look at removed rows too, so a photo an agent explicitly
 * unmatched is not re-paired the next time the screen loads — the agent's
 * decision is respected. Re-running after some pairs already exist (the
 * explicit "Auto-pair" button) only considers photos still in that state,
 * so it is idempotent by construction.
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
        $tailGroupIds = $this->groupIdsEverTouchingInspection($tail);
        $predecessorCandidates = $this->candidatesByKey($predecessor, fn (RentalInspectionPhoto $photo) => $this->everPairedWithTail($photo, $tailGroupIds));
        $tailCandidates = $this->candidatesByKey($tail, fn (RentalInspectionPhoto $photo) => $this->everTouched($photo));

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

        // "Paired" = sits in an ACTIVE group that holds a photo of each of the two inspections.
        // A predecessor photo whose group only holds an earlier inspection's photo is still unmatched here.
        $groupsOf = fn (RentalInspection $inspection) => RentalInspectionPhotoMatchGroupMember::whereHas('group')
            ->whereIn('rental_inspection_photo_id', RentalInspectionPhoto::where('rental_inspection_id', $inspection->id)->select('id'))
            ->pluck('rental_inspection_photo_match_group_id');
        $groupsOnBothSides = $groupsOf($predecessor)->intersect($groupsOf($tail))->unique()->values();

        $paired = $groupsOnBothSides->isEmpty() ? 0 : RentalInspectionPhotoMatchGroupMember::whereHas('group')
            ->whereIn('rental_inspection_photo_id', $candidateIds)
            ->whereIn('rental_inspection_photo_match_group_id', $groupsOnBothSides)
            ->pluck('rental_inspection_photo_id')
            ->unique()
            ->count();

        return count($candidateIds) - $paired;
    }

    /**
     * Every TAGGED photo on this inspection that is still up for pairing
     * ($alreadyHandled says which are not — see the class docblock), grouped by its
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
    private function candidatesByKey(RentalInspection $inspection, \Closure $alreadyHandled): Collection
    {
        return RentalInspectionPhoto::where('rental_inspection_id', $inspection->id)
            ->where(fn ($q) => $q->whereNotNull('property_room_id')->orWhereNotNull('rental_inspection_observation_id'))
            ->with('observation')
            ->orderBy('id')
            ->get()
            ->reject($alreadyHandled)
            ->groupBy(fn (RentalInspectionPhoto $photo) => $photo->matchKey());
    }

    /** Has this photo EVER belonged to a match, active or removed — not just "has no active group right now." */
    private function everTouched(RentalInspectionPhoto $photo): bool
    {
        return RentalInspectionPhotoMatchGroupMember::withTrashed()
            ->where('rental_inspection_photo_id', $photo->id)
            ->exists();
    }

    /** Ids of every group, active or removed, that ever held a photo of this inspection. */
    private function groupIdsEverTouchingInspection(RentalInspection $inspection): Collection
    {
        return RentalInspectionPhotoMatchGroupMember::withTrashed()
            ->whereIn('rental_inspection_photo_id', RentalInspectionPhoto::where('rental_inspection_id', $inspection->id)->select('id'))
            ->pluck('rental_inspection_photo_match_group_id')
            ->unique()
            ->values();
    }

    /**
     * Has this predecessor photo ever shared a group — active or removed —
     * with a photo of the tail? Membership alongside an EARLIER
     * inspection's photo does not count; that is a chain group the tail
     * is meant to join.
     */
    private function everPairedWithTail(RentalInspectionPhoto $photo, Collection $tailGroupIds): bool
    {
        if ($tailGroupIds->isEmpty()) {
            return false;
        }

        return RentalInspectionPhotoMatchGroupMember::withTrashed()
            ->where('rental_inspection_photo_id', $photo->id)
            ->whereIn('rental_inspection_photo_match_group_id', $tailGroupIds)
            ->exists();
    }
}
