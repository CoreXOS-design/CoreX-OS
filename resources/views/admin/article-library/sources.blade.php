{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — Dev Settings → Article Library → Sources (AT-456 Part C). --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $f = $filters;
    $filtersActive = $f['q'] !== '' || $f['status'] !== 'active' || $f['from'] || $f['to'];
    $input = 'background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);';
    $scheduleLabel = \App\Services\Articles\ArticleLibraryService::SCHEDULES[$settings['schedule']] ?? $settings['schedule'];
@endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Article Library</h1>
                <p class="text-sm text-white/60">The news links CoreX reads. The AI keeps what matters to South African estate agents and writes a short, original brief with credit and a link back. Agencies find the briefs under Ad Manager → Article Library — nothing is ever published for them.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.dev-settings.index') }}" class="corex-btn-outline">← Dev Settings</a>
                <form method="POST" action="{{ route('admin.article-library.run') }}" x-data="{ busy: false }" @submit="busy = true"
                      onsubmit="return confirm('Run the Article Library now? It reads every active source and uses platform AI (usually a few rand). This can take a minute or two — keep this page open.');">
                    @csrf
                    <button type="submit" class="corex-btn-primary" :disabled="busy" x-text="busy ? 'Running… please wait' : 'Run now'">Run now</button>
                </form>
            </div>
        </div>
    </div>

    @include('admin.partials.platform-flash')
    @if(session('run_errors'))
        <div class="rounded-md px-4 py-3 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-secondary);">
            <div class="font-semibold mb-1" style="color:var(--text-primary);">What went wrong</div>
            <ul class="list-disc pl-5 space-y-0.5">@foreach(session('run_errors') as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        @include('admin.article-library._tabs')
        <p class="text-xs" style="color:var(--text-muted);">
            Refreshes: <strong style="color:var(--text-secondary);">{{ $scheduleLabel }}</strong>
            · up to {{ $settings['max_per_run'] }} new articles per run
            · last run {{ $settings['last_run_at'] ? $settings['last_run_at']->format('j M Y H:i') : 'never' }}
            · <a href="{{ route('admin.article-library.settings') }}" style="color:var(--brand-icon,#0ea5e9);">change</a>
        </p>
    </div>

    {{-- Add a source --}}
    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);" x-data="{ adding: {{ old('_form') === 'add' ? 'true' : 'false' }} }">
        <div class="px-5 py-4 flex items-center justify-between gap-3">
            <div>
                <div class="ds-section-header">Sources</div>
                <div class="ds-section-sub">Each link is read when the library runs. Archiving a source stops it being read and hides its articles from agencies.</div>
            </div>
            <button type="button" class="corex-btn-primary" @click="adding = !adding" x-text="adding ? 'Cancel' : '+ Add source'">+ Add source</button>
        </div>
        <div x-show="adding" x-cloak class="px-5 py-5" style="background:var(--surface-2); border-top:1px solid var(--border);">
            @include('admin.article-library._source-form', ['source' => null])
        </div>
    </div>

    {{-- Search + filters --}}
    <form method="GET" action="{{ route('admin.article-library.index') }}"
          class="rounded-md p-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5 items-end" style="background:var(--surface); border:1px solid var(--border);">
        <input type="hidden" name="sort" value="{{ $f['sort'] }}">
        <input type="hidden" name="direction" value="{{ $f['direction'] }}">
        <label class="lg:col-span-2 block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Search</span>
            <input class="list-header-filter w-full {{ !empty($f['q']) ? 'is-active' : '' }}" type="search" name="q" value="{{ $f['q'] }}" placeholder="Name or link…">
        </label>
        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Show</span>
            <select class="list-header-filter w-full {{ $f['status'] !== 'active' ? 'is-active' : '' }}" name="status">
                <option value="active"   @selected($f['status'] === 'active')>Active</option>
                <option value="archived" @selected($f['status'] === 'archived')>Archived</option>
                <option value="all"      @selected($f['status'] === 'all')>All</option>
            </select>
        </label>
        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Last read from</span>
            <input class="list-header-filter w-full {{ !empty($f['from']) ? 'is-active' : '' }}" type="date" name="from" value="{{ $f['from'] }}">
        </label>
        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Last read to</span>
            <input class="list-header-filter w-full {{ !empty($f['to']) ? 'is-active' : '' }}" type="date" name="to" value="{{ $f['to'] }}">
        </label>
        <div class="sm:col-span-2 lg:col-span-5 flex items-center gap-2">
            <button type="submit" class="corex-btn-primary text-sm px-4 py-2">Apply</button>
            @if($filtersActive)<a href="{{ route('admin.article-library.index') }}" class="corex-btn-outline text-sm">Clear filters</a>@endif
            <span class="text-xs ml-auto" style="color:var(--text-muted);">{{ $sources->total() }} source{{ $sources->total() === 1 ? '' : 's' }}</span>
        </div>
    </form>

    @if($sources->isEmpty())
        <div class="rounded-md py-12 px-6 text-center" style="background:var(--surface); border:1px solid var(--border);">
            @if(! $hasAny)
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No sources yet</h3>
                <p class="text-sm" style="color:var(--text-muted);">Use “+ Add source” above to paste the first news feed link.</p>
            @elseif($filtersActive)
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">{{ $f['status'] === 'archived' && $f['q'] === '' && ! $f['from'] && ! $f['to'] ? 'No archived sources' : 'No sources match these filters' }}</h3>
                <p class="text-sm mb-4" style="color:var(--text-muted);">Try a different search, or clear the filters.</p>
                <a href="{{ route('admin.article-library.index') }}" class="corex-btn-outline text-sm">Clear filters</a>
            @else
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No active sources</h3>
                <p class="text-sm mb-4" style="color:var(--text-muted);">Every source is archived. Show Archived to restore one.</p>
                <a href="{{ route('admin.article-library.index', ['status' => 'archived']) }}" class="corex-btn-outline text-sm">View archived</a>
            @endif
        </div>
    @else
        <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background:var(--surface-2);">
                        <x-sort-header field="name" label="Source" :current-sort="$f['sort']" :current-direction="$f['direction']" />
                        <x-sort-header field="last_run" label="Last read" :current-sort="$f['sort']" :current-direction="$f['direction']" />
                        <x-sort-header field="status" label="Result" :current-sort="$f['sort']" :current-direction="$f['direction']" />
                        <x-sort-header field="articles" label="Articles" align="right" :current-sort="$f['sort']" :current-direction="$f['direction']" />
                        <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                @foreach($sources as $s)
                    <tbody x-data="{ edit: {{ old('_form') === 'edit-' . $s->id ? 'true' : 'false' }} }" style="border-top:1px solid var(--border); {{ $s->trashed() ? 'opacity:.7;' : '' }}">
                        <tr>
                            <td class="px-4 py-3 align-top">
                                <div class="font-semibold" style="color:var(--text-primary);">
                                    {{ $s->name }}
                                    @if($s->trashed())<span class="ds-badge ds-badge-muted ml-1">Archived</span>
                                    @elseif(! $s->is_active)<span class="ds-badge ds-badge-default ml-1">Paused</span>@endif
                                </div>
                                <a href="{{ $s->url }}" target="_blank" rel="noopener noreferrer" class="text-xs break-all" style="color:var(--text-muted);">{{ \Illuminate\Support\Str::limit($s->url, 70) }}</a>
                            </td>
                            <td class="px-4 py-3 align-top whitespace-nowrap" style="color:var(--text-secondary);">
                                {{ $s->last_run_at ? $s->last_run_at->format('j M Y H:i') : 'Never' }}
                            </td>
                            <td class="px-4 py-3 align-top">
                                @if($s->last_status === 'ok')
                                    <span class="ds-badge ds-badge-success">Read OK</span>
                                    @if($s->last_new_articles !== null)<div class="text-xs mt-1" style="color:var(--text-muted);">{{ $s->last_new_articles }} new article{{ $s->last_new_articles === 1 ? '' : 's' }} last run</div>@endif
                                @elseif($s->last_status === 'failed')
                                    <span class="ds-badge ds-badge-danger">Could not read</span>
                                    @if($s->last_error)<div class="text-xs mt-1 max-w-xs" style="color:var(--text-muted);">{{ \Illuminate\Support\Str::limit($s->last_error, 160) }}</div>@endif
                                @else
                                    <span class="ds-badge ds-badge-muted">Not read yet</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 align-top text-right font-mono" style="color:var(--text-primary);">{{ number_format($s->articles_count) }}</td>
                            <td class="px-4 py-3 align-top">
                                <div class="flex items-center justify-end gap-1.5 whitespace-nowrap">
                                    @if($s->trashed())
                                        <form method="POST" action="{{ route('admin.article-library.sources.restore', $s->id) }}">@csrf<button type="submit" class="corex-btn-outline corex-btn-xs">Restore</button></form>
                                    @else
                                        @if($s->is_active)
                                            <form method="POST" action="{{ route('admin.article-library.sources.run', $s->id) }}" x-data="{ busy: false }" @submit="busy = true"
                                                  onsubmit="return confirm('Run the library now for this source only? It uses platform AI and can take a minute or two.');">
                                                @csrf<button type="submit" class="corex-btn-outline corex-btn-xs" :disabled="busy" x-text="busy ? 'Running…' : 'Run now'">Run now</button>
                                            </form>
                                        @endif
                                        <button type="button" class="corex-btn-outline corex-btn-xs" @click="edit = !edit" x-text="edit ? 'Close' : 'Edit'">Edit</button>
                                        <form method="POST" action="{{ route('admin.article-library.sources.archive', $s->id) }}"
                                              onsubmit="return confirm(@js('Archive “' . $s->name . '”? It stops being read and its articles disappear from every agency. You can restore it.'));">
                                            @csrf @method('DELETE')<button type="submit" class="corex-btn-outline corex-btn-xs" style="color:var(--ds-crimson);">Archive</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @unless($s->trashed())
                            <tr x-show="edit" x-cloak>
                                <td colspan="5" class="px-5 py-5" style="background:var(--surface-2);">@include('admin.article-library._source-form', ['source' => $s])</td>
                            </tr>
                        @endunless
                    </tbody>
                @endforeach
            </table>
        </div>
        <div>{{ $sources->links() }}</div>
    @endif
</div>
@endsection
