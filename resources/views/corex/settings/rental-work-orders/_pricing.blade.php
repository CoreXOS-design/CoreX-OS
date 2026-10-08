{{--
    .ai/specs/rental-work-orders.md §17.14 / §17.11 — BUILD 1's settings section: the agency's default markup on parts and on
    labour (§17.4.3 rule 6), and the estimate wording printed on every owner quote (R6, "Restore default").
    Each form posts to its OWN narrow saver (RentalWorkOrderPricingSettingsController) — a saver never writes a key that is not
    in its request, so these never wipe each other or the settings above. Both are also Setup Wizard controls
    (config/agency-onboarding-copy.php, rental_work_orders step).
    Reads the model accessors directly (no variables from the parent page needed).
--}}
@php
    $wo = \App\Models\RentalWorkOrderSetting::class;
    $agencyIdForPricing = auth()->user()->effectiveAgencyId();
    $partsMarkup = $wo::defaultPartsMarkupPercentFor($agencyIdForPricing);
    $labourMarkup = $wo::defaultLabourMarkupPercentFor($agencyIdForPricing);
    $estimateTerm = $wo::quoteEstimateTermFor($agencyIdForPricing);
    $estimateIsDefault = trim($estimateTerm) === trim($wo::DEFAULT_QUOTE_ESTIMATE_TERM);
    $fmtPct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') ?: '0';
@endphp

<form method="POST" action="{{ route('corex.settings.rental-work-orders.default-markups') }}" class="space-y-3" id="pricing-markups">
    @csrf
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
        <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
            <h3 class="text-sm font-bold" style="color:var(--text-primary);">Default markup on parts and labour</h3>
        </div>
        <div class="p-5 space-y-4">
            <p class="text-xs" style="color: var(--text-muted);">
                The crew records what a part or job <strong>actually cost</strong>; the owner is charged <strong>cost plus the markup</strong>. These two percentages
                apply to every line unless the office sets a price or a markup on that line, or on the job card. Leave them at 0 to charge the owner exactly what it cost.
            </p>
            <div class="flex flex-wrap gap-6">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Markup on parts (%)</label>
                    <input type="number" name="default_parts_markup_percent" value="{{ old('default_parts_markup_percent', $fmtPct($partsMarkup)) }}"
                           min="0" max="1000" step="0.01" class="w-full max-w-[140px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Markup on labour (%)</label>
                    <input type="number" name="default_labour_markup_percent" value="{{ old('default_labour_markup_percent', $fmtPct($labourMarkup)) }}"
                           min="0" max="1000" step="0.01" class="w-full max-w-[140px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                </div>
            </div>
            <p class="text-xs" style="color: var(--text-muted);">For example, 20 % on parts turns a part that cost R100 into R120 on the quote. Default: 0 % (no markup).</p>
        </div>
    </div>
    <div class="flex justify-end"><button type="submit" class="corex-btn-primary text-sm">Save</button></div>
</form>

<form method="POST" action="{{ route('corex.settings.rental-work-orders.quote-estimate-term') }}" class="space-y-3" id="pricing-estimate-term">
    @csrf
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
        <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
            <h3 class="text-sm font-bold" style="color:var(--text-primary);">Estimate wording on owner quotes</h3>
        </div>
        <div class="p-5 space-y-3">
            <p class="text-xs" style="color: var(--text-muted);">
                Printed on every quote sent to an owner (the PDF and the email). The wording in force when a quote is sent is kept with that quote, so changing it here never
                alters a quote that has already gone out.
            </p>
            <textarea name="quote_estimate_term" rows="5" maxlength="2000" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">{{ old('quote_estimate_term', $estimateTerm) }}</textarea>
            <p class="text-xs" style="color: var(--text-muted);">Up to 2000 characters. {{ $estimateIsDefault ? 'Currently using the standard wording.' : 'Currently using your own wording.' }}</p>
        </div>
    </div>
    <div class="flex justify-end gap-2">
        <button type="submit" name="restore_default" value="1" class="corex-btn-outline text-sm" data-confirm="Restore the standard estimate wording?">Restore default</button>
        <button type="submit" class="corex-btn-primary text-sm">Save</button>
    </div>
</form>
