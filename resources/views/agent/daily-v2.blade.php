{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
{{-- SPINE-UI-FIX-2: page flows in NATURAL document height.
     Pre-M6.5 the daily-activity page used a fixed-height container
     (height: calc(100vh - 64px)) with a flex-1 inner scroll wrapper
     around the manual capture table. That layout worked because the
     manual list was the ONLY major section -- it got most of the
     viewport. After M6.5 added the auto Acquired/Pending sections
     above the manual list, the flex distribution carved the manual
     list down to a sliver. Capping the auto sections wasn't enough
     fix -- the manual list still showed only 3-4 rows on a typical
     viewport.

     The right answer is to drop the fixed page height entirely. The
     page now flows in normal document height: header, week strip,
     monthly stats, search, auto sections (at natural size), then
     the FULL manual capture list (every row visible in one
     continuous list), then the manual Save footer at the bottom.
     The page scrolls as a regular document.

     Display layer only -- no total math touched. M6.5's achievement-
     total scope (manual confirmed + auto acquired) is locked in the
     11 retrofitted controller queries; nothing here changes that.
--}}
<div class="w-full space-y-5" x-data="{ search: '' }">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" data-tour="at-agent-daily-header" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <div class="text-sm text-white/60">
                    <a class="hover:underline text-white/60" href="{{ route('agent.daily.summary') }}">&larr; Summary</a>
                </div>
                <h1 class="text-xl font-bold text-white leading-tight mt-1">Daily Activity</h1>
                <p class="text-sm text-white/60">{{ \Carbon\Carbon::parse($selectedDate)->toFormattedDateString() }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher')
                <a href="{{ route('agent.daily.print', ['date' => $selectedDate]) }}" target="_blank"
                   class="rounded-md border border-white/20 bg-white/10 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-white/20">
                    Print
                </a>
                <form method="GET" action="{{ route('agent.daily') }}">
                    <input type="date" name="date" value="{{ $selectedDate }}"
                           class="rounded-md border-0 bg-white/10 text-white text-xs px-3 py-1.5 [color-scheme:dark]"
                           onchange="this.form.submit()" />
                </form>
            </div>
        </div>
    </div>

    {{-- Week strip + Monthly stats — 50/50 split --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {{-- Week strip (left half) --}}
        @if(isset($agentDailyWeek) && isset($agentDailyWeek['days']))
            <div class="rounded-md px-3 py-2.5 flex items-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="flex flex-wrap gap-1 w-full">
                    @foreach($agentDailyWeek['days'] as $d)
                        <a href="{{ route('agent.daily', ['date' => $d['date']]) }}"
                           class="ds-daily-chip {{ $d['is_selected'] ? 'ds-daily-chip-active' : '' }} text-center px-1.5 py-1.5 rounded-md text-[11px] font-medium whitespace-nowrap">
                            {{ $d['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Monthly stats (right half) --}}
        <div class="rounded-md px-4 py-2.5 ds-status-card" data-tour="at-agent-daily-stats" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="grid grid-cols-4 gap-3 h-full items-center">
                <div class="text-center">
                    <div class="text-[11px] font-medium uppercase tracking-wide" style="color: var(--text-muted);">Month</div>
                    <div class="text-base font-bold mt-0.5" style="color: var(--text-primary);">{{ $period }}</div>
                </div>
                <div class="text-center">
                    <div class="text-[11px] font-medium uppercase tracking-wide" style="color: var(--text-muted);">Target</div>
                    <div class="text-base font-bold mt-0.5" style="color: var(--text-primary);">{{ number_format((int)($monthlyTarget ?? 0)) }}</div>
                </div>
                <div class="text-center">
                    <div class="text-[11px] font-medium uppercase tracking-wide" style="color: var(--text-muted);">MTD</div>
                    <div class="text-base font-bold mt-0.5" style="color: var(--brand-icon, #0ea5e9);">{{ number_format((int)($mtdPoints ?? 0)) }}</div>
                </div>
                <div class="text-center">
                    <div class="text-[11px] font-medium uppercase tracking-wide" style="color: var(--text-muted);">Remaining</div>
                    <div class="text-base font-bold mt-0.5" style="color: var(--text-primary);">{{ number_format((int)($remainingPoints ?? 0)) }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Search Bar --}}
    <div class="relative" data-tour="at-agent-daily-search">
        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
            <svg class="w-3.5 h-3.5" style="color: var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </div>
        <input type="text"
               x-model="search"
               placeholder="Search activities..."
               class="ds-field w-full rounded-md pl-9 pr-9 py-2 text-sm" />
        <button type="button" x-show="search.length > 0" x-on:click="search = ''" x-cloak
                class="absolute inset-y-0 right-0 pr-3 flex items-center cursor-pointer"
                style="color: var(--text-muted);">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    {{-- M6.5 — Today's achievement headline + auto sections.
         Provisional points display as a SEPARATE figure that does NOT roll
         into the headline. Anti-gaming: a ghost calendar appointment booked
         to hit a target shows as Pending, never inflates the total. --}}
    @php
        $autoAcquired      = collect($todayAutoAcquired ?? []);
        $autoProvisional   = collect($todayAutoProvisional ?? []);
        $todayManualPoints = (int)($totalPoints ?? 0);
        $todayAcq          = (int)($todayAcquiredPoints ?? 0);
        $todayProv         = (int)($todayProvisionalPoints ?? 0);
        $todayHeadline     = (int)($todayAchievementTotal ?? ($todayManualPoints + $todayAcq));
    @endphp

    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-2.5 flex flex-wrap items-baseline gap-x-6 gap-y-1.5"
             style="border-bottom:1px solid var(--border); background:var(--surface-2);">
            <div>
                <div class="text-[11px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Today's achievement</div>
                <div class="text-base font-bold" style="color:var(--brand-icon, #0ea5e9);">{{ number_format($todayHeadline) }} pts</div>
                <div class="text-[11px]" style="color:var(--text-muted);">manual {{ number_format($todayManualPoints) }} &middot; auto acquired {{ number_format($todayAcq) }}</div>
            </div>
            @if($todayProv > 0)
                <div>
                    <div class="text-[11px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Pending (not counted)</div>
                    <div class="text-base font-bold" style="color: var(--ds-amber, #f59e0b);">{{ number_format($todayProv) }} pts</div>
                    <div class="text-[11px]" style="color:var(--text-muted);">waiting on feedback</div>
                </div>
            @endif
        </div>

        @if($autoAcquired->isNotEmpty() || $autoProvisional->isNotEmpty())
            <div class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0 md:divide-x" style="border-color:var(--border);">
                {{-- Auto — Acquired (natural height, no inner scroll) --}}
                <div class="px-4 py-2">
                    <div class="text-[11px] font-semibold uppercase tracking-wider mb-1.5" style="color:var(--text-muted);">
                        Auto · Acquired ({{ $autoAcquired->count() }})
                    </div>
                    @forelse($autoAcquired as $r)
                        <div class="flex items-center justify-between py-0.5 text-[13px]">
                            <div class="min-w-0">
                                <div class="font-medium truncate" style="color:var(--text-primary);">{{ $r['label'] }}</div>
                                @if(!empty($r['context']))
                                    <div class="text-[11px] truncate" style="color:var(--text-muted);">{{ $r['context'] }}</div>
                                @endif
                            </div>
                            <div class="font-semibold ml-3" style="color:var(--brand-icon, #0ea5e9);">{{ number_format($r['points']) }}</div>
                        </div>
                    @empty
                        <div class="text-xs py-1" style="color:var(--text-muted);">No auto points credited today yet.</div>
                    @endforelse
                </div>

                {{-- Auto — Provisional (natural height, no inner scroll) --}}
                <div class="px-4 py-2" style="background: color-mix(in srgb, var(--ds-amber) 4%, transparent);">
                    <div class="text-[11px] font-semibold uppercase tracking-wider mb-1.5" style="color:var(--text-muted);">
                        Auto · Pending ({{ $autoProvisional->count() }}) — not counted
                    </div>
                    @forelse($autoProvisional as $r)
                        <div class="flex items-center justify-between py-0.5 text-[13px] opacity-80">
                            <div class="min-w-0">
                                <div class="font-medium truncate" style="color:var(--text-primary);">{{ $r['label'] }}</div>
                                <div class="text-[11px] truncate" style="color:var(--text-muted);">
                                    @if(!empty($r['context'])){{ $r['context'] }} &middot; @endif waiting on feedback
                                </div>
                            </div>
                            <div class="font-semibold ml-3" style="color: var(--ds-amber, #f59e0b);">{{ number_format($r['points']) }}</div>
                        </div>
                    @empty
                        <div class="text-xs py-1" style="color:var(--text-muted);">Nothing pending.</div>
                    @endforelse
                </div>
            </div>
        @endif
    </div>

    {{-- Manual capture form — FULL natural height, no inner scroll.
         Every manual activity row renders in one continuous list. The
         page itself scrolls if the list is long. The Save button + the
         "Today (manual)" footer sit at the bottom of the list (normal
         document flow, not sticky). --}}
    <div class="rounded-md overflow-hidden" data-tour="at-agent-daily-capture" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="POST" action="{{ route('agent.daily') }}">
            @csrf
            <input type="hidden" name="activity_date" value="{{ $selectedDate }}"/>

            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Activity</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-20" style="color: var(--text-muted);">Weight</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-32" style="color: var(--text-muted);">Qty</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-20" style="color: var(--text-muted);">Pts</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($definitions as $def)
                        @php
                            $val = (int)($values[$def->id] ?? 0);
                            $pts = $val * (int)$def->weight;
                        @endphp
                        <tr x-show="!search.trim() || '{{ strtolower(addslashes($def->name)) }}'.includes(search.toLowerCase().trim())">
                            <td class="px-4 py-2.5">
                                <div class="font-medium text-sm" style="color: var(--text-primary);">{{ $def->name }}</div>
                            </td>
                            <td class="px-4 py-2.5 text-right" style="color: var(--text-secondary);">{{ number_format((int)$def->weight) }}</td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center justify-center">
                                    @php($mode = (string)($def->scoring_mode ?? 'count'))
                                    @if($mode === 'once')
                                        <input type="hidden" name="values[{{ $def->id }}]" value="0">
                                        <input type="checkbox" name="values[{{ $def->id }}]" value="1"
                                               @checked($val > 0)
                                               class="h-5 w-5 rounded"
                                               style="accent-color: var(--brand-button, #0ea5e9); border-color: var(--border);" />
                                    @else
                                        <input type="number" min="0" step="1"
                                               name="values[{{ $def->id }}]" value="{{ $val }}"
                                               class="ds-field-sunken ds-field-number w-20 rounded-md px-2 py-1.5 text-sm" />
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-2.5 text-right font-medium" style="color: var(--text-primary);">{{ number_format($pts) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                No enabled activity definitions found for your branch.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            {{-- Footer: Today (manual) total + Save. Sits at the end of
                 the list in normal document flow; page scrolling brings
                 it into view after the list. --}}
            <div class="px-4 py-3 flex items-center justify-between" style="border-top: 1px solid var(--border);">
                <div>
                    <span class="text-sm font-medium" style="color: var(--text-primary);">Today (manual):</span>
                    <span class="text-sm font-bold ml-1" style="color: var(--brand-icon, #0ea5e9);">{{ number_format((int)$totalPoints) }} pts</span>
                </div>
                <button type="submit" class="corex-btn-primary" data-tour="at-agent-daily-save">Save</button>
            </div>
        </form>
    </div>

</div>
@endsection
