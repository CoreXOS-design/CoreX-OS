{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
{{-- Layout (2026-09-14, "Checklist + counters" — SPEC_corex_activity_engine.md §6):
     frozen header + week strip + month stats; below them two panes that scroll
     INSIDE themselves at lg+ (the page itself never scrolls):
       LEFT  — the once-a-day activities as a tick list, plus the auto
               Acquired / Pending lists underneath (when there are any);
       RIGHT — the counted activities with a −/+ stepper around the SAME
               number input the form always posted, Save pinned underneath.
     Below lg the panes stack and grow naturally. Nothing the old single table
     captured or showed is gone: every definition still posts values[id] with
     its baseline[id] snapshot; the once-off checkbox keeps its hidden-0 pair.

     M6.5 — Today's achievement headline + auto sections. Provisional points
     display as a SEPARATE figure that does NOT roll into the headline.
     Anti-gaming: a ghost calendar appointment booked to hit a target shows as
     Pending, never inflates the total. The achievement-total scope (manual
     confirmed + auto acquired) is locked in the controller queries; nothing
     here changes that. --}}
@php
    $autoAcquired      = collect($todayAutoAcquired ?? []);
    $autoProvisional   = collect($todayAutoProvisional ?? []);
    $todayManualPoints = (int)($totalPoints ?? 0);
    $todayAcq          = (int)($todayAcquiredPoints ?? 0);
    $todayProv         = (int)($todayProvisionalPoints ?? 0);
    $todayHeadline     = (int)($todayAchievementTotal ?? ($todayManualPoints + $todayAcq));

    $onceDefs  = collect($definitions)->filter(fn ($d) => (string)($d->scoring_mode ?? 'count') === 'once')->values();
    $countDefs = collect($definitions)->filter(fn ($d) => (string)($d->scoring_mode ?? 'count') !== 'once')->values();
    $onceDone  = $onceDefs->filter(fn ($d) => (int)($values[$d->id] ?? 0) > 0)->count();
    $rowShow   = fn ($d) => "!search.trim() || '" . strtolower(addslashes($d->name)) . "'.includes(search.toLowerCase().trim())";
@endphp
<div class="w-full lg:h-full flex flex-col gap-4" x-data="{ search: '' }">

    {{-- ── FROZEN HEADER — flat neutral bar (AT-336) ── --}}
    <div class="corex-page-banner flex-shrink-0" data-tour="at-agent-daily-header">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color:var(--text-primary);">Daily Activity</h1>
                <p class="text-xs" style="color:var(--text-muted);">{{ \Carbon\Carbon::parse($selectedDate)->toFormattedDateString() }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
                <a class="corex-btn-outline text-xs" href="{{ route('agent.daily.summary') }}">&larr; Summary</a>
                <a href="{{ route('agent.daily.print', ['date' => $selectedDate]) }}" target="_blank"
                   class="corex-btn-outline text-xs">
                    Print
                </a>
                <form method="GET" action="{{ route('agent.daily') }}">
                    <input type="date" name="date" value="{{ $selectedDate }}"
                           class="ds-field rounded-md text-xs px-3 py-1.5"
                           style="color-scheme: light dark;"
                           onchange="this.form.submit()" />
                </form>
            </div>
        </div>
    </div>

    {{-- ── Week strip + Monthly stats — 50/50, frozen ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 flex-shrink-0">
        @if(isset($agentDailyWeek) && isset($agentDailyWeek['days']))
            <div class="rounded-md px-3 py-2.5 flex items-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="flex flex-wrap gap-1 w-full">
                    @foreach($agentDailyWeek['days'] as $d)
                        <a href="{{ route('agent.daily', ['date' => $d['date']]) }}"
                           class="ds-daily-chip {{ $d['is_selected'] ? 'ds-daily-chip-active' : '' }} flex-1 text-center px-1.5 py-1.5 rounded-md text-[11px] font-medium whitespace-nowrap">
                            {{ $d['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="rounded-md px-4 py-2.5" data-tour="at-agent-daily-stats" style="background: var(--surface); border: 1px solid var(--border);">
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

    {{-- ── ONE form around both panes — every definition still posts
           values[id] + baseline[id] exactly as the single table did ── --}}
    <form method="POST" action="{{ route('agent.daily') }}"
          class="flex-1 min-h-0 grid grid-cols-1 lg:grid-cols-[440px_minmax(0,1fr)] gap-4">
        @csrf
        <input type="hidden" name="activity_date" value="{{ $selectedDate }}"/>

        {{-- ── LEFT — once-a-day checklist + auto lists ── --}}
        <div class="flex flex-col gap-4 min-h-0">
            <div class="rounded-md flex-1 min-h-0 flex flex-col overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="px-4 py-2.5 flex items-baseline justify-between gap-3 flex-shrink-0" style="border-bottom: 1px solid var(--border);">
                    <span class="text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Once-a-day checklist</span>
                    <span class="text-xs whitespace-nowrap" style="color: var(--text-secondary);">{{ $onceDone }} of {{ $onceDefs->count() }} done</span>
                </div>
                <div class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll p-1.5">
                    @forelse($onceDefs as $def)
                        {{-- Block form on purpose: an inline @php(...) here would pair with the
                             counted-rows @endphp below and swallow that block. --}}
                        @php
                            $val = (int)($values[$def->id] ?? 0);
                        @endphp
                        <label x-show="{{ $rowShow($def) }}"
                               class="ds-daily-check flex items-center gap-3 px-2.5 py-1.5 rounded-md cursor-pointer select-none">
                            {{-- Baseline = the value this row was rendered with. The save
                                 handler applies the form as a diff against this snapshot, so a
                                 stale/cached form (e.g. browser Back) re-posts its baseline
                                 unchanged and never wipes already-saved entries. --}}
                            <input type="hidden" name="baseline[{{ $def->id }}]" value="{{ $val }}">
                            <input type="hidden" name="values[{{ $def->id }}]" value="0">
                            <input type="checkbox" name="values[{{ $def->id }}]" value="1"
                                   @checked($val > 0)
                                   class="h-[18px] w-[18px] rounded flex-shrink-0"
                                   style="accent-color: var(--brand-button, #0ea5e9); border-color: var(--border);" />
                            <span class="flex-1 min-w-0 truncate text-[13px] font-medium" style="color: var(--text-primary);">{{ $def->name }}</span>
                            <span class="text-xs tabular-nums" style="color: var(--text-secondary);">{{ number_format((int)$def->weight) }}</span>
                        </label>
                    @empty
                        <div class="px-3 py-8 text-center text-xs" style="color: var(--text-muted);">No once-a-day activities for your branch.</div>
                    @endforelse
                </div>
            </div>

            @if($autoAcquired->isNotEmpty() || $autoProvisional->isNotEmpty())
                <div class="rounded-md flex-shrink-0 max-h-[40%] flex flex-col overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll">
                        {{-- Auto — Acquired --}}
                        <div class="px-4 py-2">
                            <div class="text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">
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
                        {{-- Auto — Provisional --}}
                        <div class="px-4 py-2" style="border-top: 1px solid var(--border); background: color-mix(in srgb, var(--ds-amber) 4%, transparent);">
                            <div class="text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">
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
                </div>
            @endif
        </div>

        {{-- ── RIGHT — counted activities, list scrolls inside, Save pinned ── --}}
        <div class="rounded-md min-h-0 flex flex-col overflow-hidden" data-tour="at-agent-daily-capture" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="px-4 py-2 flex items-center gap-4 flex-shrink-0" style="border-bottom: 1px solid var(--border);">
                <span class="text-[11px] font-semibold uppercase tracking-wider whitespace-nowrap" style="color: var(--text-muted);">Counted activities</span>
                <div class="relative ml-auto w-full max-w-[260px]" data-tour="at-agent-daily-search">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="w-3.5 h-3.5" style="color: var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>
                    <input type="text"
                           x-model="search"
                           placeholder="Search activities..."
                           class="ds-field-sunken w-full rounded-md pl-9 pr-9 py-1.5 text-xs" />
                    <button type="button" x-show="search.length > 0" x-on:click="search = ''" x-cloak
                            class="absolute inset-y-0 right-0 pr-3 flex items-center cursor-pointer"
                            style="color: var(--text-muted);">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll">
                <table class="min-w-full text-sm ds-table">
                    <thead class="sticky top-0 z-[1]">
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted); background: var(--surface-2);">Activity</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-20" style="color: var(--text-muted); background: var(--surface-2);">Weight</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-36" style="color: var(--text-muted); background: var(--surface-2);">Qty</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider w-20" style="color: var(--text-muted); background: var(--surface-2);">Pts</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($countDefs as $def)
                            @php
                                $val = (int)($values[$def->id] ?? 0);
                                $pts = $val * (int)$def->weight;
                            @endphp
                            <tr x-show="{{ $rowShow($def) }}">
                                <td class="px-4 py-2">
                                    <div class="font-medium text-[13px]" style="color: var(--text-primary);">{{ $def->name }}</div>
                                </td>
                                <td class="px-4 py-2 text-right" style="color: var(--text-secondary);">{{ number_format((int)$def->weight) }}</td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center justify-center">
                                        {{-- Baseline snapshot — see the checklist note above. --}}
                                        <input type="hidden" name="baseline[{{ $def->id }}]" value="{{ $val }}">
                                        {{-- −/+ nudge the SAME number input the form always posted;
                                             typing straight into it still works. --}}
                                        <div class="ds-daily-stepper inline-flex items-stretch rounded-md overflow-hidden" style="background: var(--surface-2); border: 1px solid var(--border);">
                                            <button type="button" tabindex="-1" aria-label="Less"
                                                    class="w-7 text-base leading-none" style="color: var(--text-secondary);"
                                                    x-on:click="const i = $el.parentNode.querySelector('input'); i.value = Math.max(0, (parseInt(i.value) || 0) - 1)">&minus;</button>
                                            <input type="number" min="0" step="1"
                                                   name="values[{{ $def->id }}]" value="{{ $val }}"
                                                   class="ds-field-number w-12 bg-transparent border-0 py-1 text-sm outline-none"
                                                   style="color: var(--text-primary);" />
                                            <button type="button" tabindex="-1" aria-label="More"
                                                    class="w-7 text-base leading-none" style="color: var(--text-secondary);"
                                                    x-on:click="const i = $el.parentNode.querySelector('input'); i.value = (parseInt(i.value) || 0) + 1">+</button>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-2 text-right font-medium" style="color: var(--text-primary);">{{ number_format($pts) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                    @if($onceDefs->isEmpty())
                                        No enabled activity definitions found for your branch.
                                    @else
                                        No counted activities for your branch.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pinned footer: today's figures + Save --}}
            <div class="px-4 py-2.5 flex items-center justify-between gap-4 flex-shrink-0" style="border-top: 1px solid var(--border);">
                <div class="min-w-0">
                    <div class="text-sm">
                        <span class="font-medium" style="color: var(--text-primary);">Today's achievement:</span>
                        <span class="font-bold ml-1" style="color: var(--brand-icon, #0ea5e9);">{{ number_format($todayHeadline) }} pts</span>
                    </div>
                    <div class="text-[11px]" style="color: var(--text-muted);">
                        manual {{ number_format($todayManualPoints) }} &middot; auto acquired {{ number_format($todayAcq) }}
                        @if($todayProv > 0)
                            &middot; <span style="color: var(--ds-amber, #f59e0b);">pending {{ number_format($todayProv) }} (not counted)</span>
                        @endif
                    </div>
                </div>
                <button type="submit" class="corex-btn-primary" data-tour="at-agent-daily-save">Save</button>
            </div>
        </div>
    </form>

</div>
@endsection
