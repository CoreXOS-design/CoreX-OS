<?php

namespace App\Http\Controllers\CommandCenter;

use App\Http\Controllers\Controller;
use App\Models\AgencyContactSettings;
use App\Models\AgencyLeaveVisibilityMatrix;
use Illuminate\Http\Request;

class ContactGovernanceController extends Controller
{
    /**
     * Resolve the agency_id to manage settings for.
     * Super_admin without agency falls back to agency_id=1 (HFC).
     */
    private function resolveAgencyId(): int
    {
        $agencyId = auth()->user()->effectiveAgencyId();
        return $agencyId ?? 1;
    }

    /**
     * Contact Governance settings page.
     */
    public function contactGovernance()
    {
        $agencyId = $this->resolveAgencyId();
        $settings = AgencyContactSettings::forAgency($agencyId);

        return view('command-center.settings.contact-governance', [
            'settings' => $settings,
        ]);
    }

    /**
     * Save contact governance settings.
     */
    public function updateContactGovernance(Request $request)
    {
        $request->validate([
            'buyer_pipeline_default_scope' => 'required|in:own,branch,agency',
            'duplicate_mode' => 'required|in:auto_link,soft_warn,hard_block_override,hard_block_request',
            'duplicate_match_fields' => 'required|array|min:1',
            'duplicate_match_fields.*' => 'in:phone,email,id_number',
            'buyer_warm_days' => 'required|integer|min:1|max:365',
            'buyer_cold_days' => 'required|integer|min:1|max:365',
            'buyer_lost_days' => 'required|integer|min:1|max:730',
            'contact_retention_years' => 'required|integer|min:5|max:99',
            'consent_retention_years' => 'required|integer|min:5|max:99',
            'access_log_retention_years' => 'required|integer|min:5|max:99',
        ]);

        $agencyId = $this->resolveAgencyId();
        $settings = AgencyContactSettings::forAgency($agencyId);

        $settings->update($request->only([
            'buyer_pipeline_default_scope',
            'duplicate_mode',
            'duplicate_match_fields',
            'buyer_warm_days',
            'buyer_cold_days',
            'buyer_lost_days',
            'contact_retention_years',
            'consent_retention_years',
            'access_log_retention_years',
        ]));

        return back()->with('success', 'Contact governance settings saved.');
    }

    /**
     * Leave Visibility Matrix settings page.
     */
    public function leaveVisibility()
    {
        $agencyId = $this->resolveAgencyId();
        $matrix = AgencyLeaveVisibilityMatrix::matrixForAgency($agencyId);

        // Dynamic roles from Role Manager
        $roles = \App\Models\Role::allRoles()->pluck('name')->toArray();

        // Alias map for legacy data (matrix stores 'bm', roles table has 'branch_manager')
        $roleAliases = ['branch_manager' => 'bm', 'bm' => 'branch_manager'];

        $grid = [];
        foreach ($roles as $viewingRole) {
            foreach ($roles as $ownerRole) {
                // Check both the actual role name and its alias in legacy data
                $viewerVariants = array_filter([$viewingRole, $roleAliases[$viewingRole] ?? null]);
                $ownerVariants = array_filter([$ownerRole, $roleAliases[$ownerRole] ?? null]);

                $sameBranchRow = $matrix->first(fn($r) =>
                    in_array($r->viewing_role, $viewerVariants) &&
                    in_array($r->leave_owner_role, $ownerVariants) &&
                    $r->same_branch_only === true
                );
                $crossBranchRow = $matrix->first(fn($r) =>
                    in_array($r->viewing_role, $viewerVariants) &&
                    in_array($r->leave_owner_role, $ownerVariants) &&
                    $r->same_branch_only === false
                );
                $grid[$viewingRole][$ownerRole] = [
                    'same_branch' => $sameBranchRow ? $sameBranchRow->can_see : false,
                    'cross_branch' => $crossBranchRow ? $crossBranchRow->can_see : false,
                ];
            }
        }

        return view('command-center.settings.leave-visibility', [
            'grid' => $grid,
            'roles' => $roles,
        ]);
    }

    /**
     * Save leave visibility matrix.
     */
    public function updateLeaveVisibility(Request $request)
    {
        $agencyId = $this->resolveAgencyId();
        $roles = \App\Models\Role::allRoles()->pluck('name')->toArray();

        $matrixData = $request->input('matrix', []);

        foreach ($roles as $viewingRole) {
            foreach ($roles as $ownerRole) {
                $cell = $matrixData[$viewingRole][$ownerRole] ?? [];

                // Same branch visibility
                AgencyLeaveVisibilityMatrix::withoutGlobalScopes()->updateOrCreate(
                    [
                        'agency_id' => $agencyId,
                        'viewing_role' => $viewingRole,
                        'leave_owner_role' => $ownerRole,
                        'same_branch_only' => true,
                    ],
                    [
                        'can_see' => !empty($cell['same_branch']),
                    ]
                );

                // Cross-branch visibility
                AgencyLeaveVisibilityMatrix::withoutGlobalScopes()->updateOrCreate(
                    [
                        'agency_id' => $agencyId,
                        'viewing_role' => $viewingRole,
                        'leave_owner_role' => $ownerRole,
                        'same_branch_only' => false,
                    ],
                    [
                        'can_see' => !empty($cell['cross_branch']),
                    ]
                );
            }
        }

        return back()->with('success', 'Leave visibility matrix saved.');
    }
}
