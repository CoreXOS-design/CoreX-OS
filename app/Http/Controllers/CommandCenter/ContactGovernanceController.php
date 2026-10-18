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

        // Won/Lost buyers (Johan, 2026-10-07) — which Buyer Pipeline statuses take a buyer off
        // Core Matches. Guarded by its own marker so a post that never rendered the group
        // (e.g. an older cached form) can never wipe the saved choice.
        if ($request->has('core_matches_excluded_buyer_states_present')) {
            $this->saveExcludedBuyerStates($request, $agencyId);
        }

        return redirect()->route('corex.settings', ['s' => 'core-matches'])
            ->with('success', 'Core Matches settings saved.');
    }

    /**
     * Narrow saver for the Setup Wizard's Core Matches step (spec agency-onboarding-setup.md
     * §6.1): touches ONLY the excluded-buyer-states choice, and only when the step actually
     * rendered it. Same write path as the settings page (CoreMatchBuyerGate::saveExcludedStates).
     */
    public function updateCoreMatchesExcludedBuyerStates(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('command_center.settings'), 403);

        if ($request->has('core_matches_excluded_buyer_states_present')) {
            $this->saveExcludedBuyerStates($request, $this->resolveAgencyId());
        }

        return back();
    }

    /**
     * Lead response time (Johan, 2026-10-07; .ai/specs/lead-response-time.md) — "respond within N minutes" and the
     * per-weekday counting hours. ONE saver for the Settings page AND the Setup Wizard step (spec §6.1: it only
     * writes what was actually posted — the `lead_response_present` marker says the form rendered these controls,
     * and each field is written only if present, so a step that posts a subset never wipes the rest).
     */
    public function updateLeadResponse(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('command_center.settings'), 403);

        if (! $request->has('lead_response_present')) {
            return back();
        }

        $data = $request->validate([
            'lead_response_target_minutes' => 'nullable|integer|min:1|max:10080',
            'lead_response_hours' => 'nullable|array',
        ]);

        $service = app(\App\Services\LeadResponse\LeadResponseSettingsService::class);
        $hours = $request->has('lead_response_hours') ? $service->validateHours((array) $request->input('lead_response_hours')) : null;
        $target = $request->filled('lead_response_target_minutes') ? (int) $data['lead_response_target_minutes'] : null;

        $changed = $service->save($this->resolveAgencyId(), $target, $hours, auth()->id());

        return back()->with('success', $changed ? 'Lead response settings saved.' : 'Lead response settings unchanged.');
    }

    private function saveExcludedBuyerStates(Request $request, int $agencyId): void
    {
        $request->validate([
            'core_matches_excluded_buyer_states'   => 'nullable|array',
            'core_matches_excluded_buyer_states.*' => ['string', 'in:' . implode(',', \App\Services\BuyerStateService::PIPELINE_STATES)],
        ]);

        \App\Services\Matching\CoreMatchBuyerGate::saveExcludedStates(
            $agencyId,
            (array) $request->input('core_matches_excluded_buyer_states', []),
            auth()->id()
        );
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
