{{-- Agency setting: show agents their branch's and the agency's commission totals on My Portal (default off).
     Shared by the settings page and its hub section; the Setup Wizard renders the same setting from
     config/agency-onboarding-copy.php. Saved by CommissionSettingsController@update, only when posted. --}}
@php
    $portalTotalsOn = (bool) old('portal_show_agency_commission_totals',
        \App\Models\Agency::query()->whereKey(auth()->user()->effectiveAgencyId())->value('portal_show_agency_commission_totals'));
@endphp
<div class="p-4 rounded-md space-y-3" style="background:var(--surface-2); border:1px solid var(--border);">
    <div class="flex items-start justify-between gap-4">
        <div>
            <div class="text-sm font-semibold" style="color:var(--text-primary);">Show agents their branch and agency commission totals</div>
            <div class="text-xs mt-0.5" style="color:var(--text-secondary);">Every agent sees their own commission on My Portal. Switch this on to let them also see the combined commission totals for their branch and for the whole agency &mdash; totals only, never another agent's figures, and only for people whose role already lets them see that branch or agency. Off by default.</div>
        </div>
        <label class="relative inline-flex items-center cursor-pointer flex-shrink-0 mt-1">
            <input type="hidden" name="portal_show_agency_commission_totals" value="0">
            <input type="checkbox" name="portal_show_agency_commission_totals" value="1" class="sr-only peer" @checked($portalTotalsOn)>
            <span class="w-11 h-6 rounded-full transition-colors bg-slate-300 peer-checked:bg-[var(--brand-button,#0ea5e9)]"></span>
            <span class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
        </label>
    </div>
</div>
