{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Demo Access Control — grant list. Owner-only.
    Spec: .ai/specs/demo-access-control.md §9, §9.1, §9.2

    Prospect cards with a Views panel (Hot now / Expiring soon / Went quiet / Not used
    yet / Ended / Archived). Header is the flat Properties header — no brand fill, a
    thin rule under it — without the fold-on-scroll behaviour.
--}}
@extends('layouts.corex')

@section('title', 'Demo Access')

@section('corex-content')
@php
    /** @var \Illuminate\Pagination\LengthAwarePaginator $rows */
    $rows    = $listing['rows'];
    $views   = $listing['views'];
    $f       = $listing['filters'];

    // A link that changes one thing and keeps every other filter.
    $to = fn (array $over) => route('admin.demo-access.index', array_filter(
        array_merge($f, ['hide_unused' => $f['hide_unused'] ? 1 : null, 'hide_ended' => $f['hide_ended'] ? 1 : null], $over),
        fn ($v) => $v !== null && $v !== '' && $v !== 'all' && $v !== 'recent'
    ));

    $dotVar = ['fresh' => 'var(--ds-green, #059669)', 'warm' => 'var(--ds-amber, #f59e0b)', 'cold' => 'var(--border-hover, #9ca3af)'];
@endphp
<div class="w-full space-y-4" x-data>

    {{-- Header — the flat bar the Properties page uses (AT-336): no card fill, no rounded
         corners, no brand block; the negative margins break it out of <main>'s padding so
         the rule spans the full width and sits flush at the top. --}}
    <div class="-mx-4 lg:-mx-6 -mt-4 lg:-mt-6">
        <div class="px-6 py-3.5" style="border-bottom: 1px solid var(--border);">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Demo Access</h1>
                    <p class="text-xs" style="color: var(--text-muted);">
                        Time-boxed access to {{ $demoHost }} · next demo reset {{ $nextReset->format('D j M, H:i') }} (the demo rebuilds every {{ $resetDays }} days).
                    </p>
                    <p class="text-xs mt-1 flex flex-wrap items-center gap-2" style="color: var(--text-muted);">
                        <span class="ds-badge {{ $tncVersion ? 'ds-badge-success' : 'ds-badge-danger' }}">{{ $tncVersion ? 'Terms version ' . $tncVersion->version : 'No terms published' }}</span>
                        <span class="ds-badge {{ $connector ? 'ds-badge-success' : 'ds-badge-danger' }}">{{ $connector ? 'Demo connected' : 'Demo not connected' }}</span>
                        <span>{{ number_format($listing['issued']) }} {{ $listing['issued'] === 1 ? 'grant' : 'grants' }} issued</span>
                    </p>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
                    <a href="{{ route('admin.demo-access.connection') }}" class="corex-btn-outline text-xs">Demo connection</a>
                    <a href="{{ route('admin.demo-access.tnc') }}" class="corex-btn-outline text-xs">Terms &amp; Conditions</a>
                    <a href="{{ route('admin.demo-access.create') }}" class="corex-btn-primary text-xs">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                        </svg>
                        New grant
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- No T&C published = the clickwrap has nothing to show and EVERY prospect is
         hard-blocked at the gate. Surface it loudly rather than discovering it when a
         prospect calls. §3.9 danger alert — a genuine blocked state. --}}
    @unless ($tncVersion)
        <div role="alert" class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson, #c41e3a);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
            </svg>
            <div class="flex-1">
                <strong>No terms published.</strong> Until a version exists, nobody can get past the
                demo's terms screen — every prospect is blocked.
            </div>
            <a href="{{ route('admin.demo-access.tnc') }}" class="text-xs font-semibold flex-shrink-0"
               style="color: var(--ds-crimson, #c41e3a);">Publish version 1</a>
        </div>
    @endunless

    {{-- Same class of failure, same loudness: no connector = the demo cannot ask us
         whether a code is real, and the gate fails closed. §3.9 --}}
    @unless ($connector)
        <div role="alert" class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson, #c41e3a);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244"/>
            </svg>
            <div class="flex-1">
                <strong>No demo connection.</strong> The demo cannot reach this system, so nobody can
                sign in to it — the gate fails closed by design.
            </div>
            <a href="{{ route('admin.demo-access.connection') }}" class="text-xs font-semibold flex-shrink-0"
               style="color: var(--ds-crimson, #c41e3a);">Set it up</a>
        </div>
    @endunless

    @if (session('status'))
        <div role="status" class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="flex flex-col lg:flex-row gap-4 items-start">

        {{-- ── Left rail: views (with counts), search, issued-date range, sort, hide switches ── --}}
        <aside class="w-full lg:w-60 lg:flex-shrink-0 rounded-md p-4 space-y-4" style="background: var(--surface); border: 1px solid var(--border);" aria-label="Filter grants">

            <form method="GET" action="{{ route('admin.demo-access.index') }}" class="space-y-3">
                <input type="hidden" name="view" value="{{ $f['view'] }}">

                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none"
                         style="color: var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                    </svg>
                    <input type="text" name="q" value="{{ $f['q'] }}" placeholder="Search company, name or email…"
                           aria-label="Search company, contact name or email"
                           class="w-full pl-10 pr-3 py-2 text-sm rounded-md"
                           style="border: 1px solid var(--border); background: var(--surface-2); color: var(--text-primary); outline: none;">
                </div>

                <div>
                    <label for="da-sort" class="text-xs font-medium block mb-1" style="color: var(--text-secondary);">Sort by</label>
                    <select id="da-sort" name="sort" onchange="this.form.submit()" class="list-header-filter w-full">
                        @foreach (\App\Support\DemoAccessListing::SORTS as $key => $label)
                            <option value="{{ $key }}" @selected($f['sort'] === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <div class="text-xs font-medium mb-1" style="color: var(--text-secondary);">Issued between</div>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="date" name="issued_from" value="{{ $f['issued_from'] }}" onchange="this.form.submit()" aria-label="Issued from"
                               class="text-xs rounded-md px-2 py-1.5" style="border: 1px solid var(--border); background: var(--surface-2); color: var(--text-primary); color-scheme: light dark;">
                        <input type="date" name="issued_to" value="{{ $f['issued_to'] }}" onchange="this.form.submit()" aria-label="Issued to"
                               class="text-xs rounded-md px-2 py-1.5" style="border: 1px solid var(--border); background: var(--surface-2); color: var(--text-primary); color-scheme: light dark;">
                    </div>
                </div>

                <div class="space-y-2 pt-1">
                    <label class="flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-primary);">
                        <input type="checkbox" name="hide_unused" value="1" @checked($f['hide_unused']) onchange="this.form.submit()" class="rounded" style="accent-color: var(--brand-button, #0ea5e9);">
                        Hide not used
                    </label>
                    <label class="flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-primary);">
                        <input type="checkbox" name="hide_ended" value="1" @checked($f['hide_ended']) onchange="this.form.submit()" class="rounded" style="accent-color: var(--brand-button, #0ea5e9);">
                        Hide ended
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>
                    @if ($listing['anyFilter'])
                        <a href="{{ route('admin.demo-access.index') }}" class="text-xs underline" style="color: var(--text-muted);">Clear all</a>
                    @endif
                </div>
            </form>

            <nav aria-label="Views" class="pt-3" style="border-top: 1px solid var(--border);">
                <div class="text-xs font-semibold uppercase tracking-wider mb-2" style="color: var(--text-muted);">Views</div>
                <ul class="space-y-0.5">
                    @foreach ($views as $v)
                        <li>
                            <a href="{{ $to(['view' => $v['key'], 'page' => null]) }}"
                               @if ($v['active']) aria-current="page" @endif
                               title="{{ $v['hint'] ?? '' }}"
                               class="flex items-center justify-between gap-2 px-3 py-2 rounded-md text-sm no-underline"
                               style="{{ $v['active']
                                    ? 'background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, var(--surface)); color: var(--text-primary); font-weight: 600;'
                                    : 'color: var(--text-secondary);' }}">
                                <span>{{ $v['label'] }}</span>
                                <span class="text-xs tabular-nums" style="color: var(--text-muted);">{{ number_format($v['count']) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        </aside>

        {{-- ── Right: prospect cards ── --}}
        <section class="flex-1 min-w-0 w-full space-y-3">

            @if ($rows->total() === 0)
                {{-- Empty state — §3.10. Distinct copy for "no grants at all" vs "this view/filter is empty",
                     because the next step is different in each case. --}}
                <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                         style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                        <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z"/>
                        </svg>
                    </div>
                    @if ($listing['issued'] === 0)
                        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No demo grants yet</h3>
                        <p class="text-sm mb-4" style="color: var(--text-muted);">Issue a grant to give a prospect time-boxed access to the demo. They get an emailed code; the clock starts when they first sign in.</p>
                        <a href="{{ route('admin.demo-access.create') }}" class="corex-btn-primary text-sm">Issue the first grant</a>
                    @elseif ($listing['anyFilter'])
                        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No grants in this view</h3>
                        <p class="text-sm mb-4" style="color: var(--text-muted);">Nothing matches the filters you have on. Pick another view on the left, or clear them to see every grant.</p>
                        <a href="{{ route('admin.demo-access.index') }}" class="corex-btn-primary text-sm">Clear all filters</a>
                    @else
                        {{-- Grants exist, but every one is archived — "All grants" excludes them. --}}
                        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No active grants</h3>
                        <p class="text-sm mb-4" style="color: var(--text-muted);">Every grant has been archived. Open the Archived view on the left to restore one, or issue a new grant.</p>
                        <a href="{{ $to(['view' => 'archived', 'page' => null]) }}" class="corex-btn-outline text-sm">Open archived grants</a>
                        <a href="{{ route('admin.demo-access.create') }}" class="corex-btn-primary text-sm">New grant</a>
                    @endif
                </div>
            @else
                <div class="text-xs" style="color: var(--text-muted);">
                    Showing {{ number_format($rows->firstItem() ?? 0) }}–{{ number_format($rows->lastItem() ?? 0) }} of {{ number_format($rows->total()) }}
                    · bars show pages viewed per day over the last {{ \App\Support\DemoAccessListing::SPARK_DAYS }} days
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 2xl:grid-cols-3 gap-3">
                    @foreach ($rows as $m)
                        @php
                            $usable = in_array($m['status'], ['pending', 'active'], true);
                            $max    = max(1, max($m['spark']));
                        @endphp
                        <article class="rounded-md p-4 flex flex-col gap-3" style="background: var(--surface); border: 1px solid var(--border);">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <a href="{{ route('admin.demo-access.show', $m['grant']) }}"
                                       class="font-semibold text-sm no-underline hover:underline" style="color: var(--brand-icon, #0ea5e9);">{{ $m['company'] }}</a>
                                    <div class="text-xs break-all" style="color: var(--text-secondary);">{{ $m['email'] }}</div>
                                </div>
                                <span class="ds-badge {{ $m['badge'] }} flex-shrink-0">{{ $m['label'] }}</span>
                            </div>

                            <div class="flex items-end gap-0.5 h-11 pb-1" style="border-bottom: 1px solid var(--border);"
                                 role="img" aria-label="Pages viewed per day, last {{ \App\Support\DemoAccessListing::SPARK_DAYS }} days: {{ implode(', ', $m['spark']) }}">
                                @foreach ($m['spark'] as $n)
                                    <div class="flex-1 rounded-t-sm"
                                         style="height: {{ $n === 0 ? 2 : 4 + (int) round($n / $max * 34) }}px; background: {{ $n === 0 ? 'var(--border)' : ($usable ? 'var(--brand-icon, #0ea5e9)' : 'var(--border-hover, #9ca3af)') }};"></div>
                                @endforeach
                            </div>

                            <div class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                                <span class="inline-block w-2 h-2 rounded-full flex-shrink-0" style="background: {{ $dotVar[$m['dot']] }};"></span>
                                {{ $m['last'] ? 'Last seen ' . $m['ago'] : $m['ago'] }}
                            </div>

                            <div class="flex items-center justify-between gap-2 text-xs" style="color: var(--text-secondary);">
                                <span><strong style="color: var(--text-primary);">{{ number_format($m['pages']) }}</strong> pages · <strong style="color: var(--text-primary);">{{ number_format($m['sessions']) }}</strong> {{ $m['sessions'] === 1 ? 'session' : 'sessions' }}</span>
                                <span class="font-semibold" style="color: {{ $m['urgent'] ? 'var(--ds-crimson, #c41e3a)' : 'var(--text-secondary)' }};">{{ $m['left'] }}</span>
                            </div>

                            <div class="flex items-center gap-2 pt-1">
                                <a href="{{ route('admin.demo-access.show', $m['grant']) }}" class="corex-btn-outline text-xs">View</a>
                                @if ($m['canExtend'])
                                    <button type="button" class="corex-btn-outline text-xs"
                                            @click="$dispatch('demo-extend', {{ \Illuminate\Support\Js::from($m['extend']) }})">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                                        Add time
                                    </button>
                                @endif
                                @if ($m['status'] === 'archived')
                                    <form method="POST" action="{{ route('admin.demo-access.restore', $m['grant']) }}">
                                        @csrf
                                        <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                                    </form>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>

                @if ($rows->hasPages())
                    <div class="pt-2">{{ $rows->links() }}</div>
                @endif
            @endif
        </section>
    </div>
</div>

@include('admin.demo-access._extend-modal')
@endsection
