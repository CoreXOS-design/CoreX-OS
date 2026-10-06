@extends('layouts.corex')

{{--
    .ai/specs/rental-portal-access.md §7 — AT-445. Each toggle is its own
    form/saver (never combined) — .ai/specs/agency-onboarding-setup.md
    §6.1: the wizard step posts a SUBSET of a shared saver's fields, so a
    saver requiring several fields at once would reject a step render
    that only shows some of them. Same discipline as
    corex.settings.rental-work-orders.
--}}

@section('corex-content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div>
            <h1 class="text-xl font-bold text-white leading-tight">Rental Portal Settings</h1>
            <p class="text-sm text-white/60">Tenant, landlord and contractor access to the rentals portal.</p>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
        <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
            <h3 class="text-sm font-bold" style="color:var(--text-primary);">Access</h3>
        </div>
        <div class="p-5 space-y-5">
            <form method="POST" action="{{ route('corex.settings.rental-portal.tenant-portal-enabled') }}">
                @csrf
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    <input type="hidden" name="tenant_portal_enabled" value="0">
                    <input type="checkbox" name="tenant_portal_enabled" value="1" onchange="this.form.submit()" @checked($tenantPortalEnabled)>
                    Tenant portal access
                </label>
            </form>
            <form method="POST" action="{{ route('corex.settings.rental-portal.landlord-portal-enabled') }}">
                @csrf
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    <input type="hidden" name="landlord_portal_enabled" value="0">
                    <input type="checkbox" name="landlord_portal_enabled" value="1" onchange="this.form.submit()" @checked($landlordPortalEnabled)>
                    Landlord portal access
                </label>
            </form>
            <form method="POST" action="{{ route('corex.settings.rental-portal.contractor-links-enabled') }}">
                @csrf
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    <input type="hidden" name="contractor_links_enabled" value="0">
                    <input type="checkbox" name="contractor_links_enabled" value="1" onchange="this.form.submit()" @checked($contractorLinksEnabled)>
                    Contractor secure links
                </label>
            </form>
            <form method="POST" action="{{ route('corex.settings.rental-portal.update') }}" class="space-y-2">
                @csrf
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Contractor link expiry (days)</label>
                <input type="number" name="contractor_secure_link_expiry_days" value="{{ old('contractor_secure_link_expiry_days', $contractorSecureLinkExpiryDays) }}"
                       min="1" max="90" required class="w-full max-w-[120px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                <p class="text-xs" style="color: var(--text-muted);">Default is {{ $defaultContractorSecureLinkExpiryDays }} days. A link stops working after this many days, when revoked, or once the job is marked done.</p>
                <button type="submit" class="corex-btn-primary text-xs">Save</button>
            </form>
        </div>
    </div>

    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
        <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
            <h3 class="text-sm font-bold" style="color:var(--text-primary);">Notifications</h3>
        </div>
        <div class="p-5 space-y-5">
            <form method="POST" action="{{ route('corex.settings.rental-portal.notify-landlord-on-decision-needed') }}">
                @csrf
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    <input type="hidden" name="notify_landlord_on_decision_needed" value="0">
                    <input type="checkbox" name="notify_landlord_on_decision_needed" value="1" onchange="this.form.submit()" @checked($notifyLandlordOnDecisionNeeded)>
                    Email the landlord when a decision is needed
                </label>
            </form>
            <form method="POST" action="{{ route('corex.settings.rental-portal.notify-tenant-on-status-change') }}">
                @csrf
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    <input type="hidden" name="notify_tenant_on_status_change" value="0">
                    <input type="checkbox" name="notify_tenant_on_status_change" value="1" onchange="this.form.submit()" @checked($notifyTenantOnStatusChange)>
                    Email the tenant when a fault's status changes
                </label>
            </form>
        </div>
    </div>

    {{-- rental-work-orders.md §14.27.3 / §14.29 — Build 2. Appended AFTER the crew-link section the
         per-job crew-link build adds (§14.30), in its own "Crew page & client visibility" group. --}}
    <div id="crew-page-client-visibility" style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
        <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
            <h3 class="text-sm font-bold" style="color:var(--text-primary);">Crew page &amp; client visibility</h3>
        </div>
        <div class="p-5 space-y-5">
            <form method="POST" action="{{ route('corex.settings.rental-portal.crew-photos-visible-to-clients') }}" class="space-y-2">
                @csrf
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Crew photos tenants and landlords can see</label>
                <select name="crew_photos_visible_to_clients" class="w-full max-w-sm rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);" onchange="this.form.submit()">
                    <option value="in_progress_and_completed" @selected($crewPhotosVisibleToClients === 'in_progress_and_completed')>Work-in-progress and completed photos</option>
                    <option value="completed_only" @selected($crewPhotosVisibleToClients === 'completed_only')>Completed photos only</option>
                </select>
                <p class="text-xs" style="color: var(--text-muted);">Photos your crew takes while on a job. The tenant and the landlord see them on the job in their portal. &ldquo;Before&rdquo; photos taken when the problem was reported are never shown.</p>
            </form>
            <form method="POST" action="{{ route('corex.settings.rental-portal.crew-standing-link-expiry-days') }}" class="space-y-2">
                @csrf
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Crew page link lasts (days)</label>
                <input type="number" name="crew_standing_link_expiry_days" value="{{ old('crew_standing_link_expiry_days', $crewStandingLinkExpiryDays) }}"
                       min="1" max="365" placeholder="No expiry" class="w-full max-w-[140px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                <p class="text-xs" style="color: var(--text-muted);">Leave blank and a crew's page link keeps working until you revoke or regenerate it (the default). Fill it in to make every new crew link stop after that many days.</p>
                <button type="submit" class="corex-btn-primary text-xs">Save</button>
            </form>
            <form method="POST" action="{{ route('corex.settings.rental-portal.crew-page-upcoming-days') }}" class="space-y-2">
                @csrf
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Crew page &ldquo;upcoming&rdquo; reaches (days ahead)</label>
                <input type="number" name="crew_page_upcoming_days" value="{{ old('crew_page_upcoming_days', $crewPageUpcomingDays) }}"
                       min="1" max="60" required class="w-full max-w-[120px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                <p class="text-xs" style="color: var(--text-muted);">Default is {{ $defaultCrewPageUpcomingDays }} days. Jobs booked further ahead than this are not on the crew page yet, and their parts are not in the &ldquo;what to load&rdquo; list.</p>
                <button type="submit" class="corex-btn-primary text-xs">Save</button>
            </form>
            <form method="POST" action="{{ route('corex.settings.rental-portal.crew-page-recent-completed-days') }}" class="space-y-2">
                @csrf
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Show completed jobs on the crew page for (days)</label>
                <input type="number" name="crew_page_recent_completed_days" value="{{ old('crew_page_recent_completed_days', $crewPageRecentCompletedDays) }}"
                       min="0" max="30" required class="w-full max-w-[120px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                <p class="text-xs" style="color: var(--text-muted);">Default is {{ $defaultCrewPageRecentCompletedDays }} days. A finished job stays listed (read-only) for this long. Set 0 to hide the list.</p>
                <button type="submit" class="corex-btn-primary text-xs">Save</button>
            </form>
        </div>
    </div>
</div>
@endsection
