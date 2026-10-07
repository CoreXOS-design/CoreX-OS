<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Compliance\FicaOfficerAppointment;
use App\Models\Concerns\BelongsToBranch;
use App\Support\Compliance\FicaOwnReviewContext;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;

#[ObservedBy([\App\Observers\FicaSubmissionObserver::class])]
class FicaSubmission extends Model
{
    use SoftDeletes, BelongsToAgency, BelongsToBranch;

    protected $fillable = [
        'contact_id',
        'agency_id',
        'branch_id',
        'requested_by',
        'token',
        'token_expires_at',
        'entity_type',
        'form_data',
        'status',
        'risk_rating',
        'verification_method',
        'verified_by',
        'verified_at',
        'reviewer_notes',
        'pdf_path',
        'signature_data',
        'signed_at',
        // Agent verification
        'agent_verified_by',
        'agent_verified_at',
        'agent_verification_data',
        'agent_notes',
        // Compliance officer verification
        'co_verified_by',
        'co_verified_at',
        'co_verification_data',
        'co_notes',
        'co_signature_data',
        // AT-236 Refer-to-CO
        'referred_by',
        'referred_at',
        'referral_note',
        // Wet-ink intake
        'intake_type',
        'wet_ink_received_date',
        'wet_ink_confirmed_by',
        // FICA validity
        'fica_expires_at',
    ];

    protected $casts = [
        'form_data'                => 'array',
        'verification_method'      => 'array',
        'agent_verification_data'  => 'array',
        'co_verification_data'     => 'array',
        'token_expires_at'         => 'datetime',
        'verified_at'              => 'datetime',
        'signed_at'                => 'datetime',
        'agent_verified_at'        => 'datetime',
        'co_verified_at'           => 'datetime',
        'referred_at'              => 'datetime',
        'risk_rating'              => 'integer',
        'wet_ink_received_date'    => 'date',
        'fica_expires_at'          => 'date',
    ];

    // ── Relationships ──

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function tfsScreenings(): HasMany
    {
        return $this->hasMany(\App\Models\Compliance\FicaTfsScreening::class, 'fica_submission_id');
    }

    /** The most recent TFS screening (the one the approval gate consults). */
    public function latestTfsScreening(): ?\App\Models\Compliance\FicaTfsScreening
    {
        return $this->tfsScreenings()->latest('screened_at')->latest('id')->first();
    }

    /**
     * Does the latest TFS screening ALLOW the approve flow to continue?
     * Non-blocking except for a TIER 3 exact-ID match (a locked screening): true unless the
     * latest screening is locked. A name review (Tier 2) is amber but does NOT block; no
     * screening => not blocking.
     */
    public function tfsGateCleared(): bool
    {
        $s = $this->latestTfsScreening();
        return ! ($s && $s->blocksApproval());
    }

    /** True once a TFS screening has run to a definite result (checklist tick). */
    public function tfsScreeningRan(): bool
    {
        return (bool) $this->latestTfsScreening()?->ranSuccessfully();
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function agentVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_verified_by');
    }

    public function coVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'co_verified_by');
    }

    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(FicaStatusHistory::class, 'fica_submission_id')->latest('id');
    }

    public function wetInkConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'wet_ink_confirmed_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FicaDocument::class);
    }

    /**
     * AT-361 — contact documents LINKED (referenced) into this FICA process, NOT
     * copied. Points at the unified `documents` store (App\Models\Document, the
     * contact's Drive / splitter output) via the fica_submission_documents pivot.
     * The pivot's `document_type` records the FICA slot the contact doc stands in
     * for (fica_form | id_copy | proof_of_address | supporting). These surface in
     * the RO/CO review alongside the uploaded FicaDocuments for approval.
     */
    public function linkedDocuments(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Document::class, 'fica_submission_documents')
            ->withPivot(['document_type', 'linked_by'])
            ->withTimestamps()
            ->latest('fica_submission_documents.created_at');
    }

    public function resendLogs(): HasMany
    {
        return $this->hasMany(FicaResendLog::class);
    }

    // ── Scopes ──

    /**
     * AT-346 — resolve the per-user FICA visibility tier: 'own' | 'branch' | 'all'.
     *
     * Mirrors the Contacts/Properties pattern (PermissionService::getDataScope +
     * the Role Manager `fica.view` scope), with ONE structural elevator: an
     * owner-role user or an appointed Compliance Officer / Responsible Officer
     * reviews the whole agency's FICA by definition, so they always resolve to
     * 'all' and the CO review station is never narrowed.
     *
     * NOTE (AT-346 design decision — flag for Johan): `manage_compliance` is an
     * ACTION permission ("Manage Compliance Records"), NOT a visibility elevator.
     * It deliberately does NOT force 'all' here — otherwise every user who can
     * reach the FICA page (the route is gated by `access_compliance`, which branch
     * managers hold alongside `manage_compliance`) would see everything and the
     * whole own/branch/company tier would be a no-op. Visibility breadth is now
     * driven purely by the role's `fica.view` scope: admin='all', branch_manager=
     * 'branch', agent='own' (scope_defaults), defaulting to the most restrictive
     * 'own' when no wider scope is granted. A custom compliance role that needs
     * agency-wide sight is granted `fica.view = all` in Role Manager.
     */
    public static function ficaScopeFor(User $user): string
    {
        if ($user->isOwnerRole() || $user->isComplianceOfficer($user->effectiveAgencyId())) {
            return 'all';
        }

        return PermissionService::getDataScope($user, 'fica') ?? 'own';
    }

    /**
     * AT-346 — constrain a FICA query to the records the given user may see.
     * 'own'    → only submissions they requested (fica_submissions.requested_by)
     * 'branch' → their branch (fica_submissions.branch_id vs effectiveBranchId)
     * 'all'    → agency-wide (AgencyScope already bounds the tenant)
     * A user with no resolvable scope/branch is shown nothing (1 = 0), never all.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $scope = static::ficaScopeFor($user);

        if ($scope === 'all') {
            return $query;
        }

        if ($scope === 'branch') {
            $branchId = $user->effectiveBranchId();

            return $branchId
                ? $query->where($this->getTable() . '.branch_id', $branchId)
                : $query->whereRaw('1 = 0');
        }

        // 'own' (and the safe default for any unexpected value)
        return $query->where($this->getTable() . '.requested_by', $user->id);
    }

    // ── Own-FICA separation (review blocked at the START, not only at final approval) ──

    /**
     * The users this submission is "own work" of: whoever requested it and whoever did
     * the stage-1 agent approval — the same set the end-of-flow guard (AT-236) uses.
     *
     * There is deliberately no third "the FICA is about this user" source: the data model
     * has no link between a contact and a staff user (`contacts.client_user_id` points at
     * the separate `client_users` portal table, not `users`).
     *
     * @return int[]
     */
    public function ownWorkUserIds(): array
    {
        return array_values(array_unique(array_filter([
            (int) $this->requested_by,
            (int) $this->agent_verified_by,
        ])));
    }

    public function isOwnWorkOf(User $user): bool
    {
        return in_array((int) $user->id, $this->ownWorkUserIds(), true);
    }

    /** Stage 1 - the first check of a returned form. Open to any user with compliance access, not only officers. */
    public const STAGE_ONE_STATUSES = ['submitted', 'under_review', 'corrections_requested'];

    /**
     * The statuses in which an own-FICA block can actually bite on a review / mark-up
     * action (stage 1, the RO queue, a referred pack). On a finished or not-yet-sent
     * record (approved / rejected / cancelled / draft) there is nothing to review, so the
     * screens show no "your own FICA" notice and the audit ledger is not written to.
     * A rejected record is the one other place the rule applies - the Reopen action - which
     * is handled by ownReopenBlockFor().
     */
    public const OWN_REVIEW_STATUSES = ['submitted', 'under_review', 'corrections_requested', 'agent_approved', 'referred_to_co'];

    /** Is this submission in a status where a review / mark-up action exists to be blocked? */
    public function isInOwnReviewState(): bool
    {
        return in_array($this->status, self::OWN_REVIEW_STATUSES, true);
    }

    /**
     * Why this user may not review/mark up this submission - null when they may.
     * Status-blind on purpose: this is the SERVER rule, evaluated at the start of every
     * review route. Screens use ownReviewNoticeFor()/ownReopenBlockFor() so they only talk
     * about it while there is something to review.
     *
     * Same rule as the end-of-flow guard in FicaController::complianceApprove (AT-236):
     * an appointed officer (RO/MLRO) may not review their own FICA; the PRIMARY
     * Compliance Officer is the one exception. Everyone else (an agent doing the stage-1
     * check on a FICA they sent, an admin who is not an officer) is unaffected - stage-1
     * work on their own request is the normal flow; the officer steps are what stay
     * separate.
     *
     * Pass a FicaOwnReviewContext when asking about many rows (list, contact tab) so the
     * officer lookups happen once per agency, not once per row.
     */
    public function ownReviewBlockFor(User $user, ?FicaOwnReviewContext $ctx = null): ?string
    {
        $agencyId = (int) $this->agency_id;
        $ctx ??= new FicaOwnReviewContext($user);

        if (! $this->isOwnWorkOf($user)
            || ! $ctx->isOfficer($agencyId)
            || $ctx->isPrimary($agencyId)) {
            return null;
        }

        // Stage 1 is open to any user with compliance access, so "nobody else can do it" is
        // never true here - never claim it, whatever the officer roster looks like.
        if (in_array($this->status, self::STAGE_ONE_STATUSES, true)) {
            return 'You cannot approve your own FICA - another Responsible Officer or the Compliance Officer must review it, '
                . 'or any other user with compliance access can do this first check.';
        }

        // A referred pack is decided only at the referral station (the recipient or the primary CO).
        if ($this->status === 'referred_to_co') {
            if ($this->hasOtherEligibleReviewer($user, $ctx)) {
                return 'You cannot approve your own FICA - this pack was referred, so only the Compliance Officer it was referred to '
                    . '(or the Primary Compliance Officer) can decide it.';
            }

            return 'You cannot approve your own FICA, and there is no other Compliance Officer able to decide this referred pack. '
                . 'An administrator must appoint one under Company Settings → Compliance Officers.';
        }

        if ($this->hasOtherEligibleReviewer($user, $ctx)) {
            return 'You cannot approve your own FICA - another Responsible Officer or the Compliance Officer must review it.';
        }

        return 'You cannot approve your own FICA, and there is no other Responsible Officer or Compliance Officer in your agency to review it. '
            . 'An administrator must appoint one under Company Settings → Compliance Officers.';
    }

    /** The block for the "this is your own FICA" notice and review buttons - only while there is something to review. */
    public function ownReviewNoticeFor(User $user, ?FicaOwnReviewContext $ctx = null): ?string
    {
        return $this->isInOwnReviewState() ? $this->ownReviewBlockFor($user, $ctx) : null;
    }

    /** The block for the Reopen-for-Corrections action - only on a rejected record, the only place it exists. */
    public function ownReopenBlockFor(User $user, ?FicaOwnReviewContext $ctx = null): ?string
    {
        return $this->status === 'rejected' ? $this->ownReviewBlockFor($user, $ctx) : null;
    }

    /**
     * Is there an active officer other than $user who may decide this submission?
     * The primary CO always may; any other officer only if it is not their own work too.
     * For a referred pack only the referral station counts: the primary CO, or the
     * resolved recipient (FicaReferralService::isReferralStationOwner) when it is not
     * also their own work.
     */
    public function hasOtherEligibleReviewer(User $user, ?FicaOwnReviewContext $ctx = null): bool
    {
        $agencyId = (int) $this->agency_id;
        $ctx ??= new FicaOwnReviewContext($user);
        $ownIds = $this->ownWorkUserIds();
        $others = $ctx->appointments($agencyId)->filter(fn ($a) => (int) $a->user_id !== (int) $user->id);

        if ($this->status === 'referred_to_co') {
            $recipientId = $ctx->referralRecipientId($agencyId);

            return $others->contains(fn ($a) => $a->role === FicaOfficerAppointment::ROLE_PRIMARY)
                || ($recipientId !== null
                    && $recipientId !== (int) $user->id
                    && ! in_array($recipientId, $ownIds, true)
                    && $others->contains(fn ($a) => (int) $a->user_id === $recipientId));
        }

        return $others->contains(fn ($a) => $a->role === FicaOfficerAppointment::ROLE_PRIMARY
            || ! in_array((int) $a->user_id, $ownIds, true));
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', ['draft', 'submitted']);
    }

    public function scopeSubmitted(Builder $query): Builder
    {
        return $query->where('status', 'submitted');
    }

    public function scopeAgentApproved(Builder $query): Builder
    {
        return $query->where('status', 'agent_approved');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    /**
     * Excludes soft-deleted rows — the shared filter for "does this row reflect a
     * REAL, current compliance action?". Works on both an Eloquent Builder and a
     * raw \Illuminate\Database\Query\Builder (DB::table('fica_submissions')),
     * which is why it's a plain static helper rather than an Eloquent local scope
     * — most call sites on this table are raw query-builder calls, not Eloquent,
     * and never benefited from the automatic SoftDeletes exclusion.
     *
     * Universal rule (Johan): the gate is contact-agnostic — a valid, non-deleted
     * 'approved' row counts, same rule for every contact, imported or not. No
     * special-casing by source/origin here; imported contacts instead simply
     * never GET an auto-created 'approved' row in the first place (see
     * ContactImportController — the import path no longer auto-marks FICA).
     *
     * Every call site that decides "is this contact FICA-compliant" or displays a
     * contact's current FICA status MUST route through this (or
     * applyGenuineApprovalFilter()) — a bare `->where('status', 'approved')` on a
     * raw query builder silently counts soft-deleted rows.
     */
    public static function applyGenuineRecordFilter($query)
    {
        return $query->whereNull('deleted_at');
    }

    /** applyGenuineRecordFilter() + status='approved' — the authoritative "is this contact FICA-approved" filter. */
    public static function applyGenuineApprovalFilter($query)
    {
        return static::applyGenuineRecordFilter($query)->where('status', 'approved');
    }

    // ── Helpers ──

    public function isWetInk(): bool
    {
        return $this->intake_type === 'wet_ink';
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }

    /**
     * Alias for isTokenExpired() — preserves backward compatibility.
     * For FICA validity expiry, use isFicaExpired() instead.
     */
    public function isExpired(): bool
    {
        return $this->isTokenExpired();
    }

    public function isFicaExpired(): bool
    {
        return $this->fica_expires_at && $this->fica_expires_at->isPast();
    }

    public function scopeExpiringSoon(Builder $query, int $days = 60): Builder
    {
        return $query->whereNotNull('fica_expires_at')
            ->whereBetween('fica_expires_at', [now(), now()->addDays($days)]);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('fica_expires_at')
            ->where('fica_expires_at', '<', now());
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }

    public function isAgentApproved(): bool
    {
        return $this->status === 'agent_approved';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isReferredToCo(): bool
    {
        return $this->status === 'referred_to_co';
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'draft'                 => 'Awaiting Client',
            'submitted'             => 'Awaiting Agent Review',
            'under_review'          => 'Under Review',
            'agent_approved'        => 'RO Approval Needed',
            'referred_to_co'        => 'CO Approval Needed',
            'corrections_requested' => 'Corrections Needed',
            'approved'              => 'Approved',
            'rejected'              => 'Rejected',
            'cancelled'             => 'Cancelled',
            default                 => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'draft'                 => 'slate',
            'submitted'             => 'blue',
            'under_review'          => 'blue',
            'agent_approved'        => 'amber',
            'referred_to_co'        => 'amber',
            'corrections_requested' => 'orange',
            'approved'              => 'green',
            'rejected'              => 'red',
            'cancelled'             => 'slate',
            default                 => 'slate',
        };
    }
}
