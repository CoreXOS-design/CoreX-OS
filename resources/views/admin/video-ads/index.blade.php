{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Video ads (Ad Studio) — CoreX owners -> Marketing -> Video ads. System Owner only.
    Spec: .ai/specs/training-videos.md 9.21.1, 9.21.9
--}}
@extends('layouts.corex')

@section('title', 'Video ads')

@section('corex-content')
@php
    $sortLink = function (string $key, string $label) use ($sort, $dir) {
        $nextDir = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
        $arrow   = $sort === $key ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
        $url     = request()->fullUrlWithQuery(['sort' => $key, 'dir' => $nextDir, 'page' => null]);
        return '<a href="' . e($url) . '" class="hover:underline">' . e($label) . $arrow . '</a>';
    };
    $th = 'text-left px-3 py-2 text-xs font-semibold uppercase tracking-wider';
    $field = 'rounded-md px-3 py-2 text-sm';
    $fieldStyle = 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);';
    $filtered = collect($filters)->except('status')->filter()->isNotEmpty() || $filters['status'] !== 'active';
    $formats = (array) config('ad-studio.formats');
    $hasAny = \App\Models\VideoAd::withTrashed()->exists();
    $working = collect($page->items())->contains(fn ($a) => $a->isWorking());
    $voices = app(\App\Services\TrainingVideos\Tts\TrainingVoices::class);
@endphp
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Video ads</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    One-click video ads for CoreX. Pick a feature, press New ad, tweak the words, press Generate. You download the video and post it yourself.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.video-ads.brand') }}" class="corex-btn-outline corex-btn-on-brand text-xs">Brand settings</a>
            </div>
        </div>
    </div>

    @if (session('status'))
        <div role="status" class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-emerald) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-emerald) 30%, transparent); color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif
    @if ($errors->any())
        <div role="alert" class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Filters --}}
    <form method="GET" action="{{ route('admin.video-ads.index') }}" class="rounded-md p-4 flex flex-wrap items-end gap-3" style="background: var(--surface); border: 1px solid var(--border);">
        <input type="hidden" name="sort" value="{{ $sort }}">
        <input type="hidden" name="dir" value="{{ $dir }}">
        <div class="flex-1 min-w-[200px]">
            <label for="q" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] }}" placeholder="Ad name, headline or post text" class="w-full {{ $field }}" style="{{ $fieldStyle }}">
        </div>
        <div>
            <label for="feature" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Feature</label>
            <select id="feature" name="feature" class="{{ $field }}" style="{{ $fieldStyle }}">
                <option value="">All features</option>
                @foreach ($features as $key => $f)
                    <option value="{{ $key }}" @selected($filters['feature'] === $key)>{{ $f->title() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="status" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Show</label>
            <select id="status" name="status" class="{{ $field }}" style="{{ $fieldStyle }}">
                <option value="active" @selected($filters['status'] === 'active')>Active</option>
                <option value="ready" @selected($filters['status'] === 'ready')>Ready only</option>
                <option value="failed" @selected($filters['status'] === 'failed')>Failed only</option>
                <option value="archived" @selected($filters['status'] === 'archived')>Archived</option>
                <option value="all" @selected($filters['status'] === 'all')>All</option>
            </select>
        </div>
        <div>
            <label for="format" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Format</label>
            <select id="format" name="format" class="{{ $field }}" style="{{ $fieldStyle }}">
                <option value="">Any</option>
                @foreach ($formats as $fmt => $for)
                    <option value="{{ $fmt }}" @selected($filters['format'] === $fmt)>{{ $fmt }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="from" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Made from</label>
            <input id="from" name="from" type="date" value="{{ $filters['from'] }}" class="{{ $field }}" style="{{ $fieldStyle }}">
        </div>
        <div>
            <label for="to" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Made to</label>
            <input id="to" name="to" type="date" value="{{ $filters['to'] }}" class="{{ $field }}" style="{{ $fieldStyle }}">
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" class="corex-btn-primary text-xs">Apply</button>
            @if ($filtered)
                <a href="{{ route('admin.video-ads.index') }}" class="corex-btn-outline text-xs">Clear</a>
            @endif
        </div>
    </form>

    @if (! $hasAny)
        <div class="rounded-md px-6 py-10 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <p class="text-sm font-medium" style="color: var(--text-primary);">No video ads yet</p>
            <p class="mt-1 text-xs max-w-md mx-auto" style="color: var(--text-muted);">Pick a feature below and press New ad. The first ad is pre-written for you.</p>
        </div>
    @endif

    {{-- One section per feature: its saved ads as dense rows, "New ad" on the heading. --}}
    @foreach ($features as $key => $feature)
        @continue($filters['feature'] && $filters['feature'] !== $key)
        @php $rows = $byFeature->get($key, collect()); @endphp
        <section class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);" data-qa="feature-{{ $key }}">
            <div class="px-4 py-3 flex flex-wrap items-center justify-between gap-2" style="background: var(--surface-2);">
                <div>
                    <h2 class="text-sm font-bold" style="color: var(--text-primary);">{{ $feature->title() }}</h2>
                    @unless ($feature->approved())
                        <p class="text-xs" style="color: var(--text-muted);">The first script is waiting for Johan's approval before an ad can be made.</p>
                    @endunless
                </div>
                <a href="{{ route('admin.video-ads.create', $key) }}" class="corex-btn-primary text-xs">New ad</a>
            </div>

            @if ($rows->isEmpty())
                <p class="px-4 py-4 text-xs" style="color: var(--text-muted);">
                    {{ $filtered ? 'No ads match these filters.' : 'No ads for ' . $feature->title() . ' yet.' }}
                    @if ($filtered) <a href="{{ route('admin.video-ads.index') }}" class="hover:underline" style="color: var(--corex-accent);">Clear filters</a> @endif
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-xs ds-table">
                        <thead>
                            <tr style="color: var(--text-muted);">
                                <th class="{{ $th }}">{!! $sortLink('name', 'Ad') !!}</th>
                                <th class="{{ $th }}">{!! $sortLink('created', 'Created') !!}</th>
                                <th class="{{ $th }}">{!! $sortLink('length', 'Length') !!}</th>
                                <th class="{{ $th }}">Formats</th>
                                <th class="{{ $th }}">Voice</th>
                                <th class="{{ $th }}">{!! $sortLink('status', 'Status') !!}</th>
                                <th class="{{ $th }}">Download</th>
                                <th class="text-right px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $ad)
                                <tr style="border-top: 1px solid var(--border);" data-qa="ad-row" data-ad-id="{{ $ad->id }}">
                                    <td class="px-3 py-2">
                                        <a href="{{ route('admin.video-ads.show', $ad) }}" class="font-semibold hover:underline" style="color: var(--corex-accent);">{{ $ad->name }}</a>
                                        <div style="color: var(--text-muted);">{{ \Illuminate\Support\Str::limit($ad->headline(), 46) }}</div>
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap" style="color: var(--text-secondary);">{{ $ad->created_at->format('j M Y H:i') }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ $ad->length_seconds }} s</td>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ implode(' · ', (array) $ad->formats) }}</td>
                                    <td class="px-3 py-2">{{ $voices->labelFor($ad->voice_key) }}</td>
                                    <td class="px-3 py-2" style="min-width: 150px;">
                                        @if ($ad->trashed())
                                            <span style="color: var(--text-muted);">Archived</span>
                                        @elseif ($ad->isWorking())
                                            <span data-qa="ad-status" data-progress-for="{{ $ad->id }}">{{ $ad->status === 'queued' ? 'Waiting in line…' : 'Making it ' . (int) $ad->progress_percent . '%' }}</span>
                                            <div class="mt-1 h-1.5 rounded" style="background: var(--surface-2);"><div data-progress-bar="{{ $ad->id }}" class="h-1.5 rounded" style="width: {{ (int) $ad->progress_percent }}%; background: var(--corex-accent);"></div></div>
                                        @elseif ($ad->status === 'failed')
                                            <span style="color: var(--ds-crimson);" title="{{ $ad->failure_reason }}">Failed</span>
                                            <div style="color: var(--text-muted);">{{ \Illuminate\Support\Str::limit($ad->failure_reason, 70) }}</div>
                                        @else
                                            <span style="color: var(--ds-emerald);">Ready</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        @if ($ad->isReady())
                                            @foreach ($ad->readyFormats() as $fmt => $info)
                                                <a href="{{ route('admin.video-ads.download', [$ad, str_replace(':', 'x', $fmt)]) }}" class="corex-btn-outline text-xs" style="padding: 2px 8px;">{{ $fmt }}</a>
                                            @endforeach
                                        @else
                                            <span style="color: var(--text-muted);">-</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap text-right">
                                        <button type="button" class="corex-btn-outline text-xs" style="padding: 2px 8px;" data-copy="{{ $ad->postText() }}" data-qa="copy-post">Copy post text</button>
                                        <a href="{{ route('admin.video-ads.duplicate', $ad) }}" class="corex-btn-outline text-xs" style="padding: 2px 8px;">Duplicate</a>
                                        @if ($ad->status === 'failed' && ! $ad->trashed())
                                            <form method="POST" action="{{ route('admin.video-ads.retry', $ad) }}" class="inline">@csrf
                                                <button type="submit" class="corex-btn-outline text-xs" style="padding: 2px 8px;">Try again</button></form>
                                        @endif
                                        @if ($ad->trashed())
                                            <form method="POST" action="{{ route('admin.video-ads.restore', $ad) }}" class="inline">@csrf
                                                <button type="submit" class="corex-btn-outline text-xs" style="padding: 2px 8px;">Restore</button></form>
                                        @else
                                            <form method="POST" action="{{ route('admin.video-ads.destroy', $ad) }}" class="inline" data-confirm="Archive {{ $ad->name }}? You can restore it later.">@csrf @method('DELETE')
                                                <button type="submit" class="corex-btn-outline text-xs" style="padding: 2px 8px;">Archive</button></form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endforeach

    <div>{{ $page->links() }}</div>
</div>

<script>
(function () {
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-copy]');
        if (!b) return;
        var text = b.getAttribute('data-copy');
        var done = function () { var t = b.textContent; b.textContent = 'Copied'; setTimeout(function () { b.textContent = t; }, 1500); };
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done); }
        else { var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (x) {} ta.remove(); }
    });
    @if ($working)
    var known = {};
    document.querySelectorAll('[data-progress-for]').forEach(function (el) { known[el.getAttribute('data-progress-for')] = true; });
    var poll = function () {
        fetch(@json(route('api.v1.video-ads.progress')), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                var still = {};
                (j.working || []).forEach(function (a) {
                    still[a.id] = true;
                    var s = document.querySelector('[data-progress-for="' + a.id + '"]');
                    var bar = document.querySelector('[data-progress-bar="' + a.id + '"]');
                    if (s) s.textContent = a.status === 'queued' ? 'Waiting in line…' : 'Making it ' + a.percent + '%';
                    if (bar) bar.style.width = a.percent + '%';
                });
                for (var id in known) { if (!still[id]) { location.reload(); return; } }
            }).catch(function () {});
    };
    setInterval(poll, 5000);
    @endif
})();
</script>
@endsection
