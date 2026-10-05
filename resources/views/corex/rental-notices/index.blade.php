@extends('layouts.corex')

{{--
    .ai/specs/rental-portal-access.md §10 — AT-445. CRUD/list-screen
    floor: search (tenant name, property address), sort (date sent
    default, newest first), filter (notice type, date range), own/branch/
    agency scope (BelongsToAgency + AgencyScope on the model), pagination,
    empty state. Notices are sent, not edited — no update/archive beyond
    the normal soft-delete floor for a sending error.
--}}

@section('content')
<div class="p-6 space-y-4">
    <h1 class="text-lg font-semibold">Rental Notices</h1>

    <form method="GET" action="{{ route('corex.rental-notices.index') }}" class="flex flex-wrap items-end gap-3">
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Search</label><br>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Tenant name or address" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Type</label><br>
            <select name="notice_type" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach(\App\Models\RentalNoticeTemplate::TYPES as $t)
                    <option value="{{ $t }}" @selected(request('notice_type') === $t)>{{ ucfirst(str_replace('_', ' ', $t)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">From</label><br>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">To</label><br>
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <button type="submit" class="corex-btn-outline text-xs">Filter</button>
    </form>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2); color: var(--text-muted);">
                    <th class="text-left px-4 py-2 font-medium">Sent</th>
                    <th class="text-left px-4 py-2 font-medium">Property</th>
                    <th class="text-left px-4 py-2 font-medium">Type</th>
                    <th class="text-left px-4 py-2 font-medium">Recipients</th>
                    <th class="text-right px-4 py-2 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($notices as $notice)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-2">{{ $notice->sent_at?->format('d M Y H:i') }}</td>
                        <td class="px-4 py-2">{{ $notice->lease?->property?->buildDisplayAddress() }}{{ $notice->lease?->property?->trashed() ? ' (archived)' : '' }}</td>
                        <td class="px-4 py-2">{{ ucfirst(str_replace('_', ' ', $notice->notice_type)) }}</td>
                        <td class="px-4 py-2">
                            @if($notice->sent_to_tenant)<span class="ds-badge ds-badge-muted">Tenant</span>@endif
                            @if($notice->sent_to_landlord)<span class="ds-badge ds-badge-muted">Landlord</span>@endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            <a href="{{ route('corex.rental-notices.show', $notice) }}" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">No notices sent yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $notices->links() }}
</div>
@endsection
