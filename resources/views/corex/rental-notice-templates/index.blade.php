@extends('layouts.corex')

{{--
    .ai/specs/rental-portal-access.md §8/§10 — AT-445. CRUD/list-screen
    floor: search (name), sort (name default; notice_type, created_at),
    filter (notice_type, active/archived), pagination, real empty state.
--}}

@section('content')
<div class="p-6 space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Rental Notice Templates</h1>
        @permission('rental_notice_templates.manage_settings')
        <a href="{{ route('corex.rental-notice-templates.create') }}" class="corex-btn-primary text-xs">+ Add Template</a>
        @endpermission
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46;">{{ session('success') }}</div>
    @endif

    <form method="GET" action="{{ route('corex.rental-notice-templates.index') }}" class="flex flex-wrap items-end gap-3">
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Search</label><br>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Name" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
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
                        <a href="{{ route('corex.rental-notice-templates.index', array_merge(request()->except('page'), ['sort' => 'name'])) }}">Name</a>
                    </th>
                    <th class="text-left px-4 py-2 font-medium">
                        <a href="{{ route('corex.rental-notice-templates.index', array_merge(request()->except('page'), ['sort' => 'notice_type'])) }}">Type</a>
                    </th>
                    <th class="text-right px-4 py-2 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($templates as $template)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-2">{{ $template->name }}</td>
                        <td class="px-4 py-2">{{ ucfirst(str_replace('_', ' ', $template->notice_type)) }}</td>
                        <td class="px-4 py-2 text-right space-x-2">
                            @permission('rental_notice_templates.manage_settings')
                                @if($status === 'archived')
                                    <form method="POST" action="{{ route('corex.rental-notice-templates.restore', $template->id) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">Restore</button>
                                    </form>
                                @else
                                    <a href="{{ route('corex.rental-notice-templates.edit', $template) }}" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">Edit</a>
                                    <form method="POST" action="{{ route('corex.rental-notice-templates.archive', $template) }}" class="inline" onsubmit="return confirm('Archive this template?');">
                                        @csrf
                                        <button type="submit" class="text-xs" style="color: #991b1b;">Archive</button>
                                    </form>
                                @endif
                            @endpermission
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
                            @if(request('q') || request('notice_type'))
                                No templates match this filter.
                            @else
                                No notice templates yet — add your agency's breach notice and notice-to-vacate templates.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $templates->links() }}
</div>
@endsection
