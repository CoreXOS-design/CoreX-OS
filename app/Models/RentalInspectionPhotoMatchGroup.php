<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-inspections.md §20.16 — a SET of photos, from any
 * number of inspections, that all depict the same thing. Replaces the
 * pairwise `RentalInspectionPhotoMatch` self-join (§20.15.1): Johan,
 * 2026-09-23 — "join the group, not the other photo... clicking any
 * member surfaces every other member, grouped by which inspection each
 * came from."
 *
 * A photo belongs to at most ONE active group at a time (Johan's own
 * instinct: "one group per photo keeps it comprehensible") — enforced
 * here in addMember(), not by a database constraint (see the member
 * migration's own doc comment for why a plain unique index can't express
 * that alongside soft deletes).
 *
 * Soft-deletable, never hard-deleted — same evidentiary discipline as
 * every other table in this feature. A group auto-archives itself once it
 * drops to one or zero active members (a "group" of one has nothing left
 * to compare).
 */
class RentalInspectionPhotoMatchGroup extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'property_id',
        'created_by_user_id',
        'archived_by_user_id',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(RentalInspectionPhotoMatchGroupMember::class, 'rental_inspection_photo_match_group_id');
    }

    public function photos(): HasManyThrough
    {
        return $this->hasManyThrough(
            RentalInspectionPhoto::class,
            RentalInspectionPhotoMatchGroupMember::class,
            'rental_inspection_photo_match_group_id',
            'id',
            'id',
            'rental_inspection_photo_id',
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** The photo's current active group, if any — the one-group-per-photo lookup every caller needs. */
    public static function forPhoto(RentalInspectionPhoto $photo): ?self
    {
        $membership = RentalInspectionPhotoMatchGroupMember::where('rental_inspection_photo_id', $photo->id)->first();

        return $membership?->group;
    }

    /**
     * Add a photo to a group, moving it out of any group it currently
     * belongs to first — the one-group-per-photo rule, enforced here so
     * every caller (the link action below, a future bulk-tag tool, etc.)
     * gets it for free rather than having to remember it.
     *
     * Idempotent: adding a photo already in THIS group is a no-op.
     * Restore-aware (BUILD_STANDARD §5a): re-adding a photo that was
     * previously removed from this exact group restores that row rather
     * than colliding with a stale soft-deleted one.
     */
    public function addMember(RentalInspectionPhoto $photo, User $by): RentalInspectionPhotoMatchGroupMember
    {
        $current = RentalInspectionPhotoMatchGroupMember::withTrashed()
            ->where('rental_inspection_photo_id', $photo->id)
            ->first();

        if ($current && (int) $current->rental_inspection_photo_match_group_id === $this->id) {
            if ($current->trashed()) {
                $current->restore();
                $current->forceFill(['added_by_user_id' => $by->id, 'added_at' => now(), 'removed_by_user_id' => null])->save();
            }

            return $current;
        }

        if ($current && ! $current->trashed()) {
            // Moving from a different group — remove there first (own
            // audit trail intact), which may auto-archive that old group.
            $oldGroup = $current->group;
            $current->removeAndMaybeArchiveGroup($by);
            unset($oldGroup);
        }

        return RentalInspectionPhotoMatchGroupMember::create([
            'agency_id' => $this->agency_id,
            'rental_inspection_photo_match_group_id' => $this->id,
            'rental_inspection_photo_id' => $photo->id,
            'added_by_user_id' => $by->id,
            'added_at' => now(),
        ]);
    }

    /**
     * Link two photos so they show up together — the actual "Match" action
     * from the viewer. $anchor is whichever photo is already anchored on
     * the other side of the comparison; $clicked is the one being brought
     * in. Deliberately decisive about the one case that's genuinely
     * ambiguous (both photos already belong to DIFFERENT existing groups):
     * $clicked always moves into $anchor's group — "whichever photo you're
     * introducing into the comparison joins the group already anchored on
     * the other side," not a silent merge of two pre-existing groups.
     */
    public static function linkPhotos(RentalInspectionPhoto $clicked, RentalInspectionPhoto $anchor, User $by): self
    {
        if ($clicked->id === $anchor->id) {
            throw new \InvalidArgumentException('A photo cannot be matched to itself.');
        }

        $group = self::forPhoto($anchor);
        if (! $group) {
            $group = self::create([
                'agency_id' => $anchor->agency_id,
                'property_id' => $anchor->inspection?->property_id,
                'created_by_user_id' => $by->id,
            ]);
            $group->addMember($anchor, $by);
        }

        $group->addMember($clicked, $by);

        return $group;
    }

    /** Archive — the exact reverse, a soft delete, never a hard one. */
    public function archive(User $by): void
    {
        $this->forceFill(['archived_by_user_id' => $by->id])->save();
        $this->delete();
    }

    /**
     * Shape shared by the store/apply response and RentalInspection::
     * tabPayloadFor()'s `photo_matches` — every active member with the
     * photo's own inspection id, so the viewer can group "who's on the
     * other side" by which inspection each member came from (Johan's own
     * framing: "grouped by which inspection each came from").
     */
    public function toComparePayload(): array
    {
        $this->loadMissing('members.photo');

        return [
            'id' => $this->id,
            'members' => $this->members->map(fn ($member) => [
                'member_id' => $member->id,
                'photo_id' => $member->rental_inspection_photo_id,
                'rental_inspection_id' => $member->photo?->rental_inspection_id,
                'storage_path' => $member->photo?->storage_path,
            ])->values(),
        ];
    }
}
