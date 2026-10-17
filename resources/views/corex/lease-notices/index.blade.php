@extends('layouts.corex')

{{--
    .ai/specs/lease-notices.md §8 - notices received (given by a tenant or a landlord). List-screen floor: search (tenant / owner name,
    property address, reason), sort (date given, end date, status, property - default newest first), filter (status - default "needs
    review", given by, date range, archived), pagination, a real empty state. Visible exactly as the lease is (own / branch / agency).
--}}

@section('corex-content')
@php
    $sortLink = fn (string $col) => route('corex.lease-notices.index', array_merge(request()->query(), ['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc']));
    $arrow = fn (string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
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
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Notices received</h1>
        <p class="text-xs" style="color: var(--text-muted);">A tenant or a landlord has given notice. Review each one against the lease and the law before anything goes to the other party.</p>
    </div>

    <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
    <form method="GET" action="{{ route('corex.lease-notices.index') }}" class="flex flex-wrap items-end gap-3" data-qa="lease-notices-filters">
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Name, address or reason" class="list-header-filter text-sm {{ filled(request('q')) ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Status</label>
            <select name="status" class="list-header-filter text-sm {{ $status !== 'received' ? 'is-active' : '' }}">
                <option value="">All statuses</option>
                @foreach(\App\Models\LeaseNotice::STATUS_LABELS as $key => $label)
                    <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Given by</label>
            <select name="given_by" class="list-header-filter text-sm {{ filled(request('given_by')) ? 'is-active' : '' }}">
                <option value="">Anyone</option>
                <option value="tenant" @selected(request('given_by') === 'tenant')>Tenant</option>
                <option value="landlord" @selected(request('given_by') === 'landlord')>Landlord</option>
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Given from</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="list-header-filter text-sm {{ filled(request('date_from')) ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">to</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="list-header-filter text-sm {{ filled(request('date_to')) ? 'is-active' : '' }}">
        </div>
        <label class="flex items-center gap-2 text-xs" style="height:2.25rem; color: var(--text-secondary);"><input type="checkbox" name="archived" value="1" @checked(request()->boolean('archived'))> Archived</label>
        <button type="submit" class="corex-btn-primary text-sm" style="height:2.25rem;">Filter</button>
        <a href="{{ route('corex.lease-notices.index') }}" class="corex-btn-outline text-sm" style="height:2.25rem;">Reset</a>
        <div class="flex-1"></div>
        <span class="ds-badge ds-badge-pill ds-badge-info">
            {{ number_format($notices->total()) }} {{ \Illuminate\Support\Str::plural('notice', $notices->total()) }}
        </span>
    </form>
    </div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2); color: var(--text-muted); border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('notice_date') }}">Given{{ $arrow('notice_date') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('property') }}">Property{{ $arrow('property') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">By</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('intended_end_date') }}">Ends{{ $arrow('intended_end_date') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('status') }}">Status{{ $arrow('status') }}</a></th>
                    <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($notices as $n)
                    <tr style="border-top: 1px solid var(--border); color: var(--text-secondary);" data-notice-row="{{ $n->id }}">
                        <td class="px-4 py-3">{{ $n->notice_date->format('j M Y') }}</td>
                        <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $n->lease?->property?->buildDisplayAddress() ?: ('Lease #' . $n->lease_id) }}{{ $n->lease?->property?->trashed() ? ' (archived)' : '' }}</td>
                        <td class="px-4 py-3">{{ $n->givenByLabel() }}{{ $n->givenByContact ? ' - ' . $n->givenByContact->full_name : '' }}</td>
                        <td class="px-4 py-3">{{ $n->effectiveEndDate()->format('j M Y') }}</td>
                        <td class="px-4 py-3"><span class="ds-badge {{ $badge($n->isReceived() ? '--ds-amber' : ($n->isAccepted() ? '--ds-green' : '--text-muted')) }}">{{ $n->statusLabel() }}</span>{{ $n->trashed() ? ' (archived)' : '' }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('corex.lease-notices.show', $n->id) }}" class="corex-btn-outline text-xs">{{ $n->isReceived() ? 'Review' : 'Open' }}</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center text-sm" style="color: var(--text-muted);" data-qa="lease-notices-empty">
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
        @if($notices->hasPages())
        <div class="px-5 py-4" style="border-top:1px solid var(--border);">
            {{ $notices->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
