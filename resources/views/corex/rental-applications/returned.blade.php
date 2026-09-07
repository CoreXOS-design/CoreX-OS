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
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Returned Applications</h1>
        <p class="text-xs" style="color: var(--text-muted);">Applications the tenant has submitted, for the rental team to work through.</p>
    </div>

    <div class="flex gap-2">
        <a href="{{ route('corex.rental-applications.returned', request()->except(['status', 'page'])) }}" class="corex-btn-outline text-xs {{ !request('status') ? 'corex-tab-active' : '' }}">All</a>
        @foreach(['returned', 'under_assessment', 'approved', 'declined', 'withdrawn'] as $status)
            <a href="{{ route('corex.rental-applications.returned', array_merge(request()->except('page'), ['status' => $status])) }}"
               class="corex-btn-outline text-xs {{ request('status') === $status ? 'corex-tab-active' : '' }}">
                {{ str_replace('_', ' ', ucfirst($status)) }}
            </a>
        @endforeach
    </div>

    <form method="GET" action="{{ route('corex.rental-applications.returned') }}" class="rounded-md p-4 flex flex-wrap items-end gap-3" style="background: var(--surface); border: 1px solid var(--border);">
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
        <button type="submit" class="corex-btn-outline text-xs">Filter</button>
        @if(request()->hasAny(['q', 'date_from', 'date_to']))
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
                    <td class="px-4 py-2"><span class="ds-badge ds-badge-info">{{ str_replace('_', ' ', $application->status) }}</span></td>
                    <td class="px-4 py-2">{{ $application->isFullySigned() ? '✓ Both signed' : 'Incomplete' }}</td>
                    <td class="px-4 py-2">{{ optional($application->submitted_at)->format('d M Y') ?? '—' }}</td>
                    <td class="px-4 py-2 text-right">
                        <a href="{{ route('corex.rental-applications.show', $application) }}" class="corex-btn-outline text-xs">Open</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
                    @if(request()->hasAny(['q', 'date_from', 'date_to', 'status']))
                        No returned applications match this filter.
                    @else
                        No returned applications.
                    @endif
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $applications->links() }}
</div>
@endsection
