<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

class AgencyLeaveVisibilityMatrix extends Model
{
    use BelongsToAgency;

    protected $table = 'agency_leave_visibility_matrix';

    protected $fillable = [
        'agency_id',
        'viewing_role',
        'leave_owner_role',
        'same_branch_only',
        'can_see',
    ];

    protected $casts = [
        'same_branch_only' => 'boolean',
        'can_see' => 'boolean',
    ];

    /**
     * Check if a viewing role can see leave for an owner role.
     */
    public static function canSee(string $viewingRole, string $ownerRole, bool $sameBranch, int $agencyId): bool
    {
        $row = self::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('viewing_role', $viewingRole)
            ->where('leave_owner_role', $ownerRole)
            ->where('same_branch_only', $sameBranch)
            ->first();

        return $row ? $row->can_see : false;
    }

    /**
     * Get the full matrix for an agency.
     */
    public static function matrixForAgency(int $agencyId): \Illuminate\Database\Eloquent\Collection
    {
        return self::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->orderBy('viewing_role')
            ->orderBy('leave_owner_role')
            ->get();
    }

    /**
     * Default matrix rows for a new agency.
     */
    public static function defaultRows(): array
    {
        return [
            // Agent sees nobody else's leave (same branch)
            ['viewing_role' => 'agent', 'leave_owner_role' => 'agent', 'same_branch_only' => true, 'can_see' => false],
            ['viewing_role' => 'agent', 'leave_owner_role' => 'bm', 'same_branch_only' => true, 'can_see' => false],
            ['viewing_role' => 'agent', 'leave_owner_role' => 'admin', 'same_branch_only' => true, 'can_see' => false],

            // BM sees same-branch agents and BMs
            ['viewing_role' => 'bm', 'leave_owner_role' => 'agent', 'same_branch_only' => true, 'can_see' => true],
            ['viewing_role' => 'bm', 'leave_owner_role' => 'bm', 'same_branch_only' => true, 'can_see' => true],
            ['viewing_role' => 'bm', 'leave_owner_role' => 'admin', 'same_branch_only' => true, 'can_see' => false],
            // BM does NOT see other branches
            ['viewing_role' => 'bm', 'leave_owner_role' => 'agent', 'same_branch_only' => false, 'can_see' => false],
            ['viewing_role' => 'bm', 'leave_owner_role' => 'bm', 'same_branch_only' => false, 'can_see' => false],

            // Admin sees all agency leave
            ['viewing_role' => 'admin', 'leave_owner_role' => 'agent', 'same_branch_only' => false, 'can_see' => true],
            ['viewing_role' => 'admin', 'leave_owner_role' => 'bm', 'same_branch_only' => false, 'can_see' => true],
            ['viewing_role' => 'admin', 'leave_owner_role' => 'admin', 'same_branch_only' => false, 'can_see' => true],

            // Super admin sees all
            ['viewing_role' => 'super_admin', 'leave_owner_role' => 'agent', 'same_branch_only' => false, 'can_see' => true],
            ['viewing_role' => 'super_admin', 'leave_owner_role' => 'bm', 'same_branch_only' => false, 'can_see' => true],
            ['viewing_role' => 'super_admin', 'leave_owner_role' => 'admin', 'same_branch_only' => false, 'can_see' => true],
            ['viewing_role' => 'super_admin', 'leave_owner_role' => 'super_admin', 'same_branch_only' => false, 'can_see' => true],
        ];
    }
}
