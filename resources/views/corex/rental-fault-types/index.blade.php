@extends('layouts.corex')

{{--
    .ai/specs/rentals-faults-work-orders.md §2/§8.1 — the agency's fault
    catalogue settings screen. CRUD/list-screen floor (BUILD_STANDARD
    §1a-§1d): search (name, category), sort (sort_order default; name,
    category, urgency), filter (category, urgency, active/archived),
    pagination, real empty state, agency scoping via BelongsToAgency +
    AgencyScope on the model.
--}}

@section('corex-content')
@php
    $badge = fn (string $tone) => match ($tone) {
        '--ds-green' => 'ds-badge-success',
        '--ds-amber' => 'ds-badge-warning',
        '--ds-crimson' => 'ds-badge-danger',
        '--brand-icon' => 'ds-badge-info',
        default => 'ds-badge-muted',
    };
@endphp
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Rental Fault Types</h1>
                <p class="text-xs" style="color: var(--text-muted);">The kinds of fault your agency can report, with how urgent each is and the first-aid steps shown to the reporter.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @permission('rental_fault_types.create')
                <a href="{{ route('corex.rental-fault-types.create') }}" class="corex-btn-primary text-xs">+ Add Fault Type</a>
                @endpermission
            </div>
        </div>
    </div>

    {{-- .ai/specs/rentals-menu-tabs.md - Fault Types is a tab of Rentals > Maintenance --}}
    @include('corex.partials._hub-tabs', ['hub' => \App\Support\Navigation\Hubs\MaintenanceTabs::class])

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif

    <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
    <form method="GET" action="{{ route('corex.rental-fault-types.index') }}" class="flex flex-wrap items-end gap-3">
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Name or category" class="list-header-filter text-sm {{ filled(request('q')) ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Category</label>
            <select name="category" class="list-header-filter text-sm {{ filled(request('category')) ? 'is-active' : '' }}">
                <option value="">All categories</option>
                @foreach($categories as $c)
                    <option value="{{ $c }}" @selected(request('category') === $c)>{{ $c }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Urgency</label>
            <select name="urgency" class="list-header-filter text-sm {{ filled(request('urgency')) ? 'is-active' : '' }}">
                <option value="">All urgencies</option>
                @foreach(['routine', 'urgent', 'emergency'] as $u)
                    <option value="{{ $u }}" @selected(request('urgency') === $u)>{{ ucfirst($u) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Status</label>
            <select name="status" class="list-header-filter text-sm {{ $status !== 'active' ? 'is-active' : '' }}">
                <option value="active" @selected($status === 'active')>Active</option>
                <option value="archived" @selected($status === 'archived')>Archived</option>
            </select>
        </div>
        <button type="submit" class="corex-btn-primary text-sm" style="height:2.25rem;">Filter</button>
        <div class="flex-1"></div>
        <span class="ds-badge ds-badge-pill ds-badge-info">
            {{ number_format($faultTypes->total()) }} {{ \Illuminate\Support\Str::plural('fault type', $faultTypes->total()) }}
        </span>
    </form>
    </div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2); color: var(--text-muted); border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">
                        <a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ route('corex.rental-fault-types.index', array_merge(request()->except('page'), ['sort' => 'name'])) }}">Name</a>
                    </th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">
                        <a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ route('corex.rental-fault-types.index', array_merge(request()->except('page'), ['sort' => 'category'])) }}">Category</a>
                    </th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">
                        <a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ route('corex.rental-fault-types.index', array_merge(request()->except('page'), ['sort' => 'urgency'])) }}">Urgency</a>
                    </th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Default</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Documents</th>
                    <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($faultTypes as $ft)
                    <tr style="border-top: 1px solid var(--border); color: var(--text-secondary);">
                        <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $ft->name }}</td>
                        <td class="px-4 py-3">{{ $ft->category ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="ds-badge {{ $badge($ft->urgency === 'emergency' ? '--ds-crimson' : ($ft->urgency === 'urgent' ? '--ds-amber' : '--text-muted')) }}">{{ ucfirst($ft->urgency) }}</span>
                        </td>
                        <td class="px-4 py-3">{{ $ft->is_default ? 'CoreX default' : 'Custom' }}</td>
                        <td class="px-4 py-3">{{ $ft->documents->count() }}</td>
                        <td class="px-4 py-3 text-right space-x-2 cx-equal-btns">
                            @permission('rental_fault_types.create')
                                @if($status === 'archived')
                                    <form method="POST" action="{{ route('corex.rental-fault-types.restore', $ft->id) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                                    </form>
                                @else
                                    <a href="{{ route('corex.rental-fault-types.edit', $ft) }}" class="corex-btn-outline text-xs">Edit</a>
                                    <form method="POST" action="{{ route('corex.rental-fault-types.archive', $ft) }}" class="inline" data-confirm="Archive this fault type?" data-confirm-danger data-confirm-label="Archive">
                                        @csrf
                                        <button type="submit" class="corex-btn-danger text-xs">Archive</button>
                                    </form>
                                @endif
                            @endpermission
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center text-sm" style="color: var(--text-muted);">
                            @if(request('q') || request('category') || request('urgency'))
                                No fault types match this filter.
                            @else
                                No fault types yet — CoreX's defaults should already be listed here.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
        @if($faultTypes->hasPages())
        <div class="px-5 py-4" style="border-top:1px solid var(--border);">
            {{ $faultTypes->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
