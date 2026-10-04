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

    // rental-renewals.md §7 — who gave notice. Free-form string, not an enum
    // class of its own — matches the 'source' column's own convention.
    public const NOTICE_BY_TENANT = 'tenant';
    public const NOTICE_BY_LANDLORD = 'landlord';

    protected $fillable = [
        'agency_id',
        'branch_id',
        'property_id',
        'status',
        'rental_amount',
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
        'notice_date',
        'notice_given_by',
        'notice_note',
        'move_out_date',
        'notice_readvertised',
        'renewal_draft_flow_id',
    ];

    protected $casts = [
        'rental_amount' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_month_to_month' => 'boolean',
        'cancelled_at' => 'datetime',
        'notice_date' => 'date',
        'move_out_date' => 'date',
        'notice_readvertised' => 'boolean',
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

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(RentalInspection::class);
    }

    public function faultReports(): HasMany
    {
        return $this->hasMany(RentalFaultReport::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(RentalWorkOrder::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(RentalInventory::class);
    }

    /**
     * rental-renewals.md §8 — append-only renewal/outcome event log, newest
     * last. Secondary `id` sort breaks ties when two events land in the
     * same second (occurred_at alone is not a reliable tiebreak — MySQL
     * does not guarantee insertion order for equal ORDER BY keys).
     */
    public function events(): HasMany
    {
        return $this->hasMany(LeaseEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    /**
     * rental-renewals.md §7 — true once notice (by either party) has been
     * recorded and not since reversed. Used by the Lease Hub next-step
     * card and by the one-click outcome buttons' own state (no point
     * offering "Tenant gave notice" again once notice is already on file).
     */
    public function hasActiveNotice(): bool
    {
        return $this->notice_date !== null;
    }

    /**
     * AT-440 (Lease Hub) — "landlord via the derived accessor cc1 is adding
     * in AT-439... if it has not landed when you get there, resolve
     * landlords from the property contact-role pivot through a small
     * method you can later swap — do not add a column." Deliberately NOT a
     * stored column — leases.md's own docblock (§G of the Stage-1
     * investigation) is explicit the landlord is derived from the
     * Property's contact link, never duplicated onto the Lease. Checks
     * BOTH 'landlord' and 'lessor' pivot roles (Property::sellerOwnerContact()'s
     * own vocabulary), falling back to sellerOwnerContact()'s single-result
     * collapse when neither role is tagged but the property has exactly one
     * contact — the same precedent RentalFaultReportController::create()
     * already uses for this exact fact.
     */
    public function landlordContacts(): \Illuminate\Support\Collection
    {
        $property = $this->property;
        if (!$property) {
            return collect();
        }

        $contacts = $property->contactsForRole('landlord')
            ->merge($property->contactsForRole('lessor'))
            ->unique('id')
            ->values();

        if ($contacts->isNotEmpty()) {
            return $contacts;
        }

        $fallback = $property->sellerOwnerContact();

        return $fallback ? collect([$fallback]) : collect();
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
