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

    // LEASE-AGREEMENT BEGIN (leases.md §15.15 — Build L1). All <= 40 characters (event_type is string(40)).
    // They reach the tenancy log with no change to LeaseTimelineService (it lists every LeaseEvent row).
    public const TYPE_LEASE_CREATED = 'lease_created';
    public const TYPE_AGREEMENT_PREPARED = 'agreement_prepared';
    public const TYPE_AGREEMENT_OUT_FOR_SIGNING = 'agreement_out_for_signing';
    public const TYPE_AGREEMENT_EDITED = 'agreement_edited';
    public const TYPE_AGREEMENT_DIFFERENCES_CONFIRMED = 'agreement_differences_confirmed';
    public const TYPE_AGREEMENT_NEEDS_CONFIRMATION = 'agreement_needs_confirmation';
    public const TYPE_AGREEMENT_SIGNED = 'agreement_signed';
    public const TYPE_AGREEMENT_ACCEPTED = 'agreement_accepted';
    public const TYPE_LEASE_ACTIVATED_BY_SIGNING = 'lease_activated_by_signing';
    public const TYPE_AGREEMENT_DECLINED = 'agreement_declined';
    public const TYPE_AGREEMENT_VOIDED = 'agreement_voided';
    public const TYPE_AGREEMENT_EXPIRED = 'agreement_expired';
    public const TYPE_SIGNED_NOT_ACTIVATED = 'signed_not_activated';
    public const TYPE_LEASE_SIGNED_ON_PAPER = 'lease_signed_on_paper';
    public const TYPE_LEASE_EDITED = 'lease_edited';
    // LEASE-AGREEMENT END

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
