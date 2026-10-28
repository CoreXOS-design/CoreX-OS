@extends('layouts.corex')

{{--
    AT-442 — the agency's own parts & labour catalogue consumed by internal
    job cards. CRUD/list-screen floor (BUILD_STANDARD §1a-§1d): search
    (code, description), sort (sort_order default; code, description,
    selling_excl, and cost_excl for anyone who may see costs), filter (type, active/archived), pagination, real empty
    state, agency scoping via BelongsToAgency + AgencyScope on the model.

    2026-10-05 (Johan QA1 finding) — split the old single `name` column
    into `code` (short, agency-unique among active items) + `description`
    (full text) so picking an item on a job card no longer leaves the
    description still to be typed by hand.

    Pastel-style enhancement, 2026-10-05: type/unit are now agency-
    configurable lists; the price column splits into excl/VAT type/incl
    (RentalJobCardVatService::catalogueItemPrices()) since the price is
    always stored excl-VAT regardless of capture mode.
    §17.38: the single price column became Cost excl (view_costs only) + Selling excl, both sortable.
--}}

@section('corex-content')
@php
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
            <div data-tour="rmnt-cat-intro">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Parts &amp; Labour Catalogue</h1>
                <p class="text-xs" style="color: var(--text-muted);">The labour and parts your maintenance team picks from when pricing a job card.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
                <a href="{{ route('admin.company-settings') }}#catalogue-item-types" class="corex-btn-outline text-xs" data-tour="rmnt-cat-types">Manage types &amp; units</a>
                @permission('rental_catalogue.manage')
                <a href="{{ route('corex.rental-catalogue-items.import.index') }}" class="corex-btn-outline text-xs" data-tour="rmnt-cat-import">Import</a>
                <a href="{{ route('corex.rental-catalogue-items.create') }}" class="corex-btn-primary text-xs" data-tour="rmnt-cat-add">+ Add Item</a>
                @endpermission
            </div>
        </div>
    </div>

    {{-- .ai/specs/rentals-menu-tabs.md - Parts & Labour Catalogue is a tab of Rentals > Maintenance --}}
    @include('corex.partials._hub-tabs', ['hub' => \App\Support\Navigation\Hubs\MaintenanceTabs::class])

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);" data-tour="rmnt-cat-filters">
    <form method="GET" action="{{ route('corex.rental-catalogue-items.index') }}" class="flex flex-wrap items-end gap-3">
        <div class="flex flex-col gap-1" data-tour="rmnt-cat-search">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Code or description" class="list-header-filter text-sm {{ filled(request('q')) ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1" data-tour="rmnt-cat-type">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Type</label>
            <select name="type" class="list-header-filter text-sm {{ filled(request('type')) ? 'is-active' : '' }}">
                <option value="">All types</option>
                @foreach($catalogueItemTypes as $t)
                    <option value="{{ $t->id }}" @selected((string) request('type') === (string) $t->id)>{{ $t->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1" data-tour="rmnt-cat-status">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Status</label>
            <select name="status" class="list-header-filter text-sm {{ $status !== 'active' ? 'is-active' : '' }}">
                <option value="active" @selected($status === 'active')>Active</option>
                <option value="archived" @selected($status === 'archived')>Archived</option>
            </select>
        </div>
        <button type="submit" class="corex-btn-primary text-sm" style="height:2.25rem;" data-tour="rmnt-cat-filter-btn">Filter</button>
        <div class="flex-1"></div>
        <span class="ds-badge ds-badge-pill ds-badge-info">
            {{ number_format($items->total()) }} {{ \Illuminate\Support\Str::plural('item', $items->total()) }}
        </span>
    </form>
    </div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);" data-tour="rmnt-cat-table">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2); color: var(--text-muted); border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ route('corex.rental-catalogue-items.index', array_merge(request()->except('page'), ['sort' => 'code'])) }}">Code</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ route('corex.rental-catalogue-items.index', array_merge(request()->except('page'), ['sort' => 'description'])) }}">Description</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Type</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Unit</th>
                    @if($canViewCosts)
                        <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ route('corex.rental-catalogue-items.index', array_merge(request()->except('page'), ['sort' => 'cost_excl'])) }}">Cost excl</a></th>
                    @endif
                    <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ route('corex.rental-catalogue-items.index', array_merge(request()->except('page'), ['sort' => 'selling_excl'])) }}">Selling excl</a></th>
                    @if($agency?->vat_registered)
                        <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">VAT type</th>
                        <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Selling incl</th>
                    @endif
                    <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    @php $prices = $vat->catalogueItemPrices($item, $agency); @endphp
                    <tr style="border-top: 1px solid var(--border); color: var(--text-secondary);">
                        <td class="px-4 py-3" style="font-family: monospace;">{{ $item->code }}</td>
                        <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $item->description }}</td>
                        <td class="px-4 py-3"><span class="ds-badge {{ $badge($item->kind() === 'labour' ? '--brand-icon' : '--text-muted') }}">{{ $item->catalogueItemType->name ?? '—' }}</span></td>
                        <td class="px-4 py-3">{{ $item->catalogueUnit->name ?? '—' }}</td>
                        @if($canViewCosts)
                            <td class="px-4 py-3 text-right">
                                @if($item->cost_excl !== null)
                                    {{ 'R' . number_format((float) $item->cost_excl, 2) }}
                                @elseif($item->kind() === 'labour' && $status !== 'archived')
                                    {{-- A labour line costs crew hours x the rate: an empty rate breaks the line, so say so and link straight to the fix. --}}
                                    @permission('rental_catalogue.manage')
                                        <a href="{{ route('corex.rental-catalogue-items.edit', $item) }}" class="underline" style="color: var(--ds-crimson);">— set cost</a>
                                    @else
                                        <span style="color: var(--ds-crimson);">— set cost</span>
                                    @endpermission
                                @else
                                    —
                                @endif
                            </td>
                        @endif
                        <td class="px-4 py-3 text-right">{{ $prices['excl'] !== null ? 'R' . number_format($prices['excl'], 2) : '—' }}</td>
                        @if($agency?->vat_registered)
                            <td class="px-4 py-3">{{ $prices['label'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">{{ $prices['incl'] !== null ? 'R' . number_format($prices['incl'], 2) : '—' }}</td>
                        @endif
                        <td class="px-4 py-3 text-right space-x-2 cx-equal-btns">
                            @permission('rental_catalogue.manage')
                                @if($status === 'archived')
                                    <form method="POST" action="{{ route('corex.rental-catalogue-items.restore', $item->id) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                                    </form>
                                @else
                                    <a href="{{ route('corex.rental-catalogue-items.edit', $item) }}" class="corex-btn-outline text-xs" @if($loop->first) data-tour="rmnt-cat-edit" @endif>Edit</a>
                                    <form method="POST" action="{{ route('corex.rental-catalogue-items.archive', $item) }}" class="inline" data-confirm="Archive this item?" data-confirm-danger data-confirm-label="Archive">
                                        @csrf
                                        <button type="submit" class="corex-btn-danger text-xs" @if($loop->first) data-tour="rmnt-cat-archive" @endif>Archive</button>
                                    </form>
                                @endif
                            @endpermission
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ 6 + ($agency?->vat_registered ? 2 : 0) + ($canViewCosts ? 1 : 0) }}" class="px-6 py-12 text-center text-sm" style="color: var(--text-muted);">
                            @if(request('q') || request('type'))
                                No catalogue items match this filter.
                            @else
                                No catalogue items yet — add the labour and part types your maintenance team uses.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
        @if($items->hasPages())
        <div class="px-5 py-4" style="border-top:1px solid var(--border);">
            {{ $items->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
