<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-inspections.md §3.2/§3.3/§20.13 — a photo belongs to the
 * inspection always, and OPTIONALLY to a room and/or a specific item within
 * it (2026-09-22 rebuild, items 2/3): both null = sits untagged in the
 * inspection's own tray; room set, item null = a general room shot; both
 * set = filed against one item's observation history. Tagging SUPERSEDES
 * (an update to these two columns), never an append — re-tagging a photo is
 * simply changing where it currently sits, and untagging (clearing both) is
 * the exact same operation in reverse. This is a deliberately different
 * discipline from `rental_inspection_observations` (immutable, evidentiary,
 * never touched after creation) — a photo's FILING is not itself evidence,
 * only the photo is.
 *
 * Soft-deletable (`archive()` below) — amended from the original "no
 * deleted_at at all" design call, which was reasoned for OBSERVATIONS
 * specifically; Johan's explicit ruling for this build is that a
 * mistakenly-uploaded photo (duplicate, wrong property, blurry) must be
 * removable, archived, never hard-deleted.
 */
class RentalInspectionPhoto extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const UPDATED_AT = null;

    protected $fillable = [
        'agency_id',
        'rental_inspection_id',
        'rental_inspection_observation_id',
        'property_room_id',
        'storage_path',
        'uploaded_by_user_id',
        'tagged_at',
        'tagged_by_user_id',
        'archived_by_user_id',
        'client_idempotency_key',
        'file_size_bytes',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'tagged_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $photo) {
            if (empty($photo->client_idempotency_key)) {
                $photo->client_idempotency_key = (string) \Illuminate\Support\Str::uuid();
            }
            if (empty($photo->created_at)) {
                $photo->created_at = now();
            }
        });
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(RentalInspection::class, 'rental_inspection_id');
    }

    public function observation(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionObservation::class, 'rental_inspection_observation_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(PropertyRoom::class, 'property_room_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function taggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tagged_by_user_id');
    }

    /**
     * .ai/specs/rental-inspections.md §25 — the photo-level note (AT-433
     * Part C), distinct from the observation's own item comment. At most
     * one live row per photo, enforced in
     * RentalInspectionPhotoNoteController::store(), not by a DB constraint
     * (see RentalInspectionPhotoNote's own docblock for why).
     */
    public function note(): HasOne
    {
        return $this->hasOne(RentalInspectionPhotoNote::class, 'rental_inspection_photo_id');
    }

    public function isUntagged(): bool
    {
        return $this->property_room_id === null && $this->rental_inspection_observation_id === null;
    }

    /**
     * §41, 2026-09-29 — the (room, item) identity two photos must share to
     * be linked, manually or automatically: property_room_id plus the item
     * this photo's own observation points at, if any. Same resolution
     * RentalInspectionPhotoAutoPairService::keyFor() already used privately
     * to decide which candidates are even OFFERED for auto-pair — shared
     * here so RentalInspectionPhotoMatchGroup::linkPhotos() enforces the
     * identical "same item, never a different one" rule against a MANUAL
     * link too, instead of trusting the click/drag source to have picked
     * correctly.
     */
    public function matchKey(): string
    {
        return $this->property_room_id . ':' . ($this->observation?->rental_inspection_item_id ?? 'none');
    }

    /**
     * Tag this photo to a room and, optionally, a specific item's
     * observation within that room. Supersedes whatever it was tagged to
     * before — a plain update, not a new row — so untagging is simply
     * calling this again with nulls (see untag() below), the same
     * operation in reverse.
     */
    public function tagTo(?int $propertyRoomId, ?int $observationId, User $by): void
    {
        $this->forceFill([
            'property_room_id' => $propertyRoomId,
            'rental_inspection_observation_id' => $observationId,
            'tagged_at' => now(),
            'tagged_by_user_id' => $by->id,
        ])->save();
    }

    /** Back to the untagged tray — the exact reverse of tagTo(). */
    public function untag(User $by): void
    {
        $this->tagTo(null, null, $by);
    }

    /**
     * Soft-delete — archived, never hard-deleted (non-negotiable #1).
     *
     * §24 (AT-433 Part B) — if this photo is currently an active member of
     * a match group, that membership is removed too (mirroring an explicit
     * unmatch), auto-archiving the group if that leaves it with one or zero
     * active members. Without this, archiving a paired photo left a
     * dangling active membership pointing at a trashed photo: the group's
     * own member count never dropped, so a two-member group's surviving
     * photo kept reading as "matched" against a photo that no longer
     * renders anywhere.
     *
     * AT-433 Part C: the photo's own live note (if any) is archived in the
     * same call. Found by testing, not assumed — a parent model's own
     * SoftDeletes global scope does NOT cascade to a child accessed FROM
     * the parent (`$photo->note`); it only protects the reverse direction
     * (`$note->photo` going null once the photo is trashed). Left
     * un-cascaded, a raw notes query that forgets to re-check its photo's
     * own trashed state would still surface a note whose evidence no
     * longer exists. No symmetric auto-restore on the reverse of this
     * method: no route currently restores an archived photo at all (an
     * existing, pre-existing gap in this module — flagged separately, not
     * fixed here per SCOPE LOCK), so there is nothing yet to keep in sync
     * on that side.
     */
    public function archive(User $by): void
    {
        $this->forceFill(['archived_by_user_id' => $by->id])->save();
        $this->delete();

        RentalInspectionPhotoMatchGroupMember::where('rental_inspection_photo_id', $this->id)
            ->first()
            ?->removeAndMaybeArchiveGroup($by);

        $liveNote = $this->note;
        if ($liveNote) {
            $liveNote->forceFill(['archived_by_user_id' => $by->id])->save();
            $liveNote->delete();
        }
    }
}
