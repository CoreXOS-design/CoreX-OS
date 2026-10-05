{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — contract templates list (AT-447). --}}
@extends('layouts.corex')

@section('corex-content')
@php $sortLink = fn ($key, $label) => '<a href="' . e(request()->fullUrlWithQuery(['sort' => $key, 'dir' => ($sort === $key && $dir === 'asc') ? 'desc' : 'asc', 'page' => null])) . '" style="color:inherit;">' . e($label) . ($sort === $key ? ($dir === 'asc' ? ' ▲' : ' ▼') : '') . '</a>'; @endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <a href="{{ route('admin.agency-contracts.index') }}" class="text-xs underline" style="color:var(--text-muted);">← Agency Contracts</a>
                <h1 class="text-base font-bold leading-tight mt-1" style="color: var(--text-primary);">Contract templates</h1>
                <p class="text-xs" style="color: var(--text-muted);">The wording of CoreX's contracts. Editing a template never changes a contract already sent.</p>
            </div>
            <a href="{{ route('admin.agency-contracts.templates.create') }}" class="corex-btn-primary text-xs">+ New template</a>
        </div>
    </div>
    @include('admin.partials.platform-flash')

    <form method="GET" class="flex flex-wrap items-end gap-3 rounded-md p-3" style="background: var(--surface); border:1px solid var(--border);">
        <div><label class="ds-label block mb-1">Search name</label><input type="text" name="q" value="{{ request('q') }}" class="ds-field"></div>
        <div><label class="ds-label block mb-1">Type</label><select name="kind" class="ds-field"><option value="">All</option>@foreach(\App\Models\Platform\PlatformContractTemplate::KINDS as $k => $l)<option value="{{ $k }}" @selected(request('kind') === $k)>{{ $l }}</option>@endforeach</select></div>
        <div><label class="ds-label block mb-1">Active</label><select name="active" class="ds-field"><option value="">Any</option><option value="1" @selected(request('active') === '1')>Active</option><option value="0" @selected(request('active') === '0')>Inactive</option></select></div>
        <label class="flex items-center gap-2 text-xs pb-2"><input type="checkbox" name="archived" value="1" @checked(request()->boolean('archived'))> Show archived</label>
        <input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="dir" value="{{ $dir }}">
        <button class="corex-btn-primary text-xs">Filter</button>
        @if(request()->hasAny(['q','kind','active','archived']))<a href="{{ route('admin.agency-contracts.templates') }}" class="text-xs underline" style="color:var(--text-muted);">Clear</a>@endif
    </form>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border:1px solid var(--border);">
        <table class="w-full text-sm ds-table">
            <thead><tr style="background: var(--surface-2); color:var(--text-muted);" class="text-left text-xs uppercase tracking-wider">
                <th class="px-4 py-3">{!! $sortLink('name', 'Name') !!}</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Version</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">{!! $sortLink('updated', 'Updated') !!}</th><th class="px-4 py-3 text-right">Actions</th></tr></thead>
            <tbody>
            @forelse($templates as $t)
                <tr style="border-top:1px solid var(--border);">
                    <td class="px-4 py-3 font-medium" style="color:var(--text-primary);">{{ $t->name }}</td>
                    <td class="px-4 py-3" style="color:var(--text-secondary);">{{ \App\Models\Platform\PlatformContractTemplate::KINDS[$t->kind] ?? $t->kind }}</td>
                    <td class="px-4 py-3" style="color:var(--text-secondary);">v{{ $t->version }}</td>
                    <td class="px-4 py-3"><span class="ds-badge {{ $t->deleted_at ? 'ds-badge-default' : ($t->is_active ? 'ds-badge-success' : 'ds-badge-warning') }}">{{ $t->deleted_at ? 'Archived' : ($t->is_active ? 'Active' : 'Inactive') }}</span></td>
                    <td class="px-4 py-3 tabular-nums" style="color:var(--text-muted);">{{ $t->updated_at->format('j M Y') }}</td>
                    <td class="px-4 py-3 text-right whitespace-nowrap text-xs">
                        <a href="{{ route('admin.agency-contracts.templates.edit', $t->id) }}" class="font-semibold" style="color:var(--brand-icon);">Edit</a>
                        <a href="{{ route('admin.agency-contracts.templates.preview', $t->id) }}" class="font-semibold ml-3" style="color:var(--brand-icon);">Preview</a>
                        @if($t->deleted_at)
                            <form method="POST" action="{{ route('admin.agency-contracts.templates.restore', $t->id) }}" class="inline">@csrf<button class="font-semibold ml-3" style="color:var(--ds-green);">Restore</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-10 text-center" style="color:var(--text-muted);">No templates {{ request()->hasAny(['q','kind','active','archived']) ? 'match these filters' : 'yet' }}. <a class="underline" href="{{ route('admin.agency-contracts.templates.create') }}">Create the first one</a>.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $templates->links() }}
</div>
@endsection
