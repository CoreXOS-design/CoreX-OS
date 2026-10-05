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
            'defaultContractorSecureLinkExpiryDays' => RentalPortalSetting::DEFAULT_CONTRACTOR_SECURE_LINK_EXPIRY_DAYS,
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
