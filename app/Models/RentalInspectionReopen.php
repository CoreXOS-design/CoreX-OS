<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-inspections.md §47 — one "Edit report" on a signed inspection. Append-only: an update or delete of a
 * row throws (the voided signatures' only explanation lives here).
 */
class RentalInspectionReopen extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id', 'rental_inspection_id', 'reopened_by_user_id', 'reopened_at', 'reason', 'previous_status',
        'report_fingerprint', 'report_snapshot', 'voided_signatures', 'revoked_link_ids',
    ];

    protected $casts = [
        'reopened_at' => 'datetime',
        'report_snapshot' => 'array',
        'voided_signatures' => 'array',
        'revoked_link_ids' => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::updating(function () {
            throw new \LogicException('A reopen record is history — it is never edited.');
        });
        static::deleting(function () {
            throw new \LogicException('A reopen record is history — it is never deleted.');
        });
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(RentalInspection::class, 'rental_inspection_id')->withTrashed();
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by_user_id');
    }
}
