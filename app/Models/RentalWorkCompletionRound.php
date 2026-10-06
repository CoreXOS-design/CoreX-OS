<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * .ai/specs/rental-work-orders.md §17.10 — one "work reported done" event and the
 * tenant check that follows it (internal AND external work). Rounds are numbered
 * 1, 2, 3… per work order and never deleted: every round is the history of the
 * check. An OPEN dispute is the work order's `status = disputed`, not a flag here.
 */
class RentalWorkCompletionRound extends Model
{
    use BelongsToAgency;

    public const VIA_CREW_LINK = 'crew_link';
    public const VIA_CREW_PAGE = 'crew_page';
    public const VIA_SIGNED_COPY = 'signed_copy';
    public const VIA_OFFICE = 'office';
    public const VIA_CONTRACTOR_CAPTURED = 'contractor_captured';

    public const NOTIFY_SENT = 'sent';
    public const NOTIFY_NO_TENANT = 'no_tenant';
    public const NOTIFY_NO_EMAIL = 'no_email';
    public const NOTIFY_DISABLED = 'disabled';
    public const NOTIFY_FAILED = 'failed';

    public const OUTCOME_AWAITING_TENANT = 'awaiting_tenant';
    public const OUTCOME_CONFIRMED = 'confirmed';
    public const OUTCOME_DISPUTED = 'disputed';
    public const OUTCOME_ACCEPTED_BY_SILENCE = 'accepted_by_silence';
    public const OUTCOME_NO_TENANT = 'no_tenant';

    public const RESPONDED_LINK = 'link';
    public const RESPONDED_PORTAL = 'portal';
    public const RESPONDED_OFFICE_ON_BEHALF = 'office_on_behalf';

    /** Matches the column default so a just-created instance reads correctly without a refresh. */
    protected $attributes = [
        'outcome' => self::OUTCOME_AWAITING_TENANT,
    ];

    protected $fillable = [
        'agency_id',
        'rental_work_order_id',
        'rental_job_card_id',
        'round_no',
        'opened_at',
        'reported_by_label',
        'reported_via',
        'reported_note',
        'reported_by_user_id',
        'tenant_notify_status',
        'tenant_notified_at',
        'window_ends_at',
        'outcome',
        'responded_at',
        'responded_via',
        'responded_by_contact_id',
        'responded_by_user_id',
        'response_note',
        'dispute_resolved_at',
        'sign_off_snapshot',
    ];

    protected $casts = [
        'round_no' => 'integer',
        'opened_at' => 'datetime',
        'tenant_notified_at' => 'datetime',
        'window_ends_at' => 'datetime',
        'responded_at' => 'datetime',
        'dispute_resolved_at' => 'datetime',
        'sign_off_snapshot' => 'array',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(RentalWorkOrder::class, 'rental_work_order_id');
    }

    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(RentalJobCard::class, 'rental_job_card_id');
    }

    /** The tenant's dispute photos (rental_work_order_photos.rental_completion_round_id). */
    public function photos(): HasMany
    {
        return $this->hasMany(RentalWorkOrderPhoto::class, 'rental_completion_round_id');
    }

    public function isAwaitingTenant(): bool
    {
        return $this->outcome === self::OUTCOME_AWAITING_TENANT;
    }

    public function scopeAwaitingTenant($query)
    {
        return $query->where($query->getModel()->getTable() . '.outcome', self::OUTCOME_AWAITING_TENANT);
    }
}
