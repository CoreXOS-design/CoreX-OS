@extends('layouts.corex')

{{--
    .ai/specs/rental-portal-access.md §10 — AT-445; AT-495 (.ai/specs/rental-notices.md §9). The "Notices sent" tab. List-screen floor: search
    (tenant name, property address, lease number, document name), sort (date sent - default, newest first -, type, delivery), filter (type,
    delivery status, date range, archived), own/branch/agency scope (Lease::visibleTo), pagination, a real empty state with its add action.
    Per row: View, Download, Resend, and what really happened to each email (Emailed / Partly sent / NOT sent / Not recorded).
--}}

@section('corex-content')
@php
    $sortLink = fn (string $col) => route('corex.rental-notices.index', array_merge(request()->except('page'), ['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc']));
    $arrow = fn (string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    $deliveryTone = fn (?string $st) => match ($st) {
        'sent' => 'ds-badge-success',
        'partial' => 'ds-badge-warning',
        'not_sent' => 'ds-badge-danger',
        default => 'ds-badge-muted',
    };
    $showArchived = request()->boolean('archived');
@endphp
<div class="w-full space-y-5">
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Notices sent</h1>
                <p class="text-xs" style="color: var(--text-muted);">Every notice sent to a tenant or landlord: what it was, who it reached and when.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @include('corex.notices._pick-lease-button', ['purpose' => 'sent', 'label' => 'Send notice', 'title' => 'Send a notice - which lease?', 'qa' => 'send-notice'])
            </div>
        </div>
    </div>
    @include('corex.notices._tabs', ['active' => 'sent'])
    @include('corex.notices._lease-picker')

    <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
    <form method="GET" action="{{ route('corex.rental-notices.index') }}" class="flex flex-wrap items-end gap-3" data-qa="rental-notices-filters">
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Tenant, address, lease no. or document" class="list-header-filter text-sm {{ filled(request('q')) ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Type</label>
            <select name="notice_type" class="list-header-filter text-sm {{ filled(request('notice_type')) ? 'is-active' : '' }}">
                <option value="">All types</option>
                @foreach(\App\Models\RentalNoticeTemplate::TYPES as $t)
                    <option value="{{ $t }}" @selected(request('notice_type') === $t)>{{ \App\Models\RentalNoticeTemplate::labelFor($t) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Delivery</label>
            <select name="status" class="list-header-filter text-sm {{ filled($status) ? 'is-active' : '' }}">
                <option value="">Any</option>
                <option value="sent" @selected($status === 'sent')>Emailed</option>
                <option value="partial" @selected($status === 'partial')>Partly sent</option>
                <option value="not_sent" @selected($status === 'not_sent')>NOT sent</option>
                <option value="unrecorded" @selected($status === 'unrecorded')>Not recorded</option>
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">From</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="list-header-filter text-sm {{ filled(request('date_from')) ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">To</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="list-header-filter text-sm {{ filled(request('date_to')) ? 'is-active' : '' }}">
        </div>
        <label class="flex items-center gap-2 text-xs" style="height:2.25rem; color: var(--text-secondary);"><input type="checkbox" name="archived" value="1" @checked($showArchived)> Archived</label>
        <button type="submit" class="corex-btn-primary text-sm" style="height:2.25rem;">Filter</button>
        <a href="{{ route('corex.rental-notices.index') }}" class="corex-btn-outline text-sm" style="height:2.25rem;">Reset</a>
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
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('sent_at') }}">Sent{{ $arrow('sent_at') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Property</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('notice_type') }}">Type{{ $arrow('notice_type') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Recipients</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('delivery_status') }}">Delivery{{ $arrow('delivery_status') }}</a></th>
                    <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($notices as $notice)
                    <tr style="border-top: 1px solid var(--border); color: var(--text-secondary);" data-notice-row="{{ $notice->id }}">
                        <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $notice->sent_at?->format('d M Y H:i') }}{{ $notice->trashed() ? ' (archived)' : '' }}</td>
                        <td class="px-4 py-3">{{ $notice->lease?->property?->buildDisplayAddress() ?: ('Lease #' . $notice->lease_id) }}{{ $notice->lease?->property?->trashed() ? ' (archived)' : '' }}</td>
                        <td class="px-4 py-3">{{ \App\Models\RentalNoticeTemplate::labelFor($notice->notice_type) }}</td>
                        <td class="px-4 py-3">
                            @if($notice->sent_to_tenant)<span class="ds-badge ds-badge-pill ds-badge-muted">Tenant</span>@endif
                            @if($notice->sent_to_landlord)<span class="ds-badge ds-badge-pill ds-badge-muted">Landlord</span>@endif
                        </td>
                        <td class="px-4 py-3" data-qa="notice-delivery"><span class="ds-badge {{ $deliveryTone($notice->delivery_status) }}">{{ $notice->deliveryLabel() }}</span></td>
                        <td class="px-4 py-3 text-right whitespace-nowrap"><div class="inline-flex items-center justify-end gap-2" data-qa="notice-actions">
                            <a href="{{ route('corex.rental-notices.show', $notice) }}" class="corex-btn-outline text-xs">View</a>
                            @if($notice->document_id)
                                <a href="{{ route('corex.rental-notices.download-document', $notice) }}" class="corex-btn-outline text-xs" data-qa="notice-download">Download</a>
                            @endif
                            @permission('rental_notices.create')
                                @if(! $notice->trashed() && $notice->isResendable())
                                    <form method="POST" action="{{ route('corex.rental-notices.resend', $notice) }}" class="inline-flex" data-confirm="Email this notice again to the people it was addressed to?" data-confirm-label="Resend">
                                        @csrf
                                        <button type="submit" class="corex-btn-outline text-xs" data-qa="notice-resend">Resend</button>
                                    </form>
                                @endif
                                @if($notice->trashed())
                                    <form method="POST" action="{{ route('corex.rental-notices.restore', $notice->id) }}" class="inline-flex">
                                        @csrf
                                        <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                                    </form>
                                @endif
                            @endpermission
                        </div></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center text-sm" style="color: var(--text-muted);" data-qa="rental-notices-empty">
                            @if(request()->hasAny(['q', 'notice_type', 'status', 'date_from', 'date_to', 'archived']))
                                No notices match these filters.
                            @else
                                No notices sent yet. Choose a lease and one of your agency's templates to send the first one.
                                <div class="mt-3">@include('corex.notices._pick-lease-button', ['purpose' => 'sent', 'label' => 'Send notice', 'title' => 'Send a notice - which lease?', 'qa' => 'send-notice-empty'])</div>
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
