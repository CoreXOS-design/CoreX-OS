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
            <form method="POST" action="{{ route('corex.settings.rental-portal.auto-portal-access-on-signing') }}">
                @csrf
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    <input type="hidden" name="auto_portal_access_on_signing" value="0">
                    <input type="checkbox" name="auto_portal_access_on_signing" value="1" onchange="this.form.submit()" @checked($autoPortalAccessOnSigning)>
                    Give tenant and landlord portal access automatically when a lease is signed
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
            <h3 class="text-sm font-bold" style="color:var(--text-primary);">Crew links</h3>
        </div>
        <div class="p-5 space-y-5">
            <form method="POST" action="{{ route('corex.settings.rental-portal.crew-links-enabled') }}">
                @csrf
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    <input type="hidden" name="crew_links_enabled" value="0">
                    <input type="checkbox" name="crew_links_enabled" value="1" onchange="this.form.submit()" @checked($crewLinksEnabled)>
                    Crew links (master switch)
                </label>
                <p class="text-xs mt-1" style="color: var(--text-muted);">Off: every crew link stops opening at once.</p>
            </form>
            <form method="POST" action="{{ route('corex.settings.rental-portal.crew-job-link-expiry-days') }}" class="space-y-2">
                @csrf
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Job link expiry (days)</label>
                <input type="number" name="crew_job_link_expiry_days" value="{{ old('crew_job_link_expiry_days', $crewJobLinkExpiryDays) }}"
                       min="1" max="90" required class="w-full max-w-[120px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                <p class="text-xs" style="color: var(--text-muted);">Default {{ $defaultCrewJobLinkExpiryDays }}. A link always stops when the job card is completed, cancelled or archived.</p>
                <button type="submit" class="corex-btn-primary text-xs">Save</button>
            </form>
            <form method="POST" action="{{ route('corex.settings.rental-portal.crew-link-show-costs') }}">
                @csrf
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    <input type="hidden" name="crew_link_show_costs" value="0">
                    <input type="checkbox" name="crew_link_show_costs" value="1" onchange="this.form.submit()" @checked($crewLinkShowCosts)>
                    Show costs on the crew's job view
                </label>
            </form>
            <form method="POST" action="{{ route('corex.settings.rental-portal.crew-link-show-tenant-contact') }}">
                @csrf
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    <input type="hidden" name="crew_link_show_tenant_contact" value="0">
                    <input type="checkbox" name="crew_link_show_tenant_contact" value="1" onchange="this.form.submit()" @checked($crewLinkShowTenantContact)>
                    Show the tenant's name and phone to the crew and contractors
                </label>
            </form>
            <form method="POST" action="{{ route('corex.settings.rental-portal.crew-link-auto-send-on-fault-job') }}">
                @csrf
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    <input type="hidden" name="crew_link_auto_send_on_fault_job" value="0">
                    <input type="checkbox" name="crew_link_auto_send_on_fault_job" value="1" onchange="this.form.submit()" @checked($crewLinkAutoSendOnFaultJob)>
                    Send the crew their job link automatically when a job card is created from a fault
                </label>
            </form>
            <form method="POST" action="{{ route('corex.settings.rental-portal.notify-landlord-on-crew-completion') }}">
                @csrf
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    <input type="hidden" name="notify_landlord_on_crew_completion" value="0">
                    <input type="checkbox" name="notify_landlord_on_crew_completion" value="1" onchange="this.form.submit()" @checked($notifyLandlordOnCrewCompletion)>
                    Email the owner as soon as the work is reported finished (crew or contractor)
                </label>
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
            <form method="POST" action="{{ route('corex.settings.rental-portal.push-informational-default') }}">
                @csrf
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    <input type="hidden" name="push_informational_default" value="0">
                    <input type="checkbox" name="push_informational_default" value="1" onchange="this.form.submit()" @checked($pushInformationalDefault)>
                    Send tenants and owners the general (non-urgent) updates on their phone
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
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Crew home link lasts (days)</label>
                <input type="number" name="crew_standing_link_expiry_days" value="{{ old('crew_standing_link_expiry_days', $crewStandingLinkExpiryDays) }}"
                       min="0" max="365" placeholder="{{ $defaultCrewStandingLinkExpiryDays }}" class="w-full max-w-[140px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                <p class="text-xs" style="color: var(--text-muted);">Each crew has one home link that lists all its jobs. A new link stops working after this many days (the standard is {{ $defaultCrewStandingLinkExpiryDays }}); enter 0 to keep every new link until you revoke or regenerate it. Links already out keep the date they were made with.</p>
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
        </div>
    </div>

    {{-- Sign-in and security (audit 2026-10-19). --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
        <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
            <h3 class="text-sm font-bold" style="color:var(--text-primary);">Portal sign-in and security</h3>
        </div>
        <div class="p-5 space-y-5">
            <form method="POST" action="{{ route('corex.settings.rental-portal.portal-remember-days') }}" class="space-y-2">
                @csrf
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Remember a sign-in on a browser (days)</label>
                <input type="number" name="portal_remember_days" value="{{ old('portal_remember_days', $portalRememberDays) }}"
                       min="1" max="30" required class="w-full max-w-[120px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                <p class="text-xs" style="color: var(--text-muted);">After a tenant or owner signs in on a browser, that browser opens their portal again without a password for this many days. Counted from the real sign-in, so using the portal does not extend it. Default 7.</p>
                <button type="submit" class="corex-btn-primary text-xs">Save</button>
            </form>
            <form method="POST" action="{{ route('corex.settings.rental-portal.portal-app-token-days') }}" class="space-y-2">
                @csrf
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Phone app stays signed in (days)</label>
                <input type="number" name="portal_app_token_days" value="{{ old('portal_app_token_days', $portalAppTokenDays) }}"
                       min="1" max="90" required class="w-full max-w-[120px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                <p class="text-xs" style="color: var(--text-muted);">The phone app signs a person out after this many days without being used. Each use renews it. Default 30.</p>
                <button type="submit" class="corex-btn-primary text-xs">Save</button>
            </form>
            <form method="POST" action="{{ route('corex.settings.rental-portal.portal-api-per-minute') }}" class="space-y-2">
                @csrf
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Portal requests per minute</label>
                <input type="number" name="portal_api_per_minute" value="{{ old('portal_api_per_minute', $portalApiPerMinute) }}"
                       min="30" max="1000" required class="w-full max-w-[120px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                <p class="text-xs" style="color: var(--text-muted);">The most requests one signed-in tenant or owner can make in a minute, in total across the portal and the phone app. It protects the system from a runaway page or a script. Default 120.</p>
                <button type="submit" class="corex-btn-primary text-xs">Save</button>
            </form>
            <form method="POST" action="{{ route('corex.settings.rental-portal.portal-photo-link-minutes') }}" class="space-y-2">
                @csrf
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Picture links last (minutes)</label>
                <input type="number" name="portal_photo_link_minutes" value="{{ old('portal_photo_link_minutes', $portalPhotoLinkMinutes) }}"
                       min="5" max="1440" required class="w-full max-w-[120px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                <p class="text-xs" style="color: var(--text-muted);">Pictures in the portal are shown through links that stop working after this long and the moment the report is archived. The portal fetches fresh links every time a page loads. Default 120.</p>
                <button type="submit" class="corex-btn-primary text-xs">Save</button>
            </form>
            <form method="POST" action="{{ route('corex.settings.rental-portal.portal-link-days') }}" class="space-y-2">
                @csrf
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Email links stay recognised (days)</label>
                <input type="number" name="portal_link_days" value="{{ old('portal_link_days', $portalLinkDays) }}"
                       min="1" max="365" required class="w-full max-w-[120px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                <p class="text-xs" style="color: var(--text-muted);">The personal link in a portal email carries a sealed reference that pre-fills the sign-in and shows your agency&rsquo;s header. After this many days the link still opens the portal, but the person simply signs in as normal. Default 60.</p>
                <button type="submit" class="corex-btn-primary text-xs">Save</button>
            </form>
        </div>
    </div>

    {{-- §22 — fault photos. --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
        <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
            <h3 class="text-sm font-bold" style="color:var(--text-primary);">Fault photos</h3>
        </div>
        <div class="p-5 space-y-5">
            <form method="POST" action="{{ route('corex.settings.rental-portal.fault-photo-max-count') }}" class="space-y-2">
                @csrf
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Photos per fault report</label>
                <input type="number" name="fault_photo_max_count" value="{{ old('fault_photo_max_count', $faultPhotoMaxCount) }}"
                       min="1" max="10" required class="w-full max-w-[120px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                <p class="text-xs" style="color: var(--text-muted);">The most photos a tenant or owner can add to one fault report from the portal (1&ndash;10).</p>
                <button type="submit" class="corex-btn-primary text-xs">Save</button>
            </form>
            <form method="POST" action="{{ route('corex.settings.rental-portal.fault-photo-max-mb') }}" class="space-y-2">
                @csrf
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Largest photo (MB)</label>
                <input type="number" name="fault_photo_max_mb" value="{{ old('fault_photo_max_mb', $faultPhotoMaxMb) }}"
                       min="1" max="15" required class="w-full max-w-[120px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                <p class="text-xs" style="color: var(--text-muted);">The portal shrinks phone photos before sending, so most arrive well under 1 MB. Anything larger than this is refused.</p>
                <button type="submit" class="corex-btn-primary text-xs">Save</button>
            </form>
            <form method="POST" action="{{ route('corex.settings.rental-portal.fault-recurrence-window-days') }}" class="space-y-2">
                @csrf
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">&ldquo;Reported before&rdquo; window (days)</label>
                <input type="number" name="fault_recurrence_window_days" value="{{ old('fault_recurrence_window_days', $faultRecurrenceWindowDays) }}"
                       min="1" max="730" required class="w-full max-w-[120px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                <p class="text-xs" style="color: var(--text-muted);">When a tenant fixes a fault themselves and the same kind of fault is reported again on that property within this many days, the agent and the owner are shown &ldquo;Reported before &hellip; the tenant resolved it then&rdquo;. Default 90.</p>
                <button type="submit" class="corex-btn-primary text-xs">Save</button>
            </form>
            {{-- Audit fixes 19 Oct 2026 (spec section 29): chasing an owner who has not answered, link renewal, the largest quote --}}
            @foreach([['owner-reminder-hours', 'owner_reminder_hours', $ownerReminderHours, 'First reminder to the owner (hours with no answer)', 'When a fault has been with the owner this many hours and they have not answered, they are sent a reminder.'], ['owner-second-reminder-hours', 'owner_second_reminder_hours', $ownerSecondReminderHours, 'Second reminder (hours after the first)', 'If there is still no answer this many hours after the first reminder, they are reminded once more.'], ['owner-queue-after-hours', 'owner_queue_after_hours', $ownerQueueAfterHours, 'Put it on my queue (hours after the second reminder)', 'After this many more hours the fault shows on the Command Centre as "owner has not answered", for an agent to phone them.']] as [$route, $field, $val, $label, $help])
            <form method="POST" action="{{ route('corex.settings.rental-portal.' . $route) }}" class="space-y-2">
                @csrf
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">{{ $label }}</label>
                <input type="number" name="{{ $field }}" value="{{ old($field, $val) }}" min="1" max="720" required class="w-full max-w-[120px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                <p class="text-xs" style="color: var(--text-muted);">{{ $help }}</p>
                <button type="submit" class="corex-btn-primary text-xs">Save</button>
            </form>
            @endforeach
            <form method="POST" action="{{ route('corex.settings.rental-portal.link-renew-on-use') }}" class="space-y-2">
                @csrf
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    <input type="checkbox" name="link_renew_on_use" value="1" @checked($linkRenewOnUse)> Contractor and crew links renew each time they are used
                </label>
                <p class="text-xs" style="color: var(--text-muted);">A job that runs longer than the link's life (parts on order) keeps working as long as the contractor or crew keep using it.</p>
                <button type="submit" class="corex-btn-primary text-xs">Save</button>
            </form>
            <form method="POST" action="{{ route('corex.settings.rental-portal.quote-max-amount') }}" class="space-y-2">
                @csrf
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Largest quote a contractor may enter (R)</label>
                <input type="number" name="quote_max_amount" value="{{ old('quote_max_amount', $quoteMaxAmount) }}" min="1" max="99999999" required class="w-full max-w-[160px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                <p class="text-xs" style="color: var(--text-muted);">A quote of R0, or above this, is refused on the contractor's link (it is usually a typing slip).</p>
                <button type="submit" class="corex-btn-primary text-xs">Save</button>
            </form>
            <form method="POST" action="{{ route('corex.settings.rental-portal.tenant-sees-agent-marks') }}" class="space-y-2">
                @csrf
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    <input type="hidden" name="tenant_sees_agent_marks" value="0">
                    <input type="checkbox" name="tenant_sees_agent_marks" value="1" onchange="this.form.submit()" @checked($tenantSeesAgentMarks)>
                    Show the tenant my agents' marks and notes on their photos
                </label>
                <p class="text-xs" style="color: var(--text-muted);">Off by default. The owner only ever sees marks on the photos you share with them.</p>
            </form>
        </div>
    </div>

    {{-- §22 — the Home FAQ wording. One form, every field optional (blank = the default wording). --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
        <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
            <h3 class="text-sm font-bold" style="color:var(--text-primary);">Home page FAQ (notice and early cancellation)</h3>
        </div>
        <form method="POST" action="{{ route('corex.settings.rental-portal.faq-texts') }}" class="p-5 space-y-5">
            @csrf
            <p class="text-xs" style="color: var(--text-muted);">Your wording, with the lease's own values merged in: <code>{notice_period}</code> <code>{notice_by_date}</code> <code>{earliest_termination_date}</code> <code>{lease_end_date}</code> <code>{early_cancellation_terms}</code> <code>{early_cancellation_notice}</code> <code>{early_cancellation_penalty}</code>. Put a piece in double square brackets, <code>[[like this {lease_end_date}]]</code>, and it is left out whenever the lease does not have that value. A lease with no notice or cancellation terms shows no FAQ at all. Clear a box to go back to the default.</p>
            @foreach([
                'faq_tenant_notice' => 'Tenant &middot; question 1', 'faq_tenant_early' => 'Tenant &middot; question 2',
                'faq_landlord_notice' => 'Owner &middot; question 1', 'faq_landlord_early' => 'Owner &middot; question 2',
            ] as $base => $heading)
                <div class="space-y-1">
                    <p class="text-xs font-semibold" style="color:var(--text-muted);">{!! $heading !!}</p>
                    <input type="text" name="{{ $base }}_question" value="{{ old($base . '_question', $faqTexts[$base . '_question']) }}" maxlength="200"
                           class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                    <textarea name="{{ $base }}_answer" rows="3" maxlength="1500" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">{{ old($base . '_answer', $faqTexts[$base . '_answer']) }}</textarea>
                </div>
            @endforeach
            @foreach(['faq_tenant_cancel_yes' => 'Tenant · when the lease allows early cancellation', 'faq_tenant_cancel_no' => 'Tenant · when it does not', 'faq_landlord_cancel_yes' => 'Owner · when the lease allows early cancellation', 'faq_landlord_cancel_no' => 'Owner · when it does not'] as $key => $heading)
                <div class="space-y-1">
                    <p class="text-xs font-semibold" style="color:var(--text-muted);">{{ $heading }}</p>
                    <textarea name="{{ $key }}" rows="2" maxlength="1500" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">{{ old($key, $faqTexts[$key]) }}</textarea>
                </div>
            @endforeach
            <button type="submit" class="corex-btn-primary text-xs">Save</button>
        </form>
    </div>
</div>
@endsection
