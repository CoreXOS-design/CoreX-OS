<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-inspections.md §45.7 item 3 — append-only record that a reminder milestone (lead | due | overdue) was
 * handled for a loaded date. The UNIQUE (planned_date_id, milestone) is what makes the daily command idempotent: a
 * second run, or a catch-up after a missed cron tick, can never write — or send — the same milestone twice.
 */
class RentalInspectionPlannedDateNotice extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    public const MILESTONE_LEAD = 'lead';
    public const MILESTONE_DUE = 'due';
    public const MILESTONE_OVERDUE = 'overdue';

    public const STATUS_SENT = 'sent';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'agency_id', 'planned_date_id', 'milestone', 'recipient_user_id', 'channel', 'status', 'detail', 'created_at',
    ];

    public function plannedDate(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionPlannedDate::class, 'planned_date_id')->withTrashed();
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
