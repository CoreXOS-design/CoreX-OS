<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22 - the AUTHORISATION RECORD. Written at each of the two decisions of a job (Decision 1: who goes
 * to look; Decision 2: the cost): who authorised, on what basis, at what stage, when. Work proceeds only after the record exists. Append-only: the
 * only columns ever written after creation are `superseded_at` / `superseded_by_id` (a newer record replaces it) and `owner_told_at`.
 */
class RentalAuthorisation extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    public const POINT_INSPECT = 'inspect';   // Decision 1 - who goes to look
    public const POINT_COST = 'cost';         // Decision 2 - the cost

    public const OUTCOME_AUTHORISED = 'authorised';
    public const OUTCOME_DECLINED = 'declined';

    public const BASIS_OWNER_LINK = 'owner_link';
    public const BASIS_OWNER_INSTRUCTION = 'owner_instruction';
    public const BASIS_AGENT_NO_CALLOUT_FEE = 'agent_no_callout_fee';
    public const BASIS_AGENT_CALLOUT_SETTING = 'agent_callout_setting';
    public const BASIS_AGENT_REPAIR_LIMIT = 'agent_repair_limit';
    public const BASIS_AGENT_EMERGENCY_LIMIT = 'agent_emergency_limit';

    public const VIA_PHONE = 'phone_call';
    public const VIA_WHATSAPP = 'whatsapp';
    public const VIA_EMAIL = 'email';
    public const VIA_LINK = 'link';
    /** What an agent may pick in "Record the owner's decision". */
    public const VIAS = [self::VIA_PHONE, self::VIA_WHATSAPP, self::VIA_EMAIL];

    protected $fillable = [
        'agency_id', 'rental_fault_report_id', 'rental_work_order_id', 'point', 'outcome', 'basis', 'stage', 'amount_up_to', 'limit_amount',
        'is_emergency', 'emergency_reason', 'owner_contact_id', 'told_via', 'told_at', 'note', 'proof_path', 'by_user_id', 'by_contact_id',
        'inspector_label', 'agency_service_provider_id', 'callout_fee', 'owner_told_at', 'superseded_at', 'superseded_by_id', 'created_at',
    ];

    protected $casts = [
        'amount_up_to' => 'decimal:2',
        'limit_amount' => 'decimal:2',
        'callout_fee' => 'decimal:2',
        'is_emergency' => 'boolean',
        'told_at' => 'datetime',
        'owner_told_at' => 'datetime',
        'superseded_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $row) {
            $row->created_at ??= now();
        });
    }

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

    public function byUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'by_user_id')->withoutGlobalScopes();
    }

    public function byContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'by_contact_id')->withoutGlobalScopes();
    }

    public function isActive(): bool
    {
        return $this->superseded_at === null;
    }

    public function isAuthorised(): bool
    {
        return $this->outcome === self::OUTCOME_AUTHORISED;
    }

    /** The agent captured what the owner told them (as opposed to the owner pressing the button, or the agent acting under a limit). */
    public function isCaptured(): bool
    {
        return $this->basis === self::BASIS_OWNER_INSTRUCTION;
    }

    public static function viaLabel(?string $via): string
    {
        return match ($via) {
            self::VIA_PHONE => 'phone call',
            self::VIA_WHATSAPP => 'WhatsApp',
            self::VIA_EMAIL => 'email',
            self::VIA_LINK => 'their link',
            default => 'a message',
        };
    }
}
