<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Services\PermissionService;
use App\Support\SaPhoneNumber;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, BelongsToAgency, BelongsToBranch;

    /** Per-instance memo of branch_id → agency_id (see effectiveAgencyId — AgencyScope N+1). */
    private array $branchAgencyMemo = [];

    /**
     * Per-instance memo, keyed by session active_agency_id override value, of
     * whether that override still matches this (non-owner) user's CURRENT
     * membership. See effectiveAgencyId() — AgencyScope calls it on every
     * scoped query, so the membership re-check must not run more than once
     * per request per user instance.
     */
    private array $overrideAgencyMembershipMemo = [];

    /**
     * Per-instance memo: is this user an owner role? Computed at most once by
     * effectiveAgencyId() (via $resolvingOwnerForAgencyOverride below) to
     * decide whether the session active_agency_id override should be trusted
     * unconditionally.
     */
    private ?bool $isOwnerRoleForAgencyOverrideMemo = null;

    /**
     * Re-entrancy guard for the isOwnerRole() call inside effectiveAgencyId().
     * isOwnerRole() itself calls effectiveAgencyId() (to disambiguate
     * same-named roles across agencies), so effectiveAgencyId() calling
     * isOwnerRole() would recurse infinitely without this. While set, the
     * nested effectiveAgencyId() call skips the override-trust check entirely
     * and falls straight through to the branch/agency_id resolution below —
     * safe because owner roles are global (agency_id NULL) and
     * Role::allRoles() finds them regardless of which agency id is passed in
     * (see isOwnerRole()'s docblock), so the nested call's return value can't
     * change the is_owner outcome.
     */
    private bool $resolvingOwnerForAgencyOverride = false;

    /**
     * Per-instance memo, keyed by session view_as_branch_id override value, of
     * whether that override is still authorized under the user's CURRENT
     * permissions/membership. See effectiveBranchId() — BranchScope calls it
     * on every scoped query, so the re-check must not run more than once per
     * request per user instance.
     */
    private array $overrideBranchAuthMemo = [];

    /**
     * Re-entrancy guard for the hasPermission('branches.view_all') call inside
     * effectiveBranchId(). That call chain is
     * hasPermission -> PermissionService::userHasPermission -> isOwnerRole()
     * -> effectiveAgencyId() -> (branch-derive fallback) -> effectiveBranchId()
     * again — a guaranteed loop without this, and NOT limited to an edge
     * case: effectiveAgencyId()'s branch-derive fallback runs on essentially
     * every call that isn't itself short-circuited by a matching
     * active_agency_id override. While set, the nested effectiveBranchId()
     * call skips the override re-validation entirely and returns the raw
     * $this->branch_id — safe because BranchSwitcherController::switch()
     * only ever allows switching to a branch within the caller's OWN
     * effective agency, so the override branch and the user's raw branch_id
     * always resolve to the same agency_id; the nested call's return value
     * can't change the permission lookup's agency context.
     */
    private bool $resolvingBranchOverride = false;

    protected $fillable = [
        'name',
        'email',
        // Optional OUTWARD-FACING email override. When set, seller/client/
        // public surfaces show this instead of the login `email`; auth/login
        // always use the real `email`. Interim for multi-login users (AT-80).
        'display_email',
        'password',
        'qr_code_slug',
        'qr_reroute_user_id',
        'role',
        // AT-267 — the assistant resolver hook. NOT a role: an assistant's permissions
        // come entirely from their assignment matrix. The flag exists because `role`
        // defaults to 'agent', so a user created without an explicit role IS a full
        // agent — the resolver must identify an assistant without trusting `role`.
        'is_assistant',
        // AT-267 — gates the Compliance tab on My Portal for assistants. Unrelated to
        // signature_requests.fica_required (the per-recipient e-sign gate).
        'fica_required',
        // AT-267 — a per-assistant display title ("PA", "Receptionist", …). A LABEL
        // only: `role` stays pinned to 'assistant'. Null falls back to "Assistant".
        'assistant_title',
        'designation',
        'supervised_by',
        'branch_id',
        'agency_id',
        'is_active',
        // AT — was write-only via mass assignment (User::create(['invited_at' =>
        // ...])) without being fillable, so it silently never persisted. Only a
        // cast existed before this. No functional gating currently reads it back
        // (the invite-pending gate is email_verified_at via isPendingInvite()) —
        // this fixes it to actually record what it claims to.
        'invited_at',
        'show_on_website',
        // Per-agent opt-out from Property24 — hides the agent on the P24 portal
        // and keeps them off syndicated listings. See Property24SyndicationService.
        'exclude_from_p24',
        'website_order',

        // Admin-controlled commission defaults
        'agent_cut_percent',
        'paye_method',
        'paye_value',

        // Sliding scale (per-agent)
        'sliding_enabled',
        'sliding_tier1_cut_percent',
        'sliding_tier2_cut_percent',
        'sliding_tier3_cut_percent',

        // Agent document uploads
        'agent_photo_path',
        'ffc_certificate_path',
        'id_document_path',
        'pi_insurance_path',
        'tax_clearance_path',

        // Flags
        'can_capture_rentals',
        'counts_for_branch_split',

        // Contact fields (email signatures, profile, presentations)
        'phone',
        'cell',
        'fax',
        'ffc_number',
        'ffc_expiry_date',
        'id_number',
        'ppra_status',
        'pi_insurance_expiry',
        'tax_clearance_expiry',
        'website',
        // Public agent profile (My Portal → Profile, shown on agency websites).
        'about_me',
        'website_social_facebook',
        'website_social_instagram',
        'website_social_linkedin',
        'website_social_youtube',
        'theme',
        'last_presentation_send_channel',
        'last_presentation_send_mode',
        'portal_show_api_token',
        'portal_show_social_accounts',

        // Private Property integration
        'pp_unique_agent_id',
        'pp_external_ref',
        'pp_exclusivity_explainer_seen_at',

        // Property24 importer
        'p24_agent_id',
        // What P24 currently holds for this agent — the agent-side equivalent of
        // properties.p24_image_signature. An unchanged agent costs zero P24 calls
        // on a listing refresh. See Property24SyndicationService::syncAgentIfChanged.
        'p24_agent_agency_id',
        'p24_profile_signature',
        'p24_photo_signature',
        'source_reference',

        // Employee screening
        'risk_tier',
        'screening_status',
        'screening_due_on',

        // Payroll
        'date_of_birth',
        'tax_reference_number',
        'employment_date',

        // Leave / Take-On
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
        'next_of_kin_name',
        'next_of_kin_phone',
        'next_of_kin_relationship',
        'home_address',
        'marital_status',
        'dependents_count',
        'medical_aid_provider',
        'medical_aid_number',
        'medical_aid_main_member',
        'medical_aid_dependents_count',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'api_token',
    ];

    /**
     * Outward-facing email address.
     *
     * Returns the optional `display_email` override when set, otherwise the
     * real login `email`. Use this on EVERY seller / client / public-facing
     * surface (presentation/CMA PDF + seller pages, e-sign documents, seller
     * outreach, mailable From/Reply-To/footer, portal feeds, agent public
     * profile). NEVER use it for auth, login, password reset, invitations, or
     * internal staff screens — those use `->email` (the credential / true
     * routing address).
     *
     * `display_email` is null for everyone by default, so behaviour is
     * unchanged unless an admin explicitly sets it. Interim mechanism for a
     * user operating multiple branch logins until proper multi-branch user
     * support lands (AT-80).
     */
    protected function outwardEmail(): Attribute
    {
        return Attribute::make(
            get: fn () => filled($this->display_email) ? $this->display_email : $this->email,
        );
    }

    protected $casts = [
        'email_verified_at' => 'datetime',
        'invited_at' => 'datetime',
        'first_login_at' => 'datetime',
        'pp_exclusivity_explainer_seen_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'is_assistant' => 'boolean',
        'fica_required' => 'boolean',
        'show_on_website' => 'boolean',
        'exclude_from_p24' => 'boolean',
        'website_order' => 'integer',

        'agent_cut_percent' => 'decimal:2',
        'paye_value' => 'decimal:2',

        'sliding_enabled' => 'boolean',
        'portal_show_api_token' => 'boolean',
        'portal_show_social_accounts' => 'boolean',
        'sliding_tier1_cut_percent' => 'decimal:2',
        'sliding_tier2_cut_percent' => 'decimal:2',
        'sliding_tier3_cut_percent' => 'decimal:2',

        'ffc_expiry_date' => 'date',
        'pi_insurance_expiry' => 'date',
        'tax_clearance_expiry' => 'date',
        'date_of_birth' => 'date',
        'employment_date' => 'date',
        'medical_aid_main_member' => 'boolean',
        'dependents_count' => 'integer',
        'medical_aid_dependents_count' => 'integer',
    ];

    /**
     * Canonicalise SA phone numbers on write so every entry path — admin forms,
     * profile updates, imports, console, Tinker — stores the digits-only,
     * leading-zero format Private Property requires. A value typed as
     * "076 901 7397" is stored as "0769017397", preventing PP107 format
     * rejections downstream. See App\Support\SaPhoneNumber.
     */
    public function setPhoneAttribute($value): void
    {
        $this->attributes['phone'] = SaPhoneNumber::normalize($value === null ? null : (string) $value);
    }

    public function setCellAttribute($value): void
    {
        $this->attributes['cell'] = SaPhoneNumber::normalize($value === null ? null : (string) $value);
    }

    public function setFaxAttribute($value): void
    {
        $this->attributes['fax'] = SaPhoneNumber::normalize($value === null ? null : (string) $value);
    }

    /**
     * Agency Admin Rule — every agency must keep ≥1 active Admin at all times.
     * See .ai/specs/agency-admin-rule.md. Enforced structurally so any path
     * (controller, console, queue, manual Tinker) cannot leave an agency
     * adminless.
     */
    protected static function booted(): void
    {
        // AT-267 (audit 2026-07-21) — pin an assistant's identity. The assistant's authority is
        // matrix ∩ agent, resolved through AssistantPermissionResolver, but ~20 sites still read
        // users.role / is_admin DIRECTLY (bypassing the resolver). If an assistant's role ever drifted
        // to admin (e.g. an admin editing them in User Management), they would silently gain
        // agency-wide authority far beyond their agent. An assistant is ALWAYS role='assistant' and
        // never an admin — enforced structurally here so no write path can escalate them.
        static::saving(function (self $user) {
            if ($user->is_assistant) {
                $user->role = 'assistant';
                $user->is_admin = false;
            }
        });

        // AT-267 E1 (audit 2026-07-21) — persist the assistant FREEZE when an agent is deactivated.
        // Fail-closed already holds (the resolver denies live on !$agent->is_active), but persisting
        // the assignment status gives a user-facing state and defence-in-depth. Reversible: the
        // auto-suspend is undone when the agent is reactivated (only the ones WE froze).
        static::updated(function (self $user) {
            if (! $user->wasChanged('is_active')) {
                return;
            }
            $assignments = \App\Models\AssistantAssignment::where('agent_user_id', $user->id);
            if (! $user->is_active) {
                (clone $assignments)->where('status', \App\Models\AssistantAssignment::STATUS_ACTIVE)
                    ->update(['status' => \App\Models\AssistantAssignment::STATUS_SUSPENDED, 'suspend_reason' => 'agent_deactivated']);
            } else {
                (clone $assignments)->where('status', \App\Models\AssistantAssignment::STATUS_SUSPENDED)
                    ->where('suspend_reason', 'agent_deactivated')
                    ->update(['status' => \App\Models\AssistantAssignment::STATUS_ACTIVE, 'suspend_reason' => null]);
            }
        });

        static::updating(function (self $user) {
            if (!$user->getOriginal('agency_id') || $user->getOriginal('role') !== 'admin') {
                return;
            }
            $demoting = $user->isDirty('role') && $user->role !== 'admin';
            $deactivating = $user->isDirty('is_active') && !$user->is_active;
            $movingAgency = $user->isDirty('agency_id');
            if (!($demoting || $deactivating || $movingAgency)) {
                return;
            }
            $count = static::query()
                ->where('agency_id', $user->getOriginal('agency_id'))
                ->where('role', 'admin')
                ->where('is_active', 1)
                ->where('id', '!=', $user->id)
                ->count();
            if ($count < 1) {
                throw \App\Exceptions\LastAdminException::forAgency(
                    (int) $user->getOriginal('agency_id'),
                    $demoting ? 'demote' : ($deactivating ? 'deactivate' : 'move')
                );
            }
        });

        static::deleting(function (self $user) {
            if ($user->role !== 'admin' || !$user->agency_id) {
                return;
            }
            // Soft-delete: only block if this is the LAST active admin for the agency.
            $count = static::query()
                ->where('agency_id', $user->agency_id)
                ->where('role', 'admin')
                ->where('is_active', 1)
                ->where('id', '!=', $user->id)
                ->count();
            if ($count < 1) {
                throw \App\Exceptions\LastAdminException::forAgency(
                    (int) $user->agency_id,
                    'delete'
                );
            }
        });
    }

    // --- View-As support (session override) ---

    public function effectiveRole(): string
    {
        $override = session('view_as_role');
        return $override ?: ($this->role ?? 'agent');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(UserDocument::class);
    }

    /** Articles the agent has authored for their public website profile. */
    public function articles(): HasMany
    {
        return $this->hasMany(AgentArticle::class, 'user_id');
    }

    /** Communication Archive mailboxes whose address belongs to this user (AT-37). */
    public function commMailboxes(): HasMany
    {
        return $this->hasMany(\App\Models\Communications\CommunicationMailbox::class, 'user_id');
    }

    public function verifiedDocuments(): HasMany
    {
        return $this->documents()->where('status', 'verified');
    }

    /**
     * Returns the public URL for the user's profile photo, or null if no valid file exists.
     * Checks user_documents (profile_photo type) first, then legacy agent_photo_path.
     */
    public function profilePhotoUrl(): ?string
    {
        // Priority: user_documents profile_photo
        $doc = $this->documents()
            ->where('document_type', 'profile_photo')
            ->latest()
            ->first();

        if ($doc && $doc->file_path && Storage::disk('public')->exists($doc->file_path)) {
            return asset('storage/' . $doc->file_path);
        }

        // Fallback: legacy agent_photo_path column
        if ($this->agent_photo_path && Storage::disk('public')->exists($this->agent_photo_path)) {
            return asset('storage/' . $this->agent_photo_path);
        }

        return null;
    }

    /**
     * The AI-segmented, background-removed cutout of this agent's profile
     * photo — a transparent PNG stored ALONGSIDE the original by
     * RemoveAgentPhotoBackgroundJob (§15.2). Returns null whenever no
     * cutout exists yet (never attempted, still processing, agency has the
     * feature disabled, or the API call failed) — every caller must treat
     * null as "fall back to profilePhotoUrl()'s plain photo", never as a
     * broken/missing avatar.
     */
    public function profilePhotoCutoutUrl(): ?string
    {
        $doc = $this->documents()
            ->where('document_type', 'profile_photo')
            ->latest()
            ->first();

        if ($doc
            && $doc->bg_removal_status === 'done'
            && $doc->bg_removal_cutout_path
            && Storage::disk('public')->exists($doc->bg_removal_cutout_path)
        ) {
            return asset('storage/' . $doc->bg_removal_cutout_path);
        }

        return null;
    }

    /**
     * Returns the user's initials (first + last name initial) for avatar placeholders.
     */
    public function initials(): string
    {
        $parts = explode(' ', trim($this->name ?? ''));
        $first = strtoupper(substr($parts[0] ?? '', 0, 1));
        $last = count($parts) > 1 ? strtoupper(substr(end($parts), 0, 1)) : '';
        return $first . $last;
    }

    /**
     * Branch switcher override (session view_as_branch_id).
     *
     * Mirrors effectiveAgencyId()'s active_agency_id handling exactly (see
     * that method's docblock for the full rationale). Authorization for this
     * session key happens ONCE, at write time, in
     * BranchSwitcherController::switch() — it is NOT re-validated on every
     * request, so if the user's `branches.view_all` permission is revoked, or
     * they are reassigned to a different branch, AFTER they switched, they
     * would otherwise stay scoped (for reads AND writes, via BelongsToBranch's
     * auto-fill) to the stale branch until they log out (the session key is
     * only cleared on Login/Logout or an explicit clear()). We close that gap
     * here by re-running the same authorization switch() does — see
     * branchOverrideStillAuthorized() below — before trusting the override.
     * If it no longer holds, we fall through to the normal resolution below
     * exactly as if no override were set, rather than returning either the
     * stale value or an unauthorized one.
     *
     * Memoized per-instance (overrideBranchAuthMemo) because BranchScope calls
     * this method on every scoped query — a hasPermission() re-check per call
     * would be a serious regression. See .ai/specs/multi-tenancy.md §3 and the
     * identical reasoning in effectiveAgencyId().
     */
    public function effectiveBranchId(): ?int
    {
        $override = session('view_as_branch_id');
        if ($override !== null && $override !== '' && ! $this->resolvingBranchOverride) {
            $key = (string) $override;
            if (! array_key_exists($key, $this->overrideBranchAuthMemo)) {
                $this->resolvingBranchOverride = true;
                try {
                    $this->overrideBranchAuthMemo[$key] = $this->branchOverrideStillAuthorized((int) $override);
                } finally {
                    $this->resolvingBranchOverride = false;
                }
            }

            if ($this->overrideBranchAuthMemo[$key]) {
                return (int) $override;
            }

            // Stale/unauthorized override: fall through to the normal
            // resolution below.
        }

        return $this->branch_id ? (int) $this->branch_id : null;
    }

    /**
     * Is the session view_as_branch_id override still authorized for this
     * user, right now?
     *
     * Mirrors BranchSwitcherController::switch() exactly: `branches.view_all`
     * authorizes any branch; without it, only a no-op switch to the user's
     * OWN `branch_id` is authorized. Used by effectiveBranchId() to
     * re-validate the override on every request, since switch() only
     * authorizes it once, at write time.
     *
     * Uses the raw `branch_id` column (not effectiveBranchId(), which is
     * itself session-derived) — this must reflect real, current DB state,
     * not another override. (switch() also re-checks the branch is within
     * the caller's effective agency, but that is unconditional for every
     * value view_as_branch_id can ever hold — switch() is the only writer of
     * this session key and already enforces it at write time — so it is not
     * re-checked here.)
     */
    private function branchOverrideStillAuthorized(int $branchId): bool
    {
        if ($this->hasPermission('branches.view_all')) {
            return true;
        }

        return (int) ($this->branch_id ?? 0) === $branchId;
    }

    // --- Admin Multi-Branch Manager (spec: admin-multi-branch-manager.md) ---
    //
    // An admin can MANAGE several branches and pick a login default. The
    // "current branch" they operate in is the existing branch-isolation
    // context (view_as_branch_id, via effectiveBranchId). They "act as" the
    // manager of whichever managed branch they are currently in. Because
    // admins hold branches.view_all, BranchScope is bypassed for them, so
    // being in a branch is CONTEXT only — it never hides another branch's data.

    /** Branches this user manages (the user_managed_branches pivot). */
    public function managedBranches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'user_managed_branches')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    /** The branch flagged as the login default, if any. Pivot-direct (scope-safe). */
    public function defaultManagedBranchId(): ?int
    {
        $id = \DB::table('user_managed_branches')
            ->where('user_id', $this->id)
            ->where('is_default', true)
            ->value('branch_id');

        return $id ? (int) $id : null;
    }

    /** True if this user is a self-assigned manager of the given branch. */
    public function isManagerOfBranch(int $branchId): bool
    {
        if ($branchId <= 0) {
            return false;
        }

        return \DB::table('user_managed_branches')
            ->where('user_id', $this->id)
            ->where('branch_id', $branchId)
            ->exists();
    }

    /**
     * The branch the user is currently acting as manager of: the branch they
     * are currently in (effectiveBranchId / view_as_branch_id), IF they manage
     * it. Returns null when they're in "all branches" or a branch they don't
     * manage — so deal-manager capture only fires in a managed branch context.
     */
    public function actingBranchManagerId(): ?int
    {
        $branchId = $this->effectiveBranchId();
        if ($branchId && $this->isManagerOfBranch((int) $branchId)) {
            return (int) $branchId;
        }

        return null;
    }

    /**
     * Rebuild this user's managed-branch set in one transaction. Shared by the
     * self-service profile panel and the admin user-edit screen.
     *
     * Only branches belonging to $agencyId are accepted (a forged / foreign
     * branch id is silently dropped). Exactly one row is flagged is_default;
     * if the chosen default isn't in the accepted set, the first accepted
     * branch becomes the default. Returns the accepted branch-id collection.
     *
     * $agencyId is passed explicitly (NOT read from effectiveAgencyId(), which
     * is session-scoped) so it stays correct when an admin edits another user.
     */
    public function syncManagedBranches(array $branchIds, ?int $defaultId, ?int $agencyId): \Illuminate\Support\Collection
    {
        $submitted = collect($branchIds)->map(fn ($v) => (int) $v)->unique();

        $validBranchIds = $agencyId
            ? Branch::where('agency_id', $agencyId)
                ->whereIn('id', $submitted->all())
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->values()
            : collect();

        $defaultId = (int) ($defaultId ?? 0);
        if (!$validBranchIds->contains($defaultId)) {
            $defaultId = (int) ($validBranchIds->first() ?? 0);
        }

        \DB::transaction(function () use ($validBranchIds, $defaultId, $agencyId) {
            \DB::table('user_managed_branches')->where('user_id', $this->id)->delete();

            $now  = now();
            $rows = $validBranchIds->map(fn ($bid) => [
                'user_id'    => $this->id,
                'branch_id'  => $bid,
                'agency_id'  => $agencyId,
                'is_default' => $bid === $defaultId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            if (!empty($rows)) {
                \DB::table('user_managed_branches')->insert($rows);
            }
        });

        return $validBranchIds;
    }

    public function effectiveAgencyId(): ?int
    {
        // Agency switcher override (session active_agency_id).
        //
        // Owners are trusted unconditionally — full cross-agency access is by
        // design (AgencySwitcherController::userCanSwitchTo() lets an owner
        // switch to ANY agency).
        //
        // For everyone else, the override is authorized ONCE, at write time,
        // in userCanSwitchTo() / AgencyAccessRequestController::confirmSwitch().
        // It is NOT re-validated on every request, so if a non-owner's own
        // membership changes AFTER they switched (e.g. an admin moves them to
        // a different branch/agency), they would otherwise stay scoped to the
        // stale agency until they log out (the session key is only cleared on
        // Login/Logout). We close that gap here by re-running the same
        // membership check userCanSwitchTo() does — see
        // membershipMatchesAgency() below — before trusting the override for a
        // non-owner. If it no longer matches, we fall through to the normal
        // resolution below exactly as if no override were set, rather than
        // returning either the stale value or an unauthorized one.
        //
        // Both checks are memoized per-instance (isOwnerRoleForAgencyOverrideMemo /
        // overrideAgencyMembershipMemo) because AgencyScope calls this method on
        // every scoped query — a DB lookup per call would be a serious
        // regression. See .ai/specs/multi-tenancy.md §3.
        $override = session('active_agency_id');
        if ($override !== null && $override !== '' && ! $this->resolvingOwnerForAgencyOverride) {
            if ($this->isOwnerRoleForAgencyOverrideMemo === null) {
                $this->resolvingOwnerForAgencyOverride = true;
                try {
                    $this->isOwnerRoleForAgencyOverrideMemo = $this->isOwnerRole();
                } finally {
                    $this->resolvingOwnerForAgencyOverride = false;
                }
            }

            if ($this->isOwnerRoleForAgencyOverrideMemo) {
                return (int) $override;
            }

            $key = (string) $override;
            if (! array_key_exists($key, $this->overrideAgencyMembershipMemo)) {
                $this->overrideAgencyMembershipMemo[$key] = $this->membershipMatchesAgency((int) $override);
            }

            if ($this->overrideAgencyMembershipMemo[$key]) {
                return (int) $override;
            }

            // Stale override for a non-owner: membership no longer matches.
            // Fall through to the normal resolution below.
        }

        // Derive from branch. Branch::find is memoized per-instance by branch id:
        // AgencyScope calls effectiveAgencyId()/isOwnerRole() on EVERY scoped query,
        // so an uncached lookup fired ~1,300 identical branch queries on a busy
        // page (calendar). Override logic above still runs fresh every call.
        $branchId = $this->effectiveBranchId();
        if ($branchId) {
            if (! array_key_exists($branchId, $this->branchAgencyMemo)) {
                $this->branchAgencyMemo[$branchId] = optional(Branch::find($branchId))->agency_id;
            }
            if ($this->branchAgencyMemo[$branchId]) {
                return (int) $this->branchAgencyMemo[$branchId];
            }
        }

        // Fallback to direct agency_id on user
        return $this->agency_id ? (int) $this->agency_id : null;
    }

    /**
     * Does $agencyId match this (non-owner) user's CURRENT membership?
     *
     * Mirrors AgencySwitcherController::userCanSwitchTo() exactly: a match on
     * the user's own `agency_id`, OR (if they belong to a branch) a match on
     * that branch's `agency_id`. Used by effectiveAgencyId() to re-validate a
     * non-owner's session active_agency_id override on every request, since
     * userCanSwitchTo() only authorizes it once, at write time.
     *
     * Uses the raw `agency_id`/`branch_id` columns (not effectiveAgencyId()/
     * effectiveBranchId(), which are themselves session-derived) — this must
     * reflect real, current DB membership, not another override.
     */
    private function membershipMatchesAgency(int $agencyId): bool
    {
        $directAgencyId = (int) ($this->agency_id ?? 0);
        if ($directAgencyId && $directAgencyId === $agencyId) {
            return true;
        }

        if (empty($this->branch_id)) {
            return false;
        }

        // Reuse an already-loaded branch relation when present (avoids a
        // query); otherwise mirror userCanSwitchTo()'s direct lookup.
        if ($this->relationLoaded('branch') && $this->branch) {
            return (int) $this->branch->agency_id === $agencyId;
        }

        $branchAgencyId = Branch::withoutGlobalScopes()
            ->where('id', $this->branch_id)
            ->value('agency_id');

        return (int) $branchAgencyId === $agencyId;
    }

    // ── Compliance Officer checks ──

    /**
     * True if this user holds ANY active FICA officer appointment
     * (primary CO or MLRO). Used by FICA approval workflow.
     */
    public function isComplianceOfficer(?int $agencyId = null): bool
    {
        $query = Compliance\FicaOfficerAppointment::where('user_id', $this->id)
            ->active();
        if ($agencyId) {
            $query->where('agency_id', $agencyId);
        }
        return $query->exists();
    }

    public function isPrimaryComplianceOfficer(?int $agencyId = null): bool
    {
        $query = Compliance\FicaOfficerAppointment::where('user_id', $this->id)
            ->primary()
            ->active();
        if ($agencyId) {
            $query->where('agency_id', $agencyId);
        }
        return $query->exists();
    }

    public function isMlro(?int $agencyId = null, ?int $branchId = null): bool
    {
        $query = Compliance\FicaOfficerAppointment::where('user_id', $this->id)
            ->mlro()
            ->active();
        if ($agencyId) {
            $query->where('agency_id', $agencyId);
        }
        if ($branchId) {
            $query->where(fn($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'));
        }
        return $query->exists();
    }

    /**
     * Ensure this user has a unique QR slug; generate one if missing.
     * The slug is embedded in the agent's onboarding QR URL.
     * Spec: .ai/specs/agent-qr-onboarding.md
     */
    public function ensureQrSlug(): string
    {
        if (!empty($this->qr_code_slug)) {
            return $this->qr_code_slug;
        }

        $alphabet = '23456789abcdefghjkmnpqrstuvwxyz';
        do {
            $slug = '';
            for ($i = 0; $i < 10; $i++) {
                $slug .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $exists = static::where('qr_code_slug', $slug)->exists();
        } while ($exists);

        $this->forceFill(['qr_code_slug' => $slug])->save();
        return $slug;
    }

    /**
     * URL-friendly slug of the agent's name (cosmetic segment of the public
     * profile URL). Never used to resolve the agent — the qr_code_slug does
     * that — so a rename only changes the pretty part and triggers a canonical
     * redirect, never a broken link.
     */
    public function nameSlug(): string
    {
        return \Illuminate\Support\Str::slug((string) $this->name) ?: 'agent';
    }

    /**
     * Canonical public profile URL — the agent's shareable "business card"
     * page. Format: /corex/agents/{name-slug}/{qr_code_slug}. The qr_code_slug
     * is the stable, unique ID tag that actually resolves the agent (and
     * follows the departed-agent reroute chain).
     * Spec: .ai/specs/agent-qr-onboarding.md
     */
    public function publicProfileUrl(): string
    {
        return rtrim(config('app.url'), '/')
            . '/corex/agents/' . $this->nameSlug() . '/' . $this->ensureQrSlug();
    }

    /**
     * Canonical web URL the agent's QR code encodes. Opened in a browser this
     * lands on the public profile page; scanned in the CoreX app it triggers
     * the client onboarding flow (the app matches the public-profile URL
     * pattern and extracts the trailing slug).
     */
    public function qrCodeUrl(): string
    {
        return $this->publicProfileUrl();
    }

    /**
     * Resolve a scanned QR slug to the live agent who should receive the lead.
     *
     * The slug always stays on the agent it was minted for (the audit anchor).
     * When that agent has left (inactive / soft-deleted) we follow their
     * `qr_reroute_user_id` pointer — chained, so a target who later leaves
     * reroutes again — until we land on an active, non-deleted agent.
     *
     * Returns null if the slug is unknown, or the chain dead-ends at an
     * inactive agent with no reroute set, or a loop is detected.
     *
     * Spec: .ai/specs/agent-qr-onboarding.md
     */
    public static function resolveByQrSlug(string $slug): ?self
    {
        if (!preg_match('/^[a-z0-9]{6,16}$/', $slug)) {
            return null;
        }

        $user = static::query()
            ->withoutGlobalScopes()
            ->where('qr_code_slug', $slug)
            ->first();

        $seen = [];
        while ($user) {
            if ($user->is_active && $user->deleted_at === null) {
                return $user;
            }
            if (isset($seen[$user->id]) || !$user->qr_reroute_user_id) {
                return null; // loop, or chain dead-ends on a departed agent
            }
            $seen[$user->id] = true;

            $user = static::query()
                ->withoutGlobalScopes()
                ->whereKey($user->qr_reroute_user_id)
                ->first();
        }

        return null;
    }

    /**
     * AT-268 — the password a freshly-INVITED user is created with: unusable, unguessable, unshared.
     *
     * An invited account must not be loginable until the invitee redeems their signed setup link and
     * sets a real password. The old code used the constant string 'INVITE_PENDING', which the `hashed`
     * cast turned into a valid bcrypt hash of a value printed in the source, the tests and the ticket
     * — so `Auth::attempt(..., 'INVITE_PENDING')` logged you in as any un-accepted invite. This is a
     * fresh 72-char random string (the `hashed` cast hashes it on assignment); nothing ever reads it
     * back, and AccountSetupController overwrites it with the invitee's real password on acceptance.
     */
    public static function pendingInvitePassword(): string
    {
        return \Illuminate\Support\Str::random(72);
    }

    /**
     * AT-268 — is this account an invite that has not been accepted yet?
     *
     * `email_verified_at` is CoreX's invite-accepted marker: AccountSetupController stamps it to now()
     * the moment the invitee sets their password. Null therefore means "invited, not yet redeemed".
     * The login gate refuses these regardless of the password supplied (belt-and-braces over the
     * unusable password above).
     */
    public function isPendingInvite(): bool
    {
        return $this->email_verified_at === null;
    }

    // ── Owner role checks (the ONLY hardcoded concept) ──

    /**
     * Check if the user's REAL role has the is_owner flag.
     */
    public function isOwnerRole(): bool
    {
        // Owner roles are global (agency_id NULL) and always present in
        // allRoles() for any agency context, so the resolved agency here
        // never hides them — it just disambiguates same-named agency roles.
        $roleModel = Role::allRoles($this->effectiveAgencyId())->firstWhere('name', $this->role ?? '');

        return $roleModel && $roleModel->is_owner;
    }

    /**
     * Check if the user's EFFECTIVE role (respects View-As) has the is_owner flag.
     */
    public function isEffectiveOwner(): bool
    {
        $roleModel = Role::allRoles($this->effectiveAgencyId())->firstWhere('name', $this->effectiveRole());

        return $roleModel && $roleModel->is_owner;
    }

    /**
     * Get the Role model for this user's real role (within the user's agency).
     */
    public function roleModel(): ?Role
    {
        return Role::allRoles($this->effectiveAgencyId())->firstWhere('name', $this->role ?? '');
    }

    /**
     * Names of every role flagged `is_owner = true`. System Owners are
     * platform identities, not agency members, so any query that builds
     * an "agency users / agents" list MUST exclude them — otherwise they
     * appear in property pickers, contact filters, commission tables,
     * branch assignment, etc., which is the cross-agency bleed we're
     * trying to close.
     *
     * @return array<int, string>
     */
    public static function ownerRoleNames(): array
    {
        return Role::allRoles()
            ->where('is_owner', true)
            ->pluck('name')
            ->all();
    }

    /**
     * Query scope: restrict to agency-member users (exclude System Owners).
     *
     * Use on every listing that represents "users of an agency" — agent
     * pickers, user management, commission tables, role manager, branch
     * assignments. Do NOT use on audit/log queries where you legitimately
     * need to resolve the actor regardless of role.
     */
    public function scopeAgencyMembers($query)
    {
        $ownerNames = static::ownerRoleNames();
        if (empty($ownerNames)) {
            return $query;
        }

        return $query->whereNotIn($query->getModel()->getTable() . '.role', $ownerNames);
    }

    public function isCandidate(): bool
    {
        return stripos($this->designation ?? '', 'Candidate') !== false;
    }

    /**
     * The supervisor (full-status practitioner) assigned to this candidate.
     */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervised_by');
    }

    /**
     * Candidates supervised by this user.
     */
    public function supervisees(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(User::class, 'supervised_by');
    }

    // ------------------------------------------------------------------
    // AT-267 — Assistants
    //
    // An Assistant works for one Assigned Agent. Their permissions are the
    // intersection of (their assignment matrix) ∩ (the agent's LIVE permissions),
    // minus the property-upload locked set — resolved in AssistantPermissionResolver.
    //
    // "Assigned Agent", never "sponsor": users.sponsored_by_user_id already exists
    // and means the commission mentor, which is an unrelated concept.
    //
    // Spec: .ai/specs/assistants-feature-spec.md §6.6, §7
    // ------------------------------------------------------------------

    /** Memo for the per-request assignment lookup (null = not yet resolved, false = none). */
    private AssistantAssignment|false|null $assistantAssignmentMemo = null;

    /** Per-request cache of agencies.assistants_enabled, keyed by agency id. */
    private static array $assistantsEnabledCache = [];

    /** Memo for the per-request linked-Sub-Agent lookup. Null = not yet resolved. */
    private ?array $activeLinkedSubAgentIdsMemo = null;

    /** Memo for the per-request linked-Sub-Agent branch lookup. Null = not yet resolved. */
    private ?array $activeLinkedSubAgentBranchIdsMemo = null;

    /** This user's own active assignment — set only when they ARE an assistant. */
    public function assistantAssignment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(AssistantAssignment::class, 'assistant_user_id')->active();
    }

    /** The assistants working for this user (this user being the Assigned Agent). */
    public function assistantAssignments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssistantAssignment::class, 'agent_user_id')->active();
    }

    /**
     * The live assignment, or null. Memoised — this is consulted on every permission
     * check and every visibility scope, so it must not re-query per call.
     */
    public function activeAssistantAssignment(): ?AssistantAssignment
    {
        if ($this->assistantAssignmentMemo === null) {
            // 'assignedAgent' is eager-loaded alongside 'permissions' — the resolver
            // (AssistantPermissionResolver::allows()/dataScope()) reads $assignment->assignedAgent
            // via property access on every permission/scope check, and leaving it to lazy-load
            // mid-query (while OTHER global scopes are already being applied, e.g. BranchScope
            // calling hasPermission() from inside applyInner() — multi-agent addendum §4) has been
            // observed to throw "Undefined property" under PHPUnit's strict warning-to-exception
            // handling. Eager-loading it here removes the lazy-load entirely.
            $this->assistantAssignmentMemo = ($this->is_assistant && static::assistantsEnabledFor($this->agency_id))
                ? ($this->assistantAssignment()->with(['permissions', 'assignedAgent'])->first() ?? false)
                : false;
        }

        return $this->assistantAssignmentMemo ?: null;
    }

    /**
     * The agency kill switch. When OFF, an assistant resolves as a plain user with a
     * zero-grant role — no inherited permissions, no inherited visibility. Flipping the
     * switch off must return the system to EXACTLY current behaviour, so the guard lives
     * here (the one place every assistant code path funnels through) rather than only in
     * the resolver. Mirrors BranchScope::splitBranchesEnabled().
     */
    private static function assistantsEnabledFor(?int $agencyId): bool
    {
        if (!$agencyId) {
            return false;
        }

        return static::$assistantsEnabledCache[$agencyId] ??= (bool) Agency::withoutGlobalScopes()
            ->whereKey($agencyId)
            ->value('assistants_enabled');
    }

    /** Test hook — the toggle cache is per-request state, not per-test state. */
    public static function flushAssistantsEnabledCache(): void
    {
        static::$assistantsEnabledCache = [];
    }

    /**
     * Is the Assistants agency kill switch ON for the agency this user is CURRENTLY
     * acting in (session switcher / branch-derived, then home agency_id)? This is the
     * admin-surface reading — it must resolve the same agency the Company Settings
     * write path targets (SettingsController::updateAssistants → effectiveAgencyId()),
     * so an owner switched into an agency sees the flag they just toggled there rather
     * than the flag of their raw home agency_id (which is null for a global owner).
     * Enforcement paths keep using the assistant's OWN agency_id — that is deliberate.
     */
    public function assistantsEnabledForEffectiveAgency(): bool
    {
        return static::assistantsEnabledFor($this->effectiveAgencyId());
    }

    /**
     * True only when the flag AND a live assignment agree. A stale `is_assistant`
     * with no assignment is not an assistant — it is a user with no permissions
     * (the resolver fails closed), never a user who falls back to agent defaults.
     */
    public function isAssistant(): bool
    {
        return $this->is_assistant && $this->activeAssistantAssignment() !== null;
    }

    /** The agent this assistant works for, or null. */
    public function assignedAgent(): ?self
    {
        return $this->activeAssistantAssignment()?->assignedAgent;
    }

    /**
     * How this assistant is labelled in the UI — their custom title ("PA",
     * "Receptionist", …) or "Assistant" when none was set. A display label
     * only; never a permission or role decision.
     */
    public function assistantTitle(): string
    {
        return trim((string) $this->assistant_title) !== ''
            ? trim($this->assistant_title)
            : 'Assistant';
    }

    /** True when this user has at least one assistant — drives the sidebar entry. */
    public function hasAssistants(): bool
    {
        return $this->assistantAssignments()->exists();
    }

    /**
     * The user ids whose records this user may see under an 'own' data scope.
     *
     * Normal user: [self]. Assistant: [assigned agent, self] — because an assistant
     * granted `contacts.view` at scope 'own' must see the AGENT's contacts. Without
     * this they would see an empty list and the feature would be inert (spec §2.4).
     *
     * Multi-agent addendum: also includes every currently-active linked Sub-Agent
     * (assistants-multi-agent-spec.md §2.4) — a Sub-Agent contributes zero permissions
     * (the ceiling stays the Main Agent's, unchanged) but widens whose records the
     * assistant may see AND edit, via this exact list. This is the entire mechanism:
     * every scopeVisibleTo() 'own' branch and all three per-record authorize traits
     * (AuthorizesPropertyAccess, AuthorizesDealAccess, AuthorizesContactAccess) resolve
     * through here already, so no other file needs to change for a Sub-Agent's
     * properties/contacts/deals to become visible and editable.
     *
     * Every scopeVisibleTo() 'own' branch resolves through here (Prompt D).
     */
    public function dataIdentityIds(): array
    {
        $agent = $this->isAssistant() ? $this->assignedAgent() : null;

        if (!$agent) {
            return [$this->id];
        }

        $ids = [$agent->id, $this->id];

        foreach ($this->activeLinkedSubAgentIds() as $subAgentId) {
            $ids[] = $subAgentId;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Multi-agent addendum — Sub-Agent ids currently in effect for this assistant's
     * assignment, filtered LIVE on every call: only agents who are still active, not
     * themselves an assistant, and not an owner (mirrors base spec E5/E6, applied to
     * data exposure rather than the permission ceiling). No re-snapshot — a Sub-Agent
     * removed by an admin, deactivated, or later promoted drops out of this list on the
     * very next request, exactly like the Main Agent's live-intersection rule (base
     * spec E3). Empty for every non-assistant and for every assistant with no linked
     * Sub-Agents — i.e. the entire population before this addendum shipped.
     *
     * Spec: .ai/specs/assistants-multi-agent-spec.md §2.4
     */
    public function activeLinkedSubAgentIds(): array
    {
        if ($this->activeLinkedSubAgentIdsMemo !== null) {
            return $this->activeLinkedSubAgentIdsMemo;
        }

        $assignment = $this->activeAssistantAssignment();

        if (!$assignment) {
            return $this->activeLinkedSubAgentIdsMemo = [];
        }

        return $this->activeLinkedSubAgentIdsMemo = $assignment->linkedAgentLinks()
            ->with('agent')
            ->get()
            ->pluck('agent')
            ->filter(fn ($a) => $a
                && $a->is_active
                && !$a->is_assistant
                && !(method_exists($a, 'isOwnerRole') && $a->isOwnerRole()))
            ->pluck('id')
            ->all();
    }

    /**
     * Multi-agent addendum — distinct branch ids of this assistant's currently-active
     * linked Sub-Agents. Empty for everyone else, and for any assistant with no linked
     * Sub-Agents. Consumed by BranchScope so a cross-branch Sub-Agent's records are not
     * silently filtered out by branch isolation when dataIdentityIds() has already
     * widened to include them (spec §4).
     */
    public function activeLinkedSubAgentBranchIds(): array
    {
        if ($this->activeLinkedSubAgentBranchIdsMemo !== null) {
            return $this->activeLinkedSubAgentBranchIdsMemo;
        }

        $assignment = $this->activeAssistantAssignment();

        if (!$assignment) {
            return $this->activeLinkedSubAgentBranchIdsMemo = [];
        }

        return $this->activeLinkedSubAgentBranchIdsMemo = $assignment->linkedAgentLinks()
            ->with('agent')
            ->get()
            ->pluck('agent')
            ->filter(fn ($a) => $a
                && $a->is_active
                && !$a->is_assistant
                && !(method_exists($a, 'isOwnerRole') && $a->isOwnerRole()))
            ->pluck('branch_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The user id a record this user CREATES is owned by.
     *
     * Normal user: self. Assistant: the Assigned Agent by default — so commission,
     * targets, the deal pipeline and "My Listings" all land on the agent. A deal
     * captured by an assistant is the AGENT's deal. The assistant is recorded as the
     * actor in the audit trail (on_behalf_of_user_id, Prompt J), never as the owner.
     *
     * Multi-agent addendum: an assistant supporting Sub-Agents may explicitly choose
     * WHICH of their agents a new record belongs to (the "Acting for" selector,
     * assistants-multi-agent-spec.md §6.1). $actingForUserId is only honoured when it is
     * a currently valid choice (in dataIdentityIds(), and not the assistant themselves)
     * — never trusted blindly from a request. Omitting it, or having no linked
     * Sub-Agents at all, reproduces exactly today's behaviour: the Main Agent.
     */
    public function ownershipUserId(?int $actingForUserId = null): int
    {
        $agent = $this->assignedAgent();

        if (!$agent) {
            return $this->id;
        }

        // Multi-agent addendum §6.2 — an explicit per-call value (a form's own "Acting for"
        // field) wins when given; otherwise fall back to the session-level "Acting for" switcher
        // (App\Http\Controllers\Agent\ActingForController) so every create surface honours the
        // assistant's choice without each one needing its own selector. Re-validated against the
        // LIVE dataIdentityIds() below either way — a stale/forged session value (e.g. the chosen
        // Sub-Agent was since unlinked) safely falls back to the Main Agent, never trusted blindly.
        $actingForUserId ??= session()->has('acting_for_user_id')
            ? (int) session('acting_for_user_id')
            : null;

        if ($actingForUserId !== null
            && $actingForUserId !== $this->id
            && in_array($actingForUserId, $this->dataIdentityIds(), true)) {
            return $actingForUserId;
        }

        return $agent->id;
    }

    /**
     * AT-267 — may this user download document files?
     *
     * True for everyone except an assistant whose assignment has the "download documents" toggle
     * off (or who has no active assignment — fail closed). Mirrors the DenyAssistantDownload
     * middleware for the VIEW layer, so download affordances the middleware cannot gate — direct
     * public-disk storage URLs ($doc->url(), which never hit the app) — are hidden too.
     */
    public function canDownloadDocuments(): bool
    {
        if (! $this->is_assistant) {
            return true;
        }

        return (bool) ($this->activeAssistantAssignment()?->can_download_documents);
    }

    /**
     * AT-267 — may this user EDIT and DELETE records, or only add and view them?
     *
     * The agent's control-page toggle, `assistant_assignments.can_manage_my_records`:
     * "{Assistant} can edit & delete my records, not just add them". ON by default (an
     * assistant who cannot change anything is barely an assistant); when the agent switches
     * it OFF the assistant keeps every VIEW and every CREATE their matrix grants and loses
     * every UPDATE and DELETE.
     *
     * AUDIT 2026-07-26 (F1) — this toggle shipped on the page in Phase 2 and its enforcement
     * (Phase 4) never did, so for five days the page told agents they had restricted their
     * assistant when they had not. A visible switch that does nothing is worse than no switch:
     * it stops the agent looking for the real control. Same reasoning, verbatim, as the
     * soft-retired notification toggles in NotificationEventTypeSeeder.
     *
     * True for everyone who is not an assistant. Fails CLOSED for an assistant with no live
     * assignment — exactly like canDownloadDocuments(), and for the same reason: an unresolvable
     * assistant is a degraded assistant, and a degraded assistant does not get write access.
     */
    public function canMutateRecords(): bool
    {
        if (! $this->is_assistant) {
            return true;
        }

        return (bool) ($this->activeAssistantAssignment()?->can_manage_my_records);
    }

    // --- Permission helpers (delegate to PermissionService) ---

    public function hasPermission(string $key): bool
    {
        return PermissionService::userHasPermission($this, $key);
    }

    public function hasAnyPermission(array $keys): bool
    {
        return PermissionService::userHasAnyPermission($this, $keys);
    }

    // --- Feature-registry helper (delegate to AgencyFeatureService) ---
    // Feature = "does this AGENCY use this module" — ORTHOGONAL to permission
    // ("may this USER touch it"). Spec: .ai/specs/corex-feature-registry.md §3.1.

    public function hasFeature(string $key): bool
    {
        return app(\App\Services\Features\AgencyFeatureService::class)->enabled($key);
    }

    public function socialAccounts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AgentSocialAccount::class);
    }

    public function marketingPosts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PropertyMarketingPost::class);
    }

    // ── Commission Engine Relationships ──

    public function sponsorship(): HasOne
    {
        return $this->hasOne(AgentSponsorship::class, 'agent_user_id');
    }

    public function sponsoredAgents(): HasMany
    {
        return $this->hasMany(AgentSponsorship::class, 'sponsor_user_id');
    }

    public function capPeriods(): HasMany
    {
        return $this->hasMany(AgentCapPeriod::class);
    }

    public function currentCapPeriod(): ?AgentCapPeriod
    {
        return AgentCapPeriod::forUser($this->id)
            ->current()
            ->first();
    }

    public function commissionEntries(): HasMany
    {
        return $this->hasMany(CommissionLedger::class);
    }

    public function revenueShareReceived(): HasMany
    {
        return $this->hasMany(RevenueShareLedger::class, 'receiving_agent_id');
    }

    public function mentorAssignment(): HasOne
    {
        return $this->hasOne(AgentMentor::class, 'mentee_user_id');
    }

    public function isCapped(): bool
    {
        $period = $this->currentCapPeriod();

        return $period ? $period->checkCap() : false;
    }

    public function isMentee(): bool
    {
        return AgentMentor::where('mentee_user_id', $this->id)
            ->where('is_active', true)
            ->exists();
    }

    // ── RMCP Acknowledgement ──

    public function rmcpAcknowledgements(): HasMany
    {
        return $this->hasMany(Compliance\RmcpAcknowledgement::class);
    }

    public function currentRmcpAcknowledgement(): ?Compliance\RmcpAcknowledgement
    {
        $agencyId = $this->effectiveAgencyId();
        if (!$agencyId) return null;

        $activeVersion = Compliance\RmcpVersion::where('agency_id', $agencyId)
            ->where('status', 'active')
            ->first();

        if (!$activeVersion) return null;

        return $this->rmcpAcknowledgements()
            ->where('rmcp_version_id', $activeVersion->id)
            ->whereIn('status', ['in_progress', 'completed'])
            ->latest()
            ->first();
    }

    public function rmcpAcknowledgementStatus(): string
    {
        $agencyId = $this->effectiveAgencyId();
        if (!$agencyId) return 'no_rmcp';

        $activeVersion = Compliance\RmcpVersion::where('agency_id', $agencyId)
            ->where('status', 'active')
            ->first();

        if (!$activeVersion) return 'no_rmcp';

        $ack = $this->rmcpAcknowledgements()
            ->where('rmcp_version_id', $activeVersion->id)
            ->whereIn('status', ['in_progress', 'completed'])
            ->latest()
            ->first();

        if (!$ack) return 'not_started';
        if ($ack->isValid()) return 'valid';
        if ($ack->isComplete()) return 'expired';
        return 'in_progress';
    }

    // ── Generic Policy Acknowledgement (AT-29) — parameterised by policy_key ──
    // Computes live against the policy's active version. Nothing is stored on
    // the users table. See .ai/specs/claude_policy_acknowledgement_spec.md §5.

    public function policyAcknowledgements(): HasMany
    {
        return $this->hasMany(Compliance\PolicyAcknowledgement::class);
    }

    /**
     * Resolve the agency's policy by key, then this user's in_progress|completed
     * acknowledgement for that policy's currently-active version (latest).
     */
    public function currentPolicyAcknowledgement(string $policyKey): ?Compliance\PolicyAcknowledgement
    {
        $agencyId = $this->effectiveAgencyId();
        if (!$agencyId) return null;

        $policy = Compliance\AgencyPolicy::where('agency_id', $agencyId)
            ->where('policy_key', $policyKey)
            ->first();
        if (!$policy) return null;

        $activeVersion = Compliance\PolicyVersion::where('policy_id', $policy->id)
            ->where('status', 'active')
            ->first();
        if (!$activeVersion) return null;

        return $this->policyAcknowledgements()
            ->where('policy_version_id', $activeVersion->id)
            ->whereIn('status', ['in_progress', 'completed'])
            ->latest()
            ->first();
    }

    /**
     * @return string no_policy|not_started|valid|expired|in_progress
     */
    public function policyAcknowledgementStatus(string $policyKey): string
    {
        $agencyId = $this->effectiveAgencyId();
        if (!$agencyId) return 'no_policy';

        $policy = Compliance\AgencyPolicy::where('agency_id', $agencyId)
            ->where('policy_key', $policyKey)
            ->first();
        if (!$policy) return 'no_policy';

        $activeVersion = Compliance\PolicyVersion::where('policy_id', $policy->id)
            ->where('status', 'active')
            ->first();
        if (!$activeVersion) return 'no_policy';

        $ack = $this->policyAcknowledgements()
            ->where('policy_version_id', $activeVersion->id)
            ->whereIn('status', ['in_progress', 'completed'])
            ->latest()
            ->first();

        if (!$ack) return 'not_started';
        if ($ack->isValid()) return 'valid';
        if ($ack->isComplete()) return 'expired';
        return 'in_progress';
    }

    /**
     * Every active policy in the user's agency whose status is not 'valid'
     * (not_started | expired | in_progress). Drives the Agent Portal
     * "you have N policies to sign" tile and the compliance roll-up.
     *
     * @return \Illuminate\Support\Collection<int, array{policy: Compliance\AgencyPolicy, status: string}>
     */
    public function outstandingPolicyAcknowledgements(): \Illuminate\Support\Collection
    {
        $agencyId = $this->effectiveAgencyId();
        if (!$agencyId) return collect();

        return Compliance\AgencyPolicy::where('agency_id', $agencyId)
            ->where('is_active', true)
            ->get()
            ->map(fn (Compliance\AgencyPolicy $policy) => [
                'policy' => $policy,
                'status' => $this->policyAcknowledgementStatus($policy->policy_key),
            ])
            ->filter(fn (array $row) => $row['status'] !== 'valid')
            ->values();
    }

    // ── Employee Screening ──

    public function screenings(): HasMany
    {
        return $this->hasMany(Compliance\EmployeeScreening::class);
    }

    public function latestScreening(): ?Compliance\EmployeeScreening
    {
        return $this->screenings()->latest('initiated_on')->first();
    }

    public function currentScreeningStatus(): string
    {
        return $this->screening_status ?? 'never_screened';
    }

    public function needsScreening(): bool
    {
        return in_array($this->screening_status, [
            'never_screened', 'pre_employment_pending', 'overdue', 'expired',
        ]);
    }

    // ── Payroll ──

    public function payrollEmployee(): HasOne
    {
        return $this->hasOne(Payroll\PayrollEmployee::class);
    }

    public function payrollPayslips(): HasMany
    {
        return $this->hasMany(Payroll\PayrollPayslip::class, 'user_id');
    }

    public function bankingDetail(): HasOne
    {
        return $this->hasOne(UserBankingDetail::class);
    }

    public function isOnPayroll(): bool
    {
        return $this->payrollEmployee()
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Calculate age at a given date from date_of_birth, falling back to
     * SA ID number first 6 digits (YYMMDD) if date_of_birth is null.
     */
    public function getAgeOnDate(\Carbon\Carbon $date): ?int
    {
        $dob = $this->date_of_birth;

        if (! $dob && $this->id_number && strlen($this->id_number) >= 6) {
            $raw = substr($this->id_number, 0, 6);
            $yy = (int) substr($raw, 0, 2);
            $mm = (int) substr($raw, 2, 2);
            $dd = (int) substr($raw, 4, 2);
            // SA IDs: 00-29 → 2000s, 30-99 → 1900s
            $yyyy = $yy <= 29 ? 2000 + $yy : 1900 + $yy;
            try {
                $dob = \Carbon\Carbon::createFromDate($yyyy, $mm, $dd);
            } catch (\Exception $e) {
                return null;
            }
        }

        if (! $dob) {
            return null;
        }

        return (int) $dob->diffInYears($date);
    }

    // ── Leave ──

    public function leaveEntitlements(): HasMany
    {
        return $this->hasMany(Leave\LeaveEntitlement::class);
    }

    public function leaveApplications(): HasMany
    {
        return $this->hasMany(Leave\LeaveApplication::class);
    }

    public function leaveTransactions(): HasMany
    {
        return $this->hasMany(Leave\LeaveTransaction::class);
    }

    public function staffTakeOnRecord(): HasOne
    {
        return $this->hasOne(Leave\StaffTakeOnRecord::class);
    }

    public function getLeaveBalanceFor(Leave\LeaveType $type, ?\Carbon\Carbon $asOf = null): ?Leave\LeaveEntitlement
    {
        $date = $asOf ?? now();

        return $this->leaveEntitlements()
            ->where('leave_type_id', $type->id)
            ->where('cycle_start_date', '<=', $date)
            ->where('cycle_end_date', '>=', $date)
            ->first();
    }

    public function hasActiveLeave(?\Carbon\Carbon $on = null): bool
    {
        $date = ($on ?? now())->toDateString();

        return $this->leaveApplications()
            ->whereIn('status', ['approved', 'taken'])
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->exists();
    }
}
