@extends('layouts.corex')

{{--
    .ai/specs/rental-portal-access.md §8/§10 — AT-445. CRUD/list-screen
    floor: search (name), sort (name default; notice_type, created_at),
    filter (notice_type, active/archived), pagination, real empty state.
--}}

@section('corex-content')
<div class="w-full space-y-5">
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Rental Notice Templates</h1>
                <p class="text-xs" style="color: var(--text-muted);">The wording of the notices your agency sends: breach notices, notices to vacate and more.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @permission('rental_notice_templates.manage_settings')
                <a href="{{ route('corex.rental-notice-templates.create') }}" class="corex-btn-primary text-xs">+ Add Template</a>
                @endpermission
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif

    <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
    <form method="GET" action="{{ route('corex.rental-notice-templates.index') }}" class="flex flex-wrap items-end gap-3">
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Name" class="list-header-filter text-sm {{ filled(request('q')) ? 'is-active' : '' }}">
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
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Status</label>
            <select name="status" class="list-header-filter text-sm {{ $status !== 'active' ? 'is-active' : '' }}">
                <option value="active" @selected($status === 'active')>Active</option>
                <option value="archived" @selected($status === 'archived')>Archived</option>
            </select>
        </div>
        <button type="submit" class="corex-btn-primary text-sm" style="height:2.25rem;">Filter</button>
            <div class="flex-1"></div>
        <span class="ds-badge ds-badge-pill ds-badge-info">
            {{ number_format($templates->total()) }} {{ \Illuminate\Support\Str::plural('template', $templates->total()) }}
        </span>
    </form>
    </div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2); color: var(--text-muted); border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">
                        <a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ route('corex.rental-notice-templates.index', array_merge(request()->except('page'), ['sort' => 'name'])) }}">Name</a>
                    </th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">
                        <a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ route('corex.rental-notice-templates.index', array_merge(request()->except('page'), ['sort' => 'notice_type'])) }}">Type</a>
                    </th>
                    <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($templates as $template)
                    <tr style="border-top: 1px solid var(--border); color: var(--text-secondary);">
                        <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $template->name }}</td>
                        <td class="px-4 py-3">{{ \App\Models\RentalNoticeTemplate::labelFor($template->notice_type) }}</td>
                        <td class="px-4 py-3 text-right space-x-2">
                            @permission('rental_notice_templates.manage_settings')
                                @if($status === 'archived')
                                    <form method="POST" action="{{ route('corex.rental-notice-templates.restore', $template->id) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">Restore</button>
                                    </form>
                                @else
                                    <a href="{{ route('corex.rental-notice-templates.edit', $template) }}" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">Edit</a>
                                    <form method="POST" action="{{ route('corex.rental-notice-templates.archive', $template) }}" class="inline" data-confirm="Archive this template?" data-confirm-danger data-confirm-label="Archive">
                                        @csrf
                                        <button type="submit" class="text-xs" style="color: #991b1b;">Archive</button>
                                    </form>
                                @endif
                            @endpermission
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-6 py-12 text-center text-sm" style="color: var(--text-muted);">
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
        @if($templates->hasPages())
        <div class="px-5 py-4" style="border-top:1px solid var(--border);">
            {{ $templates->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
