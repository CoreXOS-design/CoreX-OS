<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\RentalSettingAuditEntry;
use App\Models\RentalWorkOrderSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-work-orders.md §17.31 — the two supplier-invoice upload limits (largest file, which file types), the
 * "Supplier invoices" section of Settings → Rental Work Orders and its Setup Wizard control rows. Its OWN narrow saver: it
 * writes only these two RentalWorkOrderSetting columns, each ONLY when present in the request (has()-guarded), so the wizard —
 * which posts a subset of a step's fields (onboarding spec §6.1) — can run it beside the other rental_work_orders savers without
 * wiping a setting its step did not render. Every change is written to the rental settings audit trail.
 *
 *   invoice_max_file_mb          1–50   default 10
 *   invoice_allowed_file_types   pdf | pdf_images | pdf_images_heic   default pdf_images
 *
 * Guarded by `rental_work_orders.manage_settings` here as well as on the route, because the wizard calls this method directly.
 */
class RentalWorkOrderInvoiceSettingsController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('rental_work_orders.manage_settings'), 403);

        $agencyId = $request->user()->effectiveAgencyId();

        $request->validate([
            'invoice_max_file_mb' => ['nullable', 'integer', 'min:1', 'max:' . RentalWorkOrderSetting::MAX_INVOICE_MAX_FILE_MB],
            'invoice_allowed_file_types' => ['nullable', 'in:' . implode(',', array_keys(RentalWorkOrderSetting::INVOICE_TYPE_EXTENSIONS))],
        ], [
            'invoice_max_file_mb.integer' => 'The largest invoice file must be a whole number of megabytes.',
            'invoice_max_file_mb.min' => 'The largest invoice file must be at least 1 MB.',
            'invoice_max_file_mb.max' => 'The largest invoice file can be at most ' . RentalWorkOrderSetting::MAX_INVOICE_MAX_FILE_MB . ' MB.',
        ]);

        $before = [
            'invoice_max_file_mb' => RentalWorkOrderSetting::invoiceMaxFileMbFor($agencyId),
            'invoice_allowed_file_types' => RentalWorkOrderSetting::invoiceAllowedFileTypesFor($agencyId),
        ];

        $data = [];
        if ($request->filled('invoice_max_file_mb')) {
            $data['invoice_max_file_mb'] = (int) $request->input('invoice_max_file_mb');
        }
        if ($request->filled('invoice_allowed_file_types')) {
            $data['invoice_allowed_file_types'] = (string) $request->input('invoice_allowed_file_types');
        }

        if ($data !== []) {
            RentalWorkOrderSetting::updateOrCreate(['agency_id' => $agencyId], $data);
            foreach ($data as $key => $value) {
                RentalSettingAuditEntry::record($agencyId, $request->user(), $key, $before[$key], $value, $request->routeIs('corex.settings.*') ? 'settings' : 'wizard');
            }
        }

        return redirect()->route('corex.settings.rental-work-orders.edit')->with('success', 'Supplier invoice settings saved.');
    }
}
