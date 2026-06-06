<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    protected $fillable = [
        'name',
        'email',
        'password',
        'qr_code_slug',
        'qr_reroute_user_id',
        'role',
        'designation',
        'supervised_by',
        'branch_id',
        'agency_id',
        'is_active',
        'show_on_website',
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

        // Property24 importer
        'p24_agent_id',
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

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'show_on_website' => 'boolean',
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
     * Agency Admin Rule — every agency must keep ≥1 active Admin at all times.
     * See .ai/specs/agency-admin-rule.md. Enforced structurally so any path
     * (controller, console, queue, manual Tinker) cannot leave an agency
     * adminless.
     */
    protected static function booted(): void
    {
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
     * Returns the user's initials (first + last name initial) for avatar placeholders.
     */
    public function initials(): string
    {
        $parts = explode(' ', trim($this->name ?? ''));
        $first = strtoupper(substr($parts[0] ?? '', 0, 1));
        $last = count($parts) > 1 ? strtoupper(substr(end($parts), 0, 1)) : '';
        return $first . $last;
    }

    public function effectiveBranchId(): ?int
    {
        $override = session('view_as_branch_id');
        if ($override !== null && $override !== '') {
            return (int) $override;
        }

        return $this->branch_id ? (int) $this->branch_id : null;
    }

    public function effectiveAgencyId(): ?int
    {
        // Owner-level agency switcher override
        $override = session('active_agency_id');
        if ($override !== null && $override !== '') {
            return (int) $override;
        }

        // Derive from branch
        $branchId = $this->effectiveBranchId();
        if ($branchId) {
            $branch = Branch::find($branchId);
            if ($branch?->agency_id) {
                return (int) $branch->agency_id;
            }
        }

        // Fallback to direct agency_id on user
        return $this->agency_id ? (int) $this->agency_id : null;
    }

    // ── Compliance Officer checks ──

    /**
     * True if this user holds ANY active FICA officer appointment
     * (primary CO or MLRO). Used by FICA approval workflow.
     */
    public function isComplianceOfficer(): bool
    {
        return Compliance\FicaOfficerAppointment::where('user_id', $this->id)
            ->active()
            ->exists();
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

    public function isMlro(?int $branchId = null): bool
    {
        $query = Compliance\FicaOfficerAppointment::where('user_id', $this->id)
            ->mlro()
            ->active();
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
     * Canonical web URL the agent's QR code encodes.
     */
    public function qrCodeUrl(): string
    {
        return rtrim(config('app.url'), '/') . '/r/a/' . $this->ensureQrSlug();
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

    // ── Owner role checks (the ONLY hardcoded concept) ──

    /**
     * Check if the user's REAL role has the is_owner flag.
     */
    public function isOwnerRole(): bool
    {
        $roleModel = Role::allRoles()->firstWhere('name', $this->role ?? '');

        return $roleModel && $roleModel->is_owner;
    }

    /**
     * Check if the user's EFFECTIVE role (respects View-As) has the is_owner flag.
     */
    public function isEffectiveOwner(): bool
    {
        $roleModel = Role::allRoles()->firstWhere('name', $this->effectiveRole());

        return $roleModel && $roleModel->is_owner;
    }

    /**
     * Get the Role model for this user's real role.
     */
    public function roleModel(): ?Role
    {
        return Role::allRoles()->firstWhere('name', $this->role ?? '');
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

    // --- Permission helpers (delegate to PermissionService) ---

    public function hasPermission(string $key): bool
    {
        return PermissionService::userHasPermission($this, $key);
    }

    public function hasAnyPermission(array $keys): bool
    {
        return PermissionService::userHasAnyPermission($this, $keys);
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
