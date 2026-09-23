<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SUPERSEDED, 2026-09-23 — .ai/specs/rental-inspections.md §20.16. The app
 * no longer reads or writes this table; RentalInspectionPhotoMatchGroup /
 * RentalInspectionPhotoMatchGroupMember replaced it (Johan: pairwise was
 * wrong for "2 photos on one inspection, 5 on another, all one wall" —
 * that needs a SET, not 10 separate pairs). Every row that was active at
 * the time carried over into the new tables via the
 * 2026_10_03_100200_migrate_pairwise_photo_matches_into_groups migration
 * (connected-components over the old pairwise edges); this table and model
 * are kept, unused, purely as the historical record of what existed before
 * — never dropped, never written to again.
 *
 * .ai/specs/rental-inspections.md §20.15 — links a photo on one inspection
 * to a photo on another (In vs Out/ad-hoc), persisted so the pair stays
 * together in the compare view, the modal, and any report generated later.
 * A photo may carry more than one match. `photo_id_a`/`photo_id_b` are
 * always stored in canonical (lower-id-first) order by matchPhotos() below
 * — never assume "a" is the left/earlier side when reading a row back.
 *
 * Soft-deletable (unmatch), never hard-deleted — same discipline as
 * RentalInspectionPhoto::archive(). matched_by_user_id/matched_at and
 * unmatched_by_user_id mirror that model's tagged_by/archived_by pairing:
 * this is evidence in a deposit dispute and carries the same audit weight
 * as a recorded condition.
 */
class RentalInspectionPhotoMatch extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'property_id',
        'photo_id_a',
        'photo_id_b',
        'matched_by_user_id',
        'matched_at',
        'unmatched_by_user_id',
    ];

    protected $casts = [
        'matched_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function photoA(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionPhoto::class, 'photo_id_a');
    }

    public function photoB(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionPhoto::class, 'photo_id_b');
    }

    public function matchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by_user_id');
    }

    /**
     * Link two photos. Canonicalizes order (lower id first) so the same
     * pair is always found regardless of which side the caller names
     * first. Idempotent: re-matching an already-matched pair just returns
     * the existing row unchanged; re-matching a previously UNMATCHED pair
     * restores it rather than colliding with the pair's own unique index —
     * a soft-deleted row still occupies that unique slot (BUILD_STANDARD
     * §5a), so a naive create() would throw a duplicate-key error the
     * second time the same two photos are matched, unmatched, then
     * matched again.
     */
    public static function matchPhotos(RentalInspectionPhoto $a, RentalInspectionPhoto $b, User $by): self
    {
        if ($a->id === $b->id) {
            throw new \InvalidArgumentException('A photo cannot be matched to itself.');
        }

        [$idA, $idB] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];

        $match = self::withTrashed()->where('photo_id_a', $idA)->where('photo_id_b', $idB)->first();
        if ($match) {
            if ($match->trashed()) {
                $match->restore();
            }
            $match->forceFill([
                'matched_by_user_id' => $by->id,
                'matched_at' => now(),
                'unmatched_by_user_id' => null,
            ])->save();

            return $match;
        }

        return self::create([
            'agency_id' => $a->agency_id,
            'property_id' => $a->inspection?->property_id,
            'photo_id_a' => $idA,
            'photo_id_b' => $idB,
            'matched_by_user_id' => $by->id,
            'matched_at' => now(),
        ]);
    }

    /** Unmatch — the exact reverse, a soft delete, never a hard one. */
    public function unmatch(User $by): void
    {
        $this->forceFill(['unmatched_by_user_id' => $by->id])->save();
        $this->delete();
    }
}
