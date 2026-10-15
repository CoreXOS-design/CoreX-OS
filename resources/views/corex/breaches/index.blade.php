@extends('layouts.corex')

{{--
    .ai/specs/rental-notices.md §6 - the breach register. List-screen floor: search (tenant / landlord name, property address, clause, reason),
    sort (issued, remedy deadline, amount, status, property - default newest first), filter (status, kind, who is in breach, issued date range,
    archived), pagination, real empty state. Visible exactly as the lease is (own / branch / agency).
--}}

@section('content')
@php
    $sortLink = fn (string $col) => route('corex.breaches.index', array_merge(request()->query(), ['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc']));
    $arrow = fn (string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
@endphp
<div class="p-6 space-y-4">
    @include('corex.notices._tabs', ['active' => 'breaches'])
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Breach register</h1>
        @permission('rental_breaches.manage')
@feature('rental-leases')
            <a href="{{ route('corex.leases.index', ['status' => 'active']) }}" class="corex-btn-outline text-xs">Choose leases for a breach notice</a>
@endfeature
        @endpermission
    </div>
    <p class="text-sm" style="color: var(--text-muted);">Every breach notice and notice to vacate issued on a lease, and where each stands.</p>

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

    <form method="GET" action="{{ route('corex.breaches.index') }}" class="flex flex-wrap items-end gap-3" data-qa="breach-filters">
        @if($tile)<input type="hidden" name="tile" value="{{ $tile }}">@endif
        @if($showArchived)<input type="hidden" name="archived" value="1">@endif
        @if(request('per_page'))<input type="hidden" name="per_page" value="{{ (int) request('per_page') }}">@endif
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Search</label><br>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Name, address, clause or reason" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        {{-- the Status dropdown is replaced by the tiles above; a legacy ?status= link still filters --}}
        @if(in_array(request('status'), \App\Models\LeaseBreach::STATUSES, true))<input type="hidden" name="status" value="{{ request('status') }}">@endif
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Kind</label><br>
            <select name="kind" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">Any</option>
                <option value="payment" @selected(request('kind') === 'payment')>Payment</option>
                <option value="other" @selected(request('kind') === 'other')>Other</option>
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">In breach</label><br>
            <select name="party" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">Anyone</option>
                <option value="tenant" @selected(request('party') === 'tenant')>Tenant</option>
                <option value="landlord" @selected(request('party') === 'landlord')>Landlord</option>
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Issued from</label><br>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">to</label><br>
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <button type="submit" class="corex-btn-outline text-xs">Filter</button>
        <a href="{{ route('corex.breaches.index') }}" class="corex-btn-outline text-xs">Reset</a>
    </form>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2); color: var(--text-muted);">
                    <th class="text-left px-4 py-2 font-medium"><a href="{{ $sortLink('issued_on') }}">Issued{{ $arrow('issued_on') }}</a></th>
                    <th class="text-left px-4 py-2 font-medium"><a href="{{ $sortLink('property') }}">Property{{ $arrow('property') }}</a></th>
                    <th class="text-left px-4 py-2 font-medium">In breach</th>
                    <th class="text-left px-4 py-2 font-medium">What</th>
                    <th class="text-right px-4 py-2 font-medium"><a href="{{ $sortLink('amount') }}">Amount{{ $arrow('amount') }}</a></th>
                    <th class="text-left px-4 py-2 font-medium"><a href="{{ $sortLink('remedy_deadline') }}">Remedy by{{ $arrow('remedy_deadline') }}</a></th>
                    <th class="text-left px-4 py-2 font-medium"><a href="{{ $sortLink('status') }}">Status{{ $arrow('status') }}</a></th>
                    <th class="text-right px-4 py-2 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($breaches as $b)
                    {{-- rental-notices.md §7b - an open breach whose remedy-by date has passed with no remedy recorded (ready for a notice to vacate) is a red row --}}
                    <tr style="border-top: 1px solid var(--border);{{ $b->isOverdue() ? ' background: color-mix(in srgb, #dc2626 9%, transparent); color: #991b1b;' : '' }}" data-breach-row="{{ $b->id }}" @if($b->isOverdue()) data-overdue="1" @endif>
                        <td class="px-4 py-2">{{ $b->issued_on->format('j M Y') }}</td>
                        <td class="px-4 py-2">{{ $b->lease?->property?->buildDisplayAddress() ?: ('Lease #' . $b->lease_id) }}{{ $b->lease?->property?->trashed() ? ' (archived)' : '' }}</td>
                        <td class="px-4 py-2">{{ $b->partyLabel() }}{{ $b->breach_party === 'tenant' ? ' - ' . ($b->lease?->tenantNames() ?? '') : '' }}</td>
                        <td class="px-4 py-2">{{ $b->kindLabel() }}{{ $b->reason ? ': ' . \Illuminate\Support\Str::limit($b->reason, 50) : '' }}</td>
                        <td class="px-4 py-2 text-right">{{ $b->amount !== null ? \App\Services\Rentals\RentalNoticeService::money((float) $b->amount) : '—' }}</td>
                        <td class="px-4 py-2">{{ $b->remedy_deadline?->format('j M Y') ?? '—' }}
                            @if($b->daysLeftText())<br><span class="text-xs {{ $b->isOverdue() ? 'font-semibold' : '' }}" style="color: {{ $b->isOverdue() ? '#b91c1c' : ($b->daysToRemedy() === 0 ? '#b45309' : 'var(--text-muted)') }};" data-qa="days-left">{{ $b->daysLeftText() }}</span>@endif
                        </td>
                        <td class="px-4 py-2"><span class="ds-badge {{ $b->status === 'issued' ? 'ds-badge-warning' : ($b->status === 'remedied' ? 'ds-badge-success' : 'ds-badge-danger') }}">{{ $b->statusLabel() }}</span>{{ $b->trashed() ? ' (archived)' : '' }}</td>
                        <td class="px-4 py-2 text-right"><a href="{{ route('corex.breaches.show', $b->id) }}" class="corex-btn-outline text-xs">Open</a></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);" data-qa="breach-empty">
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

    {{ $breaches->links() }}
</div>
@endsection
