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

    private function agencyId(Request $request): int
    {
        return (int) $request->user()?->effectiveAgencyId();
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
                return back()->with('error', $e->getMessage());
            }
        }

        $settings->update([
            'number_prefix'  => $data['number_prefix'] ?: 'PRO-',
            'number_padding' => $data['number_padding'],
            'due_date_rule'  => $data['due_date_rule'],
            'due_days'       => $data['due_days'],
            'bank_details'   => $data['bank_details'] ?? null,
        ]);

        return back()->with('success', 'Proforma settings saved.');
    }
}
