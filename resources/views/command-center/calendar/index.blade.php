@extends('layouts.corex')

@section('corex-content')
@php
    $carbon = \Carbon\Carbon::create($year, $month, 1);
    $monthLabel = $carbon->format('F Y');
    $prevMonth = $carbon->copy()->subMonth();
    $nextMonth = $carbon->copy()->addMonth();
    $daysInMonth = $carbon->daysInMonth;
    $firstDayOfWeek = $carbon->dayOfWeekIso; // 1=Mon
    $today = \Carbon\Carbon::today();

    $typeColours = \App\Models\CommandCenter\CalendarEvent::TYPE_COLOURS;
@endphp

<div class="space-y-6" x-data="calendarPage()">

    {{-- ══════ PAGE HEADER (Pattern A — branded) ══════ --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Calendar</h1>
                <p class="text-sm text-white/60">{{ $monthLabel }} — deals, leases, compliance and personal events.</p>
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
    <div class="rounded-md px-4 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3"
         style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex items-center gap-2">
            <a href="{{ route('command-center.calendar', ['year' => $prevMonth->year, 'month' => $prevMonth->month, 'view' => $currentView]) }}"
               class="corex-btn-outline" aria-label="Previous month">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
            </a>

            {{-- Month/Year picker popover --}}
            <div class="relative" x-data="{ open: false, pickerYear: {{ $year }} }">
                <button type="button" @click="open = !open"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-semibold transition-colors"
                        style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);"
                        :aria-expanded="open">
                    <span>{{ $monthLabel }}</span>
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" :class="{'rotate-180': open}" style="transition: transform 150ms;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </button>
                <div x-show="open" x-cloak x-transition @click.outside="open = false"
                     class="absolute left-0 top-full mt-2 z-40 rounded-md p-3 w-64"
                     style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 8px 24px rgba(0,0,0,0.18);">
                    {{-- Year nav --}}
                    <div class="flex items-center justify-between mb-3">
                        <button type="button" @click="pickerYear--"
                                class="p-1 rounded transition-colors"
                                style="color: var(--text-secondary);"
                                onmouseover="this.style.background='var(--surface-2)'"
                                onmouseout="this.style.background='transparent'"
                                aria-label="Previous year">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                        </button>
                        <span class="text-sm font-semibold" style="color: var(--text-primary);" x-text="pickerYear"></span>
                        <button type="button" @click="pickerYear++"
                                class="p-1 rounded transition-colors"
                                style="color: var(--text-secondary);"
                                onmouseover="this.style.background='var(--surface-2)'"
                                onmouseout="this.style.background='transparent'"
                                aria-label="Next year">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                        </button>
                    </div>
                    {{-- Month grid --}}
                    <div class="grid grid-cols-3 gap-1">
                        @php
                            $monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                            $routeBase = route('command-center.calendar');
                        @endphp
                        @foreach($monthNames as $idx => $mName)
                            @php $m = $idx + 1; @endphp
                            @php
                                $extra = '';
                                if ($currentView === 'agenda') {
                                    $extra = '&range='.$agendaRange;
                                    if ($agendaRange === 'custom') {
                                        $extra .= '&from='.$agendaFrom.'&to='.$agendaTo;
                                    }
                                }
                            @endphp
                            <a x-bind:href="'{{ $routeBase }}?year=' + pickerYear + '&month={{ $m }}&view={{ $currentView }}{{ $extra }}'"
                               class="px-2 py-1.5 text-xs font-medium text-center rounded transition-colors"
                               :style="pickerYear === {{ $year }} && {{ $m }} === {{ $month }}
                                    ? 'background: var(--brand-button); color: #fff;'
                                    : (pickerYear === {{ now()->year }} && {{ $m }} === {{ now()->month }}
                                        ? 'background: color-mix(in srgb, var(--brand-button) 12%, transparent); color: var(--brand-button);'
                                        : 'color: var(--text-secondary);')"
                               onmouseover="if(!this.style.background.includes('var(--brand-button)') && !this.style.background.includes('color-mix')) this.style.background='var(--surface-2)'"
                               onmouseout="if(!this.style.background.includes('var(--brand-button)') && !this.style.background.includes('color-mix')) this.style.background='transparent'">
                                {{ $mName }}
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>

            <a href="{{ route('command-center.calendar', ['year' => $nextMonth->year, 'month' => $nextMonth->month, 'view' => $currentView]) }}"
               class="corex-btn-outline" aria-label="Next month">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </a>
            @if($year !== now()->year || $month !== now()->month)
                <a href="{{ route('command-center.calendar', ['view' => $currentView]) }}" class="corex-btn-outline">Today</a>
            @endif
        </div>

        <div class="inline-flex rounded-md overflow-hidden" style="background: var(--surface-2); border: 1px solid var(--border);">
            @foreach(['month' => 'Month', 'agenda' => 'Agenda'] as $vKey => $vLabel)
                <a href="{{ route('command-center.calendar', ['year' => $year, 'month' => $month, 'view' => $vKey]) }}"
                   class="px-3 py-1.5 text-xs font-semibold transition-colors"
                   style="{{ $currentView === $vKey ? 'background: var(--brand-button); color: #fff;' : 'color: var(--text-secondary);' }}">
                    {{ $vLabel }}
                </a>
            @endforeach
        </div>
    </div>

    {{-- ══════ LEGEND ══════ --}}
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs" style="color: var(--text-muted);">
        @foreach($typeColours as $type => $colour)
            <span class="inline-flex items-center gap-1.5 whitespace-nowrap">
                <span class="w-2.5 h-2.5 rounded" style="background: {{ $colour }};"></span>
                {{ ucfirst($type) }}
            </span>
        @endforeach
    </div>

    @if($currentView === 'month')
        {{-- ══════ MONTH VIEW ══════ --}}
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            {{-- Day headers --}}
            <div class="grid grid-cols-7" style="background: var(--surface-2); border-bottom: 1px solid var(--border);">
                @foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dayName)
                    <div class="px-2 py-2.5 text-xs font-semibold text-center uppercase tracking-wider"
                         style="color: var(--text-muted); {{ !$loop->last ? 'border-right: 1px solid var(--border);' : '' }}">
                        {{ $dayName }}
                    </div>
                @endforeach
            </div>

            {{-- Calendar grid --}}
            <div class="grid grid-cols-7">
                {{-- Empty cells before first of month --}}
                @for($i = 1; $i < $firstDayOfWeek; $i++)
                    <div class="min-h-[6rem] p-1"
                         style="background: var(--surface-2); border-bottom: 1px solid var(--border); {{ $i < 7 ? 'border-right: 1px solid var(--border);' : '' }} opacity: 0.5;"></div>
                @endfor

                @for($d = 1; $d <= $daysInMonth; $d++)
                    @php
                        $dateStr = $carbon->copy()->day($d)->toDateString();
                        $dayEvents = $byDate[$dateStr] ?? [];
                        $isToday = $carbon->copy()->day($d)->isSameDay($today);
                        $isWeekend = in_array($carbon->copy()->day($d)->dayOfWeekIso, [6, 7]);
                        $cellCol = ($firstDayOfWeek - 1 + $d - 1) % 7; // 0=Mon
                        $cellBg = $isToday
                            ? 'color-mix(in srgb, var(--brand-button) 8%, transparent)'
                            : ($isWeekend ? 'var(--surface-2)' : 'transparent');
                    @endphp
                    <div role="button" tabindex="0"
                         @click="openForDate('{{ $dateStr }}')"
                         @keydown.enter.prevent="openForDate('{{ $dateStr }}')"
                         @keydown.space.prevent="openForDate('{{ $dateStr }}')"
                         class="min-h-[6rem] p-1 transition-colors cursor-pointer focus:outline-none"
                         style="background: {{ $cellBg }}; border-bottom: 1px solid var(--border); {{ $cellCol < 6 ? 'border-right: 1px solid var(--border);' : '' }}"
                         onmouseover="this.style.background='color-mix(in srgb, var(--brand-button) 6%, transparent)'"
                         onmouseout="this.style.background='{{ $cellBg }}'"
                         title="Click to add an event on {{ $carbon->copy()->day($d)->format('D, d M Y') }}">
                        <div class="flex items-center justify-between mb-1 pointer-events-none">
                            <span class="text-xs font-semibold {{ $isToday ? 'px-1.5 py-0.5 rounded-md text-white' : '' }}"
                                  style="{{ $isToday ? 'background: var(--brand-button);' : 'color: var(--text-secondary);' }}">
                                {{ $d }}
                            </span>
                            @if(count($dayEvents) > 3)
                                <span class="text-[10px] px-1.5 py-0.5 rounded font-medium whitespace-nowrap"
                                      style="background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border);">
                                    +{{ count($dayEvents) - 3 }}
                                </span>
                            @endif
                        </div>
                        <div class="space-y-0.5">
                            @foreach(array_slice($dayEvents, 0, 3) as $evt)
                                @if($evt->property_id)
                                    <a href="{{ route('corex.properties.show', $evt->property_id) }}"
                                       @click.stop
                                       class="block text-[11px] leading-tight px-1.5 py-0.5 rounded truncate hover:opacity-80 transition-opacity"
                                       style="background: {{ $evt->colour }}22; color: {{ $evt->colour }}; border-left: 2px solid {{ $evt->colour }};"
                                       title="{{ $evt->title }} — Click to view property">
                                        {{ $evt->all_day ? '' : $evt->event_date->format('H:i') . ' ' }}{{ \Illuminate\Support\Str::limit($evt->title, 20) }}
                                    </a>
                                @else
                                    <div class="text-[11px] leading-tight px-1.5 py-0.5 rounded truncate pointer-events-none"
                                         style="background: {{ $evt->colour }}22; color: {{ $evt->colour }}; border-left: 2px solid {{ $evt->colour }};"
                                         title="{{ $evt->title }}">
                                        {{ $evt->all_day ? '' : $evt->event_date->format('H:i') . ' ' }}{{ \Illuminate\Support\Str::limit($evt->title, 20) }}
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endfor

                {{-- Fill remaining cells --}}
                @php $remaining = 7 - (($firstDayOfWeek - 1 + $daysInMonth) % 7); @endphp
                @if($remaining < 7)
                    @for($i = 0; $i < $remaining; $i++)
                        <div class="min-h-[6rem] p-1"
                             style="background: var(--surface-2); {{ $i < $remaining - 1 ? 'border-right: 1px solid var(--border);' : '' }} opacity: 0.5;"></div>
                    @endfor
                @endif
            </div>
        </div>
    @else
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
                                <span class="text-sm font-semibold"
                                      style="color: {{ $dateObj->isToday() ? 'var(--brand-icon)' : 'var(--text-primary)' }};">
                                    {{ $dateObj->format($dateObj->year === now()->year ? 'D, d M' : 'D, d M Y') }}
                                </span>
                                @if($dateObj->isToday())
                                    <span class="ds-badge ds-badge-info">Today</span>
                                @endif
                            </div>
                            <div class="space-y-1">
                                @foreach($dayEvents as $evt)
                                    <div class="flex items-center gap-3 py-1.5 px-2 rounded-md transition-colors group"
                                         style="background: transparent;"
                                         onmouseover="this.style.background='var(--surface-2)'"
                                         onmouseout="this.style.background='transparent'">
                                        <div class="w-1.5 h-6 rounded flex-shrink-0" style="background: {{ $evt->colour }};"></div>
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
                                              style="background: {{ $evt->colour }}22; color: {{ $evt->colour }};">
                                            {{ ucfirst($evt->event_type) }}
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

    {{-- ══════ CREATE EVENT MODAL ══════ --}}
    <div x-show="showCreateEvent" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
         @keydown.escape.window="showCreateEvent = false">
        <div class="w-full max-w-lg rounded-md"
             style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 10px 30px rgba(0,0,0,0.18);"
             @click.outside="showCreateEvent = false">
            <form method="POST" action="{{ route('command-center.calendar.store') }}">
                @csrf
                <div class="px-6 py-4" style="border-bottom: 1px solid var(--border);">
                    <h3 class="text-lg font-semibold" style="color: var(--text-primary);">Add Calendar Event</h3>
                </div>
                <div class="px-6 py-4 space-y-4">
                    <div>
                        <label for="event-title" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                            Title <span class="text-red-500">*</span>
                        </label>
                        <input id="event-title" type="text" name="title" required
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="event-start" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                                Start <span class="text-red-500">*</span>
                            </label>
                            <input id="event-start" type="datetime-local" name="event_date" required
                                   x-model="presetStart"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                        <div>
                            <label for="event-end" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">End (optional)</label>
                            <input id="event-end" type="datetime-local" name="end_date"
                                   x-model="presetEnd"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="event-type" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Type</label>
                            <select id="event-type" name="event_type"
                                    class="w-full rounded-md px-3 py-2 text-sm"
                                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                <option value="manual">Personal</option>
                                <option value="deal">Deal</option>
                                <option value="lease">Lease / Rental</option>
                                <option value="compliance">Compliance</option>
                                <option value="prospecting">Prospecting</option>
                            </select>
                        </div>
                        <div>
                            <label for="event-priority" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Priority</label>
                            <select id="event-priority" name="priority"
                                    class="w-full rounded-md px-3 py-2 text-sm"
                                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                <option value="normal">Normal</option>
                                <option value="low">Low</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label for="event-description" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Description</label>
                        <textarea id="event-description" name="description" rows="4"
                                  class="w-full rounded-md px-3 py-2 text-sm"
                                  style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"></textarea>
                    </div>
                    <div>
                        <label class="inline-flex items-center gap-2 text-sm" style="color: var(--text-secondary);">
                            <input type="hidden" name="send_reminder" value="0">
                            <input type="checkbox" name="send_reminder" value="1" checked class="rounded">
                            Send me a reminder before this event
                        </label>
                    </div>
                </div>
                <div class="px-6 py-4 flex justify-end gap-2" style="border-top: 1px solid var(--border);">
                    <button type="button" @click="showCreateEvent = false" class="corex-btn-outline">Cancel</button>
                    <button type="submit" class="corex-btn-primary">Add Event</button>
                </div>
            </form>
        </div>
    </div>

</div>

<script>
function calendarPage() {
    return {
        showCreateEvent: false,
        presetStart: '',
        presetEnd: '',
        openForDate(dateStr) {
            // dateStr is YYYY-MM-DD — default to 09:00 on that date.
            this.presetStart = dateStr + 'T09:00';
            this.presetEnd = '';
            this.showCreateEvent = true;
        },
        openBlank() {
            this.presetStart = '';
            this.presetEnd = '';
            this.showCreateEvent = true;
        },
    };
}
</script>
@endsection
