{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

{{--
    AT-402 — Rental Application Control Centre. Johan, verbatim: "the way
    fica works is a lot better than having 3 menus here... fica carries all
    the work and you can click the tiles to select which you want to work
    with... so it becomes more of a rental application control centre than
    having 3 menus and you have to sit and click through it to find where
    your application is at." Replaces the old Rental Applications / Returned
    Applications split — see RentalApplicationController::index() for the
    tile definitions and the scoping/counts mechanism (copied from FICA,
    compliance/fica/index.blade.php, the pattern Johan named as the better
    of the two examples he gave — e-sign's own "tiles" are same-page scroll
    anchors with no real filter and no own/branch/agency scoping at all).
--}}

@php
    $activeSort = request('sort', 'date');
    $activeDirection = request('direction', 'desc');
    $tileLink = fn ($key) => route('corex.rental-applications.index', array_merge(
        request()->except(['page', 'tile', 'status']),
        ['tile' => $key]
    ));
    $sortLink = fn ($col) => route('corex.rental-applications.index', array_merge(
        request()->except('page'),
        ['sort' => $col, 'direction' => ($activeSort === $col && $activeDirection === 'desc') ? 'asc' : 'desc']
    ));
    $sortIndicator = fn ($col) => $activeSort === $col ? ($activeDirection === 'asc' ? ' ▲' : ' ▼') : '';

    $tileLabels = [
        'all' => 'All',
        'not_yet_submitted' => 'Not Yet Submitted',
        'returned' => 'Returned',
        'under_assessment' => 'Under Assessment',
        'sent_for_authorisation' => 'Sent for Authorisation',
        'approved' => 'Approved',
        'declined' => 'Declined',
        // 2026-09-13 — Johan: the bare word read as if the applicant acted
        // for themselves; matches the control's own rename below and
        // RentalApplication::WITHDRAWN_LABEL exactly (just Title Case, to
        // match every other tile in this array).
        'withdrawn' => 'Applicant Withdrawn',
        'reopened' => 'Reopened',
    ];
    // AT-402 — permission regression guard: 'returned'/'under_assessment'/
    // 'sent_for_authorisation'/'approved'/'declined'/'reopened' were only
    // ever reachable via the old /returned route's own
    // rental_applications.view_returned gate. A user lacking it never gets
    // these tile BUTTONS rendered at all (the controller's own base query
    // also excludes the underlying rows — this is belt-and-braces, not the
    // only guard).
    $primaryTiles = $canViewReturned
        ? ['all', 'not_yet_submitted', 'returned', 'under_assessment', 'sent_for_authorisation', 'approved', 'declined']
        : ['all', 'not_yet_submitted'];
    $secondaryTiles = $canViewReturned ? ['withdrawn', 'reopened'] : ['withdrawn'];

    // Empty-state copy per tile — "what the agent is looking at and what to
    // do," not a blank panel (Johan's design standard).
    $emptyStateCopy = [
        'all' => 'No rental applications yet.',
        'not_yet_submitted' => 'Nothing waiting to be sent right now. New applications start here as a draft.',
        'returned' => 'Nothing the tenant has sent back yet.',
        'under_assessment' => 'Nothing currently with an agent for assessment.',
        'sent_for_authorisation' => 'Nothing currently sitting with the authoriser.',
        'approved' => 'No approved applications yet.',
        'declined' => 'No declined applications.',
        'withdrawn' => 'No withdrawn applications.',
        'reopened' => 'No reopened applications right now.',
    ][$tile] ?? 'Nothing here yet.';
@endphp

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Rental Applications</h1>
                <p class="text-xs" style="color: var(--text-muted);">Send a rental application to a prospective tenant, and track where every application actually is.</p>
            </div>
            @permission('rental_applications.create')
            <a href="{{ route('corex.rental-applications.create') }}" class="corex-btn-primary text-xs">New Rental Application</a>
            @endpermission
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: var(--ds-emerald-soft, #ecfdf5); color: var(--ds-emerald, #059669);">{{ session('success') }}</div>
    @endif

    {{-- AT-402 — advertises the SEPARATE Authorisation decision queue to
         RO/CO users only (RentalApplicationController::index()'s own
         docblock explains why this stays a distinct screen/route rather
         than a shared tile). Never renders, and its count is never even
         queried, for a non-RO/CO user — see $isAuthoriser in the controller. --}}
    @if($isAuthoriser && $authorisationQueueCount > 0)
        <div class="rounded-md px-4 py-3 text-sm flex items-center justify-between flex-wrap gap-2" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, transparent); border: 1px solid var(--ds-amber, #f59e0b); color: var(--text-primary);">
            <span><strong>{{ $authorisationQueueCount }}</strong> {{ Str::plural('application', $authorisationQueueCount) }} awaiting your authorisation decision.</span>
            <a href="{{ route('corex.rental-applications.authorisation.index') }}" class="corex-btn-primary text-xs shrink-0">Go to Rental Application Authorisation &rarr;</a>
        </div>
    @endif

    {{-- Own/branch/agency scope TOGGLE — unchanged mechanism from before
         AT-402 (RentalApplication::clampScope() enforces the ceiling
         server-side regardless of this UI). --}}
    <div class="flex items-center gap-2">
        <span class="text-xs font-medium" style="color: var(--text-secondary);">Showing:</span>
        <div class="inline-flex rounded-md overflow-hidden" style="border: 1px solid var(--border);">
            <a href="{{ route('corex.rental-applications.index', array_merge(request()->except('page'), ['scope' => 'own'])) }}"
               class="px-3 py-1.5 text-xs font-semibold"
               style="{{ request('scope', 'own') === 'own' ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">Own</a>
            @if($canSeeBranch)
            <a href="{{ route('corex.rental-applications.index', array_merge(request()->except('page'), ['scope' => 'branch'])) }}"
               class="px-3 py-1.5 text-xs font-semibold"
               style="border-left: 1px solid var(--border); {{ request('scope', 'own') === 'branch' ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">Branch</a>
            @endif
            @if($canSeeAgency)
            <a href="{{ route('corex.rental-applications.index', array_merge(request()->except('page'), ['scope' => 'agency'])) }}"
               class="px-3 py-1.5 text-xs font-semibold"
               style="border-left: 1px solid var(--border); {{ request('scope', 'own') === 'agency' ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">Agency</a>
            @endif
        </div>
    </div>

    {{-- AT-402 — the tiles. FICA's own pattern exactly: real server-rendered
         links (?tile=key), each count computed from the SAME scoped base
         query as the filtered list below (see the controller's
         $countBase/$counts) — a tile can never show a count of rows the
         viewer can't open. --}}
    <div class="flex flex-wrap gap-1 text-sm font-medium" style="border-bottom: 1px solid var(--border);">
        @foreach($primaryTiles as $key)
            @php $active = $tile === $key; @endphp
            <a href="{{ $tileLink($key) }}"
               class="px-4 py-2 transition-colors"
               style="{{ $active
                    ? 'color: var(--brand-icon, #0ea5e9); border-bottom: 2px solid var(--brand-icon, #0ea5e9); font-weight:600;'
                    : 'color: var(--text-secondary); border-bottom: 2px solid transparent;' }}">
                {{ $tileLabels[$key] }}
                <span class="ml-1 text-xs px-1.5 py-0.5 rounded-full" style="background: var(--surface-2); color: var(--text-secondary);">{{ number_format($counts[$key] ?? 0) }}</span>
            </a>
        @endforeach
    </div>

    {{-- Withdrawn/Reopened — real, reachable tiles per Johan's ruling
         ("a real state is never unreachable... zero live rows today is not
         a reason to hide a state"), rendered with lower visual prominence
         than the primary row above rather than hidden. --}}
    <div class="flex flex-wrap items-center gap-3 text-xs" style="color: var(--text-muted);">
        <span>Also:</span>
        @foreach($secondaryTiles as $key)
            <a href="{{ $tileLink($key) }}"
               class="no-underline"
               style="{{ $tile === $key ? 'color: var(--brand-icon, #0ea5e9); font-weight:600;' : 'color: var(--text-muted);' }}">
                {{ $tileLabels[$key] }} ({{ number_format($counts[$key] ?? 0) }})
            </a>
        @endforeach
    </div>

    <form method="GET" action="{{ route('corex.rental-applications.index') }}" class="rounded-md p-4 flex flex-wrap items-end gap-3" style="background: var(--surface); border: 1px solid var(--border);">
        <input type="hidden" name="scope" value="{{ request('scope', 'own') }}">
        <input type="hidden" name="tile" value="{{ $tile }}">
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Applicant name, phone, email, ID number, property, or #id"
                   class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border); min-width: 260px;">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Created from</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Created to</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Per page</label>
            <select name="per_page" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);" onchange="this.form.submit()">
                @foreach([10, 25, 50, 100] as $option)
                    <option value="{{ $option }}" @selected($perPage === $option)>{{ $option }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="corex-btn-outline text-xs">Filter</button>
        @if(request()->hasAny(['q', 'date_from', 'date_to', 'per_page']))
            <a href="{{ route('corex.rental-applications.index', array_merge(request()->only('scope'), ['tile' => $tile])) }}" class="corex-btn-outline text-xs">Clear</a>
        @endif
        <a href="{{ route('corex.rental-applications.index', array_merge(request()->except('page'), ['archived' => request()->boolean('archived') ? null : 1])) }}"
           class="corex-btn-outline text-xs {{ request()->boolean('archived') ? 'corex-tab-active' : '' }}">
            {{ request()->boolean('archived') ? 'Hide archived' : 'Show archived' }}
        </a>
    </form>

    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('contact') }}" style="color: var(--text-muted);">Applicant{{ $sortIndicator('contact') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('property') }}" style="color: var(--text-muted);">Property{{ $sortIndicator('property') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('status') }}" style="color: var(--text-muted);">Status{{ $sortIndicator('status') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('agent') }}" style="color: var(--text-muted);">Agent{{ $sortIndicator('agent') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('date') }}" style="color: var(--text-muted);">Created{{ $sortIndicator('date') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('updated') }}" style="color: var(--text-muted);">Last updated{{ $sortIndicator('updated') }}</a></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($applications as $application)
                <tr style="border-bottom: 1px solid var(--border);">
                    <td class="px-4 py-2">{{ $application->contact->full_name ?? $application->full_name ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $application->property?->buildDisplayAddress() ?? $application->property_address_override ?? '—' }}</td>
                    <td class="px-4 py-2">
                        @permission('rental_applications.create')
                            {{-- 2026-09-12 REGRESSION FIX — this used to trigger on
                                 'withdrawn' too (via array_merge(['returned'],
                                 AGENT_SETTABLE_STATUSES), and AGENT_SETTABLE_STATUSES
                                 itself contains 'withdrawn'), which rendered a LIVE,
                                 unguarded "Under assessment" option on a withdrawn
                                 row — one click silently reversed a withdrawal, no
                                 note, no confirmation. withdrawn is now excluded from
                                 the statuses that trigger this editable control at
                                 all; the one legitimate way out is the Reopen action
                                 below (override-tier, required note, audited) —
                                 RentalApplicationController::updateStatus() also
                                 refuses this transition server-side regardless of
                                 what this template renders. --}}
                            @if(in_array($application->status, ['returned', 'under_assessment'], true))
                                <form method="POST" action="{{ route('corex.rental-applications.update-status', $application) }}" class="inline">
                                    @csrf
                                    {{-- 2026-09-12 — 'withdrawn' removed from this
                                         dropdown's own options (approved, same day):
                                         Johan wants recording a withdrawal to be its
                                         OWN explicit, clearly-labelled action with a
                                         REQUIRED note — not a silent value in a
                                         multi-purpose dropdown alongside the routine
                                         "under assessment" judgement call, which stays
                                         optional-note. See _record-withdrawn.blade.php,
                                         included right below. --}}
                                    <select name="status" onchange="this.form.submit()" class="ds-badge ds-badge-info text-xs" style="border: 1px solid var(--border); cursor: pointer;">
                                        <option value="returned" disabled @selected($application->status === 'returned')>Returned</option>
                                        @foreach(array_diff(\App\Models\RentalApplication::AGENT_SETTABLE_STATUSES, ['withdrawn']) as $s)
                                            <option value="{{ $s }}" @selected($application->status === $s)>{{ str_replace('_', ' ', ucfirst($s)) }}</option>
                                        @endforeach
                                    </select>
                                </form>
                                @include('corex.rental-applications._record-withdrawn', ['application' => $application])
                            @elseif($application->status === 'approved' && ! $application->applicant_notified_at)
                                <span class="ds-badge" style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 16%, transparent); color:var(--ds-amber, #f59e0b); font-weight:600;">Approved — ready to send</span>
                            @else
                                <span class="ds-badge {{ $application->status === 'draft' ? 'ds-badge-muted' : 'ds-badge-info' }}">{{ \App\Models\RentalApplication::displayStatusLabel($application->status) }}</span>
                            @endif
                        @else
                            @if($application->status === 'approved' && ! $application->applicant_notified_at)
                                <span class="ds-badge" style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 16%, transparent); color:var(--ds-amber, #f59e0b); font-weight:600;">Approved — ready to send</span>
                            @else
                                <span class="ds-badge {{ $application->status === 'draft' ? 'ds-badge-muted' : 'ds-badge-info' }}">{{ \App\Models\RentalApplication::displayStatusLabel($application->status) }}</span>
                            @endif
                        @endpermission
                        {{-- AT-402 — the under_assessment split, visible even in
                             the 'All' tile or a search result: without this, two
                             rows both reading "under assessment" look identical
                             even though one is with the agent and the other is
                             out of their hands entirely. Never collapse the
                             distinction back to invisible (Johan's ruling). --}}
                        @if($application->status === 'under_assessment')
                            <div class="text-[10px] mt-0.5" style="color: var(--text-muted);">
                                {{ $application->submitted_for_approval_at ? '→ with authoriser' : '→ with agent' }}
                            </div>
                        @endif
                    </td>
                    <td class="px-4 py-2">{{ $application->createdBy->name ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $application->created_at->format('d M Y') }}</td>
                    <td class="px-4 py-2">{{ $application->updated_at->format('d M Y H:i') }}</td>
                    <td class="px-4 py-2 text-right whitespace-nowrap">
                        {{-- REGRESSION FIX (2026-09-11) — Open alone orphaned the review
                             screen for every application actively being worked.
                             Review shows for the statuses an agent still has real
                             work to do on (mark up docs, run the assessment, submit
                             for authorisation); a terminal/pre-submission row gets
                             Open only, matching Johan's explicit "an approved or
                             declined application opening into a working review
                             screen is its own bug." Mirrors old returned.blade.php's
                             own unconditional Open+Review pair exactly, for exactly
                             the statuses that had it.

                             LABELLING FIX (2026-09-14, cc4's agent-walk finding) —
                             "Open" and "Review" told an agent nothing about which
                             screen she'd land on, so she picked one, found it
                             wasn't the one she needed, and went back for the
                             other — every time. Both buttons do genuinely
                             different jobs (confirmed from the routes, not the
                             old labels): Review is always the workspace (mark
                             up documents, capture the ledger, run the
                             assessment, submit for authorisation); Open always
                             lands on RentalApplicationController::show(), which
                             itself branches into the SAME editable-vs-read-only
                             split AGENT_EDIT_LOCKED_STATUSES already governs
                             elsewhere. Each label now names its own
                             destination instead of a generic verb — no help
                             text, per Johan's standing rule that a label which
                             doesn't say where it goes is worse than no label
                             at all.

                             WIDTH RULING (2026-09-14, Johan) — "View Submission"
                             widened the action cell enough to force a real
                             ~144px horizontal scroll at 1280px (measured with
                             real Puppeteer geometry, before/after, at four
                             viewports — see the spec). Shortened to "View":
                             Johan's own ruling was that the row's Status
                             column already sits right beside this button
                             ("Returned", "Approved", etc.), so "View" next to
                             a status badge still names its destination in
                             context — the shortest word that clears the width
                             regression without losing the labelling fix.
                             "Review & Assess" kept as-is; shortening it back
                             to "Review" would have undone the destination-
                             naming fix for that button specifically. --}}
                        @if(in_array($application->status, \App\Http\Controllers\CoreX\RentalApplicationController::REVIEWABLE_STATUSES, true))
                            <a href="{{ route('corex.rental-applications.review', $application) }}" class="corex-btn-outline text-xs">Review &amp; Assess</a>
                        @endif
                        <a href="{{ route('corex.rental-applications.show', $application) }}" class="corex-btn-outline text-xs">{{ in_array($application->status, \App\Models\RentalApplication::AGENT_EDIT_LOCKED_STATUSES, true) ? 'View' : 'Edit' }}</a>
                        {{-- REGRESSION FIX (2026-09-11, broadened 2026-09-12) —
                             declined/withdrawn's own explicit door back, since
                             Review is deliberately never offered on either row.
                             See _reopen-terminal.blade.php. --}}
                        @include('corex.rental-applications._reopen-terminal', ['application' => $application])
                        @permission('rental_applications.create')
                            @if($application->recipientEmail())
                                <form method="POST" action="{{ route('corex.rental-applications.send', $application) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="corex-btn-outline text-xs">{{ $application->status === 'draft' ? 'Send' : 'Resend' }}</button>
                                </form>
                            @endif
                        @endpermission
                        {{-- 2026-09-12 — Archive moved off rental_applications.create
                             (it never belonged there) onto its own rental_applications.archive,
                             matching every other module's {module}.archive convention. --}}
                        @permission('rental_applications.archive')
                            <form method="POST" action="{{ route('corex.rental-applications.destroy', $application) }}"
                                  onsubmit="return confirm('Archive this rental application? It can be restored later.');" class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626);">Archive</button>
                            </form>
                        @endpermission
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
                    @if(request()->hasAny(['q', 'date_from', 'date_to']))
                        No rental applications match this search in {{ $tileLabels[$tile] }}. Try clearing a filter{{ ($canSeeBranch || $canSeeAgency) && request('scope', 'own') === 'own' ? ', or widen the scope above' : '' }}.
                    @elseif(request('scope', 'own') === 'own' && ($canSeeBranch || $canSeeAgency))
                        {{ $emptyStateCopy }} Try {{ $canSeeAgency ? 'Agency' : 'Branch' }} above if you're expecting to see a colleague's.
                    @else
                        {{ $emptyStateCopy }}
                    @endif
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $applications->links() }}

    @if($archived !== null)
    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3 text-sm font-semibold" style="color: var(--text-primary); border-bottom: 1px solid var(--border);">Archived — {{ $tileLabels[$tile] }}</div>
        <table class="w-full text-sm">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-2" style="color: var(--text-muted);">Contact</th>
                    <th class="text-left px-4 py-2" style="color: var(--text-muted);">Property</th>
                    <th class="text-left px-4 py-2" style="color: var(--text-muted);">Archived</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($archived as $application)
                <tr style="border-bottom: 1px solid var(--border);">
                    <td class="px-4 py-2">{{ $application->contact->full_name ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $application->property?->buildDisplayAddress() ?? $application->property_address_override ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $application->deleted_at->format('d M Y') }}</td>
                    <td class="px-4 py-2 text-right">
                        {{-- 2026-09-12 — moved off rental_applications.create onto
                             rental_applications.archive, same as Archive above. --}}
                        @permission('rental_applications.archive')
                        <form method="POST" action="{{ route('corex.rental-applications.restore', $application->id) }}">
                            @csrf
                            <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                        </form>
                        @endpermission
                    </td>
                </tr>
                @empty
                <tr><td colspan="4" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">Nothing archived in {{ $tileLabels[$tile] }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $archived->links() }}
    @endif
</div>
@endsection
