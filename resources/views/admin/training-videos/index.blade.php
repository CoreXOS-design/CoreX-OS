{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Training Videos list. Owner-only.
    Spec: .ai/specs/training-videos.md §4.1
--}}
@extends('layouts.corex')

@section('title', 'Training Videos')

@section('corex-content')
@php
    $sortLink = function (string $key, string $label) use ($sort, $dir) {
        $nextDir = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
        $arrow   = $sort === $key ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
        $url     = request()->fullUrlWithQuery(['sort' => $key, 'dir' => $nextDir, 'page' => null]);
        return '<a href="' . e($url) . '" class="hover:underline">' . e($label) . $arrow . '</a>';
    };
    $th = 'text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider';
    $field = 'rounded-md px-3 py-2 text-sm';
    $fieldStyle = 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);';
@endphp
<div class="w-full space-y-5">

    {{-- Page header — §2.4 Pattern A --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Training Videos</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    Videos published on the public Training Videos page — YouTube links and files you upload. Anyone can watch them — no login needed.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('public.training-videos') }}" target="_blank" rel="noopener" class="corex-btn-outline corex-btn-on-brand text-xs">
                    View public page ↗
                </a>
                <a href="{{ route('admin.training-videos.groups.index') }}" class="corex-btn-outline corex-btn-on-brand text-xs">
                    Groups
                </a>
                <a href="{{ route('admin.training-videos.create') }}" class="corex-btn-primary text-xs">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                    Add video
                </a>
            </div>
        </div>
    </div>

    @if (session('status'))
        <div role="status" class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-emerald) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-emerald) 30%, transparent);
                    color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->has('retry'))
        <div role="alert" class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            {{ $errors->first('retry') }}
        </div>
    @endif

    @if (! $hasAny)
        {{-- Empty state — nothing has ever been added. --}}
        <div class="rounded-md px-6 py-12 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <p class="text-sm font-medium" style="color: var(--text-primary);">No training videos yet</p>
            <p class="mt-1 text-xs max-w-md mx-auto" style="color: var(--text-muted);">
                Paste a YouTube link with a title and description, and it appears on the public
                Training Videos page straight away. Create groups first if you want the page split into sections.
            </p>
            <a href="{{ route('admin.training-videos.create') }}" class="corex-btn-primary text-xs mt-4 inline-flex">
                Add your first video
            </a>
        </div>
    @else
        {{-- Filters --}}
        <form method="GET" action="{{ route('admin.training-videos.index') }}"
              class="rounded-md p-4 flex flex-wrap items-end gap-3" style="background: var(--surface); border: 1px solid var(--border);">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="dir" value="{{ $dir }}">
            <div class="flex-1 min-w-[200px]">
                <label for="q" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
                <input id="q" name="q" type="search" value="{{ $q }}" placeholder="Title, description or group"
                       class="w-full {{ $field }}" style="{{ $fieldStyle }}">
            </div>
            <div>
                <label for="status" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Status</label>
                <select id="status" name="status" class="{{ $field }}" style="{{ $fieldStyle }}">
                    <option value="active" @selected($status === 'active')>Active</option>
                    <option value="archived" @selected($status === 'archived')>Archived</option>
                    <option value="all" @selected($status === 'all')>All</option>
                </select>
            </div>
            <div>
                <label for="source" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Kind</label>
                <select id="source" name="source" class="{{ $field }}" style="{{ $fieldStyle }}">
                    <option value="">All</option>
                    <option value="youtube" @selected($source === 'youtube')>YouTube</option>
                    <option value="upload" @selected($source === 'upload')>Uploaded</option>
                </select>
            </div>
            <div>
                <label for="group" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Group</label>
                <select id="group" name="group" class="{{ $field }}" style="{{ $fieldStyle }}">
                    <option value="">All groups</option>
                    <option value="none" @selected($group === 'none')>No group</option>
                    @foreach ($groups as $g)
                        <option value="{{ $g->id }}" @selected($group === (string) $g->id)>{{ $g->name }}{{ $g->trashed() ? ' (archived)' : '' }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="from" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Added from</label>
                <input id="from" name="from" type="date" value="{{ request('from') }}" class="{{ $field }}" style="{{ $fieldStyle }}">
            </div>
            <div>
                <label for="to" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Added to</label>
                <input id="to" name="to" type="date" value="{{ request('to') }}" class="{{ $field }}" style="{{ $fieldStyle }}">
            </div>
            <div class="flex items-center gap-2">
                <button type="submit" class="corex-btn-primary text-xs">Apply</button>
                @if ($filtered)
                    <a href="{{ route('admin.training-videos.index') }}" class="corex-btn-outline text-xs">Clear</a>
                @endif
            </div>
        </form>

        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            @if ($videos->isEmpty())
                <div class="px-6 py-12 text-center">
                    <p class="text-sm font-medium" style="color: var(--text-primary);">No videos match these filters</p>
                    <a href="{{ route('admin.training-videos.index') }}" class="text-xs mt-2 inline-block hover:underline" style="color: var(--corex-accent);">Clear filters</a>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm ds-table">
                        <thead>
                            <tr style="background: var(--surface-2); color: var(--text-muted);">
                                <th class="{{ $th }}" style="width: 136px;"></th>
                                <th class="{{ $th }}">{!! $sortLink('title', 'Video') !!}</th>
                                <th class="{{ $th }}">{!! $sortLink('group', 'Group') !!}</th>
                                <th class="{{ $th }}">{!! $sortLink('created', 'Added') !!}</th>
                                <th class="{{ $th }}">{!! $sortLink('updated', 'Updated') !!}</th>
                                <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wider"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($videos as $video)
                                <tr style="border-bottom: 1px solid var(--border);">
                                    <td class="px-4 py-3">
                                        @if ($video->isUpload())
                                            <a href="{{ route('admin.training-videos.edit', $video) }}">
                                                @if ($video->file_name && ! $video->trashed())
                                                    <img src="{{ $video->posterUrl() }}" alt="" loading="lazy" class="rounded-md" style="width: 112px; aspect-ratio: 16/9; object-fit: cover; background: var(--surface-2);">
                                                @else
                                                    <div class="rounded-md" style="width: 112px; aspect-ratio: 16/9; background: var(--surface-2);"></div>
                                                @endif
                                            </a>
                                        @else
                                            <a href="{{ $video->watchUrl() }}" target="_blank" rel="noopener">
                                                <img src="{{ $video->thumbnailUrl() }}" alt="" loading="lazy" class="rounded-md" style="width: 112px; aspect-ratio: 16/9; object-fit: cover; background: var(--surface-2);">
                                            </a>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('admin.training-videos.edit', $video) }}" class="font-medium hover:underline" style="color: var(--corex-accent);">
                                            {{ $video->title }}
                                        </a>
                                        @if ($video->description)
                                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ \Illuminate\Support\Str::limit($video->description, 110) }}</div>
                                        @endif
                                        @if ($video->isUpload())
                                            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs" style="color: var(--text-muted);">
                                                <span class="ds-badge" style="--ds-badge-c: var(--text-muted);">Uploaded</span>
                                                @if ($video->status === 'processing' && ! $video->file_name)
                                                    <span class="ds-badge" style="--ds-badge-c: var(--ds-amber);">Processing</span>
                                                @elseif ($video->status === 'failed')
                                                    <span class="ds-badge" style="--ds-badge-c: var(--ds-crimson);">Failed</span>
                                                @else
                                                    <span class="ds-badge" style="--ds-badge-c: var(--ds-emerald);">Ready</span>
                                                @endif
                                                @if ($video->sizeSummary())
                                                    <span>{{ $video->sizeSummary() }}@if ($video->durationLabel()) · {{ $video->durationLabel() }}@endif</span>
                                                @endif
                                            </div>
                                            @if (($video->status === 'processing' && ! $video->file_name) || $video->isReplacing())
                                                {{-- Live shrink progress. Spec §8.11 --}}
                                                <div class="mt-2 max-w-sm" data-tv-progress="{{ $video->id }}">
                                                    <div class="w-full rounded-full overflow-hidden" style="height: 8px; background: var(--surface-2); border: 1px solid var(--border);">
                                                        <div data-tv-bar class="h-full" style="width: {{ (int) $video->progress_percent }}%; background: var(--corex-accent); transition: width .5s;"></div>
                                                    </div>
                                                    <div data-tv-label class="text-xs mt-1" style="color: var(--text-muted);">
                                                        {{ $video->progress_percent === null ? 'Waiting in line…' : 'Shrinking… ' . $video->progress_percent . '%' }}
                                                    </div>
                                                    <div class="text-xs" style="color: var(--text-muted);">
                                                        {{ $video->isReplacing() ? 'The current video keeps playing until the new one is ready.' : 'It goes live on the public page the moment this reaches 100%.' }}
                                                    </div>
                                                </div>
                                            @endif
                                            @if ($video->failure_reason && ($video->status === 'failed' || $video->replacementFailed()))
                                                <div class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $video->failure_reason }}</div>
                                            @endif
                                            @if ($video->isStuck())
                                                <div class="text-xs mt-1" style="color: var(--ds-amber);">Still processing after an hour — the video worker may not be running on this server.</div>
                                            @endif
                                        @endif
                                        @if ($video->trashed())
                                            <span class="ds-badge mt-1" style="--ds-badge-c: var(--text-muted);">Archived</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3" style="color: var(--text-primary);">
                                        @if ($video->group)
                                            {{ $video->group->name }}
                                            @if ($video->group->trashed())
                                                <div class="text-xs" style="color: var(--ds-amber);">Group archived — not on public page</div>
                                            @endif
                                        @else
                                            <span style="color: var(--text-muted);">No group</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap" style="color: var(--text-primary);">{{ $video->created_at?->format('j M Y') }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap" style="color: var(--text-muted);">{{ $video->updated_at?->format('j M Y') }}</td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        <a href="{{ route('admin.training-videos.edit', $video) }}" class="corex-btn-outline text-xs">Edit</a>
                                        @if ($video->canRetry() && ! $video->trashed())
                                            <form method="POST" action="{{ route('admin.training-videos.retry', $video) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="corex-btn-outline text-xs">Try again</button>
                                            </form>
                                        @endif
                                        @if ($video->trashed())
                                            <form method="POST" action="{{ route('admin.training-videos.restore', $video) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('admin.training-videos.destroy', $video) }}" class="inline"
                                                  onsubmit="return confirm('Archive this video? It comes off the public page. You can restore it later.');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="corex-btn-outline text-xs">Archive</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        @if ($videos->hasPages())
            <div>{{ $videos->links() }}</div>
        @endif
    @endif

</div>

<script>
// Live progress for videos still being shrunk (spec §8.11). Polls every 5 s while any are in flight;
// when one finishes the list reloads so it shows Ready.
(function () {
    var boxes = document.querySelectorAll('[data-tv-progress]');
    if (!boxes.length) return;
    var url = @js(route('api.v1.training-video-uploads.progress'));
    function fmt(sec) {
        if (sec === null || sec === undefined) return '';
        if (sec < 60) return ' · under a minute left';
        var m = Math.round(sec / 60);
        return ' · about ' + (m < 60 ? m + ' min' : Math.floor(m / 60) + ' h ' + (m % 60) + ' min') + ' left';
    }
    function tick() {
        fetch(url, {headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'})
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) return;
                var live = {};
                d.videos.forEach(function (v) { live[v.id] = v; });
                var finished = false;
                boxes.forEach(function (box) {
                    var v = live[box.getAttribute('data-tv-progress')];
                    if (!v) { finished = true; return; }
                    var p = v.percent;
                    box.querySelector('[data-tv-bar]').style.width = (p || 0) + '%';
                    box.querySelector('[data-tv-label]').textContent =
                        p === null ? 'Waiting in line…' : 'Shrinking… ' + p + '%' + fmt(v.eta);
                });
                if (finished) { window.location.reload(); }
            })
            .catch(function () {});
    }
    setInterval(tick, 5000);
    tick();
})();
</script>
@endsection
