<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Proforma\AgencyProformaSettings;
use App\Services\Proforma\ProformaAdminService;
use Illuminate\Http\Request;

/**
 * Agency "Proforma Invoices" settings section (permission:proforma.manage):
 * numbering (prefix + start number + padding), due-date rule, bank details.
 * Letterhead/logo/VAT-no/vat_registered are shown read-only (they live on branding).
 */
class ProformaSettingsController extends Controller
{
    // Gated by route middleware `permission:proforma.manage` (admin only).

    /**
     * The agency whose proforma settings are being read or saved.
     *
     * Owners are cross-agency until they switch into one, so for them the
     * agency comes from the form (`agency_id`, posted by the Company Settings
     * page for the agency it is SHOWING); everyone else is pinned to their own.
     * A login with no resolvable agency is sent back with a plain message —
     * never the `(int) null` = 0 sentinel that BelongsToAgency refuses (Rule 17,
     * the 500 Andre hit on QA2 2026-09-15).
     */
    private function agencyId(Request $request): int
    {
        $user = $request->user();

        $requested = (int) $request->input('agency_id', 0);
        if ($requested > 0 && $user?->isOwnerRole() && Agency::whereKey($requested)->exists()) {
            return $requested;
        }

        $agencyId = (int) ($user?->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            abort(redirect()->route('admin.company-settings')->with(
                'error',
                'Choose an agency first — proforma settings belong to one agency. Use the agency switcher, then try again.'
            ));
        }

        return $agencyId;
    }

    /** Land back on the Company Settings tab the save came from, when it came from there. */
    private function redirectAfterSave(Request $request)
    {
        if ($request->filled('from_company_settings')) {
            return redirect()->route('admin.company-settings', ['agency' => $request->input('from_company_settings')])
                ->withFragment('company');
        }

        return back();
    }

    public function index(Request $request)
    {
        $agencyId = $this->agencyId($request);

        return view('admin.proforma-settings.index', [
            'settings' => AgencyProformaSettings::forAgency($agencyId),
            'agency'   => Agency::withoutGlobalScopes()->find($agencyId),
        ]);
    }

    public function update(Request $request, ProformaAdminService $admin)
    {
        $agencyId = $this->agencyId($request);
        $settings = AgencyProformaSettings::forAgency($agencyId);

        $data = $request->validate([
            'number_prefix'  => ['nullable', 'string', 'max:16'],
            'number_padding' => ['required', 'integer', 'min:1', 'max:10'],
            'start_number'   => ['nullable', 'integer', 'min:1'],
            'due_date_rule'  => ['required', 'in:end_of_month,days_after,on_receipt'],
            'due_days'       => ['required', 'integer', 'min:0', 'max:365'],
            'bank_details'   => ['nullable', 'string', 'max:2000'],
        ]);

        // Start number advances forward only (never reuse) — routed through the audited service.
        if (! empty($data['start_number']) && (int) $data['start_number'] > $settings->next_number) {
            try {
                $admin->advanceStartNumber($agencyId, $request->user(), (int) $data['start_number']);
            } catch (\DomainException $e) {
                return $this->redirectAfterSave($request)->with('error', $e->getMessage());
            }
        }

        $settings->update([
            'number_prefix'  => $data['number_prefix'] ?: 'PRO-',
            'number_padding' => $data['number_padding'],
            'due_date_rule'  => $data['due_date_rule'],
            'due_days'       => $data['due_days'],
            'bank_details'   => $data['bank_details'] ?? null,
        ]);

        return $this->redirectAfterSave($request)->with('success', 'Proforma settings saved.');
    }
}
