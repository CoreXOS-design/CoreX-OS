<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/leases.md — the spine of rentals. A lease takes a Property
 * (owner already known via the existing owner/landlord contact link),
 * adds one or more Tenants (lease_tenants, N-party), and carries terms.
 *
 * Every lease TERM is its own row — a renewal is a NEW row chained via
 * previous_lease_id/renewed_lease_id, never the same record extended in
 * place. See leases.md §3.1 for the argument.
 */
class Lease extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'agency_id',
        'branch_id',
        'property_id',
        'status',
        'rental_amount',
        // .ai/specs/rental-work-orders.md §3.4b, Johan's ruling 2026-09-26 —
        // the spend-threshold override lives on the lease, not the property.
        // Null means "use the agency default" (RentalWorkOrderSetting).
        'rental_no_approval_spend_threshold',
        'deposit_amount',
        'start_date',
        'end_date',
        'is_month_to_month',
        'lease_type',
        'source',
        'rental_application_id',
        'source_document_id',
        'previous_lease_id',
        'renewed_lease_id',
        'created_by_user_id',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancel_reason',
        'migrated_from_table',
        'migrated_from_id',
    ];

    protected $casts = [
        'rental_amount' => 'decimal:2',
        'rental_no_approval_spend_threshold' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_month_to_month' => 'boolean',
        'cancelled_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function rentalApplication(): BelongsTo
    {
        return $this->belongsTo(RentalApplication::class);
    }

    public function previousLease(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_lease_id');
    }

    public function renewedLease(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewed_lease_id');
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(LeaseTenant::class);
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(LeaseEscalation::class)->orderByDesc('effective_date');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Whether this lease can still be soft-deleted through the ordinary CRUD
     * path (leases.md §2 — deletable only while nothing has attached yet).
     * A lease with any escalation history has evidence on it and may only
     * be cancelled, never deleted.
     */
    public function isDeletable(): bool
    {
        return $this->escalations()->doesntExist();
    }

    public function tenantNames(): string
    {
        $names = $this->tenants->map(fn (LeaseTenant $t) => $t->contact?->full_name)->filter();

        return $names->isEmpty() ? 'No tenant linked' : $names->implode(', ');
    }

    /**
     * leases.md §7 — OWN/BRANCH/AGENCY scoping, layered on top of the hard
     * AgencyScope boundary. Same PermissionService::getDataScope() +
     * clampScope() convention already used by rental_applications, so the
     * existing role-manager scope UI covers this module without a new
     * mechanism.
     */
    public function scopeVisibleTo($query, User $user, ?string $requestedScope = null)
    {
        $maxScope = \App\Services\PermissionService::getDataScope($user, 'leases');
        $scope = \App\Services\PermissionService::clampScope($requestedScope, $maxScope);

        if ($scope === 'all') {
            return $query;
        }
        if ($scope === 'branch') {
            return $query->where('leases.branch_id', $user->effectiveBranchId());
        }
        if ($scope === 'own') {
            return $query->whereIn('leases.created_by_user_id', $user->dataIdentityIds());
        }

        return $query->whereRaw('1 = 0');
    }
}
