<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22.7 - "Stop this": the owner paused a job their agent arranged. ONE row per stop, append-only; a hold is
 * closed by `resumed_at` (a fresh authority: the owner's "Go ahead after all", or the agent recording the owner's decision), never deleted. ACTIVE = not resumed.
 */
class RentalOwnerHold extends Model
{
    use BelongsToAgency;

    public const VIA_LINK = 'link';
    public const VIA_PORTAL = 'portal';

    public const RESUME_OWNER_LINK = 'owner_link';
    public const RESUME_OWNER_INSTRUCTION = 'owner_instruction';

    protected $fillable = [
        'agency_id', 'rental_fault_report_id', 'rental_work_order_id', 'rental_authorisation_id', 'stopped_by_contact_id', 'stopped_via', 'reason',
        'stage_when_stopped', 'stopped_at', 'contractor_told_at', 'contractor_told_note', 'resumed_at', 'resume_basis', 'resumed_by_contact_id',
        'resumed_by_user_id', 'resume_authorisation_id',
    ];

    protected $casts = ['stopped_at' => 'datetime', 'contractor_told_at' => 'datetime', 'resumed_at' => 'datetime'];

    public function scopeActive($query)
    {
        return $query->whereNull('resumed_at');
    }

    public function isActive(): bool
    {
        return $this->resumed_at === null;
    }

    public function stoppedBy(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'stopped_by_contact_id')->withoutGlobalScopes();
    }

    public function resumedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resumed_by_user_id')->withoutGlobalScopes();
    }

    public function resumedByContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'resumed_by_contact_id')->withoutGlobalScopes();
    }

    public function faultReport(): BelongsTo
    {
        return $this->belongsTo(RentalFaultReport::class, 'rental_fault_report_id')->withoutGlobalScopes();
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(RentalWorkOrder::class, 'rental_work_order_id')->withoutGlobalScopes();
    }
}
