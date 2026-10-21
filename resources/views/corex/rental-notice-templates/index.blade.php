@extends('layouts.corex')

{{--
    .ai/specs/rental-portal-access.md §8/§10 — AT-445. CRUD/list-screen
    floor: search (name), sort (name default; notice_type, created_at; either direction),
    filter (notice_type, active/archived, date added range), pagination, real empty state.
    AT-495: per row Download original (the uploaded Word document) and Use this template (opens the lease picker / the breach register).
--}}

@section('corex-content')
@php
    $sortLink = fn (string $col) => route('corex.rental-notice-templates.index', array_merge(request()->except('page'), ['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc']));
    $arrow = fn (string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
@endphp
<div class="w-full space-y-5">
    @include('corex.notices._tabs', ['active' => 'templates'])
    @include('corex.notices._lease-picker')
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
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Added from</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="list-header-filter text-sm {{ filled(request('date_from')) ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">to</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="list-header-filter text-sm {{ filled(request('date_to')) ? 'is-active' : '' }}">
        </div>
        <button type="submit" class="corex-btn-primary text-sm" style="height:2.25rem;">Filter</button>
        <a href="{{ route('corex.rental-notice-templates.index') }}" class="corex-btn-outline text-sm" style="height:2.25rem;">Reset</a>
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
                        <a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('name') }}">Name{{ $arrow('name') }}</a>
                    </th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">
                        <a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('notice_type') }}">Type{{ $arrow('notice_type') }}</a>
                    </th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Remedy days</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('created_at') }}">Added{{ $arrow('created_at') }}</a></th>
                    <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($templates as $template)
                    <tr style="border-top: 1px solid var(--border); color: var(--text-secondary);">
                        <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $template->name }}</td>
                        <td class="px-4 py-3">{{ \App\Models\RentalNoticeTemplate::labelFor($template->notice_type) }}</td>
                        <td class="px-4 py-3" data-qa="template-remedy-days-cell">{{ $template->remedy_days ? $template->remedy_days . ' days' : '—' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $template->created_at?->format('j M Y') }}</td>
                        <td class="px-4 py-3 text-right space-x-2 whitespace-nowrap">
                            @permission('rental_notice_templates.manage_settings')
                                @if($status === 'archived')
                                    @if($template->source_file_path)
                                        <a href="{{ route('corex.rental-notice-templates.download-original', $template->id) }}" class="text-xs" style="color: var(--brand-icon, #0ea5e9);" data-qa="template-download-original">Download original</a>
                                    @endif
                                    <form method="POST" action="{{ route('corex.rental-notice-templates.restore', $template->id) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">Restore</button>
                                    </form>
                                @else
                                    @if($template->notice_type === \App\Models\RentalNoticeTemplate::TYPE_NOTICE_TO_VACATE)
                                        {{-- a notice to vacate is issued from a breach whose remedy-by date has been reached --}}
                                        @permission('rental_breaches.view')
                                            <a href="{{ route('corex.breaches.index', ['tile' => 'reached']) }}" class="text-xs" style="color: var(--brand-icon, #0ea5e9);" data-qa="template-use" title="A notice to vacate is issued from a breach whose remedy-by date has been reached">Use this template</a>
                                        @endpermission
                                    @else
                                        @include('corex.notices._pick-lease-button', ['purpose' => 'breach', 'label' => 'Use this template', 'title' => 'Use "' . $template->name . '" - which lease?', 'template' => $template->id, 'class' => 'text-xs', 'qa' => 'template-use'])
                                    @endif
                                    @if($template->source_file_path)
                                        <a href="{{ route('corex.rental-notice-templates.download-original', $template->id) }}" class="text-xs" style="color: var(--brand-icon, #0ea5e9);" data-qa="template-download-original">Download original</a>
                                    @endif
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
                        <td colspan="6" class="px-6 py-12 text-center text-sm" style="color: var(--text-muted);" data-qa="templates-empty">
                            @if(request()->hasAny(['q', 'notice_type', 'date_from', 'date_to']) || $status === 'archived')
                                No templates match this filter.
                            @else
                                No notice templates yet — add your agency's breach notice and notice-to-vacate templates.
                                @permission('rental_notice_templates.manage_settings')
                                    <div class="mt-3"><a href="{{ route('corex.rental-notice-templates.create') }}" class="corex-btn-primary text-xs" data-qa="template-add-empty">+ Add Template</a></div>
                                @endpermission
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
