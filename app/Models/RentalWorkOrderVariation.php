<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * .ai/specs/rental-work-orders.md §17.7 — extra work (or a higher external quote)
 * raised AFTER the owner approved an amount. Compared with the work order's
 * `approved_amount` only (§17.7.2) so small auto-approved steps cannot creep.
 * Never deleted — a variation that no longer applies is `withdrawn`.
 */
class RentalWorkOrderVariation extends Model
{
    use BelongsToAgency;

    public const STATUS_AWAITING_OWNER = 'awaiting_owner';
    public const STATUS_AUTO_APPROVED = 'auto_approved';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_WITHDRAWN = 'withdrawn';

    public const ORIGIN_CREW_LINES = 'crew_lines';
    public const ORIGIN_OFFICE_EDIT = 'office_edit';
    public const ORIGIN_EXTERNAL_QUOTE = 'external_quote';

    public const VIA_PORTAL = 'portal';
    public const VIA_AGENT_CAPTURE = 'agent_capture';

    protected $fillable = [
        'agency_id',
        'rental_work_order_id',
        'rental_job_card_id',
        'rental_work_order_quote_id',
        'revision',
        'status',
        'origin',
        'baseline_amount',
        'extra_amount',
        'new_total',
        'price_change_amount',
        'term_basis',
        'term_value',
        'term_source',
        'note',
        'term_text',
        'raised_by_user_id',
        'raised_at',
        'mail_sent_at',
        'decided_at',
        'decided_by_user_id',
        'decided_by_contact_id',
        'decided_via',
        'decision_note',
    ];

    protected $casts = [
        'revision' => 'integer',
        'baseline_amount' => 'decimal:2',
        'extra_amount' => 'decimal:2',
        'new_total' => 'decimal:2',
        'price_change_amount' => 'decimal:2',
        'term_value' => 'decimal:2',
        'raised_at' => 'datetime',
        'mail_sent_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(RentalWorkOrder::class, 'rental_work_order_id');
    }

    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(RentalJobCard::class, 'rental_job_card_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(RentalWorkOrderQuote::class, 'rental_work_order_quote_id')->withTrashed();
    }

    /** The job-card lines this variation covers (office view: accepted lines flagged awaiting the owner). */
    public function lines(): HasMany
    {
        return $this->hasMany(RentalJobCardLine::class, 'rental_work_order_variation_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(RentalApprovalDecision::class, 'rental_work_order_variation_id')->orderByDesc('created_at');
    }

    public function isAwaitingOwner(): bool
    {
        return $this->status === self::STATUS_AWAITING_OWNER;
    }

    public function scopeAwaitingOwner($query)
    {
        return $query->where($query->getModel()->getTable() . '.status', self::STATUS_AWAITING_OWNER);
    }
}
