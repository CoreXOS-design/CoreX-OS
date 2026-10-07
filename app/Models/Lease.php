<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    // rental-takeon-import.md §5.3 — audit trail of how this lease came to
    // exist. 'source' is a plain string(30), not a real enum, so this is
    // just a new recognised value, no schema change.
    public const SOURCE_MIGRATED_TAKEON = 'migrated_takeon';

    // LEASE-AGREEMENT BEGIN (leases.md §15.5 — Build L1). `signing_status` runs beside `status`
    // (which keeps its meaning); it says where the lease's e-sign agreement is, never whether the
    // lease is in force.
    public const SIGNING_NOT_SENT = 'not_sent';
    public const SIGNING_PREPARED = 'prepared';
    public const SIGNING_OUT_FOR_SIGNING = 'out_for_signing';
    public const SIGNING_AWAITING_AGENT_REVIEW = 'awaiting_agent_review';
    public const SIGNING_SIGNED = 'signed';
    public const SIGNING_DECLINED = 'declined';
    public const SIGNING_VOIDED = 'voided';
    public const SIGNING_EXPIRED = 'expired';
    public const SIGNING_SIGNED_ON_PAPER = 'signed_on_paper';

    public const SIGNING_STATUSES = [
        self::SIGNING_NOT_SENT,
        self::SIGNING_PREPARED,
        self::SIGNING_OUT_FOR_SIGNING,
        self::SIGNING_AWAITING_AGENT_REVIEW,
        self::SIGNING_SIGNED,
        self::SIGNING_DECLINED,
        self::SIGNING_VOIDED,
        self::SIGNING_EXPIRED,
        self::SIGNING_SIGNED_ON_PAPER,
    ];

    // leases.source — how the lease came to exist (§2, §15.5/§15.6.5).
    public const SOURCE_ESIGN_DOCUMENT = 'esign_document';
    public const SOURCE_UPLOADED_SIGNED_COPY = 'uploaded_signed_copy';

    /** §15.13 — shown on the edit panel and returned by update() while isLockedForSigning(). */
    public const LOCKED_FOR_SIGNING_MESSAGE = 'This agreement is out for signing. Change values in the agreement — CoreX will ask you to confirm them at approval.';
    // LEASE-AGREEMENT END

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
        'archived_by_user_id',
        'archive_reason',
        'archived_from_status',
        'migrated_from_table',
        'migrated_from_id',
        // rental-takeon-import.md §6 — read-only, display-only facts about
        // the tenancy as it stood on the day it was taken on. Never written
        // by any other flow.
        'migrated_escalation_percent',
        'migrated_next_escalation_date',
        'migrated_opening_arrears',
        'migrated_last_inspection_date',
        'notice_date',
        'notice_given_by',
        'notice_note',
        'move_out_date',
        'notice_outcome',
        'renewal_draft_flow_id',
        // LEASE-AGREEMENT BEGIN (leases.md §15.10 M2 — Build L1)
        'signing_status',
        'signing_flow_id',
        'signature_template_id',
        'agreement_document_id',
        'agreement_template_id',
        'signed_at',
        'accepted_at',
        'accepted_by_user_id',
        'agreement_confirmed_fingerprint',
        'agreement_confirmed_at',
        'agreement_confirmed_by_user_id',
        'capture_key',
        'signing_failure_note',
        // LEASE-AGREEMENT END
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
        'migrated_escalation_percent' => 'decimal:2',
        'migrated_next_escalation_date' => 'date',
        'migrated_opening_arrears' => 'decimal:2',
        'migrated_last_inspection_date' => 'date',
        // LEASE-AGREEMENT BEGIN (leases.md §15.10 M2 — Build L1)
        'signed_at' => 'datetime',
        'accepted_at' => 'datetime',
        'agreement_confirmed_at' => 'datetime',
        // LEASE-AGREEMENT END
    ];

    /**
     * Deleted-related-record rule (.ai/BUILD_STANDARD.md §4): a lease
     * outlives its property's own soft-delete (e.g. a landlord's property
     * record is archived while historic leases on it stay on file).
     * ->withTrashed() keeps $lease->property resolving to the archived
     * Property instead of silently going null, so every screen that
     * renders it can show "(archived)" instead of 500ing on a route()
     * call with a null model. Mirrors the existing
     * RentalWorkOrder::property() precedent.
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Same reasoning as property() above; RentalApplication is soft-deletable. */
    public function rentalApplication(): BelongsTo
    {
        return $this->belongsTo(RentalApplication::class)->withTrashed();
    }

    /** Same reasoning as property() above. */
    public function previousLease(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_lease_id')->withTrashed();
    }

    /** Same reasoning as property() above. */
    public function renewedLease(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewed_lease_id')->withTrashed();
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

    /**
     * rental-renewals.md §20 — a renewal draft the agent explicitly
     * cancelled for this term. Kept as its own distinct state from an
     * active draft so the tenancy log and any future lookup can tell "never
     * started" apart from "started, then called off" — only an agent
     * explicitly using "Renew lease" creates another one for this term.
     */
    public function cancelledRenewalDrafts(): HasMany
    {
        return $this->hasMany(self::class, 'previous_lease_id')->where('status', self::STATUS_CANCELLED);
    }

    public function hasCancelledRenewalDraft(): bool
    {
        return $this->cancelledRenewalDrafts()->exists();
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(LeaseTenant::class);
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(LeaseEscalation::class)->orderByDesc('effective_date');
    }

    // LEASE-AGREEMENT BEGIN (leases.md §15.10 — Build L1)
    /**
     * What this lease's agreement says beyond rent and dates (leases.md §15.7.2). Not withTrashed —
     * a soft-deleted terms row is "no terms"; LeaseAgreementTerms::forLease() restores it on write.
     */
    public function agreementTerms(): HasOne
    {
        return $this->hasOne(LeaseAgreementTerms::class);
    }

    /** The lease agreement template (docuperfect_templates) this lease's document was made from. */
    public function agreementTemplate(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Docuperfect\Template::class, 'agreement_template_id');
    }

    /** The e-sign flow the launcher created for this lease (renewal drafts mirror it in renewal_draft_flow_id). */
    public function signingFlow(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Docuperfect\Flow::class, 'signing_flow_id');
    }

    /** The e-sign envelope (signature_templates row) of this lease's agreement. */
    public function signatureTemplate(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Docuperfect\SignatureTemplate::class, 'signature_template_id');
    }

    /** The in-flight (or signed) agreement document — kept apart from source_document_id until completion. */
    public function agreementDocument(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Docuperfect\Document::class, 'agreement_document_id');
    }

    public function acceptedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    /**
     * §15.11 — the one accessor for "the signed copy of this lease": the filed e-sign PDF
     * (documents.source_type='esign', source_id = the envelope), falling back to a signed paper copy
     * (source_type='lease', source_id = this lease). Null when there is neither.
     */
    public function signedDocument(): ?\App\Models\Document
    {
        if ($this->signature_template_id) {
            $filed = \App\Models\Document::where('source_type', 'esign')
                ->where('source_id', $this->signature_template_id)
                ->latest('id')
                ->first();
            if ($filed) {
                return $filed;
            }
        }

        return \App\Models\Document::where('source_type', 'lease')
            ->where('source_id', $this->id)
            ->latest('id')
            ->first();
    }

    /**
     * §15.13 — what the Leases list's "Agreement" filter, the status sub-label and the export call each
     * signing state, in plain words. `not_sent` is a real state ("no agreement requested") but is never
     * shown as a sub-label — most leases are plain leases.
     */
    public const SIGNING_LABELS = [
        self::SIGNING_NOT_SENT => 'Not sent',
        self::SIGNING_PREPARED => 'Being prepared',
        self::SIGNING_OUT_FOR_SIGNING => 'Out for signing',
        self::SIGNING_AWAITING_AGENT_REVIEW => 'Needs my approval',
        self::SIGNING_SIGNED => 'Signed',
        self::SIGNING_SIGNED_ON_PAPER => 'Signed on paper',
        self::SIGNING_DECLINED => 'Declined',
        self::SIGNING_VOIDED => 'Voided',
        self::SIGNING_EXPIRED => 'Expired',
    ];

    /** The sub-label shown under a lease's status badge, or null for a lease with no agreement requested. */
    public function signingStatusLabel(): ?string
    {
        $status = (string) ($this->signing_status ?? self::SIGNING_NOT_SENT);

        return $status === self::SIGNING_NOT_SENT ? null : (self::SIGNING_LABELS[$status] ?? null);
    }

    /**
     * §15.13 — while the agreement is out for signing or waiting for the agent's approval, the lease's
     * agreement-governed fields change in the agreement, not here (one place to change a value).
     */
    public function isLockedForSigning(): bool
    {
        return in_array($this->signing_status, [
            self::SIGNING_OUT_FOR_SIGNING,
            self::SIGNING_AWAITING_AGENT_REVIEW,
        ], true);
    }

    /** The signing states in which the agreement is still live — the only ones the e-sign engine may move. */
    public const SIGNING_IN_FLIGHT = [
        self::SIGNING_PREPARED,
        self::SIGNING_OUT_FOR_SIGNING,
        self::SIGNING_AWAITING_AGENT_REVIEW,
    ];

    /**
     * §15.5 — what the e-sign engine's own status for an envelope means for the lease, in one place (the listener
     * and the safety-net re-check both use it).
     *
     *   draft / ready / signing / awaiting_* / partial / deferred / revived → out_for_signing
     *   pending_agent_approval and every returned / amendment state        → awaiting_agent_review
     *   completed                                                           → signed ONLY once finalisation has
     *                                                                         succeeded (the signed copy is filed);
     *                                                                         until then the agent's approval is
     *                                                                         still the last thing that happened
     *   declined, rejected                                                  → declined
     *   cancelled                                                           → voided
     *   expired, lapsed, re_lapsed, extension_proposed                      → expired
     */
    public static function signingStatusFor(\App\Models\Docuperfect\SignatureTemplate $envelope): string
    {
        $status = (string) $envelope->status;
        $envelopeModel = \App\Models\Docuperfect\SignatureTemplate::class;

        return match (true) {
            $status === $envelopeModel::STATUS_COMPLETED
                => $envelope->finalization_status === $envelopeModel::FINALIZATION_SUCCEEDED
                    ? self::SIGNING_SIGNED
                    : self::SIGNING_AWAITING_AGENT_REVIEW,
            in_array($status, [
                $envelopeModel::STATUS_PENDING_AGENT_APPROVAL,
                $envelopeModel::STATUS_RETURNED_TO_CANDIDATE,
                $envelopeModel::STATUS_AMENDMENT_REVIEW,
                $envelopeModel::STATUS_AMENDMENT_INITIALING,
                $envelopeModel::STATUS_AMENDMENT_CHAIN_REVIEW,
                $envelopeModel::STATUS_EDITOR_REACCEPTANCE,
            ], true) => self::SIGNING_AWAITING_AGENT_REVIEW,
            in_array($status, [$envelopeModel::STATUS_DECLINED, $envelopeModel::STATUS_REJECTED], true) => self::SIGNING_DECLINED,
            $status === $envelopeModel::STATUS_CANCELLED => self::SIGNING_VOIDED,
            in_array($status, [
                $envelopeModel::STATUS_EXPIRED,
                $envelopeModel::STATUS_LAPSED,
                $envelopeModel::STATUS_RE_LAPSED,
                $envelopeModel::STATUS_EXTENSION_PROPOSED,
            ], true) => self::SIGNING_EXPIRED,
            default => self::SIGNING_OUT_FOR_SIGNING,
        };
    }

    /**
     * §15.15 safety net — re-reads the envelope and brings this lease in step with it, whatever the engine did or
     * failed to announce. Called when the Lease Hub, the Leases list or the Command Centre loads a lease whose
     * agreement is in flight, and by the nightly `leases:reconcile-signing`. Never throws; returns whether anything
     * changed.
     */
    public function reconcileSigning(): bool
    {
        return app(\App\Services\Rentals\LeaseSigningStateService::class)->reconcile($this);
    }
    // LEASE-AGREEMENT END

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

    /** rental-work-orders.md §14.29 — job cards raised against this tenancy (rental_job_cards.lease_id). */
    public function jobCards(): HasMany
    {
        return $this->hasMany(RentalJobCard::class);
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

        // Leases list (leases.md §7) eager-loads 'property.contacts' and
        // calls this per row — Property::contactsForRole() always issues a
        // fresh query via $this->contacts()->get() regardless of what's
        // eager-loaded, so it would N+1 on a paginated list. Reuse the
        // already-loaded collection when present; Property.php itself is
        // untouched (shared with sales-side callers) — the identical
        // pivot-role matching (Property::pivotRolesForContactRole()) is
        // replicated here, scoped to this one call site.
        $contacts = $this->property->relationLoaded('contacts')
            ? $this->property->contacts
            : $this->property->contacts()->get();

        $matchRole = fn (array $roles) => $contacts->filter(function ($c) use ($roles) {
            $role = strtolower(trim((string) ($c->pivot->role ?? '')));
            return in_array($role, $roles, true);
        })->values();

        $landlords = $matchRole(['landlord'])->merge($matchRole(['lessor']))->unique('id')->values();

        if ($landlords->isNotEmpty()) {
            return $landlords;
        }

        return $matchRole(['seller', 'owner']);
    }

    /** Leases list (leases.md §7) — same shape as tenantNames(), for the landlord column + export. */
    public function landlordNames(): string
    {
        $names = $this->landlordContacts()->map(fn (Contact $c) => $c->full_name)->filter();

        return $names->isEmpty() ? 'No landlord linked' : $names->implode(', ');
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
        $scope = \App\Services\Rentals\RentalDataScope::resolve($user, 'leases', $requestedScope);

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
