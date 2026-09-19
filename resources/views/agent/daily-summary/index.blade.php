{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
{{-- Layout (2026-09-14, "Checklist + counters" — SPEC_corex_activity_engine.md §6):
     frozen header (range picker unchanged), then two panes that scroll INSIDE
     themselves at lg+ so the page never scrolls:
       LEFT  — the once-a-day activities: days done out of the days in the range;
       RIGHT — the counted activities in the By Activity table, with the three
               totals (count / points / activities tracked) in its header.
     Every figure the stacked page showed is still here: count, manual/auto,
     points, % of points, and the drill-down link on every activity name. --}}
@php
    $all       = collect($items);
    $onceItems = $all->filter(fn ($it) => ($it['scoring_mode'] ?? 'count') === 'once')->values();
    $countItems = $all->filter(fn ($it) => ($it['scoring_mode'] ?? 'count') !== 'once')->values();
    $daysInRange = max(1, (int) $start->diffInDays($end) + 1);
    $drill = fn ($it) => route('agent.daily.summary.activity', array_filter(['definition' => $it['id'], 'range' => $range, 'month' => $month]));
@endphp
<div class="w-full lg:h-full flex flex-col gap-4">

    {{-- ── FROZEN HEADER ── --}}
    <div class="rounded-md px-6 py-5 corex-page-banner flex-shrink-0">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Daily Activity Summary</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    {{ $start->toFormattedDateString() }} &rarr; {{ $end->toFormattedDateString() }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <a class="corex-btn-outline text-xs" href="{{ route('agent.dashboard') }}">&larr; Dashboard</a>

                <form method="GET" action="{{ route('agent.daily.summary') }}" class="flex flex-wrap items-center gap-2">
                    <select name="range" class="list-header-filter">
                        <option value="7d"  {{ $range==='7d' ? 'selected' : '' }}>Last 7 days</option>
                        <option value="month" {{ $range==='month' ? 'selected' : '' }}>This month</option>
                        <option value="3m"  {{ $range==='3m' ? 'selected' : '' }}>Last 3 months</option>
                        <option value="6m"  {{ $range==='6m' ? 'selected' : '' }}>Last 6 months</option>
                        <option value="12m" {{ $range==='12m' ? 'selected' : '' }}>Last 12 months</option>
                    </select>

                    @if($range === 'month')
                        <input type="text" name="month" value="{{ $month ?? '' }}" placeholder="YYYY-MM"
                               class="w-28 list-header-filter placeholder:text-[color:var(--text-faint)]" />
                    @endif

                    <button class="corex-btn-primary text-xs">Apply</button>
                </form>
            </div>
        </div>
    </div>

    <div class="flex-1 min-h-0 grid grid-cols-1 lg:grid-cols-[440px_minmax(0,1fr)] gap-4">

        {{-- ── LEFT — once-a-day checklist: days done in the range ── --}}
        <div class="rounded-md min-h-0 flex flex-col overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="px-4 py-2.5 flex items-baseline justify-between gap-3 flex-shrink-0" style="border-bottom: 1px solid var(--border);">
                <span class="text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Once-a-day checklist</span>
                <span class="text-xs whitespace-nowrap" style="color: var(--text-secondary);">days done / {{ $daysInRange }}</span>
            </div>
            <div class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll p-1.5">
                @forelse($onceItems as $it)
                    @php($donePct = min(100, ($it['count'] / $daysInRange) * 100))
                    <div class="px-2.5 py-2 rounded-md">
                        <div class="flex items-center gap-3">
                            <a class="flex-1 min-w-0 truncate text-[13px] font-medium hover:underline" style="color: var(--brand-icon, #0ea5e9);" href="{{ $drill($it) }}">
                                {{ $it['name'] }}
                            </a>
                            <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold whitespace-nowrap" style="background: var(--surface-2); color: var(--text-primary);">
                                {{ number_format((int)$it['count']) }}
                            </span>
                            <span class="text-xs tabular-nums w-14 text-right whitespace-nowrap" style="color: var(--text-secondary);">{{ number_format((float)$it['points'], 0) }} pts</span>
                        </div>
                        <div class="flex items-center gap-3 mt-1.5">
                            <div class="flex-1 h-1 rounded-full overflow-hidden" style="background: var(--surface-2);">
                                <div class="h-full rounded-full" style="width: {{ $donePct }}%; background: var(--brand-icon, #0ea5e9);"></div>
                            </div>
                            @include('daily-summary._source-badge', ['it' => $it])
                            <span class="text-[11px] tabular-nums w-11 text-right" style="color: var(--text-muted);">{{ number_format((float)$it['pct_points'], 1) }}%</span>
                        </div>
                    </div>
                @empty
                    <div class="px-3 py-8 text-center text-xs" style="color: var(--text-muted);">No once-a-day activities for your branch.</div>
                @endforelse
            </div>
        </div>

        {{-- ── RIGHT — counted activities, totals in the header, table scrolls inside ── --}}
        <div class="rounded-md min-h-0 flex flex-col overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="px-4 py-2 flex flex-wrap items-center gap-x-6 gap-y-1 flex-shrink-0" style="border-bottom: 1px solid var(--border);">
                <div>
                    <span class="text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Counted activities</span>
                    <p class="text-[11px]" style="color: var(--text-muted);">Click an activity name to drill down to your dates list.</p>
                </div>
                <div class="ml-auto flex items-center gap-6">
                    <div>
                        <div class="text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Total count</div>
                        <div class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ number_format((int)$grandCount) }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Total points</div>
                        <div class="text-base font-bold leading-tight" style="color: var(--brand-icon, #0ea5e9);">{{ number_format((float)$grandPoints, 0) }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Activities tracked</div>
                        <div class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ number_format(count($items)) }}</div>
                    </div>
                </div>
            </div>

            <div class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll">
                <table class="min-w-full text-sm ds-table">
                    <thead class="sticky top-0 z-[1]">
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted); background: var(--surface-2);">Activity</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted); background: var(--surface-2);">Count</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted); background: var(--surface-2);">Manual / Auto</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted); background: var(--surface-2);">Points</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted); background: var(--surface-2);">% (Points)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($countItems as $it)
                            <tr>
                                <td class="px-4 py-2 font-medium">
                                    <a class="hover:underline" style="color: var(--brand-icon, #0ea5e9);" href="{{ $drill($it) }}">
                                        {{ $it['name'] }}
                                    </a>
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold whitespace-nowrap" style="background: var(--surface-2); color: var(--text-primary);">
                                        {{ number_format((int)$it['count']) }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-right">
                                    @include('daily-summary._source-badge', ['it' => $it])
                                </td>
                                <td class="px-4 py-2 text-right" style="color: var(--text-secondary);">{{ number_format((float)$it['points'], 0) }}</td>
                                <td class="px-4 py-2 text-right" style="color: var(--text-secondary);">{{ number_format((float)$it['pct_points'], 1) }}%</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                    @if($all->isEmpty())
                                        No activity recorded in this range.
                                    @else
                                        No counted activities for your branch.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
@endsection
