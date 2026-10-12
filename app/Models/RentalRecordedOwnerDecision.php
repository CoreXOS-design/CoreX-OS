<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-452 round 3 (.ai/specs/rentals-faults-work-orders.md §20.2) - a decision an agent recorded on the owner's instruction (they told the agent
 * by phone, WhatsApp or email) instead of the owner pressing the button on their link. Never edited: the only columns ever written after
 * creation are `superseded_at` / `superseded_by`, when a newer recorded decision or the owner's own decision replaces it.
 */
class RentalRecordedOwnerDecision extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    public const STEP_FAULT = 'fault_decision';
    public const STEP_QUOTE = 'quote_approval';

    public const DECISION_APPROVED = 'approved';
    public const DECISION_GET_QUOTE_FIRST = 'get_quote_first';
    public const DECISION_DECLINED = 'declined';

    public const VIA_EMAIL = 'email';
    public const VIA_WHATSAPP = 'whatsapp';
    public const VIA_PHONE = 'phone_call';
    public const VIAS = [self::VIA_EMAIL, self::VIA_WHATSAPP, self::VIA_PHONE];

    protected $fillable = [
        'agency_id', 'rental_fault_report_id', 'rental_work_order_id', 'step', 'owner_contact_id', 'decision', 'approved_up_to',
        'told_via', 'told_at', 'note', 'proof_path', 'recorded_by_user_id', 'superseded_at', 'superseded_by',
    ];

    protected $casts = [
        'approved_up_to' => 'decimal:2',
        'told_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function faultReport(): BelongsTo
    {
        return $this->belongsTo(RentalFaultReport::class, 'rental_fault_report_id')->withoutGlobalScopes();
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(RentalWorkOrder::class, 'rental_work_order_id')->withoutGlobalScopes();
    }

    public function ownerContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'owner_contact_id')->withoutGlobalScopes();
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id')->withoutGlobalScopes();
    }

    public function isActive(): bool
    {
        return $this->superseded_at === null;
    }

    public static function viaLabel(string $via): string
    {
        return match ($via) {
            self::VIA_PHONE => 'phone call',
            self::VIA_WHATSAPP => 'WhatsApp',
            default => 'email',
        };
    }

    public static function decisionLabel(string $decision): string
    {
        return match ($decision) {
            self::DECISION_APPROVED => 'Approved',
            self::DECISION_GET_QUOTE_FIRST => 'Get a quote first',
            default => 'Declined',
        };
    }
}
