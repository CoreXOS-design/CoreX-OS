@extends('layouts.corex')

{{--
    .ai/specs/lease-notices.md §8 - notices received (given by a tenant or a landlord). List-screen floor: search (tenant / owner name,
    property address, reason), sort (date given, end date, status, property - default newest first), filter (status - default "needs
    review", given by, date range, archived), pagination, a real empty state. Visible exactly as the lease is (own / branch / agency).
--}}

@section('content')
@php
    $sortLink = fn (string $col) => route('corex.lease-notices.index', array_merge(request()->query(), ['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc']));
    $arrow = fn (string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
@endphp
<div class="p-6 space-y-4">
    <h1 class="text-lg font-semibold">Notices received</h1>
    <p class="text-sm" style="color: var(--text-muted);">A tenant or a landlord has given notice. Review each one against the lease and the law before anything goes to the other party.</p>

    <form method="GET" action="{{ route('corex.lease-notices.index') }}" class="flex flex-wrap items-end gap-3" data-qa="lease-notices-filters">
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Search</label><br>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Name, address or reason" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Status</label><br>
            <select name="status" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach(\App\Models\LeaseNotice::STATUS_LABELS as $key => $label)
                    <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Given by</label><br>
            <select name="given_by" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">Anyone</option>
                <option value="tenant" @selected(request('given_by') === 'tenant')>Tenant</option>
                <option value="landlord" @selected(request('given_by') === 'landlord')>Landlord</option>
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Given from</label><br>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">to</label><br>
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <label class="flex items-center gap-2 text-xs pb-2"><input type="checkbox" name="archived" value="1" @checked(request()->boolean('archived'))> Archived</label>
        <button type="submit" class="corex-btn-outline text-xs">Filter</button>
        <a href="{{ route('corex.lease-notices.index') }}" class="corex-btn-outline text-xs">Reset</a>
    </form>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2); color: var(--text-muted);">
                    <th class="text-left px-4 py-2 font-medium"><a href="{{ $sortLink('notice_date') }}">Given{{ $arrow('notice_date') }}</a></th>
                    <th class="text-left px-4 py-2 font-medium"><a href="{{ $sortLink('property') }}">Property{{ $arrow('property') }}</a></th>
                    <th class="text-left px-4 py-2 font-medium">By</th>
                    <th class="text-left px-4 py-2 font-medium"><a href="{{ $sortLink('intended_end_date') }}">Ends{{ $arrow('intended_end_date') }}</a></th>
                    <th class="text-left px-4 py-2 font-medium"><a href="{{ $sortLink('status') }}">Status{{ $arrow('status') }}</a></th>
                    <th class="text-right px-4 py-2 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($notices as $n)
                    <tr style="border-top: 1px solid var(--border);" data-notice-row="{{ $n->id }}">
                        <td class="px-4 py-2">{{ $n->notice_date->format('j M Y') }}</td>
                        <td class="px-4 py-2">{{ $n->lease?->property?->buildDisplayAddress() ?: ('Lease #' . $n->lease_id) }}{{ $n->lease?->property?->trashed() ? ' (archived)' : '' }}</td>
                        <td class="px-4 py-2">{{ $n->givenByLabel() }}{{ $n->givenByContact ? ' - ' . $n->givenByContact->full_name : '' }}</td>
                        <td class="px-4 py-2">{{ $n->effectiveEndDate()->format('j M Y') }}</td>
                        <td class="px-4 py-2"><span class="ds-badge {{ $n->isReceived() ? 'ds-badge-warning' : ($n->isAccepted() ? 'ds-badge-success' : 'ds-badge-muted') }}">{{ $n->statusLabel() }}</span>{{ $n->trashed() ? ' (archived)' : '' }}</td>
                        <td class="px-4 py-2 text-right">
                            <a href="{{ route('corex.lease-notices.show', $n->id) }}" class="corex-btn-outline text-xs">{{ $n->isReceived() ? 'Review' : 'Open' }}</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);" data-qa="lease-notices-empty">
                            @if(request()->hasAny(['q', 'given_by', 'date_from', 'date_to', 'archived']) || $status !== 'received')
                                No notices match these filters.
                            @else
                                Nothing waiting for review. When a tenant or a landlord gives notice - from their portal, or recorded on a lease - it appears here.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $notices->links() }}
</div>
@endsection
