<?php

declare(strict_types=1);

namespace App\Models\Compliance\Rcr;

use App\Models\Agency;
use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase 9d — one RCR cycle per agency per questionnaire-period.
 */
final class RcrSubmission extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const STATUS_DRAFT                  = 'draft';
    public const STATUS_IN_REVIEW              = 'in_review';
    public const STATUS_APPROVED_FOR_SUBMISSION = 'approved_for_submission';
    public const STATUS_SUBMITTED              = 'submitted';
    public const STATUS_LOCKED                 = 'locked';

    public const EDITABLE_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_IN_REVIEW,
        self::STATUS_APPROVED_FOR_SUBMISSION,
    ];

    protected $fillable = [
        'agency_id', 'questionnaire_id', 'status',
        'reporting_period_from', 'reporting_period_to', 'submission_deadline',
        'submitted_at', 'submitted_by_user_id', 'submitted_to_platform_reference',
        'locked_at', 'export_document_path', 'notes', 'assigned_co_user_id',
    ];

    protected $casts = [
        'reporting_period_from' => 'date',
        'reporting_period_to'   => 'date',
        'submission_deadline'   => 'date',
        'submitted_at'          => 'datetime',
        'locked_at'             => 'datetime',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(RcrQuestionnaire::class, 'questionnaire_id');
    }

    public function assignedCo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_co_user_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(RcrAnswer::class, 'submission_id');
    }

    public function snapshot(): HasOne
    {
        return $this->hasOne(RcrSubmissionSnapshot::class, 'submission_id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true);
    }

    public function isSubmitted(): bool
    {
        return in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_LOCKED], true);
    }

    public function daysToDeadline(): int
    {
        return (int) round(now()->diffInDays($this->submission_deadline, false));
    }
}
