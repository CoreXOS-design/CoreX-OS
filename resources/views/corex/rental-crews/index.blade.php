@extends('layouts.corex')

{{--
    2026-10-05 — Johan's ruling: "Agents and staff are never maintenance
    crew. Crew are people with NO CoreX access, set up by the agency
    admin, pickable on job cards." CRUD/list-screen floor (BUILD_STANDARD
    §1a-§1d): search (name), sort (name default, created_at), filter
    (active/archived), pagination, real empty state, agency scoping via
    BelongsToAgency + AgencyScope on the model.
--}}

@section('content')
<div class="p-6 space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Rental Crews</h1>
        @permission('rental_catalogue.manage')
        <a href="{{ route('corex.rental-crews.create') }}" class="corex-btn-primary text-xs">+ Add Crew</a>
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

    <form method="GET" action="{{ route('corex.rental-crews.index') }}" class="flex flex-wrap items-end gap-3">
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Search</label><br>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Crew name" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
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
                    <th class="text-left px-4 py-2 font-medium"><a href="{{ route('corex.rental-crews.index', array_merge(request()->except('page'), ['sort' => 'name', 'direction' => $sort === 'name' && $direction === 'asc' ? 'desc' : 'asc'])) }}">Name</a></th>
                    <th class="text-left px-4 py-2 font-medium">Members</th>
                    <th class="text-left px-4 py-2 font-medium">Notes</th>
                    <th class="text-right px-4 py-2 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($crews as $crew)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-2">{{ $crew->name }}</td>
                        <td class="px-4 py-2">{{ $crew->members_count }}</td>
                        <td class="px-4 py-2" style="color: var(--text-muted);">{{ \Illuminate\Support\Str::limit($crew->notes, 60) ?: '—' }}</td>
                        <td class="px-4 py-2 text-right space-x-2">
                            @permission('rental_catalogue.manage')
                                @if($status === 'archived')
                                    <form method="POST" action="{{ route('corex.rental-crews.restore', $crew->id) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">Restore</button>
                                    </form>
                                @else
                                    <a href="{{ route('corex.rental-crews.edit', $crew) }}" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">Edit</a>
                                    <form method="POST" action="{{ route('corex.rental-crews.archive', $crew) }}" class="inline" onsubmit="return confirm('Archive this crew? It stays visible on job cards it is already assigned to, but can no longer be newly picked.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs" style="color: #991b1b;">Archive</button>
                                    </form>
                                @endif
                            @endpermission
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
                            @if(request('q'))
                                No crews match this filter.
                            @elseif($status === 'archived')
                                No archived crews.
                            @else
                                No crews yet — add the first team your job cards will assign work to.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    {{ $crews->links() }}
</div>
@endsection
