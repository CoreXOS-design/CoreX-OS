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
 * after any work order tied to it is closed. Stage 1 built the record
 * itself, reporting, and cancel/restore. Stage 2 (this revision) builds the
 * lifecycle: requestApproval()/recordApproval()/setOutcome(), against
 * columns already defined since Stage 1 (§3a's schema is spec-complete
 * from day one, not amended per stage). Work-order linkage (§3.1's reverse
 * FK, the workOrder() relation) lands in Stage 4.
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

    /** Stage 4 — App\Models\RentalWorkOrder now exists; see this class's own docblock. */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(RentalWorkOrder::class, 'rental_work_order_id');
    }

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

    public function approvals(): HasMany
    {
        return $this->hasMany(RentalApproval::class)->orderByDesc('created_at');
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
     * §3a.1 — a purely agent-initiated status marker: "I've asked the owner,
     * waiting to hear back." Records no evidence (Johan's ruling requires
     * evidence only for the DECISION, §3.4a) — it exists so the list screen
     * can distinguish "nothing asked yet" from "asked, pending." Optional:
     * recordApproval() below does NOT require this to have been called
     * first — an agent who already has the written reply in hand records
     * the decision directly, without a pointless intermediate click.
     */
    public function requestApproval(): void
    {
        if (in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CANCELLED], true)) {
            throw new \LogicException('This fault report is already closed.');
        }
        if ($this->owner_approval_status === self::APPROVAL_APPROVED || $this->owner_approval_status === self::APPROVAL_DECLINED) {
            throw new \LogicException('A decision has already been recorded for this fault report.');
        }

        $this->forceFill([
            'status' => self::STATUS_AWAITING_APPROVAL,
            'owner_approval_status' => self::APPROVAL_PENDING,
        ])->save();
    }

    /**
     * §3.4a/§3a.1, settled 2026-09-25 — the decision, always in writing, with
     * TWO distinct outcomes when approved (§0c): agency_appoints (a work
     * order follows, Stage 4) or owner_handles (no work order, ever, for
     * this report — a fully normal, complete path). Writes the append-only
     * evidence row and updates this report's own denormalized current-value
     * columns, same "current column + log" shape §3.4 already uses for
     * rental_work_orders.status/rental_work_order_updates.
     */
    public function recordApproval(User $recordedBy, array $attributes): RentalApproval
    {
        if (in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CANCELLED], true)) {
            throw new \LogicException('This fault report is already closed.');
        }

        $decision = $attributes['decision'];
        $route = $attributes['approval_route'] ?? null;

        if ($decision === self::APPROVAL_APPROVED && ! in_array($route, [self::ROUTE_AGENCY_APPOINTS, self::ROUTE_OWNER_HANDLES], true)) {
            throw new \InvalidArgumentException('approval_route (agency_appoints or owner_handles) is required when the decision is approved.');
        }
        if ($decision === self::APPROVAL_DECLINED) {
            $route = null; // never meaningful on a decline
        }

        $approval = $this->approvals()->create([
            'agency_id' => $this->agency_id,
            'decision' => $decision,
            'approval_route' => $route,
            'evidence_type' => $attributes['evidence_type'],
            'evidence_text' => $attributes['evidence_text'] ?? null,
            'evidence_file_path' => $attributes['evidence_file_path'] ?? null,
            'decided_at' => $attributes['decided_at'] ?? now(),
            'recorded_by_user_id' => $recordedBy->id,
        ]);

        $newStatus = match (true) {
            $decision === self::APPROVAL_DECLINED => self::STATUS_DECLINED,
            $route === self::ROUTE_OWNER_HANDLES => self::STATUS_OWNER_HANDLING,
            $route === self::ROUTE_AGENCY_APPOINTS => self::STATUS_APPROVED,
            default => $this->status,
        };

        $this->forceFill([
            'status' => $newStatus,
            'owner_approval_status' => $decision === self::APPROVAL_APPROVED ? self::APPROVAL_APPROVED : self::APPROVAL_DECLINED,
            'approval_route' => $route,
        ])->save();

        return $approval;
    }

    /**
     * §3a.2/§0c — the spine of this whole record: was it repaired, and when.
     * Deliberately NOT gated on owner_approval_status, approval_route, or
     * any work order existing — Johan's own situation 3 (§1/§7) is a fault
     * report reaching an outcome having never gone through approval at all
     * (nobody fixed it), and the owner_handles route (§3a.1) reaches a
     * `repaired` outcome with no work order ever having existed. This method
     * is callable from any state except already-closed, on purpose.
     */
    public function setOutcome(array $attributes): void
    {
        if (in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CANCELLED], true)) {
            throw new \LogicException('This fault report is already closed.');
        }

        $outcome = $attributes['outcome'];
        $note = $attributes['outcome_note'] ?? null;
        $repairedAt = $attributes['repaired_at'] ?? null;

        if ($outcome !== self::OUTCOME_REPAIRED && empty($note)) {
            throw new \InvalidArgumentException('outcome_note is required unless the outcome is "repaired".');
        }
        if (in_array($outcome, [self::OUTCOME_REPAIRED, self::OUTCOME_REPAIRED_PARTIALLY], true) && empty($repairedAt)) {
            throw new \InvalidArgumentException('repaired_at is required when the outcome is repaired or repaired_partially — Johan\'s own words: "the important part is capturing if and when the repairs were carried out."');
        }

        $this->forceFill([
            'status' => self::STATUS_RESOLVED,
            'outcome' => $outcome,
            'outcome_note' => $note,
            'repaired_at' => $repairedAt,
            'resolved_at' => now(),
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
