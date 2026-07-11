{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{--
    AT-164 (single week-stream) — ONE calendar week (Mon–Sun) in the continuous MONTH
    view. The month view is now one seamless stream of these rows: every week exists
    EXACTLY ONCE (no month-block splitter, no duplicated boundary week), months flow
    into each other, and a month boundary is MARKED — not repeated — by the first day of
    the month rendering "Jul 1" with a subtle seam accent. Used for BOTH the initial
    render and every lazy prepend/append window (via /calendar/week-rows) so there is no
    second cell renderer to drift.

    Required data:
      $weekStart       — Carbon (Monday of this week)
      $byDate          — [Y-m-d => CalendarEvent[]] appointment species (this week only)
      $deadlineGroups  — [Y-m-d => group[]] aggregate deadline chips (this week only)
      $spanningBars    — multi-day bar segments falling in this week (start_col/span)
--}}
@php
    $today = \Carbon\Carbon::today();
    $ragChip = [
        'red'     => 'background:#dc2626; color:#ffffff; border-left:2px solid #991b1b;',
        'amber'   => 'background:#d97706; color:#ffffff; border-left:2px solid #92400e;',
        'green'   => 'background:#0d9488; color:#ffffff; border-left:2px solid #115e59;',
        'neutral' => 'background:#475569; color:#ffffff; border-left:2px solid #334155;',
    ];
    $defaultChip = 'background:#475569; color:#ffffff; border-left:2px solid #334155;';
    $timeRange = function ($e) {
        if (!empty($e->all_day)) return '';
        $start = $e->event_date->format('H:i');
        if ($e->end_date && $e->end_date->gt($e->event_date) && $e->end_date->isSameDay($e->event_date)) {
            return $start . '–' . $e->end_date->format('H:i');
        }
        return $start;
    };

    // The 7 dates of this week.
    $weekDates = [];
    for ($i = 0; $i < 7; $i++) { $weekDates[] = $weekStart->copy()->addDays($i); }

    // Slot-pack this week's spanning bars (interval partitioning) — identical geometry
    // to the classic month grid, just scoped to the single week.
    $bars = $spanningBars ?? [];
    usort($bars, function ($a, $b) {
        if ($a['start_col'] !== $b['start_col']) return $a['start_col'] - $b['start_col'];
        return $b['span'] - $a['span'];
    });
    $slots = [];
    foreach ($bars as $bar) {
        $placed = false;
        foreach ($slots as $si => &$slotBars) {
            $conflict = false;
            foreach ($slotBars as $existing) {
                if ($bar['start_col'] <= $existing['end_col'] && $bar['end_col'] >= $existing['start_col']) { $conflict = true; break; }
            }
            if (!$conflict) { $bar['slot'] = $si; $slotBars[] = $bar; $placed = true; break; }
        }
        unset($slotBars);
        if (!$placed) { $bar['slot'] = count($slots); $slots[] = [$bar]; }
    }
    $barCount = count($slots);

    // Does this week open a new month? (first-of-month cell → seam marker)
    $seamMonth = null;
    foreach ($weekDates as $d) { if ($d->day === 1) { $seamMonth = $d; break; } }

    // AT-164 — MONTH BOUNDARY TINT: alternating faint wash per month so a month change reads
    // as a glanceable full-width colour shift (continuous mode only — scoped by the parent
    // .cal-scroll-continuous class; strength tuned via --cal-month-tint-alpha in one place).
    // The week's OWNING month = its Thursday (ISO), matching the sticky-label logic.
    $ownMonth   = $weekStart->copy()->addDays(3);
    $monthParity = ($ownMonth->year * 12 + $ownMonth->month) % 2;
@endphp

<div class="cal-week-row cal-month-tint-{{ $monthParity }}" data-week="{{ $weekStart->toDateString() }}"
     data-month-parity="{{ $monthParity }}"
     @if($seamMonth) data-month-first="{{ $seamMonth->format('Y-m') }}" @endif
     style="border-bottom: 1px solid var(--border);{{ $seamMonth ? ' border-top: 2px solid var(--brand-button);' : '' }}">

    {{-- 1. DATE NUMBER STRIP — every day full opacity (seamless stream); a first-of-month
         cell shows "Jul 1" in the brand accent so the boundary reads without a splitter. --}}
    <div class="grid grid-cols-7">
        @foreach($weekDates as $colIdx => $cellDate)
            @php
                $isToday = $cellDate->isSameDay($today);
                $isWeekend = in_array($cellDate->dayOfWeekIso, [6, 7]);
                $isFirst = $cellDate->day === 1;
                $dateBg = $isFirst ? 'color-mix(in srgb, var(--brand-button) 8%, transparent)'
                                   : ($isWeekend ? 'var(--surface-2)' : 'transparent');
            @endphp
            <div @click="selectDate('{{ $cellDate->toDateString() }}')"
                 class="px-1.5 pt-1 pb-0.5 cursor-pointer"
                 style="background: {{ $dateBg }}; {{ $colIdx < 6 ? 'border-right: 1px solid var(--border);' : '' }}"
                 :class="selectedDate === '{{ $cellDate->toDateString() }}' && 'ring-2 ring-inset ring-[#00d4aa]'">
                @if($isToday)
                    <span class="inline-flex items-center justify-center h-6 px-1.5 min-w-6 rounded-full text-xs font-bold whitespace-nowrap"
                          style="background: #00d4aa; color: #0f172a;">{{ $isFirst ? $cellDate->format('M ') . '1' : $cellDate->day }}</span>
                @elseif($isFirst)
                    <span class="text-xs font-bold whitespace-nowrap" style="color: var(--brand-button);">{{ $cellDate->format('M') }} 1</span>
                @else
                    <span class="text-xs font-semibold" style="color: var(--text-secondary);">{{ $cellDate->day }}</span>
                @endif
            </div>
        @endforeach
    </div>

    {{-- 2. SPANNING BAR ZONE (between dates and chips — order is load-bearing, §3) --}}
    @if($barCount > 0)
        <div class="relative" style="min-height: {{ $barCount * 22 + 4 }}px; padding: 2px 0;">
            @foreach($slots as $slotIdx => $slotBars)
                @foreach($slotBars as $bar)
                    @php
                        $barEvt = $bar['event'];
                        $isInformational = ($barEvt->resolved_colour ?? 'neutral') === 'neutral';
                        $barBg = $isInformational ? '#0f172a' : match($barEvt->resolved_colour) {
                            'red' => '#dc2626', 'amber' => '#d97706', 'green' => '#0d9488', default => '#0f172a',
                        };
                        $barBorder = $isInformational ? '#1e293b' : match($barEvt->resolved_colour) {
                            'red' => '#991b1b', 'amber' => '#92400e', 'green' => '#115e59', default => '#1e293b',
                        };
                    @endphp
                    <button type="button"
                            data-event-id="{{ $bar['event_id'] }}"
                            data-layer="{{ $bar['layer'] ?? 'appointments' }}"
                            @click.stop="openEventPanel({{ $bar['event_id'] }})"
                            class="cal-layerable absolute text-[11px] text-white font-medium px-2 truncate hover:opacity-90 transition-opacity cursor-pointer"
                            style="top: {{ $slotIdx * 22 + 2 }}px; height: 18px; line-height: 18px;
                                   left: calc(({{ $bar['start_col'] - 1 }} / 7) * 100% + 3px);
                                   width: calc(({{ $bar['span'] }} / 7) * 100% - 6px);
                                   background: {{ $barBg }}; border: 2px solid {{ $barBorder }}; border-radius:6px;"
                            title="{{ $barEvt->title }} ({{ \Carbon\Carbon::parse($bar['start_date'])->format('d M') }}–{{ \Carbon\Carbon::parse($bar['end_date'])->format('d M') }})">
                        {{ \Illuminate\Support\Str::limit($barEvt->title, 30) }}
                    </button>
                @endforeach
            @endforeach
        </div>
    @endif

    {{-- 3. CELL GRID (single-day chips + aggregate deadline chips) --}}
    <div class="grid grid-cols-7">
        @foreach($weekDates as $colIdx => $cellDate)
            @php
                $dateStr = $cellDate->toDateString();
                $dayEvents = $byDate[$dateStr] ?? [];
                $isWeekend = in_array($cellDate->dayOfWeekIso, [6, 7]);
                $isFirst = $cellDate->day === 1;
                $cellBg = $isFirst ? 'color-mix(in srgb, var(--brand-button) 8%, transparent)'
                                   : ($isWeekend ? 'var(--surface-2)' : 'transparent');
                $chipCap = 6;
            @endphp
            <div @click="selectDate('{{ $dateStr }}')"
                 @dblclick="window.location.href='{{ route('command-center.calendar', array_merge(request()->only(['scope','types','categories']), ['view' => 'day', 'date' => $dateStr])) }}'"
                 @dragover.prevent="rescheduleDragOver = '{{ $dateStr }}'"
                 @drop.prevent="rescheduleDropOnDate('{{ $dateStr }}')"
                 class="relative min-h-[2.5rem] px-1 pt-0.5 pb-1 cursor-pointer transition-colors hover:brightness-110"
                 style="{{ $colIdx < 6 ? 'border-right: 1px solid var(--border);' : '' }}"
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
                                data-layer="{{ $evt->layer_key ?? 'appointments' }}"
                                draggable="true"
                                @dragstart.stop="rescheduleStartDrag({{ $evt->id }}, '{{ $dateStr }}')"
                                @dragend="rescheduleDragOver = null"
                                @click.stop="openEventPanel({{ $evt->id }})"
                                class="cal-layerable block w-full text-left text-[11px] leading-tight px-1.5 py-0.5 rounded truncate hover:opacity-80 transition-opacity cursor-grab active:cursor-grabbing {{ in_array($evt->status, ['completed', 'dismissed'], true) ? 'line-through opacity-70' : '' }}"
                                style="{{ $chipStyle }}"
                                title="{{ $evt->title }}{{ $isTentative ? ' (Tentative)' : '' }}{{ $isPending ? ' (Pending — accept to confirm)' : '' }}">
                            <span class="rag-dot w-1.5 h-1.5 rounded-full inline-block mr-0.5 align-middle" style="display:none;"></span>@if($isPending)<span class="text-[9px] font-bold uppercase mr-0.5" style="opacity:0.7;">PENDING</span> @endif{{ $timeRange($evt) ? $timeRange($evt) . ' ' : '' }}{{ \Illuminate\Support\Str::limit($evt->title, $isPending ? 14 : 20) }}
                        </button>
                    @endforeach
                </div>

                {{-- AT-164 Gate 1/2 — aggregate deadline chips + popover drill-down. --}}
                @php $dayDeadlines = $deadlineGroups[$dateStr] ?? []; @endphp
                @if(!empty($dayDeadlines))
                    <div class="space-y-0.5 mt-0.5">
                        @foreach($dayDeadlines as $grp)
                            @php $gChip = $ragChip[$grp['worst']] ?? $defaultChip; @endphp
                            <div class="cal-layerable relative" data-layer="{{ \App\Services\CommandCenter\Calendar\CalendarLayers::layerForType($grp['group']) }}" x-data="{ dlOpen: false }" @click.outside="dlOpen = false" :style="dlOpen ? 'position: relative; z-index: 9999;' : ''">
                                <button type="button"
                                        data-deadline-group="{{ $grp['group'] }}"
                                        @click.stop="dlOpen = !dlOpen"
                                        class="flex w-full items-center gap-1 text-[11px] leading-tight px-1.5 py-0.5 rounded hover:opacity-80 transition-opacity cursor-pointer"
                                        style="{{ $gChip }}"
                                        title="{{ $grp['count'] }} {{ $grp['label'] }} due — click to list">
                                    <span class="font-bold">{{ $grp['count'] }}</span>
                                    <span class="truncate">{{ $grp['label'] }}</span>
                                </button>
                                <div x-show="dlOpen" x-cloak @click.stop
                                     class="absolute left-0 mt-1 w-64 max-h-64 overflow-y-auto rounded-lg text-left"
                                     style="z-index:9999; background:var(--surface,#ffffff); border:1px solid var(--border,#e5e7eb); box-shadow:0 8px 24px rgba(0,0,0,0.18);">
                                    <div class="px-3 py-2 text-[11px] font-semibold" style="border-bottom:1px solid var(--border,#e5e7eb); color:var(--text-muted,#9ca3af);">
                                        {{ $grp['count'] }} {{ $grp['label'] }} · {{ $cellDate->format('d M') }}
                                    </div>
                                    @foreach($grp['items'] as $it)
                                        @php $dotBg = ['red'=>'#dc2626','amber'=>'#d97706','green'=>'#0d9488'][$it['rag']] ?? '#94a3b8'; @endphp
                                        @if($it['url'])
                                            <a href="{{ $it['url'] }}" target="_blank" rel="noopener"
                                               class="flex items-center gap-2 px-3 py-1.5 text-xs hover:opacity-80" style="color:var(--text-primary,#111827);">
                                                <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:{{ $dotBg }};"></span>
                                                <span class="flex-1 truncate">{{ $it['title'] }}</span>
                                                @if($it['due'])<span style="color:var(--text-muted,#9ca3af);">{{ $it['due'] }}</span>@endif
                                            </a>
                                        @else
                                            <button type="button" @click.stop="dlOpen=false; openEventPanel({{ $it['id'] }})"
                                                    class="flex w-full items-center gap-2 px-3 py-1.5 text-xs text-left hover:opacity-80" style="color:var(--text-primary,#111827);">
                                                <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:{{ $dotBg }};"></span>
                                                <span class="flex-1 truncate">{{ $it['title'] }}</span>
                                                @if($it['due'])<span style="color:var(--text-muted,#9ca3af);">{{ $it['due'] }}</span>@endif
                                            </button>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
