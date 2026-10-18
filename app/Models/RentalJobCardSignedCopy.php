<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-work-orders.md §14.27.5 / §14.28 — the wet-ink signed
 * copy of a job card, uploaded by the office. Private disk. A newer upload
 * keeps the earlier one and stamps it Superseded; nothing is hard-deleted.
 */
class RentalJobCardSignedCopy extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    /** pdf / jpg / png only, §14.27.5. */
    public const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
    public const MAX_SIZE_KB = 10240;

    protected $fillable = [
        'agency_id',
        'rental_job_card_id',
        'storage_path',
        'original_name',
        'mime_type',
        'size_kb',
        'signed_by_name',
        'uploaded_by_user_id',
        'uploaded_at',
        'superseded_at',
        'superseded_by_id',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'superseded_at' => 'datetime',
        'size_kb' => 'integer',
    ];

    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(RentalJobCard::class, 'rental_job_card_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function isCurrent(): bool
    {
        return $this->superseded_at === null;
    }
}
