<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-renewals.md §8 — append-only renewal/outcome event log.
 * Never updated or deleted once recorded — a reversal writes a NEW row
 * (event_type = '..._reversed'), it never removes the original.
 */
class LeaseEvent extends Model
{
    public $timestamps = false;

    public const TYPE_MONTH_TO_MONTH_SET = 'month_to_month_set';
    public const TYPE_MONTH_TO_MONTH_REVERSED = 'month_to_month_reversed';
    public const TYPE_NOTICE_RECORDED = 'notice_recorded';
    public const TYPE_NOTICE_REVERSED = 'notice_reversed';
    public const TYPE_NOTICE_OUTCOME_CHANGED = 'notice_outcome_changed';
    public const TYPE_RENEWAL_DRAFT_CREATED = 'renewal_draft_created';
    public const TYPE_RENEWAL_ACTIVATED = 'renewal_activated';
    public const TYPE_RENEWAL_DRAFT_CANCELLED = 'renewal_draft_cancelled';

    protected $fillable = [
        'lease_id',
        'event_type',
        'description',
        'actor_user_id',
        'metadata',
        'occurred_at',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
