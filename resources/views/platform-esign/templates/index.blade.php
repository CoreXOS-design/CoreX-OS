{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — Platform E-Sign templates list (AT-447, spec §3A). --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $th = 'text-left px-5 py-2.5 text-xs font-semibold uppercase tracking-wider';
    $sortLink = function ($key, $label) use ($sort, $dir) {
        $next = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
        $arrow = $sort === $key ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
        return '<a href="' . e(request()->fullUrlWithQuery(['sort' => $key, 'dir' => $next])) . '">' . e($label) . $arrow . '</a>';
    };
@endphp
<div class="w-full space-y-5">
    @include('platform-esign._header', [
        'title' => 'Templates', 'tab' => 'templates',
        'sub' => 'The contracts CoreX can send. Type the wording, or upload a PDF and place the signature spots on it.',
        'actions' => '<a href="' . route('platform-esign.templates.create') . '" class="corex-btn-primary">+ Wording template</a><a href="' . route('platform-esign.templates.create', ['source' => 'pdf']) . '" class="corex-btn-outline">+ From a PDF</a>',
    ])

    <form method="GET" class="rounded-md p-3 flex flex-col lg:flex-row gap-2 lg:items-center" style="background: var(--surface); border: 1px solid var(--border);">
        <input type="text" name="q" value="{{ $q }}" placeholder="Search template name…" class="ds-field flex-1">
        <select name="kind" class="list-header-filter" onchange="this.form.submit()">
            <option value="">All types</option>
            @foreach(\App\Models\PlatformEsign\Template::KINDS as $k => $l)<option value="{{ $k }}" @selected(request('kind') === $k)>{{ $l }}</option>@endforeach
        </select>
        <select name="source" class="list-header-filter" onchange="this.form.submit()">
            <option value="">Wording + PDF</option>
            <option value="web" @selected(request('source') === 'web')>Wording</option>
            <option value="pdf" @selected(request('source') === 'pdf')>PDF</option>
        </select>
        <select name="state" class="list-header-filter" onchange="this.form.submit()">
            <option value="active" @selected($state === 'active')>Available</option>
            <option value="inactive" @selected($state === 'inactive')>Switched off</option>
            <option value="archived" @selected($state === 'archived')>Archived</option>
        </select>
        <button class="corex-btn-primary">Search</button>
        @if($q !== '' || request()->hasAny(['kind', 'source']) || $state !== 'active')<a href="{{ route('platform-esign.templates.index') }}" class="corex-btn-outline">Clear</a>@endif
    </form>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr style="background: var(--surface-2);">
                    <th class="{{ $th }}" style="color: var(--text-muted);">{!! $sortLink('name', 'Template') !!}</th>
                    <th class="{{ $th }}" style="color: var(--text-muted);">{!! $sortLink('kind', 'Type') !!}</th>
                    <th class="{{ $th }}" style="color: var(--text-muted);">Signers</th>
                    <th class="{{ $th }}" style="color: var(--text-muted);">{!! $sortLink('version', 'Version') !!}</th>
                    <th class="{{ $th }}" style="color: var(--text-muted);">{!! $sortLink('updated', 'Updated') !!}</th>
                    <th class="text-right px-5 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                </tr></thead>
                <tbody>
                @forelse($templates as $t)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-5 py-4">
                            <div class="font-semibold" style="color: var(--text-primary);">{{ $t->name }}</div>
                            <div class="flex gap-1 mt-1">
                                <span class="ds-badge ds-badge-info">{{ $t->isWebdoc() ? 'Web document' : ($t->isPdf() ? 'PDF · ' . $t->page_count . 'p · ' . $t->fields_count . ' fields' : 'Wording') }}</span>
                                @unless($t->is_active)<span class="ds-badge ds-badge-default">Switched off</span>@endunless
                                @if($t->trashed())<span class="ds-badge ds-badge-default">Archived</span>@endif
                            </div>
                        </td>
                        <td class="px-5 py-4" style="color: var(--text-secondary);">{{ \App\Models\PlatformEsign\Template::KINDS[$t->kind] ?? $t->kind }}</td>
                        <td class="px-5 py-4" style="color: var(--text-secondary);">{{ collect($t->roles())->pluck('label')->implode(' → ') }}</td>
                        <td class="px-5 py-4 tabular-nums" style="color: var(--text-secondary);">v{{ $t->version }}</td>
                        <td class="px-5 py-4 whitespace-nowrap" style="color: var(--text-muted);">{{ $t->updated_at?->format('j M Y') }}</td>
                        <td class="px-5 py-4">
                            <div class="flex items-center justify-end gap-1.5 whitespace-nowrap">
                            @if($t->trashed())
                                <form method="POST" action="{{ route('platform-esign.templates.restore', $t->id) }}">@csrf<button class="corex-btn-outline corex-btn-xs">Restore</button></form>
                            @else
                                @if($t->isWebdoc())
                                    <a href="{{ route('platform-esign.agreements.create') }}" class="corex-btn-primary corex-btn-xs">Send</a>
                                @else
                                <a href="{{ route('platform-esign.documents.create', ['template' => $t->id]) }}" class="corex-btn-primary corex-btn-xs">Send</a>
                                @endif
                                @if($t->isWebdoc())
                                @elseif($t->isPdf())<a href="{{ route('platform-esign.templates.fields', $t->id) }}" class="corex-btn-outline corex-btn-xs">Fields</a>
                                @else<a href="{{ route('platform-esign.templates.preview', $t->id) }}" class="corex-btn-outline corex-btn-xs">Preview</a>@endif
                                @unless($t->isWebdoc())<a href="{{ route('platform-esign.templates.edit', $t->id) }}" class="corex-btn-outline corex-btn-xs">Edit</a>@endunless
                                <form method="POST" action="{{ route('platform-esign.templates.destroy', $t->id) }}" onsubmit="return confirm('Archive this template? Documents already sent are unaffected. You can restore it.');">@csrf @method('DELETE')<button class="corex-btn-outline corex-btn-xs" style="color: var(--ds-crimson);">Archive</button></form>
                            @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-5 py-12 text-center text-sm" style="color: var(--text-muted);">No templates found. Create a wording template or upload a PDF to get started.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($templates->hasPages())<div class="px-5 py-3" style="border-top: 1px solid var(--border);">{{ $templates->links() }}</div>@endif
    </div>
</div>
@endsection
