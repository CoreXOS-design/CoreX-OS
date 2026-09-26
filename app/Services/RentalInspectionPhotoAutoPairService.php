<?php

namespace App\Services;

use App\Models\RentalInspection;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionPhotoMatchGroup;
use App\Models\RentalInspectionPhotoMatchGroupMember;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * .ai/specs/rental-inspections.md §24.5 — AT-433 Part B. Proposes a match
 * ONLY where it is unambiguous: exactly one tagged, never-touched photo per
 * (room, item) key on EACH side of a predecessor/tail pair. Johan's own
 * ruling: any key with more than one candidate on either side proposes
 * nothing for that key — "proposes nothing rather than guessing wrong." A
 * key present on only one side (nothing to compare yet) also proposes
 * nothing.
 *
 * "Never touched" (checked via withTrashed() in everTouched() below) means
 * this photo has never belonged to ANY match, active or removed — not just
 * "has no active group right now." This is what makes the rule safe to run
 * automatically on every view (§24.5's "runs on first view of an item's
 * comparison"): a photo an agent explicitly unmatched stays untouched by
 * later auto-pair runs, because it is no longer "never touched" — the
 * agent's own decision is respected, not overwritten the next time the
 * screen loads. Re-running after some pairs already exist (the explicit
 * "Auto-pair" button, kept per Johan's approved mockup to re-run after new
 * photos are added) only ever considers photos still in that
 * never-touched state, so it is idempotent by construction.
 *
 * Calls the exact same RentalInspectionPhotoMatchGroup::linkPhotos() the
 * click-based "Match" control and the (not-yet-built) drag gesture both
 * call — one linking primitive, three ways to trigger it, never a second
 * write path.
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

            if (! $predecessorPhotos || $predecessorPhotos->count() !== 1 || $tailPhotos->count() !== 1) {
                continue; // zero or ambiguous (>1) on either side — propose nothing for this key
            }

            $created->push(RentalInspectionPhotoMatchGroup::linkPhotos($tailPhotos->first(), $predecessorPhotos->first(), $by));
        }

        return $created->unique('id')->values();
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
            ->get()
            ->reject(fn (RentalInspectionPhoto $photo) => $this->everTouched($photo))
            ->groupBy(fn (RentalInspectionPhoto $photo) => $this->keyFor($photo));
    }

    private function keyFor(RentalInspectionPhoto $photo): string
    {
        $itemId = $photo->observation?->rental_inspection_item_id;

        return $photo->property_room_id . ':' . ($itemId ?? 'none');
    }

    /** Has this photo EVER belonged to a match, active or removed — not just "has no active group right now." */
    private function everTouched(RentalInspectionPhoto $photo): bool
    {
        return RentalInspectionPhotoMatchGroupMember::withTrashed()
            ->where('rental_inspection_photo_id', $photo->id)
            ->exists();
    }
}
