<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-work-orders.md §3a, amended 2026-09-25 (§0c) — a fault
 * report is its own record: what actually happened during a tenancy that
 * might need repair, with its own lifecycle (reported -> owner approval
 * where required -> work order raised (optional) -> outcome). It exists
 * whether or not a work order is ever raised from it, and survives long
 * after any work order tied to it is closed. Stage 1 build: the record
 * itself, reporting, and cancel/restore. Approval/outcome transitions land
 * in Stage 2 against the columns already defined here (§3a's schema is
 * spec-complete from day one, not amended per stage).
 */
class RentalFaultReport extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const REPORTED_BY_TENANT = 'tenant';
    public const REPORTED_BY_AGENT_NOTICED = 'agent_noticed';
    public const REPORTED_BY_OWNER_INSTRUCTED = 'owner_instructed';

    public const CHANNEL_PHONE = 'phone';
    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_IN_PERSON = 'in_person';
    public const CHANNEL_APP = 'app';
    public const CHANNEL_OTHER = 'other';

    public const STATUS_REPORTED = 'reported';
    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_WORK_ORDER_RAISED = 'work_order_raised';
    public const STATUS_OWNER_HANDLING = 'owner_handling';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CANCELLED = 'cancelled';

    public const APPROVAL_NOT_REQUIRED = 'not_required';
    public const APPROVAL_PENDING = 'pending';
    public const APPROVAL_APPROVED = 'approved';
    public const APPROVAL_DECLINED = 'declined';

    public const ROUTE_AGENCY_APPOINTS = 'agency_appoints';
    public const ROUTE_OWNER_HANDLES = 'owner_handles';

    public const OUTCOME_REPAIRED = 'repaired';
    public const OUTCOME_REPAIRED_PARTIALLY = 'repaired_partially';
    public const OUTCOME_NOT_REPAIRED = 'not_repaired';
    public const OUTCOME_OWNER_DECLINED = 'owner_declined';
    public const OUTCOME_TENANT_LIABLE = 'tenant_liable';

    protected $fillable = [
        'agency_id',
        'branch_id',
        'property_id',
        'lease_id',
        'rental_inspection_item_id',
        'reported_inspection_observation_id',
        'rental_work_order_id',
        'reported_by_type',
        'reported_by_contact_id',
        'reported_by_user_id',
        'reported_channel',
        'captured_by_user_id',
        'title',
        'description',
        'status',
        'owner_approval_status',
        'approval_route',
        'outcome',
        'outcome_note',
        'repaired_at',
        'reported_at',
        'resolved_at',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancel_reason',
        'created_by_user_id',
    ];

    protected $casts = [
        'repaired_at' => 'date',
        'reported_at' => 'datetime',
        'resolved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function inspectionItem(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionItem::class, 'rental_inspection_item_id');
    }

    public function reportedInspectionObservation(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionObservation::class, 'reported_inspection_observation_id');
    }

    // workOrder(): BelongsTo — added in Stage 4 once App\Models\RentalWorkOrder
    // exists. Unlike the DB column (present now, §3a schema is spec-complete
    // from day one), an Eloquent relation method must instantiate its related
    // model class the moment it's CALLED — not just referenced by ::class —
    // so this can't be defined against a class that doesn't exist yet without
    // breaking every eager-load/lazy-access of this model in the meantime.

    public function reportedByContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'reported_by_contact_id');
    }

    public function reportedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function capturedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(RentalFaultReportPhoto::class);
    }

    /**
     * §3a schema block — deletable only while nothing has been logged
     * against it: no photo, no linked work order. Once either exists, only
     * 'cancelled' — same reasoning and shape as RentalInspection::isDeletable().
     */
    public function isDeletable(): bool
    {
        return $this->photos()->doesntExist() && $this->rental_work_order_id === null;
    }

    /**
     * §12/§3a — logged in error, or the issue turned out not to need
     * action. Never deleted once anything has been logged against it (§3a
     * schema, isDeletable() above) — stays visible, cancelled.
     */
    public function cancel(User $by, string $reason): void
    {
        if ($this->status === self::STATUS_CANCELLED) {
            throw new \LogicException('This fault report is already cancelled.');
        }

        $this->forceFill([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $by->id,
            'cancel_reason' => $reason,
        ])->save();
    }

    /**
     * §3a.4 — OWN/BRANCH/AGENCY scoping, same PermissionService::getDataScope()
     * + clampScope() convention as RentalInspection::scopeVisibleTo(). This is
     * the AGENCY-WIDE, property-wide view (§3a.4's own distinction) — the
     * out-inspection's attached block (Stage 5) queries lease_id directly
     * instead, deliberately narrower than this scope.
     */
    public function scopeVisibleTo(Builder $query, User $user, ?string $requestedScope = null): Builder
    {
        $maxScope = \App\Services\PermissionService::getDataScope($user, 'rental_fault_reports');
        $scope = \App\Services\PermissionService::clampScope($requestedScope, $maxScope);

        if ($scope === 'all') {
            return $query;
        }
        if ($scope === 'branch') {
            return $query->whereHas('property', fn (Builder $p) => $p->where('properties.branch_id', $user->effectiveBranchId()));
        }
        if ($scope === 'own') {
            return $query->whereIn('rental_fault_reports.created_by_user_id', $user->dataIdentityIds());
        }

        return $query->whereRaw('1 = 0');
    }
}
