<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'designation',
        'supervised_by',
        'branch_id',
        'agency_id',
        'is_active',

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

        // Flags
        'can_capture_rentals',
        'counts_for_branch_split',

        // Contact fields (email signatures, profile, presentations)
        'phone',
        'cell',
        'fax',
        'ffc_number',
        'website',
        'theme',

        // Private Property integration
        'pp_unique_agent_id',

        // Property24 importer
        'p24_agent_id',
        'source_reference',
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

        'agent_cut_percent' => 'decimal:2',
        'paye_value' => 'decimal:2',

        'sliding_enabled' => 'boolean',
        'sliding_tier1_cut_percent' => 'decimal:2',
        'sliding_tier2_cut_percent' => 'decimal:2',
        'sliding_tier3_cut_percent' => 'decimal:2',
    ];

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

    // ── Compliance Officer check ──

    public function isComplianceOfficer(): bool
    {
        return FicaComplianceOfficer::where('user_id', $this->id)->exists();
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

}
