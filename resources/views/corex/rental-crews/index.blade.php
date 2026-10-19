@extends('layouts.corex')

{{--
    2026-10-05 — Johan's ruling: "Agents and staff are never maintenance
    crew. Crew are people with NO CoreX access, set up by the agency
    admin, pickable on job cards." CRUD/list-screen floor (BUILD_STANDARD
    §1a-§1d): search (name), sort (name default, created_at), filter
    (active/archived), pagination, real empty state, agency scoping via
    BelongsToAgency + AgencyScope on the model.
--}}

@section('corex-content')
@php
    $linkStatuses = app(\App\Services\Rentals\RentalCrewScheduleService::class)->linkStatusesFor($crews->pluck('id')->all());
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
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Rental Crews</h1>
                <p class="text-xs" style="color: var(--text-muted);">The maintenance teams your job cards assign work to.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @permission('rental_catalogue.manage')
                <a href="{{ route('corex.rental-crews.create') }}" class="corex-btn-primary text-xs">+ Add Crew</a>
                @endpermission
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
    <form method="GET" action="{{ route('corex.rental-crews.index') }}" class="flex flex-wrap items-end gap-3">
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Name, email or number" class="list-header-filter text-sm {{ filled(request('q')) ? 'is-active' : '' }}">
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
            {{ number_format($crews->total()) }} {{ \Illuminate\Support\Str::plural('crew', $crews->total()) }}
        </span>
    </form>
    </div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2); color: var(--text-muted); border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ route('corex.rental-crews.index', array_merge(request()->except('page'), ['sort' => 'name', 'direction' => $sort === 'name' && $direction === 'asc' ? 'desc' : 'asc'])) }}">Name</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Contact</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Members</th>
                    @permission('rental_job_cards.share')<th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Crew link</th>@endpermission
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Notes</th>
                    <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($crews as $crew)
                    <tr style="border-top: 1px solid var(--border); color: var(--text-secondary);">
                        <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $crew->name }}</td>
                        <td class="px-4 py-3">
                            @if($crew->email || $crew->phone)
                                @if($crew->email)<div>{{ $crew->email }}</div>@endif
                                @if($crew->phone)<div style="color: var(--text-muted);">{{ $crew->phone }}</div>@endif
                            @else
                                <span style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $crew->members_count }}</td>
                        @permission('rental_job_cards.share')
                        <td class="px-4 py-3 text-xs" data-crew-link-cell="{{ $crew->id }}">
                            @php $ls = $linkStatuses[$crew->id] ?? null; @endphp
                            @if($ls)
                                <span class="ds-badge {{ $badge($ls['state'] === 'live' ? '--ds-green' : ($ls['state'] === 'never' ? '--text-muted' : '--ds-crimson')) }}">{{ $ls['label'] }}</span>
                                <div style="margin-top:4px;">@include('corex.rental-crews._home-link-line', ['crew' => $crew, 'ls' => $ls, 'withRegenerate' => false])</div>
                            @endif
                        </td>
                        @endpermission
                        <td class="px-4 py-3" style="color: var(--text-muted);">{{ \Illuminate\Support\Str::limit($crew->notes, 60) ?: '—' }}</td>
                        <td class="px-4 py-3 text-right space-x-2 cx-equal-btns">
                            @permission('rental_catalogue.manage')
                                @if($status === 'archived')
                                    <form method="POST" action="{{ route('corex.rental-crews.restore', $crew->id) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                                    </form>
                                @else
                                    <a href="{{ route('corex.rental-crews.edit', $crew) }}" class="corex-btn-outline text-xs">Edit</a>
                                    <form method="POST" action="{{ route('corex.rental-crews.archive', $crew) }}" class="inline" data-confirm="Archive this crew? It stays visible on job cards it is already assigned to, but can no longer be newly picked." data-confirm-danger data-confirm-label="Archive">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="corex-btn-danger text-xs">Archive</button>
                                    </form>
                                @endif
                            @endpermission
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center text-sm" style="color: var(--text-muted);">
                            @if(request('q'))
                                No crews match this filter.
                            @elseif($status === 'archived')
                                No archived crews.
                            @else
                                No crews yet — add the first team your job cards will assign work to.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
        @if($crews->hasPages())
        <div class="px-5 py-4" style="border-top:1px solid var(--border);">
            {{ $crews->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
