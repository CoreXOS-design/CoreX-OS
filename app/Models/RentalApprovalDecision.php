<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-work-orders.md §17.6.4 — an approval decision made by the
 * system (the gate), the owner or an emergency capture, ALWAYS citing the term
 * it relied on (key, value, where the value came from, the amount tested and
 * the ceiling it was tested against). `note` is the decision in words, frozen
 * when written: a later change of a term never rewrites history.
 *
 * Append-only — no updated_at, no deleted_at. Written only by
 * RentalApprovalGateService.
 */
class RentalApprovalDecision extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    public const BY_SYSTEM = 'system';
    public const BY_USER = 'user';
    public const BY_OWNER = 'owner';
    public const BY_EMERGENCY = 'emergency';

    public const DECISION_AUTO_APPROVED = 'auto_approved';
    public const DECISION_NEEDS_OWNER = 'needs_owner';
    public const DECISION_APPROVED = 'approved';
    public const DECISION_DECLINED = 'declined';
    public const DECISION_BLOCKED = 'blocked';
    public const DECISION_EMERGENCY_COVERED = 'emergency_covered';

    public const TERM_NO_APPROVAL_LIMIT = 'no_approval_limit';
    public const TERM_VARIATION_TOLERANCE = 'variation_tolerance';
    public const TERM_OWNER_DECISION = 'owner_decision';
    public const TERM_EMERGENCY = 'emergency';

    public const SOURCE_PROPERTY = 'property';
    public const SOURCE_AGENCY_DEFAULT = 'agency_default';
    public const SOURCE_CONSTANT = 'constant';
    public const SOURCE_OWNER = 'owner';
    public const SOURCE_EMERGENCY = 'emergency';

    protected $fillable = [
        'agency_id',
        'rental_work_order_id',
        'rental_work_order_variation_id',
        'rental_work_order_quote_id',
        'decided_by',
        'decided_by_user_id',
        'decided_by_contact_id',
        'decision',
        'basis',
        'term_key',
        'term_value',
        'term_source',
        'amount_tested',
        'baseline_amount',
        'limit_amount',
        'note',
    ];

    protected $casts = [
        'term_value' => 'decimal:2',
        'amount_tested' => 'decimal:2',
        'baseline_amount' => 'decimal:2',
        'limit_amount' => 'decimal:2',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(RentalWorkOrder::class, 'rental_work_order_id');
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(RentalWorkOrderVariation::class, 'rental_work_order_variation_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(RentalWorkOrderQuote::class, 'rental_work_order_quote_id')->withTrashed();
    }

    public function decidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
