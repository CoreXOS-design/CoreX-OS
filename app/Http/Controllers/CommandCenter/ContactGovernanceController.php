<?php

namespace App\Http\Controllers\CommandCenter;

use App\Http\Controllers\Controller;
use App\Models\AgencyContactSettings;
use App\Models\AgencyLeaveVisibilityMatrix;
use Illuminate\Http\Request;

class ContactGovernanceController extends Controller
{
    /**
     * Resolve the agency whose governance settings are being managed.
     *
     * AT-253 (STANDARDS Rule 17). This used to fall back to agency 1 for a super-admin with no
     * agency — and said so in the comment, as though it were a decision rather than the bug.
     * It is the bug: it silently pointed a no-tenant user at HOME FINDERS' contact-governance
     * settings, which are POPIA retention rules. Reading them under the wrong tenant is
     * misleading; writing them is a compliance change to an agency the actor does not belong to.
     *
     * A no-agency actor now resolves to the sentinel 0, which matches no agency and reads
     * nothing. Correct answer, honestly empty.
     */
    private function resolveAgencyId(): int
    {
        return (int) (auth()->user()?->effectiveAgencyId() ?: 0);
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
            // AT-60 — address-duplicate-guard aggressiveness for the
            // "Use for property" transfer.
            'address_match_mode' => 'required|in:off,standard,strict',
            // Part 3 — warn an agent when they capture an address HFC already holds.
            'warn_on_held_address_capture' => 'nullable|boolean',
            // Buyer loop — auto-seed a criteria-bearing buyer from a portal/listing lead.
            'portal_lead_auto_seed_buyer' => 'nullable|boolean',
            'buyer_warm_days' => 'required|integer|min:1|max:365',
            'buyer_cold_days' => 'required|integer|min:1|max:365',
            'buyer_lost_days' => 'required|integer|min:1|max:730',
            // AT-81 — no-response window before a pending outreach contact lapses.
            'outreach_no_response_days' => 'required|integer|min:1|max:365',
            'contact_retention_years' => 'required|integer|min:5|max:99',
            'consent_retention_years' => 'required|integer|min:5|max:99',
            'access_log_retention_years' => 'required|integer|min:5|max:99',
        ]);

        $agencyId = $this->resolveAgencyId();
        $settings = AgencyContactSettings::forAgency($agencyId);

        $settings->update(array_merge(
            $request->only([
                'buyer_pipeline_default_scope',
                'duplicate_mode',
                'duplicate_match_fields',
                'address_match_mode',
                'buyer_warm_days',
                'buyer_cold_days',
                'buyer_lost_days',
                'outreach_no_response_days',
                'contact_retention_years',
                'consent_retention_years',
                'access_log_retention_years',
            ]),
            // Checkboxes — absent when unticked, so resolve explicitly.
            [
                'warn_on_held_address_capture' => $request->boolean('warn_on_held_address_capture'),
                'portal_lead_auto_seed_buyer'  => $request->boolean('portal_lead_auto_seed_buyer'),
            ],
        ));

        return back()->with('success', 'Contact governance settings saved.');
    }

    /**
     * Save leave visibility matrix.
     */
    public function updateLeaveVisibility(Request $request)
    {
        $agencyId = $this->resolveAgencyId();
        $roles = \App\Models\Role::allRoles($agencyId)->pluck('name')->reject(fn($r) => $r === 'super_admin')->values()->toArray();

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

        return redirect()->route('corex.settings', ['s' => 'leave-visibility'])
            ->with('success', 'Leave visibility matrix saved.');
    }
}
