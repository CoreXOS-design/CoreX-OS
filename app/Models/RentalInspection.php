<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-inspections.md §3.2, amended (leases.md §9) — the event a
 * set of observations happens inside. Anchored to a `Lease` (required,
 * authoritative "which tenancy") with `property_id` as a denormalized
 * convenience column only (§2 pillar connections).
 */
class RentalInspection extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const TYPE_IN = 'in';
    public const TYPE_OUT = 'out';
    public const TYPE_AD_HOC = 'ad_hoc';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_AWAITING_SIGNATURE = 'awaiting_signature';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'agency_id',
        'lease_id',
        'property_id',
        'type',
        'status',
        'scheduled_for',
        'fault_report_deadline_at',
        'signing_deadline_at',
        'completed_at',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancel_reason',
        'created_by_user_id',
    ];

    protected $casts = [
        'scheduled_for' => 'date',
        'fault_report_deadline_at' => 'datetime',
        'signing_deadline_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $inspection) {
            // property_id is denormalized from the lease — always, never independently
            // set (§2/§3.2 docblock in the migration).
            if (empty($inspection->property_id) && $inspection->lease_id) {
                $inspection->property_id = Lease::withoutGlobalScopes()->find($inspection->lease_id)?->property_id;
            }
            if (empty($inspection->status)) {
                $inspection->status = self::STATUS_DRAFT;
            }
        });
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(RentalInspectionObservation::class);
    }

    public function discrepancies(): HasMany
    {
        return $this->hasMany(RentalInspectionDiscrepancy::class);
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(RentalInspectionSignature::class);
    }

    public function scopeOfType(Builder $q, ?string $type): Builder
    {
        return $type ? $q->where('type', $type) : $q;
    }

    public function scopeWithUnresolvedDiscrepancy(Builder $q): Builder
    {
        return $q->whereHas('discrepancies', fn (Builder $d) => $d->whereNull('resolved_at'));
    }

    /** §11 — cannot complete while any linked discrepancy is unresolved. */
    public function hasUnresolvedDiscrepancy(): bool
    {
        return $this->discrepancies()->whereNull('resolved_at')->exists();
    }

    /**
     * §3.3 — deletable through the ordinary CRUD path only while nothing has
     * been recorded against it yet. Once a single observation exists, this
     * is evidence and may only be cancelled, never deleted — same reasoning
     * and shape as Lease::isDeletable().
     */
    public function isDeletable(): bool
    {
        return $this->observations()->doesntExist();
    }

    /**
     * §5/§7 — OWN/BRANCH/AGENCY scoping, layered on top of the hard
     * AgencyScope boundary. Same PermissionService::getDataScope() +
     * clampScope() convention as Lease::scopeVisibleTo() and rental
     * applications, so the existing role-manager scope UI covers this
     * module without a new mechanism.
     */
    public function scopeVisibleTo($query, User $user, ?string $requestedScope = null)
    {
        $maxScope = \App\Services\PermissionService::getDataScope($user, 'rental_inspections');
        $scope = \App\Services\PermissionService::clampScope($requestedScope, $maxScope);

        if ($scope === 'all') {
            return $query;
        }
        if ($scope === 'branch') {
            return $query->whereHas('property', fn (Builder $p) => $p->where('properties.branch_id', $user->effectiveBranchId()));
        }
        if ($scope === 'own') {
            return $query->whereIn('rental_inspections.created_by_user_id', $user->dataIdentityIds());
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * §3.5/§0.7 — an out-inspection moves to awaiting_signature once the
     * walkthrough is done, opening the tenant's signing window. Guarded the
     * same way completion is: cannot proceed while a discrepancy is still
     * unresolved (§11).
     */
    public function startAwaitingSignature(): void
    {
        if ($this->type !== self::TYPE_OUT) {
            throw new \LogicException('Only an out-inspection has a signing window.');
        }
        if ($this->hasUnresolvedDiscrepancy()) {
            throw new \LogicException('Cannot start the signing window while a discrepancy is unresolved.');
        }

        $this->forceFill([
            'status' => self::STATUS_AWAITING_SIGNATURE,
            'signing_deadline_at' => now()->addDays(RentalInspectionSetting::signingWindowDaysFor($this->agency_id)),
        ])->save();
    }

    /**
     * §3.5/§11 — completing an in-inspection opens the tenant's fault-report
     * window from that moment. Completing an out-inspection requires a
     * signature already on record (tenant's own, or an agent_on_behalf one
     * carrying the required refusal note, §0.7) — signing is what closes it,
     * not a separate step. Either way, cannot complete while a discrepancy
     * is unresolved (§11).
     */
    public function markCompleted(): void
    {
        if ($this->hasUnresolvedDiscrepancy()) {
            throw new \LogicException('Cannot complete an inspection while a discrepancy is unresolved.');
        }
        if ($this->type === self::TYPE_OUT && ! $this->signatures()->exists()) {
            throw new \LogicException('Cannot complete an out-inspection with no signature on record.');
        }

        $completedAt = now();
        $attributes = [
            'status' => self::STATUS_COMPLETED,
            'completed_at' => $completedAt,
        ];

        if ($this->type === self::TYPE_IN) {
            $attributes['fault_report_deadline_at'] = $completedAt->copy()
                ->addDays(RentalInspectionSetting::faultReportWindowDaysFor($this->agency_id));
        }

        $this->forceFill($attributes)->save();
    }

    /**
     * §0.2/§3.2a/§11 — every observation ever recorded against every item on
     * THIS PROPERTY, oldest first, regardless of which lease/tenancy
     * recorded it. This is what an out-inspection view must read: a fault
     * reported under a PREVIOUS tenant stays visible so an owner can't blame
     * the current tenant for damage the owner neglected to fix. Deliberately
     * includes retired items too — retiring only blocks new observations,
     * it never hides history (§3.3).
     */
    public function carryForwardItems(): \Illuminate\Support\Collection
    {
        return RentalInspectionItem::query()
            ->where('property_id', $this->property_id)
            ->with(['observations' => fn (HasMany $q) => $q->oldest('created_at')->oldest('id')])
            ->get();
    }
}
