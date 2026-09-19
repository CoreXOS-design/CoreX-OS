@extends('layouts.corex')

{{--
    Part 4 — Unified Outreach & Canvassing board.
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md (CoreX tokens; var(--token,#fallback) pattern).

    Tab 1: Activity Feed — every outreach/canvassing action over agent_activity_events,
           source-tagged (MIC prospecting | Direct contact | Comms tile). The three
           streams are counted SEPARATELY (own subtotal each); the total is shown as a
           VISIBLE SUM of the parts so the origin of every WhatsApp is always answerable.
    Tab 2: Consent Funnel — the existing AT-91 WhatsApp matrix, retained as-is.

    AT-393 — header, tab switcher, subtotal tiles and the filter card are frozen (the page
    wrapper is a full-height flex column); each tab's body is its own scroll region.
    Spec: .ai/specs/outreach-canvassing-board-list.md
--}}

@php
    $sourceTokens = [
        'mic_prospect'   => '--ds-green,#059669',
        'direct_contact' => '--brand-icon,#0ea5e9',
        'comms_tile'     => '--ds-orange,#ea580c',
    ];
    $sub = $feed['subtotals'] ?? ['mic_prospect' => 0, 'direct_contact' => 0, 'comms_tile' => 0];
    $filterQ = $filterQ ?? '';
    $filterAgentId = $filterAgentId ?? null;
    $agents = $agents ?? collect();
    // Carried on every tile / clear link so a source click never drops the search or agent.
    $carry = array_filter(['q' => $filterQ, 'agent_id' => $filterAgentId], fn ($v) => $v !== '' && $v !== null);
    $filtersActive = $filterQ !== '' || $filterSource || $filterAgentId;
@endphp

@section('corex-content')
<div class="w-full h-full flex flex-col" x-data="{ tab: '{{ $activeTab }}' }">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner flex-shrink-0">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Outreach &amp; Canvassing</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    Every canvassing and outreach action in one place — source-tagged so you can always
                    see where a pitch came from. MIC prospecting, direct contact, and comms-tile figures
                    are kept separate and never blended.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
            </div>
        </div>
    </div>

    {{-- Tab switcher --}}
    <div class="flex gap-1 flex-shrink-0 mt-3" style="border-bottom:1px solid var(--border,#e5e7eb);">
        <button @click="tab = 'activity'"
                :style="tab === 'activity' ? 'color:var(--brand-icon,#0ea5e9); border-color:var(--brand-icon,#0ea5e9);' : 'color:var(--text-muted,#9ca3af);'"
                :class="tab === 'activity' ? 'border-b-2' : 'border-b-2 border-transparent'"
                class="px-4 py-2.5 text-sm font-semibold">Activity Feed</button>
        <button @click="tab = 'consent'"
                :style="tab === 'consent' ? 'color:var(--brand-icon,#0ea5e9); border-color:var(--brand-icon,#0ea5e9);' : 'color:var(--text-muted,#9ca3af);'"
                :class="tab === 'consent' ? 'border-b-2' : 'border-b-2 border-transparent'"
                class="px-4 py-2.5 text-sm font-semibold">Consent Funnel</button>
    </div>

    {{-- ════════════════ TAB 1 — ACTIVITY FEED ════════════════ --}}
    <div x-show="tab === 'activity'" x-cloak class="flex-1 min-h-0 flex flex-col">

        {{-- Source subtotals — three SEPARATE streams, never blended. Total = visible sum.
             Window-wide and source-wide: the search box never changes these. --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 flex-shrink-0 mt-3">
            @foreach(['mic_prospect','direct_contact','comms_tile'] as $src)
                <a href="{{ route('corex.outreach-canvassing.index', array_merge(['tab' => 'activity', 'days' => $filterDays, 'source' => $src], $carry)) }}"
                   class="rounded-md px-4 py-3 no-underline transition-all"
                   style="background:var(--surface,#fff); border:1px solid {{ $filterSource === $src ? 'var('.$sourceTokens[$src].')' : 'var(--border,#e5e7eb)' }};">
                    <div class="flex items-center gap-2">
                        <span class="inline-block w-2.5 h-2.5 rounded-full" style="background:var({{ $sourceTokens[$src] }});"></span>
                        <span class="text-xs font-semibold" style="color:var(--text-muted,#9ca3af);">{{ $sourceLabels[$src] }}</span>
                    </div>
                    <div class="text-2xl font-bold mt-1 tabular-nums" style="color:var(--text-primary,#0b2a4a);">{{ number_format($sub[$src] ?? 0) }}</div>
                </a>
            @endforeach

            {{-- Total = a VISIBLE SUM of the three parts (mic + direct + comms-tile). --}}
            <div class="rounded-md px-4 py-3" style="background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb);">
                <div class="text-xs font-semibold" style="color:var(--text-muted,#9ca3af);">Total actions</div>
                <div class="text-2xl font-bold mt-1 tabular-nums" style="color:var(--text-primary,#0b2a4a);">{{ number_format($feed['total'] ?? 0) }}</div>
                <div class="text-[0.625rem] mt-0.5" style="color:var(--text-muted,#9ca3af);">
                    = {{ number_format($sub['mic_prospect'] ?? 0) }} + {{ number_format($sub['direct_contact'] ?? 0) }} + {{ number_format($sub['comms_tile'] ?? 0) }}
                </div>
            </div>
        </div>

        {{-- Filters — one GET form: search + agent (scope-limited) + window + source. --}}
        <form method="GET" action="{{ route('corex.outreach-canvassing.index') }}"
              class="rounded-md px-4 py-3 mt-3 flex-shrink-0 flex flex-wrap items-center gap-3"
              style="background: var(--surface,#fff); border: 1px solid var(--border,#e5e7eb);">
            <input type="hidden" name="tab" value="activity">

            <div class="relative flex-1 min-w-[180px] max-w-xs">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none" style="color:var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                </svg>
                <input type="text" name="q" value="{{ $filterQ }}"
                       placeholder="Search who, agent, action or outcome…"
                       class="w-full pl-10 pr-3 py-2 text-sm rounded-md transition-all duration-300"
                       style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);outline:none;">
            </div>

            @if($canSeeTeam && $agents->isNotEmpty())
            <select name="agent_id" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All agents</option>
                @foreach($agents as $a)
                    <option value="{{ $a->id }}" {{ (int) $filterAgentId === (int) $a->id ? 'selected' : '' }}>{{ $a->name }}</option>
                @endforeach
            </select>
            @endif

            <select name="days" onchange="this.form.submit()" class="list-header-filter">
                @foreach([30 => 'Last 30 days', 90 => 'Last 90 days', 180 => 'Last 180 days', 365 => 'Last year'] as $d => $lbl)
                    <option value="{{ $d }}" {{ (int)$filterDays === $d ? 'selected' : '' }}>{{ $lbl }}</option>
                @endforeach
            </select>

            <select name="source" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All sources</option>
                @foreach($sourceLabels as $sk => $sl)
                    <option value="{{ $sk }}" {{ $filterSource === $sk ? 'selected' : '' }}>{{ $sl }}</option>
                @endforeach
            </select>

            <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>
            @if($filtersActive)
                <a href="{{ route('corex.outreach-canvassing.index', ['tab' => 'activity', 'days' => $filterDays]) }}"
                   class="text-xs underline transition-all duration-300" style="color:var(--text-muted);">Clear</a>
            @endif

            <span class="ml-auto text-xs" style="color:var(--text-muted);">{{ number_format($feedRows->total()) }} action{{ $feedRows->total() === 1 ? '' : 's' }}</span>
        </form>

        {{-- Scroll region — the feed table + pagination scroll; everything above stays put. --}}
        <div class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll mt-4 space-y-5">

        @if($feedRows->total() === 0)
            <div class="rounded-md py-12 px-6 text-center" style="background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-icon,#0ea5e9);">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
                    </svg>
                </div>
                @if($filtersActive)
                    <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary,#111827);">No actions match these filters</h3>
                    <p class="text-sm" style="color:var(--text-muted,#9ca3af);">Try a different search, agent, window or source.</p>
                @else
                    <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary,#111827);">No outreach or canvassing activity yet</h3>
                    <p class="text-sm" style="color:var(--text-muted,#9ca3af);">
                        Claims, pitches and comms-tile messages will appear here as they happen — each tagged with where it came from.
                    </p>
                @endif
            </div>
        @else
            <div class="rounded-md overflow-hidden" style="background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb);">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr style="background:var(--surface-2,#f8fafc); border-bottom:1px solid var(--border,#e5e7eb);">
                                <th class="text-left font-semibold px-4 py-3 whitespace-nowrap" style="color:var(--text-primary,#0b2a4a);">Source</th>
                                <th class="text-left font-semibold px-4 py-3 whitespace-nowrap" style="color:var(--text-primary,#0b2a4a);">Action</th>
                                <th class="text-left font-semibold px-4 py-3 whitespace-nowrap" style="color:var(--text-primary,#0b2a4a);">Who</th>
                                <th class="text-left font-semibold px-4 py-3 whitespace-nowrap" style="color:var(--text-primary,#0b2a4a);">Agent</th>
                                <th class="text-left font-semibold px-4 py-3 whitespace-nowrap" style="color:var(--text-primary,#0b2a4a);">Channel</th>
                                <th class="text-left font-semibold px-4 py-3 whitespace-nowrap" style="color:var(--text-primary,#0b2a4a);">Outcome</th>
                                <th class="text-right font-semibold px-4 py-3 whitespace-nowrap" style="color:var(--text-primary,#0b2a4a);">When</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($feedRows as $r)
                                <tr style="border-bottom:1px solid var(--border,#eef2f6);">
                                    <td class="px-4 py-2.5 whitespace-nowrap">
                                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[0.6875rem] font-semibold"
                                              style="background:color-mix(in srgb, var({{ $sourceTokens[$r['source']] ?? '--border,#e5e7eb' }}) 14%, transparent); color:var({{ $sourceTokens[$r['source']] ?? '--text-secondary,#6b7280' }});">
                                            <span class="inline-block w-1.5 h-1.5 rounded-full" style="background:var({{ $sourceTokens[$r['source']] ?? '--text-secondary,#6b7280' }});"></span>
                                            {{ $r['source_label'] }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2.5 whitespace-nowrap" style="color:var(--text-primary,#0b2a4a);">{{ $r['action'] }}</td>
                                    <td class="px-4 py-2.5 whitespace-nowrap" style="color:var(--text-secondary,#6b7280);">{{ $r['who'] }}</td>
                                    <td class="px-4 py-2.5 whitespace-nowrap" style="color:var(--text-secondary,#6b7280);">{{ $r['agent'] }}</td>
                                    <td class="px-4 py-2.5 whitespace-nowrap" style="color:var(--text-secondary,#6b7280);">{{ $r['channel'] ? ucfirst($r['channel']) : '—' }}</td>
                                    <td class="px-4 py-2.5 whitespace-nowrap" style="color:var(--text-secondary,#6b7280);">{{ $r['outcome'] ?: '—' }}</td>
                                    <td class="px-4 py-2.5 text-right whitespace-nowrap tabular-nums" style="color:var(--text-muted,#9ca3af);"
                                        title="{{ $r['when'] ? \Carbon\Carbon::parse($r['when'])->format('j M Y H:i') : '' }}">
                                        {{ $r['when'] ? \Carbon\Carbon::parse($r['when'])->diffForHumans() : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($feedRows->hasPages())
                <div class="px-4 py-3" style="border-top: 1px solid var(--border,#e5e7eb);">
                    {{ $feedRows->links() }}
                </div>
                @endif
            </div>
            @if(!empty($feed['truncated']))
                <p class="text-xs" style="color:var(--text-muted,#9ca3af);">
                    Showing the most recent actions in this window. Narrow the window or filter by source to see more.
                </p>
            @endif
            <p class="text-xs" style="color:var(--text-muted,#9ca3af);">
                Source tags are derived from durable facts: a pitch counts as <strong>MIC prospecting</strong> when its property
                is a matched prospecting listing, otherwise <strong>Direct contact</strong>; comms-tile quick-sends are their own
                stream. The three are never merged — the total above is the visible sum of the parts.
            </p>
        @endif

        </div>{{-- /scroll region --}}
    </div>

    {{-- ════════════════ TAB 2 — CONSENT FUNNEL (AT-91, as-is) ════════════════ --}}
    <div x-show="tab === 'consent'" x-cloak class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll mt-4">
        @include('corex.outreach-summary._board', ['rows' => $rows, 'totals' => $totals, 'hasAwaiting' => $hasAwaiting, 'embedded' => true])
    </div>
</div>
@endsection
