<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function isUntagged(): bool
    {
        return $this->property_room_id === null && $this->rental_inspection_observation_id === null;
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

    /** Soft-delete — archived, never hard-deleted (non-negotiable #1). */
    public function archive(User $by): void
    {
        $this->forceFill(['archived_by_user_id' => $by->id])->save();
        $this->delete();
    }
}
