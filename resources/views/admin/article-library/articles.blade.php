{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — System Developer → Article Library → Articles (AT-456 Part C). --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $f = $filters;
    $filtersActive = $f['q'] !== '' || $f['status'] !== 'all' || $f['source'] > 0 || $f['from'] || $f['to'];
    $input = 'background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);';
@endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Article Library — articles</h1>
                <p class="text-sm text-white/60">Everything the library has written. Hide an article and it disappears from every agency's Article Library (agencies that already used it keep their own copy).</p>
            </div>
        </div>
    </div>

    @include('admin.partials.platform-flash')
    <div>@include('admin.article-library._tabs')</div>

    <form method="GET" action="{{ route('admin.article-library.articles') }}"
          class="rounded-md p-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-6 items-end" style="background:var(--surface); border:1px solid var(--border);">
        <input type="hidden" name="sort" value="{{ $f['sort'] }}">
        <input type="hidden" name="direction" value="{{ $f['direction'] }}">
        <label class="lg:col-span-2 block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Search</span>
            <input class="list-header-filter w-full {{ !empty($f['q']) ? 'is-active' : '' }}" type="search" name="q" value="{{ $f['q'] }}" placeholder="Title or source…">
        </label>
        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Status</span>
            <select class="list-header-filter w-full {{ $f['status'] !== 'all' ? 'is-active' : '' }}" name="status">
                <option value="all"    @selected($f['status'] === 'all')>All statuses</option>
                <option value="ready"  @selected($f['status'] === 'ready')>Ready</option>
                <option value="hidden" @selected($f['status'] === 'hidden')>Hidden</option>
            </select>
        </label>
        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Source</span>
            <select class="list-header-filter w-full {{ !empty($f['source']) ? 'is-active' : '' }}" name="source">
                <option value="0">All sources</option>
                @foreach($sources as $s)
                    <option value="{{ $s->id }}" @selected($f['source'] === $s->id)>{{ $s->name }}{{ $s->deleted_at ? ' (archived)' : '' }}</option>
                @endforeach
            </select>
        </label>
        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">From</span>
            <input class="list-header-filter w-full {{ !empty($f['from']) ? 'is-active' : '' }}" type="date" name="from" value="{{ $f['from'] }}">
        </label>
        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">To</span>
            <input class="list-header-filter w-full {{ !empty($f['to']) ? 'is-active' : '' }}" type="date" name="to" value="{{ $f['to'] }}">
        </label>
        <div class="sm:col-span-2 lg:col-span-6 flex items-center gap-2">
            <button type="submit" class="corex-btn-primary text-sm px-4 py-2">Apply</button>
            @if($filtersActive)<a href="{{ route('admin.article-library.articles') }}" class="corex-btn-outline text-sm">Clear filters</a>@endif
            <span class="text-xs ml-auto" style="color:var(--text-muted);">{{ $articles->total() }} article{{ $articles->total() === 1 ? '' : 's' }}</span>
        </div>
    </form>

    @if($articles->isEmpty())
        <div class="rounded-md py-12 px-6 text-center" style="background:var(--surface); border:1px solid var(--border);">
            @if(! $hasAny)
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">The library is empty</h3>
                <p class="text-sm mb-4" style="color:var(--text-muted);">Nothing has been written yet. Press Run now on the Sources tab to fetch the sources and write the first briefs.</p>
                <a href="{{ route('admin.article-library.index') }}" class="corex-btn-outline text-sm">Go to Sources</a>
            @else
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No articles match these filters</h3>
                <p class="text-sm mb-4" style="color:var(--text-muted);">Try a different search, or clear the filters.</p>
                <a href="{{ route('admin.article-library.articles') }}" class="corex-btn-outline text-sm">Clear filters</a>
            @endif
        </div>
    @else
        <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background:var(--surface-2);">
                        <x-sort-header field="title" label="Article" :current-sort="$f['sort']" :current-direction="$f['direction']" />
                        <x-sort-header field="source" label="Source" :current-sort="$f['sort']" :current-direction="$f['direction']" />
                        <x-sort-header field="date" label="Date" :current-sort="$f['sort']" :current-direction="$f['direction']" />
                        <x-sort-header field="status" label="Status" :current-sort="$f['sort']" :current-direction="$f['direction']" />
                        <x-sort-header field="used" label="Times used" align="right" :current-sort="$f['sort']" :current-direction="$f['direction']" />
                        <x-sort-header field="cost" label="AI cost" align="right" :current-sort="$f['sort']" :current-direction="$f['direction']" />
                        <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                @foreach($articles as $a)
                    <tbody x-data="{ open: false }" style="border-top:1px solid var(--border); {{ $a->status === 'hidden' ? 'opacity:.7;' : '' }}">
                        <tr>
                            <td class="px-4 py-3 align-top max-w-md">
                                <div class="font-semibold" style="color:var(--text-primary);">{{ $a->title }}</div>
                                @if($a->excerpt)<div class="text-xs mt-0.5" style="color:var(--text-muted);">{{ \Illuminate\Support\Str::limit($a->excerpt, 160) }}</div>@endif
                                @if($a->tagList())
                                    <div class="flex flex-wrap gap-1 mt-1">@foreach($a->tagList() as $t)<span class="ds-badge ds-badge-muted ds-badge-pill">{{ $t }}</span>@endforeach</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 align-top" style="color:var(--text-secondary);">
                                {{ $a->source_name }}
                                <div><a href="{{ $a->source_url }}" target="_blank" rel="noopener noreferrer nofollow" class="text-xs" style="color:var(--brand-icon,#0ea5e9);">Read original ↗</a></div>
                            </td>
                            <td class="px-4 py-3 align-top whitespace-nowrap" style="color:var(--text-secondary);">{{ ($a->source_published_at ?? $a->generated_at ?? $a->created_at)?->format('j M Y') }}</td>
                            <td class="px-4 py-3 align-top">
                                <span class="ds-badge {{ $a->status === 'ready' ? 'ds-badge-success' : 'ds-badge-default' }}">{{ $a->status === 'ready' ? 'Ready' : 'Hidden' }}</span>
                            </td>
                            <td class="px-4 py-3 align-top text-right font-mono" style="color:var(--text-primary);">{{ number_format((int) $a->used_count) }}</td>
                            <td class="px-4 py-3 align-top text-right font-mono" style="color:var(--text-secondary);">R {{ number_format((float) $a->cost_zar, 2) }}</td>
                            <td class="px-4 py-3 align-top">
                                <div class="flex items-center justify-end gap-1.5 whitespace-nowrap">
                                    <button type="button" class="corex-btn-outline corex-btn-xs" @click="open = !open" x-text="open ? 'Close' : 'Read'">Read</button>
                                    @if($a->status === 'ready')
                                        <form method="POST" action="{{ route('admin.article-library.articles.hide', $a->id) }}" onsubmit="return confirm('Hide this article from every agency?');">@csrf<button type="submit" class="corex-btn-outline corex-btn-xs">Hide</button></form>
                                    @else
                                        <form method="POST" action="{{ route('admin.article-library.articles.unhide', $a->id) }}">@csrf<button type="submit" class="corex-btn-outline corex-btn-xs">Unhide</button></form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        <tr x-show="open" x-cloak>
                            <td colspan="7" class="px-5 py-4 text-sm whitespace-pre-line" style="background:var(--surface-2); color:var(--text-secondary);">{{ $a->body }}</td>
                        </tr>
                    </tbody>
                @endforeach
            </table>
        </div>
        <div>{{ $articles->links() }}</div>
    @endif
</div>
@endsection
