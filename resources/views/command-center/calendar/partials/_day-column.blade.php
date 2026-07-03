{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{--
    AT-164 cockpit — ONE day column for the continuous-scroll WEEK view. Weeks flow
    HORIZONTALLY as a windowed strip of these columns inside the bounded grid frame,
    mirroring the month windowing. Self-contained (re-declares its helpers) so the
    /calendar/day-columns endpoint can render it standalone for lazy prepend/append.
    Fixed pixel heights (header/all-day/hour-row) so every column + the sticky time
    gutter line up exactly. Inline z-index only (§3 — no npm build on deploy).

    Props: $date (Carbon), $events (Collection for that day), $rowPx (hour row px).
--}}
@php
    $hourGridStart = 6; $hourGridEnd = 20;
    $gridHours = range($hourGridStart, $hourGridEnd - 1);
    $rowPx     = $rowPx ?? 48;
    $HEADER_PX = 44; $ALLDAY_PX = 40;
    $gridMinutes = max(1, count($gridHours) * 60);
    $totalPx = count($gridHours) * $rowPx;

    $ragChip = [
        'red'     => 'background:#dc2626; color:#ffffff; border-left:2px solid #991b1b;',
        'amber'   => 'background:#d97706; color:#ffffff; border-left:2px solid #92400e;',
        'green'   => 'background:#0d9488; color:#ffffff; border-left:2px solid #115e59;',
        'neutral' => 'background:#475569; color:#ffffff; border-left:2px solid #334155;',
    ];
    $defaultChip = 'background:#475569; color:#ffffff; border-left:2px solid #334155;';

    $isAllDayEvent = function ($e) {
        if (!empty($e->all_day)) return true;
        if (str_starts_with((string) ($e->source_type ?? ''), 'synthetic:')) return true;
        return $e->event_date->format('H:i:s') === '00:00:00';
    };
    $timeRange = function ($e) {
        if (!empty($e->all_day)) return '';
        $start = $e->event_date->format('H:i');
        if ($e->end_date && $e->end_date->gt($e->event_date) && $e->end_date->isSameDay($e->event_date)) {
            return $start . '–' . $e->end_date->format('H:i');
        }
        return $start;
    };
    // Lane-packing (identical geometry to the classic week overlay).
    $layoutDayColumn = function ($events, int $gridStart, int $gridCount) {
        $gridMin = max(1, $gridCount * 60);
        $items = collect($events)->filter()->map(function ($e) use ($gridStart, $gridMin) {
            $startMin = ($e->event_date->hour - $gridStart) * 60 + $e->event_date->minute;
            $endDt = ($e->end_date && $e->end_date->gt($e->event_date)) ? $e->end_date : $e->event_date->copy()->addMinutes(60);
            $endMin = $endDt->isSameDay($e->event_date) ? ($endDt->hour - $gridStart) * 60 + $endDt->minute : $gridMin;
            $s  = max(0, min($startMin, $gridMin));
            $en = max($s + 30, min($endMin, $gridMin));
            return ['e' => $e, 's' => $s, 'en' => $en, 'lane' => 0, 'lanes' => 1];
        })->sortBy('s')->values()->all();
        $i = 0; $n = count($items);
        while ($i < $n) {
            $clusterEnd = $items[$i]['en']; $laneEnds = []; $j = $i;
            while ($j < $n && $items[$j]['s'] < $clusterEnd) {
                $placed = false;
                foreach ($laneEnds as $lane => $end) { if ($items[$j]['s'] >= $end) { $items[$j]['lane'] = $lane; $laneEnds[$lane] = $items[$j]['en']; $placed = true; break; } }
                if (!$placed) { $items[$j]['lane'] = count($laneEnds); $laneEnds[] = $items[$j]['en']; }
                $clusterEnd = max($clusterEnd, $items[$j]['en']); $j++;
            }
            $laneCount = max(1, count($laneEnds));
            for ($k = $i; $k < $j; $k++) { $items[$k]['lanes'] = $laneCount; }
            $i = $j;
        }
        return $items;
    };

    $allDay = collect(); $timed = collect();
    foreach ($events as $e) { if ($isAllDayEvent($e)) $allDay->push($e); else $timed->push($e); }
    $rects = $layoutDayColumn($timed, $hourGridStart, count($gridHours));
    $today = \Carbon\Carbon::today();
    $isToday = $date->isSameDay($today);
    $isWeekend = in_array($date->dayOfWeekIso, [6, 7]);
    $monday = $date->copy()->startOfWeek(\Carbon\Carbon::MONDAY);
    $now = now();
    $showNow = $isToday && $now->hour >= $hourGridStart && $now->hour < $hourGridEnd;
    $nowTop = $showNow ? (($now->hour - $hourGridStart) * 60 + $now->minute) / $gridMinutes * $totalPx : 0;
@endphp

<div class="cal-day-col flex-shrink-0 flex flex-col" data-day="{{ $date->toDateString() }}" data-week="{{ $monday->toDateString() }}"
     style="width: 168px; border-left: 1px solid var(--border); {{ $isWeekend ? 'background: var(--surface-2);' : '' }}">
    {{-- Day header (sticky top) --}}
    <div class="cal-day-head sticky top-0 text-center flex flex-col items-center justify-center flex-shrink-0"
         style="height: {{ $HEADER_PX }}px; z-index: 6; background: var(--surface-2); border-bottom: 1px solid var(--border);">
        <div class="text-[10px] uppercase tracking-wider" style="color: var(--text-muted);">{{ $date->format('D') }}</div>
        @if($isToday)
            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold" style="background: #00d4aa; color: #0f172a;">{{ $date->format('j') }}</span>
        @else
            <span class="text-sm font-semibold" style="color: var(--text-primary);">{{ $date->format('j') }}</span>
        @endif
    </div>

    {{-- All-day area (fixed height so columns align with the gutter) --}}
    <div class="flex-shrink-0 px-0.5 py-1 space-y-0.5 overflow-y-auto" style="height: {{ $ALLDAY_PX }}px; border-bottom: 1px solid var(--border);">
        @foreach($allDay as $evt)
            @php $chipStyle = $ragChip[$evt->resolved_colour] ?? $defaultChip; @endphp
            <button type="button" data-event-id="{{ $evt->id }}" @click.stop="openEventPanel({{ $evt->id }})"
                    class="block w-full text-left px-1.5 py-0.5 rounded text-[10px] truncate transition hover:opacity-80 {{ in_array($evt->status, ['completed','dismissed'], true) ? 'line-through opacity-70' : '' }}"
                    style="{{ $chipStyle }}" title="{{ $evt->title }}">{{ \Illuminate\Support\Str::limit($evt->title, 16) }}</button>
        @endforeach
    </div>

    {{-- Timed area (fixed pixel height; hour lines + absolute lane-packed tiles).
         cal-timed-grid marks the click-to-create zone so drag-to-scroll skips it. --}}
    <div class="relative flex-shrink-0 cal-timed-grid" style="height: {{ $totalPx }}px;">
        @foreach($gridHours as $idx => $hour)
            <div class="absolute inset-x-0 select-none" style="top: {{ $idx * $rowPx }}px; height: {{ $rowPx }}px; border-bottom: 1px solid var(--border); cursor: cell;">
                <div class="absolute inset-x-0 top-0 h-1/2" style="z-index: 1;"
                     @mousedown="dragStart('{{ $date->toDateString() }}', {{ $hour }}, 0, $event)" @mousemove="dragMove({{ $hour }}, 0)"
                     @dragover.prevent @drop.prevent="rescheduleDrop('{{ $date->toDateString() }}', {{ $hour }}, 0)"></div>
                <div class="absolute inset-x-0 top-1/2 h-1/2" style="z-index: 1;"
                     @mousedown="dragStart('{{ $date->toDateString() }}', {{ $hour }}, 1, $event)" @mousemove="dragMove({{ $hour }}, 1)"
                     @dragover.prevent @drop.prevent="rescheduleDrop('{{ $date->toDateString() }}', {{ $hour }}, 1)"></div>
            </div>
        @endforeach

        @if($showNow)
            <div class="absolute inset-x-0 pointer-events-none" style="z-index: 4; top: {{ $nowTop }}px; border-top: 2px solid #ef4444;">
                <div class="absolute -top-1.5 -left-1 w-2.5 h-2.5 rounded-full" style="background: #ef4444;"></div>
            </div>
        @endif

        {{-- Drag-create preview (within this column) --}}
        <div x-show="drag.active && drag.dayDate === '{{ $date->toDateString() }}'" x-cloak class="absolute pointer-events-none" style="z-index: 5;"
             :style="(() => { const ov = dragOverlay('{{ $date->toDateString() }}'); if (!ov) return 'display:none'; return `top:${ov.top}%;height:${ov.height}%;left:1px;right:1px;background:color-mix(in srgb, var(--brand-icon) 20%, transparent);border:1px solid var(--brand-button);border-radius:4px;`; })()"></div>

        @foreach($rects as $r)
            @php
                $evt = $r['e'];
                $topPct = $r['s'] / $gridMinutes * 100;
                $heightPct = ($r['en'] - $r['s']) / $gridMinutes * 100;
                $lane = $r['lane']; $lanes = $r['lanes'];
                $chipStyle = $ragChip[$evt->resolved_colour] ?? $defaultChip;
                $isDraggable = in_array($evt->source_type, ['manual', 'manual:demo']);
                $tr = $timeRange($evt);
                $isDone = in_array($evt->status, ['completed', 'dismissed'], true);
            @endphp
            <button type="button" data-event-id="{{ $evt->id }}" @click.stop="openEventPanel({{ $evt->id }})" @mousedown.stop
                    @if($isDraggable) draggable="true" @dragstart="rescheduleStart({{ $evt->id }}, '{{ $date->toDateString() }}', $event)" @dragend="rescheduleEnd()" @endif
                    :class="{ 'pointer-events-none': reschedule.dragging }"
                    class="absolute text-left rounded overflow-hidden transition hover:opacity-90 {{ $isDone ? 'line-through opacity-70' : '' }}"
                    style="z-index: 3; {{ $chipStyle }} {{ $isDraggable ? 'cursor:grab;' : '' }} top: {{ $topPct }}%; height: calc({{ $heightPct }}% - 2px); min-height: 14px; left: calc({{ $lane }} / {{ $lanes }} * 100% + 1px); width: calc(100% / {{ $lanes }} - 2px);"
                    title="{{ $tr }} {{ $evt->title }}">
                <span class="block px-1 pt-0.5 text-[9px] opacity-80 leading-none">{{ $tr }}</span>
                <span class="block px-1 text-[10px] font-medium leading-tight truncate">{{ \Illuminate\Support\Str::limit($evt->title, 16) }}</span>
            </button>
        @endforeach
    </div>
</div>
