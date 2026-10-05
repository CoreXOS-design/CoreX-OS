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

    // rental-renewals.md §19 — Johan's ruling 2026-10-05: what happens to the
    // PROPERTY once notice is recorded. The agent picks exactly one, every
    // time; nothing defaults/pre-selects. Replaces the old boolean
    // `notice_readvertised`, which could only ever represent READVERTISE or
    // LEAVE, never WITHDRAW.
    public const NOTICE_OUTCOME_READVERTISE = 'readvertise';
    public const NOTICE_OUTCOME_WITHDRAW = 'withdraw';
    public const NOTICE_OUTCOME_LEAVE = 'leave';

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
        'notice_outcome',
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

    /**
     * rental-command-centre.md §3.1 "Renewals in progress" / §9 — draft
     * term(s) chained BACK to this lease via previous_lease_id, still in
     * draft (not yet activated). Deliberately NOT "this lease's own
     * renewed_lease_id is set" — LeaseActivationService::activate() only
     * sets that at ACTIVATION time, by which point this lease is already
     * expired, not active — so that check can never fire for a currently
     * active lease. This relation/method is the ONE definition of "has a
     * renewal term in progress", reused by RentalCommandCentreService's
     * tile/filter so the two can never drift apart.
     */
    public function renewalDrafts(): HasMany
    {
        return $this->hasMany(self::class, 'previous_lease_id')->where('status', self::STATUS_DRAFT);
    }

    public function hasPendingRenewalDraft(): bool
    {
        return $this->renewalDrafts()->exists();
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(LeaseTenant::class);
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(LeaseEscalation::class)->orderByDesc('effective_date');
    }

    /**
     * Sets rental_amount to the new amount of the latest escalation whose
     * effective date has arrived (audit M6: a future-dated escalation must
     * not change the rent until its date). Idempotent — safe to call after
     * recording an escalation and again from the daily
     * leases:apply-due-escalations command. Returns whether the rent changed.
     */
    public function applyDueEscalation(): bool
    {
        $due = LeaseEscalation::where('lease_id', $this->id)
            ->whereDate('effective_date', '<=', now()->toDateString())
            ->orderByDesc('effective_date')->orderByDesc('id')
            ->first();

        if (! $due || (float) $due->new_rental_amount === (float) $this->rental_amount) {
            return false;
        }

        $this->update(['rental_amount' => $due->new_rental_amount]);

        return true;
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

    /** AT-445 — .ai/specs/rental-portal-access.md §8/§10. */
    public function notices(): HasMany
    {
        return $this->hasMany(RentalNotice::class);
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
     * AT-439 §G — derived, never duplicated onto this model. This is the
     * ONLY definition of this method — the other, AT-440/cc3 copy was
     * removed during a 2026-10-04 QA1 outage (duplicate-declaration 500,
     * 96b4f3ca0) by keeping this (AT-439's canonical contact-role-key,
     * N-party) version.
     *
     * AT-444 (2026-10-05): the 2026-10-04 "AT-444 follow-up 3" revision had
     * restored a fallback to `Property::sellerOwnerContact()` for a property
     * with zero landlord/lessor pivots — but that method's own job (AT-105,
     * PDF Splitter filing) is to guess the SOLE linked contact when none is
     * tagged seller-side, with NO awareness of whether that sole contact is
     * actually a tenant. On a property linked ONLY to its tenant, that
     * fallback returned the tenant AS the landlord — confirmed on QA1,
     * property 5792 / lease 10 (Andre Roets, the lease's tenant, shown as
     * both party chips in the rental context bar). `sellerOwnerContact()`
     * itself is NOT changed here — it is shared with sales-side callers
     * (PDF Splitter, Deal Register, match-card, Proforma) whose behaviour is
     * out of scope — this method simply stops calling it.
     *
     * The fallback is now a second EXPLICIT role check (seller/owner pivot
     * tags, via `contactsForRole('seller_owner')`), used ONLY when nothing
     * is tagged landlord/lessor — never a guess at "the only contact on
     * file." A contact whose role is tenant/occupant/applicant — anything
     * other than landlord/lessor/seller/owner — can never be returned
     * here. A true gap (no contact tagged any of the four roles) returns
     * an empty collection; every caller (Lease Hub, rental-context-bar,
     * tenancy report PDF, rental notices, renewal recipients, owner/
     * landlord-decision-needed mail) already renders "No landlord linked"
     * / "—" for an empty result rather than inventing one.
     */
    public function landlordContacts(): \Illuminate\Support\Collection
    {
        if (!$this->property) {
            return collect();
        }

        $landlords = $this->property->contactsForRole('landlord')
            ->merge($this->property->contactsForRole('lessor'))
            ->unique('id')
            ->values();

        if ($landlords->isNotEmpty()) {
            return $landlords;
        }

        return $this->property->contactsForRole('seller_owner');
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
     * AT-445 — the tenant-side equivalent of landlordContacts() above, used
     * by the portal to resolve which leases a given Contact may see as a
     * tenant. N-party: every contact on this lease's `lease_tenants` pivot.
     */
    public function tenantContacts(): \Illuminate\Support\Collection
    {
        return $this->tenants->map(fn (LeaseTenant $t) => $t->contact)->filter()->unique('id')->values();
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
