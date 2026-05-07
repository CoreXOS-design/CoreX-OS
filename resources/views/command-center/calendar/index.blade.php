@extends('layouts.corex')

@section('corex-content')
@php
    $today = \Carbon\Carbon::today();

    // Month grid vars (only when month/agenda view)
    if (in_array($currentView, ['month', 'agenda'])) {
        $carbon = \Carbon\Carbon::create($year, $month, 1);
        $monthLabel = $carbon->format('F Y');
        $daysInMonth = $carbon->daysInMonth;
        $firstDayOfWeek = $carbon->dayOfWeekIso;
    }

    // RAG colour classes — solid backgrounds, white text (WCAG AA compliant on any surface)
    $ragChip = [
        'red'     => 'background:#dc2626; color:#ffffff; border-left:2px solid #991b1b;',
        'amber'   => 'background:#d97706; color:#ffffff; border-left:2px solid #92400e;',
        'green'   => 'background:#0d9488; color:#ffffff; border-left:2px solid #115e59;',
        'neutral' => 'background:#475569; color:#ffffff; border-left:2px solid #334155;',
    ];
    $ragDot = [
        'red'     => '#ef4444',
        'amber'   => '#f59e0b',
        'green'   => '#14b8a6',
        'neutral' => '#94a3b8',
    ];
    $defaultChip = 'background:#475569; color:#ffffff; border-left:2px solid #334155;';
    $defaultDot  = '#64748b';

    // Hour grid bounds for week + day views
    $hourGridStart = 6;
    $hourGridEnd   = 20;
    $gridHours     = range($hourGridStart, $hourGridEnd - 1);

    // Classify event as all-day vs timed
    $isAllDayEvent = function ($e) {
        if (!empty($e->all_day)) return true;
        if (str_starts_with((string) ($e->source_type ?? ''), 'synthetic:')) return true;
        return $e->event_date->format('H:i:s') === '00:00:00';
    };

    $eventHour = function ($e) use ($hourGridStart, $hourGridEnd) {
        $h = (int) $e->event_date->format('H');
        return ($h >= $hourGridStart && $h < $hourGridEnd) ? $h : null;
    };
@endphp

<div class="flex flex-col h-full overflow-hidden -m-4 lg:-m-6" x-data="calendarPage()" x-init="initPanel(); if ({{ $autoOpenFeedbackEventId ?? 'null' }}) openFeedbackModal({{ $autoOpenFeedbackEventId ?? 'null' }}); handlePrefill();" @keydown.window="handleShortcut($event)" @mouseup.window="dragEnd()">

    {{-- ══════ HEADER BAND (fixed, never scrolls) ══════ --}}
    <div class="flex-shrink-0 px-4 lg:px-6 pb-3 space-y-3 pt-1" style="background: var(--bg);">

    {{-- ══════ PAGE HEADER (Pattern A — branded) ══════ --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Calendar</h1>
                <p class="text-sm text-white/60">
                    @if($currentView === 'week' && isset($weekStart))
                        Week of {{ $weekStart->format('j M Y') }}
                    @elseif($currentView === 'day' && isset($anchorDate))
                        {{ $anchorDate->format('l, j F Y') }}
                    @elseif(isset($monthLabel))
                        {{ $monthLabel }}
                    @endif
                    — deals, leases, compliance and personal events.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" @click="openBlank()" class="corex-btn-primary">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Add Event
                </button>
            </div>
        </div>
    </div>

    {{-- ══════ TOOLBAR (nav + view switcher) ══════ --}}
    @php
        // View-aware navigation URLs
        if (in_array($currentView, ['week', 'day'])) {
            $prevUrl = route('command-center.calendar', array_merge(request()->only(['scope','types','categories']), ['view' => $currentView, 'date' => $prevAnchor ?? null]));
            $nextUrl = route('command-center.calendar', array_merge(request()->only(['scope','types','categories']), ['view' => $currentView, 'date' => $nextAnchor ?? null]));
            $todayUrl = route('command-center.calendar', ['view' => $currentView]);
            $navLabel = $currentView === 'week'
                ? 'Week of ' . ($weekStart ?? now())->format('j M Y')
                : ($anchorDate ?? now())->format('l, j F Y');
            $showToday = ($currentView === 'day' && isset($anchorDate) && !$anchorDate->isToday())
                      || ($currentView === 'week' && isset($weekStart) && !now()->between($weekStart, $weekEnd ?? now()));
        } else {
            $prevUrl = route('command-center.calendar', ['year' => ($prevMonth ?? now()->subMonth())->year, 'month' => ($prevMonth ?? now()->subMonth())->month, 'view' => $currentView]);
            $nextUrl = route('command-center.calendar', ['year' => ($nextMonth ?? now()->addMonth())->year, 'month' => ($nextMonth ?? now()->addMonth())->month, 'view' => $currentView]);
            $todayUrl = route('command-center.calendar', ['view' => $currentView]);
            $navLabel = $monthLabel ?? now()->format('F Y');
            $showToday = ($year ?? now()->year) !== now()->year || ($month ?? now()->month) !== now()->month;
        }

        // Keyboard shortcut nav URLs
        $kbParams = request()->only(['scope','types','categories']);
        $kbDate = ($anchorDate ?? now())->toDateString();
        $keyboardNavUrls = [
            'today'  => $todayUrl,
            'prev'   => $prevUrl,
            'next'   => $nextUrl,
            'month'  => route('command-center.calendar', array_merge($kbParams, ['view' => 'month', 'date' => $kbDate])),
            'week'   => route('command-center.calendar', array_merge($kbParams, ['view' => 'week', 'date' => $kbDate])),
            'day'    => route('command-center.calendar', array_merge($kbParams, ['view' => 'day', 'date' => $kbDate])),
            'agenda' => route('command-center.calendar', array_merge($kbParams, ['view' => 'agenda', 'date' => $kbDate])),
        ];
    @endphp
    <div class="rounded-md px-4 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3"
         style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex items-center gap-2">
            <a href="{{ $prevUrl }}" class="corex-btn-outline" aria-label="Previous">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
            </a>
            @if($showToday)
                <a href="{{ $todayUrl }}" class="corex-btn-outline">Today</a>
            @else
                <span class="corex-btn-outline opacity-40 cursor-default pointer-events-none" aria-disabled="true">Today</span>
            @endif
            {{-- Clickable date picker label --}}
            <div x-data="{ pickerOpen: false }" class="relative inline-flex">
                <button type="button"
                        @click="pickerOpen = !pickerOpen; if (pickerOpen) $nextTick(() => $refs.calDatePicker.showPicker?.())"
                        class="px-3 py-1.5 rounded text-sm font-semibold transition hover:opacity-80 inline-flex items-center gap-2"
                        style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">
                    {{ $navLabel }}
                    <svg class="w-3.5 h-3.5 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </button>
                <input type="date"
                       x-ref="calDatePicker"
                       class="absolute top-full left-0 mt-1 opacity-0 pointer-events-none h-0 w-0 overflow-hidden"
                       tabindex="-1"
                       value="{{ $anchorDate->toDateString() }}"
                       @change="
                           const d = $event.target.value;
                           if (d) {
                               const params = new URLSearchParams(window.location.search);
                               params.set('date', d);
                               params.set('view', '{{ $currentView }}');
                               params.delete('month');
                               params.delete('year');
                               window.location.href = window.location.pathname + '?' + params.toString();
                           }
                           pickerOpen = false;
                       ">
            </div>
            <a href="{{ $nextUrl }}" class="corex-btn-outline" aria-label="Next">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </a>
        </div>

        <div class="flex items-center gap-2">
            <div class="inline-flex rounded-md overflow-hidden" style="background: var(--surface-2); border: 1px solid var(--border);">
                @foreach(['month' => 'Month', 'week' => 'Week', 'day' => 'Day', 'agenda' => 'Agenda'] as $vKey => $vLabel)
                    <a href="{{ route('command-center.calendar', array_merge(request()->only(['scope','types','categories']), ['view' => $vKey])) }}"
                       class="px-3 py-1.5 text-xs font-semibold transition-colors"
                       style="{{ $currentView === $vKey ? 'background: var(--brand-button); color: #fff;' : 'color: var(--text-secondary);' }}">
                        {{ $vLabel }}
                    </a>
                @endforeach
            </div>
            <button type="button" @click="helpOpen = !helpOpen" title="Keyboard shortcuts (?)"
                    class="px-2 py-1.5 rounded text-xs font-bold transition hover:opacity-80"
                    style="background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border);">
                ?
            </button>
        </div>
    </div>

    {{-- Legend moved to right panel Color By section --}}

    </div>{{-- END sticky header band --}}

    {{-- ══════ FLEX ROW: Calendar grid + Right panel (fills remaining height) ══════ --}}
    <div class="flex gap-0 flex-1 min-h-0 overflow-hidden px-4 lg:px-6">
    {{-- Main calendar column (scrolls independently) --}}
    <div class="flex-1 min-w-0 overflow-y-auto space-y-4 pr-0">

    {{-- ══════ FILTER BAR (compact — panel toggle + active filter summary) ══════ --}}
    <div class="flex items-center gap-3 rounded-md px-4 py-2"
         style="background: var(--surface); border: 1px solid var(--border);">
        {{-- Scope pills (kept inline — primary control) --}}
        <form method="GET" action="{{ route('command-center.calendar') }}" id="calendar-filters" class="flex items-center gap-2">
            <input type="hidden" name="view" value="{{ $currentView }}">
            <input type="hidden" name="month" value="{{ $month ?? now()->month }}">
            <input type="hidden" name="year" value="{{ $year ?? now()->year }}">
            @if(isset($anchorDate))
                <input type="hidden" name="date" value="{{ $anchorDate->toDateString() }}">
            @endif
            <div class="inline-flex rounded-md overflow-hidden" style="background: var(--surface-2); border: 1px solid var(--border);">
                @foreach(['all' => 'All', 'branch' => 'Branch', 'own' => 'Mine'] as $sKey => $sLabel)
                    <label class="cursor-pointer">
                        <input type="radio" name="scope" value="{{ $sKey }}"
                               {{ ($scope ?? 'all') === $sKey ? 'checked' : '' }}
                               onchange="this.form.submit()" class="sr-only peer">
                        <span class="block px-3 py-1.5 text-xs font-semibold transition-colors peer-checked:text-white"
                              style="{{ ($scope ?? 'all') === $sKey ? 'background: var(--brand-button); color: #fff;' : 'color: var(--text-secondary);' }}">
                            {{ $sLabel }}
                        </span>
                    </label>
                @endforeach
            </div>
        </form>

        <div class="flex-1"></div>

        {{-- Active filter badges --}}
        @if(!empty($typeFilter))
            <span class="text-[10px] px-2 py-0.5 rounded-full font-medium" style="background: var(--brand-button); color: #fff;">{{ count($typeFilter) }} types</span>
        @endif
        @if(!empty($categoryFilter))
            <span class="text-[10px] px-2 py-0.5 rounded-full font-medium" style="background: var(--brand-button); color: #fff;">{{ count($categoryFilter) }} classes</span>
        @endif
        @if(!empty($typeFilter) || !empty($categoryFilter) || ($scope ?? 'all') !== 'all')
            <a href="{{ route('command-center.calendar', array_merge(['view' => $currentView], isset($month) ? ['month' => $month, 'year' => $year] : [])) }}"
               class="text-xs font-medium hover:underline" style="color: var(--brand-icon);">Clear</a>
        @endif

        {{-- Panel toggle button --}}
        <button type="button" @click="togglePanel()"
                class="p-1.5 rounded-md transition hover:opacity-80"
                :style="rightPanelOpen ? 'background: var(--brand-button); color: #fff;' : 'background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border);'"
                title="Toggle sidebar panel">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12" />
            </svg>
        </button>
    </div>

    @if($currentView === 'month')
        {{-- ══════ MONTH VIEW ══════ --}}
        @php
            // Build week rows: each row is an array of 7 date strings
            $gridStart = $grid['start'];
            $gridEnd = $grid['end'];
            $weekRows = [];
            $cursor = $gridStart->copy();
            while ($cursor->lte($gridEnd)) {
                $week = [];
                for ($col = 0; $col < 7; $col++) {
                    $week[] = $cursor->copy();
                    $cursor->addDay();
                }
                $weekRows[] = $week;
            }

            // Group spanning bars by week_row
            $barsByWeek = [];
            foreach ($spanningBars ?? [] as $bar) {
                $barsByWeek[$bar['week_row']][] = $bar;
            }

            // Assign vertical slots to spanning bars within each week (interval partitioning)
            $barSlotsByWeek = [];
            foreach ($barsByWeek as $weekIdx => $bars) {
                // Sort by start_col, then by span descending (wider first)
                usort($bars, function ($a, $b) {
                    if ($a['start_col'] !== $b['start_col']) return $a['start_col'] - $b['start_col'];
                    return $b['span'] - $a['span'];
                });
                $slots = []; // array of arrays, each slot = list of bars that fit in that row
                foreach ($bars as $bar) {
                    $placed = false;
                    foreach ($slots as $si => &$slotBars) {
                        $conflict = false;
                        foreach ($slotBars as $existing) {
                            if ($bar['start_col'] <= $existing['end_col'] && $bar['end_col'] >= $existing['start_col']) {
                                $conflict = true;
                                break;
                            }
                        }
                        if (!$conflict) {
                            $bar['slot'] = $si;
                            $slotBars[] = $bar;
                            $placed = true;
                            break;
                        }
                    }
                    unset($slotBars);
                    if (!$placed) {
                        $bar['slot'] = count($slots);
                        $slots[] = [$bar];
                    }
                }
                $barSlotsByWeek[$weekIdx] = $slots;
            }
        @endphp

        <div class="rounded-md overflow-hidden flex flex-col" style="background: var(--surface); border: 1px solid var(--border);">
            {{-- Day headers (sticky) --}}
            <div class="grid grid-cols-7 sticky top-0 z-10" style="background: var(--surface-2); border-bottom: 1px solid var(--border);">
                @foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dayName)
                    <div class="px-2 py-2.5 text-xs font-semibold text-center uppercase tracking-wider"
                         style="color: var(--text-muted); {{ !$loop->last ? 'border-right: 1px solid var(--border);' : '' }}">
                        {{ $dayName }}
                    </div>
                @endforeach
            </div>

            {{-- Calendar grid — scrollable container --}}
            <div class="flex-1">
                @foreach($weekRows as $weekIdx => $weekDates)
                    @php
                        $weekSlots = $barSlotsByWeek[$weekIdx] ?? [];
                        $barCount = count($weekSlots);
                    @endphp
                    {{-- WEEK ROW STRUCTURE — do not change ordering:
                         1. Date numbers strip (7-col grid with day numbers)
                         2. Spanning bar zone (sits INSIDE row, between dates and chips)
                         3. Cell grid with single-day chips

                         Bug history: the bar zone has regressed THREE times when
                         restructured. Bars MUST sit inside the row, between dates
                         and chips. Never above the date numbers. Never in the gap
                         between rows. This ordering is final. --}}
                    <div style="border-bottom: 1px solid var(--border);">

                        {{-- 1. DATE NUMBER STRIP --}}
                        <div class="grid grid-cols-7">
                            @foreach($weekDates as $colIdx => $cellDate)
                                @php
                                    $isCurrentMonth = $cellDate->month === $month;
                                    $isToday = $cellDate->isSameDay($today);
                                    $isWeekend = in_array($cellDate->dayOfWeekIso, [6, 7]);
                                    $dateBg = $isWeekend ? 'var(--surface-2)' : 'transparent';
                                @endphp
                                <div @click="selectedDate = '{{ $cellDate->toDateString() }}'"
                                     class="px-1.5 pt-1 pb-0.5 cursor-pointer"
                                     style="opacity: {{ $isCurrentMonth ? '1' : '0.5' }}; background: {{ $dateBg }}; {{ $colIdx < 6 ? 'border-right: 1px solid var(--border);' : '' }}"
                                     :class="selectedDate === '{{ $cellDate->toDateString() }}' && 'ring-2 ring-inset ring-[#00d4aa]'">
                                    @if($isToday)
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold"
                                              style="background: #00d4aa; color: #0f172a;">
                                            {{ $cellDate->day }}
                                        </span>
                                    @else
                                        <span class="text-xs font-semibold" style="color: var(--text-secondary);">
                                            {{ $cellDate->day }}
                                        </span>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        {{-- 2. SPANNING BAR ZONE (between dates and chips — NEVER move this) --}}
                        @if($barCount > 0)
                            <div class="relative" style="min-height: {{ $barCount * 22 + 4 }}px; padding: 2px 0;">
                                @foreach($weekSlots as $slotIdx => $slotBars)
                                    @foreach($slotBars as $bar)
                                        @php
                                            $barEvt = $bar['event'];
                                            $isInformational = ($barEvt->resolved_colour ?? 'neutral') === 'neutral';
                                            $barBg = $isInformational ? '#0f172a' : match($barEvt->resolved_colour) {
                                                'red'   => '#dc2626',
                                                'amber' => '#d97706',
                                                'green' => '#0d9488',
                                                default => '#0f172a',
                                            };
                                            $barBorder = $isInformational ? '#1e293b' : match($barEvt->resolved_colour) {
                                                'red'   => '#991b1b',
                                                'amber' => '#92400e',
                                                'green' => '#115e59',
                                                default => '#1e293b',
                                            };
                                        @endphp
                                        <button type="button"
                                                data-event-id="{{ $bar['event_id'] }}"
                                                @click.stop="openEventPanel({{ $bar['event_id'] }})"
                                                class="absolute text-[11px] text-white font-medium px-2 truncate hover:opacity-90 transition-opacity cursor-pointer"
                                                style="top: {{ $slotIdx * 22 + 2 }}px; height: 18px; line-height: 18px;
                                                       left: calc(({{ $bar['start_col'] - 1 }} / 7) * 100% + 3px);
                                                       width: calc(({{ $bar['span'] }} / 7) * 100% - 6px);
                                                       background: {{ $barBg }};
                                                       border: 2px solid {{ $barBorder }};
                                                       border-radius: 3px;"
                                                title="{{ $barEvt->title }} ({{ \Carbon\Carbon::parse($bar['start_date'])->format('d M') }}–{{ \Carbon\Carbon::parse($bar['end_date'])->format('d M') }})">
                                            {{ \Illuminate\Support\Str::limit($barEvt->title, 30) }}
                                        </button>
                                    @endforeach
                                @endforeach
                            </div>
                        @endif

                        {{-- 3. CELL GRID (single-day chips only — no date numbers, no bars) --}}
                        <div class="grid grid-cols-7">
                            @foreach($weekDates as $colIdx => $cellDate)
                                @php
                                    $dateStr = $cellDate->toDateString();
                                    $dayEvents = $byDate[$dateStr] ?? [];
                                    $isCurrentMonth = $cellDate->month === $month;
                                    $isWeekend = in_array($cellDate->dayOfWeekIso, [6, 7]);
                                    $cellBg = $isWeekend ? 'var(--surface-2)' : 'transparent';
                                    $cellOpacity = $isCurrentMonth ? '1' : '0.5';
                                    $chipCap = 6;
                                @endphp
                                <div @click="selectedDate = '{{ $dateStr }}'"
                                     @dblclick="window.location.href='{{ route('command-center.calendar', array_merge(request()->only(['scope','types','categories']), ['view' => 'day', 'date' => $dateStr])) }}'"
                                     @dragover.prevent="rescheduleDragOver = '{{ $dateStr }}'"
                                     @drop.prevent="rescheduleDropOnDate('{{ $dateStr }}')"
                                     class="relative min-h-[2.5rem] px-1 pt-0.5 pb-1 cursor-pointer transition-colors hover:brightness-110"
                                     style="opacity: {{ $cellOpacity }}; {{ $colIdx < 6 ? 'border-right: 1px solid var(--border);' : '' }}"
                                     :class="[selectedDate === '{{ $dateStr }}' && 'ring-2 ring-inset ring-[#00d4aa]', rescheduleDragOver === '{{ $dateStr }}' && 'ring-2 ring-inset ring-amber-400']"
                                     :style="selectedDate === '{{ $dateStr }}' ? 'background: color-mix(in srgb, #00d4aa 8%, {{ $cellBg === 'transparent' ? 'var(--surface)' : $cellBg }});' : 'background: {{ $cellBg }};'">
                                    @if(count($dayEvents) > $chipCap)
                                        <div class="flex justify-end mb-0.5">
                                            <span class="text-[10px] px-1.5 py-0.5 rounded font-medium whitespace-nowrap"
                                                  style="background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border);">
                                                +{{ count($dayEvents) - $chipCap }}
                                            </span>
                                        </div>
                                    @endif
                                    <div class="space-y-0.5">
                                        @foreach(array_slice($dayEvents, 0, $chipCap) as $evt)
                                            @php
                                                $chipStyle = $ragChip[$evt->resolved_colour] ?? $defaultChip;
                                                $invStatus = $evt->user_invitation_status ?? null;
                                                $isTentative = $invStatus === 'tentative';
                                                $isPending = $invStatus === 'pending';
                                                if ($isTentative) $chipStyle .= ' border: 2px dashed rgba(255,255,255,0.5); opacity: 0.75;';
                                                if ($isPending) $chipStyle .= ' border: 2px dotted rgba(255,255,255,0.4); opacity: 0.6;';
                                            @endphp
                                            <button type="button"
                                                    data-event-id="{{ $evt->id }}"
                                                    draggable="true"
                                                    @dragstart.stop="rescheduleStartDrag({{ $evt->id }}, '{{ $dateStr }}')"
                                                    @dragend="rescheduleDragOver = null"
                                                    @click.stop="openEventPanel({{ $evt->id }})"
                                                    class="block w-full text-left text-[11px] leading-tight px-1.5 py-0.5 rounded truncate hover:opacity-80 transition-opacity cursor-grab active:cursor-grabbing {{ $evt->status === 'completed' ? 'line-through opacity-70' : '' }}"
                                                    style="{{ $chipStyle }}"
                                                    title="{{ $evt->title }}{{ $isTentative ? ' (Tentative)' : '' }}{{ $isPending ? ' (Pending — accept to confirm)' : '' }}">
                                                <span class="rag-dot w-1.5 h-1.5 rounded-full inline-block mr-0.5 align-middle" style="display:none;"></span>@if($isPending)<span class="text-[9px] font-bold uppercase mr-0.5" style="opacity:0.7;">PENDING</span> @endif{{ $evt->all_day ? '' : $evt->event_date->format('H:i') . ' ' }}{{ \Illuminate\Support\Str::limit($evt->title, $isPending ? 14 : 20) }}
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>

                    </div>
                @endforeach
            </div>
        </div>
    @elseif($currentView === 'week')
        {{-- ══════ WEEK VIEW — Time-slot grid ══════ --}}
        @php
            $weekDaySplits = [];
            foreach ($weekDays as $day) {
                $allDay = collect();
                $timedByHour = [];
                foreach ($day['events'] as $evt) {
                    if ($isAllDayEvent($evt)) {
                        $allDay->push($evt);
                    } else {
                        $h = $eventHour($evt);
                        if ($h === null) {
                            $allDay->push($evt);
                        } else {
                            $timedByHour[$h] ??= collect();
                            $timedByHour[$h]->push($evt);
                        }
                    }
                }
                $weekDaySplits[] = [
                    'date'     => $day['date'],
                    'is_today' => $day['is_today'],
                    'all_day'  => $allDay,
                    'timed'    => $timedByHour,
                ];
            }
            $nowHour = now()->hour;
            $nowMinute = now()->minute;
            $nowOffsetPct = count($gridHours) > 0
                ? (($nowHour - $hourGridStart) + ($nowMinute / 60)) / count($gridHours) * 100
                : 0;
        @endphp

        <div class="rounded-md overflow-hidden overflow-y-auto" style="background: var(--surface); border: 1px solid var(--border); max-height: 70vh;">
            {{-- Day headers (sticky inside week scroll container) --}}
            <div class="grid grid-cols-[56px_repeat(7,1fr)] sticky top-0 z-10" style="border-bottom: 1px solid var(--border); background: var(--surface);">
                <div></div>
                @foreach($weekDaySplits as $day)
                    <a href="{{ route('command-center.calendar', array_merge(request()->only(['scope','types','categories']), ['view' => 'day', 'date' => $day['date']->toDateString()])) }}"
                       class="block text-center py-2 no-underline hover:opacity-80 transition-opacity"
                       style="background: {{ $day['is_today'] ? 'color-mix(in srgb, var(--brand-button) 8%, transparent)' : 'var(--surface)' }}; border-left: 1px solid var(--border);">
                        <div class="text-[10px] uppercase tracking-wider" style="color: var(--text-muted);">{{ $day['date']->format('D') }}</div>
                        <div class="text-lg font-semibold" style="color: {{ $day['is_today'] ? 'var(--brand-button)' : 'var(--text-primary)' }};">{{ $day['date']->format('j') }}</div>
                    </a>
                @endforeach
            </div>

            {{-- All-day swim-lane (spanning bars + single-day all-day chips) --}}
            @php
                $hasSpanningBars = !empty($weekSpanningBars);
                $hasAnyAllDay = collect($weekDaySplits)->contains(fn ($d) => $d['all_day']->isNotEmpty());
                $weekBarCount = count($weekBarSlots ?? []);
            @endphp
            @if($hasSpanningBars || $hasAnyAllDay)
                <div style="border-bottom: 1px solid var(--border); background: var(--surface-2);">
                    {{-- Spanning bars (continuous, not repeated per-day) --}}
                    @if($hasSpanningBars)
                        <div class="grid grid-cols-[56px_1fr]">
                            <div class="text-[10px] uppercase pt-2 pl-1.5" style="color: var(--text-muted);">all day</div>
                            <div class="relative" style="min-height: {{ $weekBarCount * 22 + 4 }}px; padding: 2px 0;">
                                @foreach($weekSpanningBars as $bar)
                                    @php
                                        $barEvt = $bar['event'];
                                        $isInformational = ($barEvt->resolved_colour ?? 'neutral') === 'neutral';
                                        $barBg = $isInformational ? '#0f172a' : match($barEvt->resolved_colour) {
                                            'red'   => '#dc2626',
                                            'amber' => '#d97706',
                                            'green' => '#0d9488',
                                            default => '#0f172a',
                                        };
                                        $barBorder = $isInformational ? '#1e293b' : match($barEvt->resolved_colour) {
                                            'red'   => '#991b1b',
                                            'amber' => '#92400e',
                                            'green' => '#115e59',
                                            default => '#1e293b',
                                        };
                                        $barSlot = $bar['slot'] ?? 0;
                                    @endphp
                                    <button type="button"
                                            data-event-id="{{ $bar['event_id'] }}"
                                            @click.stop="openEventPanel({{ $bar['event_id'] }})"
                                            class="absolute text-[11px] text-white font-medium px-2 truncate hover:opacity-90 transition-opacity cursor-pointer"
                                            style="top: {{ $barSlot * 22 + 2 }}px; height: 18px; line-height: 18px;
                                                   left: calc(({{ $bar['start_col'] - 1 }} / 7) * 100% + 3px);
                                                   width: calc(({{ $bar['span'] }} / 7) * 100% - 6px);
                                                   background: {{ $barBg }};
                                                   border: 2px solid {{ $barBorder }};
                                                   border-radius: 3px;"
                                            title="{{ $barEvt->title }}">
                                        {{ \Illuminate\Support\Str::limit($barEvt->title, 30) }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Single-day all-day events (rendered per-cell below bars) --}}
                    @if($hasAnyAllDay)
                        <div class="grid grid-cols-[56px_repeat(7,1fr)]">
                            <div class="@if(!$hasSpanningBars) text-[10px] uppercase pt-2 pl-1.5 @endif" style="color: var(--text-muted);">
                                @if(!$hasSpanningBars) all day @endif
                            </div>
                            @foreach($weekDaySplits as $day)
                                <div class="px-0.5 py-1 space-y-0.5" style="border-left: 1px solid var(--border);">
                                    @foreach($day['all_day'] as $evt)
                                        @php $chipStyle = $ragChip[$evt->resolved_colour] ?? $defaultChip; @endphp
                                        <button type="button"
                                                data-event-id="{{ $evt->id }}"
                                                @click.stop="openEventPanel({{ $evt->id }})"
                                                class="block w-full text-left px-1.5 py-0.5 rounded text-[10px] truncate transition hover:opacity-80"
                                                style="{{ $chipStyle }}"
                                                title="{{ $evt->title }}">
                                            {{ \Illuminate\Support\Str::limit($evt->title, 18) }}
                                        </button>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            {{-- Hour grid --}}
            <div class="relative">
                {{-- Now-line (only when today is in view and within grid hours) --}}
                @php
                    $todayInWeekView = collect($weekDaySplits)->contains(fn ($d) => $d['is_today']);
                    $nowInRange = $nowHour >= $hourGridStart && $nowHour < $hourGridEnd;
                @endphp
                @if($todayInWeekView && $nowInRange)
                    <div class="absolute left-[56px] right-0 z-10 pointer-events-none"
                         style="top: {{ $nowOffsetPct }}%; border-top: 2px solid #ef4444;">
                        <div class="absolute -top-1.5 -left-1.5 w-3 h-3 rounded-full" style="background: #ef4444;"></div>
                    </div>
                @endif

                @foreach($gridHours as $hour)
                    <div class="grid grid-cols-[56px_repeat(7,1fr)]" style="border-bottom: 1px solid var(--border);">
                        <div class="text-[10px] pt-1 pl-1.5 select-none" style="color: var(--text-muted);">
                            {{ str_pad((string)$hour, 2, '0', STR_PAD_LEFT) }}:00
                        </div>
                        @foreach($weekDaySplits as $day)
                            <div class="min-h-[3rem] relative select-none" style="border-left: 1px solid var(--border); cursor: cell;">
                                {{-- Top half (HH:00-HH:30) --}}
                                <div class="absolute inset-x-0 top-0 h-1/2 z-[1]"
                                     @mousedown="dragStart('{{ $day['date']->toDateString() }}', {{ $hour }}, 0, $event)"
                                     @mousemove="dragMove({{ $hour }}, 0)"
                                     @dragover.prevent
                                     @drop.prevent="rescheduleDrop('{{ $day['date']->toDateString() }}', {{ $hour }}, 0)"></div>
                                {{-- Bottom half (HH:30-HH+1:00) --}}
                                <div class="absolute inset-x-0 top-1/2 h-1/2 z-[1]"
                                     @mousedown="dragStart('{{ $day['date']->toDateString() }}', {{ $hour }}, 1, $event)"
                                     @mousemove="dragMove({{ $hour }}, 1)"
                                     @dragover.prevent
                                     @drop.prevent="rescheduleDrop('{{ $day['date']->toDateString() }}', {{ $hour }}, 1)"></div>
                                {{-- Event chips (above drag layers, pass-through for drop) --}}
                                <div class="relative z-[2] px-0.5 py-0.5 space-y-0.5"
                                     :class="{ 'pointer-events-none': reschedule.dragging }"
                                     @dragover.prevent>
                                    @foreach($day['timed'][$hour] ?? [] as $evt)
                                        @php
                                            $chipStyle = $ragChip[$evt->resolved_colour] ?? $defaultChip;
                                            $isDraggable = in_array($evt->source_type, ['manual', 'manual:demo']);
                                        @endphp
                                        <button type="button"
                                                data-event-id="{{ $evt->id }}"
                                                @click.stop="openEventPanel({{ $evt->id }})"
                                                @mousedown.stop
                                                @if($isDraggable)
                                                    draggable="true"
                                                    @dragstart="rescheduleStart({{ $evt->id }}, '{{ $day['date']->toDateString() }}', $event)"
                                                    @dragend="rescheduleEnd()"
                                                    style="cursor: grab; {{ $chipStyle }}"
                                                @else
                                                    style="{{ $chipStyle }}"
                                                @endif
                                                class="block w-full text-left px-1.5 py-0.5 rounded text-[10px] truncate transition hover:opacity-80"
                                                title="{{ $evt->event_date->format('H:i') }} {{ $evt->title }}">
                                            <span class="opacity-70">{{ $evt->event_date->format('H:i') }}</span>
                                            <span class="font-medium ml-0.5">{{ \Illuminate\Support\Str::limit($evt->title, 14) }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach

                {{-- Drag overlay per day-column --}}
                @foreach($weekDaySplits as $dIdx => $day)
                    <div x-show="drag.active && drag.dayDate === '{{ $day['date']->toDateString() }}'"
                         x-cloak
                         class="absolute pointer-events-none z-[5]"
                         :style="(() => {
                             const ov = dragOverlay('{{ $day['date']->toDateString() }}');
                             if (!ov) return 'display:none';
                             return `top:${ov.top}%;height:${ov.height}%;left:calc(56px + (100% - 56px) * {{ $dIdx }} / 7);width:calc((100% - 56px) / 7);background:rgba(0,212,170,0.2);border:1px solid var(--brand-button);border-radius:4px;`;
                         })()">
                    </div>
                @endforeach
            </div>
        </div>

    @elseif($currentView === 'day')
        {{-- ══════ DAY VIEW — Time-slot grid ══════ --}}
        @php
            $dayAllDay = collect();
            $dayTimedByHour = [];
            foreach ($dayEvents as $evt) {
                if ($isAllDayEvent($evt)) {
                    $dayAllDay->push($evt);
                } else {
                    $h = $eventHour($evt);
                    if ($h === null) {
                        $dayAllDay->push($evt);
                    } else {
                        $dayTimedByHour[$h] ??= collect();
                        $dayTimedByHour[$h]->push($evt);
                    }
                }
            }
            $dayIsToday = $anchorDate->isSameDay(now());
            $dayNowHour = now()->hour;
            $dayNowMinute = now()->minute;
            $dayNowOffsetPct = count($gridHours) > 0
                ? (($dayNowHour - $hourGridStart) + ($dayNowMinute / 60)) / count($gridHours) * 100
                : 0;
            $dayNowInRange = $dayNowHour >= $hourGridStart && $dayNowHour < $hourGridEnd;
        @endphp

        <div class="max-w-3xl mx-auto rounded-md overflow-hidden"
             style="background: var(--surface); border: 1px solid var(--border);">
            {{-- Date header --}}
            <div class="text-center py-3" style="border-bottom: 1px solid var(--border);">
                <div class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">{{ $anchorDate->format('l') }}</div>
                <div class="text-2xl font-semibold" style="color: {{ $dayIsToday ? 'var(--brand-button)' : 'var(--text-primary)' }};">{{ $anchorDate->format('j F Y') }}</div>
            </div>

            {{-- All-day swim-lane --}}
            @if($dayAllDay->isNotEmpty())
                <div class="grid grid-cols-[56px_1fr]" style="border-bottom: 1px solid var(--border); background: var(--surface-2);">
                    <div class="text-[10px] uppercase pt-2 pl-1.5" style="color: var(--text-muted);">all day</div>
                    <div class="p-2 space-y-1" style="border-left: 1px solid var(--border);">
                        @foreach($dayAllDay as $evt)
                            @php
                                $chipStyle = $ragChip[$evt->resolved_colour] ?? $defaultChip;
                                $isMultiDayEvt = $evt->end_date && $evt->end_date->copy()->startOfDay()->gt($evt->event_date->copy()->startOfDay());
                                if ($isMultiDayEvt) {
                                    $isInfo = ($evt->resolved_colour ?? 'neutral') === 'neutral';
                                    $chipStyle = $isInfo
                                        ? 'background:#0f172a; color:#ffffff; border:2px solid #1e293b; border-radius:3px;'
                                        : $chipStyle;
                                }
                            @endphp
                            <button type="button"
                                    data-event-id="{{ $evt->id }}"
                                    @click.stop="openEventPanel({{ $evt->id }})"
                                    class="block w-full text-left px-3 py-2 rounded transition hover:opacity-80"
                                    style="{{ $chipStyle }}">
                                <div class="font-medium text-sm">{{ $evt->title }}</div>
                                <div class="text-[11px] opacity-70 mt-0.5">{{ $evt->category }}</div>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Hour grid --}}
            <div class="relative">
                @if($dayIsToday && $dayNowInRange)
                    <div class="absolute left-[56px] right-0 z-10 pointer-events-none"
                         style="top: {{ $dayNowOffsetPct }}%; border-top: 2px solid #ef4444;">
                        <div class="absolute -top-1.5 -left-1.5 w-3 h-3 rounded-full" style="background: #ef4444;"></div>
                    </div>
                @endif

                @foreach($gridHours as $hour)
                    <div class="grid grid-cols-[56px_1fr]" style="border-bottom: 1px solid var(--border);">
                        <div class="text-[10px] pt-1.5 pl-1.5 select-none" style="color: var(--text-muted);">
                            {{ str_pad((string)$hour, 2, '0', STR_PAD_LEFT) }}:00
                        </div>
                        <div class="min-h-[3.5rem] relative select-none" style="border-left: 1px solid var(--border); cursor: cell;">
                            {{-- Top half --}}
                            <div class="absolute inset-x-0 top-0 h-1/2 z-[1]"
                                 @mousedown="dragStart('{{ $anchorDate->toDateString() }}', {{ $hour }}, 0, $event)"
                                 @mousemove="dragMove({{ $hour }}, 0)"
                                 @dragover.prevent
                                 @drop.prevent="rescheduleDrop('{{ $anchorDate->toDateString() }}', {{ $hour }}, 0)"></div>
                            {{-- Bottom half --}}
                            <div class="absolute inset-x-0 top-1/2 h-1/2 z-[1]"
                                 @mousedown="dragStart('{{ $anchorDate->toDateString() }}', {{ $hour }}, 1, $event)"
                                 @mousemove="dragMove({{ $hour }}, 1)"
                                 @dragover.prevent
                                 @drop.prevent="rescheduleDrop('{{ $anchorDate->toDateString() }}', {{ $hour }}, 1)"></div>
                            {{-- Event chips (pass-through for drop) --}}
                            <div class="relative z-[2] p-1.5 space-y-1"
                                 :class="{ 'pointer-events-none': reschedule.dragging }"
                                 @dragover.prevent>
                                @foreach($dayTimedByHour[$hour] ?? [] as $evt)
                                    @php
                                        $chipStyle = $ragChip[$evt->resolved_colour] ?? $defaultChip;
                                        $isDraggable = in_array($evt->source_type, ['manual', 'manual:demo']);
                                    @endphp
                                    <button type="button"
                                            data-event-id="{{ $evt->id }}"
                                            @click.stop="openEventPanel({{ $evt->id }})"
                                            @mousedown.stop
                                            @if($isDraggable)
                                                draggable="true"
                                                @dragstart="rescheduleStart({{ $evt->id }}, '{{ $anchorDate->toDateString() }}', $event)"
                                                @dragend="rescheduleEnd()"
                                                style="cursor: grab; {{ $chipStyle }}"
                                            @else
                                                style="{{ $chipStyle }}"
                                            @endif
                                            class="block w-full text-left px-3 py-2 rounded transition hover:opacity-80">
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs opacity-80">{{ $evt->event_date->format('H:i') }}</span>
                                            <span class="font-medium text-sm">{{ $evt->title }}</span>
                                        </div>
                                        <div class="text-[11px] opacity-70 mt-0.5">{{ $evt->category }}</div>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach

                {{-- Drag overlay --}}
                <div x-show="drag.active && drag.dayDate === '{{ $anchorDate->toDateString() }}'"
                     x-cloak
                     class="absolute pointer-events-none z-[5]"
                     :style="(() => {
                         const ov = dragOverlay('{{ $anchorDate->toDateString() }}');
                         if (!ov) return 'display:none';
                         return `top:${ov.top}%;height:${ov.height}%;left:56px;right:0;background:rgba(0,212,170,0.2);border:1px solid var(--brand-button);border-radius:4px;`;
                     })()">
                </div>
            </div>

            {{-- Empty state --}}
            @if($dayAllDay->isEmpty() && empty($dayTimedByHour))
                <div class="text-center py-8" style="color: var(--text-muted);">
                    <p>No events on this day.</p>
                </div>
            @endif
        </div>

    @elseif($currentView === 'agenda')
        {{-- ══════ AGENDA VIEW ══════ --}}
        <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
            {{-- Range filter bar --}}
            <form method="GET" action="{{ route('command-center.calendar') }}"
                  class="flex flex-col gap-3 px-4 py-3"
                  style="border-bottom: 1px solid var(--border);">
                <input type="hidden" name="year" value="{{ $year }}">
                <input type="hidden" name="month" value="{{ $month }}">
                <input type="hidden" name="view" value="agenda">

                <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-3">
                    <div class="flex flex-col sm:flex-row sm:flex-wrap sm:items-end gap-3">
                        {{-- Preset --}}
                        <div class="flex flex-col gap-1">
                            <label for="agenda-range" class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Range</label>
                            <select id="agenda-range" name="range" onchange="this.form.submit()" class="list-header-filter">
                                @foreach($rangeGroups as $groupLabel => $opts)
                                    <optgroup label="{{ $groupLabel }}">
                                        @foreach($opts as $rKey => $rLabel)
                                            <option value="{{ $rKey }}" @selected($agendaRange === $rKey)>{{ $rLabel }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>

                        {{-- Custom from/to — editing a date forces range=custom --}}
                        <div class="flex flex-col gap-1">
                            <label for="agenda-from" class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">From</label>
                            <input id="agenda-from" type="date" name="from" value="{{ $agendaFrom }}"
                                   onchange="this.form.querySelector('[name=range]').value='custom'; this.form.submit();"
                                   class="list-header-filter">
                        </div>
                        <div class="flex flex-col gap-1">
                            <label for="agenda-to" class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">To</label>
                            <input id="agenda-to" type="date" name="to" value="{{ $agendaTo }}"
                                   onchange="this.form.querySelector('[name=range]').value='custom'; this.form.submit();"
                                   class="list-header-filter">
                        </div>

                        @if($agendaRange !== 'month' || request('from') || request('to'))
                            <a href="{{ route('command-center.calendar', ['year' => $year, 'month' => $month, 'view' => 'agenda']) }}"
                               class="text-xs font-semibold self-end pb-2 hover:underline"
                               style="color: var(--brand-icon);">
                                Clear
                            </a>
                        @endif
                    </div>

                    <div class="flex items-center gap-3 self-end pb-1">
                        <span class="text-xs" style="color: var(--text-muted);">
                            {{ \Carbon\Carbon::parse($agendaFrom)->format('d M Y') }} — {{ \Carbon\Carbon::parse($agendaTo)->format('d M Y') }}
                        </span>
                        <span class="text-xs font-semibold" style="color: var(--text-primary);">
                            {{ $agendaEvents->count() }} {{ Str::plural('event', $agendaEvents->count()) }}
                        </span>
                    </div>
                </div>
            </form>

            @if($agendaEvents->isEmpty())
                <div class="py-12 px-6 text-center">
                    <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                         style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                    </div>
                    <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No events in {{ strtolower($agendaRangeLabel) }}</h3>
                    <p class="text-sm mb-4" style="color: var(--text-muted);">Expand the range or add an event to plan deals, viewings, compliance reminders and more.</p>
                    <button type="button" @click="openBlank()" class="corex-btn-primary">Add Event</button>
                </div>
            @else
                @php $groupedByDate = $agendaEvents->groupBy(fn ($e) => $e->event_date->toDateString()); @endphp
                <div class="px-4 py-2">
                    @foreach($groupedByDate as $dateKey => $dayEvents)
                        @php $dateObj = \Carbon\Carbon::parse($dateKey); @endphp
                        <div class="py-3" style="{{ !$loop->first ? 'border-top: 1px solid var(--border);' : '' }}">
                            <div class="flex items-center gap-2 mb-2">
                                <a href="{{ route('command-center.calendar', array_merge(request()->only(['scope','types','categories']), ['view' => 'day', 'date' => $dateKey])) }}"
                                   class="text-sm font-semibold no-underline hover:underline"
                                   style="color: {{ $dateObj->isToday() ? 'var(--brand-icon)' : 'var(--text-primary)' }};">
                                    {{ $dateObj->format($dateObj->year === now()->year ? 'D, d M' : 'D, d M Y') }}
                                </a>
                                @if($dateObj->isToday())
                                    <span class="ds-badge ds-badge-info">Today</span>
                                @endif
                            </div>
                            <div class="space-y-1">
                                @foreach($dayEvents as $evt)
                                    @php $dotColour = $ragDot[$evt->resolved_colour] ?? $defaultDot; @endphp
                                    <div class="flex items-center gap-3 py-1.5 px-2 rounded-md transition-colors group cursor-pointer"
                                         style="background: transparent;"
                                         onmouseover="this.style.background='var(--surface-2)'"
                                         onmouseout="this.style.background='transparent'"
                                         @click="openEventPanel({{ $evt->id }})">
                                        <div class="w-1.5 h-6 rounded flex-shrink-0" style="background: {{ $dotColour }};"></div>
                                        <span class="text-xs font-mono flex-shrink-0 whitespace-nowrap" style="color: var(--text-muted); min-width: 3rem;">
                                            {{ $evt->all_day ? 'All day' : $evt->event_date->format('H:i') }}
                                        </span>
                                        @if($evt->property_id)
                                            <a href="{{ route('corex.properties.show', $evt->property_id) }}" class="text-sm flex-1 truncate hover:underline" style="color: var(--text-primary);">
                                                {{ $evt->title }}
                                            </a>
                                        @else
                                            <span class="text-sm flex-1 truncate" style="color: var(--text-primary);">{{ $evt->title }}</span>
                                        @endif
                                        @if($evt->property_id)
                                            <a href="{{ route('corex.properties.show', $evt->property_id) }}"
                                               class="text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded whitespace-nowrap hover:opacity-80 transition-opacity inline-flex items-center gap-1"
                                               style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);"
                                               title="View Property">
                                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3H21m-3.75 3H21" /></svg>
                                                Property
                                            </a>
                                        @endif
                                        <span class="text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded whitespace-nowrap"
                                              style="{{ $ragChip[$evt->resolved_colour] ?? $defaultChip }} border-left:none;">
                                            {{ $evt->category ?? ucfirst($evt->event_type) }}
                                        </span>
                                        <div class="flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                                            <form method="POST" action="{{ route('command-center.calendar.complete', $evt) }}">
                                                @csrf
                                                <button type="submit" class="p-1 rounded transition-colors"
                                                        style="color: var(--ds-green);"
                                                        onmouseover="this.style.background='color-mix(in srgb, var(--ds-green) 12%, transparent)'"
                                                        onmouseout="this.style.background='transparent'"
                                                        title="Mark complete">
                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- ══════ CREATE EVENT MODAL V2 ══════ --}}
    <div x-show="showCreateEvent" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/50" @click="showCreateEvent = false"></div>
        <div class="relative w-full max-w-2xl max-h-[90vh] flex flex-col rounded-md shadow-2xl"
             style="background: var(--surface); border: 1px solid var(--border);">

            {{-- Header --}}
            <div class="px-6 py-4 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
                <h2 class="text-lg font-semibold" style="color: var(--text-primary);" x-text="editMode ? 'Edit Event' : 'New Event'"></h2>
                <button type="button" @click="showCreateEvent = false" class="text-xl leading-none px-2" style="color: var(--text-muted);">&times;</button>
            </div>

            {{-- Body (scrollable) --}}
            <form id="createEventFormV2" method="POST"
                  :action="editMode ? '/corex/command-center/calendar/' + editingEventId : '{{ route('command-center.calendar.store') }}'"
                  class="flex-1 overflow-y-auto px-6 py-4 space-y-4" @submit="submitting = true">
                @csrf
                <template x-if="editMode"><input type="hidden" name="_method" value="PUT"></template>

                {{-- Title --}}
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Title <span style="color:#ef4444">*</span></label>
                    <input type="text" name="title" x-model="form.title" required
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                </div>

                {{-- Category --}}
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Type <span style="color:#ef4444">*</span></label>
                    <select name="category" x-model="form.category" required
                            class="w-full rounded-md px-3 py-2 text-sm"
                            style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <option value="">Select type…</option>
                        @foreach($manualCreatableClasses as $cls)
                            <option value="{{ $cls->event_class }}" data-multi-property="{{ $cls->allow_multiple_properties ? '1' : '0' }}">{{ $cls->label }}</option>
                        @endforeach
                    </select>
                    @php
                        $classConfigMap = $manualCreatableClasses->mapWithKeys(fn($c) => [$c->event_class => [
                            'multi' => (bool) $c->allow_multiple_properties,
                            'actor_role' => $c->actor_role ?? 'neither',
                            'completion' => $c->completion_behaviour ?? 'freeform',
                        ]])->toArray();
                    @endphp
                    <script type="application/json" id="classConfigMap">{!! json_encode($classConfigMap) !!}</script>
                </div>

                {{-- All day toggle --}}
                <div>
                    <label class="inline-flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-secondary);">
                        <input type="checkbox" x-model="form.allDay" class="rounded">
                        All day
                    </label>
                </div>

                {{-- Start date + time --}}
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Start <span style="color:#ef4444">*</span></label>
                    <div class="grid gap-2" :class="form.allDay ? 'grid-cols-1' : 'grid-cols-2'">
                        <input type="date" x-model="form.startDate" @change="onStartDateChange()" required
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <select x-show="!form.allDay" x-model="form.startTime" @change="onStartTimeChange()" required
                                class="w-full rounded-md px-3 py-2 text-sm"
                                style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                            @for($h = 6; $h <= 22; $h++)
                                @foreach([0, 15, 30, 45] as $m)
                                    @php
                                        $val = str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($m, 2, '0', STR_PAD_LEFT);
                                        $display = ($h > 12 ? $h - 12 : ($h === 0 ? 12 : $h)) . ':' . str_pad($m, 2, '0', STR_PAD_LEFT) . ' ' . ($h >= 12 ? 'PM' : 'AM');
                                    @endphp
                                    <option value="{{ $val }}">{{ $display }}</option>
                                @endforeach
                            @endfor
                        </select>
                    </div>
                </div>

                {{-- End date + time (hidden for all-day events) --}}
                <div x-show="!form.allDay">
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">End</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="date" x-model="form.endDate" @change="endManuallyEdited = true"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <select x-model="form.endTime" @change="onEndTimeChange()"
                                class="w-full rounded-md px-3 py-2 text-sm"
                                style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                            <option value="">—</option>
                            @for($h = 6; $h <= 22; $h++)
                                @foreach([0, 15, 30, 45] as $m)
                                    @php
                                        $val = str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($m, 2, '0', STR_PAD_LEFT);
                                        $display = ($h > 12 ? $h - 12 : ($h === 0 ? 12 : $h)) . ':' . str_pad($m, 2, '0', STR_PAD_LEFT) . ' ' . ($h >= 12 ? 'PM' : 'AM');
                                    @endphp
                                    <option value="{{ $val }}">{{ $display }}</option>
                                @endforeach
                            @endfor
                        </select>
                    </div>
                </div>

                {{-- Hidden datetime fields for backend (assembled from split pickers) --}}
                <input type="hidden" name="event_date" :value="computedEventDate">
                <input type="hidden" name="end_date" :value="computedEndDate">

                {{-- Property multi-select (mirrors attendee pattern) --}}
                <div x-data="propertySearch()">
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Properties</label>
                    {{-- Selected property chips --}}
                    <div class="flex flex-wrap gap-1 mb-1.5" x-show="chosen.length > 0">
                        <template x-for="p in chosen" :key="p.id">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs"
                                  style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                <span x-text="p.address" class="truncate max-w-[180px]"></span>
                                <button type="button" @click="remove(p)" class="opacity-60 hover:opacity-100">&times;</button>
                            </span>
                        </template>
                    </div>
                    {{-- Search input --}}
                    <div class="relative">
                        <input type="text" x-model="query" @input.debounce.250ms="search()"
                               placeholder="Search address or suburb…"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <div x-show="results.length > 0" x-cloak
                             class="absolute z-20 left-0 right-0 mt-1 rounded-md max-h-48 overflow-y-auto shadow-lg"
                             style="background: var(--surface); border: 1px solid var(--border);">
                            <template x-for="r in results" :key="r.id">
                                <button type="button" @click="pick(r)"
                                        class="block w-full text-left px-3 py-2 text-sm transition"
                                        style="color: var(--text-primary);"
                                        onmouseover="this.style.background='var(--surface-2)'"
                                        onmouseout="this.style.background='transparent'">
                                    <span x-text="r.address"></span>
                                    <span class="text-xs opacity-60 ml-1" x-text="r.listing_agent_name ? '(' + r.listing_agent_name + ')' : ''"></span>
                                </button>
                            </template>
                        </div>
                        <div x-show="query.length >= 2 && results.length === 0 && !loading" x-cloak
                             class="text-xs mt-1" style="color: var(--text-muted);">No properties found.</div>
                    </div>
                    {{-- Hidden inputs for form submission --}}
                    <template x-for="(p, idx) in chosen" :key="p.id">
                        <input type="hidden" :name="'property_ids[' + idx + ']'" :value="p.id">
                    </template>
                    {{-- Legacy fallback for single property --}}
                    <input type="hidden" name="property_id" :value="chosen.length === 1 ? chosen[0].id : ''">
                </div>

                {{-- Attendees multi-select (contacts + agents) --}}
                <div x-data="contactSearch()" x-ref="attendeePicker">
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Attendees</label>
                    <div class="flex flex-wrap gap-1 mb-1.5">
                        <template x-for="c in chosen" :key="(c.type||'contact') + ':' + c.id">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs"
                                  :style="c.conflict ? 'background: var(--surface-2); border: 2px solid #f59e0b; color: var(--text-primary);' : 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);'"
                                  :title="c.conflictLabel ? '⚠ Conflict: ' + c.conflictLabel : ''">
                                <span class="text-[10px] px-1 py-0.5 rounded font-bold"
                                      :style="c.type === 'agent' ? 'background:#475569;color:#fff' : (c.role === 'seller_contact' ? 'background:#0f172a;color:#fff' : 'background:#00d4aa;color:#fff')"
                                      x-text="c.type === 'agent' ? 'Agent' : (c.role === 'seller_contact' ? 'Seller' : 'Buyer')"></span>
                                <template x-if="c.conflict"><span class="text-[10px]" style="color: #f59e0b;">⚠</span></template>
                                <span x-text="c.name"></span>
                                <button type="button" @click="remove(c)" class="opacity-60 hover:opacity-100">&times;</button>
                            </span>
                        </template>
                    </div>
                    <div class="relative">
                        <input type="text" x-model="query" @input.debounce.250ms="search()"
                               placeholder="Search contacts or agents…"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <div x-show="results.length > 0" x-cloak
                             class="absolute z-20 left-0 right-0 mt-1 rounded-md max-h-48 overflow-y-auto shadow-lg"
                             style="background: var(--surface); border: 1px solid var(--border);">
                            <template x-for="r in results" :key="(r.type||'contact') + ':' + r.id">
                                <button type="button" @click="add(r)"
                                        class="block w-full text-left px-3 py-2 text-sm transition"
                                        style="color: var(--text-primary);"
                                        onmouseover="this.style.background='var(--surface-2)'"
                                        onmouseout="this.style.background='transparent'">
                                    <span x-text="r.name"></span>
                                    <span class="text-[10px] px-1 py-0.5 rounded ml-1"
                                          :style="r.type === 'agent' ? 'background:#0d9488;color:#fff' : 'background:var(--surface-2);color:var(--text-muted)'"
                                          x-text="r.type === 'agent' ? 'agent' : 'contact'"></span>
                                    <span class="text-xs opacity-50 ml-1" x-text="r.phone || r.email || ''"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                    {{-- Submit attendees as indexed array with type --}}
                    <template x-for="(c, idx) in chosen" :key="(c.type||'contact') + ':' + c.id">
                        <div>
                            <input type="hidden" :name="'attendees[' + idx + '][id]'" :value="c.id">
                            <input type="hidden" :name="'attendees[' + idx + '][type]'" :value="c.type || 'contact'">
                            <input type="hidden" :name="'attendees[' + idx + '][role]'" :value="c.role || ''">
                        </div>
                    </template>
                </div>

                {{-- Description --}}
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Description</label>
                    <textarea name="description" x-model="form.description" rows="3"
                              class="w-full rounded-md px-3 py-2 text-sm"
                              style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"></textarea>
                </div>
            </form>

            {{-- Footer --}}
            <div class="px-6 py-4 flex items-center justify-end gap-2" style="border-top: 1px solid var(--border);">
                <button type="button" @click="showCreateEvent = false" class="corex-btn-outline">Cancel</button>
                <button type="submit" form="createEventFormV2" :disabled="submitting"
                        class="corex-btn-primary disabled:opacity-50">
                    <span x-show="!submitting" x-text="editMode ? 'Save Changes' : 'Create Event'"></span>
                    <span x-show="submitting" x-cloak x-text="editMode ? 'Saving…' : 'Creating…'"></span>
                </button>
            </div>
        </div>
    </div>

    {{-- ══════ KEYBOARD SHORTCUT HELP ══════ --}}
    <div x-show="helpOpen" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @click.self="helpOpen = false">
        <div class="absolute inset-0 bg-black/40"></div>
        <div class="relative rounded-md shadow-2xl w-full max-w-sm p-5"
             style="background: var(--surface); border: 1px solid var(--border);">
            <div class="flex items-start justify-between mb-4">
                <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Keyboard shortcuts</h2>
                <button @click="helpOpen = false" class="text-lg leading-none px-1" style="color: var(--text-muted);">&times;</button>
            </div>
            <table class="w-full text-xs">
                @php
                    $shortcuts = [
                        ['T', 'Jump to today'],
                        ['M / W / D / A', 'Switch view'],
                        ['← / →', 'Previous / next period'],
                        ['N', 'New event'],
                        ['Esc', 'Close panel / modal'],
                        ['?', 'Show this help'],
                    ];
                @endphp
                @foreach($shortcuts as [$key, $desc])
                    <tr>
                        <td class="py-1.5 pr-3 align-top">
                            <kbd class="px-1.5 py-0.5 text-[11px] rounded font-mono"
                                 style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">{{ $key }}</kbd>
                        </td>
                        <td class="py-1.5" style="color: var(--text-secondary);">{{ $desc }}</td>
                    </tr>
                @endforeach
            </table>
            <p class="mt-3 text-[11px]" style="color: var(--text-muted);">Disabled while typing in inputs.</p>
        </div>
    </div>

    {{-- ══════ EVENT DETAIL SIDE PANEL (Redesigned) ══════ --}}
    <div x-show="panelOpen" x-cloak class="fixed inset-0 z-40">
        <div class="absolute inset-0 bg-black/40" @click="panelOpen = false"></div>
        <aside class="absolute top-0 right-0 h-full w-full max-w-md flex flex-col shadow-2xl"
               style="background: var(--surface); border-left: 1px solid var(--border);"
               x-transition:enter="transition ease-out duration-200 transform"
               x-transition:enter-start="translate-x-full"
               x-transition:enter-end="translate-x-0"
               x-transition:leave="transition ease-in duration-150 transform"
               x-transition:leave-start="translate-x-0"
               x-transition:leave-end="translate-x-full">

            {{-- Scrollable content --}}
            <div class="flex-1 overflow-y-auto">

                {{-- Header: class label + status + close --}}
                <div class="px-5 pt-4 pb-3 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="text-[10px] font-semibold uppercase tracking-wider" style="color: var(--text-muted);" x-text="panelData.class_label"></span>
                        <span x-show="panelData.colour"
                              class="inline-flex items-center gap-1 px-1.5 py-0.5 text-[10px] font-semibold uppercase"
                              :style="panelColourStyle(panelData.colour)">
                            <span class="w-1.5 h-1.5 rounded-full" :style="'background:' + panelDotHex(panelData.colour)"></span>
                            <span x-text="panelColourLabel(panelData.colour)"></span>
                        </span>
                    </div>
                    <button @click="panelOpen = false" class="p-1 rounded transition-colors" style="color: var(--text-muted);"
                            onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                {{-- Invitation status pill + respond buttons (invitee only) --}}
                <template x-if="panelData.invitation && !panelData.is_organizer">
                    <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);">
                        {{-- Status pill --}}
                        <template x-if="panelData.invitation.status === 'pending'">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded" style="background:rgba(245,158,11,0.15); color:#f59e0b;">Pending</span>
                                <span class="text-xs" style="color:var(--text-muted);">Invitation from <span x-text="panelData.invitation.inviter_name" style="color:var(--text-secondary);"></span></span>
                            </div>
                        </template>
                        <template x-if="panelData.invitation.status === 'tentative'">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded" style="background:rgba(245,158,11,0.15); color:#f59e0b;">Tentative</span>
                                <span class="text-xs" style="color:var(--text-muted);">You marked tentative<template x-if="panelData.invitation.response_at"> on <span x-text="panelData.invitation.response_at"></span></template></span>
                            </div>
                        </template>
                        <template x-if="panelData.invitation.status === 'accepted'">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded" style="background:rgba(16,185,129,0.15); color:#10b981;">Accepted</span>
                                <span class="text-xs" style="color:var(--text-muted);">You accepted this invitation</span>
                            </div>
                        </template>
                        {{-- Respond buttons --}}
                        <template x-if="panelData.invitation.status === 'pending' || panelData.invitation.status === 'tentative'">
                            <div class="flex items-center gap-1.5">
                                <button type="button" @click="respondInvitation('accepted')" class="text-[11px] font-medium px-3 py-1 rounded text-white" style="background:#10b981;">Accept</button>
                                <button type="button" @click="respondInvitation('tentative')" class="text-[11px] font-medium px-3 py-1 rounded" style="background:var(--surface-2); color:#f59e0b; border:1px solid rgba(245,158,11,0.3);">Tentative</button>
                                <button type="button" @click="respondInvitation('declined')" class="text-[11px] font-medium px-3 py-1 rounded" style="background:var(--surface-2); color:#ef4444; border:1px solid rgba(239,68,68,0.3);">Decline</button>
                            </div>
                        </template>
                        <template x-if="panelData.invitation.status === 'accepted'">
                            <button type="button" @click="respondInvitation('pending')" class="text-[10px] underline" style="color:var(--text-muted);">Change response</button>
                        </template>
                    </div>
                </template>

                {{-- Title + date --}}
                <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
                    <h2 class="text-xl font-semibold leading-tight" style="color: var(--text-primary);" x-text="panelData.title"></h2>
                    <p class="text-sm mt-1.5" style="color: var(--text-secondary);" x-text="panelData.event_date_h"></p>
                    <p class="text-xs mt-0.5" style="color: var(--text-muted);" x-text="panelDaysDiffLabel(panelData.days_diff)"></p>
                </div>

                {{-- Linked property --}}
                <template x-if="panelData.linked_property">
                    <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);">
                        <div class="text-[10px] font-semibold uppercase tracking-wider mb-1" style="color: var(--text-muted);">Property</div>
                        <a :href="'/corex/properties/' + panelData.linked_property.id"
                           class="text-sm font-medium transition-colors hover:underline" style="color: var(--brand-button);"
                           x-text="panelData.linked_property.address"></a>
                    </div>
                </template>

                {{-- Attendees --}}
                <template x-if="panelData.attendees && panelData.attendees.length > 0">
                    <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);">
                        <div class="text-[10px] font-semibold uppercase tracking-wider mb-1.5" style="color: var(--text-muted);">Attendees</div>
                        <div class="flex flex-wrap gap-1.5">
                            <template x-for="att in panelData.attendees" :key="(att.type||'contact') + ':' + att.id">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs transition-colors"
                                      style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                                      onmouseover="this.style.background='var(--border)'" onmouseout="this.style.background='var(--surface-2)'">
                                    <span x-text="att.name"></span>
                                    <span x-show="att.type === 'agent'" class="text-[9px] uppercase" style="color: var(--text-muted);">agent</span>
                                </span>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- Description --}}
                <template x-if="panelData.description">
                    <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);">
                        <div class="text-[10px] font-semibold uppercase tracking-wider mb-1" style="color: var(--text-muted);">Description</div>
                        <p class="text-sm leading-relaxed" style="color: var(--text-primary);" x-text="panelData.description"></p>
                    </div>
                </template>

                {{-- Linked Records (grouped by role: Buyers / Sellers / Agents / Properties) --}}
                <template x-if="panelData.linked_records && panelData.linked_records.length > 0">
                    <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);">
                        <template x-for="group in [{key:'buyers',label:'Buyers',color:'#00d4aa'},{key:'sellers',label:'Sellers',color:'#0f172a'},{key:'agents',label:'Agents',color:'#475569'},{key:'properties',label:'Properties',color:'var(--brand-icon)'},{key:'attendees',label:'Attendees',color:'var(--text-muted)'},{key:'deals',label:'Deals',color:'var(--brand-icon)'}]" :key="group.key">
                            <template x-if="panelData.linked_records.filter(r => r.group === group.key).length > 0">
                                <div class="mb-2">
                                    <div class="text-[10px] font-semibold uppercase tracking-wider mb-1" :style="'color:' + group.color" x-text="group.label + ' (' + panelData.linked_records.filter(r => r.group === group.key).length + ')'"></div>
                                    <div class="space-y-1">
                                        <template x-for="rec in panelData.linked_records.filter(r => r.group === group.key)" :key="rec.url + rec.name">
                                            <a :href="rec.url" :target="rec.url === '#' ? '' : '_blank'" rel="noopener"
                                               class="flex items-center gap-2 px-2 py-1 rounded transition hover:opacity-80 no-underline"
                                               style="background: var(--surface-2);">
                                                <template x-if="rec.badge">
                                                    <span class="text-[9px] px-1 py-0.5 rounded font-bold text-white"
                                                          :style="'background:' + (rec.badge === 'Buyer' ? '#00d4aa' : rec.badge === 'Seller' ? '#0f172a' : '#475569')"
                                                          x-text="rec.badge"></span>
                                                </template>
                                                <div class="min-w-0 flex-1">
                                                    <div class="text-[11px] font-medium truncate" style="color: var(--text-primary);" x-text="rec.name"></div>
                                                </div>
                                                <template x-if="rec.url !== '#'">
                                                    <svg class="w-3 h-3 flex-shrink-0 opacity-40" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                                                </template>
                                            </a>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </template>
                    </div>
                </template>

                {{-- Legacy source link fallback (if no linked_records) --}}
                <template x-if="panelData.source_link && (!panelData.linked_records || panelData.linked_records.length === 0)">
                    <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);">
                        <a :href="panelData.source_link.url" target="_blank" class="text-xs font-medium hover:underline" style="color: var(--brand-button);">
                            <span x-text="panelData.source_link.label"></span> &rarr;
                        </a>
                    </div>
                </template>

                {{-- Activity timeline --}}
                <template x-if="panelData.audit_log && panelData.audit_log.length > 0">
                    <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);">
                        <div class="text-[10px] font-semibold uppercase tracking-wider mb-1.5" style="color: var(--text-muted);">Activity</div>
                        <ul class="space-y-1">
                            <template x-for="entry in panelData.audit_log" :key="entry.when + entry.action">
                                <li class="flex justify-between gap-2 text-[11px]">
                                    <span x-text="formatAuditAction(entry)" style="color: var(--text-secondary);"></span>
                                    <span x-text="entry.when" class="whitespace-nowrap" style="color: var(--text-muted);"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </template>

                {{-- Feedback CTA (past actionable events with contacts) --}}
                <template x-if="panelData.is_actionable && panelData.is_past && panelData.has_contacts">
                    <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);">
                        <button type="button" @click="openFeedbackModal(panelData.id)"
                                class="text-xs font-medium transition-colors hover:underline" style="color: var(--brand-button);">
                            Capture feedback &rarr;
                        </button>
                    </div>
                </template>

            </div>

            {{-- Sticky footer action bar --}}
            <div class="px-5 py-2.5 flex items-center gap-4" style="border-top: 1px solid var(--border); background: var(--surface);">
                <template x-if="panelData.is_editable">
                    <button type="button" @click="openEditModal(panelData.id)"
                            class="text-xs font-medium transition-colors hover:opacity-70 inline-flex items-center gap-1"
                            style="color: var(--text-primary);">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Z"/></svg>
                        Edit
                    </button>
                </template>
                {{-- Mark Complete (behaviour-aware) --}}
                <template x-if="panelData.is_actionable && panelData.completion_behaviour === 'require_feedback'">
                    <button type="button" @click="openFeedbackModal(panelData.id)"
                            class="text-xs font-medium transition-colors hover:opacity-70 inline-flex items-center gap-1"
                            style="color: #00d4aa;">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                        Capture Feedback to Complete
                    </button>
                </template>
                <template x-if="panelData.is_actionable && panelData.completion_behaviour === 'require_reason'">
                    <button type="button" @click="reasonPickerAction = 'complete'; reasonPickerEventId = panelData.id; reasonPickerOpen = true"
                            class="text-xs font-medium transition-colors hover:opacity-70 inline-flex items-center gap-1"
                            style="color: var(--text-secondary);">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                        Complete with Reason
                    </button>
                </template>
                <template x-if="panelData.is_actionable && (!panelData.completion_behaviour || panelData.completion_behaviour === 'freeform')">
                    <form :action="'/corex/command-center/calendar/' + panelData.id + '/complete'" method="POST">
                        @csrf
                        {{-- Deal step context badge --}}
                        <template x-if="panelData.metadata && panelData.metadata.deal_ref">
                            <div class="mb-2 px-2 py-1 rounded text-[10px] inline-flex items-center gap-1" style="background:rgba(245,158,11,0.1);color:#f59e0b;border:1px solid rgba(245,158,11,0.2);">
                                <span>Deal Step:</span> <span x-text="(panelData.metadata.step_name || 'Step') + ' — ' + panelData.metadata.deal_ref"></span>
                            </div>
                        </template>
                        <button type="submit" class="text-xs font-medium transition-colors hover:opacity-70 inline-flex items-center gap-1"
                                style="color: var(--text-secondary);">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                            <span x-text="(panelData.metadata && panelData.metadata.deal_ref) ? 'Mark Step Complete' : 'Complete'"></span>
                        </button>
                    </form>
                </template>
                {{-- Dismiss (always requires reason) --}}
                <template x-if="panelData.is_actionable">
                    <button type="button" @click="reasonPickerAction = 'dismiss'; reasonPickerEventId = panelData.id; reasonPickerOpen = true"
                            class="text-xs font-medium transition-colors hover:opacity-70 inline-flex items-center gap-1"
                            style="color: var(--text-muted);">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                        Dismiss
                    </button>
                </template>
            </div>
        </aside>
    </div>

    {{-- ══════ REASON PICKER MODAL (dismiss + require_reason complete) ══════ --}}
    <div x-show="reasonPickerOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/50" @click="reasonPickerOpen = false"></div>
        <div class="relative w-full max-w-sm rounded-md shadow-2xl p-5" style="background: var(--surface); border: 1px solid var(--border);">
            <h3 class="text-sm font-semibold mb-3" style="color: var(--text-primary);"
                x-text="reasonPickerAction === 'dismiss' ? 'Why is this being dismissed?' : 'Why is this being completed?'"></h3>
            <div class="space-y-2 mb-4">
                <template x-for="reason in getReasonOptions()" :key="reason.code">
                    <label class="flex items-center gap-2 px-2 py-1.5 rounded cursor-pointer text-xs" style="color: var(--text-primary);"
                           :style="reasonPickerCode === reason.code ? 'background: var(--surface-2); border: 1px solid var(--brand-button);' : 'background: transparent;'">
                        <input type="radio" :value="reason.code" x-model="reasonPickerCode" class="w-3 h-3">
                        <span x-text="reason.label"></span>
                    </label>
                </template>
            </div>
            <div x-show="reasonPickerCode === 'other'" class="mb-4">
                <textarea x-model="reasonPickerNotes" rows="2" placeholder="Additional details…"
                          class="w-full rounded-md px-3 py-2 text-sm"
                          style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"></textarea>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" @click="reasonPickerOpen = false" class="text-xs px-3 py-1.5 rounded" style="color: var(--text-muted);">Cancel</button>
                <button type="button" @click="submitReasonPicker()" :disabled="!reasonPickerCode || reasonPickerSaving"
                        class="text-xs font-semibold px-3 py-1.5 rounded text-white disabled:opacity-50" style="background: var(--brand-button);">
                    <span x-show="!reasonPickerSaving" x-text="reasonPickerAction === 'dismiss' ? 'Dismiss' : 'Complete'"></span>
                    <span x-show="reasonPickerSaving" x-cloak>Saving…</span>
                </button>
            </div>
        </div>
    </div>

    {{-- ══════ FEEDBACK CAPTURE MODAL ══════ --}}
    <div x-show="feedbackOpen" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/50" @click="feedbackOpen = false"></div>
        <div class="relative w-full max-w-2xl max-h-[90vh] flex flex-col rounded-md shadow-2xl"
             style="background: var(--surface); border: 1px solid var(--border);">

            {{-- Header --}}
            <div class="px-6 py-4 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
                <div>
                    <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Capture Feedback</h2>
                    <p class="text-xs mt-0.5" style="color: var(--text-muted);" x-text="feedbackData.event?.title + ' — ' + feedbackData.event?.date"></p>
                    {{-- Multi-property step indicator --}}
                    <template x-if="feedbackData.is_multi_property && feedbackData.properties.length > 1">
                        <div class="mt-1.5 flex items-center gap-2">
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded" style="background: var(--brand-button); color: #fff;"
                                  x-text="'Property ' + (feedbackPropertyStep + 1) + ' of ' + feedbackData.properties.length"></span>
                            <span class="text-xs font-medium" style="color: var(--text-primary);"
                                  x-text="feedbackData.properties[feedbackPropertyStep]?.address"></span>
                        </div>
                    </template>
                </div>
                <button type="button" @click="feedbackOpen = false" class="text-xl leading-none px-2" style="color: var(--text-muted);">&times;</button>
            </div>

            {{-- Body --}}
            <div class="flex-1 overflow-y-auto px-6 py-4 space-y-6">
                <template x-for="contact in feedbackData.contacts" :key="contact.id">
                    <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                        <h3 class="text-sm font-semibold mb-3" style="color: var(--text-primary);" x-text="contact.label"></h3>

                        {{-- Outcome --}}
                        <div class="mb-3">
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Outcome</label>
                            <select x-model="feedbackForm[contact.id].outcome_id"
                                    class="w-full rounded-md px-3 py-2 text-sm"
                                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                <option value="">Select…</option>
                                <template x-for="o in feedbackData.outcomes" :key="o.id">
                                    <option :value="o.id" x-text="o.label"></option>
                                </template>
                            </select>
                        </div>

                        {{-- Concerns --}}
                        <div class="mb-3">
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Concerns</label>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="c in feedbackData.concerns" :key="c.id">
                                    <label class="inline-flex items-center gap-1.5 text-xs cursor-pointer"
                                           style="color: var(--text-primary);">
                                        <input type="checkbox" :value="c.id"
                                               x-model="feedbackForm[contact.id].concern_ids"
                                               class="rounded">
                                        <span x-text="c.label"></span>
                                    </label>
                                </template>
                            </div>
                        </div>

                        {{-- Seller-visible notes --}}
                        <div class="mb-3">
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Seller-visible notes</label>
                            <textarea x-model="feedbackForm[contact.id].seller_visible_notes" rows="2"
                                      class="w-full rounded-md px-3 py-2 text-sm"
                                      style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                      placeholder="Shown to seller on live link…"></textarea>
                        </div>

                        {{-- Internal notes --}}
                        <div class="mb-3">
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Internal notes</label>
                            <textarea x-model="feedbackForm[contact.id].internal_notes" rows="2"
                                      class="w-full rounded-md px-3 py-2 text-sm"
                                      style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                      placeholder="Agent-only notes…"></textarea>
                        </div>

                        {{-- Next action --}}
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Next action</label>
                            <input type="text" x-model="feedbackForm[contact.id].next_action_notes"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                   placeholder="Follow-up action…">
                        </div>
                    </div>
                </template>
            </div>

            {{-- Footer (step-aware for multi-property) --}}
            <div class="px-6 py-4 flex items-center justify-between gap-2" style="border-top: 1px solid var(--border);">
                <div>
                    <template x-if="feedbackData.is_multi_property && feedbackData.properties.length > 1">
                        <button type="button" @click="skipFeedbackProperty()"
                                class="text-xs font-medium px-3 py-1.5 rounded" style="color: var(--text-muted); background: var(--surface-2); border: 1px solid var(--border);">
                            Skip this property
                        </button>
                    </template>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" @click="feedbackOpen = false" class="corex-btn-outline">Cancel</button>
                    <template x-if="!feedbackData.is_multi_property || feedbackPropertyStep >= feedbackData.properties.length - 1">
                        <button type="button" @click="saveFeedback()" :disabled="feedbackSaving"
                                class="corex-btn-primary disabled:opacity-50">
                            <span x-show="!feedbackSaving">Save Feedback</span>
                            <span x-show="feedbackSaving" x-cloak>Saving…</span>
                        </button>
                    </template>
                    <template x-if="feedbackData.is_multi_property && feedbackPropertyStep < feedbackData.properties.length - 1">
                        <button type="button" @click="saveFeedbackAndNext()" :disabled="feedbackSaving"
                                class="corex-btn-primary disabled:opacity-50">
                            <span x-show="!feedbackSaving">Save & Next Property</span>
                            <span x-show="feedbackSaving" x-cloak>Saving…</span>
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </div>

</div>{{-- END main calendar column --}}

{{-- ══════ RIGHT SIDE PANEL ══════ --}}
<aside x-show="rightPanelOpen" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150"
       class="hidden lg:block flex-shrink-0 relative"
       :style="'width:' + panelWidth + 'px; border-left: 1px solid var(--border); background: var(--surface);'">
    {{-- Drag resize handle (outside content flow, wider hit target) --}}
    <div class="absolute top-0 -left-[3px] w-[6px] h-full cursor-col-resize z-20 group"
         @mousedown.prevent="startPanelResize($event)">
        <div class="absolute top-0 left-[2px] w-[2px] h-full group-hover:bg-blue-500/50 group-active:bg-blue-500/70 transition-colors"></div>
    </div>
    {{-- Scrollable content (no explicit width — fills aside naturally) --}}
    <div class="flex flex-col h-full overflow-y-auto">

        {{-- Panel header --}}
        <div class="flex items-center justify-between px-4 py-3" style="border-bottom: 1px solid var(--border);">
            <span class="text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Panel</span>
            <button type="button" @click="togglePanel()" class="p-1 rounded hover:opacity-70" style="color: var(--text-muted);">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
            </button>
        </div>

        {{-- SECTION 1: Filters --}}
        <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
            <button type="button" @click="panelSection.filters = !panelSection.filters"
                    class="flex items-center justify-between w-full text-xs font-semibold" style="color: var(--text-primary);">
                <span>Filters</span>
                <svg class="w-3.5 h-3.5 transition-transform" :class="panelSection.filters && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
            </button>
            <div x-show="panelSection.filters" x-transition class="mt-3 space-y-3">
                {{-- Event Types --}}
                <form method="GET" action="{{ route('command-center.calendar') }}" id="panel-filter-form">
                    <input type="hidden" name="view" value="{{ $currentView }}">
                    <input type="hidden" name="month" value="{{ $month ?? now()->month }}">
                    <input type="hidden" name="year" value="{{ $year ?? now()->year }}">
                    <input type="hidden" name="scope" value="{{ $scope ?? 'all' }}">
                    @if(isset($anchorDate))
                        <input type="hidden" name="date" value="{{ $anchorDate->toDateString() }}">
                    @endif

                    <div class="mb-3">
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-[11px] font-medium" style="color: var(--text-secondary);">Event Types</span>
                            <span class="flex gap-2">
                                <a href="#" class="text-[10px] hover:underline" style="color: var(--brand-icon);" onclick="event.preventDefault(); document.querySelectorAll('#panel-filter-form input[name=\'types[]\']').forEach(c => c.checked = true); document.getElementById('panel-filter-form').submit();">All</a>
                                <a href="#" class="text-[10px] hover:underline" style="color: var(--brand-icon);" onclick="event.preventDefault(); document.querySelectorAll('#panel-filter-form input[name=\'types[]\']').forEach(c => c.checked = false); document.getElementById('panel-filter-form').submit();">Clear</a>
                            </span>
                        </div>
                        <div class="space-y-1 max-h-40 overflow-y-auto">
                            @foreach($availableTypes as $type)
                                <label class="flex items-center gap-2 px-1.5 py-0.5 rounded text-[11px] cursor-pointer" style="color: var(--text-primary);">
                                    <input type="checkbox" name="types[]" value="{{ $type }}"
                                           {{ empty($typeFilter) || in_array($type, $typeFilter) ? 'checked' : '' }}
                                           onchange="document.getElementById('panel-filter-form').submit()" class="rounded w-3 h-3">
                                    {{ ucfirst($type) }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    {{-- Event Classes --}}
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-[11px] font-medium" style="color: var(--text-secondary);">Event Classes</span>
                            <span class="flex gap-2">
                                <a href="#" class="text-[10px] hover:underline" style="color: var(--brand-icon);" onclick="event.preventDefault(); document.querySelectorAll('#panel-filter-form input[name=\'categories[]\']').forEach(c => c.checked = true); document.getElementById('panel-filter-form').submit();">All</a>
                                <a href="#" class="text-[10px] hover:underline" style="color: var(--brand-icon);" onclick="event.preventDefault(); document.querySelectorAll('#panel-filter-form input[name=\'categories[]\']').forEach(c => c.checked = false); document.getElementById('panel-filter-form').submit();">Clear</a>
                            </span>
                        </div>
                        <div class="space-y-1 max-h-48 overflow-y-auto">
                            @foreach($availableCategories as $cat)
                                @php $swatchColour = ($colourPalettes['class'] ?? [])[$cat->event_class] ?? '#64748b'; @endphp
                                <label class="flex items-center gap-2 px-1.5 py-0.5 rounded text-[11px] cursor-pointer" style="color: var(--text-primary);">
                                    <input type="checkbox" name="categories[]" value="{{ $cat->event_class }}"
                                           {{ empty($categoryFilter) || in_array($cat->event_class, $categoryFilter) ? 'checked' : '' }}
                                           onchange="document.getElementById('panel-filter-form').submit()" class="rounded w-3 h-3">
                                    <span class="w-3 h-3 rounded-full flex-shrink-0" style="background: {{ $swatchColour }};"></span>
                                    {{ $cat->label }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                </form>
            </div>
        </div>

        {{-- SECTION 2: Color By --}}
        <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
            <button type="button" @click="panelSection.colorBy = !panelSection.colorBy"
                    class="flex items-center justify-between w-full text-xs font-semibold" style="color: var(--text-primary);">
                <span>Color By</span>
                <svg class="w-3.5 h-3.5 transition-transform" :class="panelSection.colorBy && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
            </button>
            <div x-show="panelSection.colorBy" x-transition class="mt-3 space-y-2">
                <template x-for="opt in [{v:'rag',l:'Status (RAG)'},{v:'class',l:'Event Class'},{v:'branch',l:'Branch'},{v:'agent',l:'Agent'}]" :key="opt.v">
                    <label class="flex items-center gap-2 text-[11px] cursor-pointer px-1.5 py-1 rounded hover:opacity-80"
                           :style="colorBy === opt.v ? 'background: var(--surface-2); color: var(--text-primary);' : 'color: var(--text-secondary);'">
                        <input type="radio" name="colorBy" :value="opt.v" x-model="colorBy" @change="saveColorBy()" class="w-3 h-3">
                        <span x-text="opt.l" class="font-medium"></span>
                    </label>
                </template>

                {{-- Legend removed — filter swatches serve as legend --}}
            </div>
        </div>

        {{-- SECTION 3: Day Preview --}}
        <div class="px-4 py-3 flex-1 flex flex-col min-h-0">
            <div class="text-xs font-semibold mb-2" style="color: var(--text-primary);">
                <span x-text="selectedDate ? new Date(selectedDate + 'T12:00:00').toLocaleDateString('en-ZA', { weekday:'short', day:'numeric', month:'short', year:'numeric' }) : 'Select a day'"></span>
            </div>
            <div class="flex-1 overflow-y-auto space-y-1 min-h-0" style="max-height: 300px;">
                <template x-if="!selectedDate">
                    <p class="text-[11px] py-4 text-center" style="color: var(--text-muted);">Click a day in the calendar to preview events here.</p>
                </template>
                <template x-if="selectedDate && dayPreviewEvents.length === 0">
                    <div class="text-center py-4">
                        <p class="text-[11px] mb-2" style="color: var(--text-muted);">No events on this day.</p>
                        <button type="button" @click="openForDate(selectedDate)"
                                class="text-[11px] font-medium px-2 py-1 rounded" style="background: var(--brand-button); color: #fff;">+ Add event</button>
                    </div>
                </template>
                <template x-for="evt in dayPreviewEvents" :key="evt.id">
                    <button type="button" @click="openEventPanel(evt.id)"
                            class="w-full text-left flex items-center gap-2 px-2 py-1.5 rounded transition hover:opacity-80"
                            style="background: var(--surface-2);">
                        <span class="w-2 h-2 rounded-full flex-shrink-0" :style="'background:' + ragHex(evt.rag)"></span>
                        <div class="min-w-0 flex-1">
                            <div class="text-[11px] font-medium truncate" style="color: var(--text-primary);" x-text="evt.title"></div>
                            <div class="text-[10px]" style="color: var(--text-muted);" x-text="evt.time + ' · ' + evt.classLabel"></div>
                        </div>
                    </button>
                </template>
            </div>
        </div>
    </div>
</aside>

</div>{{-- END flex row (grid + panel) --}}
</div>{{-- END outer x-data wrapper --}}

<script>
function calendarPage() {
    return {
        showCreateEvent: false,
        form: { title: '', category: '', startDate: '', startTime: '', endDate: '', endTime: '', description: '', allDay: false },
        endManuallyEdited: false,
        selectedDate: '{{ $anchorDate->toDateString() }}',
        editMode: false,
        editingEventId: null,
        submitting: false,
        panelOpen: false,
        panelData: {},
        helpOpen: false,
        drag: { active: false, dayDate: null, startHour: null, startHalf: null, currentHour: null, currentHalf: null },
        reschedule: { dragging: false, eventId: null, originalDate: null },
        rescheduleDragOver: null,
        rescheduleDragEventId: null,
        rescheduleDragFromDate: null,
        feedbackOpen: false,
        feedbackData: { event: null, contacts: [], outcomes: [], concerns: [], properties: [], is_multi_property: false },
        feedbackForm: {},
        feedbackSaving: false,
        feedbackPropertyStep: 0,
        // Reason picker modal (dismiss + require_reason complete)
        reasonPickerOpen: false,
        reasonPickerAction: 'dismiss', // 'dismiss' or 'complete'
        reasonPickerEventId: null,
        reasonPickerCode: '',
        reasonPickerNotes: '',
        reasonPickerSaving: false,

        // Right panel state
        rightPanelOpen: false,
        panelWidth: 360,
        panelResizing: false,
        panelSection: { filters: true, colorBy: true },
        colorBy: 'rag',

        // Colour data from server (no round-trip needed for color-by switch)
        colourMap: {!! json_encode($colourMap ?? new stdClass()) !!},
        colourPalettes: {!! json_encode($colourPalettes ?? ['class'=>new stdClass(),'branch'=>new stdClass(),'agent'=>new stdClass()]) !!},
        classLabels: {!! json_encode($classLabels ?? new stdClass()) !!},
        branchLabels: {!! json_encode($branchLabels ?? new stdClass()) !!},
        agentLabels: {!! json_encode($agentLabels ?? new stdClass()) !!},

        // Day preview data (built from server-rendered events)
        @php
            // Build combined events-by-date for day preview (single-day + spanning)
            $previewByDate = [];
            foreach ($byDate ?? [] as $dateKey => $evts) {
                foreach ($evts as $e) {
                    $previewByDate[$dateKey][] = [
                        'id' => $e->id, 'title' => $e->title,
                        'time' => $e->all_day ? 'All day' : $e->event_date->format('H:i'),
                        'rag' => $e->resolved_colour ?? 'neutral',
                        'classLabel' => $e->category ?? '',
                    ];
                }
            }
            // Add spanning bar events to each date they cover
            foreach ($spanningBars ?? [] as $bar) {
                $c = \Carbon\Carbon::parse($bar['start_date']);
                $end = \Carbon\Carbon::parse($bar['end_date']);
                while ($c->lte($end)) {
                    $ds = $c->toDateString();
                    $previewByDate[$ds][] = [
                        'id' => $bar['event_id'], 'title' => $bar['title'],
                        'time' => 'All day',
                        'rag' => $bar['event']->resolved_colour ?? 'neutral',
                        'classLabel' => $bar['event']->category ?? '',
                    ];
                    $c->addDay();
                }
            }
        @endphp
        allEventsByDate: {!! json_encode($previewByDate) !!},

        get dayPreviewEvents() {
            if (!this.selectedDate) return [];
            return this.allEventsByDate[this.selectedDate] || [];
        },

        // Navigation URLs for keyboard shortcuts (rendered by Blade)
        navUrls: {!! json_encode($keyboardNavUrls) !!},

        handleShortcut(e) {
            const tag = (e.target.tagName || '').toUpperCase();
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag)) return;
            if (e.target.isContentEditable) return;
            if (e.ctrlKey || e.metaKey || e.altKey) return;

            const key = e.key;

            if (key === 'Escape') {
                if (this.panelOpen)       { this.panelOpen = false; e.preventDefault(); return; }
                if (this.showCreateEvent) { this.showCreateEvent = false; e.preventDefault(); return; }
                if (this.helpOpen)        { this.helpOpen = false; e.preventDefault(); return; }
                if (this.selectedDate)    { this.selectedDate = null; e.preventDefault(); return; }
                return;
            }

            const k = key.toLowerCase();
            const nav = {
                't': this.navUrls.today,
                'm': this.navUrls.month,
                'w': this.navUrls.week,
                'd': this.navUrls.day,
                'a': this.navUrls.agenda,
            };

            if (nav[k]) { window.location.href = nav[k]; e.preventDefault(); return; }
            if (key === 'ArrowLeft'  && this.navUrls.prev) { window.location.href = this.navUrls.prev; e.preventDefault(); return; }
            if (key === 'ArrowRight' && this.navUrls.next) { window.location.href = this.navUrls.next; e.preventDefault(); return; }
            if (k === 'n') { this.openBlank(); e.preventDefault(); return; }
            if (k === '?') { this.helpOpen = !this.helpOpen; e.preventDefault(); return; }
        },

        openForDate(dateStr) {
            const nextQ = this.nextQuarterHour();
            this.form = { title: '', category: '', startDate: dateStr, startTime: nextQ, endDate: dateStr, endTime: this.addHour(nextQ), description: '', allDay: false };
            this.endManuallyEdited = false;
            this.editMode = false;
            this.editingEventId = null;
            this.submitting = false;
            this.showCreateEvent = true;
        },
        openBlank() {
            const today = new Date().toISOString().slice(0, 10);
            const dateToUse = this.selectedDate || today;
            const nextQ = this.nextQuarterHour();
            this.form = { title: '', category: '', startDate: dateToUse, startTime: nextQ, endDate: dateToUse, endTime: this.addHour(nextQ), description: '', allDay: false };
            this.endManuallyEdited = false;
            this.editMode = false;
            this.editingEventId = null;
            this.submitting = false;
            this.showCreateEvent = true;
        },

        // ── Prefill from URL params (Schedule from Contact/Buyer) ──
        handlePrefill() {
            const params = new URLSearchParams(window.location.search);
            const prefillContactId = params.get('prefill_contact_id');
            const prefillClass = params.get('prefill_class');
            if (!prefillContactId) return;

            this.$nextTick(() => {
                const today = new Date().toISOString().slice(0, 10);
                this.form = {
                    title: '',
                    category: prefillClass || 'viewing',
                    startDate: today,
                    startTime: prefillClass === 'viewing' ? '14:00' : '09:00',
                    endDate: today,
                    endTime: prefillClass === 'viewing' ? '15:00' : '10:00',
                    description: '',
                    allDay: false,
                };
                this.editMode = false;
                this.editingEventId = null;
                this.showCreateEvent = true;

                // Fetch contact by ID and pre-populate
                this.$nextTick(async () => {
                    try {
                        // Search by ID directly (attendee search works by name; use contact endpoint)
                        const r = await fetch('/corex/contacts/' + prefillContactId, {
                            headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
                        });
                        if (r.ok) {
                            const c = await r.json();
                            const match = {
                                id: c.id || parseInt(prefillContactId),
                                name: (c.first_name || '') + ' ' + (c.last_name || ''),
                                type: 'contact',
                                role: prefillClass === 'viewing' ? 'buyer_contact' : 'attendee',
                                phone: c.phone || null,
                                email: c.email || null,
                            };
                            const form = document.getElementById('createEventFormV2');
                            const picker = form?.querySelector('[x-ref="attendeePicker"]');
                            if (picker) {
                                Alpine.$data(picker).chosen = [match];
                            }
                            // Auto-fill title
                            if (prefillClass === 'viewing' && match.name.trim()) {
                                this.form.title = 'Viewing with ' + match.name.trim();
                            }
                        } else {
                            // Fallback: try attendee search
                            const r2 = await fetch('/corex/command-center/calendar/search/attendees?q=' + prefillContactId, {
                                headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
                            });
                            if (!r2.ok) return;
                            const contacts = await r2.json();
                            const fallback = contacts.find(c => String(c.id) === prefillContactId && c.type !== 'agent');
                            if (fallback) {
                                fallback.role = prefillClass === 'viewing' ? 'buyer_contact' : 'attendee';
                                const form = document.getElementById('createEventFormV2');
                                const picker = form?.querySelector('[x-ref="attendeePicker"]');
                                if (picker) Alpine.$data(picker).chosen = [fallback];
                                if (prefillClass === 'viewing' && fallback.name) {
                                    this.form.title = 'Viewing with ' + fallback.name;
                                }
                            }
                        }
                    } catch (e) { console.warn('Prefill contact failed:', e); }
                });
            });
        },

        // ── Right Panel ──
        initPanel() {
            // Default: hidden on first visit. Only show if user previously opened it.
            const stored = localStorage.getItem('corex.calendar.panelOpen');
            this.rightPanelOpen = stored === '1';

            // Restore saved width
            const w = parseInt(localStorage.getItem('corex.calendar.panelWidth'));
            if (w && w >= 280 && w <= 600) {
                this.panelWidth = w;
            }

            const cb = localStorage.getItem('corex.calendar.colorBy');
            if (cb && ['rag','class','branch','agent'].includes(cb)) {
                this.colorBy = cb;
            }

            // Apply color-by on load if non-default
            if (this.colorBy !== 'rag') {
                this.$nextTick(() => this.recolourChips());
            }
        },
        togglePanel() {
            this.rightPanelOpen = !this.rightPanelOpen;
            localStorage.setItem('corex.calendar.panelOpen', this.rightPanelOpen ? '1' : '0');
        },
        startPanelResize(e) {
            this.panelResizing = true;
            const startX = e.clientX;
            const startW = this.panelWidth;
            const maxW = Math.min(600, window.innerWidth * 0.4);

            const onMove = (ev) => {
                const delta = startX - ev.clientX; // dragging left = wider
                const newW = Math.max(280, Math.min(maxW, startW + delta));
                this.panelWidth = newW;
            };
            const onUp = () => {
                this.panelResizing = false;
                localStorage.setItem('corex.calendar.panelWidth', String(this.panelWidth));
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
            };
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        },
        saveColorBy() {
            localStorage.setItem('corex.calendar.colorBy', this.colorBy);
            this.recolourChips();
        },
        ragHex(colour) {
            return { red: '#ef4444', amber: '#f59e0b', green: '#14b8a6', neutral: '#94a3b8' }[colour] || '#64748b';
        },
        recolourChips() {
            // Recolour all event chips and spanning bars based on colorBy mode
            const map = this.colourMap;
            const palettes = this.colourPalettes;
            const ragMap = { red: '#dc2626', amber: '#d97706', green: '#0d9488', neutral: '#475569' };
            const ragHexMap = { red: '#ef4444', amber: '#f59e0b', green: '#14b8a6', neutral: '#94a3b8' };
            const isRag = this.colorBy === 'rag';

            document.querySelectorAll('[data-event-id]').forEach(el => {
                const eid = el.dataset.eventId;
                const meta = map[eid];
                if (!meta) return;

                let bg;
                if (isRag) {
                    bg = ragMap[meta.rag] || '#475569';
                } else if (this.colorBy === 'class') {
                    bg = (palettes.class || {})[meta.class] || '#475569';
                } else if (this.colorBy === 'branch') {
                    bg = (palettes.branch || {})[meta.branch] || '#475569';
                } else if (this.colorBy === 'agent') {
                    bg = (palettes.agent || {})[meta.agent] || '#475569';
                }

                if (bg) {
                    el.style.background = bg;
                    // RAG stripe: 12px solid left border in RAG colour when non-RAG mode
                    if (isRag) {
                        el.style.borderLeft = '2px solid ' + (ragMap[meta.rag] || '#334155');
                    } else {
                        el.style.borderLeft = '12px solid ' + (ragHexMap[meta.rag] || '#64748b');
                    }
                }

                // Show/hide RAG dot when not in RAG mode
                const dot = el.querySelector('.rag-dot');
                if (dot) {
                    dot.style.display = 'none'; // stripe replaces the dot
                }
            });
        },

        nextQuarterHour() {
            const now = new Date();
            let m = Math.ceil(now.getMinutes() / 15) * 15;
            let h = now.getHours();
            if (m >= 60) { m = 0; h++; }
            if (h >= 22) { h = 9; m = 0; }
            return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
        },
        addHour(timeStr) {
            if (!timeStr) return '';
            const [h, m] = timeStr.split(':').map(Number);
            const nh = h + 1 > 22 ? 22 : h + 1;
            return String(nh).padStart(2, '0') + ':' + String(m).padStart(2, '0');
        },
        onStartDateChange() {
            if (!this.endManuallyEdited && this.form.startDate) {
                this.form.endDate = this.form.startDate;
            }
        },
        onStartTimeChange() {
            if (!this.endManuallyEdited && this.form.startTime) {
                this.form.endTime = this.addHour(this.form.startTime);
                if (!this.form.endDate) this.form.endDate = this.form.startDate;
            }
        },
        onEndTimeChange() {
            this.endManuallyEdited = true;
        },
        get computedEventDate() {
            if (!this.form.startDate) return '';
            if (this.form.allDay) return this.form.startDate + 'T00:00';
            return this.form.startDate + 'T' + (this.form.startTime || '09:00');
        },
        get computedEndDate() {
            if (this.form.allDay) return '';
            if (!this.form.endDate || !this.form.endTime) return '';
            return this.form.endDate + 'T' + this.form.endTime;
        },

        async openEditModal(eventId) {
            const r = await fetch('/corex/command-center/calendar/' + eventId, {
                headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
            });
            if (!r.ok) return;
            const d = await r.json();

            // Populate form with split date/time fields
            const ed = d.event_date ? new Date(d.event_date) : null;
            const endD = d.end_date ? new Date(d.end_date) : null;
            const toDate = (dt) => dt ? dt.getFullYear() + '-' + String(dt.getMonth()+1).padStart(2,'0') + '-' + String(dt.getDate()).padStart(2,'0') : '';
            const toTime = (dt) => dt ? String(dt.getHours()).padStart(2,'0') + ':' + String(Math.floor(dt.getMinutes()/15)*15).padStart(2,'0') : '';

            const isAllDay = ed && ed.getHours() === 0 && ed.getMinutes() === 0 && !endD;
            this.form = {
                title: d.title || '',
                category: d.category || '',
                startDate: toDate(ed),
                startTime: toTime(ed) || '09:00',
                endDate: toDate(endD) || toDate(ed),
                endTime: toTime(endD) || '',
                description: d.description || '',
                allDay: isAllDay,
            };
            this.endManuallyEdited = !!endD;
            this.editMode = true;
            this.editingEventId = eventId;
            this.submitting = false;
            this.panelOpen = false;
            this.showCreateEvent = true;

            // Pre-populate property + attendees after modal renders
            this.$nextTick(() => {
                const form = document.getElementById('createEventFormV2');
                if (!form) return;

                // Property (multi-select: load all linked properties into chosen[])
                const propPicker = form.querySelector('[x-data*="propertySearch"]');
                if (propPicker) {
                    const propData = Alpine.$data(propPicker);
                    if (d.linked_properties && d.linked_properties.length > 0) {
                        propData.chosen = d.linked_properties.map(p => ({ id: p.id, address: p.address }));
                    } else if (d.linked_property) {
                        propData.chosen = [{ id: d.linked_property.id, address: d.linked_property.address }];
                    }
                }

                // Attendees
                const attPicker = form.querySelector('[x-ref="attendeePicker"]');
                if (attPicker && d.attendees && d.attendees.length) {
                    Alpine.$data(attPicker).chosen = d.attendees;
                }
            });
        },

        // Drag-to-create on time grid
        dragStart(dayDate, hour, half, e) {
            if (e.target.closest('button') || e.target.closest('a')) return;
            this.drag = { active: true, dayDate, startHour: hour, startHalf: half, currentHour: hour, currentHalf: half };
            e.preventDefault();
        },
        dragMove(hour, half) {
            if (!this.drag.active) return;
            this.drag.currentHour = hour;
            this.drag.currentHalf = half;
        },
        dragEnd() {
            if (!this.drag.active) return;
            const d = this.drag;
            const startMin = d.startHour * 60 + d.startHalf * 30;
            const endMin = d.currentHour * 60 + d.currentHalf * 30 + 30;
            let s = Math.min(startMin, endMin);
            let e = Math.max(startMin, endMin);
            if (e - s < 30) e = s + 60;
            const pad = n => n.toString().padStart(2, '0');
            const fmt = m => pad(Math.floor(m / 60)) + ':' + pad(m % 60);
            this.drag.active = false;
            this.openBlank();
            this.form.startDate = d.dayDate;
            this.form.startTime = fmt(s);
            this.form.endDate = d.dayDate;
            this.form.endTime = fmt(e);
            this.endManuallyEdited = true;
            this.showCreateEvent = true;
        },
        dragOverlay(dayDate) {
            if (!this.drag.active || this.drag.dayDate !== dayDate) return null;
            const d = this.drag;
            const startMin = d.startHour * 60 + d.startHalf * 30;
            const endMin = d.currentHour * 60 + d.currentHalf * 30 + 30;
            const s = Math.min(startMin, endMin);
            const e = Math.max(startMin, endMin);
            const gridStart = {{ $hourGridStart }} * 60;
            const gridSpan = {{ count($gridHours) }} * 60;
            return { top: ((s - gridStart) / gridSpan) * 100, height: ((e - s) / gridSpan) * 100 };
        },

        // Drag-to-reschedule (HTML5 native drag on existing chips)
        rescheduleStart(eventId, dayDate, e) {
            this.reschedule = { dragging: true, eventId, originalDate: dayDate };
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', String(eventId));
        },
        rescheduleEnd() {
            this.reschedule = { dragging: false, eventId: null, originalDate: null };
        },
        // Month-grid drag-to-reschedule
        rescheduleStartDrag(eventId, fromDate) {
            this.rescheduleDragEventId = eventId;
            this.rescheduleDragFromDate = fromDate;
        },
        async rescheduleDropOnDate(newDate) {
            const eventId = this.rescheduleDragEventId;
            this.rescheduleDragOver = null;
            this.rescheduleDragEventId = null;
            if (!eventId || newDate === this.rescheduleDragFromDate) return;
            // Block past dates
            if (new Date(newDate) < new Date(new Date().toISOString().slice(0, 10))) {
                alert('Cannot reschedule to past dates.'); return;
            }
            try {
                const r = await fetch('/corex/command-center/calendar/' + eventId + '/reschedule', {
                    method: 'PATCH',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    credentials: 'same-origin',
                    body: JSON.stringify({ event_date: newDate + 'T' + '09:00:00' }),
                });
                if (r.ok) { window.location.reload(); }
                else { alert('Reschedule failed.'); }
            } catch (e) { alert('Network error.'); }
        },
        async rescheduleDrop(dayDate, hour, half) {
            if (!this.reschedule.dragging || !this.reschedule.eventId) return;
            if (dayDate !== this.reschedule.originalDate) return;

            const mins = hour * 60 + half * 30;
            const h = Math.floor(mins / 60).toString().padStart(2, '0');
            const m = (mins % 60).toString().padStart(2, '0');
            const newStart = `${dayDate}T${h}:${m}:00`;

            try {
                const r = await fetch(`/corex/command-center/calendar/${this.reschedule.eventId}/reschedule`, {
                    method: 'PATCH',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ event_date: newStart }),
                });
                if (r.ok) { window.location.reload(); }
                else { const err = await r.json().catch(() => ({})); alert(err.error || 'Could not reschedule.'); }
            } catch (e) { alert('Network error during reschedule.'); }
        },

        formatAuditAction(entry) {
            const labels = { created: 'Event created', rescheduled: 'Rescheduled', cancelled: 'Cancelled', completed: 'Marked complete', feedback_captured: 'Feedback captured', feedback_task_created: 'Auto-task created' };
            const base = labels[entry.action] || entry.action;
            return entry.by ? `${base} by ${entry.by}` : base;
        },

        async openFeedbackModal(eventId) {
            const r = await fetch('/corex/command-center/calendar/' + eventId + '/feedback', {
                headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
            });
            if (!r.ok) return;
            const data = await r.json();
            this.feedbackData = data;
            this.feedbackPropertyStep = 0;
            this.feedbackForm = {};
            data.contacts.forEach(c => {
                this.feedbackForm[c.id] = {
                    outcome_id: c.outcome_id ? String(c.outcome_id) : '',
                    concern_ids: (c.concerns || []).map(String),
                    seller_visible_notes: c.seller_notes || '',
                    internal_notes: c.internal_notes || '',
                    next_action_notes: c.next_action || '',
                };
            });
            this.panelOpen = false;
            this.feedbackOpen = true;
        },
        getCurrentFeedbackPropertyId() {
            if (!this.feedbackData.is_multi_property || !this.feedbackData.properties.length) {
                return (this.feedbackData.properties && this.feedbackData.properties[0]) ? this.feedbackData.properties[0].id : null;
            }
            return this.feedbackData.properties[this.feedbackPropertyStep]?.id || null;
        },

        buildFeedbackPayload() {
            const propertyId = this.getCurrentFeedbackPropertyId();
            return {
                feedback: Object.entries(this.feedbackForm).map(([cid, f]) => ({
                    contact_id: parseInt(cid),
                    property_id: propertyId,
                    outcome_id: f.outcome_id ? parseInt(f.outcome_id) : null,
                    concern_ids: (f.concern_ids || []).map(Number),
                    seller_visible_notes: f.seller_visible_notes || null,
                    internal_notes: f.internal_notes || null,
                    next_action_notes: f.next_action_notes || null,
                })),
            };
        },

        async submitFeedbackPayload(payload) {
            return await fetch('/corex/command-center/calendar/' + this.feedbackData.event.id + '/feedback', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });
        },

        async saveFeedback() {
            this.feedbackSaving = true;
            const payload = this.buildFeedbackPayload();
            const r = await this.submitFeedbackPayload(payload);
            this.feedbackSaving = false;
            if (r.ok) { this.feedbackOpen = false; window.location.reload(); }
        },

        async saveFeedbackAndNext() {
            this.feedbackSaving = true;
            const payload = this.buildFeedbackPayload();
            const r = await this.submitFeedbackPayload(payload);
            this.feedbackSaving = false;
            if (r.ok) {
                this.feedbackPropertyStep++;
                this.resetFeedbackForm();
            }
        },

        skipFeedbackProperty() {
            if (this.feedbackPropertyStep < this.feedbackData.properties.length - 1) {
                this.feedbackPropertyStep++;
                this.resetFeedbackForm();
            } else {
                this.feedbackOpen = false;
                window.location.reload();
            }
        },

        resetFeedbackForm() {
            this.feedbackForm = {};
            this.feedbackData.contacts.forEach(c => {
                this.feedbackForm[c.id] = {
                    outcome_id: '', concern_ids: [],
                    seller_visible_notes: '', internal_notes: '', next_action_notes: '',
                };
            });
        },

        // ── Reason Picker ──
        getReasonOptions() {
            const actorRole = this.panelData?.actor_role || 'neither';
            if (actorRole === 'buyer_action') {
                return [
                    { code: 'buyer_no_show', label: 'Buyer no-show' },
                    { code: 'cancelled_by_buyer', label: 'Cancelled by buyer' },
                    { code: 'cancelled_by_agent', label: 'Cancelled by agent' },
                    { code: 'rescheduled', label: 'Rescheduled' },
                    { code: 'other', label: 'Other' },
                ];
            }
            if (actorRole === 'seller_action') {
                return [
                    { code: 'seller_no_show', label: 'Seller no-show' },
                    { code: 'cancelled_by_seller', label: 'Cancelled by seller' },
                    { code: 'cancelled_by_agent', label: 'Cancelled by agent' },
                    { code: 'rescheduled', label: 'Rescheduled' },
                    { code: 'mandate_not_signed', label: 'Mandate not signed' },
                    { code: 'other', label: 'Other' },
                ];
            }
            return [
                { code: 'acknowledged', label: 'Acknowledged' },
                { code: 'resolved', label: 'Resolved' },
                { code: 'no_longer_relevant', label: 'No longer relevant' },
                { code: 'rescheduled', label: 'Rescheduled' },
                { code: 'other', label: 'Other' },
            ];
        },
        async submitReasonPicker() {
            this.reasonPickerSaving = true;
            const endpoint = this.reasonPickerAction === 'dismiss'
                ? '/corex/command-center/calendar/' + this.reasonPickerEventId + '/dismiss'
                : '/corex/command-center/calendar/' + this.reasonPickerEventId + '/complete';
            const r = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    completion_reason_code: this.reasonPickerCode,
                    completion_reason: this.reasonPickerNotes || this.reasonPickerCode,
                }),
            });
            this.reasonPickerSaving = false;
            if (r.ok) {
                this.reasonPickerOpen = false;
                this.reasonPickerCode = '';
                this.reasonPickerNotes = '';
                window.location.reload();
            }
        },

        openEventPanel(eventId) {
            this.panelOpen = true;
            this.panelData = { title: 'Loading\u2026', colour: null, days_diff: 0 };

            fetch('/corex/command-center/calendar/' + eventId, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            })
            .then(r => r.ok ? r.json() : Promise.reject(r.status))
            .then(data => { this.panelData = data; })
            .catch(err => {
                this.panelData = { title: 'Could not load event', colour: null, days_diff: 0 };
                console.warn('Calendar event load failed:', err);
            });
        },

        async respondInvitation(action) {
            if (!this.panelData?.invitation?.respond_url) return;
            try {
                const r = await fetch(this.panelData.invitation.respond_url, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ action: action, _token: document.querySelector('meta[name="csrf-token"]').content }),
                    credentials: 'same-origin',
                });
                if (r.ok || r.status === 302) {
                    // Refresh panel data
                    this.openEventPanel(this.panelData.id);
                    // If declined, close panel after brief delay
                    if (action === 'declined') {
                        setTimeout(() => { this.panelOpen = false; }, 800);
                    }
                }
            } catch (e) { console.error('Invitation respond failed:', e); }
        },

        panelColourStyle(colour) {
            const m = {
                red:     'background:#dc2626; color:#ffffff; border:1px solid #991b1b;',
                amber:   'background:#d97706; color:#ffffff; border:1px solid #92400e;',
                green:   'background:#0d9488; color:#ffffff; border:1px solid #115e59;',
                neutral: 'background:#475569; color:#ffffff; border:1px solid #334155;',
            };
            return m[colour] || '';
        },
        panelDotHex(colour) {
            return { red: '#ef4444', amber: '#f59e0b', green: '#14b8a6', neutral: '#94a3b8' }[colour] || '#64748b';
        },
        panelColourLabel(colour) {
            if (this.panelData.status === 'completed') return 'Completed';
            if (this.panelData.status === 'dismissed') return 'Dismissed';
            return { red: 'Urgent', amber: 'Approaching', green: 'Upcoming', neutral: 'Future' }[colour] || '';
        },
        panelDaysDiffLabel(days) {
            if (days == null) return '';
            if (days === 0) return 'Today';
            if (days === 1) return 'Tomorrow';
            if (days === -1) return 'Yesterday';
            if (days > 0) return 'In ' + days + ' days';
            return Math.abs(days) + ' days ago';
        },
    };
}

function propertySearch() {
    return {
        query: '', results: [], chosen: [], loading: false,
        getClassConfig() {
            const mapEl = document.getElementById('classConfigMap');
            if (!mapEl) return { multi: true, actor_role: 'both', completion: 'freeform' };
            try {
                const map = JSON.parse(mapEl.textContent);
                const form = this.$el?.closest?.('form');
                const cat = form?.querySelector('[name="category"]')?.value || '';
                return map[cat] || { multi: true, actor_role: 'both', completion: 'freeform' };
            } catch { return { multi: true, actor_role: 'both', completion: 'freeform' }; }
        },
        get maxProperties() {
            return this.getClassConfig().multi ? 99 : 1;
        },
        get atCap() { return this.chosen.length >= this.maxProperties; },
        async search() {
            if (this.atCap) { this.results = []; return; }
            if (this.query.length < 2) { this.results = []; return; }
            this.loading = true;
            try {
                const r = await fetch('/deals-v2/search/properties?q=' + encodeURIComponent(this.query), {
                    headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
                });
                const data = r.ok ? await r.json() : [];
                const ids = this.chosen.map(p => p.id);
                this.results = data.filter(d => !ids.includes(d.id));
            } finally { this.loading = false; }
        },
        async pick(r) {
            if (this.atCap) return;
            this.chosen.push(r); this.results = []; this.query = '';
            await this.autoPopulateOwners(r.id);
        },
        remove(p) { this.chosen = this.chosen.filter(x => x.id !== p.id); },
        get selected() { return this.chosen.length > 0 ? this.chosen[0] : null; },
        async autoPopulateOwners(propertyId) {
            const config = this.getClassConfig();
            // Only auto-populate sellers for seller_action or both events
            if (config.actor_role !== 'seller_action' && config.actor_role !== 'both') return;
            const form = this.$el?.closest?.('form');
            try {
                const r = await fetch('/corex/command-center/calendar/properties/' + propertyId + '/owners', {
                    headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
                });
                if (!r.ok) return;
                const owners = await r.json();
                const picker = form?.querySelector('[x-ref="attendeePicker"]');
                if (picker) {
                    Alpine.$data(picker).setOwners(owners);
                }
            } catch (e) { console.warn('Auto-populate owners failed:', e); }
        },
    };
}

function contactSearch() {
    return {
        query: '', results: [], chosen: [],
        async search() {
            if (this.query.length < 2) { this.results = []; return; }
            const r = await fetch('/corex/command-center/calendar/search/attendees?q=' + encodeURIComponent(this.query), {
                headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
            });
            const data = r.ok ? await r.json() : [];
            const keys = this.chosen.map(c => c.type + ':' + c.id);
            this.results = data.filter(d => !keys.includes((d.type || 'contact') + ':' + d.id));
        },
        add(c) {
            if (!c.type) c.type = 'contact';
            // Auto-assign role based on class actor_role
            if (!c.role && c.type !== 'agent') {
                const mapEl = document.getElementById('classConfigMap');
                try {
                    const map = JSON.parse(mapEl?.textContent || '{}');
                    const form = this.$el?.closest?.('form');
                    const cat = form?.querySelector('[name="category"]')?.value || '';
                    const cfg = map[cat] || {};
                    c.role = cfg.actor_role === 'buyer_action' ? 'buyer_contact'
                           : cfg.actor_role === 'seller_action' ? 'seller_contact'
                           : 'attendee';
                } catch { c.role = 'attendee'; }
            }
            this.chosen.push(c); this.query = ''; this.results = [];
            // Conflict check for user (agent) attendees
            if (c.type === 'agent') { this.checkConflictForAttendee(c); }
        },
        remove(c) { this.chosen = this.chosen.filter(x => !(x.id === c.id && x.type === c.type)); },
        async checkConflictForAttendee(c) {
            const form = this.$el?.closest?.('form');
            const startDate = form?.querySelector('[name="event_date"]')?.value || form?.querySelector('[x-bind\\:value="computedEventDate"]')?.value;
            const endDate = form?.querySelector('[name="end_date"]')?.value;
            if (!startDate) return;
            try {
                const params = new URLSearchParams({ user_id: c.id, start: startDate, end: endDate || startDate });
                const r = await fetch('/corex/command-center/calendar/check-conflicts?' + params, {
                    headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
                });
                if (!r.ok) return;
                const data = await r.json();
                if (data.has_conflict) {
                    c.conflict = data.conflicts;
                    c.conflictLabel = data.conflicts.map(cf => cf.title).join(', ');
                    // Force reactivity
                    this.chosen = [...this.chosen];
                }
            } catch (e) { /* silent */ }
        },
        setOwners(owners) {
            // Auto-populate with property owners as seller_contact (additive, don't duplicate)
            owners.forEach(o => {
                if (!o.type) o.type = 'contact';
                if (!o.role) o.role = 'seller_contact';
                const key = o.type + ':' + o.id;
                if (!this.chosen.some(c => c.type + ':' + c.id === key)) {
                    this.chosen.push(o);
                }
            });
        },
    };
}
</script>
@endsection
