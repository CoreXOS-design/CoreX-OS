{{--
    .ai/specs/rental-work-orders.md §17.31 — the supplier-invoice upload limits. Own form, own narrow saver
    (RentalWorkOrderInvoiceSettingsController::update — writes only these two columns), so it can never disturb the other
    sections of this page. Both controls are also Setup Wizard rows (config/agency-onboarding-copy.php, `rental_work_orders`).
--}}
@php
    $iAgencyId = auth()->user()?->effectiveAgencyId();
    $iMax = \App\Models\RentalWorkOrderSetting::invoiceMaxFileMbFor($iAgencyId);
    $iTypes = \App\Models\RentalWorkOrderSetting::invoiceAllowedFileTypesFor($iAgencyId);
@endphp
<form method="POST" action="{{ route('corex.settings.rental-work-orders.invoice-limits') }}" class="space-y-3" id="invoice-limit-settings">
    @csrf
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
        <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
            <h3 class="text-sm font-bold" style="color:var(--text-primary);">Supplier invoices</h3>
        </div>
        <div class="p-5 space-y-5">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="invoice_max_file_mb">Largest invoice file (MB)</label>
                <input type="number" id="invoice_max_file_mb" name="invoice_max_file_mb" value="{{ old('invoice_max_file_mb', $iMax) }}" min="1" max="{{ \App\Models\RentalWorkOrderSetting::MAX_INVOICE_MAX_FILE_MB }}" step="1"
                       class="w-full max-w-[160px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                @error('invoice_max_file_mb')<p class="text-xs mt-1" style="color:#b3261e;">{{ $message }}</p>@enderror
                <p class="text-xs mt-2" style="color: var(--text-muted);">Default is {{ \App\Models\RentalWorkOrderSetting::DEFAULT_INVOICE_MAX_FILE_MB }} MB; the most that can be set is {{ \App\Models\RentalWorkOrderSetting::MAX_INVOICE_MAX_FILE_MB }} MB. A bigger file is refused with a plain message.</p>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="invoice_allowed_file_types">File types allowed for an invoice</label>
                <select id="invoice_allowed_file_types" name="invoice_allowed_file_types" class="w-full max-w-[420px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                    <option value="pdf" @selected(old('invoice_allowed_file_types', $iTypes) === 'pdf')>PDF only</option>
                    <option value="pdf_images" @selected(old('invoice_allowed_file_types', $iTypes) === 'pdf_images')>PDF and photos (JPG, PNG, WebP)</option>
                    <option value="pdf_images_heic" @selected(old('invoice_allowed_file_types', $iTypes) === 'pdf_images_heic')>PDF and photos, including iPhone HEIC</option>
                </select>
                @error('invoice_allowed_file_types')<p class="text-xs mt-1" style="color:#b3261e;">{{ $message }}</p>@enderror
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Save supplier invoice settings</button>
        </div>
    </div>
</form>
