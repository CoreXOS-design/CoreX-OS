@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Title Deeds</h1>
            <div class="flex flex-wrap items-center gap-2">
                @if($total > 0)
                <a href="{{ route('title-deeds.export', request()->query()) }}" class="corex-btn-outline text-xs">Export CSV</a>
                @endif
                @permission('title_deeds.create')
                <a href="{{ route('title-deeds.create') }}" class="corex-btn-primary text-xs">Log a deed</a>
                @endpermission
            </div>
        </div>
    </div>

    @if(session('success'))<div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">{{ session('error') }}</div>@endif

    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" action="{{ route('title-deeds.index') }}" class="flex flex-wrap items-end gap-3">
            @if(request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}"><input type="hidden" name="direction" value="{{ request('direction') }}">@endif
            <div class="flex-1 min-w-[220px]">
                <label for="td-search" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
                <input id="td-search" type="text" name="search" value="{{ $filters['search'] }}" maxlength="100"
                       placeholder="Property, owner, attorney, collector"
                       class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label for="td-status" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Status</label>
                <select id="td-status" name="status" class="rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">All</option>
                    @foreach($statuses as $key => $label)
                        <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
                    @endforeach
                    <option value="archived" @selected($filters['status'] === 'archived')>Archived</option>
                </select>
            </div>
            @if($isAll && $branches->count() > 1)
            <div>
                <label for="td-branch" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Branch</label>
                <select id="td-branch" name="branch_id" class="rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">All branches</option>
                    @foreach($branches as $b)<option value="{{ $b->id }}" @selected((string) $filters['branch_id'] === (string) $b->id)>{{ $b->name }}</option>@endforeach
                </select>
            </div>
            @endif
            @if($agents->count() > 1)
            <div>
                <label for="td-agent" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Agent</label>
                <select id="td-agent" name="agent_id" class="rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">All agents</option>
                    @foreach($agents as $a)<option value="{{ $a->id }}" @selected((string) $filters['agent_id'] === (string) $a->id)>{{ $a->name }}</option>@endforeach
                </select>
            </div>
            @endif
            <div>
                <label for="td-from" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Received from</label>
                <input id="td-from" type="date" name="from" value="{{ $filters['from'] }}" class="rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label for="td-to" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">to</label>
                <input id="td-to" type="date" name="to" value="{{ $filters['to'] }}" class="rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div class="flex items-center gap-2">
                <button type="submit" class="corex-btn-primary">Filter</button>
                @if($hasFilters)<a href="{{ route('title-deeds.index') }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Clear</a>@endif
            </div>
        </form>
    </div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <x-sort-header field="property" label="Property" />
                        <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Owner(s)</th>
                        <x-sort-header field="agent" label="Agent" />
                        <x-sort-header field="received" label="Received" />
                        <x-sort-header field="status" label="Status" />
                        <x-sort-header field="waiting" label="Days waiting" align="right" />
                        <x-sort-header field="collected" label="Collected" />
                    </tr>
                </thead>
                <tbody>
                    @forelse($deeds as $d)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-3">
                            <a href="{{ route('title-deeds.show', $d->id) }}" class="font-medium no-underline hover:underline" style="color: var(--text-primary);">{{ $d->property_display }}</a>
                        </td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $d->owners->map->display_name->implode(', ') ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $d->agent?->name ?? '—' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $d->received_at?->format('d M Y') }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            @if($d->trashed())
                                <span style="color: var(--ds-amber);">Archived</span>
                            @elseif($d->isCollected())
                                <span style="color: var(--ds-green);">Collected</span>
                            @else
                                <span style="color: {{ $d->status === 'notified' ? 'var(--brand-icon)' : 'var(--text-secondary)' }};">{{ $d->status_label }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $d->days_waiting ?? '—' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap" style="color: var(--text-secondary);">
                            @if($d->isCollected())
                                {{ $d->collected_at?->format('d M Y H:i') }} · {{ $d->collected_by_name }}
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                            @if($hasFilters)
                                No title deeds match these filters.
                                <a href="{{ route('title-deeds.index') }}" class="font-semibold" style="color: var(--brand-icon);">Clear the filters</a>
                            @else
                                No title deeds logged yet.
                                @permission('title_deeds.create')<a href="{{ route('title-deeds.create') }}" class="font-semibold" style="color: var(--brand-icon);">Log the first one</a>@endpermission
                            @endif
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($deeds->hasPages())
        <div class="px-4 py-3 flex items-center justify-between flex-wrap gap-2" style="border-top:1px solid var(--border);">
            <div class="text-xs" style="color:var(--text-muted);">{{ number_format($deeds->firstItem() ?? 0) }}–{{ number_format($deeds->lastItem() ?? 0) }} of {{ number_format($deeds->total()) }}</div>
            <div>{{ $deeds->onEachSide(1)->links() }}</div>
        </div>
        @endif
    </div>
</div>
@endsection
