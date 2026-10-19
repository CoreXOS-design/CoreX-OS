@extends('layouts.corex')

{{--
    .ai/specs/rental-portal-access.md §10 — AT-445. CRUD/list-screen
    floor: search (tenant name, property address), sort (date sent
    default, newest first), filter (notice type, date range), own/branch/
    agency scope (BelongsToAgency + AgencyScope on the model), pagination,
    empty state. Notices are sent, not edited — no update/archive beyond
    the normal soft-delete floor for a sending error.
--}}

@section('corex-content')
<div class="w-full space-y-5">
    @include('corex.notices._tabs', ['active' => 'sent'])
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Rental Notices</h1>
        <p class="text-xs" style="color: var(--text-muted);">Every notice sent to a tenant or landlord: what it was, who received it and when.</p>
    </div>

    <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
    <form method="GET" action="{{ route('corex.rental-notices.index') }}" class="flex flex-wrap items-end gap-3">
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Tenant name or address" class="list-header-filter text-sm {{ filled(request('q')) ? 'is-active' : '' }}">
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
            <label class="text-xs font-medium" style="color: var(--text-secondary);">From</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="list-header-filter text-sm {{ filled(request('date_from')) ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">To</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="list-header-filter text-sm {{ filled(request('date_to')) ? 'is-active' : '' }}">
        </div>
        <button type="submit" class="corex-btn-primary text-sm" style="height:2.25rem;">Filter</button>
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
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Sent</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Property</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Type</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Recipients</th>
                    <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($notices as $notice)
                    <tr style="border-top: 1px solid var(--border); color: var(--text-secondary);">
                        <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $notice->sent_at?->format('d M Y H:i') }}</td>
                        <td class="px-4 py-3">{{ $notice->lease?->property?->buildDisplayAddress() }}{{ $notice->lease?->property?->trashed() ? ' (archived)' : '' }}</td>
                        <td class="px-4 py-3">{{ \App\Models\RentalNoticeTemplate::labelFor($notice->notice_type) }}</td>
                        <td class="px-4 py-3">
                            @if($notice->sent_to_tenant)<span class="ds-badge ds-badge-pill ds-badge-muted">Tenant</span>@endif
                            @if($notice->sent_to_landlord)<span class="ds-badge ds-badge-pill ds-badge-muted">Landlord</span>@endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('corex.rental-notices.show', $notice) }}" class="corex-btn-outline text-xs">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center text-sm" style="color: var(--text-muted);">No notices sent yet.</td>
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
