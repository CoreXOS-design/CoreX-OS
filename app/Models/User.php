<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'designation',
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

    // --- Relationships ---

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    // --- View-As / Agency-Switch support (session overrides) ---

    public function effectiveRole(): string
    {
        $override = session('view_as_role');
        return $override ?: ($this->role ?? 'agent');
    }

    public function effectiveBranchId(): ?int
    {
        $override = session('view_as_branch_id');
        if ($override !== null && $override !== '') {
            return (int) $override;
        }

        return $this->branch_id ? (int) $this->branch_id : null;
    }

    /** Returns the active agency ID (Super Admin can switch agencies via session). */
    public function effectiveAgencyId(): ?int
    {
        if ($this->isSuperAdmin()) {
            $override = session('active_agency_id');
            if ($override) {
                return (int) $override;
            }
            return null; // null = all agencies
        }

        return $this->agency_id ? (int) $this->agency_id : null;
    }

    // --- Effective role helpers (respect View-As) ---

    /** True for super_admin OR admin (both are "admin-level" for middleware). */
    public function isEffectiveAdmin(): bool
    {
        return in_array($this->effectiveRole(), ['super_admin', 'admin']);
    }

    public function isEffectiveSuperAdmin(): bool
    {
        return $this->effectiveRole() === 'super_admin';
    }

    public function isEffectiveBranchManager(): bool
    {
        // Admin-level roles are NEVER treated as Branch Manager (prevents NULL-branch filters)
        if ($this->isEffectiveAdmin()) {
            return false;
        }

        return (string)($this->role ?? '') === 'branch_manager';
    }

    public function isEffectiveAgent(): bool
    {
        return $this->effectiveRole() === 'agent';
    }

    public function isEffectiveViewer(): bool
    {
        return $this->effectiveRole() === 'viewer';
    }

    // --- Real role helpers (no View-As) ---

    public function isSuperAdmin(): bool
    {
        return ($this->role ?? '') === 'super_admin';
    }

    public function isAdmin(): bool
    {
        return ($this->role ?? '') === 'admin';
    }

    public function isBranchManager(): bool
    {
        return ($this->role ?? '') === 'branch_manager';
    }

    public function isAgent(): bool
    {
        return ($this->role ?? '') === 'agent';
    }

    public function isViewer(): bool
    {
        return ($this->role ?? '') === 'viewer';
    }

    // --- Nexus OS Section Access (DB-driven) ---

    /**
     * Check if this user can access a Nexus section.
     * Super admin always has access. All other roles are checked against role_permissions table.
     * Falls back to hardcoded defaults if the table is empty (first-run safety).
     */
    public function canAccessNexusSection(string $section): bool
    {
        // Super admin has access to everything
        if ($this->isEffectiveSuperAdmin()) {
            return true;
        }

        $role = $this->effectiveRole();

        // Map section to an access-gate permission key
        $sectionPermMap = [
            'dashboard'       => 'view_dashboard',
            'agency-tracker'  => 'access_agency_tracker',
            'compliance'      => 'access_compliance',
            'supervision'     => 'access_supervision',
            'training'        => 'access_training',
            'communication'   => 'access_communication',
            'client-portal'   => 'access_client_portal',
            'franchise-admin' => 'access_franchise_admin',
            'role-manager'    => 'access_role_manager',
            'settings'        => 'access_settings',
            'docuperfect'     => 'access_docuperfect',
            'document-library' => 'access_document_library',
            'presentations'   => 'access_presentations',
            'pdf-splitter'    => 'access_pdf_splitter',
            'knowledge-base'  => 'access_knowledge_base',
            'finance-engine'  => 'access_finance_engine',
            'agencies'        => 'access_agencies',
        ];

        $permKey = $sectionPermMap[$section] ?? null;
        if (!$permKey) {
            return false;
        }

        // Check DB
        $granted = DB::table('role_permissions')
            ->where('role', $role)
            ->where('permission_key', $permKey)
            ->exists();

        if ($granted) {
            return true;
        }

        // Fallback hardcoded defaults (safety net before seeder runs)
        $fallback = [
            'dashboard'       => ['admin', 'branch_manager', 'agent', 'viewer'],
            'agency-tracker'  => ['admin', 'branch_manager', 'agent'],
            'compliance'      => ['admin', 'branch_manager'],
            'supervision'     => ['admin', 'branch_manager'],
            'training'        => ['admin', 'branch_manager', 'agent'],
            'communication'   => ['admin', 'branch_manager', 'agent'],
            'client-portal'   => ['admin', 'branch_manager', 'agent'],
            'franchise-admin' => ['admin'],
            'role-manager'    => ['admin'],
            'settings'        => ['admin'],
        ];

        return in_array($role, $fallback[$section] ?? []);
    }
}
