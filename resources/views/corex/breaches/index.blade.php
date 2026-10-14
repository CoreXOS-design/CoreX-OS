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
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Breach register</h1>
        @permission('rental_breaches.manage')
@feature('rental-leases')
            <a href="{{ route('corex.leases.index', ['status' => 'active']) }}" class="corex-btn-outline text-xs">Choose leases for a breach notice</a>
@endfeature
        @endpermission
    </div>
    <p class="text-sm" style="color: var(--text-muted);">Every breach notice and notice to vacate issued on a lease, and where each stands.</p>

    <form method="GET" action="{{ route('corex.breaches.index') }}" class="flex flex-wrap items-end gap-3" data-qa="breach-filters">
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Search</label><br>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Name, address, clause or reason" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Status</label><br>
            <select name="status" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach(\App\Models\LeaseBreach::STATUS_LABELS as $key => $label)
                    <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
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
        <label class="flex items-center gap-2 text-xs pb-2"><input type="checkbox" name="archived" value="1" @checked(request()->boolean('archived'))> Archived</label>
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
                    <tr style="border-top: 1px solid var(--border);" data-breach-row="{{ $b->id }}">
                        <td class="px-4 py-2">{{ $b->issued_on->format('j M Y') }}</td>
                        <td class="px-4 py-2">{{ $b->lease?->property?->buildDisplayAddress() ?: ('Lease #' . $b->lease_id) }}{{ $b->lease?->property?->trashed() ? ' (archived)' : '' }}</td>
                        <td class="px-4 py-2">{{ $b->partyLabel() }}{{ $b->breach_party === 'tenant' ? ' - ' . ($b->lease?->tenantNames() ?? '') : '' }}</td>
                        <td class="px-4 py-2">{{ $b->kindLabel() }}{{ $b->reason ? ': ' . \Illuminate\Support\Str::limit($b->reason, 50) : '' }}</td>
                        <td class="px-4 py-2 text-right">{{ $b->amount !== null ? \App\Services\Rentals\RentalNoticeService::money((float) $b->amount) : '—' }}</td>
                        <td class="px-4 py-2">{{ $b->remedy_deadline?->format('j M Y') ?? '—' }}</td>
                        <td class="px-4 py-2"><span class="ds-badge {{ $b->status === 'issued' ? 'ds-badge-warning' : ($b->status === 'remedied' ? 'ds-badge-success' : 'ds-badge-danger') }}">{{ $b->statusLabel() }}</span>{{ $b->trashed() ? ' (archived)' : '' }}</td>
                        <td class="px-4 py-2 text-right"><a href="{{ route('corex.breaches.show', $b->id) }}" class="corex-btn-outline text-xs">Open</a></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);" data-qa="breach-empty">
                            @if(request()->hasAny(['q', 'status', 'kind', 'party', 'date_from', 'date_to', 'archived']))
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
