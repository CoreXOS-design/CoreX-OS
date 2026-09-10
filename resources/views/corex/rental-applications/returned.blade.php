{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@php
    $sortLink = fn ($col) => route('corex.rental-applications.returned', array_merge(
        request()->except('page'),
        ['sort' => $col, 'direction' => (request('sort') === $col && request('direction', 'desc') === 'desc') ? 'asc' : 'desc']
    ));
@endphp

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Returned Applications</h1>
                <p class="text-xs" style="color: var(--text-muted);">Applications the tenant has submitted, for the rental team to work through.</p>
            </div>
            {{--
                AT-392, Johan (2026-09-08) — "make the relationship between
                the two screens legible from both directions." The count
                link back on Rental Applications points here; this is the
                way back, so an agent can move between "building/sending"
                and "the tenant has replied" without hunting the sidebar.
            --}}
            @permission('rental_applications.view')
            <a href="{{ route('corex.rental-applications.index') }}" class="corex-btn-outline text-xs">&larr; Rental Applications</a>
            @endpermission
        </div>
    </div>

    {{-- 2026-09-09 (conductor, cc6's regression walk) — Johan's standing
         CRUD standard: own / branch / agency on every list, enforced at
         the query layer, same segmented-link pattern as index.blade.php's
         own toggle right next to this screen. Options above the user's
         permission ceiling are not shown at all (scopeVisibleTo()'s own
         clampScope() also enforces this server-side regardless, so hiding
         here is UX, not the security boundary). --}}
    <div class="flex items-center gap-2">
        <span class="text-xs font-medium" style="color: var(--text-secondary);">Showing:</span>
        <div class="inline-flex rounded-md overflow-hidden" style="border: 1px solid var(--border);">
            <a href="{{ route('corex.rental-applications.returned', array_merge(request()->except('page'), ['scope' => 'own'])) }}"
               class="px-3 py-1.5 text-xs font-semibold"
               style="{{ request('scope', 'own') === 'own' ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">Own</a>
            @if($canSeeBranch)
            <a href="{{ route('corex.rental-applications.returned', array_merge(request()->except('page'), ['scope' => 'branch'])) }}"
               class="px-3 py-1.5 text-xs font-semibold"
               style="border-left: 1px solid var(--border); {{ request('scope', 'own') === 'branch' ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">Branch</a>
            @endif
            @if($canSeeAgency)
            <a href="{{ route('corex.rental-applications.returned', array_merge(request()->except('page'), ['scope' => 'agency'])) }}"
               class="px-3 py-1.5 text-xs font-semibold"
               style="border-left: 1px solid var(--border); {{ request('scope', 'own') === 'agency' ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">Agency</a>
            @endif
        </div>
    </div>

    <div class="flex gap-2">
        <a href="{{ route('corex.rental-applications.returned', request()->except(['status', 'page'])) }}" class="corex-btn-outline text-xs {{ !request('status') ? 'corex-tab-active' : '' }}">All</a>
        @foreach(['in_progress', 'returned', 'reopened', 'under_assessment', 'approved', 'declined', 'withdrawn'] as $status)
            <a href="{{ route('corex.rental-applications.returned', array_merge(request()->except('page'), ['status' => $status])) }}"
               class="corex-btn-outline text-xs {{ request('status') === $status ? 'corex-tab-active' : '' }}">
                {{ str_replace('_', ' ', ucfirst($status)) }}
            </a>
        @endforeach
    </div>

    <form method="GET" action="{{ route('corex.rental-applications.returned') }}" class="rounded-md p-4 flex flex-wrap items-end gap-3" style="background: var(--surface); border: 1px solid var(--border);">
        <input type="hidden" name="scope" value="{{ request('scope', 'own') }}">
        @if(request('status'))
            <input type="hidden" name="status" value="{{ request('status') }}">
        @endif
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Applicant name, email, property, or #id"
                   class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border); min-width: 260px;">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Submitted from</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Submitted to</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
        </div>
        {{-- 2026-09-09 (design-standard audit) — Johan: "per-page control,
             matching the index screen's 10-100 range sitting right next to
             it." Same options, same onchange-submit pattern, as
             index.blade.php's own selector. --}}
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
            <a href="{{ route('corex.rental-applications.returned', request()->only('status')) }}" class="corex-btn-outline text-xs">Clear</a>
        @endif
    </form>

    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('contact') }}" style="color: var(--text-muted);">Contact</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('property') }}" style="color: var(--text-muted);">Property</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('status') }}" style="color: var(--text-muted);">Status</a></th>
                    <th class="text-left px-4 py-2" style="color: var(--text-muted);">Signatures</th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('date') }}" style="color: var(--text-muted);">Submitted</a></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($applications as $application)
                <tr style="border-bottom: 1px solid var(--border);">
                    <td class="px-4 py-2">{{ $application->contact->full_name ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $application->property?->buildDisplayAddress() ?? $application->property_address_override ?? '—' }}</td>
                    <td class="px-4 py-2">
                        @permission('rental_applications.create')
                            @if(in_array($application->status, \App\Models\RentalApplication::POST_RETURN_STATUSES, true))
                                <form method="POST" action="{{ route('corex.rental-applications.update-status', $application) }}" class="inline">
                                    @csrf
                                    <select name="status" onchange="this.form.submit()" class="ds-badge ds-badge-info text-xs" style="border: 1px solid var(--border); cursor: pointer;">
                                        <option value="returned" disabled @selected($application->status === 'returned')>Returned</option>
                                        @foreach(\App\Models\RentalApplication::AGENT_SETTABLE_STATUSES as $s)
                                            <option value="{{ $s }}" @selected($application->status === $s)>{{ str_replace('_', ' ', ucfirst($s)) }}</option>
                                        @endforeach
                                    </select>
                                </form>
                            @elseif($application->status === 'approved' && ! $application->applicant_notified_at)
                                {{-- AT-392 — Johan: "agent gets back and upon them being happy it gets sent out."
                                     Approved-but-not-yet-sent is the agent's own action item, not just a status. --}}
                                <span class="ds-badge" style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 16%, transparent); color:var(--ds-amber, #f59e0b); font-weight:600;">Approved — ready to send</span>
                            @else
                                <span class="ds-badge ds-badge-info">{{ str_replace('_', ' ', $application->status) }}</span>
                            @endif
                        @else
                            @if($application->status === 'approved' && ! $application->applicant_notified_at)
                                <span class="ds-badge" style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 16%, transparent); color:var(--ds-amber, #f59e0b); font-weight:600;">Approved — ready to send</span>
                            @else
                                <span class="ds-badge ds-badge-info">{{ str_replace('_', ' ', $application->status) }}</span>
                            @endif
                        @endpermission
                    </td>
                    <td class="px-4 py-2">{{ $application->isFullySigned() ? '✓ Both signed' : 'Incomplete' }}</td>
                    <td class="px-4 py-2">{{ optional($application->submitted_at)->format('d M Y') ?? '—' }}</td>
                    <td class="px-4 py-2 text-right">
                        <a href="{{ route('corex.rental-applications.show', $application) }}" class="corex-btn-outline text-xs">Open</a>
                        {{-- AT-392 Phase 2 — new file (RentalApplicationReviewController), agreed with cc4 --}}
                        <a href="{{ route('corex.rental-applications.review', $application) }}" class="corex-btn-outline text-xs">Review</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
                    @if(request()->hasAny(['q', 'date_from', 'date_to', 'status']))
                        No returned applications match this filter{{ ($canSeeBranch || $canSeeAgency) && request('scope', 'own') === 'own' ? ', or widen the scope above' : '' }}.
                    @else
                        No returned applications of your own yet{{ ($canSeeBranch || $canSeeAgency) && request('scope', 'own') === 'own' ? ' — try ' . ($canSeeAgency ? 'Agency' : 'Branch') . ' above if you\'re expecting to see a colleague\'s' : '' }}.
                    @endif
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $applications->links() }}
</div>
@endsection
