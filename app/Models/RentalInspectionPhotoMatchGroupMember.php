<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-inspections.md §20.16 — one photo's membership in one
 * RentalInspectionPhotoMatchGroup. `added_by_user_id`/`added_at` and
 * `removed_by_user_id` mirror the old pairwise table's `matched_by_user_id`
 * /`matched_at`/`unmatched_by_user_id` pairing — this is deposit-dispute
 * evidence and carries the same audit weight as a recorded condition.
 *
 * Soft-deletable (removed from the group), never hard-deleted.
 */
class RentalInspectionPhotoMatchGroupMember extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'rental_inspection_photo_match_group_id',
        'rental_inspection_photo_id',
        'added_by_user_id',
        'added_at',
        'removed_by_user_id',
    ];

    protected $casts = [
        'added_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionPhotoMatchGroup::class, 'rental_inspection_photo_match_group_id');
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionPhoto::class, 'rental_inspection_photo_id');
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    /**
     * Unmatch — remove this photo from its group. If that leaves the group
     * with one or zero active members, archive the group too (nothing left
     * to compare); the member row itself always records who removed it,
     * regardless.
     */
    public function removeAndMaybeArchiveGroup(User $by): void
    {
        $group = $this->group;

        $this->forceFill(['removed_by_user_id' => $by->id])->save();
        $this->delete();

        if ($group && $group->members()->count() <= 1) {
            $group->archive($by);
        }
    }
}
