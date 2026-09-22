<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-inspection-form.md §13 — one uploaded scan (PDF or
 * image) of a physically wet-ink-marked printed form (cc5's §12 build).
 * The original file is retained and openable against the inspection
 * FOREVER regardless of whether the reader could decode it — Johan's own
 * words: "the scan on file is what backs up anything we could not read."
 * Soft-deletable (archived, never hard-deleted, non-negotiable #1) — "a
 * superseded scan is archived, never removed."
 */
class RentalInspectionScan extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const STATUS_PROCESSING = 'processing';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_VERSION_MISMATCH = 'version_mismatch';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'agency_id',
        'branch_id',
        'rental_inspection_id',
        'rental_inspection_form_id',
        'original_filename',
        'storage_path',
        'mime_type',
        'status',
        'failure_reason',
        'decoded_inspection_id',
        'decoded_form_version',
        'page_count',
        'uploaded_by_user_id',
        'applied_by_user_id',
        'applied_at',
        'archived_by_user_id',
    ];

    protected $casts = [
        'decoded_inspection_id' => 'integer',
        'decoded_form_version' => 'integer',
        'page_count' => 'integer',
        'applied_at' => 'datetime',
    ];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(RentalInspection::class, 'rental_inspection_id');
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionForm::class, 'rental_inspection_form_id');
    }

    public function marks(): HasMany
    {
        return $this->hasMany(RentalInspectionScanMark::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_user_id');
    }

    /** Archived, never hard-deleted — the retained original is exactly what a bad read falls back to. */
    public function archive(User $by): void
    {
        $this->forceFill(['archived_by_user_id' => $by->id])->save();
        $this->delete();
    }
}
