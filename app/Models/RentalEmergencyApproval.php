<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-work-orders.md §17.8 — the owner's agreement to emergency
 * work, captured by the office against a work order with NO COST ATTACHED (by
 * ruling there is no amount column). There is no override of the owner's
 * agreement anywhere: this record IS the agreement.
 *
 * Append-only except `voided_*`: a mistaken entry is voided with a reason and a
 * new one recorded; never deleted. One active (un-voided) record per work order.
 */
class RentalEmergencyApproval extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    public const VIA_PHONE = 'phone';
    public const VIA_WHATSAPP = 'whatsapp';
    public const VIA_EMAIL = 'email';
    public const VIA_IN_PERSON = 'in_person';
    public const VIA_OTHER = 'other';

    public const VIAS = [self::VIA_PHONE, self::VIA_WHATSAPP, self::VIA_EMAIL, self::VIA_IN_PERSON, self::VIA_OTHER];

    protected $fillable = [
        'agency_id',
        'rental_work_order_id',
        'approved_by_name',
        'owner_contact_id',
        'approved_via',
        'approved_at',
        'reason',
        'reported_by_crew_name',
        'notes',
        'attachment_path',
        'recorded_by_user_id',
        'voided_at',
        'voided_by_user_id',
        'void_reason',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(RentalWorkOrder::class, 'rental_work_order_id');
    }

    /** Deleted-related-record rule (.ai/BUILD_STANDARD.md §4): an archived contact must not null the record. */
    public function ownerContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'owner_contact_id')->withTrashed();
    }

    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function scopeActive($query)
    {
        return $query->whereNull($query->getModel()->getTable() . '.voided_at');
    }
}
