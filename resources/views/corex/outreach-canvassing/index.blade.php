@extends('layouts.corex')

{{--
    Part 4 — Unified Outreach & Canvassing board.
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md (CoreX tokens; var(--token,#fallback) pattern).

    Tab 1: Activity Feed — every outreach/canvassing action over agent_activity_events,
           source-tagged (MIC prospecting | Direct contact | Comms tile). The three
           streams are counted SEPARATELY (own subtotal each); the total is shown as a
           VISIBLE SUM of the parts so the origin of every WhatsApp is always answerable.
    Tab 2: Consent Funnel — the existing AT-91 WhatsApp matrix, retained as-is.
--}}

@php
    $sourceTokens = [
        'mic_prospect'   => '--ds-green,#059669',
        'direct_contact' => '--brand-icon,#0ea5e9',
        'comms_tile'     => '--ds-orange,#ea580c',
    ];
    $sub = $feed['subtotals'] ?? ['mic_prospect' => 0, 'direct_contact' => 0, 'comms_tile' => 0];
@endphp

@section('corex-content')
<div class="w-full space-y-5" x-data="{ tab: '{{ $activeTab }}' }">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Outreach &amp; Canvassing</h1>
                <p class="text-sm text-white/60">
                    Every canvassing and outreach action in one place — source-tagged so you can always
                    see where a pitch came from. MIC prospecting, direct contact, and comms-tile figures
                    are kept separate and never blended.
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @include('layouts.partials.tour-header-launcher')
            </div>
        </div>
    </div>

    {{-- Tab switcher --}}
    <div class="flex gap-1" style="border-bottom:1px solid var(--border,#e5e7eb);">
        <button @click="tab = 'activity'"
                :style="tab === 'activity' ? 'color:var(--brand-icon,#0ea5e9); border-color:var(--brand-icon,#0ea5e9);' : 'color:var(--text-secondary,#6b7280);'"
                :class="tab === 'activity' ? 'border-b-2' : 'border-b-2 border-transparent'"
                class="px-4 py-2.5 text-sm font-semibold">Activity Feed</button>
        <button @click="tab = 'consent'"
                :style="tab === 'consent' ? 'color:var(--brand-icon,#0ea5e9); border-color:var(--brand-icon,#0ea5e9);' : 'color:var(--text-secondary,#6b7280);'"
                :class="tab === 'consent' ? 'border-b-2' : 'border-b-2 border-transparent'"
                class="px-4 py-2.5 text-sm font-semibold">Consent Funnel</button>
    </div>

    {{-- ════════════════ TAB 1 — ACTIVITY FEED ════════════════ --}}
    <div x-show="tab === 'activity'" x-cloak class="space-y-5">

        {{-- Source subtotals — three SEPARATE streams, never blended. Total = visible sum. --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            @foreach(['mic_prospect','direct_contact','comms_tile'] as $src)
                <a href="{{ route('corex.outreach-canvassing.index', ['tab' => 'activity', 'days' => $filterDays, 'source' => $src]) }}"
                   class="rounded-md px-4 py-3 no-underline transition-all"
                   style="background:var(--surface,#fff); border:1px solid {{ $filterSource === $src ? 'var('.$sourceTokens[$src].')' : 'var(--border,#e5e7eb)' }};">
                    <div class="flex items-center gap-2">
                        <span class="inline-block w-2.5 h-2.5 rounded-full" style="background:var({{ $sourceTokens[$src] }});"></span>
                        <span class="text-xs font-semibold" style="color:var(--text-secondary,#6b7280);">{{ $sourceLabels[$src] }}</span>
                    </div>
                    <div class="text-2xl font-bold mt-1 tabular-nums" style="color:var(--text-primary,#0b2a4a);">{{ number_format($sub[$src] ?? 0) }}</div>
                </a>
            @endforeach

            {{-- Total = a VISIBLE SUM of the three parts (mic + direct + comms-tile). --}}
            <div class="rounded-md px-4 py-3" style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb);">
                <div class="text-xs font-semibold" style="color:var(--text-secondary,#6b7280);">Total actions</div>
                <div class="text-2xl font-bold mt-1 tabular-nums" style="color:var(--text-primary,#0b2a4a);">{{ number_format($feed['total'] ?? 0) }}</div>
                <div class="text-[0.625rem] mt-0.5" style="color:var(--text-muted,#9ca3af);">
                    = {{ number_format($sub['mic_prospect'] ?? 0) }} + {{ number_format($sub['direct_contact'] ?? 0) }} + {{ number_format($sub['comms_tile'] ?? 0) }}
                </div>
            </div>
        </div>

        {{-- Filters --}}
        <form method="GET" action="{{ route('corex.outreach-canvassing.index') }}" class="flex items-center gap-2 flex-wrap">
            <input type="hidden" name="tab" value="activity">
            <label class="text-xs" style="color:var(--text-secondary,#6b7280);">Window</label>
            <select name="days" onchange="this.form.submit()" class="px-3 py-1.5 text-sm rounded-md"
                    style="background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0b2a4a);">
                @foreach([30 => 'Last 30 days', 90 => 'Last 90 days', 180 => 'Last 180 days', 365 => 'Last year'] as $d => $lbl)
                    <option value="{{ $d }}" {{ (int)$filterDays === $d ? 'selected' : '' }}>{{ $lbl }}</option>
                @endforeach
            </select>
            <select name="source" onchange="this.form.submit()" class="px-3 py-1.5 text-sm rounded-md"
                    style="background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0b2a4a);">
                <option value="">All sources</option>
                @foreach($sourceLabels as $sk => $sl)
                    <option value="{{ $sk }}" {{ $filterSource === $sk ? 'selected' : '' }}>{{ $sl }}</option>
                @endforeach
            </select>
            @if($filterSource)
                <a href="{{ route('corex.outreach-canvassing.index', ['tab' => 'activity', 'days' => $filterDays]) }}"
                   class="text-xs font-semibold" style="color:var(--brand-icon,#0ea5e9);">Clear source filter</a>
            @endif
        </form>

        @if(empty($feed['rows']))
            <div class="rounded-md py-12 px-6 text-center" style="background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-icon,#0ea5e9);">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary,#111827);">No outreach or canvassing activity yet</h3>
                <p class="text-sm" style="color:var(--text-muted,#9ca3af);">
                    Claims, pitches and comms-tile messages will appear here as they happen — each tagged with where it came from.
                </p>
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
                            @foreach($feed['rows'] as $r)
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
    </div>

    {{-- ════════════════ TAB 2 — CONSENT FUNNEL (AT-91, as-is) ════════════════ --}}
    <div x-show="tab === 'consent'" x-cloak>
        @include('corex.outreach-summary._board', ['rows' => $rows, 'totals' => $totals, 'hasAwaiting' => $hasAwaiting, 'embedded' => true])
    </div>
</div>
@endsection
