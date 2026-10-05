@extends('layouts.corex')

{{--
    AT-442 — the agency's own parts & labour catalogue consumed by internal
    job cards. CRUD/list-screen floor (BUILD_STANDARD §1a-§1d): search
    (name), sort (sort_order default; name, type, default_price), filter
    (type, active/archived), pagination, real empty state, agency scoping
    via BelongsToAgency + AgencyScope on the model.
--}}

@section('content')
<div class="p-6 space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Parts &amp; Labour Catalogue</h1>
        @permission('rental_catalogue.manage')
        <a href="{{ route('corex.rental-catalogue-items.create') }}" class="corex-btn-primary text-xs">+ Add Item</a>
        @endpermission
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
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Name" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Type</label><br>
            <select name="type" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                <option value="labour" @selected(request('type') === 'labour')>Labour</option>
                <option value="part" @selected(request('type') === 'part')>Part</option>
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
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2); color: var(--text-muted);">
                    <th class="text-left px-4 py-2 font-medium"><a href="{{ route('corex.rental-catalogue-items.index', array_merge(request()->except('page'), ['sort' => 'name'])) }}">Name</a></th>
                    <th class="text-left px-4 py-2 font-medium"><a href="{{ route('corex.rental-catalogue-items.index', array_merge(request()->except('page'), ['sort' => 'type'])) }}">Type</a></th>
                    <th class="text-left px-4 py-2 font-medium">Unit</th>
                    <th class="text-left px-4 py-2 font-medium"><a href="{{ route('corex.rental-catalogue-items.index', array_merge(request()->except('page'), ['sort' => 'default_price'])) }}">{{ $priceLabel }}</a></th>
                    <th class="text-right px-4 py-2 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-2">{{ $item->name }}</td>
                        <td class="px-4 py-2"><span class="ds-badge {{ $item->type === 'labour' ? 'ds-badge-info' : 'ds-badge-muted' }}">{{ ucfirst($item->type) }}</span></td>
                        <td class="px-4 py-2">{{ $item->unit }}</td>
                        <td class="px-4 py-2">{{ $item->default_price !== null ? 'R' . number_format((float) $item->default_price, 2) : '—' }}</td>
                        <td class="px-4 py-2 text-right space-x-2">
                            @permission('rental_catalogue.manage')
                                @if($status === 'archived')
                                    <form method="POST" action="{{ route('corex.rental-catalogue-items.restore', $item->id) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">Restore</button>
                                    </form>
                                @else
                                    <a href="{{ route('corex.rental-catalogue-items.edit', $item) }}" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">Edit</a>
                                    <form method="POST" action="{{ route('corex.rental-catalogue-items.archive', $item) }}" class="inline" onsubmit="return confirm('Archive this item?');">
                                        @csrf
                                        <button type="submit" class="text-xs" style="color: #991b1b;">Archive</button>
                                    </form>
                                @endif
                            @endpermission
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
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

    {{ $items->links() }}
</div>
@endsection
