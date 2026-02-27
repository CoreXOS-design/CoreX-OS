<?php

namespace App\Models;

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

    public function isEffectiveAdmin(): bool
    {
        return $this->effectiveRole() === 'admin';
    }

    public function isEffectiveBranchManager(): bool
    {
        // Admin is NEVER treated as Branch Manager (prevents NULL-branch filters)
        if ($this->isEffectiveAdmin()) {
            return false;
        }

        return (string)($this->role ?? '') === 'branch_manager';
    }

    public function isEffectiveAgent(): bool
    {
        return $this->effectiveRole() === 'agent';
    }

    // Real role (no View-As)
    public function isAdmin(): bool
    {
        return ($this->role ?? '') === 'admin';
    }

    public function isBranchManager(): bool
    {
        return (($this->role ?? "") === "branch_manager");
    }

    public function isAgent(): bool
    {
        return (($this->role ?? "") === "agent");
    }

    public function isCandidate(): bool
    {
        return stripos($this->designation ?? '', 'Candidate') !== false;
    }

    // --- Nexus OS Section Access ---

    public function canAccessNexusSection(string $section): bool
    {
        if ($this->isEffectiveAdmin()) {
            return true;
        }

        $access = [
            'dashboard'       => ['admin', 'branch_manager', 'agent'],
            'agency-tracker'  => ['admin', 'branch_manager', 'agent'],
            'documents'       => ['admin', 'branch_manager', 'agent'],
            'compliance'      => ['admin', 'branch_manager'],
            'supervision'     => ['admin', 'branch_manager'],
            'training'        => ['admin', 'branch_manager', 'agent'],
            'communication'   => ['admin', 'branch_manager', 'agent'],
            'client-portal'   => ['admin', 'branch_manager', 'agent'],
            'franchise-admin' => ['admin'],
            'role-manager'    => ['admin'],
            'settings'        => ['admin'],
        ];

        return in_array($this->effectiveRole(), $access[$section] ?? []);
    }
}
