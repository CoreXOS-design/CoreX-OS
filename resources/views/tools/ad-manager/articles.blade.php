{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Article Library (Ad Manager, third tab) — AT-456 Part C, spec §C.5 --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $f = $filters;
    $filtersActive = $f['q'] !== '' || $f['source'] > 0 || $f['tag'] !== '' || $f['unused'] || $f['from'] || $f['to'];
    $input = 'background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);';
    $orderOptions = [
        'date-desc' => 'Newest first', 'date-asc' => 'Oldest first',
        'title-asc' => 'Title A to Z', 'title-desc' => 'Title Z to A',
        'source-asc' => 'Source A to Z', 'source-desc' => 'Source Z to A',
    ];
@endphp

<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Article Library</h1>
                <p class="text-sm text-white/60">Short, ready-to-adapt briefs on South African property news. Click “Use this” to copy one into your own article drafts — nothing is ever published for you.</p>
            </div>
            @include('tools.ad-manager._switcher', ['inHeader' => true])
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium" style="background:color-mix(in srgb, var(--ds-green,#059669) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green,#059669) 30%, transparent); color:var(--text-primary);">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm font-medium" style="background:color-mix(in srgb, var(--ds-crimson,#c41e3a) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson,#c41e3a) 30%, transparent); color:var(--text-primary);">{{ session('error') }}</div>
    @endif

    {{-- Search + filters --}}
    <form method="GET" action="{{ route('tools.ad-manager.articles') }}"
          class="rounded-md p-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-6 items-end" style="background:var(--surface); border:1px solid var(--border);">
        <label class="lg:col-span-2 block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Search</span>
            <input type="search" name="q" value="{{ $f['q'] }}" placeholder="Title, source or tag…" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $input }}">
        </label>
        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Source</span>
            <select name="source" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $input }}">
                <option value="0">All sources</option>
                @foreach($sources as $s)<option value="{{ $s->id }}" @selected($f['source'] === $s->id)>{{ $s->name }}</option>@endforeach
            </select>
        </label>
        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Tag</span>
            <select name="tag" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $input }}">
                <option value="">All tags</option>
                @foreach($tags as $t)<option value="{{ $t }}" @selected($f['tag'] === $t)>{{ $t }}</option>@endforeach
            </select>
        </label>
        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">From</span>
            <input type="date" name="from" value="{{ $f['from'] }}" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $input }}">
        </label>
        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">To</span>
            <input type="date" name="to" value="{{ $f['to'] }}" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $input }}">
        </label>
        <label class="block">
            <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Sort</span>
            <select name="order" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $input }}">
                @foreach($orderOptions as $k => $label)<option value="{{ $k }}" @selected($f['order'] === $k)>{{ $label }}</option>@endforeach
            </select>
        </label>
        <label class="lg:col-span-2 inline-flex items-center gap-2 text-sm pb-2" style="color:var(--text-secondary);">
            <input type="checkbox" name="unused" value="1" @checked($f['unused'])> Not yet used by me
        </label>
        <div class="sm:col-span-2 lg:col-span-6 flex items-center gap-2">
            <button type="submit" class="corex-btn-primary text-sm px-4 py-2">Apply</button>
            @if($filtersActive)<a href="{{ route('tools.ad-manager.articles') }}" class="corex-btn-outline text-sm">Clear filters</a>@endif
            <span class="text-xs ml-auto" style="color:var(--text-muted);">{{ $articles->total() }} article{{ $articles->total() === 1 ? '' : 's' }}</span>
        </div>
    </form>

    @if($articles->isEmpty())
        <div class="rounded-md py-12 px-6 text-center" style="background:var(--surface); border:1px solid var(--border);">
            @if($libraryEmpty)
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">The Article Library is empty for now</h3>
                <p class="text-sm" style="color:var(--text-muted);">New briefs on South African property news are added regularly. Check back soon.</p>
            @else
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No articles match these filters</h3>
                <p class="text-sm mb-4" style="color:var(--text-muted);">Try a different search, or clear the filters to see everything.</p>
                <a href="{{ route('tools.ad-manager.articles') }}" class="corex-btn-outline text-sm">Clear filters</a>
            @endif
        </div>
    @else
        <div class="space-y-3">
            @foreach($articles as $a)
                @php $copyId = $mine[$a->id] ?? null; @endphp
                <article class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);" x-data="{ open: false }">
                    <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="text-base font-semibold" style="color:var(--text-primary);">{{ $a->title }}</h2>
                            <div class="text-xs mt-0.5" style="color:var(--text-muted);">
                                {{ $a->source_name }} · {{ ($a->source_published_at ?? $a->generated_at ?? $a->created_at)?->format('j M Y') }}
                                @if($copyId)<span class="ds-badge ds-badge-success ml-1">You've used this</span>@endif
                            </div>
                            @if($a->excerpt)<p class="text-sm mt-2" style="color:var(--text-secondary);">{{ $a->excerpt }}</p>@endif
                            @if($a->tagList())
                                <div class="flex flex-wrap gap-1 mt-2">@foreach($a->tagList() as $t)<a href="{{ route('tools.ad-manager.articles', ['tag' => $t]) }}" class="ds-badge ds-badge-muted">{{ $t }}</a>@endforeach</div>
                            @endif
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0 flex-wrap">
                            <button type="button" class="corex-btn-outline text-xs" @click="open = !open" x-text="open ? 'Hide brief' : 'Read brief'">Read brief</button>
                            <a href="{{ $a->source_url }}" target="_blank" rel="noopener noreferrer nofollow" class="corex-btn-outline text-xs">Read original ↗</a>
                            @if($canUse)
                                @if($copyId)
                                    <form method="POST" action="{{ route('tools.ad-manager.articles.use', $a->id) }}">@csrf<button type="submit" class="corex-btn-outline text-xs" title="Opens the copy you already made">Open my copy</button></form>
                                @else
                                    <form method="POST" action="{{ route('tools.ad-manager.articles.use', $a->id) }}">@csrf<button type="submit" class="corex-btn-primary text-xs">Use this</button></form>
                                @endif
                            @endif
                        </div>
                    </div>
                    <div x-show="open" x-cloak class="mt-4 pt-4 text-sm whitespace-pre-line" style="border-top:1px solid var(--border); color:var(--text-secondary);">{{ $a->body }}

<span class="text-xs" style="color:var(--text-muted);">{{ $a->creditLine() }}</span></div>
                </article>
            @endforeach
        </div>
        <div>{{ $articles->links() }}</div>
    @endif
</div>
@endsection
