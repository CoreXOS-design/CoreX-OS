<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-work-orders.md §3.1/§3.4, Stage 4 — a work order is
 * evidence, permanently, not an operational ticket that closes and is
 * forgotten (§1). Raised directly, from an inspection observation, or from
 * an already-approved fault report (§3a.1) — the last case is the
 * "agency_appoints" route, the only route that ever produces a work order
 * at all (§0c/§3a.1: "owner_handles" never does).
 */
class RentalWorkOrder extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const REPORTED_BY_TENANT = 'tenant';
    public const REPORTED_BY_AGENT_NOTICED = 'agent_noticed';
    public const REPORTED_BY_OWNER_INSTRUCTED = 'owner_instructed';
    public const REPORTED_BY_INSPECTION = 'inspection';
    public const REPORTED_BY_FAULT_REPORT = 'fault_report';

    public const STATUS_REPORTED = 'reported';
    public const STATUS_ORDERED = 'ordered';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const APPROVAL_NOT_REQUIRED = 'not_required';
    public const APPROVAL_PENDING = 'pending';
    public const APPROVAL_APPROVED = 'approved';
    public const APPROVAL_DECLINED = 'declined';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_URGENT = 'urgent';

    public const PAID_BY_OWNER = 'owner';
    public const PAID_BY_TENANT = 'tenant';
    public const PAID_BY_DEPOSIT_DEDUCTION = 'deposit_deduction';
    public const PAID_BY_NOT_YET_PAID = 'not_yet_paid';

    public const PHOTO_REPORTED = 'reported';
    public const PHOTO_IN_PROGRESS = 'in_progress';
    public const PHOTO_COMPLETED = 'completed';

    protected $fillable = [
        'agency_id',
        'branch_id',
        'property_id',
        'lease_id',
        'rental_inspection_item_id',
        'agency_service_provider_id',
        'owner_approval_status',
        'trade_type',
        'title',
        'description',
        'status',
        'priority',
        'reported_by_type',
        'reported_by_contact_id',
        'reported_by_user_id',
        'reported_inspection_observation_id',
        'reported_fault_report_id',
        'reported_at',
        'ordered_at',
        'completed_at',
        'completion_notes',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancel_reason',
        'paid_by',
        'cost_amount',
        'created_by_user_id',
    ];

    protected $casts = [
        'reported_at' => 'datetime',
        'ordered_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'cost_amount' => 'decimal:2',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** Branch logo fallback for the supplier PDF (§"Printing", 2026-09-22). */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function inspectionItem(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionItem::class, 'rental_inspection_item_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(\App\Models\DealV2\AgencyServiceProvider::class, 'agency_service_provider_id');
    }

    public function reportedByContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'reported_by_contact_id');
    }

    public function reportedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function reportedInspectionObservation(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionObservation::class, 'reported_inspection_observation_id');
    }

    public function reportedFaultReport(): BelongsTo
    {
        return $this->belongsTo(RentalFaultReport::class, 'reported_fault_report_id');
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
        return $this->hasMany(RentalWorkOrderPhoto::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(RentalWorkOrderUpdate::class)->orderByDesc('created_at');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(RentalApproval::class)->orderByDesc('created_at');
    }

    /**
     * A single, plain, chronological history — every state-changing action
     * on this record, actor + action + from/to + note + when, oldest first.
     * Merges the synthetic "logged" event (this record's own creation —
     * created_by_user_id/created_at already carry it, never duplicated into
     * a row), the real rental_work_order_updates rows, and the approval
     * decisions (a separate table, §3.4a, folded in so an agent reads ONE
     * timeline). Same shape as RentalFaultReport::history() — this feature's
     * two records share one audit convention, not two.
     *
     * @return \Illuminate\Support\Collection<int, array{at: \Illuminate\Support\Carbon, actor: ?string, action: string, from: ?string, to: ?string, note: ?string}>
     */
    public function history(): \Illuminate\Support\Collection
    {
        $entries = collect();

        $entries->push([
            'at' => $this->created_at,
            'actor' => $this->createdByUser?->name,
            'action' => 'Logged',
            'from' => null,
            'to' => null,
            'note' => null,
        ]);

        foreach ($this->updates as $update) {
            $entries->push([
                'at' => $update->created_at,
                'actor' => $update->createdByUser?->name,
                'action' => match ($update->update_type) {
                    'supplier_assigned' => 'Supplier assigned',
                    'supplier_changed' => 'Supplier changed',
                    'status_change' => 'Status changed',
                    'note' => 'Note added',
                    default => ucfirst(str_replace('_', ' ', $update->update_type)),
                },
                'from' => $update->from_status ? ucfirst(str_replace('_', ' ', $update->from_status)) : null,
                'to' => $update->to_status ? ucfirst(str_replace('_', ' ', $update->to_status)) : null,
                'note' => $update->note,
            ]);
        }

        foreach ($this->approvals as $approval) {
            $entries->push([
                'at' => $approval->created_at,
                'actor' => $approval->recordedByUser?->name,
                'action' => $approval->decision === RentalApproval::DECISION_APPROVED ? 'Approved' : 'Declined',
                'from' => null,
                'to' => null,
                'note' => $approval->evidence_text,
            ]);
        }

        return $entries->sortBy('at')->values();
    }

    /**
     * §3.1 — deletable only while nothing has been logged against it: no
     * update row, no photo. Once either exists, only 'cancelled'.
     */
    public function isDeletable(): bool
    {
        return $this->updates()->doesntExist() && $this->photos()->doesntExist();
    }

    /**
     * Johan, 2026-09-22 — archive/restore captured in the same "who did
     * what" history as every other action on this record.
     */
    public function archive(User $by): void
    {
        $this->delete();
        $this->updates()->create([
            'agency_id' => $this->agency_id, 'update_type' => 'archived', 'created_by_user_id' => $by->id,
        ]);
    }

    public function restoreRecord(User $by): void
    {
        $this->restore();
        $this->updates()->create([
            'agency_id' => $this->agency_id, 'update_type' => 'restored', 'created_by_user_id' => $by->id,
        ]);
    }

    /**
     * §3.4a — a work order raised WITHOUT an upstream fault report has no
     * pre-satisfied approval to inherit, so it records its own decision
     * here, via the same shared rental_approvals table (§3.4a). Unlike
     * RentalFaultReport::recordApproval(), there is no "route" — a work
     * order's mere existence already means the agency-appoints path was
     * chosen (§3a.1) — so approval_route is always null at this level.
     */
    public function recordApproval(User $recordedBy, array $attributes): RentalApproval
    {
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            throw new \LogicException('This work order is already closed.');
        }

        $decision = $attributes['decision'];

        $approval = $this->approvals()->create([
            'agency_id' => $this->agency_id,
            'decision' => $decision,
            'approval_route' => null,
            'evidence_type' => $attributes['evidence_type'],
            'evidence_text' => $attributes['evidence_text'] ?? null,
            'evidence_file_path' => $attributes['evidence_file_path'] ?? null,
            'decided_at' => $attributes['decided_at'] ?? now(),
            'recorded_by_user_id' => $recordedBy->id,
        ]);

        $this->forceFill([
            'owner_approval_status' => $decision === self::APPROVAL_APPROVED ? self::APPROVAL_APPROVED : self::APPROVAL_DECLINED,
        ])->save();

        return $approval;
    }

    /**
     * §3.4 — assigning/re-assigning a supplier. Moves 'reported' straight to
     * 'ordered' the first time; re-assigning a supplier on an already-
     * ordered/in_progress work order does not re-trigger that transition.
     * Gated: refuses while owner approval is pending or declined (§3.4a) —
     * a work order can only ever exist on the agency_appoints route, so
     * this gate is only ever exercised by one raised directly (proactive
     * owner-instructed work, or straight from an inspection).
     */
    public function assignSupplier(int $agencyServiceProviderId, ?string $tradeType, User $by): void
    {
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            throw new \LogicException('This work order is already closed.');
        }
        if (in_array($this->owner_approval_status, [self::APPROVAL_PENDING, self::APPROVAL_DECLINED], true)) {
            throw new \LogicException('Cannot assign a supplier while owner approval is pending or declined.');
        }

        $updateType = $this->agency_service_provider_id === null ? 'supplier_assigned' : 'supplier_changed';
        $fromStatus = $this->status;
        $movingToOrdered = $this->status === self::STATUS_REPORTED;

        $this->agency_service_provider_id = $agencyServiceProviderId;
        if ($tradeType !== null) {
            $this->trade_type = $tradeType;
        }
        if ($movingToOrdered) {
            $this->status = self::STATUS_ORDERED;
            $this->ordered_at = now();
        }
        $this->save();

        $this->updates()->create([
            'agency_id' => $this->agency_id, 'update_type' => $updateType, 'created_by_user_id' => $by->id,
        ]);
        if ($movingToOrdered) {
            $this->updates()->create([
                'agency_id' => $this->agency_id, 'update_type' => 'status_change',
                'from_status' => $fromStatus, 'to_status' => self::STATUS_ORDERED, 'created_by_user_id' => $by->id,
            ]);
        }
    }

    /** §3.4 — optional stage; a quick fix may skip straight to completed. */
    public function startProgress(User $by): void
    {
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            throw new \LogicException('This work order is already closed.');
        }

        $fromStatus = $this->status;
        $this->update(['status' => self::STATUS_IN_PROGRESS]);
        $this->updates()->create([
            'agency_id' => $this->agency_id, 'update_type' => 'status_change',
            'from_status' => $fromStatus, 'to_status' => self::STATUS_IN_PROGRESS, 'created_by_user_id' => $by->id,
        ]);
    }

    /**
     * §3.4 — a 'completed' photo is invited, not required, by default
     * (gated by the agency's own completion_requires_photo setting,
     * default off — corrected 2026-09-21, Johan: not every repair has a
     * meaningful photo, e.g. a gate motor). An agency may still turn this
     * on. paid_by is always required, no setting behind it.
     */
    public function complete(User $by, array $attributes): void
    {
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            throw new \LogicException('This work order is already closed.');
        }

        if (RentalWorkOrderSetting::completionRequiresPhotoFor($this->agency_id)
            && $this->photos()->where('photo_type', self::PHOTO_COMPLETED)->doesntExist()) {
            throw new \LogicException('At least one "completed" photo is required before this can be marked complete.');
        }

        $paidBy = $attributes['paid_by'] ?? null;
        if (empty($paidBy)) {
            throw new \InvalidArgumentException('paid_by is required before completion.');
        }

        $fromStatus = $this->status;
        $this->forceFill([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
            'completion_notes' => $attributes['completion_notes'] ?? $this->completion_notes,
            'paid_by' => $paidBy,
            'cost_amount' => $attributes['cost_amount'] ?? $this->cost_amount,
        ])->save();

        $this->updates()->create([
            'agency_id' => $this->agency_id, 'update_type' => 'status_change',
            'from_status' => $fromStatus, 'to_status' => self::STATUS_COMPLETED, 'created_by_user_id' => $by->id,
        ]);
    }

    /** §3.4 — never deleted once anything has been logged against it. */
    public function cancel(User $by, string $reason): void
    {
        if ($this->status === self::STATUS_CANCELLED) {
            throw new \LogicException('This work order is already cancelled.');
        }

        $fromStatus = $this->status;
        $this->forceFill([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $by->id,
            'cancel_reason' => $reason,
        ])->save();

        $this->updates()->create([
            'agency_id' => $this->agency_id, 'update_type' => 'status_change',
            'from_status' => $fromStatus, 'to_status' => self::STATUS_CANCELLED, 'created_by_user_id' => $by->id,
        ]);
    }

    /** §3.4 — a free-text elaboration, not tied to a status change. */
    public function addNote(string $note, User $by): void
    {
        $this->updates()->create([
            'agency_id' => $this->agency_id, 'update_type' => 'note', 'note' => $note, 'created_by_user_id' => $by->id,
        ]);
    }

    /**
     * §6 — the "overdue" list-screen filter: ordered/in_progress with no
     * status change past the agency's own overdue_reminder_days. Reads the
     * model's own `updated_at`, bumped by every save() this class performs
     * (assignSupplier/startProgress/complete/cancel all call save()) —
     * a reliable proxy for "last time anything changed" without a
     * correlated subquery against rental_work_order_updates.
     */
    public function scopeOverdue(Builder $query, int $days): Builder
    {
        return $query->whereIn('rental_work_orders.status', [self::STATUS_ORDERED, self::STATUS_IN_PROGRESS])
            ->where('rental_work_orders.updated_at', '<=', now()->subDays($days));
    }

    /**
     * §6 — OWN/BRANCH/AGENCY scoping, same convention as
     * RentalFaultReport::scopeVisibleTo()/RentalInspection::scopeVisibleTo().
     */
    public function scopeVisibleTo(Builder $query, User $user, ?string $requestedScope = null): Builder
    {
        $maxScope = \App\Services\PermissionService::getDataScope($user, 'rental_work_orders');
        $scope = \App\Services\PermissionService::clampScope($requestedScope, $maxScope);

        if ($scope === 'all') {
            return $query;
        }
        if ($scope === 'branch') {
            return $query->whereHas('property', fn (Builder $p) => $p->where('properties.branch_id', $user->effectiveBranchId()));
        }
        if ($scope === 'own') {
            return $query->whereIn('rental_work_orders.created_by_user_id', $user->dataIdentityIds());
        }

        return $query->whereRaw('1 = 0');
    }
}
