{{--
    .ai/specs/rental-work-orders.md §17.14 — BUILD 2's settings section: approvals. The agency's DEFAULT variation tolerance (each property can
    override it on its Rental tab), whether the owner is emailed when extra work is approved automatically, and the agency's fee on an outside
    contractor's quote (0 = off; a single work order can override it). One narrow saver (RentalWorkOrderSettingsController::updateApprovals);
    the same four controls are in the Setup Wizard. Values are read here (not passed in) so the page's controller stays untouched by this build.
--}}
@php
    $apAgencyId = auth()->user()->effectiveAgencyId();
    $apTolerance = \App\Models\RentalWorkOrderSetting::variationTolerancePercentFor($apAgencyId);
    $apNotifyAuto = \App\Models\RentalWorkOrderSetting::notifyLandlordOnAutoVariationFor($apAgencyId);
    $apFeeType = \App\Models\RentalWorkOrderSetting::externalQuoteMarkupTypeFor($apAgencyId);
    $apFeeValue = \App\Models\RentalWorkOrderSetting::externalQuoteMarkupValueFor($apAgencyId);
    $apEmergency = \App\Models\RentalWorkOrderSetting::emergencySpendThresholdFor($apAgencyId);
    $apEmergencyDefault = \App\Models\RentalWorkOrderSetting::DEFAULT_EMERGENCY_SPEND_THRESHOLD;
    $apMayRecord = \App\Models\RentalWorkOrderSetting::agentsMayRecordOwnerDecisionsFor($apAgencyId);
@endphp
<form method="POST" action="{{ route('corex.settings.rental-work-orders.approvals') }}" class="space-y-3" id="approvals-settings">
    @csrf
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
        <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
            <h3 class="text-sm font-bold" style="color:var(--text-primary);">Owner approvals: extra work and contractor fee</h3>
        </div>
        <div class="p-5 space-y-5">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Emergency repairs the agent may approve without asking the owner - up to R</label>
                <input type="number" name="emergency_spend_threshold" value="{{ old('emergency_spend_threshold', $apEmergency) }}"
                       min="0" max="99999999.99" step="0.01" data-emergency-limit
                       class="w-full max-w-[160px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                <p class="text-xs mt-2" style="color: var(--text-muted);">
                    Default R{{ number_format($apEmergencyDefault, 2) }}. Counts only when the agent ticks "This is an emergency" and gives a reason; the owner is told at the start
                    and when the work is finished. Never lower than the ordinary limit above. A property can have its own figure on its work terms.
                </p>
            </div>
            <div>
                <input type="hidden" name="agents_may_record_owner_decisions" value="0">
                <label class="flex items-center gap-2 text-sm font-semibold" style="color:var(--text-primary);">
                    <input type="checkbox" name="agents_may_record_owner_decisions" value="1" @checked(old('agents_may_record_owner_decisions', $apMayRecord)) data-agents-may-record>
                    Agents may record an owner's decision for them
                </label>
                <p class="text-xs mt-2" style="color: var(--text-muted);">
                    On by default. When the owner tells the agent their decision by phone, WhatsApp or email, the agent records it (who, how, when, what was said, optional proof);
                    the owner is emailed what was written down and can query it. Off: only the owner's own link can approve work above the limits.
                </p>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Extra work the owner is not asked about (% above what they approved)</label>
                <input type="number" name="variation_tolerance_percent" value="{{ old('variation_tolerance_percent', rtrim(rtrim(number_format($apTolerance, 2, '.', ''), '0'), '.') ?: '0') }}"
                       min="0" max="100" step="0.5"
                       class="w-full max-w-[160px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                <p class="text-xs mt-2" style="color: var(--text-muted);">
                    Default 0 %: every increase after the owner has approved goes back to the owner. A property can carry its own figure, agreed with its owner, on its Rental tab.
                    The limits for what the agent may approve alone are separate.
                </p>
            </div>
            <div>
                <input type="hidden" name="notify_landlord_on_auto_variation" value="0">
                <label class="flex items-center gap-2 text-sm font-semibold" style="color:var(--text-primary);">
                    <input type="checkbox" name="notify_landlord_on_auto_variation" value="1" @checked(old('notify_landlord_on_auto_variation', $apNotifyAuto))>
                    Email the owner when extra work is approved automatically
                </label>
                <p class="text-xs mt-2" style="color: var(--text-muted);">On by default. An information email saying what was added and the new total.</p>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Your fee on an outside contractor's quote</label>
                <div class="flex flex-wrap items-center gap-2">
                    <select name="external_quote_markup_type" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                        <option value="percent" @selected(old('external_quote_markup_type', $apFeeType) === 'percent')>% of the contractor's quote</option>
                        <option value="amount" @selected(old('external_quote_markup_type', $apFeeType) === 'amount')>Fixed amount (R)</option>
                    </select>
                    <input type="number" name="external_quote_markup_value" value="{{ old('external_quote_markup_value', rtrim(rtrim(number_format($apFeeValue, 2, '.', ''), '0'), '.') ?: '0') }}"
                           min="0" step="0.01" class="w-32 rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                </div>
                <p class="text-xs mt-2" style="color: var(--text-muted);">
                    0 (the default) means no fee: the owner approves exactly what the contractor quoted. With a fee, the owner sees one total; your office sees the quote, the fee and the total.
                    A single work order can override this.
                </p>
            </div>
        </div>
    </div>
    <div class="flex justify-end">
        <button type="submit" class="corex-btn-primary text-sm">Save</button>
    </div>
</form>
