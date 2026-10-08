@extends('layouts.corex')

{{--
    AT-442 — the agency's own parts & labour catalogue consumed by internal
    job cards. CRUD/list-screen floor (BUILD_STANDARD §1a-§1d): search
    (code, description), sort (sort_order default; code, description,
    default_price), filter (type, active/archived), pagination, real empty
    state, agency scoping via BelongsToAgency + AgencyScope on the model.

    2026-10-05 (Johan QA1 finding) — split the old single `name` column
    into `code` (short, agency-unique among active items) + `description`
    (full text) so picking an item on a job card no longer leaves the
    description still to be typed by hand.

    Pastel-style enhancement, 2026-10-05: type/unit are now agency-
    configurable lists; the price column splits into excl/VAT type/incl
    (RentalJobCardVatService::catalogueItemPrices()) since default_price is
    always stored excl-VAT regardless of capture mode.
--}}

@section('content')
<div class="p-6 space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Parts &amp; Labour Catalogue</h1>
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.company-settings') }}#catalogue-item-types" class="text-xs underline" style="color: var(--text-muted);">Manage types &amp; units</a>
            @permission('rental_catalogue.manage')
            <a href="{{ route('corex.rental-catalogue-items.import.index') }}" class="corex-btn-outline text-xs">Import</a>
            <a href="{{ route('corex.rental-catalogue-items.create') }}" class="corex-btn-primary text-xs">+ Add Item</a>
            @endpermission
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46;">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    <form method="GET" action="{{ route('corex.rental-catalogue-items.index') }}" class="flex flex-wrap items-end gap-3">
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Search</label><br>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Code or description" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Type</label><br>
            <select name="type" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach($catalogueItemTypes as $t)
                    <option value="{{ $t->id }}" @selected((string) request('type') === (string) $t->id)>{{ $t->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Status</label><br>
            <select name="status" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="active" @selected($status === 'active')>Active</option>
                <option value="archived" @selected($status === 'archived')>Archived</option>
            </select>
        </div>
        <button type="submit" class="corex-btn-outline text-xs">Filter</button>
    </form>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2); color: var(--text-muted);">
                    <th class="text-left px-4 py-2 font-medium"><a href="{{ route('corex.rental-catalogue-items.index', array_merge(request()->except('page'), ['sort' => 'code'])) }}">Code</a></th>
                    <th class="text-left px-4 py-2 font-medium"><a href="{{ route('corex.rental-catalogue-items.index', array_merge(request()->except('page'), ['sort' => 'description'])) }}">Description</a></th>
                    <th class="text-left px-4 py-2 font-medium">Type</th>
                    <th class="text-left px-4 py-2 font-medium">Unit</th>
                    <th class="text-right px-4 py-2 font-medium"><a href="{{ route('corex.rental-catalogue-items.index', array_merge(request()->except('page'), ['sort' => 'default_price'])) }}">Excl VAT</a></th>
                    @if($agency?->vat_registered)
                        <th class="text-left px-4 py-2 font-medium">VAT type</th>
                        <th class="text-right px-4 py-2 font-medium">Incl VAT</th>
                    @endif
                    <th class="text-right px-4 py-2 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    @php $prices = $vat->catalogueItemPrices($item, $agency); @endphp
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-2" style="font-family: monospace;">{{ $item->code }}</td>
                        <td class="px-4 py-2">{{ $item->description }}</td>
                        <td class="px-4 py-2"><span class="ds-badge {{ $item->kind() === 'labour' ? 'ds-badge-info' : 'ds-badge-muted' }}">{{ $item->catalogueItemType->name ?? '—' }}</span></td>
                        <td class="px-4 py-2">{{ $item->catalogueUnit->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-right">{{ $prices['excl'] !== null ? 'R' . number_format($prices['excl'], 2) : '—' }}</td>
                        @if($agency?->vat_registered)
                            <td class="px-4 py-2">{{ $prices['label'] ?? '—' }}</td>
                            <td class="px-4 py-2 text-right">{{ $prices['incl'] !== null ? 'R' . number_format($prices['incl'], 2) : '—' }}</td>
                        @endif
                        <td class="px-4 py-2 text-right space-x-2">
                            @permission('rental_catalogue.manage')
                                @if($status === 'archived')
                                    <form method="POST" action="{{ route('corex.rental-catalogue-items.restore', $item->id) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">Restore</button>
                                    </form>
                                @else
                                    <a href="{{ route('corex.rental-catalogue-items.edit', $item) }}" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">Edit</a>
                                    <form method="POST" action="{{ route('corex.rental-catalogue-items.archive', $item) }}" class="inline" data-confirm="Archive this item?" data-confirm-danger data-confirm-label="Archive">
                                        @csrf
                                        <button type="submit" class="text-xs" style="color: #991b1b;">Archive</button>
                                    </form>
                                @endif
                            @endpermission
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $agency?->vat_registered ? 8 : 6 }}" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
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
    </div>

    {{ $items->links() }}
</div>
@endsection
