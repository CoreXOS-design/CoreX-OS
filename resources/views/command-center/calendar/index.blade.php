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

    // ITEM 1 — start–end time label for a tile. Empty for all-day events;
    // "HH:MM" when there is no distinct end; "HH:MM–HH:MM" for a same-day range.
    $timeRange = function ($e) {
        if (!empty($e->all_day)) return '';
        $start = $e->event_date->format('H:i');
        if ($e->end_date && $e->end_date->gt($e->event_date) && $e->end_date->isSameDay($e->event_date)) {
            return $start . '–' . $e->end_date->format('H:i');
        }
        return $start;
    };

    // ITEM 3 — lay out a day-column's timed events by DURATION (Google/Outlook
    // style). Returns rects with grid-minute offsets (s/en) + overlap lanes so a
    // tile spans the correct number of hour rows. Cluster-based greedy lane
    // packing keeps overlapping events side by side instead of one hiding another.
    $layoutDayColumn = function ($events, int $gridStart, int $gridCount) {
        $gridMinutes = max(1, $gridCount * 60);
        $items = collect($events)->filter()->map(function ($e) use ($gridStart, $gridMinutes) {
            $startMin = ($e->event_date->hour - $gridStart) * 60 + $e->event_date->minute;
            $endDt = ($e->end_date && $e->end_date->gt($e->event_date))
                ? $e->end_date
                : $e->event_date->copy()->addMinutes(60);
            // End on a later day → clamp to the bottom of the visible grid.
            $endMin = $endDt->isSameDay($e->event_date)
                ? ($endDt->hour - $gridStart) * 60 + $endDt->minute
                : $gridMinutes;
            $s  = max(0, min($startMin, $gridMinutes));
            $en = max($s + 30, min($endMin, $gridMinutes)); // 30-min floor keeps short events clickable
            return ['e' => $e, 's' => $s, 'en' => $en, 'lane' => 0, 'lanes' => 1];
        })->sortBy('s')->values()->all();

        // Cluster-based lane packing: within each run of overlapping events,
        // greedily assign the first free lane; the whole cluster shares the
        // lane count so widths line up.
        $i = 0; $n = count($items);
        while ($i < $n) {
            $clusterEnd = $items[$i]['en'];
            $laneEnds = [];
            $j = $i;
            while ($j < $n && $items[$j]['s'] < $clusterEnd) {
                $placed = false;
                foreach ($laneEnds as $lane => $end) {
                    if ($items[$j]['s'] >= $end) { $items[$j]['lane'] = $lane; $laneEnds[$lane] = $items[$j]['en']; $placed = true; break; }
                }
                if (!$placed) { $items[$j]['lane'] = count($laneEnds); $laneEnds[] = $items[$j]['en']; }
                $clusterEnd = max($clusterEnd, $items[$j]['en']);
                $j++;
            }
            $laneCount = max(1, count($laneEnds));
            for ($k = $i; $k < $j; $k++) { $items[$k]['lanes'] = $laneCount; }
            $i = $j;
        }
        return $items;
    };
@endphp

<div class="flex flex-col h-full overflow-hidden -m-4 lg:-m-6" x-data="calendarPage()" x-init="initPanel(); restoreCreateEventState(); restoreEventDetailState(); if ({{ $autoOpenFeedbackEventId ?? 'null' }}) openFeedbackModal({{ $autoOpenFeedbackEventId ?? 'null' }}); handlePrefill(); window.addEventListener('beforeunload', () => { persistCreateEventState(); persistEventDetailState(); }); $watch('showCreateEvent', open => { if (open) { this.panelOpen = false; } if (!open) { this.pendingCreateDate = null; sessionStorage.removeItem('corex.calendar.createEventState'); this.clearStalePickerState(); } }); $watch('panelOpen', open => { if (open) { this.showCreateEvent = false; } if (!open) sessionStorage.removeItem('corex.calendar.eventDetailState'); });" @keydown.window="handleShortcut($event)" @mouseup.window="dragEnd()">

    @once
    <style>
        /* AT-164 — MONTH BOUNDARY TINT (continuous month stream only). Alternating faint wash
           per month so the boundary reads as a glanceable full-width colour change (Johan:
           "the 2px line + Jul 1 — do not cover it" — this ADDS to them, keeping both). Applies
           only under .cal-scroll-continuous so the classic paged month (one month, uniform)
           is untinted. STRENGTH is a single tunable variable — Johan can dial it on QA1. */
        :root { --cal-month-tint-alpha: 0.045; }        /* barely-there slate; 0 = off */
        .cal-scroll-continuous .cal-week-row.cal-month-tint-1 { background: rgba(100, 116, 139, var(--cal-month-tint-alpha)); }
        .cal-scroll-continuous .cal-week-row.cal-month-tint-0 { background: transparent; }
    </style>
    @endonce

    {{-- ══════ HEADER STRIP (fixed, compact — cockpit density) ══════ --}}
    <div class="flex-shrink-0 px-4 lg:px-6 pb-1.5 space-y-1.5 pt-2" style="background: var(--bg);">


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
    <div class="rounded-md px-3 py-1.5 flex flex-row items-center justify-between gap-3"
         style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex items-center gap-2">
            {{-- AT-164 Gate 5 — month view scrolls continuously; prev/next month
                 pagination is replaced by scroll (kept for week/day). --}}
            @if($currentView !== 'month')
            <a href="{{ $prevUrl }}" class="corex-btn-outline" aria-label="Previous">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
            </a>
            @endif
            @if($currentView === 'month' || $currentView === 'week')
                {{-- In-page Today — snaps the continuous month/week view back to today from
                     anywhere. ALWAYS clickable (Johan ruling): the continuous views scroll
                     away from today, so a stale server-computed "is today in view" must
                     never disable it. The controller ignores the click if already on today. --}}
                <button type="button" data-tour="cal-today" class="corex-btn-outline" @click="window.dispatchEvent(new Event('calendar:today'))">Today</button>
            @else
                {{-- Day/Agenda are server-rendered per date → navigate to today. --}}
                <a href="{{ $todayUrl }}" data-tour="cal-today" class="corex-btn-outline">Today</a>
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
            @if($currentView !== 'month')
            <a href="{{ $nextUrl }}" class="corex-btn-outline" aria-label="Next">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </a>
            @endif
        </div>

        <div class="flex items-center gap-2">
            <div data-tour="cal-views" class="inline-flex rounded-md overflow-hidden" style="background: var(--surface-2); border: 1px solid var(--border);">
                @foreach(['month' => 'Month', 'week' => 'Week', 'day' => 'Day', 'agenda' => 'Agenda'] as $vKey => $vLabel)
                    {{-- AT-164 explicit-save — the view is transient (sessionStorage); it becomes
                         the default only via "Save as my default". Record it before navigating. --}}
                    <a href="{{ route('command-center.calendar', array_merge(request()->only(['scope','types','categories']), ['view' => $vKey] + (in_array($scrollMode ?? 'continuous', ['continuous','paged'], true) ? ['scroll' => $scrollMode] : []))) }}"
                       @click="window.CoreXCal.patch({ view: '{{ $vKey }}' })"
                       class="px-3 py-1.5 text-xs font-semibold transition-colors"
                       style="{{ $currentView === $vKey ? 'background: var(--brand-button); color: #fff;' : 'color: var(--text-secondary);' }}">
                        {{ $vLabel }}
                    </a>
                @endforeach
            </div>

            {{-- AT-164 — CALENDAR SCROLLING preference: continuous stream vs classic paged.
                 Only month/week have two shells (day is single). Per-user transient; saved to
                 the default via "Save as my default". Andre gets pages, Johan gets the stream. --}}
            @if(in_array($currentView, ['month', 'week'], true))
                <div data-tour="cal-scrollmode" class="inline-flex rounded-md overflow-hidden" style="background: var(--surface-2); border: 1px solid var(--border);" title="Calendar scrolling: continuous stream or classic paged">
                    @foreach(['continuous' => 'Stream', 'paged' => 'Pages'] as $sKey => $sLabel)
                        <a href="{{ route('command-center.calendar', array_merge(request()->only(['scope','types','categories','date']), ['view' => $currentView, 'scroll' => $sKey])) }}"
                           @click="window.CoreXCal.patch({ scroll_mode: '{{ $sKey }}' })"
                           class="px-2.5 py-1.5 text-xs font-semibold transition-colors inline-flex items-center gap-1"
                           style="{{ ($scrollMode ?? 'continuous') === $sKey ? 'background: var(--brand-button); color: #fff;' : 'color: var(--text-secondary);' }}">
                            {{ $sLabel }}
                        </a>
                    @endforeach
                </div>
            @endif

            {{-- AT-164 explicit-save — ALWAYS-VISIBLE arrangement controls (Johan QA1): the
                 Save/Reset pair lives in the persistent toolbar so it is reachable in EVERY
                 state — deck hidden, right panel hidden, both hidden, any view, Stream or
                 Pages. "Save default" promotes the current live arrangement (INCLUDING hidden
                 panels) to the per-user saved default; "Reset" restores that default,
                 discarding in-session (transient) changes. Rendered in all views (arrangement
                 applies beyond month/week), so it sits OUTSIDE the scroll-mode gate. --}}
            <div x-data="arrangementControls()" class="inline-flex items-center rounded-md overflow-hidden" style="background: var(--surface-2); border: 1px solid var(--border);">
                <button type="button" data-tour="cal-saveview" @click="save()" :disabled="state==='saving'"
                        class="px-2.5 py-1.5 text-xs font-semibold transition-colors hover:opacity-80 inline-flex items-center gap-1 disabled:opacity-70"
                        :style="'border-right: 1px solid var(--border);' + (state==='saved' ? 'background: var(--brand-button); color:#fff;' : (state==='error' ? 'background:#dc2626; color:#fff;' : 'color: var(--text-primary);'))"
                        :title="state==='error' ? 'Save failed — click to try again' : 'Save the current layout — including hidden panels — as your default (shown on every fresh load)'">
                    {{-- idle / saving: save (disk) icon --}}
                    <svg x-show="state==='idle' || state==='saving'" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 6h-15m15 0a2.25 2.25 0 0 1 2.25 2.25v9A2.25 2.25 0 0 1 19.5 19.5h-15A2.25 2.25 0 0 1 2.25 17.25v-9A2.25 2.25 0 0 1 4.5 6m15 0-1.5-1.5A2.25 2.25 0 0 0 16.5 3h-9a2.25 2.25 0 0 0-1.5.6L4.5 6"/></svg>
                    {{-- saved: check icon --}}
                    <svg x-show="state==='saved'" x-cloak class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    {{-- error: warning icon --}}
                    <svg x-show="state==='error'" x-cloak class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008M10.34 3.94l-7.9 13.67A1.5 1.5 0 0 0 3.73 20h16.54a1.5 1.5 0 0 0 1.3-2.39L13.66 3.94a1.5 1.5 0 0 0-2.6 0z"/></svg>
                    <span x-text="saveLabel()"></span>
                </button>
                <button type="button" data-tour="cal-resetview" @click="reset()"
                        class="px-2.5 py-1.5 text-xs font-semibold transition-colors hover:opacity-80"
                        style="color: var(--text-muted);"
                        title="Restore your saved default (discard in-session changes)">Reset</button>
            </div>

            {{-- Guided-tour "?" launcher relocates here (AT-164 calendar tour). Empty +
                 additive — the tour engine appends its button; no effect if absent. --}}
            <span id="tour-launcher-slot" class="tour-slot-surface inline-flex"></span>
            <button type="button" @click="helpOpen = !helpOpen" title="Keyboard shortcuts (?)"
                    class="px-2 py-1.5 rounded text-xs font-bold transition hover:opacity-80"
                    style="background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border);">
                ?
            </button>
            {{-- AT-164 cockpit — Add Event folded into the toolbar (branded band removed for density). --}}
            <button type="button" @click="openBlank()" class="corex-btn-primary text-xs py-1" data-tour="cal-add">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Add Event
            </button>
        </div>
    </div>

    {{-- Legend moved to right panel Color By section --}}

    </div>{{-- END sticky header band --}}

    {{-- ══════ FLEX ROW: Calendar grid + Right panel (fills remaining height) ══════ --}}
    <div data-tour="cal-cockpit" class="relative flex gap-3 flex-1 min-h-0 overflow-hidden px-4 lg:px-6 pb-1.5">
    {{-- Main calendar column (scrolls independently). CAL-2: min-w pins
         the calendar so the right-docked create-event aside can never
         squeeze it to zero — preserves the "calendar stays visible on the
         left" guarantee when filter panel + create panel are both open. --}}
    {{-- AT-164 COCKPIT (Johan QA 21:41): the main column is a NON-scrolling flex-col
         — [filter bar (pinned)] + [grid frame (flex-1, the ONLY scroll container)] +
         [deck row (pinned)]. The page never scrolls; the GRID scrolls inside its
         bounded frame. --}}
    {{-- CALENDAR BLOCK (~73% width; the grid scroll frame lives inside) --}}
    <div class="flex flex-col min-h-0" style="flex: 1 1 0%; min-width: 0;">

    {{-- ══════ FILTER BAR (compact — panel toggle + active filter summary) ══════ --}}
    <div class="flex-shrink-0 flex items-center gap-3 rounded-md px-3 py-1 mb-1.5"
         style="background: var(--surface); border: 1px solid var(--border);">
        {{-- Scope pills (kept inline — primary control) --}}
        <form method="GET" action="{{ route('command-center.calendar') }}" id="calendar-filters" class="flex items-center gap-2">
            <input type="hidden" name="view" value="{{ $currentView }}">
            <input type="hidden" name="month" value="{{ $month ?? now()->month }}">
            <input type="hidden" name="year" value="{{ $year ?? now()->year }}">
            @if(isset($anchorDate))
                <input type="hidden" name="date" value="{{ $anchorDate->toDateString() }}">
            @endif
            @php
                // Only show scope pills the role's Data Scope ceiling allows.
                // own → Mine only; branch → Mine + Branch; all → all three.
                $scopeRank = ['own' => 0, 'branch' => 1, 'all' => 2];
                $ceilingRank = $scopeRank[$scopeCeiling ?? 'all'] ?? 2;
                $scopePills = array_filter(
                    ['all' => 'All', 'branch' => 'Branch', 'own' => 'Mine'],
                    fn ($k) => ($scopeRank[$k] ?? 0) <= $ceilingRank,
                    ARRAY_FILTER_USE_KEY
                );
            @endphp
            @if(count($scopePills) > 1)
            <div class="inline-flex rounded-md overflow-hidden" style="background: var(--surface-2); border: 1px solid var(--border);">
                @foreach($scopePills as $sKey => $sLabel)
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
            @endif
        </form>

        <div class="flex-1"></div>

        {{-- AT-164 Gate 6 — Layer toggles. Show/hide event species on the grid
             (instant, client-side) + filter the Notifications tile (server-side);
             persisted per-user (cross-device). Inline z-index (no new Tailwind
             arbitrary class, §3). --}}
        <div data-tour="cal-layers" x-data="layerFilter()" x-init="initLayers()" class="relative" @click.outside="open=false">
            <button type="button" @click="open=!open"
                    class="corex-btn-outline text-xs inline-flex items-center gap-1.5" title="Show or hide event layers">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m3.75 6 8.25 4.5L20.25 6M3.75 12l8.25 4.5L20.25 12M3.75 18l8.25 4.5L20.25 18"/></svg>
                <span>Layers</span>
                <span x-show="hiddenCount>0" x-cloak class="text-[10px] px-1.5 rounded-full font-semibold"
                      style="background: var(--brand-button); color:#fff;" x-text="hiddenCount + ' off'"></span>
            </button>
            <div x-show="open" x-cloak
                 class="absolute right-0 mt-1 w-52 rounded-md py-1"
                 style="z-index:30; background: var(--surface-2); border: 1px solid var(--border); box-shadow: 0 8px 24px rgba(0,0,0,0.35);">
                <template x-for="l in catalog" :key="l.key">
                    <label class="flex items-center gap-2 px-3 py-1.5 text-xs cursor-pointer transition-colors hover:bg-[color:var(--surface)]">
                        <input type="checkbox" :checked="active.includes(l.key)" @change="toggle(l.key)">
                        <span style="color: var(--text-secondary);" x-text="l.label"></span>
                    </label>
                </template>
                <div class="flex justify-between px-3 py-1.5 mt-1" style="border-top: 1px solid var(--border);">
                    <button type="button" @click="setAll(true)" class="text-[11px] font-semibold" style="color: var(--brand-button);">All</button>
                    <button type="button" @click="setAll(false)" class="text-[11px] font-semibold" style="color: var(--text-muted);">None</button>
                </div>
            </div>
        </div>

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

    {{-- ══════ GRID FRAME — the bounded scroll container (flex-1 fills the viewport
         remainder; the view inside is the ONLY thing that scrolls) ══════ --}}
    <div class="flex-1 min-h-0 flex flex-col">
    @if($currentView === 'month')
    @if(($scrollMode ?? 'continuous') === 'paged')
        {{-- ══════ MONTH VIEW — CLASSIC PAGED (single month + prev/next) ══════
             The classic shell for agents who dislike the continuous stream. ONE rendering
             truth: the SAME _week-row partial as the stream, scoped to the anchor month's
             weeks, with month paging instead of scroll. Today + ?date deep-links work. --}}
        @php
            $pagedBase = array_merge(request()->only(['scope','types','categories']), ['view' => 'month', 'scroll' => 'paged']);
        @endphp
        <div class="rounded-md overflow-hidden flex flex-col"
             style="background: var(--surface); border: 1px solid var(--border); flex: 1 1 0%; min-height: 0; position: relative;">

            {{-- Month header + prev/next paging --}}
            <div class="flex items-center justify-between px-3 py-1.5 flex-shrink-0"
                 style="z-index: 21; background: var(--surface-2); border-bottom: 1px solid var(--border);">
                <a href="{{ route('command-center.calendar', array_merge($pagedBase, ['date' => $prevMonth->toDateString()])) }}"
                   class="corex-btn-outline text-[11px] py-0.5" aria-label="Previous month">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
                </a>
                <span class="text-xs font-bold uppercase tracking-wider" style="color: var(--text-secondary);">{{ $anchorDate->format('F Y') }}</span>
                <a href="{{ route('command-center.calendar', array_merge($pagedBase, ['date' => $nextMonth->toDateString()])) }}"
                   class="corex-btn-outline text-[11px] py-0.5" aria-label="Next month">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                </a>
            </div>

            {{-- Day-of-week header (pinned) --}}
            <div class="grid grid-cols-7 flex-shrink-0" style="z-index: 20; background: var(--surface-2); border-bottom: 1px solid var(--border);">
                @foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dayName)
                    <div class="px-2 py-2 text-xs font-semibold text-center uppercase tracking-wider"
                         style="color: var(--text-muted); {{ !$loop->last ? 'border-right: 1px solid var(--border);' : '' }}">
                        {{ $dayName }}
                    </div>
                @endforeach
            </div>

            {{-- The anchor month's weeks (scrolls only if it overflows — rare). --}}
            <div class="flex-1 min-h-0 overflow-y-auto cal-scroll-paged">
                @foreach(($pagedWeekRows ?? []) as $wk)
                    @include('command-center.calendar.partials._week-row', [
                        'weekStart' => $wk['weekStart'], 'byDate' => $wk['byDate'],
                        'deadlineGroups' => $wk['deadlineGroups'], 'spanningBars' => $wk['spanningBars'],
                    ])
                @endforeach
            </div>
        </div>
    @else
        {{-- ══════ MONTH VIEW — ONE continuous week stream (§15.3, AT-164) ══════
             Every calendar week exists exactly once; months flow into each other; a
             month boundary is MARKED (first cell "Jul 1" + seam accent), never repeated
             with a splitter row. Windows are addressed by WEEK. --}}
        <div x-data="continuousMonth()" x-init="initMonth()"
             class="rounded-md overflow-hidden flex flex-col"
             style="background: var(--surface); border: 1px solid var(--border); flex: 1 1 0%; min-height: 0; position: relative;">

            {{-- Sticky month label — PINNED above the scroll region. Its text FOLLOWS the
                 scroll: it always names the month occupying the viewport (updated by the
                 controller as week rows cross the seam). --}}
            <div class="flex-shrink-0 px-3 py-1.5 text-xs font-bold uppercase tracking-wider"
                 style="z-index: 21; background: var(--surface-2); color: var(--text-secondary); border-bottom: 1px solid var(--border);"
                 x-text="monthLabel"></div>

            {{-- Day-of-week header — PINNED outside the scroll region: it is a flex-shrink-0
                 sibling above the scroller, so it never scrolls and stays visible at ANY
                 calendar/strip split (Johan 05:45 — resize must never hide the headers). --}}
            <div class="grid grid-cols-7 flex-shrink-0" style="z-index: 20; background: var(--surface-2); border-bottom: 1px solid var(--border);">
                @foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dayName)
                    <div class="px-2 py-2 text-xs font-semibold text-center uppercase tracking-wider"
                         style="color: var(--text-muted); {{ !$loop->last ? 'border-right: 1px solid var(--border);' : '' }}">
                        {{ $dayName }}
                    </div>
                @endforeach
            </div>

            {{-- Scroll region — ONLY the week rows scroll here. --}}
            <div x-ref="scroller" @scroll.passive="onScroll()" class="flex-1 min-h-0 overflow-y-auto" style="position: relative;">

            {{-- Top loading indicator (prepend earlier weeks) --}}
            <div class="text-center py-2 text-xs" style="color: var(--text-muted);" x-show="loadingTop" x-cloak>Loading…</div>

            {{-- Week rows. A window is server-rendered; the windowing controller
                 lazy-prepends/appends adjacent weeks through the identical _week-row
                 partial (via /calendar/week-rows) — one renderer, full interaction
                 parity, no dual JS cell renderer, no duplicated boundary weeks. --}}
            <div x-ref="weeks" class="cal-scroll-continuous">
                @foreach(($weekRows ?? []) as $wk)
                    @include('command-center.calendar.partials._week-row', [
                        'weekStart' => $wk['weekStart'], 'byDate' => $wk['byDate'],
                        'deadlineGroups' => $wk['deadlineGroups'], 'spanningBars' => $wk['spanningBars'],
                    ])
                @endforeach
            </div>

            {{-- Bottom loading indicator (append later weeks) --}}
            <div class="text-center py-2 text-xs" style="color: var(--text-muted);" x-show="loadingBottom" x-cloak>Loading…</div>
            </div>{{-- END scroll region --}}
        </div>
    @endif
    @elseif($currentView === 'week')
    @if(($scrollMode ?? 'continuous') === 'paged')
        {{-- ══════ WEEK VIEW — CLASSIC PAGED (single week + prev/next) ══════
             Same _day-column renderer as the continuous strip, scoped to one week, paged
             instead of scrolled. Today jumps to this week; ?date deep-links land. --}}
        @php $pagedWeekBase = array_merge(request()->only(['scope','types','categories']), ['view' => 'week', 'scroll' => 'paged']); @endphp
        <div class="rounded-md overflow-hidden flex flex-col"
             style="background: var(--surface); border: 1px solid var(--border); flex: 1 1 0%; min-height: 0;">
            {{-- Week header + prev/next paging --}}
            <div class="flex items-center justify-between px-3 py-1.5 flex-shrink-0"
                 style="border-bottom: 1px solid var(--border); background: var(--surface-2);">
                <a href="{{ route('command-center.calendar', array_merge($pagedWeekBase, ['date' => $prevAnchor])) }}"
                   class="corex-btn-outline text-[11px] py-0.5" aria-label="Previous week">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
                </a>
                <span class="text-xs font-bold uppercase tracking-wider" style="color: var(--text-secondary);">{{ $weekStart->format('d M') }} – {{ $weekEnd->format('d M Y') }}</span>
                <a href="{{ route('command-center.calendar', array_merge($pagedWeekBase, ['date' => $nextAnchor])) }}"
                   class="corex-btn-outline text-[11px] py-0.5" aria-label="Next week">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                </a>
            </div>
            {{-- Single-week grid: sticky time gutter + 7 day columns (vertical hour scroll). --}}
            <div class="flex-1 min-h-0 overflow-auto">
                <div class="flex" style="min-width: 100%;">
                    <div class="flex-shrink-0 sticky left-0" style="width: 56px; z-index: 7; background: var(--surface);">
                        <div class="sticky top-0" style="height: 44px; z-index: 8; background: var(--surface-2); border-bottom: 1px solid var(--border);"></div>
                        <div style="height: 40px; border-bottom: 1px solid var(--border);"></div>
                        @foreach(range(6, 19) as $gh)
                            <div class="text-[10px] pt-1 pl-1.5 select-none" style="height: 48px; color: var(--text-muted); border-bottom: 1px solid var(--border);">
                                {{ str_pad((string)$gh, 2, '0', STR_PAD_LEFT) }}:00
                            </div>
                        @endforeach
                    </div>
                    <div class="flex flex-1">
                        @foreach(($pagedDayColumns ?? []) as $col)
                            @include('command-center.calendar.partials._day-column', ['date' => $col['date'], 'events' => $col['events']])
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @else
        {{-- ══════ WEEK VIEW — continuous HORIZONTAL scroll (§15.3, cockpit) ══════ --}}
        <div x-data="continuousWeek()" x-init="initWeek()"
             class="rounded-md overflow-hidden flex flex-col"
             style="background: var(--surface); border: 1px solid var(--border); flex: 1 1 0%; min-height: 0;">

            {{-- Sticky week/date-range label + in-frame Today anchor --}}
            <div class="flex items-center justify-between px-3 py-1.5 flex-shrink-0"
                 style="border-bottom: 1px solid var(--border); background: var(--surface-2);">
                <span class="text-xs font-bold uppercase tracking-wider" style="color: var(--text-secondary);" x-text="rangeLabel"></span>
                <button type="button" @click="scrollToDay('{{ now()->toDateString() }}')" class="corex-btn-outline text-[11px] py-0.5">Today</button>
            </div>

            {{-- The ONE scroller: sticky time gutter (left) + horizontal day strip. A
                 vertical wheel is translated to horizontal so the mouse wheel advances
                 days/weeks; drag-to-scroll on the headers/gutter also advances. Inline
                 z-index only (§3). --}}
            <div x-ref="weekScroller" @scroll.passive="onWeekScroll()"
                 @mousedown="dragScrollStart($event)" @mousemove.window="dragScrollMove($event)" @mouseup.window="dragScrollEnd()"
                 class="flex-1 min-h-0 overflow-auto" style="cursor: grab;">
                <div class="flex" style="width: max-content;">
                    {{-- Sticky time gutter --}}
                    <div class="flex-shrink-0 sticky left-0" style="width: 56px; z-index: 7; background: var(--surface);">
                        <div class="sticky top-0" style="height: 44px; z-index: 8; background: var(--surface-2); border-bottom: 1px solid var(--border);"></div>
                        <div style="height: 40px; border-bottom: 1px solid var(--border);"></div>
                        @foreach(range(6, 19) as $gh)
                            <div class="text-[10px] pt-1 pl-1.5 select-none" style="height: 48px; color: var(--text-muted); border-bottom: 1px solid var(--border);">
                                {{ str_pad((string)$gh, 2, '0', STR_PAD_LEFT) }}:00
                            </div>
                        @endforeach
                    </div>
                    {{-- Day-column strip (windowed; lazy prepend/append) --}}
                    <div x-ref="days" class="flex">
                        @foreach(($dayColumns ?? []) as $col)
                            @include('command-center.calendar.partials._day-column', ['date' => $col['date'], 'events' => $col['events']])
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif

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

        <div class="max-w-3xl mx-auto rounded-md overflow-hidden w-full"
             style="background: var(--surface); border: 1px solid var(--border); flex: 1 1 0%; min-height: 0; overflow-y: auto;">
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
                                        ? 'background:#0f172a; color:#ffffff; border:2px solid #1e293b; border-radius:6px;'
                                        : $chipStyle;
                                }
                            @endphp
                            <button type="button"
                                    data-event-id="{{ $evt->id }}"
                                    @click.stop="openEventPanel({{ $evt->id }})"
                                    data-layer="{{ $evt->layer_key ?? 'appointments' }}"
                                    class="cal-layerable block w-full text-left px-3 py-2 rounded transition hover:opacity-80 {{ in_array($evt->status, ['completed', 'dismissed'], true) ? 'line-through opacity-70' : '' }}"
                                    style="{{ $chipStyle }}">
                                <div class="font-medium text-sm flex items-center gap-1.5">
                                    <span>{{ $evt->title }}</span>
                                    @if($evt->created_by_ai)<x-ai-badge size="xs" />@endif
                                </div>
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
                            {{-- ITEM 3 — timed events positioned by duration in the overlay below. --}}
                        </div>
                    </div>
                @endforeach

                {{-- ITEM 3 — timed events positioned by start + duration (single column). --}}
                @php
                    $gridMinutesDay = max(1, count($gridHours) * 60);
                    $dayTimedFlat = collect($dayTimedByHour)->flatMap(fn($c) => is_iterable($c) ? collect($c)->all() : []);
                @endphp
                @foreach($layoutDayColumn($dayTimedFlat, $hourGridStart, count($gridHours)) as $r)
                    @php
                        $evt = $r['e'];
                        $topPct = $r['s'] / $gridMinutesDay * 100;
                        $heightPct = ($r['en'] - $r['s']) / $gridMinutesDay * 100;
                        $lane = $r['lane']; $lanes = $r['lanes'];
                        $chipStyle = $ragChip[$evt->resolved_colour] ?? $defaultChip;
                        $isDraggable = in_array($evt->source_type, ['manual', 'manual:demo']);
                        $tr = $timeRange($evt);
                        $isDone = in_array($evt->status, ['completed', 'dismissed'], true);
                    @endphp
                    <button type="button"
                            data-event-id="{{ $evt->id }}"
                            @click.stop="openEventPanel({{ $evt->id }})"
                            @mousedown.stop
                            @if($isDraggable)
                                draggable="true"
                                @dragstart="rescheduleStart({{ $evt->id }}, '{{ $anchorDate->toDateString() }}', $event)"
                                @dragend="rescheduleEnd()"
                            @endif
                            :class="{ 'pointer-events-none': reschedule.dragging }"
                            data-layer="{{ $evt->layer_key ?? 'appointments' }}"
                            {{-- Inline z-index (see week overlay note): the z-[3] class was not in
                                 the compiled CSS, dropping the tile below the z-1 drag layers. --}}
                            class="cal-layerable absolute text-left rounded overflow-hidden transition hover:opacity-90 {{ $isDone ? 'line-through opacity-70' : '' }}"
                            style="z-index: 3; {{ $chipStyle }} {{ $isDraggable ? 'cursor:grab;' : '' }} top: {{ $topPct }}%; height: calc({{ $heightPct }}% - 2px); min-height: 18px; left: calc(56px + (100% - 56px) * {{ $lane }} / {{ $lanes }}); width: calc((100% - 56px) / {{ $lanes }} - 3px);"
                            title="{{ $tr }} {{ $evt->title }}">
                        <div class="flex items-center gap-2 px-2 pt-1">
                            <span class="text-[11px] opacity-80">{{ $tr }}</span>
                            <span class="font-medium text-xs truncate">{{ $evt->title }}</span>
                        </div>
                        <div class="text-[10px] opacity-70 px-2">{{ $evt->category }}</div>
                    </button>
                @endforeach

                {{-- Drag overlay --}}
                <div x-show="drag.active && drag.dayDate === '{{ $anchorDate->toDateString() }}'"
                     x-cloak
                     class="absolute pointer-events-none z-[5]"
                     :style="(() => {
                         const ov = dragOverlay('{{ $anchorDate->toDateString() }}');
                         if (!ov) return 'display:none';
                         return `top:${ov.top}%;height:${ov.height}%;left:56px;right:0;background:color-mix(in srgb, var(--brand-icon) 20%, transparent);border:1px solid var(--brand-button);border-radius:4px;`;
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
        <div class="rounded-md flex flex-col" style="background: var(--surface); border: 1px solid var(--border); flex: 1 1 0%; min-height: 0; overflow-y: auto;">
            {{-- Range filter bar --}}
            <form method="GET" action="{{ route('command-center.calendar') }}"
                  class="flex flex-col gap-3 px-4 py-3 flex-shrink-0"
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
                                    <div class="cal-layerable flex items-center gap-3 py-1.5 px-2 rounded-md transition-colors group cursor-pointer"
                                         data-layer="{{ $evt->layer_key ?? 'appointments' }}"
                                         style="background: transparent;"
                                         onmouseover="this.style.background='var(--surface-2)'"
                                         onmouseout="this.style.background='transparent'"
                                         @click="openEventPanel({{ $evt->id }})">
                                        <div class="w-1.5 h-6 rounded flex-shrink-0" style="background: {{ $dotColour }};"></div>
                                        <span class="text-xs font-mono flex-shrink-0 whitespace-nowrap" style="color: var(--text-muted); min-width: 3rem;">
                                            {{ $evt->all_day ? 'All day' : $timeRange($evt) }}
                                        </span>
                                        @if($evt->property_id)
                                            <a href="{{ route('corex.properties.show', $evt->property_id) }}" class="text-sm flex-1 truncate hover:underline {{ in_array($evt->status, ['completed', 'dismissed'], true) ? 'line-through opacity-70' : '' }}" style="color: var(--text-primary);">
                                                {{ $evt->title }}
                                            </a>
                                        @else
                                            <span class="text-sm flex-1 truncate {{ in_array($evt->status, ['completed', 'dismissed'], true) ? 'line-through opacity-70' : '' }}" style="color: var(--text-primary);">{{ $evt->title }}</span>
                                        @endif
                                        @if($evt->created_by_ai)<x-ai-badge size="xs" />@endif
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
    </div>{{-- END grid frame (bounded scroll container) --}}

    {{-- CREATE EVENT PANEL is rendered below as a flex sibling of the
         calendar grid + rightPanel aside (search for "CREATE EVENT PANEL
         (column-flex sibling)" below). Anchoring it as a flex column rather
         than a fixed-positioned overlay is what lets the grid SHRINK to
         make room when the panel opens — Google/Outlook/Cal.com pattern. --}}
    @php /* The create-event panel previously lived here as a fixed-positioned overlay.
            Moved to a flex-sibling position at the end of the flex row so the calendar
            grid SHRINKS when the panel opens (instead of being covered). The block
            below is now empty by design; @if(false) keeps the original closing tags
            valid until the file is re-saved without them. */ @endphp
    @if(false)
            <form id="createEventFormV2_DEAD" method="POST"
                  :action="editMode ? '/corex/command-center/calendar/' + editingEventId : '{{ route('command-center.calendar.store') }}'"
                  class="flex-1 overflow-y-auto px-6 py-4 space-y-4" @submit="submitting = true">
                @csrf
                <template x-if="editMode"><input type="hidden" name="_method" value="PUT"></template>

                {{-- Title --}}
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Title <span style="color:var(--ds-crimson)">*</span></label>
                    <input type="text" name="title" x-model="form.title" required
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                </div>

                {{-- Category --}}
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Type <span style="color:var(--ds-crimson)">*</span></label>
                    <select name="category" x-model="form.category" required
                            class="w-full rounded-md px-3 py-2 text-sm"
                            style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <option value="">Select type…</option>
                        @foreach($manualCreatableClasses as $cls)
                            <option value="{{ $cls->event_class }}" data-multi-property="{{ $cls->allow_multiple_properties ? '1' : '0' }}">{{ $cls->label }}</option>
                        @endforeach
                    </select>
                    {{-- CAL-3-HOTFIX — the @php block + <script id="classConfigMap">
                         that lived here was a duplicate of the live copy at the
                         live form's category select (L~1897 below). Both elements
                         carried the same DOM id; document.getElementById
                         ('classConfigMap') from calendarPage's Alpine readers
                         (propertySearch.getClassConfig L~3327 + contactSearch.add
                         L~3396) returns the FIRST DOM match — and a duplicate
                         inside this dead @if(false) block was the wrong element
                         for the live form's auto-populate + Capture-Feedback
                         flows. The live copy below is the single source of truth;
                         this duplicate is removed. Full cleanup of the @if(false)
                         dead block is a separate task. --}}
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
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Start <span style="color:var(--ds-crimson)">*</span></label>
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
                                  :title="c.conflictLabel ? '⚠  Conflict: ' + c.conflictLabel : ''">
                                <span class="text-[10px] px-1 py-0.5 rounded font-bold"
                                      :style="c.type === 'agent' ? 'background:#475569;color:#fff' : (c.role === 'seller_contact' ? 'background:#0f172a;color:#fff' : 'background:var(--brand-icon);color:#fff')"
                                      x-text="c.type === 'agent' ? 'Agent' : (c.role === 'seller_contact' ? 'Seller' : 'Buyer')"></span>
                                <template x-if="c.conflict"><span class="text-[10px]" style="color: #f59e0b;">⚠ </span></template>
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
    @endif
    {{-- END dead create-event block (live panel rendered below as flex sibling) --}}

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
                        ['â† / â†’', 'Previous / next period'],
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

    {{-- EVENT DETAIL SIDE PANEL — original location stub. The live panel is
         rendered below as a flex-sibling of the calendar grid (search for
         "EVENT DETAIL PANEL (column-flex sibling)"). The original here is
         kept disabled so the rest of the file's div/template counts stay
         balanced during the move. --}}
    @if(false)
    <div x-show="panelOpen" x-cloak class="hidden">
        <div class="hidden"></div>
        <aside class="hidden">

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
    @endif
    {{-- END original-location stub for EVENT DETAIL SIDE PANEL --}}

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

                {{-- CAL-6 — empty-state banner. The form's Outcome <select>
                     and Concerns checkbox list are nested inside the per-
                     contact / per-property loops and read from
                     feedbackData.outcomes / .concerns / .lp_outcomes /
                     .lp_concerns. If the agency_feedback_options table
                     hasn't been seeded on this host, all four arrays come
                     back empty, every <select> is reduced to "Select…"
                     with no options, and the user reads the modal as
                     "blank". Surface the actual root cause to whoever's
                     using the form instead of staring at an empty
                     dropdown. --}}
                <template x-if="(feedbackData.feedback_mode === 'per_property'
                                 ? (feedbackData.lp_outcomes.length === 0)
                                 : (feedbackData.outcomes.length === 0))">
                    <div class="rounded-md p-4 text-xs"
                         style="background: color-mix(in srgb, var(--ds-crimson, #dc2626) 6%, var(--surface));
                                border: 1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 30%, var(--border));
                                color: var(--text-primary);">
                        <strong>No feedback options configured for this agency.</strong><br>
                        The Outcome / Concerns lists are empty because
                        <code>agency_feedback_options</code> has no rows seeded
                        for this host. An admin needs to run:
                        <pre class="mt-2 p-2 rounded text-[11px]"
                             style="background: var(--surface-2); border: 1px solid var(--border); white-space: pre-wrap;">php artisan db:seed --class=Database\Seeders\AgencyFeedbackOptionsSeeder --force</pre>
                        Once that's done, reopen this event and the form will render its fields.
                    </div>
                </template>

                {{-- Per-property feedback (listing_presentation events) --}}
                <template x-if="feedbackData.feedback_mode === 'per_property'">
                    <div class="space-y-4">
                        <template x-for="item in feedbackData.items" :key="item.property_id">
                            <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                                <h3 class="text-sm font-semibold mb-3" style="color: var(--text-primary);" x-text="item.label"></h3>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                                    {{-- Outcome --}}
                                    <div>
                                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Outcome</label>
                                        <select x-model="feedbackForm['prop:' + item.property_id].outcome"
                                                class="w-full rounded-md px-3 py-2 text-sm"
                                                style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                            <option value="">Select…</option>
                                            <template x-for="o in feedbackData.lp_outcomes" :key="o">
                                                <option :value="o" x-text="o"></option>
                                            </template>
                                        </select>
                                    </div>
                                    {{-- Mandate type --}}
                                    <div>
                                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Mandate type</label>
                                        <select x-model="feedbackForm['prop:' + item.property_id].mandate_type"
                                                class="w-full rounded-md px-3 py-2 text-sm"
                                                style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                            <option value="">Select…</option>
                                            <template x-for="m in feedbackData.lp_mandate_types" :key="m">
                                                <option :value="m" x-text="m"></option>
                                            </template>
                                        </select>
                                    </div>
                                </div>

                                {{-- Concerns --}}
                                <div class="mb-3" x-show="feedbackData.lp_concerns.length > 0">
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Concerns</label>
                                    <div class="flex flex-wrap gap-2">
                                        <template x-for="c in feedbackData.lp_concerns" :key="c.id">
                                            <label class="inline-flex items-center gap-1.5 text-xs cursor-pointer" style="color: var(--text-primary);">
                                                <input type="checkbox" :value="c.id"
                                                       x-model="feedbackForm['prop:' + item.property_id].concern_ids"
                                                       class="rounded">
                                                <span x-text="c.label"></span>
                                            </label>
                                        </template>
                                    </div>
                                </div>

                                {{-- Internal notes --}}
                                <div class="mb-3">
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Internal notes</label>
                                    <textarea x-model="feedbackForm['prop:' + item.property_id].internal_notes" rows="2"
                                              class="w-full rounded-md px-3 py-2 text-sm"
                                              style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                              placeholder="Agent-only notes for this property…"></textarea>
                                </div>

                                {{-- Next action --}}
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Next action</label>
                                    <input type="text" x-model="feedbackForm['prop:' + item.property_id].next_action_notes"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                           placeholder="Follow-up action…">
                                </div>
                            </div>
                        </template>
                        <template x-if="feedbackData.items.length === 0">
                            <p class="text-sm py-4 text-center" style="color: var(--text-muted);">No properties linked to this listing presentation.</p>
                        </template>
                    </div>
                </template>

                {{-- Per-contact feedback (viewings — original UI) --}}
                <template x-for="contact in (feedbackData.feedback_mode === 'per_property' ? [] : feedbackData.contacts)" :key="contact.id">
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
            <div style="border-top: 1px solid var(--border);">
                {{-- Inline server-error surface — replaces the prior silent
                     fail where a 500/422 left the save button dead. --}}
                <template x-if="feedbackError">
                    <div class="px-6 pt-3 text-xs"
                         style="color: var(--ds-crimson, #dc2626);"
                         x-text="feedbackError"></div>
                </template>
            <div class="px-6 py-4 flex items-center justify-between gap-2">
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
            </div>{{-- /footer wrapper (border-top + feedbackError display) --}}
        </div>
    </div>

</div>{{-- END main calendar column --}}

{{-- ══════ AT-164 COCKPIT — RIGHT CONTEXT PANEL (fixed ~27%; quick-create by default,
     event details on click; scrolls its OWN contents, never the page) ══════ --}}
<div data-tour="cal-panel" class="flex flex-col min-h-0 rounded-md relative overflow-hidden"
     x-show="!panelCollapsed"
     :style="'flex: 0 0 27%; max-width: 27%; min-width: 300px; background: var(--surface); border: 1px solid var(--border);'">
    {{-- AT-164 cockpit v2 — panel RESIDENT default = AGENDA. Event click → detail
         overlay (panelOpen); Add-Event/empty-day → New Event form overlay
         (showCreateEvent); closing either returns here. When hidden it is removed
         ENTIRELY (calendar full width) — a slim floating tab (below) is the reopen. --}}
    <div x-show="!panelCollapsed" class="flex-shrink-0 flex items-center justify-between px-3 py-1.5" style="border-bottom: 1px solid var(--border);">
        <span class="text-[11px] font-bold uppercase tracking-wider" style="color: var(--text-secondary);">Agenda</span>
        <div class="flex items-center gap-2">
            <button type="button" @click="openBlank()" class="text-[11px] font-semibold" style="color: var(--brand-button);" title="Create a new event">+ New event</button>
            <button type="button" @click="togglePanelCollapse()" class="w-5 h-5 rounded flex items-center justify-center hover:opacity-70" style="color: var(--text-muted);" title="Collapse panel">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
            </button>
        </div>
    </div>
    <div x-show="!panelCollapsed" class="flex-1 min-h-0 overflow-y-auto px-2 py-1.5">
        <template x-if="agenda.length === 0">
            <div class="py-8 text-center text-xs leading-relaxed" style="color: var(--text-muted);">Nothing coming up.<br>Click a day, or “+ New event”, to add one.</div>
        </template>
        <div class="space-y-0.5">
            <template x-for="(it, idx) in agenda" :key="it.id + '-' + idx">
                {{-- AT-164 Gate 6 — the panel agenda is a calendar surface: it respects the
                     layer lens the SAME client-side way the grid does (instant + reversible). --}}
                <button type="button" @click="openEventPanel(it.id)"
                        x-show="calLayers.includes(it.layer || 'appointments')"
                        class="w-full text-left flex items-start gap-2 px-2 py-1.5 rounded transition-colors hover:bg-[color:var(--surface-2)]">
                    <span class="w-1.5 h-1.5 rounded-full flex-shrink-0 mt-1.5" :style="'background:' + (window.CoreXTile ? window.CoreXTile.ragColour(it.rag) : '#94a3b8')"></span>
                    <span class="flex-1 min-w-0">
                        <span class="block text-xs font-medium truncate" style="color: var(--text-primary);" x-text="(it.title||'').split('\n')[0]"></span>
                        <span class="block text-[10px]" style="color: var(--text-muted);">
                            <span x-text="it.day"></span><span x-show="it.time" x-text="' · ' + it.time"></span><span x-show="it.is_deadline"> · due</span>
                        </span>
                    </span>
                </button>
            </template>
        </div>
    </div>
        {{-- AT-164 cockpit — create/detail/color-by render INSIDE the panel --}}
<aside x-show="showCreateEvent" x-cloak
       x-transition:enter="transform transition ease-out duration-200"
       x-transition:enter-start="translate-x-full opacity-0"
       x-transition:enter-end="translate-x-0 opacity-100"
       x-transition:leave="transform transition ease-in duration-150"
       x-transition:leave-start="translate-x-0 opacity-100"
       x-transition:leave-end="translate-x-full opacity-0"
       @keydown.escape.window="showCreateEvent = false"
       class="absolute inset-0 flex flex-col overflow-hidden"
       style="z-index: 20; background: var(--surface);">

    {{-- Header --}}
    <div class="px-6 py-4 flex items-center justify-between flex-shrink-0" style="border-bottom: 1px solid var(--border);">
        <h2 class="text-lg font-semibold" style="color: var(--text-primary);" x-text="editMode ? 'Edit Event' : 'New Event'"></h2>
        <button type="button" @click="showCreateEvent = false" class="text-xl leading-none px-2" style="color: var(--text-muted); background: none; border: none; cursor: pointer;">&times;</button>
    </div>

    {{-- Body (scrollable) --}}
    {{-- CAL-7 Class 2 — on submit, clear the panel's sessionStorage state so
         the redirect-back doesn't restore the just-saved chips onto the
         next "+ New Event" click. The form data itself has already been
         serialised into the POST body by the browser at this point;
         clearing chosen[] now does not affect what the server receives.
         If validation returns 422 the user lands back with old() input
         in form fields but the picker chips are gone — acceptable
         trade-off for the much more common success path. --}}
    <form id="createEventFormV2" method="POST"
          :action="editMode ? '/corex/command-center/calendar/' + editingEventId : '{{ route('command-center.calendar.store') }}'"
          class="flex-1 overflow-y-auto px-6 py-4 space-y-4"
          @submit="onFormSubmit($event)">
        @csrf
        <template x-if="editMode"><input type="hidden" name="_method" value="PUT"></template>

        {{-- Title --}}
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Title <span style="color:var(--ds-crimson)">*</span></label>
            <input type="text" name="title" x-model="form.title" required
                   class="w-full rounded-md px-3 py-2 text-sm"
                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
        </div>

        {{-- Category --}}
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Type <span style="color:var(--ds-crimson)">*</span></label>
            <select name="category" x-model="form.category" required @change="applyCategoryNatureDefault()"
                    class="w-full rounded-md px-3 py-2 text-sm"
                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="">Select type…</option>
                @foreach($manualCreatableClasses as $cls)
                    <option value="{{ $cls->event_class }}" data-multi-property="{{ $cls->allow_multiple_properties ? '1' : '0' }}">{{ $cls->label }}</option>
                @endforeach
            </select>
            {{-- CAL-3 — class config map for the LIVE create form. Mirrors
                 the version inside the deprecated DEAD form below (which
                 sits inside @if(false) and so never renders). Without this
                 the Alpine helpers propertySearch.getClassConfig() (L~3303)
                 and contactSearch.add() (L~3372) read null from the DOM
                 and fall back to {actor_role:'both', multi:true} for every
                 class — which silently breaks class-aware behaviour:
                  - Single-property event classes allow multi-property pick.
                  - autoPopulateOwners runs for buyer-action events that
                    shouldn't pre-fill the seller as an attendee.
                  - Manually added attendees default to role 'attendee'
                    instead of 'buyer_contact'/'seller_contact', so the
                    backend can't disambiguate on save.
                 The script tag is a JSON island read by document.
                 getElementById — placement inside the form is fine
                 (DOM-lookup, not Alpine scope). --}}
            @php
                $classConfigMap = $manualCreatableClasses->mapWithKeys(fn($c) => [$c->event_class => [
                    'multi'           => (bool) $c->allow_multiple_properties,
                    'actor_role'      => $c->actor_role ?? 'neither',
                    'completion'      => $c->completion_behaviour ?? 'freeform',
                    'nature'          => $c->event_nature ?? 'actionable',
                    // AT-154 — buyers auto-fill only for buyer-driven classes; the
                    // server (propertyOwners) is authoritative, this is informational.
                    'autofill_buyers' => (bool) ($c->autofill_buyers ?? false),
                ]])->toArray();
            @endphp
            <script type="application/json" id="classConfigMap">{!! json_encode($classConfigMap) !!}</script>
        </div>

        {{-- Requires-feedback (event_nature). Pre-selected from the class default
             when a type is chosen (applyCategoryNatureDefault), overridable per
             event; posted as name="event_nature". Informational events never go
             overdue/red and never prompt for feedback. --}}
        <div x-show="form.category" x-cloak>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Feedback</label>
            <select name="event_nature" x-model="form.eventNature"
                    class="w-full rounded-md px-3 py-2 text-sm"
                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="actionable">Requires feedback — can go overdue</option>
                <option value="informational">No feedback needed — time-block only</option>
            </select>
            <p class="text-[11px] mt-1" style="color: var(--text-muted);"
               x-text="form.eventNature === 'informational'
                    ? 'This event will never show as overdue and won\'t prompt for feedback.'
                    : 'This event can go overdue and prompts for feedback after it passes.'"></p>
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
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Start <span style="color:var(--ds-crimson)">*</span></label>
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

        {{-- Recurrence (repeat) + edit-scope. recur_scope/occurrence_date are set by
             the scope modal on an "edit all/this/future" save; empty on plain create. --}}
        <input type="hidden" name="recur_scope" :value="form.recurScope">
        <input type="hidden" name="occurrence_date" :value="form.occurrenceDate">
        <div class="rounded-md p-3 space-y-3" style="background: var(--surface-2); border: 1px solid var(--border);">
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Repeat</label>
                <select name="recur_freq" x-model="form.recurFreq"
                        class="w-full rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">Does not repeat</option>
                    <option value="DAILY">Daily</option>
                    <option value="WEEKLY">Weekly</option>
                    <option value="MONTHLY">Monthly</option>
                </select>
            </div>
            <div x-show="form.recurFreq" x-cloak class="space-y-3">
                <div class="flex items-center gap-2">
                    <span class="text-xs" style="color: var(--text-secondary);">Every</span>
                    <input type="number" name="recur_interval" x-model.number="form.recurInterval" min="1" max="99"
                           class="w-16 rounded-md px-2 py-1.5 text-sm"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <span class="text-xs" style="color: var(--text-secondary);" x-text="recurIntervalUnit()"></span>
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Ends</label>
                    <select name="recur_end_type" x-model="form.recurEndType"
                            class="w-full rounded-md px-3 py-2 text-sm"
                            style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <option value="never">Never</option>
                        <option value="until">On date</option>
                        <option value="count">After a number of times</option>
                    </select>
                </div>
                <div x-show="form.recurEndType === 'until'" x-cloak>
                    <input type="date" name="recur_until" x-model="form.recurUntil" :min="form.startDate"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div x-show="form.recurEndType === 'count'" x-cloak class="flex items-center gap-2">
                    <input type="number" name="recur_count" x-model.number="form.recurCount" min="1" max="1000"
                           class="w-20 rounded-md px-2 py-1.5 text-sm"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <span class="text-xs" style="color: var(--text-secondary);">occurrences</span>
                </div>
                <p class="text-[11px]" style="color: var(--text-muted);" x-text="recurSummary()"></p>
            </div>
        </div>

        {{-- Part B — organizer self double-booking SOFT warning. Same amber ⚠
             language as the invited-agent conflict badge. Non-blocking: it lists
             the clashing appointment(s); the user may still save. Markers
             (occupies_time=false) never appear here — excluded server-side. --}}
        <div x-show="selfConflicts.length > 0" x-cloak
             class="rounded-md px-3 py-2 text-xs flex items-start gap-2"
             style="background: color-mix(in srgb, #f59e0b 12%, transparent); border: 1px solid #f59e0b; color: var(--text-primary);">
            <span class="text-sm leading-none" style="color:#f59e0b;">&#9888;</span>
            <div class="min-w-0">
                <div class="font-medium">You already have an appointment at this time.</div>
                <div class="opacity-80 truncate" x-text="selfConflicts.map(c => c.title).join(', ')"></div>
                <div class="opacity-60 mt-0.5">You can still save — this is just a heads-up.</div>
            </div>
        </div>

        {{-- Property multi-select --}}
        <div x-data="propertySearch()">
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Properties</label>
            <div class="flex flex-wrap gap-1 mb-1.5" x-show="chosen.length > 0">
                <template x-for="p in chosen" :key="p.id">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs"
                          style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        <span x-text="p.address" class="truncate max-w-[180px]"></span>
                        <button type="button" @click="remove(p)" class="opacity-60 hover:opacity-100">&times;</button>
                    </span>
                </template>
            </div>
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
            <template x-for="(p, idx) in chosen" :key="p.id">
                <input type="hidden" :name="'property_ids[' + idx + ']'" :value="p.id">
            </template>
            <input type="hidden" name="property_id" :value="chosen.length === 1 ? chosen[0].id : ''">
        </div>

        {{-- Attendees multi-select --}}
        <div x-data="contactSearch()" x-ref="attendeePicker">
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Attendees</label>
            <div class="flex flex-wrap gap-1 mb-1.5">
                <template x-for="c in chosen" :key="(c.type||'contact') + ':' + c.id">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs"
                          :style="c.conflict ? 'background: var(--surface-2); border: 2px solid #f59e0b; color: var(--text-primary);' : 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);'"
                          :title="c.conflictLabel ? '⚠  Conflict: ' + c.conflictLabel : ''">
                        {{-- CAL-4 — chip text prefers the server-supplied
                             role_label (raw pivot role, e.g. "Owner",
                             "Seller", "Lessor") when present so the
                             auto-fill preserves the property↔contact pivot
                             label. Falls through to the attendee-role enum
                             ("Seller"/"Buyer") for chips added via search
                             (no pivot context), and lands on the neutral
                             "Attendee" for blank pivots — never on
                             "Buyer", which is a misleading default. --}}
                        <span class="text-[10px] px-1 py-0.5 rounded font-bold"
                              :style="c.type === 'agent' ? 'background:#475569;color:#fff' : (c.role === 'seller_contact' ? 'background:#0f172a;color:#fff' : c.role === 'buyer_contact' ? 'background:var(--brand-icon);color:#fff' : 'background:var(--text-muted);color:#fff')"
                              x-text="c.type === 'agent' ? 'Agent' : (c.role_label || (c.role === 'seller_contact' ? 'Seller' : c.role === 'buyer_contact' ? 'Buyer' : 'Attendee'))"></span>
                        <template x-if="c.conflict"><span class="text-[10px]" style="color: #f59e0b;">⚠ </span></template>
                        {{-- CAL-5 — chip text is rebuilt from THIS object's
                             own first_name + last_name fields so the
                             displayed name can never originate from a
                             different contact row than c.id. The precomputed
                             `name` field is only used as a fallback for
                             chips that pre-date the CAL-5 server response
                             shape (none in normal flow; defensive only). --}}
                        <span x-text="(((c.first_name || '') + ' ' + (c.last_name || '')).trim()) || c.name || ('Contact #' + c.id)"></span>
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

        {{-- Reminder (AT-178) — self-contained partial; binds form.reminder* keys --}}
        @include('command-center.calendar.partials.reminder-fields')
    </form>

    {{-- Footer --}}
    <div class="px-6 py-4 flex items-center justify-end gap-2 flex-shrink-0" style="border-top: 1px solid var(--border);">
        <button type="button" @click="showCreateEvent = false" class="corex-btn-outline">Cancel</button>
        <button type="submit" form="createEventFormV2" :disabled="submitting"
                class="corex-btn-primary disabled:opacity-50">
            <span x-show="!submitting" x-text="editMode ? 'Save Changes' : 'Create Event'"></span>
            <span x-show="submitting" x-cloak x-text="editMode ? 'Saving…' : 'Creating…'"></span>
        </button>
    </div>
</aside>
<aside x-show="panelOpen" x-cloak
       x-transition:enter="transform transition ease-out duration-200"
       x-transition:enter-start="translate-x-full opacity-0"
       x-transition:enter-end="translate-x-0 opacity-100"
       x-transition:leave="transform transition ease-in duration-150"
       x-transition:leave-start="translate-x-0 opacity-100"
       x-transition:leave-end="translate-x-full opacity-0"
       @keydown.escape.window="panelOpen = false"
       class="absolute inset-0 flex flex-col overflow-hidden"
       style="z-index: 20; background: var(--surface);">

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
            <button @click="panelOpen = false" class="p-1 rounded transition-colors" style="color: var(--text-muted); background: none; border: none; cursor: pointer;"
                    onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        {{-- Invitation status pill + respond buttons (invitee only) --}}
        <template x-if="panelData.invitation && !panelData.is_organizer">
            <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);">
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
                <template x-if="panelData.invitation.status === 'pending' || panelData.invitation.status === 'tentative'">
                    <div class="flex items-center gap-1.5">
                        <button type="button" @click="respondInvitation('accepted')" class="text-[11px] font-medium px-3 py-1 rounded text-white" style="background:#10b981;">Accept</button>
                        <button type="button" @click="respondInvitation('tentative')" class="text-[11px] font-medium px-3 py-1 rounded" style="background:var(--surface-2); color:#f59e0b; border:1px solid rgba(245,158,11,0.3);">Tentative</button>
                        <button type="button" @click="respondInvitation('declined')" class="text-[11px] font-medium px-3 py-1 rounded" style="background:var(--surface-2); color:#ef4444; border:1px solid rgba(239,68,68,0.3);">Decline</button>
                    </div>
                </template>
                <template x-if="panelData.invitation.status === 'accepted'">
                    <button type="button" @click="respondInvitation('pending')" class="text-[10px] underline" style="color:var(--text-muted); background: none; border: none; cursor: pointer;">Change response</button>
                </template>
            </div>
        </template>

        {{-- Title + date --}}
        <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
            <h2 class="text-xl font-semibold leading-tight" style="color: var(--text-primary);" x-text="panelData.title"></h2>
            <p class="text-sm mt-1.5" style="color: var(--text-secondary);" x-text="panelData.event_date_h"></p>
            <p class="text-xs mt-0.5" style="color: var(--text-muted);" x-text="panelDaysDiffLabel(panelData.days_diff)"></p>
            <template x-if="panelData.recurrence_label">
                <p class="text-xs mt-1 inline-flex items-center gap-1" style="color: var(--text-secondary);">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                    <span x-text="panelData.recurrence_label"></span>
                </p>
            </template>
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

        {{-- Linked Records --}}
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

        {{-- Legacy source link fallback --}}
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

        {{-- Feedback CTA --}}
        <template x-if="panelData.is_actionable && panelData.is_past && panelData.has_contacts">
            <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);">
                <button type="button" @click="openFeedbackModal(panelData.id)"
                        class="text-xs font-medium transition-colors hover:underline" style="color: var(--brand-button); background: none; border: none; cursor: pointer;">
                    Capture feedback &rarr;
                </button>
            </div>
        </template>

    </div>

    {{-- Sticky footer action bar --}}
    <div class="px-5 py-2.5 flex items-center gap-4 flex-shrink-0" style="border-top: 1px solid var(--border); background: var(--surface);">
        <template x-if="panelData.is_editable">
            <button type="button" @click="editFromPanel()"
                    class="text-xs font-medium transition-colors hover:opacity-70 inline-flex items-center gap-1"
                    style="color: var(--text-primary); background: none; border: none; cursor: pointer;">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Z"/></svg>
                Edit
            </button>
        </template>

        {{-- AT-111 — Viewing pack ↔ appointment. Linked pack → Open + (when prepared)
             download buttons, straight from the event. No pack yet → launch one from
             this appointment (schedule-now-prep-later). --}}
        <template x-if="panelData.linked_viewing_pack">
            <span class="inline-flex items-center gap-4">
                <a :href="panelData.linked_viewing_pack.url"
                   class="text-xs font-medium transition-colors hover:opacity-70 inline-flex items-center gap-1"
                   style="color: var(--text-primary); text-decoration:none;">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/></svg>
                    Open pack
                </a>
                <template x-if="panelData.linked_viewing_pack.property_count > 0">
                    <span class="inline-flex items-center gap-4">
                        <a :href="panelData.linked_viewing_pack.buyer_pack_url"
                           class="text-xs font-medium transition-colors hover:opacity-70 inline-flex items-center gap-1"
                           style="color: var(--brand-button); text-decoration:none;">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                            Download Buyer Pack
                        </a>
                        <a :href="panelData.linked_viewing_pack.agent_sheet_url"
                           class="text-xs font-medium transition-colors hover:opacity-70 inline-flex items-center gap-1"
                           style="color: var(--text-secondary); text-decoration:none;">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                            Download Agent Sheet
                        </a>
                    </span>
                </template>
            </span>
        </template>
        <template x-if="!panelData.linked_viewing_pack && panelData.is_editable">
            <form :action="panelData.viewing_pack_launch_url" method="POST" class="inline">
                @csrf
                <button type="submit"
                        class="text-xs font-medium transition-colors hover:opacity-70 inline-flex items-center gap-1"
                        style="color: var(--brand-button); background:none; border:none; cursor:pointer;">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    Prepare viewing pack
                </button>
            </form>
        </template>

        <template x-if="panelData.is_actionable && panelData.completion_behaviour === 'require_feedback'">
            <button type="button" @click="openFeedbackModal(panelData.id)"
                    class="text-xs font-medium transition-colors hover:opacity-70 inline-flex items-center gap-1"
                    style="color: #00d4aa; background: none; border: none; cursor: pointer;">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                Capture Feedback to Complete
            </button>
        </template>
        <template x-if="panelData.is_actionable && panelData.completion_behaviour === 'require_reason'">
            <button type="button" @click="reasonPickerAction = 'complete'; reasonPickerEventId = panelData.id; reasonPickerOpen = true"
                    class="text-xs font-medium transition-colors hover:opacity-70 inline-flex items-center gap-1"
                    style="color: var(--text-secondary); background: none; border: none; cursor: pointer;">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                Complete with Reason
            </button>
        </template>
        <template x-if="panelData.is_actionable && (!panelData.completion_behaviour || panelData.completion_behaviour === 'freeform')">
            <form :action="'/corex/command-center/calendar/' + panelData.id + '/complete'" method="POST">
                @csrf
                <template x-if="panelData.metadata && panelData.metadata.deal_ref">
                    <div class="mb-2 px-2 py-1 rounded text-[10px] inline-flex items-center gap-1" style="background:rgba(245,158,11,0.1);color:#f59e0b;border:1px solid rgba(245,158,11,0.2);">
                        <span>Deal Step:</span> <span x-text="(panelData.metadata.step_name || 'Step') + ' — ' + panelData.metadata.deal_ref"></span>
                    </div>
                </template>
                <button type="submit" class="text-xs font-medium transition-colors hover:opacity-70 inline-flex items-center gap-1"
                        style="color: var(--text-secondary); background: none; border: none; cursor: pointer;">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    <span x-text="(panelData.metadata && panelData.metadata.deal_ref) ? 'Mark Step Complete' : 'Complete'"></span>
                </button>
            </form>
        </template>
        <template x-if="panelData.is_actionable">
            <button type="button" @click="reasonPickerAction = 'dismiss'; reasonPickerEventId = panelData.id; reasonPickerOpen = true"
                    class="text-xs font-medium transition-colors hover:opacity-70 inline-flex items-center gap-1"
                    style="color: var(--text-muted); background: none; border: none; cursor: pointer;">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                Dismiss
            </button>
        </template>
        {{-- Delete — on EVERY editable panel (incl. private/informational events that
             have no Complete/Dismiss). Soft-delete, audited. Recurring events branch
             into the this/future/all scope modal; one-offs get a simple confirm. --}}
        <template x-if="panelData.is_editable">
            <button type="button" @click="deleteEvent()"
                    class="text-xs font-medium transition-colors hover:opacity-70 inline-flex items-center gap-1"
                    style="color: #ef4444; background: none; border: none; cursor: pointer;">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                <span x-text="panelData.is_recurring ? 'Delete…' : 'Delete'"></span>
            </button>
        </template>
    </div>
</aside>
<aside x-show="rightPanelOpen" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:leave="transition ease-in duration-150"
       class="absolute inset-0 flex flex-col overflow-hidden"
       style="z-index: 20; background: var(--surface);">
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
                            x-show="calLayers.includes(evt.layer || 'appointments')"
                            class="w-full text-left flex items-center gap-2 px-2 py-1.5 rounded transition hover:opacity-80"
                            style="background: var(--surface-2);">
                        <span class="w-2 h-2 rounded-full flex-shrink-0" :style="'background:' + ragHex(evt.rag)"></span>
                        <div class="min-w-0 flex-1">
                            <div class="text-[11px] font-medium truncate" style="color: var(--text-primary);" x-text="evt.title"></div>
                            <div class="text-[10px]" style="color: var(--text-muted);" x-text="evt.time + ' Â· ' + evt.classLabel"></div>
                        </div>
                    </button>
                </template>
            </div>
        </div>
    </div>
</aside>
</div>

{{-- ══════ RIGHT SIDE PANEL ══════ --}}

{{-- ══════ CREATE EVENT PANEL (column-flex sibling — Google/Outlook layout) ══════
     The panel docks as a real column inside the flex row. When x-show flips
     to true, the panel takes its column space and the grid (flex-1) shrinks
     to make room — no overlap. NO fixed positioning, NO backdrop, NO
     click-outside-to-close. Escape closes.

     CAL-2 width contract:
       - Mobile (< sm): w-full — acts as a full-screen sheet because the
         calendar would be too narrow alongside any side panel anyway.
       - sm and up: fixed 420px column docked at the right edge of the
         flex row. Calendar column's min-w-[320px] guarantees it stays
         visible to the left even when the filter panel is also open.
         420 + 360 (filter panel) + 320 (calendar min) = 1100px fits a
         typical laptop viewport; on narrower screens the calendar
         scrolls horizontally rather than collapsing to zero.
     The flex-shrink-0 lock keeps the panel at 420px when the grid
     tries to claim space — without it max-w-md plus flex-shrink:1
     allowed the panel to compress below readable width on very wide
     screens with multiple asides open. --}}

{{-- ══════ EVENT DETAIL PANEL (column-flex sibling — Google/Outlook layout) ══════
     Replaces the previous fixed-positioned overlay. Behaves as a column
     beside the grid: no backdrop, no click-outside-to-close, prev/next/view
     navigation no longer dismisses it. Escape closes. --}}

{{-- ══════ RECURRING EDIT/DELETE SCOPE MODAL ══════
     Shown when saving an edit to (or deleting) a recurring series. The user must
     pick this / this-and-future / all. For edit it sets the hidden recur_scope +
     occurrence_date and submits the create/edit form; for delete it issues a
     scoped DELETE. No hard deletes — see RecurrenceEditService. --}}
<div x-show="recurScopeModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50" @click="recurScopeModalOpen = false"></div>
    <div class="relative w-full max-w-sm rounded-md shadow-2xl p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h3 class="text-sm font-semibold mb-1" style="color: var(--text-primary);"
            x-text="recurScopeMode === 'delete' ? 'Delete recurring event' : 'Edit recurring event'"></h3>
        <p class="text-xs mb-4" style="color: var(--text-muted);"
           x-text="'This event repeats. Apply the ' + (recurScopeMode === 'delete' ? 'deletion' : 'change') + ' to:'"></p>
        <div class="space-y-2 mb-4">
            <label class="flex items-center gap-2 text-sm cursor-pointer px-3 py-2 rounded"
                   style="color: var(--text-primary); background: var(--surface-2); border: 1px solid var(--border);">
                <input type="radio" name="recurScopeChoice" value="this" x-model="recurScopeChoice">
                This event only
            </label>
            <label class="flex items-center gap-2 text-sm cursor-pointer px-3 py-2 rounded"
                   style="color: var(--text-primary); background: var(--surface-2); border: 1px solid var(--border);">
                <input type="radio" name="recurScopeChoice" value="future" x-model="recurScopeChoice">
                This and following events
            </label>
            <label class="flex items-center gap-2 text-sm cursor-pointer px-3 py-2 rounded"
                   style="color: var(--text-primary); background: var(--surface-2); border: 1px solid var(--border);">
                <input type="radio" name="recurScopeChoice" value="all" x-model="recurScopeChoice">
                All events in the series
            </label>
        </div>
        <div class="flex justify-end gap-2">
            <button type="button" @click="recurScopeModalOpen = false"
                    class="text-xs px-3 py-1.5 rounded" style="color: var(--text-muted);">Cancel</button>
            <button type="button" @click="confirmRecurScope()"
                    class="text-xs font-medium px-3 py-1.5 rounded text-white"
                    :style="recurScopeMode === 'delete' ? 'background:#ef4444;' : 'background: var(--brand-button);'"
                    x-text="recurScopeMode === 'delete' ? 'Delete' : 'Save'"></button>
        </div>
    </div>
</div>

{{-- ══════ ONE-OFF DELETE CONFIRM MODAL ══════
     Non-recurring events: a simple confirm before the audited soft-delete. --}}
<div x-show="deleteConfirmOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50" @click="deleteConfirmOpen = false"></div>
    <div class="relative w-full max-w-sm rounded-md shadow-2xl p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h3 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Delete this event?</h3>
        <p class="text-xs mb-4" style="color: var(--text-muted);">
            <span x-text="panelData.title"></span> will be removed from the calendar. This is a soft delete — an administrator can recover it.
        </p>
        <div class="flex justify-end gap-2">
            <button type="button" @click="deleteConfirmOpen = false"
                    class="text-xs px-3 py-1.5 rounded" style="color: var(--text-muted);">Cancel</button>
            <button type="button" @click="confirmDeleteOneOff()" :disabled="deleteSaving"
                    class="text-xs font-medium px-3 py-1.5 rounded text-white" style="background:#ef4444;">
                <span x-show="!deleteSaving">Delete</span>
                <span x-show="deleteSaving" x-cloak>Deleting…</span>
            </button>
        </div>
    </div>
</div>

    {{-- AT-164 full-calendar mode — slim floating REOPEN tab, shown only when the panel
         is fully hidden. The calendar occupies the full width; this obvious tab at the
         right edge brings the agenda panel back. Persisted state, reset by Reset view. --}}
    <button type="button" x-show="panelCollapsed" x-cloak @click="togglePanelCollapse()"
            class="absolute top-2 right-0 flex flex-col items-center gap-1 py-2 px-1 rounded-l-md hover:opacity-90 transition-opacity"
            style="z-index: 25; background: var(--surface-2); border: 1px solid var(--border); border-right: none; color: var(--text-muted);"
            title="Show agenda panel">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
        <span class="text-[9px] font-bold uppercase tracking-wider" style="writing-mode: vertical-rl;">Agenda</span>
    </button>

</div>{{-- END flex row (grid + panel) --}}

{{-- ══════ AT-164 COCKPIT — BOTTOM TILE STRIP (fixed height; N EQUAL columns; NEVER
     scrolls horizontally and never grows; each tile scrolls its own list) ══════ --}}
<section x-data="calendarDeck()" x-init="init()" data-tour="cal-deck"
         @pointermove.window="stripResizeMove($event); ratioMove($event)"
         @pointerup.window="stripResizeEnd(); ratioEnd()"
         class="flex-shrink-0 px-4 lg:px-6 pb-2 relative" style="background: var(--bg);">
    {{-- AT-164 full-calendar mode — EMPTY DECK. With zero tiles the strip disappears
         entirely (the calendar block takes the full height); a slim, findable restore
         bar remains so the deck is never lost. "Show deck" re-opens it in edit mode to
         pick tiles. Persisted per user (empty layout) and reset by Reset view. --}}
    <div x-show="cards.length === 0 && !editing" x-cloak
         class="rounded-md px-3 py-1 flex items-center justify-between"
         style="background: var(--surface); border: 1px dashed var(--border);">
        <div class="flex items-center gap-2 min-w-0" style="color: var(--text-muted);">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
            <span class="text-xs font-semibold">Deck hidden</span>
        </div>
        <button type="button" @click="editing = true" class="corex-btn-outline text-[11px] py-0.5" title="Show the deck and pick tiles">Show deck</button>
    </div>

    {{-- Top-edge resize handle — drag to change the calendar/strip vertical split
         (inline critical styling; pointer events). Hidden when collapsed OR empty. --}}
    <div x-show="!stripCollapsed && (cards.length > 0 || editing)" @pointerdown="stripResizeStart($event)"
         class="h-2.5 flex items-center justify-center cursor-ns-resize" title="Drag to resize the calendar / deck split">
        <div class="w-10 h-1 rounded-full transition-colors" style="background: var(--border);" :style="_resize.on ? 'background: var(--brand-button);' : ''"></div>
    </div>
    <div class="rounded-md px-3 py-1.5" style="background: var(--surface); border: 1px solid var(--border);"
         x-show="cards.length > 0 || editing">
        {{-- Compact header --}}
        <div class="flex items-center justify-between gap-3" :class="stripCollapsed ? '' : 'mb-1.5'">
            <div class="flex items-center gap-2 min-w-0">
                <button type="button" @click="toggleStripCollapse()" class="w-5 h-5 rounded flex items-center justify-center hover:opacity-70 flex-shrink-0" style="color: var(--text-muted);" :title="stripCollapsed ? 'Expand deck' : 'Collapse deck'">
                    <svg class="w-4 h-4 transition-transform" :class="stripCollapsed && '-rotate-90'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
                </button>
                <h2 class="text-xs font-semibold truncate flex-shrink-0" style="color: var(--text-primary);">My Deck</h2>
                <span class="text-[11px] flex-shrink-0" style="color: var(--text-muted);" x-text="'· ' + cards.length + '/' + slots"></span>
                {{-- Collapsed: tile name chips + count badges (one slim bar) --}}
                <div x-show="stripCollapsed" x-cloak class="flex items-center gap-1.5 overflow-x-auto min-w-0">
                    <template x-for="c in cards" :key="'chip'+c.card_id">
                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] flex-shrink-0" style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">
                            <span class="truncate" style="max-width: 120px;" x-text="c.title"></span>
                            <span x-show="(c.count||0)>0" class="font-bold" :style="'color:' + (window.CoreXTile ? window.CoreXTile.accent(c) : 'var(--brand-button)')" x-text="c.count>99?'99+':c.count"></span>
                        </span>
                    </template>
                </div>
                <span x-show="saving" x-cloak class="text-[11px] flex-shrink-0" style="color: var(--text-muted);">Saving…</span>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0" x-show="!stripCollapsed">
                {{-- AT-164 explicit-save — the "Save default" / "Reset" arrangement controls
                     live in the ALWAYS-VISIBLE top toolbar (near Stream/Pages), NOT here:
                     they must stay reachable when the deck itself is hidden (Johan QA1). --}}
                <div x-show="editing" x-cloak class="relative" @click.outside="pickerOpen = false">
                    <button type="button" @click="pickerOpen = !pickerOpen" :disabled="!canAddMore"
                            class="corex-btn-outline text-[11px] py-0.5 disabled:opacity-40"
                            :title="canAddMore ? 'Add a tile' : 'Deck is full ('+slots+' slots)'">+ Add tile</button>
                    <div x-show="pickerOpen" x-cloak class="absolute right-0 bottom-full mb-1 w-64 max-h-72 overflow-y-auto rounded-md py-1"
                         style="z-index: 30; background: var(--surface-2); border: 1px solid var(--border); box-shadow: 0 8px 24px rgba(0,0,0,0.35);">
                        <template x-if="availableToAdd.length === 0"><div class="px-3 py-2 text-xs" style="color: var(--text-muted);">All tiles are on your Deck.</div></template>
                        <template x-for="t in availableToAdd" :key="t.tile_id">
                            <button type="button" @click="addTile(t.tile_id)" class="w-full text-left px-3 py-2 text-xs flex items-center gap-2 transition-colors hover:bg-[color:var(--surface)]" style="color: var(--text-secondary);">
                                <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" :style="t.launch ? 'background: var(--brand-button);' : 'background: var(--text-muted);'"></span>
                                <span class="truncate" x-text="t.title"></span>
                            </button>
                        </template>
                    </div>
                </div>
                <button type="button" @click="toggleEdit()" class="corex-btn-outline text-[11px] py-0.5 inline-flex items-center gap-1" :style="editing ? 'background: var(--brand-button); color:#fff;' : ''">
                    <span x-text="editing ? 'Done' : 'Edit Deck'"></span>
                </button>
            </div>
        </div>

        {{-- Empty deck --}}
        <template x-if="!stripCollapsed && cards.length === 0">
            <div class="py-4 text-center">
                <p class="text-xs" style="color: var(--text-muted);">Your Deck is empty.</p>
                <button type="button" @click="editing = true" class="corex-btn-outline text-[11px] mt-1">Add tiles</button>
            </div>
        </template>

        {{-- N columns — widths from the saved per-user ratios (equal by default); the
             column count always matches the tile count so there is never a horizontal
             scrollbar. Height comes from the (draggable) strip split. --}}
        @once
        <style>
            .cal-deck-grid { display: grid; gap: 0.75rem; }
            .cal-deck-grid > * { min-width: 0; }
        </style>
        @endonce
        <div class="cal-deck-grid" x-show="!stripCollapsed && cards.length > 0" x-ref="deckGrid"
             :style="'grid-template-columns:' + gridTemplate() + '; height:' + (stripHeight - 6) + 'px;'">
            <template x-for="(card, idx) in cards" :key="card.card_id">
                <div class="relative"
                     :draggable="editing"
                     @dragstart="dragStart(idx, $event)" @dragover.prevent="dragOver(idx)" @drop.prevent="drop(idx)" @dragend="dragEndDeck()"
                     :style="editing ? 'cursor: move;' : ''"
                     :class="editing && dragIndex === idx && 'opacity-50'">
                    {{-- Column-ratio resize handle (between this tile and the next) --}}
                    <div x-show="idx < cards.length - 1" @pointerdown="ratioStart(idx, $event)"
                         class="absolute top-0 flex items-center justify-center cursor-col-resize" style="right: -0.5rem; width: 0.75rem; height: 100%; z-index: 15;" title="Drag to resize columns">
                        <div class="rounded-full transition-colors" style="width: 2px; height: 2rem; background: var(--border);" :style="(_ratio.on && _ratio.i === idx) ? 'background: var(--brand-button);' : ''"></div>
                    </div>
                    <div x-show="editing" x-cloak class="absolute top-1.5 right-1.5 z-20 flex items-center gap-1">
                        <span class="w-5 h-5 rounded flex items-center justify-center" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);" title="Drag to reorder">
                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/></svg>
                        </span>
                        <button type="button" @click="removeTile(card.card_id)" class="w-5 h-5 rounded flex items-center justify-center transition hover:opacity-80"
                                style="background: var(--surface); border: 1px solid var(--border); color: var(--ds-crimson, #c41e3a);" title="Remove from Deck">
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <x-tile :var="'card'" :compact="true" />
                </div>
            </template>
        </div>
    </div>
</section>
</div>{{-- END outer x-data wrapper --}}

<script>
/* ═══════════════════════════════════════════════════════════════════════════
   AT-164 cockpit — EXPLICIT-SAVE arrangement model (2026-07-06, replaces auto-persist)

   Three tiers of arrangement state:
     • SAVED DEFAULT  — server-injected from calendar_user_preferences on every page
                        load. Written ONLY by "Save as my default" (window.CoreXCal.save).
     • TRANSIENT      — sessionStorage. Every in-session change writes here SYNCHRONOUSLY
                        (no debounce, no DB). Survives navigate-away-and-return (same tab).
                        A hard reload discards it → the saved default renders.
     • FACTORY/ROLE   — the fallback when a user never saved (null pref columns).

   This kills the "popping panels" bug: the old model debounced every change to the DB
   default, so a hide + navigate-within-400ms abandoned the write and the panel came back.
   Now the hide is recorded synchronously in the transient and re-applied on return; the
   DB is only touched on an explicit Save.

   Client-reactive tiers (collapse / height / ratios / deck / layers) apply instantly on
   load. Structural tiers (view / scroll mode) are server-rendered shells carried across
   navigation via a single guarded ?view=&scroll= redirect (never on a reload).
   ═══════════════════════════════════════════════════════════════════════════ */
window.CoreXCal = {
    KEY: 'corex.calendar.arrangement',
    _reload: (() => {
        try {
            const nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
            if (nav && nav.type) return nav.type === 'reload';
            // Legacy fallback.
            return performance.navigation && performance.navigation.type === 1;
        } catch (e) { return false; }
    })(),
    saveUrl: '{{ route('command-center.calendar.cockpit.save') }}',
    csrf: (document.querySelector('meta[name="csrf-token"]') || {}).content || '',
    // Live component registry — set by each Alpine component's init so Save can read the
    // true current arrangement across components.
    page: null, deck: null, layers: null,

    FLASH_KEY: 'corex.calendar.flash',
    isReload() { return this._reload; },
    // On a hard reload, discard the transient so the SAVED DEFAULT renders. Runs at parse
    // time — before any Alpine component reads get() — so components see a clean slate. Also
    // surfaces any queued cross-reload confirmation toast (e.g. after Reset).
    boot() {
        if (this._reload) { try { sessionStorage.removeItem(this.KEY); } catch (e) {} }
        const self = this;
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', () => self.showPendingFlash()); }
        else { self.showPendingFlash(); }
    },
    // Cross-reload flash: Reset reloads the page, so its confirmation must survive the reload.
    // Queued in sessionStorage, drained on the next load through the canonical CoreX toast.
    flashOnNextLoad(message, type) { try { sessionStorage.setItem(this.FLASH_KEY, JSON.stringify({ message: message, type: type || 'success' })); } catch (e) {} },
    showPendingFlash() {
        let f = null;
        try { f = JSON.parse(sessionStorage.getItem(this.FLASH_KEY) || 'null'); sessionStorage.removeItem(this.FLASH_KEY); } catch (e) {}
        if (!f || !f.message) return;
        const show = () => { if (typeof window.showToast === 'function') { window.showToast(f.message, f.type || 'success'); } else { setTimeout(show, 120); } };
        show();
    },
    get() { try { return JSON.parse(sessionStorage.getItem(this.KEY) || '{}') || {}; } catch (e) { return {}; } },
    has(k) { return Object.prototype.hasOwnProperty.call(this.get(), k); },
    patch(obj) {
        try { const c = this.get(); Object.assign(c, obj); sessionStorage.setItem(this.KEY, JSON.stringify(c)); }
        catch (e) { /* private-mode / quota — degrade silently */ }
    },
    clear() { try { sessionStorage.removeItem(this.KEY); } catch (e) {} },

    // Promote the CURRENT LIVE arrangement to the per-user saved default (the ONLY DB write).
    // Returns Promise<boolean>: true on a persisted 2xx, false on ANY failure (network OR a
    // non-2xx status). On failure the transient is NOT cleared and NO false success is
    // signalled — the caller shows an error state and the live arrangement is left intact.
    async save() {
        const body = {};
        if (this.page)  { body.panel_collapsed = !!this.page.panelCollapsed; }
        if (this.deck)  {
            body.strip_collapsed = !!this.deck.stripCollapsed;
            body.strip_height    = Math.round(this.deck.stripHeight || 176);
            body.tile_ratios     = (this.deck.cockpit && Array.isArray(this.deck.cockpit.tile_ratios))
                                      ? this.deck.cockpit.tile_ratios.map(Number) : [];
            body.deck_layout     = Array.isArray(this.deck.layout) ? this.deck.layout.slice() : [];
        }
        if (this.layers) { body.layers = Array.isArray(this.layers.active) ? this.layers.active.slice() : []; }
        // AT-164 view-trap fix (2026-07-07): the landing VIEW and SCROLL mode are promoted to
        // the saved default ONLY when the user DELIBERATELY chose them this session — i.e.
        // clicked the view switcher / Stream·Pages toggle, the ONLY writers of the transient
        // `view` / `scroll_mode` keys (toolbar links above). A view reached by an event-click
        // or a ?view= deep-link never writes the transient, so it is navigation — not a saved
        // preference — and is OMITTED here, leaving default_view untouched. Root cause of five
        // agents silently locked into Day view: save() previously grabbed the server-rendered
        // $currentView, promoting a forced day view on every arrangement save.
        const _t = this.get();
        if (_t && typeof _t.scroll_mode === 'string') { body.scroll_mode = _t.scroll_mode; }
        if (_t && typeof _t.view === 'string')        { body.view        = _t.view; }
        try {
            const r = await fetch(this.saveUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });
            if (!r.ok) { return false; }   // 4xx/5xx — NOT a success (fetch does not reject on these)
            // Persisted. Current == default now → drop the transient so a later reload shows
            // exactly what was just saved.
            this.clear();
            return true;
        } catch (e) {
            return false;                   // network failure — transient kept, no false success
        }
    },

    // Reset = restore the SAVED default, discarding transients. No DB write (the saved
    // default is untouched). Clear transient, queue a confirmation for after the reload, and
    // reload a clean URL (strip transient params).
    reset() {
        this.clear();
        this.flashOnNextLoad('Calendar default restored', 'success');
        const u = new URL(window.location.href);
        ['anchor', 'view', 'scroll', 'date'].forEach(p => u.searchParams.delete(p));
        window.location.replace(u.pathname + (u.searchParams.toString() ? '?' + u.searchParams.toString() : ''));
    },

    // Structural (view/scroll) transient carry-across-navigation. On a NON-reload load with
    // no explicit ?view=/?scroll= param, if the transient asks for a different shell than the
    // server rendered (the saved default), redirect ONCE to apply it. Guarded so it can't
    // loop (the redirected URL carries the params) and never fires on a reload (transient is
    // already cleared). Returns true if it redirected (caller should stop init).
    reconcileStructural(curView, curScroll) {
        if (this._reload) return false;
        const u = new URL(window.location.href);
        if (u.searchParams.has('view') || u.searchParams.has('scroll')) return false;
        const t = this.get();
        const wantView   = t.view;
        const wantScroll = t.scroll_mode;
        const viewDiff   = wantView   && wantView   !== curView;
        const scrollDiff = wantScroll && wantScroll !== curScroll;
        if (!viewDiff && !scrollDiff) return false;
        u.searchParams.set('view',   wantView   || curView);
        u.searchParams.set('scroll', wantScroll || curScroll);
        window.location.replace(u.pathname + '?' + u.searchParams.toString());
        return true;
    },
};
window.CoreXCal.boot();

/* AT-164 — the always-visible toolbar Save/Reset pair with explicit feedback (Johan QA:
   "a confirmation that save worked"). Success: the button briefly flips to "Saved ✓" +
   the canonical CoreX toast ("Calendar default saved"). Failure (network/500): the button
   shows "Save failed", an error toast fires, and it returns to actionable — never a silent
   nothing, never a false success. Reset confirms via a cross-reload toast. */
function arrangementControls() {
    return {
        state: 'idle',   // idle | saving | saved | error
        _t: null,
        saveLabel() { return { idle: 'Save default', saving: 'Saving…', saved: 'Saved', error: 'Save failed' }[this.state] || 'Save default'; },
        async save() {
            if (this.state === 'saving') return;
            clearTimeout(this._t);
            this.state = 'saving';
            const ok = await window.CoreXCal.save();
            if (ok) {
                this.state = 'saved';
                if (typeof window.showToast === 'function') window.showToast('Calendar default saved', 'success');
                this._t = setTimeout(() => { this.state = 'idle'; }, 2200);
            } else {
                this.state = 'error';
                if (typeof window.showToast === 'function') window.showToast('Save failed — try again', 'error');
                this._t = setTimeout(() => { this.state = 'idle'; }, 3500);  // returns to actionable
            }
        },
        reset() { window.CoreXCal.reset(); },
    };
}

function calendarPage() {
    return {
        showCreateEvent: false,
        // Part B — organizer self double-booking (soft, non-blocking) warning.
        currentUserId: {{ auth()->id() ?? 'null' }},
        selfConflicts: [],
        _selfConflictTimer: null,
        form: { title: '', category: '', startDate: '', startTime: '', endDate: '', endTime: '', description: '', allDay: false, eventNature: 'actionable',
                recurFreq: '', recurInterval: 1, recurEndType: 'never', recurUntil: '', recurCount: 10, recurScope: '', occurrenceDate: '',
                // AT-178 reminder keys. Defaults: popup ON, email OFF, 60 min (Johan).
                sendReminder: true, reminderOffset: 60, reminderPopup: true, reminderEmail: false },
        // Recurring edit/delete scope modal state.
        recurScopeModalOpen: false,
        recurScopeMode: 'edit',        // 'edit' | 'delete'
        recurScopeChoice: 'this',
        editIsRecurring: false,        // the event being edited is a recurring series/occurrence
        editOccurrenceDate: '',        // the clicked occurrence's date (Y-m-d)
        // One-off (non-recurring) delete confirm.
        deleteConfirmOpen: false,
        deleteSaving: false,
        endManuallyEdited: false,
        selectedDate: '{{ $anchorDate->toDateString() }}',
        // CAL-2 — explicit "user actively clicked this day to seed a new
        // event" signal. Independent of selectedDate (which doubles as the
        // day-preview index and is cleared by Escape — the resulting state
        // collision was the date-passthrough bug). Set by selectDate +
        // dragStart, consumed by openBlank, cleared on Escape / panel
        // close. null = "no recent date pick" → openBlank falls back to
        // today, which is the spec'd behaviour for the global + New Event
        // button.
        pendingCreateDate: null,
        editMode: false,
        editingEventId: null,
        submitting: false,
        panelOpen: false,
        panelData: {},
        contextMode: 'create',
        // AT-164 cockpit v2 — panel resident agenda + per-user panel collapse.
        agenda: @json($agenda ?? []),
        // AT-164 Gate 6 — the active layer set, mirrored here so the panel agenda
        // (a calendar surface) can hide/show its items reactively when the Layers
        // control toggles. Kept in sync via the calendar:layers-changed event.
        calLayers: @json($activeLayers ?? []),
        panelCollapsed: {{ !empty($cockpit['panel_collapsed']) ? 'true' : 'false' }},
        _panelSaveTimer: null,
        quick: { title: '', category: '{{ ($manualCreatableClasses->first()->event_class ?? 'viewing') }}', date: '{{ ($anchorDate ?? now())->toDateString() }}', time: '09:00', allDay: false, saving: false, error: '', ok: false },
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
        // Surfaced when the save POST returns non-2xx or throws — rendered
        // inline at the modal footer. Replaces the prior silent fail where
        // a 500 / 422 left the button dead with no user feedback.
        feedbackError: null,
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
                        'layer' => $e->layer_key ?? 'appointments', // AT-164 Gate 6
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
                        'layer' => $bar['layer'] ?? ($bar['event']->layer_key ?? 'appointments'), // AT-164 Gate 6
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
                if (this.selectedDate)    { this.selectedDate = null; this.pendingCreateDate = null; e.preventDefault(); return; }
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
            this.form = { title: '', category: '', startDate: dateStr, startTime: nextQ, endDate: dateStr, endTime: this.addHour(nextQ), description: '', allDay: false, eventNature: 'actionable',
                          sendReminder: true, reminderOffset: 60, reminderPopup: true, reminderEmail: false };
            this.endManuallyEdited = false;
            this.editMode = false;
            this.editingEventId = null;
            this.submitting = false;
            this.showCreateEvent = true;
            this.clearStalePickerState();
        },

        // View switches in this calendar are full page reloads (<a href>
        // links). Without persistence the create-event panel would lose its
        // open state + form contents on every view switch. We snapshot to
        // sessionStorage on beforeunload and restore on init.
        persistCreateEventState() {
            try {
                if (!this.showCreateEvent) {
                    sessionStorage.removeItem('corex.calendar.createEventState');
                    return;
                }
                sessionStorage.setItem('corex.calendar.createEventState', JSON.stringify({
                    showCreateEvent: true,
                    form: this.form,
                    editMode: this.editMode,
                    editingEventId: this.editingEventId,
                    // Snapshot the property + attendee picker chips so they
                    // survive too. Read directly from the live Alpine pickers.
                    pickedProperties: this.readPickerChosen('propertySearch'),
                    pickedAttendees:  this.readPickerChosen('contactSearch'),
                }));
            } catch (e) { console.warn('persist create-event state failed:', e); }
        },
        // Mirror persistence for the event-detail panel (panelOpen).
        // Snapshot just the event id so we can re-open the same event on the
        // next page load — the full panelData is fetched fresh from the
        // server so it reflects any state changes since.
        persistEventDetailState() {
            try {
                if (!this.panelOpen || !this.panelData || !this.panelData.id) {
                    sessionStorage.removeItem('corex.calendar.eventDetailState');
                    return;
                }
                sessionStorage.setItem('corex.calendar.eventDetailState', JSON.stringify({
                    panelOpen: true,
                    eventId: this.panelData.id,
                }));
            } catch (e) { console.warn('persist event-detail state failed:', e); }
        },
        restoreEventDetailState() {
            try {
                const raw = sessionStorage.getItem('corex.calendar.eventDetailState');
                if (!raw) return;
                const state = JSON.parse(raw);
                if (!state || !state.panelOpen || !state.eventId) return;
                this.openEventPanel(state.eventId);
            } catch (e) { console.warn('restore event-detail state failed:', e); }
        },
        readPickerChosen(componentMatch) {
            try {
                const el = document.querySelector('[x-data*="' + componentMatch + '"]');
                return el ? (Alpine.$data(el).chosen || []) : [];
            } catch { return []; }
        },
        // CAL-6 — clear stale state on every fresh "+ New Event" open.
        // Without this, attendees and properties left over from a prior
        // URL prefill (?prefill_contact_id=X), session-restore on a view
        // switch, or an edit-mode load would bleed into the new event:
        // the user would see chips that look "auto-filled" but were
        // actually carried forward from the previous panel state. The
        // bug surfaced on staging where a buyer-pipeline handoff for
        // contact 3177 (Larochelle) left that contact pinned in the
        // attendee picker; subsequent picking of a different property
        // didn't displace it, and the user read 3177 as the property's
        // auto-fill. Runs after the panel becomes visible so Alpine has
        // wired up the picker components.
        clearStalePickerState() {
            this.$nextTick(() => {
                const form = document.getElementById('createEventFormV2');
                if (!form) return;
                const propPicker = form.querySelector('[x-data*="propertySearch"]');
                if (propPicker) Alpine.$data(propPicker).chosen = [];
                const attPicker = form.querySelector('[x-ref="attendeePicker"]');
                if (attPicker) Alpine.$data(attPicker).chosen = [];
            });
        },
        restoreCreateEventState() {
            try {
                const raw = sessionStorage.getItem('corex.calendar.createEventState');
                if (!raw) return;
                const state = JSON.parse(raw);
                if (!state || !state.showCreateEvent) return;
                this.form = state.form || this.form;
                this.editMode = !!state.editMode;
                this.editingEventId = state.editingEventId || null;
                this.showCreateEvent = true;
                // Restore picker chips after Alpine wires the new pickers.
                this.$nextTick(() => {
                    if (Array.isArray(state.pickedProperties) && state.pickedProperties.length) {
                        const el = document.querySelector('[x-data*="propertySearch"]');
                        if (el) Alpine.$data(el).chosen = state.pickedProperties;
                    }
                    if (Array.isArray(state.pickedAttendees) && state.pickedAttendees.length) {
                        const el = document.querySelector('[x-data*="contactSearch"]');
                        if (el) Alpine.$data(el).chosen = state.pickedAttendees;
                    }
                });
            } catch (e) { console.warn('restore create-event state failed:', e); }
        },

        // Called by date-cell clicks in Month/Week views. Always updates the
        // day-preview selection (selectedDate) AND records the click as the
        // pending-create date (pendingCreateDate) — the latter is what
        // openBlank consumes so the clicked day flows through to a new
        // event even if selectedDate gets cleared by Escape between the
        // click and the toolbar + New Event press (CAL-2). If the panel is
        // already open, also pushes the date into the form so the agent
        // can flip through the calendar to pick a date for the event
        // being created.
        selectDate(dateStr, time = null) {
            this.selectedDate = dateStr;
            this.pendingCreateDate = dateStr;
            if (this.showCreateEvent) {
                // Form already open in the panel — just move its date.
                this.form.startDate = dateStr;
                if (time) this.form.startTime = time;
                if (this.form.endDate && this.form.endDate < dateStr) { this.form.endDate = dateStr; this.endManuallyEdited = false; }
            } else if (!this.panelOpen) {
                // AT-164 cockpit — empty day/slot click opens the FULL New Event form
                // INSIDE the right panel (Johan's model), pre-filled with the clicked date.
                this.openBlank();
                if (time) { this.form.startTime = time; this.form.endTime = this.addHour(time); }
            }
        },
        openBlank() {
            const today = new Date().toISOString().slice(0, 10);
            // CAL-2 — pendingCreateDate wins over selectedDate. selectedDate
            // can legitimately be cleared (Escape) while the user still
            // expects the day they just clicked to seed the new event;
            // pendingCreateDate is the explicit signal for that flow.
            // Falls back to selectedDate (preserves day-view "I'm on this
            // day, + New Event should use this day" behaviour) and finally
            // today (global toolbar button / 'n' shortcut with no
            // prior context).
            const dateToUse = this.pendingCreateDate || this.selectedDate || today;
            const nextQ = this.nextQuarterHour();
            this.form = { title: '', category: '', startDate: dateToUse, startTime: nextQ, endDate: dateToUse, endTime: this.addHour(nextQ), description: '', allDay: false, eventNature: 'actionable',
                          recurFreq: '', recurInterval: 1, recurEndType: 'never', recurUntil: '', recurCount: 10, recurScope: '', occurrenceDate: '',
                          sendReminder: true, reminderOffset: 60, reminderPopup: true, reminderEmail: false };
            this.endManuallyEdited = false;
            this.editMode = false;
            this.editingEventId = null;
            this.editIsRecurring = false;
            this.editOccurrenceDate = '';
            this.submitting = false;
            this.panelCollapsed = false; // opening the create form brings the (possibly hidden) panel back
            this.showCreateEvent = true;
            this.clearStalePickerState();
        },

        // â”€â”€ Prefill from URL params (Schedule from Contact/Buyer) â”€â”€
        handlePrefill() {
            const params = new URLSearchParams(window.location.search);
            const prefillContactId = params.get('prefill_contact_id');
            const prefillClass = params.get('prefill_class');
            const prefillPropertiesRaw = params.get('prefill_properties');
            const prefillAttendeesRaw = params.get('prefill_attendees');
            if (!prefillContactId) return;

            // Parse property handoff from the buyer-pipeline picker. Format:
            // ?prefill_properties=<JSON array of {id, address}>. The address
            // travels with the id so chips render without an extra fetch.
            let prefillProperties = [];
            if (prefillPropertiesRaw) {
                try {
                    const parsed = JSON.parse(prefillPropertiesRaw);
                    if (Array.isArray(parsed)) {
                        prefillProperties = parsed
                            .filter(p => p && p.id)
                            .map(p => ({ id: Number(p.id), address: String(p.address || ('Property #' + p.id)) }));
                    }
                } catch (e) { console.warn('Prefill properties parse failed:', e); }
            }

            // Parse attendee handoff. Format:
            // ?prefill_attendees=<JSON array of {id, name, type, role}>. When
            // present, the chip(s) render immediately with no fetch. The
            // legacy fetch-by-id path below is the fallback for entry points
            // that only have prefill_contact_id.
            let prefillAttendees = [];
            if (prefillAttendeesRaw) {
                try {
                    const parsed = JSON.parse(prefillAttendeesRaw);
                    if (Array.isArray(parsed)) {
                        prefillAttendees = parsed
                            .filter(a => a && a.id && a.name)
                            .map(a => ({
                                id: Number(a.id),
                                name: String(a.name),
                                type: a.type || 'contact',
                                role: a.role || (prefillClass === 'viewing' ? 'buyer_contact' : 'attendee'),
                                phone: a.phone || null,
                                email: a.email || null,
                            }));
                    }
                } catch (e) { console.warn('Prefill attendees parse failed:', e); }
            }

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
                    sendReminder: true, reminderOffset: 60, reminderPopup: true, reminderEmail: false,
                };
                this.editMode = false;
                this.editingEventId = null;
                this.showCreateEvent = true;
                // CAL-7 Class 2 — clear any prior session-restored chips
                // BEFORE the prefill pushes its own. Without this, a
                // session-restored Larochelle (from a previous panel state)
                // would merge with the URL prefill's contact and the user
                // would see TWO chips, one of which they didn't pick.
                this.clearStalePickerState();

                // Pre-populate property picker if the buyer-pipeline handoff
                // passed properties. Runs after $nextTick so the form is in the DOM.
                if (prefillProperties.length) {
                    this.$nextTick(() => {
                        const form = document.getElementById('createEventFormV2');
                        const propPicker = form?.querySelector('[x-data*="propertySearch"]');
                        if (propPicker) {
                            Alpine.$data(propPicker).chosen = prefillProperties;
                        }
                    });
                }

                // Fast path: pre-populate attendee chips from prefill_attendees
                // payload (carries id+name from the source page — no fetch needed).
                if (prefillAttendees.length) {
                    this.$nextTick(() => {
                        const form = document.getElementById('createEventFormV2');
                        const picker = form?.querySelector('[x-ref="attendeePicker"]');
                        if (picker) {
                            Alpine.$data(picker).chosen = prefillAttendees;
                        }
                        if (prefillClass === 'viewing' && prefillAttendees[0]?.name) {
                            this.form.title = 'Viewing with ' + prefillAttendees[0].name;
                        }
                    });
                    return; // skip the fetch fallback below
                }

                // Fallback: fetch contact by ID when only prefill_contact_id was passed
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

        // When the user picks a Type, default the requires-feedback choice to that
        // class's configured nature (classConfigMap). Fires on @change only (user
        // action), so an edit-loaded override is never reset. Self-contained read
        // of the JSON island — no dependency on other helpers.
        applyCategoryNatureDefault() {
            let nature = 'actionable';
            try {
                const map = JSON.parse(document.getElementById('classConfigMap')?.textContent || '{}');
                const cfg = map[this.form.category];
                if (cfg && (cfg.nature === 'actionable' || cfg.nature === 'informational')) nature = cfg.nature;
            } catch (e) { /* fall back to actionable */ }
            this.form.eventNature = nature;
        },

        // â”€â”€ Right Panel â”€â”€
        initPanel() {
            // AT-164 explicit-save — register this component so "Save as my default" can read
            // the live panel state; apply the client-side TRANSIENT over the saved default;
            // and carry any transient view/scroll shell across navigation (guarded redirect).
            window.CoreXCal.page = this;
            if (window.CoreXCal.reconcileStructural(@json($currentView ?? 'month'), @json($scrollMode ?? 'continuous'))) {
                return; // redirecting to apply the transient shell — stop init
            }
            const _t = window.CoreXCal.get();
            if (Object.prototype.hasOwnProperty.call(_t, 'panel_collapsed')) {
                this.panelCollapsed = !!_t.panel_collapsed;
            }

            // AT-164 Gate 6 — keep the panel agenda's layer lens in sync with the Layers
            // control. The layerFilter component broadcasts its active set; we mirror it
            // so the x-for'd agenda items hide/show reactively (calendar surface).
            window.addEventListener('calendar:layers-changed', (e) => {
                if (Array.isArray(e.detail)) this.calLayers = [...e.detail];
            });

            // Part B — recheck the ORGANIZER's own schedule whenever the event's
            // time changes (create OR edit) → surfaces a self double-booking as a
            // soft warning. Watches catch user edits AND programmatic sets
            // (openBlank / openEditModal); toggling all-day clears it.
            ['form.startDate', 'form.startTime', 'form.endDate', 'form.endTime', 'form.allDay']
                .forEach(f => this.$watch(f, () => this.checkSelfConflict()));

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

        // Part B — organizer self double-booking check. Debounced because several
        // time fields can change in one tick (openBlank seeds date+time+end;
        // auto end-time). Reuses the SAME /check-conflicts endpoint + response
        // shape as the invited-agent badge — just against the organizer's own id.
        checkSelfConflict() {
            clearTimeout(this._selfConflictTimer);
            this._selfConflictTimer = setTimeout(() => this._runSelfConflict(), 200);
        },
        async _runSelfConflict() {
            this.selfConflicts = [];
            const start = this.computedEventDate;
            // Only a timed event occupies a slot; all-day / no-start makes no claim.
            if (!start || this.form.allDay || !this.currentUserId) return;
            const end = this.computedEndDate || start;
            try {
                const params = new URLSearchParams({ user_id: this.currentUserId, start, end });
                // When editing, exclude the event itself so it never clashes with itself.
                if (this.editingEventId) params.append('exclude_event_id', this.editingEventId);
                const r = await fetch('/corex/command-center/calendar/check-conflicts?' + params, {
                    headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
                });
                if (!r.ok) return;
                const data = await r.json();
                // Markers (occupies_time=false) are excluded server-side, so they
                // can never appear here. Soft warning only — never blocks save.
                this.selfConflicts = data.has_conflict ? (data.conflicts || []) : [];
            } catch (e) { /* best-effort — a check failure must never block the form */ }
        },

        async openEditModal(eventId) {
            // A synthetic occurrence id (>= 1e8) decodes to a real parent id + the
            // clicked occurrence's date; we edit the PARENT (so recur_scope applies)
            // and load the occurrence view via ?occurrence=.
            const occ = this.decodeOccurrenceId(eventId);
            const fetchId = occ ? occ.parentId : eventId;
            const url = '/corex/command-center/calendar/' + fetchId + (occ ? ('?occurrence=' + occ.date) : '');
            const r = await fetch(url, {
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
            const rc = d.recurrence || null;
            this.form = {
                title: d.title || '',
                category: d.category || '',
                startDate: toDate(ed),
                startTime: toTime(ed) || '09:00',
                endDate: toDate(endD) || toDate(ed),
                endTime: toTime(endD) || '',
                description: d.description || '',
                allDay: isAllDay,
                // Prefill the requires-feedback choice from the loaded event's
                // EFFECTIVE nature so an override round-trips. Set via programmatic
                // form assignment (not the @change handler) so it isn't reset to
                // the class default on edit.
                eventNature: d.event_nature || 'actionable',
                // Prefill recurrence controls so an "edit all" round-trips the series.
                recurFreq: rc ? rc.freq : '',
                recurInterval: rc ? (rc.interval || 1) : 1,
                recurEndType: rc ? (rc.end_type || 'never') : 'never',
                recurUntil: rc ? (rc.until || '') : '',
                recurCount: rc ? (rc.count || 10) : 10,
                recurScope: '',
                occurrenceDate: '',
                // AT-178 — round-trip the saved reminder config. Offsets is an array
                // in the schema; the single-lead-time UI uses the first (or effective
                // default). Channels are the effective popup/email set.
                sendReminder: d.send_reminder !== undefined ? !!d.send_reminder : true,
                reminderOffset: (Array.isArray(d.reminder_offsets) && d.reminder_offsets.length) ? Number(d.reminder_offsets[0]) : 60,
                reminderPopup: Array.isArray(d.reminder_channels) ? d.reminder_channels.includes('popup') : true,
                reminderEmail: Array.isArray(d.reminder_channels) ? d.reminder_channels.includes('email') : false,
            };
            this.endManuallyEdited = !!endD;
            this.editMode = true;
            // Edit the PARENT for a recurring occurrence; the scope modal decides
            // how the change applies (this / future / all).
            this.editingEventId = (occ && d.recurrence_parent_id) ? d.recurrence_parent_id : fetchId;
            this.editIsRecurring = !!(d.is_recurring || d.is_occurrence);
            this.editOccurrenceDate = d.occurrence_date || (occ ? occ.date : '');
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
            // CAL-2 — seed pendingCreateDate so openBlank's date resolution
            // already lands on the dragged day; the explicit startDate
            // override below stays as defensive belt-and-suspenders.
            this.pendingCreateDate = d.dayDate;
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
            try {
                const r = await fetch('/corex/command-center/calendar/' + eventId + '/feedback', {
                    headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
                });
                if (!r.ok) {
                    console.warn('Feedback endpoint returned', r.status);
                    return;
                }
                const data = await r.json();
                // Per-property mode (listing_presentation) returns `items`
                // instead of `contacts`. Normalise so the rest of the
                // pipeline can branch on feedback_mode without crashing.
                const mode = data.feedback_mode || 'per_contact';
                this.feedbackData = {
                    event: data.event || null,
                    feedback_mode: mode,
                    feedback_kind: data.feedback_kind || (mode === 'per_property' ? 'listing_presentation' : 'viewing'),
                    contacts: Array.isArray(data.contacts) ? data.contacts : [],
                    properties: Array.isArray(data.properties) ? data.properties : [],
                    items: Array.isArray(data.items) ? data.items : [],
                    is_multi_property: !!data.is_multi_property,
                    outcomes: data.outcomes || [],
                    concerns: data.concerns || [],
                    lp_outcomes: data.lp_outcomes || [],
                    lp_mandate_types: data.lp_mandate_types || [],
                    lp_concerns: data.lp_concerns || [],
                };
                this.feedbackPropertyStep = 0;
                this.feedbackForm = {};
                this.feedbackError = null;

                if (mode === 'per_property') {
                    // Index per-property form rows by property_id.
                    this.feedbackData.items.forEach(it => {
                        const kd = it.kind_data || {};
                        this.feedbackForm['prop:' + it.property_id] = {
                            outcome:        kd.outcome || '',
                            mandate_type:   kd.mandate_type || '',
                            concern_ids:    Array.isArray(kd.concern_ids) ? kd.concern_ids.map(String) : [],
                            seller_notes:   kd.seller_notes || '',
                            internal_notes: it.internal_notes || '',
                            next_action_notes: it.next_action || '',
                        };
                    });
                } else {
                    this.feedbackData.contacts.forEach(c => {
                        this.feedbackForm[c.id] = {
                            outcome_id: c.outcome_id ? String(c.outcome_id) : '',
                            concern_ids: (c.concerns || []).map(String),
                            seller_visible_notes: c.seller_notes || '',
                            internal_notes: c.internal_notes || '',
                            next_action_notes: c.next_action || '',
                        };
                    });
                }

                this.panelOpen = false;
                this.feedbackOpen = true;
            } catch (e) {
                console.warn('openFeedbackModal failed:', e);
            }
        },
        getCurrentFeedbackPropertyId() {
            if (!this.feedbackData.is_multi_property || !this.feedbackData.properties.length) {
                return (this.feedbackData.properties && this.feedbackData.properties[0]) ? this.feedbackData.properties[0].id : null;
            }
            return this.feedbackData.properties[this.feedbackPropertyStep]?.id || null;
        },

        buildFeedbackPayload() {
            // Per-property mode (listing_presentation) — keys are "prop:<id>"
            if (this.feedbackData.feedback_mode === 'per_property') {
                return {
                    feedback_kind: 'listing_presentation',
                    feedback: Object.entries(this.feedbackForm)
                        .filter(([k, _]) => k.startsWith('prop:'))
                        .map(([k, f]) => ({
                            property_id: parseInt(k.slice('prop:'.length)),
                            kind_specific_data: {
                                outcome:        f.outcome || null,
                                mandate_type:   f.mandate_type || null,
                                concern_ids:    (f.concern_ids || []).map(Number),
                                seller_notes:   f.seller_notes || null,
                            },
                            internal_notes:    f.internal_notes || null,
                            next_action_notes: f.next_action_notes || null,
                        })),
                };
            }

            // Per-contact (viewings) — original behaviour
            const propertyId = this.getCurrentFeedbackPropertyId();
            return {
                feedback_kind: 'viewing',
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

        // Returns a structured result so callers can surface server
        // errors instead of swallowing them. On non-2xx, parses the
        // standard Laravel JSON shape ({message, errors}) and folds
        // validation errors into a single readable message. Never
        // throws on HTTP status — only on network/parse failure.
        async submitFeedbackPayload(payload) {
            const url = '/corex/command-center/calendar/' + this.feedbackData.event.id + '/feedback';
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });
            if (response.ok) {
                return { ok: true, status: response.status };
            }
            let body = null;
            try { body = await response.json(); } catch (_) { /* non-JSON body */ }
            const validationDetail = body && body.errors
                ? Object.values(body.errors).flat().slice(0, 3).join(' · ')
                : null;
            const message = validationDetail
                || (body && body.message)
                || ('Save failed (HTTP ' + response.status + ').');
            console.error('[Capture Feedback] save failed', { url, status: response.status, payload, body });
            return { ok: false, status: response.status, message, errors: body?.errors ?? null };
        },

        async saveFeedback() {
            this.feedbackSaving = true;
            this.feedbackError = null;
            try {
                const payload = this.buildFeedbackPayload();
                const r = await this.submitFeedbackPayload(payload);
                if (r.ok) {
                    this.feedbackOpen = false;
                    window.location.reload();
                } else {
                    this.feedbackError = r.message;
                }
            } catch (e) {
                console.error('[Capture Feedback] network error', e);
                this.feedbackError = 'Network error: ' + (e.message || 'request failed');
            } finally {
                this.feedbackSaving = false;
            }
        },

        async saveFeedbackAndNext() {
            this.feedbackSaving = true;
            this.feedbackError = null;
            try {
                const payload = this.buildFeedbackPayload();
                const r = await this.submitFeedbackPayload(payload);
                if (r.ok) {
                    this.feedbackPropertyStep++;
                    this.resetFeedbackForm();
                } else {
                    this.feedbackError = r.message;
                }
            } catch (e) {
                console.error('[Capture Feedback] network error', e);
                this.feedbackError = 'Network error: ' + (e.message || 'request failed');
            } finally {
                this.feedbackSaving = false;
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

        // â”€â”€ Reason Picker â”€â”€
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
            // AT-164 cockpit \u2014 a clicked event populates the FIXED right context panel
            // (not a slide-over). The full detail/edit slide-over opens from there via
            // "Full details / Edit". panelData is loaded exactly as before.
            this.panelCollapsed = false; // opening detail brings the (possibly hidden) panel back
            this.showCreateEvent = false;
            this.panelOpen = true;  // full detail renders INSIDE the panel (absolute-inset child)
            this.panelData = { title: 'Loading\u2026', colour: null, days_diff: 0 };

            // Synthetic occurrence id (>= 1e8) \u2192 real parent id + ?occurrence=date,
            // so the panel shows THIS occurrence and can offer the scope prompt.
            const occ = this.decodeOccurrenceId(eventId);
            const fetchId = occ ? occ.parentId : eventId;
            const url = '/corex/command-center/calendar/' + fetchId + (occ ? ('?occurrence=' + occ.date) : '');

            fetch(url, {
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

        // AT-164 cockpit — quick-create from the right context panel.
        async quickCreateSubmit() {
            this.quick.error = ''; this.quick.ok = false; this.quick.saving = true;
            try {
                const fd = new FormData();
                fd.append('_token', document.querySelector('meta[name="csrf-token"]').content);
                fd.append('title', this.quick.title);
                fd.append('category', this.quick.category);
                fd.append('event_date', this.quick.allDay ? this.quick.date : (this.quick.date + 'T' + (this.quick.time || '09:00')));
                fd.append('all_day', this.quick.allDay ? '1' : '0');
                const r = await fetch('{{ route('command-center.calendar.store') }}', {
                    method: 'POST', body: fd,
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (r.ok) {
                    this.quick.ok = true; this.quick.title = '';
                    window.dispatchEvent(new Event('focus')); // repaint grid + deck
                    setTimeout(() => { this.quick.ok = false; }, 2500);
                } else {
                    let msg = 'Could not create the event.';
                    try { const j = await r.json(); if (j.errors) msg = Object.values(j.errors).flat()[0] || msg; else if (j.message) msg = j.message; } catch (e) {}
                    this.quick.error = msg;
                }
            } catch (e) { this.quick.error = 'Network error — please retry.'; }
            this.quick.saving = false;
        },

        // AT-164 cockpit — mark the panel's event complete without opening the slide-over.
        completeFromContext() {
            if (!this.panelData || !this.panelData.id) return;
            const fd = new FormData();
            fd.append('_token', document.querySelector('meta[name="csrf-token"]').content);
            fetch('/corex/command-center/calendar/' + this.panelData.id + '/complete', {
                method: 'POST', body: fd, headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
            }).then(r => { if (r.ok) { this.panelData.status = 'completed'; window.dispatchEvent(new Event('focus')); } });
        },

        // AT-164 cockpit — right-panel collapse. EXPLICIT-SAVE model: the change is recorded
        // SYNCHRONOUSLY in the client transient (survives navigation), never auto-persisted to
        // the DB default. It reaches the default only via "Save as my default".
        togglePanelCollapse() {
            this.panelCollapsed = !this.panelCollapsed;
            if (this.panelCollapsed) { this.showCreateEvent = false; this.panelOpen = false; }
            window.CoreXCal.patch({ panel_collapsed: this.panelCollapsed });
        },

        // AT-164 cockpit v2 — reset the WHOLE arrangement to the role default, then reload.
        resetCockpit() {
            fetch('{{ route('command-center.calendar.cockpit.reset') }}', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
                credentials: 'same-origin',
            }).then(() => { const u = new URL(window.location.href); u.searchParams.delete('anchor'); window.location.href = u.pathname; });
        },

        // \u2500\u2500 Recurrence helpers \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
        // Mirror of RecurrenceExpander::syntheticId \u2014 parentId*1e8 + YYYYMMDD.
        // Any id >= 1e8 is a virtual occurrence; decode it to {parentId, date}.
        decodeOccurrenceId(id) {
            const BASE = 100000000;
            id = Number(id);
            if (!Number.isFinite(id) || id < BASE) return null;
            const parentId = Math.floor(id / BASE);
            const ymd = id % BASE;
            const s = String(ymd).padStart(8, '0');
            const date = s.slice(0, 4) + '-' + s.slice(4, 6) + '-' + s.slice(6, 8);
            return { parentId, date };
        },
        recurIntervalUnit() {
            const n = this.form.recurInterval || 1;
            const u = { DAILY: 'day', WEEKLY: 'week', MONTHLY: 'month' }[this.form.recurFreq] || 'day';
            return n === 1 ? u : (u + 's');
        },
        recurSummary() {
            if (!this.form.recurFreq) return '';
            let s = 'Repeats every ' + (this.form.recurInterval || 1) + ' ' + this.recurIntervalUnit();
            if (this.form.recurEndType === 'count') s += ', ' + (this.form.recurCount || 1) + ' times';
            else if (this.form.recurEndType === 'until' && this.form.recurUntil) s += ', until ' + this.form.recurUntil;
            return s;
        },
        onFormSubmit(e) {
            // Editing a recurring series needs a scope decision first. Intercept the
            // native submit, open the scope modal; confirmRecurScope re-submits with
            // recur_scope set so this guard passes through the second time.
            if (this.editMode && this.editIsRecurring && !this.form.recurScope) {
                e.preventDefault();
                this.openRecurScopeModal('edit');
                return;
            }
            this.submitting = true;
            sessionStorage.removeItem('corex.calendar.createEventState');
            this.clearStalePickerState();
        },
        openRecurScopeModal(mode) {
            this.recurScopeMode = mode;
            this.recurScopeChoice = 'this';
            this.recurScopeModalOpen = true;
        },
        // The panel loads an occurrence as its parent (id = parent id) + occurrence_date;
        // reconstruct the synthetic id so openEditModal takes the occurrence path.
        editFromPanel() {
            if (this.panelData.is_occurrence && this.panelData.recurrence_parent_id && this.panelData.occurrence_date) {
                const synth = this.panelData.recurrence_parent_id * 100000000 + Number(this.panelData.occurrence_date.replace(/-/g, ''));
                this.openEditModal(synth);
            } else {
                this.openEditModal(this.panelData.id);
            }
        },
        confirmRecurScope() {
            const scope = this.recurScopeChoice;
            const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
            if (this.recurScopeMode === 'delete') {
                const parentId = this.panelData.recurrence_parent_id || this.panelData.id;
                const occ = this.panelData.occurrence_date || '';
                fetch('/corex/command-center/calendar/' + parentId, {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({ recur_scope: scope, occurrence_date: occ, _token: token }),
                    credentials: 'same-origin',
                }).then(r => {
                    if (r.ok || r.status === 302) {
                        this.recurScopeModalOpen = false;
                        this.panelOpen = false;
                        window.location.reload();
                    }
                }).catch(err => console.warn('Recurring delete failed:', err));
                return;
            }
            // Edit: stamp the hidden fields and re-submit the form.
            this.form.recurScope = scope;
            this.form.occurrenceDate = this.editOccurrenceDate || '';
            this.recurScopeModalOpen = false;
            this.$nextTick(() => {
                const form = document.getElementById('createEventFormV2');
                if (form) form.requestSubmit();
            });
        },

        // Delete from the detail panel. Recurring → this/future/all scope modal;
        // one-off → a simple confirm. Both end in an audited soft-delete.
        deleteEvent() {
            if (this.panelData.is_recurring) {
                this.openRecurScopeModal('delete');
                return;
            }
            this.deleteConfirmOpen = true;
        },
        confirmDeleteOneOff() {
            const id = this.panelData.id;
            const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
            this.deleteSaving = true;
            fetch('/corex/command-center/calendar/' + id, {
                method: 'DELETE',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify({ _token: token }),
                credentials: 'same-origin',
            }).then(r => {
                this.deleteSaving = false;
                if (r.ok || r.status === 302) {
                    this.deleteConfirmOpen = false;
                    this.panelOpen = false;
                    window.location.reload();
                }
            }).catch(err => { this.deleteSaving = false; console.warn('Delete failed:', err); });
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
        remove(p) {
            this.chosen = this.chosen.filter(x => x.id !== p.id);
            // CAL-5 — when a property is removed from the picker, also
            // remove every attendee that was auto-filled FROM that property
            // (stamped with source_property_id in setOwners). Without this
            // a stale auto-filled contact from a previously-selected
            // property would survive into the new selection and the chip
            // text could be misread as belonging to the new property's
            // pivot. Manually-added attendees (no source_property_id)
            // are untouched.
            const form = this.$el?.closest?.('form');
            const picker = form?.querySelector('[x-ref="attendeePicker"]');
            if (picker) {
                const pickerData = Alpine.$data(picker);
                pickerData.chosen = (pickerData.chosen || []).filter(c => {
                    return Number(c.source_property_id) !== Number(p.id);
                });
            }
        },
        get selected() { return this.chosen.length > 0 ? this.chosen[0] : null; },
        async autoPopulateOwners(propertyId) {
            const config = this.getClassConfig();
            // AT-154 — SELLERS auto-fill for EVERY property appointment; only
            // reminder/marker classes (actor_role 'neither') never auto-fill.
            // The SERVER decides whether the linked property's BUYER is included
            // (per the class's autofill_buyers flag), so we pass the category and
            // add whatever it returns. Previously this skipped buyer_action
            // classes (a viewing auto-filled NOBODY) and, for seller/both classes,
            // added EVERY contact — so a listing_presentation pulled the buyer.
            if (config.actor_role === 'neither') return;
            const form = this.$el?.closest?.('form');
            const category = form?.querySelector('[name="category"]')?.value || '';
            try {
                const ownersUrl = '/corex/command-center/calendar/properties/' + propertyId + '/owners'
                    + (category ? ('?category=' + encodeURIComponent(category)) : '');
                const r = await fetch(ownersUrl, {
                    headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
                });
                if (!r.ok) return;
                const owners = await r.json();
                // CAL-5 — stamp source_property_id on every auto-filled
                // contact so propertySearch.remove(p) can scrub them when
                // the originating property leaves the selection. This is
                // the "clear prior attendee state when the selected
                // property changes" guarantee from the spec — applied
                // per-property so multi-property events keep each
                // property's auto-fills isolated from the others.
                const stamped = (Array.isArray(owners) ? owners : []).map(o => ({
                    ...o,
                    source_property_id: Number(propertyId),
                }));
                const picker = form?.querySelector('[x-ref="attendeePicker"]');
                if (picker) {
                    Alpine.$data(picker).setOwners(stamped);
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
            // CAL-4 — auto-populate with EVERY linked contact returned by the
            // property-owners endpoint (now inclusive — see CalendarController
            // ::propertyOwners). Honour the server-supplied `role` (mapped to
            // the attendee_role enum) and `role_label` (raw pivot role for
            // chip display). Falls back to 'attendee' when the server didn't
            // supply a role — previously this hardcoded seller_contact, which
            // mislabelled blank-pivot contacts on save. Additive — never
            // duplicates an already-chosen contact.
            owners.forEach(o => {
                if (!o.type) o.type = 'contact';
                if (!o.role) o.role = 'attendee';
                const key = o.type + ':' + o.id;
                if (!this.chosen.some(c => c.type + ':' + c.id === key)) {
                    this.chosen.push(o);
                }
            });
        },
    };
}

/* ══════ AT-164 Gate 4/7 — Tile Deck controller ══════
   Per-user Deck of tiles below the grid: pick/reorder/save/reset, with a live-RAG
   refresh loop (focus/visibilitychange + light poll) so tile RAG stays current
   without a full reload. Server is authoritative — every structural change POSTs
   and re-reads the built cards. */
function calendarDeck() {
    return {
        editing: false,
        saving: false,
        pickerOpen: false,
        dragIndex: null,
        cards: @json($deck ?? []),
        catalog: @json($deckCatalog ?? []),
        layout: @json($deckLayout ?? []),
        slots: {{ (int) ($deckSlots ?? 4) }},
        pollSeconds: {{ (int) ($pollSeconds ?? 60) }},
        _pollTimer: null,
        _csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',
        _urls: {
            deck:  '{{ route('command-center.calendar.deck') }}',
            reset: '{{ route('command-center.calendar.deck.reset') }}',
            cockpit: '{{ route('command-center.calendar.cockpit.save') }}',
            tile:  '{{ route('command-center.calendar.tile', ['tileId' => '__ID__']) }}',
        },
        // AT-164 cockpit v2 — resizable/collapsible strip + adjustable tile ratios.
        cockpit: @json($cockpit ?? []),
        _cockpitTimer: null,
        _resize: { on: false, y: 0, h: 0 },
        _ratio: { on: false, i: -1, x: 0, a: 0, b: 0 },

        get stripCollapsed() { return !!this.cockpit.strip_collapsed; },
        // Clamp to the CURRENT viewport so a height saved on a big screen can't push the
        // calendar below ~45vh on a smaller one (section chrome ≈ 66px; strip ≤ 40vh).
        get stripHeight() {
            const maxTile = Math.max(120, Math.round(window.innerHeight * 0.40) - 66);
            return Math.max(120, Math.min(maxTile, this.cockpit.strip_height || 176));
        },
        // grid-template-columns from saved ratios (fallback: equal columns).
        gridTemplate() {
            const n = this.cards.length || 1;
            let r = Array.isArray(this.cockpit.tile_ratios) ? this.cockpit.tile_ratios.slice(0, n) : [];
            while (r.length < n) r.push(1);
            return r.map(v => (Math.max(0.2, +v || 1)) + 'fr').join(' ');
        },
        // AT-164 explicit-save — record strip geometry in the client TRANSIENT (synchronous,
        // survives navigation). Never auto-persisted; reaches the default only via Save.
        persistCockpit() {
            window.CoreXCal.patch({
                strip_height: Math.round(this.stripHeight),
                strip_collapsed: this.stripCollapsed,
                tile_ratios: (this.cockpit.tile_ratios || []).map(v => +v),
            });
        },
        toggleStripCollapse() { this.cockpit.strip_collapsed = !this.cockpit.strip_collapsed; this.persistCockpit(); },
        // Strip vertical resize (pointer) — bounded so the calendar never drops below ~45vh.
        stripResizeStart(e) {
            this._resize = { on: true, y: e.clientY, h: this.stripHeight };
            e.preventDefault();
        },
        stripResizeMove(e) {
            if (!this._resize.on) return;
            const dy = this._resize.y - e.clientY;         // drag up = taller strip
            // Cap the TILE-AREA height so the whole strip SECTION (tile area + ~66px of
            // header/padding/handle chrome) never exceeds ~40vh — which keeps the
            // calendar block above ~45vh at both proof sizes.
            const maxTile = Math.max(120, Math.round(window.innerHeight * 0.40) - 66);
            const h = Math.max(120, Math.min(maxTile, this._resize.h + dy));
            this.cockpit.strip_height = h;
        },
        stripResizeEnd() { if (this._resize.on) { this._resize.on = false; this.persistCockpit(); } },
        // Tile column-ratio resize (pointer) between column i and i+1.
        ratioStart(i, e) {
            const n = this.cards.length;
            let r = Array.isArray(this.cockpit.tile_ratios) ? this.cockpit.tile_ratios.slice(0, n) : [];
            while (r.length < n) r.push(1);
            this.cockpit.tile_ratios = r;
            this._ratio = { on: true, i, x: e.clientX, a: r[i], b: r[i + 1] };
            e.preventDefault(); e.stopPropagation();
        },
        ratioMove(e) {
            if (!this._ratio.on) return;
            const grid = this.$refs.deckGrid; if (!grid) return;
            const per = grid.getBoundingClientRect().width / Math.max(1, this.cards.length);
            const dfr = (e.clientX - this._ratio.x) / Math.max(1, per);   // px → fr
            const a = Math.max(0.3, this._ratio.a + dfr);
            const b = Math.max(0.3, this._ratio.b - dfr);
            const r = this.cockpit.tile_ratios.slice();
            r[this._ratio.i] = a; r[this._ratio.i + 1] = b;
            this.cockpit.tile_ratios = r;
        },
        ratioEnd() { if (this._ratio.on) { this._ratio.on = false; this.persistCockpit(); } },
        // AT-164 explicit-save — "Reset view" restores the saved default (discards transient).
        resetView() { window.CoreXCal.reset(); },

        init() {
            // AT-164 explicit-save — register this component and APPLY the client TRANSIENT
            // (strip geometry / ratios / deck layout) over the server-rendered saved default.
            // A hard reload already cleared the transient (window.CoreXCal.boot), so a reload
            // renders the saved default; a navigate-back re-applies the in-session arrangement.
            window.CoreXCal.deck = this;
            const _t = window.CoreXCal.get();
            if (Object.prototype.hasOwnProperty.call(_t, 'strip_collapsed')) this.cockpit.strip_collapsed = !!_t.strip_collapsed;
            if (typeof _t.strip_height === 'number') this.cockpit.strip_height = _t.strip_height;
            if (Array.isArray(_t.tile_ratios)) this.cockpit.tile_ratios = _t.tile_ratios.slice();
            if (Array.isArray(_t.deck_layout)) { this._applyTransientLayout(_t.deck_layout); }

            // AT-164 SELF-HEAL (Johan 05:45): if a saved strip height is egregiously out of
            // range (e.g. a pre-clamp stuck arrangement), correct the in-memory value. The
            // stripHeight getter also clamps DISPLAY per-viewport, so headers can never be
            // pushed off regardless.
            const raw = this.cockpit.strip_height;
            if (raw != null && (!isFinite(raw) || raw > 450 || raw < 100)) {
                this.cockpit.strip_height = this.stripHeight;
            }
            // Live-RAG loop (Gate 7): refetch on focus/visibility + a light poll.
            window.addEventListener('focus', () => this.refresh());
            document.addEventListener('visibilitychange', () => { if (!document.hidden) this.refresh(); });
            // AT-164 Gate 6 (defect fix) — the Deck deliberately does NOT listen for layer
            // changes. Deck tiles are independent instruments whose content contracts are
            // their own (Notifications shows all notifications, Upcoming all appointments,
            // etc.), never a projection of the calendar's layer lens (Johan's doctrine).
            const secs = Math.max(15, this.pollSeconds || 60);
            this._pollTimer = setInterval(() => { if (!document.hidden && !this.editing) this.refresh(); }, secs * 1000);
        },

        get availableToAdd() {
            const used = new Set(this.layout);
            return this.catalog.filter(t => !used.has(t.tile_id));
        },
        get canAddMore() { return this.layout.length < this.slots; },

        toggleEdit() { this.editing = !this.editing; if (!this.editing) this.pickerOpen = false; },

        // Fetch ONE tile's built card (non-persisting) — used to render a newly-added tile
        // and to reconcile a transient layout that references a tile not currently loaded.
        async _fetchTile(id) {
            try {
                const r = await fetch(this._urls.tile.replace('__ID__', encodeURIComponent(id)), {
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this._csrf }, credentials: 'same-origin',
                });
                if (r.ok) { const d = await r.json(); return d.card || null; }
            } catch (e) { /* degrade */ }
            return null;
        },
        // Apply a transient deck layout over the server-rendered cards WITHOUT persisting:
        // reuse loaded cards, fetch any missing tile, drop de-selected tiles, reorder.
        async _applyTransientLayout(layout) {
            const clean = (Array.isArray(layout) ? layout : [])
                .filter(id => this.catalog.some(t => t.tile_id === id))
                .slice(0, this.slots);
            const byId = {}; this.cards.forEach(c => { byId[c.card_id] = c; });
            const result = [];
            for (const id of clean) {
                if (byId[id]) { result.push(byId[id]); }
                else { const card = await this._fetchTile(id); if (card) result.push(card); }
            }
            this.cards = result;
            this.layout = result.map(c => c.card_id);
        },

        // AT-164 explicit-save — deck edits are TRANSIENT (sessionStorage), never auto-saved
        // to the default. They reach the default only via "Save as my default".
        _recordDeckTransient() { window.CoreXCal.patch({ deck_layout: this.layout.slice() }); },

        async addTile(id) {
            if (!this.canAddMore) return;
            this.pickerOpen = false;
            if (this.layout.includes(id)) return;
            const card = await this._fetchTile(id);
            if (!card) return;
            this.cards = [...this.cards, card];
            this.layout = this.cards.map(c => c.card_id);
            this._recordDeckTransient();
        },
        removeTile(id) {
            this.cards = this.cards.filter(c => c.card_id !== id);
            this.layout = this.cards.map(c => c.card_id);
            this._recordDeckTransient();
        },

        // Drag reorder (edit mode)
        dragStart(idx, ev) { if (!this.editing) return; this.dragIndex = idx; try { ev.dataTransfer.effectAllowed = 'move'; } catch (e) {} },
        dragOver(idx) { /* handled by @dragover.prevent to allow drop */ },
        drop(idx) {
            if (!this.editing || this.dragIndex === null || this.dragIndex === idx) { this.dragIndex = null; return; }
            const moved = this.cards.splice(this.dragIndex, 1)[0];
            this.cards.splice(idx, 0, moved);
            this.layout = this.cards.map(c => c.card_id);
            this.dragIndex = null;
            this._recordDeckTransient();
        },
        dragEndDeck() { this.dragIndex = null; },

        // Live refresh — re-read built cards (RAG may have changed elsewhere).
        async refresh() {
            if (this.editing) return; // never clobber an in-progress edit
            try {
                const r = await fetch(this._urls.deck, {
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this._csrf },
                    credentials: 'same-origin',
                });
                if (r.ok) {
                    const data = await r.json();
                    if (Array.isArray(data.cards)) this.cards = data.cards;
                    if (Array.isArray(data.layout)) this.layout = data.layout;
                    if (typeof data.slots === 'number') this.slots = data.slots;
                }
            } catch (e) { /* silent — degrade, never break the page */ }
        },
    };
}

/* ══════ AT-164 Gate 5 — continuous-scroll month controller ══════
   Month weeks flow vertically and continuously (Outlook-web). The initial month is
   server-rendered; earlier/later months lazy-load through /calendar/month-block (the
   SAME _month-block partial), so there is no second JS cell renderer and every window
   keeps full interaction parity (drag-reschedule, chips, deadline popovers, bars).
   Sticky month labels come free from CSS position:sticky in the partial. Today anchor,
   jump-to-date and ?anchor scroll-restore included. */
function continuousMonth() {
    return {
        loadingTop: false,
        loadingBottom: false,
        minWeek: null,    // 'YYYY-MM-DD' Monday of the earliest loaded week
        maxWeek: null,    // 'YYYY-MM-DD' Monday of the latest loaded week
        monthLabel: '',   // sticky label — the month occupying the viewport (follows scroll)
        _params: '',
        _restoreAnchor: null,
        _anchorTimer: null,
        _ready: false,    // gate lazy-load until the initial anchor scroll has settled
        // Gate 7 — live-RAG loop
        pollSeconds: {{ (int) ($pollSeconds ?? 60) }},
        _gridPoll: null,
        _refreshingGrid: false,

        /* AT-164 single week-stream — the month view is ONE continuous stream of week
           rows (each calendar week exactly once; months flow into each other; boundaries
           MARKED not repeated). Windows are addressed by WEEK (a Monday date). ALL
           programmatic scrolling targets ONLY this frame's inner scroller via scrollTop
           — never scrollIntoView, which would drag the overflow-hidden page shell and
           strand the toolbar/banner off-screen (the outer-scroll defect). */

        // ── date helpers (local-time, no TZ drift) ──
        _fmt(dt) {
            const y = dt.getFullYear(), m = String(dt.getMonth() + 1).padStart(2, '0'), d = String(dt.getDate()).padStart(2, '0');
            return `${y}-${m}-${d}`;
        },
        _mondayOf(dateStr) {
            const [y, m, d] = dateStr.split('-').map(Number);
            const dt = new Date(y, m - 1, d);
            const dow = (dt.getDay() + 6) % 7; // 0=Mon … 6=Sun
            dt.setDate(dt.getDate() - dow);
            return this._fmt(dt);
        },
        _addWeeks(mondayStr, n) {
            const [y, m, d] = mondayStr.split('-').map(Number);
            const dt = new Date(y, m - 1, d);
            dt.setDate(dt.getDate() + n * 7);
            return this._fmt(dt);
        },
        // A week's owning month = the month of its Thursday (ISO convention) — a stable,
        // intuitive transition point at a boundary week.
        _monthLabelFor(mondayStr) {
            const [y, m, d] = mondayStr.split('-').map(Number);
            const th = new Date(y, m - 1, d + 3);
            return th.toLocaleDateString('en-ZA', { month: 'long', year: 'numeric' });
        },

        initMonth() {
            this._syncBounds();
            if (!this.minWeek) { this.minWeek = this.maxWeek = this._mondayOf(this._fmt(new Date())); }

            // Carry the active filters/scope to the week-rows endpoint.
            const url = new URL(window.location.href);
            const carry = new URLSearchParams();
            for (const k of ['scope']) if (url.searchParams.get(k)) carry.set(k, url.searchParams.get(k));
            for (const k of ['types', 'categories']) {
                url.searchParams.getAll(k + '[]').forEach(v => carry.append(k + '[]', v));
                url.searchParams.getAll(k).forEach(v => carry.append(k + '[]', v));
            }
            this._params = carry.toString();

            // Scroll-restore: ?anchor=YYYY-MM-DD returns to the same week after refresh;
            // otherwise open on the anchor month's first week (weeks are preloaded ABOVE
            // it, so without this the frame would open scrolled to an earlier week).
            this._restoreAnchor = url.searchParams.get('anchor');
            const anchorWeek = '{{ $anchorWeek ?? '' }}';
            this.monthLabel = this._monthLabelFor(anchorWeek || this.minWeek);
            // Land on the anchor week BEFORE enabling lazy-load — otherwise the scroll
            // event from positioning fires loadPrev/loadNext, which prepends/appends and
            // drifts the viewport off the anchor (the classic open-at race). A double rAF
            // lets layout settle so the scrollTop math is exact.
            this.$nextTick(() => {
                requestAnimationFrame(() => requestAnimationFrame(() => {
                    const target = this._restoreAnchor ? this._mondayOf(this._restoreAnchor) : anchorWeek;
                    if (target) this._scrollToWeek(target, false);
                    this.updateLabel();
                    // Enable lazy-load only AFTER the positioning scroll's own scroll events
                    // have flushed, so init never trips loadPrev/loadNext and drifts the view.
                    setTimeout(() => { this._ready = true; }, 350);
                }));
            });

            // Toolbar Today control + jump-to-date.
            window.addEventListener('calendar:today', () => this.scrollToDate(this._fmt(new Date())));
            window.addEventListener('calendar:jump', (e) => { if (e.detail) this.scrollToDate(e.detail); });

            // Gate 7 — live-RAG loop: refetch visible weeks on focus/visibility + a light
            // poll, so RAG changed elsewhere repaints without a full reload.
            window.addEventListener('focus', () => this.refreshGrid());
            document.addEventListener('visibilitychange', () => { if (!document.hidden) this.refreshGrid(); });
            const secs = Math.max(15, this.pollSeconds || 60);
            this._gridPoll = setInterval(() => { if (!document.hidden) this.refreshGrid(); }, secs * 1000);
        },

        _rows() { return Array.from(this.$refs.weeks?.querySelectorAll('.cal-week-row') || []); },
        _syncBounds() {
            const rows = this._rows();
            if (!rows.length) return;
            this.minWeek = rows[0].dataset.week;
            this.maxWeek = rows[rows.length - 1].dataset.week;
        },

        // SCOPED scroll — set the inner scroller's scrollTop so the target week sits at
        // the top of the frame. Never scrollIntoView (which scrolls every ancestor).
        _scrollToWeek(mondayStr, smooth = true) {
            const el = this.$refs.scroller;
            const row = this.$refs.weeks?.querySelector('.cal-week-row[data-week="' + mondayStr + '"]');
            if (!el || !row) return;
            const delta = row.getBoundingClientRect().top - el.getBoundingClientRect().top;
            el.scrollTo({ top: Math.max(0, el.scrollTop + delta), behavior: smooth ? 'smooth' : 'auto' });
        },

        _topVisibleWeek() {
            const el = this.$refs.scroller;
            if (!el) return null;
            const top = el.getBoundingClientRect().top + 4;
            for (const r of this._rows()) {
                const rc = r.getBoundingClientRect();
                if (rc.bottom > top) return r.dataset.week;
            }
            const rows = this._rows();
            return rows.length ? rows[rows.length - 1].dataset.week : null;
        },
        updateLabel() {
            const wk = this._topVisibleWeek();
            if (wk) this.monthLabel = this._monthLabelFor(wk);
        },

        /* Re-fetch the visible week rows and replace them in place. Uses the SAME
           /calendar/week-rows renderer, so RAG repaints server-side with zero reload.
           Scroll position and active layer toggles are preserved. */
        async refreshGrid() {
            if (this._refreshingGrid || document.hidden) return;
            const el = this.$refs.scroller, weeks = this.$refs.weeks;
            if (!el || !weeks) return;
            this._refreshingGrid = true;
            const beforeTop = el.scrollTop;
            const viewTop = el.getBoundingClientRect().top;
            const viewBottom = viewTop + el.clientHeight;
            const visible = this._rows().filter(r => {
                const rc = r.getBoundingClientRect();
                return rc.bottom >= viewTop - 240 && rc.top <= viewBottom + 240;
            });
            if (visible.length) {
                const html = await this._fetchWeeks(visible[0].dataset.week, visible.length);
                if (html) {
                    const tmp = document.createElement('div');
                    tmp.innerHTML = html.trim();
                    const fresh = Array.from(tmp.children).filter(n => n.classList && n.classList.contains('cal-week-row'));
                    if (fresh.length) {
                        const anchor = visible[0];
                        fresh.forEach(n => { weeks.insertBefore(n, anchor); if (window.Alpine && window.Alpine.initTree) window.Alpine.initTree(n); });
                        visible.forEach(o => o.remove());
                    }
                }
            }
            window.dispatchEvent(new Event('calendar:block-appended'));
            el.scrollTop = beforeTop;
            this._refreshingGrid = false;
        },

        _weekUrl(startMonday, count) {
            const base = '{{ route('command-center.calendar.week-rows') }}';
            const q = new URLSearchParams(this._params);
            q.set('start', startMonday); q.set('count', count);
            return base + '?' + q.toString();
        },
        async _fetchWeeks(startMonday, count) {
            try {
                const r = await fetch(this._weekUrl(startMonday, count), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin',
                });
                if (!r.ok) return null;
                return await r.text();
            } catch (e) { return null; }
        },
        _insertRows(html, where) {
            const tmp = document.createElement('div');
            tmp.innerHTML = html.trim();
            const fresh = Array.from(tmp.children).filter(n => n.classList && n.classList.contains('cal-week-row'));
            const weeks = this.$refs.weeks;
            if (!fresh.length || !weeks) return;
            if (where === 'top') {
                const first = weeks.firstElementChild;
                fresh.forEach(n => weeks.insertBefore(n, first));
            } else {
                fresh.forEach(n => weeks.appendChild(n));
            }
            fresh.forEach(n => { if (window.Alpine && window.Alpine.initTree) window.Alpine.initTree(n); });
            window.dispatchEvent(new Event('calendar:block-appended'));
        },

        onScroll() {
            const el = this.$refs.scroller;
            if (!el) return;
            this.updateLabel();
            if (!this._ready) return; // don't lazy-load while the initial anchor scroll settles
            if (el.scrollTop < 240 && !this.loadingTop) this.loadPrev();
            if (el.scrollHeight - el.scrollTop - el.clientHeight < 320 && !this.loadingBottom) this.loadNext();
            clearTimeout(this._anchorTimer);
            this._anchorTimer = setTimeout(() => this._syncAnchor(), 250);
        },
        _syncAnchor() {
            const wk = this._topVisibleWeek();
            if (!wk) return;
            const url = new URL(window.location.href);
            url.searchParams.set('anchor', wk);
            history.replaceState(null, '', url.toString());
        },

        async loadNext() {
            if (!this.maxWeek || this.loadingBottom) return;
            const start = this._addWeeks(this.maxWeek, 1);
            if (Number(start.slice(0, 4)) > 2100) return;
            this.loadingBottom = true;
            const html = await this._fetchWeeks(start, 6);
            if (html) { this._insertRows(html, 'bottom'); this._syncBounds(); }
            this.loadingBottom = false;
        },
        async loadPrev() {
            if (!this.minWeek || this.loadingTop) return;
            const start = this._addWeeks(this.minWeek, -6);
            if (Number(start.slice(0, 4)) < 2000) return;
            this.loadingTop = true;
            const el = this.$refs.scroller;
            const beforeH = el.scrollHeight;
            const html = await this._fetchWeeks(start, 6);
            if (html) {
                this._insertRows(html, 'top');
                this._syncBounds();
                // Keep the viewport stable after prepending content above.
                this.$nextTick(() => { el.scrollTop += (el.scrollHeight - beforeH); });
            }
            this.loadingTop = false;
        },

        // Ensure the target date's week is loaded (prepend/append until it exists), then
        // scroll to it — the Today snap and jump-to-date entry point, scoped to the frame.
        async scrollToDate(dateStr) {
            const monday = this._mondayOf(dateStr);
            const has = () => this.$refs.weeks?.querySelector('.cal-week-row[data-week="' + monday + '"]');
            let guard = 0;
            while (!has() && guard++ < 80) {
                if (monday < this.minWeek) await this.loadPrev();
                else await this.loadNext();
            }
            if (has()) { this.$nextTick(() => { this._scrollToWeek(monday, true); this.updateLabel(); }); }
        },
    };
}

/* ══════ AT-164 cockpit — continuous HORIZONTAL week ══════
   Days flow left→right as a windowed strip inside the bounded frame (mirrors the
   month windowing). Lazy prepend/append day columns via /calendar/day-columns (the
   same _day-column partial), sticky date-range label, Today snap, a vertical wheel
   translated to horizontal, drag-to-scroll on the headers/gutter, and a live-RAG
   refetch on focus/poll. The create/reschedule drag inside a day column is untouched. */
function continuousWeek() {
    return {
        rangeLabel: '',
        minDay: null, maxDay: null,      // YYYY-MM-DD earliest/latest loaded
        loading: false,
        pollSeconds: {{ (int) ($pollSeconds ?? 60) }},
        _params: '',
        _drag: { on: false, x: 0, left: 0, moved: false },
        _pollTimer: null,

        initWeek() {
            const cols = this.$refs.days ? this.$refs.days.querySelectorAll('.cal-day-col') : [];
            if (cols.length) {
                this.minDay = cols[0].dataset.day;
                this.maxDay = cols[cols.length - 1].dataset.day;
            }
            // Carry active filters/scope to the day-columns endpoint.
            const url = new URL(window.location.href);
            const carry = new URLSearchParams();
            if (url.searchParams.get('scope')) carry.set('scope', url.searchParams.get('scope'));
            for (const k of ['types', 'categories']) {
                url.searchParams.getAll(k + '[]').forEach(v => carry.append(k + '[]', v));
                url.searchParams.getAll(k).forEach(v => carry.append(k + '[]', v));
            }
            this._params = carry.toString();

            const anchorMonday = '{{ $anchorMonday ?? '' }}';
            this.$nextTick(() => {
                if (anchorMonday) this.scrollToDay(anchorMonday, false);
                this.updateLabel();
                // Non-passive wheel listener so a vertical mouse wheel can be translated
                // to horizontal (Alpine's @wheel is passive → preventDefault is ignored).
                const sc = this.$refs.weekScroller;
                if (sc) sc.addEventListener('wheel', (e) => this.onWheel(e), { passive: false });
            });

            // Toolbar Today control + jump-to-date — snap the strip back to today's column
            // (or an arbitrary date). Local date string so a late-night UTC roll never
            // lands on tomorrow.
            window.addEventListener('calendar:today', () => this.scrollToDay(this._todayStr()));
            window.addEventListener('calendar:jump', (e) => { if (e.detail) this.scrollToDay(e.detail); });

            // Live-RAG loop (focus/visibility + light poll).
            window.addEventListener('focus', () => this.refreshWeek());
            document.addEventListener('visibilitychange', () => { if (!document.hidden) this.refreshWeek(); });
            const secs = Math.max(15, this.pollSeconds || 60);
            this._pollTimer = setInterval(() => { if (!document.hidden) this.refreshWeek(); }, secs * 1000);
        },

        _url(start, count) {
            const q = new URLSearchParams(this._params);
            q.set('start', start); q.set('count', count);
            return '{{ route('command-center.calendar.day-columns') }}?' + q.toString();
        },
        // UTC-safe: parse + shift + format all in UTC so a +2 timezone never bumps the
        // date back a day (that off-by-one duplicated the boundary column on append).
        _addDays(dateStr, n) { const d = new Date(dateStr + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); },

        onWheel(e) {
            // Week scrolls on BOTH axes. A plain VERTICAL wheel (deltaY) is the time axis —
            // native scroll through the hours; NEVER hijacked. Horizontal ADVANCE across
            // days/weeks happens only on explicit horizontal intent: a trackpad deltaX, or
            // shift+wheel. (Drag-to-scroll is the other horizontal path.)
            const el = this.$refs.weekScroller; if (!el) return;
            const horizIntent = Math.abs(e.deltaX) > Math.abs(e.deltaY) || (e.shiftKey && e.deltaY);
            if (horizIntent) {
                el.scrollLeft += (e.deltaX || e.deltaY);
                e.preventDefault();
            }
            // else: let the browser scroll the hours vertically (default).
            this.onWeekScroll();
        },
        dragScrollStart(e) {
            if (e.button !== 0) return;
            if (e.target.closest('.cal-timed-grid') || e.target.closest('[data-event-id]')) return; // let create/open happen
            const el = this.$refs.weekScroller; if (!el) return;
            this._drag = { on: true, x: e.clientX, left: el.scrollLeft, moved: false };
            el.style.cursor = 'grabbing';
        },
        dragScrollMove(e) {
            if (!this._drag.on) return;
            const el = this.$refs.weekScroller; if (!el) return;
            const dx = e.clientX - this._drag.x;
            if (Math.abs(dx) > 3) this._drag.moved = true;
            el.scrollLeft = this._drag.left - dx;
            this.onWeekScroll();
        },
        dragScrollEnd() {
            if (!this._drag.on) return;
            this._drag.on = false;
            const el = this.$refs.weekScroller; if (el) el.style.cursor = 'grab';
        },

        onWeekScroll() {
            const el = this.$refs.weekScroller; if (!el) return;
            if (el.scrollLeft < 300 && !this.loading) this.prependDays();
            if (el.scrollWidth - el.scrollLeft - el.clientWidth < 400 && !this.loading) this.appendDays();
            this.updateLabel();
        },

        async _fetch(start, count) {
            try {
                const r = await fetch(this._url(start, count), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                return r.ok ? await r.text() : null;
            } catch (e) { return null; }
        },
        _insert(html, where) {
            const tmp = document.createElement('div'); tmp.innerHTML = html.trim();
            const nodes = Array.from(tmp.children);
            const days = this.$refs.days;
            if (where === 'start') { for (let i = nodes.length - 1; i >= 0; i--) days.insertBefore(nodes[i], days.firstElementChild); }
            else { nodes.forEach(n => days.appendChild(n)); }
            nodes.forEach(n => { if (window.Alpine && window.Alpine.initTree) window.Alpine.initTree(n); });
            window.dispatchEvent(new Event('calendar:block-appended')); // re-apply layers if any
        },
        async appendDays() {
            if (!this.maxDay) return;
            this.loading = true;
            const start = this._addDays(this.maxDay, 1);
            const html = await this._fetch(start, 7);
            if (html) { this._insert(html, 'end'); this.maxDay = this._addDays(this.maxDay, 7); }
            this.loading = false;
        },
        async prependDays() {
            if (!this.minDay) return;
            this.loading = true;
            const el = this.$refs.weekScroller; const beforeW = el.scrollWidth;
            const start = this._addDays(this.minDay, -7);
            const html = await this._fetch(start, 7);
            if (html) {
                this._insert(html, 'start');
                this.minDay = start;
                this.$nextTick(() => { el.scrollLeft += (el.scrollWidth - beforeW); });
            }
            this.loading = false;
        },

        _todayStr() {
            const d = new Date();
            return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        },
        async scrollToDay(dateStr, smooth = true) {
            const day = dateStr.slice(0, 10);
            let guard = 0;
            const has = () => this.$refs.days?.querySelector('[data-day="' + day + '"]');
            while (!has() && guard++ < 20) {
                if (this.minDay && day < this.minDay) await this.prependDays();
                else await this.appendDays();
            }
            const col = has();
            if (!col) return;
            // SCOPED horizontal scroll — set the week scroller's scrollLeft directly so the
            // target column sits just past the sticky time gutter. NEVER scrollIntoView,
            // which scrolls every ancestor (incl. the overflow-hidden page shell) and
            // strands the toolbar/banner off-screen (the outer-scroll defect).
            const el = this.$refs.weekScroller;
            if (el) {
                const delta = col.getBoundingClientRect().left - el.getBoundingClientRect().left - 56; // gutter width
                el.scrollTo({ left: Math.max(0, el.scrollLeft + delta), behavior: smooth ? 'smooth' : 'auto' });
            }
            this.$nextTick(() => this.updateLabel());
        },

        updateLabel() {
            const el = this.$refs.weekScroller; const cols = this.$refs.days?.querySelectorAll('.cal-day-col') || [];
            if (!el || !cols.length) return;
            const left = el.getBoundingClientRect().left + 60; // past the sticky gutter
            let vis = null;
            for (const c of cols) { const r = c.getBoundingClientRect(); if (r.right > left) { vis = c; break; } }
            vis = vis || cols[0];
            const mon = vis.dataset.week;
            const d = new Date(mon + 'T00:00:00Z'); const end = new Date(d); end.setUTCDate(d.getUTCDate() + 6);
            const fmt = (x) => x.toLocaleDateString('en-ZA', { day: 'numeric', month: 'short', timeZone: 'UTC' });
            this.rangeLabel = fmt(d) + ' – ' + fmt(end);
        },

        async refreshWeek() {
            if (this._drag.on || document.hidden) return;
            const el = this.$refs.weekScroller; const days = this.$refs.days;
            if (!el || !days) return;
            const cols = Array.from(days.querySelectorAll('.cal-day-col'));
            if (!cols.length) return;
            // Refetch the currently-visible span in one call, replace those columns.
            const vLeft = el.getBoundingClientRect().left, vRight = vLeft + el.clientWidth;
            const visible = cols.filter(c => { const r = c.getBoundingClientRect(); return r.right > vLeft - 200 && r.left < vRight + 200; });
            if (!visible.length) return;
            const start = visible[0].dataset.day;
            const html = await this._fetch(start, visible.length);
            if (!html) return;
            const tmp = document.createElement('div'); tmp.innerHTML = html.trim();
            const fresh = Array.from(tmp.children);
            visible.forEach((old, i) => { if (fresh[i]) { old.replaceWith(fresh[i]); if (window.Alpine && window.Alpine.initTree) window.Alpine.initTree(fresh[i]); } });
            window.dispatchEvent(new Event('calendar:block-appended'));
        },
    };
}

/* ══════ AT-164 Gate 6 — layer toggles ══════
   Show/hide event species on the grid instantly (client-side, via data-layer tags)
   and filter the Deck's Notifications tile server-side. Persisted per-user
   (cross-device) so the choice survives reloads and other devices. Re-applies to
   lazy-loaded month blocks; the server is authoritative — toggles never widen the
   visibility/RAG gate, they only hide already-authorised rows. */
function layerFilter() {
    return {
        open: false,
        catalog: @json($layerCatalog ?? []),
        active: @json($activeLayers ?? []),
        _csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',
        _url: '{{ route('command-center.calendar.layers.save') }}',

        get hiddenCount() { return Math.max(0, this.catalog.length - this.active.length); },

        initLayers() {
            // AT-164 explicit-save — register + apply the client TRANSIENT layer set over the
            // server-rendered saved default (reload already cleared it → saved default shows).
            window.CoreXCal.layers = this;
            const _t = window.CoreXCal.get();
            if (Array.isArray(_t.layers)) { this.active = _t.layers.slice(); }
            this.apply();
            // Re-apply when the continuous-scroll controller appends a new month block.
            window.addEventListener('calendar:block-appended', () => this.apply());
        },
        toggle(key) {
            this.active = this.active.includes(key)
                ? this.active.filter(k => k !== key)
                : [...this.active, key];
            this.apply();
            this._record();
        },
        setAll(on) {
            this.active = on ? this.catalog.map(l => l.key) : [];
            this.apply();
            this._record();
        },
        // Record the active layer set in the client transient (no DB write until Save).
        _record() { window.CoreXCal.patch({ layers: this.active.slice() }); },
        apply() {
            // Grid surfaces (month/week/day/agenda) hide/show via data-layer tags —
            // instant, reversible, no reload. The panel agenda is Alpine-reactive and
            // listens for the broadcast below instead of being touched here.
            document.querySelectorAll('.cal-layerable').forEach(el => {
                const layer = el.dataset.layer || 'appointments';
                el.style.display = this.active.includes(layer) ? '' : 'none';
            });
            // Broadcast the active set so calendar-adjacent JS surfaces (the panel
            // agenda) re-filter reactively. Layers are a CALENDAR lens only — the Deck
            // deliberately does NOT listen (its tiles are independent instruments).
            window.dispatchEvent(new CustomEvent('calendar:layers-changed', { detail: [...this.active] }));
        },
        // NOTE (AT-164 explicit-save): layer toggles no longer POST to the DB — the change is
        // recorded in the client transient by _record() and reaches the saved default only via
        // "Save as my default". The instant client-side hide in apply() (+ its
        // calendar:layers-changed broadcast for the panel agenda) is the whole of the effect.
        // The Deck is intentionally NOT notified — deck tiles are independent instruments and
        // never respect layers (AT-164 doctrine).
    };
}
</script>
@endsection
