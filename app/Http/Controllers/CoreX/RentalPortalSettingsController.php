<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\RentalPortalSetting;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * .ai/specs/rental-portal-access.md §7 — AT-445. Mirrors
 * RentalWorkOrderSettingsController's shape exactly: one saver for the
 * single required numeric control, and one narrow, independent,
 * has()-guarded saver PER boolean toggle — never a single combined
 * saver. .ai/specs/agency-onboarding-setup.md §6.1: the wizard step posts
 * only a SUBSET of a shared saver's fields, so a saver requiring several
 * fields at once would reject (or, worse, silently wipe) a step render
 * that only shows some of them. Each toggle below is independently safe
 * to register as its own saver on the onboarding wizard.
 */
class RentalPortalSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        $agencyId = $request->user()->effectiveAgencyId();

        return view('corex.settings.rental-portal', [
            'tenantPortalEnabled' => RentalPortalSetting::tenantPortalEnabledFor($agencyId),
            'landlordPortalEnabled' => RentalPortalSetting::landlordPortalEnabledFor($agencyId),
            'contractorLinksEnabled' => RentalPortalSetting::contractorLinksEnabledFor($agencyId),
            'contractorSecureLinkExpiryDays' => RentalPortalSetting::contractorSecureLinkExpiryDaysFor($agencyId),
            'notifyLandlordOnDecisionNeeded' => RentalPortalSetting::notifyLandlordOnDecisionNeededFor($agencyId),
            'notifyTenantOnStatusChange' => RentalPortalSetting::notifyTenantOnStatusChangeFor($agencyId),
            'autoPortalAccessOnSigning' => RentalPortalSetting::autoPortalAccessOnSigningFor($agencyId),
            'defaultContractorSecureLinkExpiryDays' => RentalPortalSetting::DEFAULT_CONTRACTOR_SECURE_LINK_EXPIRY_DAYS,
            // §14.27.3 — crew links.
            'crewLinksEnabled' => RentalPortalSetting::crewLinksEnabledFor($agencyId),
            'crewJobLinkExpiryDays' => RentalPortalSetting::crewJobLinkExpiryDaysFor($agencyId),
            'crewLinkShowCosts' => RentalPortalSetting::crewLinkShowCostsFor($agencyId),
            'crewLinkShowTenantContact' => RentalPortalSetting::crewLinkShowTenantContactFor($agencyId),
            'notifyLandlordOnCrewCompletion' => RentalPortalSetting::notifyLandlordOnCrewCompletionFor($agencyId),
            'defaultCrewJobLinkExpiryDays' => RentalPortalSetting::DEFAULT_CREW_JOB_LINK_EXPIRY_DAYS,
            // rental-work-orders.md §14.27.3 — Build 2 (crew page & client visibility).
            'crewPhotosVisibleToClients' => RentalPortalSetting::crewPhotosVisibleToClientsFor($agencyId),
            'crewStandingLinkExpiryDays' => RentalPortalSetting::crewStandingLinkExpiryDaysFor($agencyId),
            'crewPageRecentCompletedDays' => RentalPortalSetting::crewPageRecentCompletedDaysFor($agencyId),
            'crewPageUpcomingDays' => RentalPortalSetting::crewPageUpcomingDaysFor($agencyId),
            'defaultCrewPageRecentCompletedDays' => RentalPortalSetting::DEFAULT_CREW_PAGE_RECENT_COMPLETED_DAYS,
            'defaultCrewPageUpcomingDays' => RentalPortalSetting::DEFAULT_CREW_PAGE_UPCOMING_DAYS,
        ]);
    }

    /** The one required-numeric control. */
    public function update(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'contractor_secure_link_expiry_days' => ['required', 'integer', 'min:1', 'max:90'],
        ]);

        RentalPortalSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['contractor_secure_link_expiry_days' => $validated['contractor_secure_link_expiry_days']],
        );

        return redirect()->route('corex.settings.rental-portal.edit')->with('success', 'Contractor link expiry saved.');
    }

    public function updateTenantPortalEnabled(Request $request): RedirectResponse
    {
        return $this->updateToggle($request, 'tenant_portal_enabled', 'Tenant portal access');
    }

    public function updateLandlordPortalEnabled(Request $request): RedirectResponse
    {
        return $this->updateToggle($request, 'landlord_portal_enabled', 'Landlord portal access');
    }

    public function updateContractorLinksEnabled(Request $request): RedirectResponse
    {
        return $this->updateToggle($request, 'contractor_links_enabled', 'Contractor secure links');
    }

    public function updateNotifyLandlordOnDecisionNeeded(Request $request): RedirectResponse
    {
        return $this->updateToggle($request, 'notify_landlord_on_decision_needed', 'Landlord decision-needed notification');
    }

    public function updateNotifyTenantOnStatusChange(Request $request): RedirectResponse
    {
        return $this->updateToggle($request, 'notify_tenant_on_status_change', 'Tenant status-change notification');
    }

    /** rental-portal-access.md §16 — has()-guarded like every other toggle (onboarding §6.1). */
    public function updateAutoPortalAccessOnSigning(Request $request): RedirectResponse
    {
        return $this->updateToggle($request, 'auto_portal_access_on_signing', 'Automatic portal access when a lease is signed');
    }

    // ── §14.27.3 — crew links (rental-work-orders.md §14.28). Each one is its own narrow, has()-guarded saver (onboarding §6.1). ──

    public function updateCrewLinksEnabled(Request $request): RedirectResponse
    {
        return $this->updateToggle($request, 'crew_links_enabled', 'Crew links');
    }

    public function updateCrewLinkShowCosts(Request $request): RedirectResponse
    {
        return $this->updateToggle($request, 'crew_link_show_costs', 'Costs on crew links');
    }

    public function updateCrewLinkShowTenantContact(Request $request): RedirectResponse
    {
        return $this->updateToggle($request, 'crew_link_show_tenant_contact', 'Tenant contact on crew links');
    }

    public function updateNotifyLandlordOnCrewCompletion(Request $request): RedirectResponse
    {
        return $this->updateToggle($request, 'notify_landlord_on_crew_completion', 'Landlord email on crew completion');
    }

    /**
     * The numeric control. Absent = leave the saved value alone (the wizard step
     * posts a SUBSET of fields — onboarding §6.1); present must be 1-90.
     */
    public function updateCrewJobLinkExpiryDays(Request $request): RedirectResponse
    {
        if (! $request->has('crew_job_link_expiry_days')) {
            return redirect()->route('corex.settings.rental-portal.edit');
        }

        $validated = $request->validate([
            'crew_job_link_expiry_days' => ['required', 'integer', 'min:1', 'max:90'],
        ]);

        RentalPortalSetting::updateOrCreate(
            ['agency_id' => $request->user()->effectiveAgencyId()],
            ['crew_job_link_expiry_days' => $validated['crew_job_link_expiry_days']],
        );

        return redirect()->route('corex.settings.rental-portal.edit')->with('success', 'Crew link expiry saved.');
    }

    /**
     * rental-work-orders.md §14.27.3 — which crew photos a tenant / landlord sees.
     * Narrow, independent saver (agency-onboarding-setup.md §6.1): absent from
     * the post means "leave it alone", never a reset to the default.
     */
    public function updateCrewPhotosVisibleToClients(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        if (!$request->has('crew_photos_visible_to_clients')) {
            return redirect()->route('corex.settings.rental-portal.edit');
        }

        $validated = $request->validate([
            'crew_photos_visible_to_clients' => ['required', 'string', 'in:' . implode(',', RentalPortalSetting::CREW_PHOTO_VISIBILITY_OPTIONS)],
        ]);

        RentalPortalSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['crew_photos_visible_to_clients' => $validated['crew_photos_visible_to_clients']],
        );

        return redirect()->route('corex.settings.rental-portal.edit')->with('success', 'Crew photo visibility saved.');
    }

    /**
     * §14.27.3 — how long the crew PAGE link lives. Present-but-blank means "no
     * expiry — stands until revoked" (clears the value); absent from the post
     * means leave it alone (agency-onboarding-setup.md §6.1).
     */
    public function updateCrewStandingLinkExpiryDays(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        if (!$request->has('crew_standing_link_expiry_days')) {
            return redirect()->route('corex.settings.rental-portal.edit');
        }

        $validated = $request->validate([
            'crew_standing_link_expiry_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ], ['crew_standing_link_expiry_days.*' => 'The crew page link can last between 1 and 365 days — or leave it blank to keep it until you revoke it.']);

        RentalPortalSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['crew_standing_link_expiry_days' => $validated['crew_standing_link_expiry_days'] ?? null],
        );

        return redirect()->route('corex.settings.rental-portal.edit')->with('success', 'Crew page link expiry saved.');
    }

    /** §14.27.3 — "recently completed" window on the crew page; 0 hides the list. Absent or blank = leave alone. */
    public function updateCrewPageRecentCompletedDays(Request $request): RedirectResponse
    {
        return $this->updateBoundedInt($request, 'crew_page_recent_completed_days', 0, 30, 'Recently-completed window');
    }

    /** §14.27.3 — how many days ahead the crew page's "upcoming" list reaches. Absent or blank = leave alone. */
    public function updateCrewPageUpcomingDays(Request $request): RedirectResponse
    {
        return $this->updateBoundedInt($request, 'crew_page_upcoming_days', 1, 60, 'Upcoming window');
    }

    private function updateBoundedInt(Request $request, string $field, int $min, int $max, string $label): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();
        $raw = $request->input($field);

        // Absent or blank: the wizard step (or an older form) did not carry this field — leave the stored value alone.
        if ($raw === null || $raw === '') {
            return redirect()->route('corex.settings.rental-portal.edit');
        }

        $validated = $request->validate([
            $field => ['integer', "min:{$min}", "max:{$max}"],
        ], ["{$field}.*" => "{$label} must be a whole number from {$min} to {$max}."]);

        RentalPortalSetting::updateOrCreate(['agency_id' => $agencyId], [$field => (int) $validated[$field]]);

        return redirect()->route('corex.settings.rental-portal.edit')->with('success', "{$label} saved.");
    }

    private function updateToggle(Request $request, string $field, string $label): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        if (!$request->has($field)) {
            return redirect()->route('corex.settings.rental-portal.edit')
                ->withErrors([$field => 'That did not save — please try again.']);
        }

        RentalPortalSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            [$field => $request->boolean($field)],
        );

        return redirect()->route('corex.settings.rental-portal.edit')->with('success', "{$label} saved.");
    }
}
