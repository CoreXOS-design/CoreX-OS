<?php

namespace App\Models;

use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

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

        // Contact fields (email signatures, profile, presentations)
        'phone',
        'cell',
        'fax',
        'ffc_number',
        'website',
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
        $branchId = $this->effectiveBranchId();
        if (!$branchId) {
            return null;
        }
        $branch = Branch::find($branchId);
        return $branch?->agency_id ? (int) $branch->agency_id : null;
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

    public function isCandidate(): bool
    {
        return stripos($this->designation ?? '', 'Candidate') !== false;
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

}
