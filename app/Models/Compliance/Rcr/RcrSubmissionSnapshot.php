<?php

declare(strict_types=1);

namespace App\Models\Compliance\Rcr;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 9d — immutable RCR snapshot. Append-only: no UPDATED_AT, no soft
 * deletes. Once taken at submission, this row is sacred.
 */
final class RcrSubmissionSnapshot extends Model
{
    protected $table = 'rcr_submission_snapshots';

    /** Append-only — no updated_at. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'submission_id', 'snapshot_json', 'questionnaire_version_hash',
        'taken_at', 'taken_by_user_id',
    ];

    protected $casts = [
        'snapshot_json' => 'array',
        'taken_at'      => 'datetime',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(RcrSubmission::class, 'submission_id');
    }

    public function takenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by_user_id');
    }
}
