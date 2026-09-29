<?php

namespace App\Http\Controllers\CommandCenter;

use App\Http\Controllers\Controller;
use App\Jobs\RegenerateBuyerMatchesJob;
use App\Models\AgencyContactSettings;
use App\Models\AgencyLeaveVisibilityMatrix;
use App\Models\Property;
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

        // Core Matches (working window + status allow-list) moved to main
        // Settings, 2026-09-29 — this page links to it instead of rendering
        // it; see updateCoreMatches() and corex/settings.blade.php.
        return view('command-center.settings.contact-governance', [
            'settings' => $settings,
        ]);
    }

    /**
     * Save contact governance settings.
     */
    public function updateContactGovernance(Request $request)
    {
        $agencyId = $this->resolveAgencyId();

        $request->validate([
            'buyer_pipeline_default_scope' => 'required|in:own,branch,agency',
            'buyer_kanban_column_limit' => 'required|integer|min:10|max:500',
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

        $settings = AgencyContactSettings::forAgency($agencyId);

        $settings->update(array_merge(
            $request->only([
                'buyer_pipeline_default_scope',
                'buyer_kanban_column_limit',
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
     * Save Core Matches settings (working window + status allow-list).
     *
     * Moved out of the combined Contact Governance save, 2026-09-29 (Johan's
     * direction) — Core Matches settings now live in main Settings (its own
     * section/tab, corex/settings.blade.php, activeSection === 'core-matches'),
     * with their own save action instead of riding the giant Contact
     * Governance form. Same stored columns on the SAME AgencyContactSettings
     * row as before — this is a relocation of the form, not a new setting or
     * a second copy of it. Contact Governance now only links here.
     */
    public function updateCoreMatches(Request $request)
    {
        $agencyId = $this->resolveAgencyId();
        // AT-Core-Matches (2026-09-29) — validate against the SAME known-status
        // list the picker offers (code default ∪ this agency's vocabulary), so
        // a crafted request can't smuggle in a status the UI never offered.
        $knownStatuses = collect(Property::CORE_MATCH_DEFAULT_ALLOWED_STATUSES)
            ->merge(Property::allowedStatuses($agencyId ?: null))
            ->unique()
            ->values()
            ->all();

        $request->validate([
            // AT-Core-Matches, Johan's ruling 5 — the Core Matches working window, never hardcoded.
            'core_matches_working_window_days' => 'required|integer|min:1|max:90',
            // AT-Core-Matches (2026-09-29) — an empty selection is explicitly
            // not allowed (Johan): Core Matches showing NOTHING is never a
            // valid configured state, only ever a bug.
            'core_matches_allowed_statuses' => 'required|array|min:1',
            'core_matches_allowed_statuses.*' => ['required', 'string', 'in:' . implode(',', $knownStatuses)],
        ]);

        $settings = AgencyContactSettings::forAgency($agencyId);
        $previousStatuses = $settings->coreMatchesAllowedStatuses();

        $settings->update($request->only([
            'core_matches_working_window_days',
            'core_matches_allowed_statuses',
        ]));

        // AT-Core-Matches (2026-09-29) — the allow-list changed, so this
        // agency's cached property_buyer_matches (pipeline counts/badges,
        // AT-108) is stale until rebuilt: rows for a now-excluded status must
        // drop, and rows for a newly-included status must appear. Queued
        // (ShouldQueue) — RegenerateBuyerMatchesJob is the existing rebuild
        // path (truncate=true, agency-scoped), not a new mechanism.
        $newStatuses = $settings->fresh()->coreMatchesAllowedStatuses();
        if ($agencyId > 0 && $this->normalizedStatusList($previousStatuses) !== $this->normalizedStatusList($newStatuses)) {
            AgencyContactSettings::clearCoreMatchAllowedStatusesCache();
            RegenerateBuyerMatchesJob::dispatch($agencyId, null, true);
        }

        return redirect()->route('corex.settings', ['s' => 'core-matches'])
            ->with('success', 'Core Matches settings saved.');
    }

    /** Sort + lower-case a status list so two differently-ordered/cased lists compare equal. */
    private function normalizedStatusList(array $statuses): array
    {
        $normalized = array_values(array_unique(array_map(fn ($s) => strtolower(trim((string) $s)), $statuses)));
        sort($normalized);
        return $normalized;
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
