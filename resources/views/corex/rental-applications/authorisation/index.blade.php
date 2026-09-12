{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- AT-392 authoriser flow, 2026-09-08 — everything currently awaiting THIS
     agency's authoriser(s). Only reachable by a user configured as an
     authoriser (agencies.rental_application_authoriser_user_ids) —
     enforced in RentalApplicationAuthorisationController::index(), not
     just by this nav link being hidden. --}}
@extends('layouts.corex')

@php
    // 2026-09-09 (design-standard audit) — same sort-indicator pattern as
    // index.blade.php/returned.blade.php. Default direction is 'asc'
    // (oldest submitted first) to match the controller's own default —
    // the queue's whole point is working the longest-waiting decision
    // first, so the indicator on first load (no ?sort= at all) must show
    // that, not silently disagree with what's actually being shown.
    $activeSort = request('sort', 'date');
    $activeDirection = request('direction', 'asc');
    $sortLink = fn ($col) => route('corex.rental-applications.authorisation.index', array_merge(
        request()->except('page'),
        ['sort' => $col, 'direction' => ($activeSort === $col && $activeDirection === 'asc') ? 'desc' : 'asc']
    ));
    $sortIndicator = fn ($col) => $activeSort === $col ? ($activeDirection === 'asc' ? ' ▲' : ' ▼') : '';
@endphp

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Rental Applications — Awaiting Authorisation</h1>
        <p class="text-xs" style="color: var(--text-muted);">
            Applications an agent has submitted for your decision.
        </p>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: var(--ds-emerald-soft, #ecfdf5); color: var(--ds-emerald, #059669);">{{ session('success') }}</div>
    @endif

    {{-- 2026-09-09 (design-standard audit) — Johan: "search must cover what
         an RO or CO would actually type: applicant name, property, agent."
         Same form pattern as index.blade.php/returned.blade.php. No status
         filter here — this queue is always exactly "awaiting authorisation",
         a status control would offer a choice that can only ever narrow to
         nothing else. No own/branch/agency scope toggle either: an RO/CO
         grant is an agency-wide, named-individual role (Johan's own tier
         definition), not branch-scoped, so there is no narrower level to
         toggle to.

         2026-09-12 (design-standard hard test) — date range was missing from
         this screen's own form even though the controller's
         applySearchSortAndDateRange() call already supports it against
         submitted_for_approval_at (the trait is shared with index.blade.php,
         which DOES expose it) — the backend capability existed, nothing on
         screen could reach it. Added to match the standard every other
         rental-application list screen already has. --}}
    <form method="GET" action="{{ route('corex.rental-applications.authorisation.index') }}" class="rounded-md p-4 flex flex-wrap items-end gap-3" style="background: var(--surface); border: 1px solid var(--border);">
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Applicant name, email, property, or agent"
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
        {{-- 2026-09-09 (cc5 regression pass) — this screen's per-page control
             was missing entirely (paginate(20) was hardcoded, ?per_page= did
             nothing). Same options, same onchange-submit pattern, as
             index.blade.php/returned.blade.php's own selectors. --}}
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
            <a href="{{ route('corex.rental-applications.authorisation.index') }}" class="corex-btn-outline text-xs">Clear</a>
        @endif
    </form>

    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('contact') }}" style="color: var(--text-muted);">Contact{{ $sortIndicator('contact') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('property') }}" style="color: var(--text-muted);">Property{{ $sortIndicator('property') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('agent') }}" style="color: var(--text-muted);">Agent{{ $sortIndicator('agent') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('date') }}" style="color: var(--text-muted);">Submitted for approval{{ $sortIndicator('date') }}</a></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($applications as $application)
                <tr style="border-bottom: 1px solid var(--border);">
                    <td class="px-4 py-2">{{ $application->contact->full_name ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $application->property?->buildDisplayAddress() ?? $application->property_address_override ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $application->createdBy->name ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $application->submitted_for_approval_at?->format('d M Y H:i') }}</td>
                    <td class="px-4 py-2 text-right">
                        <a href="{{ route('corex.rental-applications.authorisation.show', $application) }}" class="corex-btn-primary text-xs">Review &amp; Decide</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
                    @if(request()->hasAny(['q', 'date_from', 'date_to']))
                        No applications awaiting your decision match this search/filter. Try clearing it.
                    @else
                        Nothing waiting on you right now.
                    @endif
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $applications->links() }}
</div>
@endsection
