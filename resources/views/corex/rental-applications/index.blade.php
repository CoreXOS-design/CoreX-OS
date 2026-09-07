{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@php
    $sortLink = fn ($col) => route('corex.rental-applications.index', array_merge(
        request()->except('page'),
        ['sort' => $col, 'direction' => (request('sort') === $col && request('direction', 'desc') === 'desc') ? 'asc' : 'desc']
    ));
@endphp

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Rental Applications</h1>
                <p class="text-xs" style="color: var(--text-muted);">Send a rental application to a prospective tenant.</p>
            </div>
            @permission('rental_applications.create')
            <a href="{{ route('corex.rental-applications.create') }}" class="corex-btn-primary text-xs">New Rental Application</a>
            @endpermission
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: var(--ds-emerald-soft, #ecfdf5); color: var(--ds-emerald, #059669);">{{ session('success') }}</div>
    @endif

    <form method="GET" action="{{ route('corex.rental-applications.index') }}" class="rounded-md p-4 flex flex-wrap items-end gap-3" style="background: var(--surface); border: 1px solid var(--border);">
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Applicant name, email, property, or #id"
                   class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border); min-width: 260px;">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Sent from</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Sent to</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
        </div>
        <button type="submit" class="corex-btn-outline text-xs">Filter</button>
        @if(request()->hasAny(['q', 'date_from', 'date_to']))
            <a href="{{ route('corex.rental-applications.index') }}" class="corex-btn-outline text-xs">Clear</a>
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
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('contact') }}" style="color: var(--text-muted);">Contact</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('property') }}" style="color: var(--text-muted);">Property</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('status') }}" style="color: var(--text-muted);">Status</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('date') }}" style="color: var(--text-muted);">Sent</a></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($applications as $application)
                <tr style="border-bottom: 1px solid var(--border);">
                    <td class="px-4 py-2">{{ $application->contact->full_name ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $application->property?->buildDisplayAddress() ?? $application->property_address_override ?? '—' }}</td>
                    <td class="px-4 py-2"><span class="ds-badge ds-badge-info">{{ str_replace('_', ' ', $application->status) }}</span></td>
                    <td class="px-4 py-2">{{ $application->created_at->format('d M Y') }}</td>
                    <td class="px-4 py-2 text-right">
                        <a href="{{ route('corex.rental-applications.show', $application) }}" class="corex-btn-outline text-xs">Open</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
                    @if(request()->hasAny(['q', 'date_from', 'date_to']))
                        No rental applications match this search.
                    @else
                        No rental applications yet.
                    @endif
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $applications->links() }}

    @if($archived !== null)
    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3 text-sm font-semibold" style="color: var(--text-primary); border-bottom: 1px solid var(--border);">Archived</div>
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
                        @permission('rental_applications.create')
                        <form method="POST" action="{{ route('corex.rental-applications.restore', $application->id) }}">
                            @csrf
                            <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                        </form>
                        @endpermission
                    </td>
                </tr>
                @empty
                <tr><td colspan="4" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">Nothing archived.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $archived->links() }}
    @endif
</div>
@endsection
