{{--
    Rental Applications tab — AT-392. Johan: "the rental application is not
    a pillar of corex but the contact is, so we need to save the
    information on the contact so its available at any point if anyone
    needs to look at it." Current status (a derived cache kept in sync by
    App\Listeners\Contact\RecomputeRentalApplicationStatus) plus the full,
    permanent history — every application this contact has ever had.

    2026-09-12 — design-standard hardening: real server-side pagination,
    search, filter, and date range, reusing FiltersRentalApplicationList
    (the SAME trait every other rental-application list screen in the app
    uses) rather than a parallel implementation — see ContactController::
    show() for the query build. A plain GET form (full page reload, tab
    re-selected via ?tab=rental) rather than AJAX, matching the Contact
    page's own History tab convention (_history-tab-body.blade.php).

    Spec: .ai/specs/rental-applications.md — Contact status section.
--}}
@php
    $rentalStatusLabels = [
        'none' => ['label' => 'No applications', 'bg' => 'var(--surface-2)', 'fg' => 'var(--text-muted)'],
        'invited' => ['label' => 'Invited', 'bg' => 'color-mix(in srgb, var(--ds-blue, #2563eb) 14%, transparent)', 'fg' => 'var(--ds-blue, #2563eb)'],
        'in_progress' => ['label' => 'In progress', 'bg' => 'color-mix(in srgb, var(--ds-amber, #f59e0b) 14%, transparent)', 'fg' => 'var(--ds-amber, #f59e0b)'],
        'approved' => ['label' => 'Approved', 'bg' => 'color-mix(in srgb, var(--ds-green, #16a34a) 14%, transparent)', 'fg' => 'var(--ds-green, #16a34a)'],
        'declined' => ['label' => 'Declined', 'bg' => 'color-mix(in srgb, var(--ds-red, #dc2626) 14%, transparent)', 'fg' => 'var(--ds-red, #dc2626)'],
        'withdrawn' => ['label' => 'Withdrawn', 'bg' => 'var(--surface-2)', 'fg' => 'var(--text-muted)'],
    ];
    $currentStatus = $rentalStatusLabels[$contact->rental_application_status] ?? $rentalStatusLabels['none'];
    // AT-392 — viewer-scoped (own/branch/agency, agency-configurable in
    // Role Manager, default agency-wide). $visibleRentalApplications is
    // now a real paginator (server-side search/filter/date-range already
    // applied by the controller); $rentalApplicationsTotalCount is the
    // UNFILTERED scoped count (drives the tab badge, never affected by
    // whatever search/filter happens to be in the URL); $hasAnyRentalApplications
    // is the fully-unscoped existence check, used only to tell "genuinely
    // none" apart from "some exist but your access level doesn't show them".
    $rentalApps = $visibleRentalApplications;
    $rentalOutcome = $rentalOutcome ?? '';
    $rentalHasFilters = request()->filled('q') || $rentalOutcome !== '' || request()->filled('date_from') || request()->filled('date_to');
@endphp

<div class="flex items-center gap-3 flex-wrap">
    <span class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Current status</span>
    <span class="text-xs font-semibold px-2.5 py-1 rounded-md" style="background:{{ $currentStatus['bg'] }}; color:{{ $currentStatus['fg'] }};">
        {{ $currentStatus['label'] }}
    </span>
    @if($contact->rental_application_status_updated_at)
        <span class="text-[11px]" style="color:var(--text-muted);">as of {{ $contact->rental_application_status_updated_at->format('d M Y, H:i') }}</span>
    @endif
</div>

@if(!$hasAnyRentalApplications)
    <div class="rounded-md p-6 text-center" style="background:var(--surface-2); border:1px dashed var(--border);">
        <p class="text-sm font-medium" style="color:var(--text-secondary);">No rental applications yet</p>
        <p class="text-xs mt-1" style="color:var(--text-muted);">Every application this contact ever has will show up here, with its outcome and date.</p>
    </div>
@elseif($rentalApplicationsTotalCount === 0)
    {{-- Scoping legitimately hid everything — never say "no
         applications" when some genuinely exist; that would be
         a false statement about the contact, not just an empty list. --}}
    <div class="rounded-md p-6 text-center" style="background:var(--surface-2); border:1px dashed var(--border);">
        <p class="text-sm font-medium" style="color:var(--text-secondary);">No rental applications visible at your access level</p>
        <p class="text-xs mt-1" style="color:var(--text-muted);">This contact has rental application history, but your current Rental History access (set in Role Manager) doesn't include it. Ask an admin to widen it if you need to see it.</p>
    </div>
@else
    <form method="GET" action="{{ route('corex.contacts.show', $contact->id) }}" class="flex items-center gap-2 flex-wrap">
        <input type="hidden" name="tab" value="rental">
        <span class="text-xs" style="color:var(--text-muted);">{{ $rentalApplicationsTotalCount }} application{{ $rentalApplicationsTotalCount !== 1 ? 's' : '' }}</span>

        <input type="text" name="q" value="{{ request('q') }}" placeholder="Search property, cell, ID number…"
               class="text-xs rounded-md border px-2 py-1.5" style="border-color:var(--border); background:var(--surface); color:var(--text-primary); min-width:180px;">

        <select name="outcome" onchange="this.form.submit()" class="text-xs rounded-md border px-2 py-1.5" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
            <option value="" {{ $rentalOutcome === '' ? 'selected' : '' }}>All outcomes</option>
            <option value="approved" {{ $rentalOutcome === 'approved' ? 'selected' : '' }}>Approved</option>
            <option value="declined" {{ $rentalOutcome === 'declined' ? 'selected' : '' }}>Declined</option>
            <option value="withdrawn" {{ $rentalOutcome === 'withdrawn' ? 'selected' : '' }}>Withdrawn</option>
            <option value="in_progress" {{ $rentalOutcome === 'in_progress' ? 'selected' : '' }}>In progress</option>
            <option value="invited" {{ $rentalOutcome === 'invited' ? 'selected' : '' }}>Invited</option>
        </select>

        {{-- cc4 walk, finding 8, 2026-09-13 — Johan: "every list screen has
             search, sort, filter, pagination... he should never have to
             ask for them after a feature is built." The direction toggle
             below already existed, but there was no control for WHICH
             column to sort by at all — every row silently sorted by
             submission date with no way to change it. 'Contact'/'Property'/
             'Agent' are meaningless sort columns on this specific tab
             (every row already belongs to this one contact — see
             FiltersRentalApplicationList's own docblock), so only the two
             columns that are genuinely meaningful here are offered.
             Default: Date submitted, newest first — unchanged from before
             this control existed, so adding it doesn't silently reorder
             anyone's existing view. --}}
        <select name="sort" onchange="this.form.submit()" class="text-xs rounded-md border px-2 py-1.5" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
            <option value="date" {{ request('sort', 'date') === 'date' ? 'selected' : '' }}>Sort: Date submitted</option>
            <option value="updated" {{ request('sort') === 'updated' ? 'selected' : '' }}>Sort: Last updated</option>
        </select>

        <select name="direction" onchange="this.form.submit()" class="text-xs rounded-md border px-2 py-1.5" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
            <option value="desc" {{ request('direction', 'desc') === 'desc' ? 'selected' : '' }}>Newest first</option>
            <option value="asc" {{ request('direction') === 'asc' ? 'selected' : '' }}>Oldest first</option>
        </select>

        <label class="text-[11px] flex items-center gap-1" style="color:var(--text-muted);">
            From <input type="date" name="date_from" value="{{ request('date_from') }}" class="text-xs rounded-md border px-1.5 py-1" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
        </label>
        <label class="text-[11px] flex items-center gap-1" style="color:var(--text-muted);">
            To <input type="date" name="date_to" value="{{ request('date_to') }}" class="text-xs rounded-md border px-1.5 py-1" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
        </label>

        <button type="submit" class="text-xs font-semibold px-2.5 py-1.5 rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">Search</button>
        @if($rentalHasFilters)
            <a href="{{ route('corex.contacts.show', $contact->id) }}?tab=rental" class="text-xs underline" style="color:var(--text-muted);">Clear</a>
        @endif
    </form>

    @if($rentalApps->total() === 0)
        <div class="rounded-md p-4 text-center text-xs" style="background:var(--surface-2); color:var(--text-muted);">
            No applications match this search/filter.
        </div>
    @else
        <div class="space-y-2">
            @foreach($rentalApps as $app)
                @php
                    $appAddress = $app->property?->buildDisplayAddress() ?: ($app->property_address_override ?: 'No property on record');
                    $appDate = optional($app->submitted_at ?: $app->created_at);
                @endphp
                <div class="rounded-md p-3 flex items-center justify-between gap-3"
                     style="background:var(--surface); border:1px solid var(--border);">
                    <a href="{{ route('corex.rental-applications.review', $app->id) }}" class="min-w-0 flex-1 hover:opacity-80 no-underline">
                        <div class="text-sm font-medium truncate" style="color:var(--text-primary);">{{ $appAddress }}</div>
                        <div class="text-[11px] mt-0.5" style="color:var(--text-muted);">
                            {{ $appDate->format('d M Y') }}
                            @if($app->approved_rental_amount)
                                · Approved for R{{ number_format($app->approved_rental_amount) }}/mo
                            @endif
                        </div>
                    </a>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        {{-- AT-392 — Johan: "allow the agent to download and print it if
                             they want to physically file the whole pack." Same existing
                             PDF service every other rental-application download already uses. --}}
                        <a href="{{ route('corex.rental-applications.pdf', $app->id) }}" onclick="event.stopPropagation()" title="Download the full application pack"
                           class="text-[11px] font-semibold px-2 py-0.5 rounded-md whitespace-nowrap no-underline" style="color:var(--brand-icon,#2563eb); border:1px solid var(--border);">
                            Download
                        </a>
                        @php
                            $statusColors = [
                                'approved' => ['bg' => 'color-mix(in srgb, var(--ds-green, #16a34a) 14%, transparent)', 'fg' => 'var(--ds-green, #16a34a)'],
                                'declined' => ['bg' => 'color-mix(in srgb, var(--ds-red, #dc2626) 14%, transparent)', 'fg' => 'var(--ds-red, #dc2626)'],
                                'withdrawn' => ['bg' => 'var(--surface-2)', 'fg' => 'var(--text-muted)'],
                            ];
                            $sc = $statusColors[$app->status] ?? ['bg' => 'color-mix(in srgb, var(--ds-amber, #f59e0b) 14%, transparent)', 'fg' => 'var(--ds-amber, #f59e0b)'];
                        @endphp
                        <span class="text-[10px] font-semibold px-2 py-0.5 rounded-md whitespace-nowrap" style="background:{{ $sc['bg'] }}; color:{{ $sc['fg'] }};">
                            {{ str_replace('_', ' ', $app->status) }}
                        </span>
                    </div>
                </div>
            @endforeach
        </div>

        @if($rentalApps->hasPages())
            <div class="pt-2">{{ $rentalApps->links() }}</div>
        @endif
    @endif
@endif
