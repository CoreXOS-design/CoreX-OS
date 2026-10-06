{{--
    .ai/specs/rental-work-orders.md §17.10 / §17.14 — BUILD 3: the completion-check settings. Own form, own narrow saver
    (RentalCompletionSettingsController::update — writes only these four columns), so it can never disturb the other
    sections of this page. Reads its own values (the page controller is shared by three builds). Every control is also a
    Setup Wizard row (config/agency-onboarding-copy.php, `rental_work_orders`).
--}}
@php
    $cAgencyId = auth()->user()?->effectiveAgencyId();
    $cEnabled = \App\Models\RentalWorkOrderSetting::tenantCompletionCheckEnabledFor($cAgencyId);
    $cWindow = \App\Models\RentalWorkOrderSetting::completionResponseWindowDaysFor($cAgencyId);
    $cLandlord = \App\Models\RentalWorkOrderSetting::notifyLandlordOnDisputeFor($cAgencyId);
    $cCrew = \App\Models\RentalWorkOrderSetting::disputeNotifyCrewImmediatelyFor($cAgencyId);
@endphp
<form method="POST" action="{{ route('corex.settings.rental-work-orders.completion-check') }}" class="space-y-3" id="completion-check-settings">
    @csrf
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
        <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
            <h3 class="text-sm font-bold" style="color:var(--text-primary);">Tenant check on finished work</h3>
        </div>
        <div class="p-5 space-y-5">
            <div>
                <label class="flex items-center gap-2 text-sm font-semibold" style="color: var(--text-primary);">
                    <input type="hidden" name="tenant_completion_check_enabled" value="0">
                    <input type="checkbox" name="tenant_completion_check_enabled" value="1" @checked(old('tenant_completion_check_enabled', $cEnabled))>
                    Ask the tenant to check finished work
                </label>
                <p class="text-xs mt-1" style="color: var(--text-muted);">
                    When the crew (or a contractor) reports a job done, the tenant is emailed a link to say it is done, or still wrong.
                    Switched off, nobody is asked and nothing ever waits on a tenant.
                </p>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="completion_response_window_days">Days the tenant has to answer</label>
                <input type="number" id="completion_response_window_days" name="completion_response_window_days"
                       value="{{ old('completion_response_window_days', $cWindow) }}" min="1" max="30" step="1"
                       class="w-full max-w-[160px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                <p class="text-xs mt-2" style="color: var(--text-muted);">
                    Default is {{ \App\Models\RentalWorkOrderSetting::DEFAULT_COMPLETION_RESPONSE_WINDOW_DAYS }} days. If the tenant has not answered by then, the work counts as accepted.
                </p>
            </div>
            <div>
                <label class="flex items-center gap-2 text-sm font-semibold" style="color: var(--text-primary);">
                    <input type="hidden" name="notify_landlord_on_dispute" value="0">
                    <input type="checkbox" name="notify_landlord_on_dispute" value="1" @checked(old('notify_landlord_on_dispute', $cLandlord))>
                    Email the owner when a tenant says work is not complete
                </label>
                <p class="text-xs mt-1" style="color: var(--text-muted);">
                    The owner gets the tenant's words and photos and is told the office is arranging a fix. On by default.
                </p>
            </div>
            <div>
                <label class="flex items-center gap-2 text-sm font-semibold" style="color: var(--text-primary);">
                    <input type="hidden" name="dispute_notify_crew_immediately" value="0">
                    <input type="checkbox" name="dispute_notify_crew_immediately" value="1" @checked(old('dispute_notify_crew_immediately', $cCrew))>
                    Send a disputed job straight back to the crew
                </label>
                <p class="text-xs mt-1" style="color: var(--text-muted);">
                    Off by default: the office looks at the tenant's complaint first and presses “Send back to crew”. Switched on, the crew is emailed a fresh link
                    with the tenant's note and photos the moment the tenant disputes the work. (A contractor is always sent back by the office.)
                </p>
            </div>
        </div>
    </div>
    <div class="flex justify-end">
        <button type="submit" class="corex-btn-primary text-sm">Save</button>
    </div>
</form>
