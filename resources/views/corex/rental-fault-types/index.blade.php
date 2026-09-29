@extends('layouts.corex')

{{--
    .ai/specs/rentals-faults-work-orders.md §2/§8.1 — the agency's fault
    catalogue settings screen. CRUD/list-screen floor (BUILD_STANDARD
    §1a-§1d): search (name, category), sort (sort_order default; name,
    category, urgency), filter (category, urgency, active/archived),
    pagination, real empty state, agency scoping via BelongsToAgency +
    AgencyScope on the model.
--}}

@section('content')
<div class="p-6 space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Rental Fault Types</h1>
        @permission('rental_fault_types.create')
        <a href="{{ route('corex.rental-fault-types.create') }}" class="corex-btn-primary text-xs">+ Add Fault Type</a>
        @endpermission
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46;">{{ session('success') }}</div>
    @endif

    <form method="GET" action="{{ route('corex.rental-fault-types.index') }}" class="flex flex-wrap items-end gap-3">
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Search</label><br>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Name or category" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Category</label><br>
            <select name="category" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach($categories as $c)
                    <option value="{{ $c }}" @selected(request('category') === $c)>{{ $c }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Urgency</label><br>
            <select name="urgency" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach(['routine', 'urgent', 'emergency'] as $u)
                    <option value="{{ $u }}" @selected(request('urgency') === $u)>{{ ucfirst($u) }}</option>
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
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2); color: var(--text-muted);">
                    <th class="text-left px-4 py-2 font-medium">
                        <a href="{{ route('corex.rental-fault-types.index', array_merge(request()->except('page'), ['sort' => 'name'])) }}">Name</a>
                    </th>
                    <th class="text-left px-4 py-2 font-medium">
                        <a href="{{ route('corex.rental-fault-types.index', array_merge(request()->except('page'), ['sort' => 'category'])) }}">Category</a>
                    </th>
                    <th class="text-left px-4 py-2 font-medium">
                        <a href="{{ route('corex.rental-fault-types.index', array_merge(request()->except('page'), ['sort' => 'urgency'])) }}">Urgency</a>
                    </th>
                    <th class="text-left px-4 py-2 font-medium">Default</th>
                    <th class="text-left px-4 py-2 font-medium">Documents</th>
                    <th class="text-right px-4 py-2 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($faultTypes as $ft)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-2">{{ $ft->name }}</td>
                        <td class="px-4 py-2">{{ $ft->category ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <span class="ds-badge {{ $ft->urgency === 'emergency' ? 'ds-badge-danger' : ($ft->urgency === 'urgent' ? 'ds-badge-warning' : 'ds-badge-muted') }}">{{ ucfirst($ft->urgency) }}</span>
                        </td>
                        <td class="px-4 py-2">{{ $ft->is_default ? 'CoreX default' : 'Custom' }}</td>
                        <td class="px-4 py-2">{{ $ft->documents->count() }}</td>
                        <td class="px-4 py-2 text-right space-x-2">
                            @permission('rental_fault_types.create')
                                @if($status === 'archived')
                                    <form method="POST" action="{{ route('corex.rental-fault-types.restore', $ft->id) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">Restore</button>
                                    </form>
                                @else
                                    <a href="{{ route('corex.rental-fault-types.edit', $ft) }}" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">Edit</a>
                                    <form method="POST" action="{{ route('corex.rental-fault-types.archive', $ft) }}" class="inline" onsubmit="return confirm('Archive this fault type?');">
                                        @csrf
                                        <button type="submit" class="text-xs" style="color: #991b1b;">Archive</button>
                                    </form>
                                @endif
                            @endpermission
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
                            @if(request('q') || request('category') || request('urgency'))
                                No fault types match this filter.
                            @else
                                No fault types yet — CoreX's defaults should already be listed here.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $faultTypes->links() }}
</div>
@endsection
