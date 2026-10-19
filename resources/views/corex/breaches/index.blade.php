@extends('layouts.corex')

{{--
    .ai/specs/rental-notices.md §6 - the breach register. List-screen floor: search (tenant / landlord name, property address, clause, reason),
    sort (issued, remedy deadline, amount, status, property - default newest first), filter (status, kind, who is in breach, issued date range,
    archived), pagination, real empty state. Visible exactly as the lease is (own / branch / agency).
--}}

@section('corex-content')
@php
    $sortLink = fn (string $col) => route('corex.breaches.index', array_merge(request()->query(), ['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc']));
    $arrow = fn (string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
@endphp
<div class="w-full space-y-5">
    @include('corex.notices._tabs', ['active' => 'breaches'])

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Breach register</h1>
                <p class="text-xs" style="color: var(--text-muted);">Every breach notice and notice to vacate issued on a lease, and where each stands.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @include('corex.breaches._templates-link')
                @permission('rental_breaches.manage')
@feature('rental-leases')
                <a href="{{ route('corex.leases.index', ['status' => 'active']) }}" class="corex-btn-primary text-xs">Choose leases for a breach notice</a>
@endfeature
                @endpermission
            </div>
        </div>
    </div>

    {{-- rental-notices.md §7b - status tiles, the rentals command centre's pattern: click to filter, click again to clear. The number on a tile is the
         number of rows the list shows when it is clicked (same query, same own / branch / agency scope, with the search and filters below). --}}
    {{-- The tiles are the SHARED rentals list component (<x-rental-list-controls>: Leases, Inspections, Fault Reports, Work Orders), not a restyle: the
         same markup, size, count styling and active highlight, a Total tile first, the same per-page and Show archived controls. --}}
    @php
        $tileList = collect(['total' => 'Total'] + $tiles)->map(fn ($label, $key) => [
            'key' => $key, 'label' => $label, 'count' => $tileCounts[$key],
            'href' => route('corex.breaches.index', array_merge(request()->except(['tile', 'page']), ($key === 'total' || $tile === $key) ? [] : ['tile' => $key])),
            'active' => $key === 'total' ? $tile === '' : $tile === $key,
        ])->values()->all();
    @endphp
    <div data-qa="breach-tiles" class="space-y-2">
        <x-rental-list-controls
            :tiles="$tileList"
            route-name="corex.breaches.index"
            :per-page="$perPage"
            :per-page-options="$perPageOptions"
            :archivable="true"
            :archived="$showArchived"
        />
    </div>

    <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
    <form method="GET" action="{{ route('corex.breaches.index') }}" class="flex flex-wrap items-end gap-3" data-qa="breach-filters">
        @if($tile)<input type="hidden" name="tile" value="{{ $tile }}">@endif
        @if($showArchived)<input type="hidden" name="archived" value="1">@endif
        @if(request('per_page'))<input type="hidden" name="per_page" value="{{ (int) request('per_page') }}">@endif
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Name, address, clause or reason" class="list-header-filter text-sm {{ filled(request('q')) ? 'is-active' : '' }}">
        </div>
        {{-- the Status dropdown is replaced by the tiles above; a legacy ?status= link still filters --}}
        @if(in_array(request('status'), \App\Models\LeaseBreach::STATUSES, true))<input type="hidden" name="status" value="{{ request('status') }}">@endif
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Kind</label>
            <select name="kind" class="list-header-filter text-sm {{ filled(request('kind')) ? 'is-active' : '' }}">
                <option value="">All kinds</option>
                <option value="payment" @selected(request('kind') === 'payment')>Payment</option>
                <option value="other" @selected(request('kind') === 'other')>Other</option>
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">In breach</label>
            <select name="party" class="list-header-filter text-sm {{ filled(request('party')) ? 'is-active' : '' }}">
                <option value="">Anyone</option>
                <option value="tenant" @selected(request('party') === 'tenant')>Tenant</option>
                <option value="landlord" @selected(request('party') === 'landlord')>Landlord</option>
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Issued from</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="list-header-filter text-sm {{ filled(request('date_from')) ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">to</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="list-header-filter text-sm {{ filled(request('date_to')) ? 'is-active' : '' }}">
        </div>
        <button type="submit" class="corex-btn-primary text-sm" style="height:2.25rem;">Filter</button>
        <a href="{{ route('corex.breaches.index') }}" class="corex-btn-outline text-sm" style="height:2.25rem;">Reset</a>
        <div class="flex-1"></div>
        <span class="ds-badge ds-badge-pill ds-badge-info">
            {{ number_format($breaches->total()) }} {{ \Illuminate\Support\Str::plural('breach notice', $breaches->total()) }}
        </span>
    </form>
    </div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2); color: var(--text-muted); border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('issued_on') }}">Issued{{ $arrow('issued_on') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('property') }}">Property{{ $arrow('property') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">In breach</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">What</th>
                    <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('amount') }}">Amount{{ $arrow('amount') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('remedy_deadline') }}">Remedy by{{ $arrow('remedy_deadline') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('status') }}">Status{{ $arrow('status') }}</a></th>
                    <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($breaches as $b)
                    {{-- rental-notices.md §7b - an open breach whose remedy-by date has passed with no remedy recorded (ready for a notice to vacate) is a red row --}}
                    @php $rowBg = $b->isOverdue() ? 'color-mix(in srgb, #dc2626 9%, transparent)' : 'transparent'; @endphp
                    <tr style="border-top: 1px solid var(--border); color: {{ $b->isOverdue() ? '#991b1b' : 'var(--text-secondary)' }}; background: {{ $rowBg }};" class="transition-colors duration-150" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='{{ $rowBg }}'" data-breach-row="{{ $b->id }}" @if($b->isOverdue()) data-overdue="1" @endif>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $b->issued_on->format('j M Y') }}</td>
                        <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $b->lease?->property?->buildDisplayAddress() ?: ('Lease #' . $b->lease_id) }}{{ $b->lease?->property?->trashed() ? ' (archived)' : '' }}</td>
                        <td class="px-4 py-3">{{ $b->partyLabel() }}{{ $b->breach_party === 'tenant' ? ' - ' . ($b->lease?->tenantNames() ?? '') : '' }}</td>
                        <td class="px-4 py-3">{{ $b->kindLabel() }}{{ $b->reason ? ': ' . \Illuminate\Support\Str::limit($b->reason, 50) : '' }}</td>
                        <td class="px-4 py-3 text-right">{{ $b->amount !== null ? \App\Services\Rentals\RentalNoticeService::money((float) $b->amount) : '—' }}</td>
                        <td class="px-4 py-3">{{ $b->remedy_deadline?->format('j M Y') ?? '—' }}
                            @if($b->daysLeftText())<br><span class="text-xs {{ $b->isOverdue() ? 'font-semibold' : '' }}" style="color: {{ $b->isOverdue() ? '#b91c1c' : ($b->daysToRemedy() === 0 ? '#b45309' : 'var(--text-muted)') }};" data-qa="days-left">{{ $b->daysLeftText() }}</span>@endif
                        </td>
                        <td class="px-4 py-3">@php $tone = $b->status === 'issued' ? '--ds-amber' : ($b->status === 'remedied' ? '--ds-green' : '--ds-crimson'); @endphp
                            <span class="text-xs px-2 py-0.5 rounded-md font-medium whitespace-nowrap" style="background:color-mix(in srgb, var({{ $tone }}) 12%, transparent); color:var({{ $tone }}); border:1px solid color-mix(in srgb, var({{ $tone }}) 25%, transparent);">{{ $b->statusLabel() }}</span>{{ $b->trashed() ? ' (archived)' : '' }}</td>
                        <td class="px-4 py-3 text-right"><a href="{{ route('corex.breaches.show', $b->id) }}" class="corex-btn-outline text-xs">Open</a></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-6 py-12 text-center text-sm" style="color: var(--text-muted);" data-qa="breach-empty">
                            @if(request()->hasAny(['q', 'status', 'kind', 'party', 'date_from', 'date_to', 'archived', 'tile']))
                                No breach notices match these filters.
                            @else
                                No breach notices have been issued yet. Tick leases on the Leases list and choose "Breach notice", or open a lease and use its Breach notice button.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
        @if($breaches->hasPages())
        <div class="px-5 py-4" style="border-top:1px solid var(--border);">
            {{ $breaches->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
