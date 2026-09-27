<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-inspections.md §25 — AT-433 Part C. The PHOTO-level
 * note: what THIS specific photo shows, distinct from
 * `RentalInspectionObservation::notes` (the item comment — what the ITEM
 * is like overall). One scuff photo out of four is the evidence; this note
 * is what makes it evidence.
 *
 * Full CRUD, soft delete only (Johan's explicit ruling for this build,
 * overriding the module's usual immutable/append-only convention for
 * observations/room notes/findings) — edit-in-place via update(), archived
 * via SoftDeletes, restorable, never a supersede chain.
 *
 * At most one LIVE note per photo — enforced here and in the controller,
 * deliberately NOT as a DB unique constraint (BUILD_STANDARD §5a: a unique
 * index has no soft-delete awareness and would collide on the very
 * archive-then-recreate flow this feature exists to support).
 *
 * Read-only once the inspection is signed (Johan's ruling): a report
 * someone signed must not change underneath them. Confirmed against the
 * actual code, not assumed — RentalInspection::markCompleted() is the ONE
 * transition that both sets status=completed AND is gated on every
 * required signature already existing (§15), so "signed" and "completed"
 * are the same event in this data model; there is no in-between state
 * where signing is done but the inspection isn't yet completed. This gate
 * mirrors RentalInspection::updateDetails()'s own precedent exactly
 * (locks on COMPLETED or CANCELLED, never just COMPLETED alone).
 *
 * When its photo is removed: RentalInspectionPhoto::archive() cascades —
 * archives this row in the same call. Found by testing: a parent's own
 * SoftDeletes global scope does not cascade to a child read FROM the
 * parent (`$photo->note`), only to the reverse (`$note->photo` going null
 * once the photo is trashed) — an explicit cascade was needed, not "free"
 * protection from the scope alone.
 */
class RentalInspectionPhotoNote extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'rental_inspection_id',
        'rental_inspection_photo_id',
        'classification_key',
        'note',
        'created_by_user_id',
        'updated_by_user_id',
        'archived_by_user_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function photo(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionPhoto::class, 'rental_inspection_photo_id');
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(RentalInspection::class, 'rental_inspection_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_user_id');
    }

    /**
     * Johan's ruling: once an inspection is signed (== completed, see class
     * docblock) its photo notes are read-only. Cancelled is locked too,
     * matching RentalInspection::updateDetails()'s own precedent — a
     * cancelled inspection's record is not a form left open for revision
     * either.
     *
     * @throws \LogicException
     */
    public static function assertMutable(RentalInspection $inspection): void
    {
        if (in_array($inspection->status, [RentalInspection::STATUS_COMPLETED, RentalInspection::STATUS_CANCELLED], true)) {
            throw new \LogicException('This inspection is completed and signed — its photo notes are read-only.');
        }
    }

    /** Does this photo currently have a live (non-archived) note? */
    public static function liveFor(RentalInspectionPhoto $photo): ?self
    {
        return static::where('rental_inspection_photo_id', $photo->id)->first();
    }
}
