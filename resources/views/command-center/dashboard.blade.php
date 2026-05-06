@extends('layouts.corex')

@section('corex-content')
@php
    $today      = \Carbon\Carbon::now();
    $pct        = $monthlyTarget > 0 ? min(100, round(($mtdPoints / $monthlyTarget) * 100)) : 0;
    $greeting   = now()->hour < 12 ? 'morning' : (now()->hour < 17 ? 'afternoon' : 'evening');

    // Merge events + tasks due today into one Timeline collection, sorted by time.
    $timelineAllDay    = collect();
    $timelineScheduled = collect();
    $timelineUnscheduled = collect();

    foreach ($todayEvents as $ev) {
        $item = (object)[
            'kind'      => 'event',
            'sort_time' => $ev->all_day ? '00:00' : $ev->event_date->format('H:i'),
            'time_label'=> $ev->all_day ? 'All day' : $ev->event_date->format('H:i'),
            'title'     => $ev->title,
            // User-selected event colour (DB) falls back to brand icon token.
            'colour'    => $ev->colour ?: 'var(--brand-icon)',
            'property'  => $ev->property,
            'priority'  => $ev->priority ?? 'normal',
            'model'     => $ev,
            'all_day'   => $ev->all_day,
        ];
        if ($ev->all_day) { $timelineAllDay->push($item); } else { $timelineScheduled->push($item); }
    }

    foreach ($tasksToday as $tk) {
        $hasTime = $tk->due_date && $tk->due_date->format('H:i:s') !== '00:00:00';
        $item = (object)[
            'kind'       => 'task',
            'sort_time'  => $hasTime ? $tk->due_date->format('H:i') : '99:99',
            'time_label' => $hasTime ? $tk->due_date->format('H:i') : 'Unscheduled',
            'title'      => $tk->title,
            // Semantic priority colours — tokens only. Never red for non-danger.
            'colour'     => match ($tk->priority) {
                'critical' => 'var(--ds-crimson)',
                'high'     => 'var(--ds-amber)',
                'low'      => 'var(--text-muted)',
                default    => 'var(--ds-green)',
            },
            'property'   => $tk->property,
            'priority'   => $tk->priority,
            'model'      => $tk,
            'all_day'    => false,
        ];
        if ($hasTime) { $timelineScheduled->push($item); } else { $timelineUnscheduled->push($item); }
    }

    $timelineScheduled = $timelineScheduled->sortBy('sort_time')->values();

    $timelineEmpty = $timelineAllDay->isEmpty()
        && $timelineScheduled->isEmpty()
        && $timelineUnscheduled->isEmpty();
@endphp

<div class="space-y-5" x-data="cockpit()">

    {{-- ═══════════════════════════════════════════════════════════════
         HEADER — compact: greeting · date · quick-add · nav links
    ═══════════════════════════════════════════════════════════════ --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight" style="color:var(--text-primary);">
                Good {{ $greeting }}, {{ $user->name }}
            </h1>
            <p class="text-sm mt-0.5" style="color:var(--text-secondary);">
                {{ $today->format('l, d F Y') }}
                @if($inboxTotal > 0)
                    <span class="ml-2 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium"
                          style="background:color-mix(in srgb, var(--ds-amber) 12%, transparent); color:var(--ds-amber);">
                        {{ $inboxTotal }} need action
                    </span>
                @endif
            </p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <button type="button" @click="showQuickAdd = true; quickAddKind = 'task'" class="corex-btn-primary">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Quick Add
            </button>
            <a href="{{ route('command-center.calendar') }}" class="corex-btn-outline">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                Calendar
            </a>
            <a href="{{ route('command-center.tasks') }}" class="corex-btn-outline">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                Tasks
            </a>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════
         COCKPIT GRID — Timeline (2/3) + Inbox (1/3)
    ═══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        {{-- ── LEFT: TODAY TIMELINE ── --}}
        <div class="lg:col-span-2">
            <div class="corex-panel h-full">
                <div class="corex-panel-header">
                    <h3 class="corex-panel-title flex items-center gap-2">
                        <svg class="w-4 h-4" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        Today
                        <span class="text-xs font-normal ml-1" style="color:var(--text-muted);">
                            {{ $today->format('D d M') }}
                        </span>
                    </h3>
                    <div class="flex items-center gap-3 text-xs">
                        <span style="color:var(--text-muted);">
                            {{ $todayEvents->count() }} events · {{ $tasksToday->count() }} tasks
                        </span>
                    </div>
                </div>

                <div class="corex-panel-body">
                    @if($timelineEmpty)
                        <div class="py-12 text-center">
                            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                                 style="background:color-mix(in srgb, var(--brand-icon) 12%, transparent); color:var(--brand-icon);">
                                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                            </div>
                            <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">Your day is clear</h3>
                            <p class="text-sm mb-4" style="color:var(--text-muted);">Nothing scheduled — use Quick Add to plan the day.</p>
                            <button type="button" @click="showQuickAdd = true; quickAddKind = 'event'" class="corex-btn-outline">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                Add Event
                            </button>
                        </div>
                    @else

                        {{-- All-day row --}}
                        @if($timelineAllDay->isNotEmpty())
                            <div class="mb-4 pb-3 border-b" style="border-color:var(--border);">
                                <p class="text-xs font-medium mb-2" style="color:var(--text-muted);">ALL DAY</p>
                                <div class="space-y-1.5">
                                    @foreach($timelineAllDay as $item)
                                        @include('command-center.partials.timeline-row', ['item' => $item])
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- Scheduled rows --}}
                        @if($timelineScheduled->isNotEmpty())
                            <div class="space-y-1">
                                @foreach($timelineScheduled as $item)
                                    @include('command-center.partials.timeline-row', ['item' => $item])
                                @endforeach
                            </div>
                        @endif

                        {{-- Unscheduled tasks --}}
                        @if($timelineUnscheduled->isNotEmpty())
                            <div class="mt-4 pt-3 border-t" style="border-color:var(--border);">
                                <p class="text-xs font-medium mb-2" style="color:var(--text-muted);">UNSCHEDULED</p>
                                <div class="space-y-1">
                                    @foreach($timelineUnscheduled as $item)
                                        @include('command-center.partials.timeline-row', ['item' => $item])
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        </div>

        {{-- ── RIGHT: ACTION INBOX ── --}}
        <div>
            <div class="corex-panel h-full">
                <div class="corex-panel-header">
                    <h3 class="corex-panel-title flex items-center gap-2">
                        <svg class="w-4 h-4" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H6.911a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661Z" /></svg>
                        Inbox
                    </h3>
                    @if($inboxTotal > 0)
                        <span class="ds-badge ds-badge-warning">{{ $inboxTotal }}</span>
                    @else
                        <span class="ds-badge ds-badge-success">Clear</span>
                    @endif
                </div>

                <div class="corex-panel-body">
                    @if($inboxTotal === 0)
                        <div class="py-8 text-center">
                            <div class="w-10 h-10 rounded-full mx-auto mb-3 flex items-center justify-center"
                                 style="background:color-mix(in srgb, var(--ds-green) 12%, transparent); color:var(--ds-green);">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            </div>
                            <p class="text-sm font-medium" style="color:var(--text-primary);">Inbox clear</p>
                            <p class="text-xs mt-1" style="color:var(--text-muted);">You're on top of things.</p>
                        </div>
                    @else
                        <div class="space-y-2.5 overflow-y-auto pr-1" style="max-height:32rem;">

                            {{-- Overdue tasks --}}
                            @foreach($inboxOverdueTasks as $task)
                                @php
                                    // Resolve click destination: pillar first (property → contact → deal),
                                    // falling back to the tasks index so every inbox item is actionable.
                                    $taskLink = $task->property ? route('corex.properties.show', $task->property)
                                              : ($task->contact  ? route('corex.contacts.show',  $task->contact)
                                              : ($task->deal_id  ? route('deals-v2.show',        $task->deal_id)
                                              : route('command-center.tasks')));
                                @endphp
                                <div class="rounded-md p-3 border-l-2 cursor-pointer hover:brightness-110 transition"
                                     style="background:var(--surface-2); border-left-color:var(--ds-crimson);"
                                     x-data="{ action: null, extDays: 7 }"
                                     onclick="if(!event.target.closest('form,button,input,a')){window.location.href='{{ $taskLink }}'}">
                                    <div class="flex items-start gap-2">
                                        <div class="flex-shrink-0 mt-0.5">
                                            <svg class="w-4 h-4" style="color:var(--ds-amber);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <a href="{{ $taskLink }}" class="text-sm font-medium hover:underline" style="color:var(--text-primary);">{{ $task->title }}</a>
                                            <p class="text-xs mt-0.5" style="color:var(--ds-crimson);">
                                                Due {{ $task->due_date->diffForHumans() }}
                                                @if($task->property)
                                                    <span style="color:var(--text-muted);"> · <a href="{{ route('corex.properties.show', $task->property) }}" class="hover:underline" style="color:var(--text-muted);">{{ $task->property->buildDisplayAddress() }}</a></span>
                                                @elseif($task->contact)
                                                    <span style="color:var(--text-muted);"> · <a href="{{ route('corex.contacts.show', $task->contact) }}" class="hover:underline" style="color:var(--text-muted);">{{ $task->contact->first_name }} {{ $task->contact->last_name }}</a></span>
                                                @endif
                                            </p>

                                            <div class="flex flex-wrap items-center gap-1.5 mt-2" x-show="!action">
                                                <form method="POST" action="{{ route('command-center.resolve-task', $task) }}" class="inline">
                                                    @csrf
                                                    <input type="hidden" name="resolution" value="completed">
                                                    <button type="submit" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-semibold text-white" style="background:var(--ds-green);">
                                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                                        Done
                                                    </button>
                                                </form>
                                                <button type="button" @click="action = 'extend'" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium"
                                                        style="background:color-mix(in srgb, var(--brand-icon) 12%, transparent); color:var(--brand-icon);">
                                                    Reschedule
                                                </button>
                                                <form method="POST" action="{{ route('command-center.resolve-task', $task) }}" class="inline">
                                                    @csrf
                                                    <input type="hidden" name="resolution" value="did_not_happen">
                                                    <button type="submit" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium"
                                                            style="background:var(--surface); border:1px solid var(--border); color:var(--text-muted);">
                                                        Skip
                                                    </button>
                                                </form>
                                            </div>

                                            <div x-show="action === 'extend'" x-cloak class="mt-2">
                                                <form method="POST" action="{{ route('command-center.resolve-task', $task) }}" class="flex items-center gap-2">
                                                    @csrf
                                                    <input type="hidden" name="resolution" value="extended">
                                                    <input type="number" name="extend_days" x-model="extDays" min="1" max="90"
                                                           class="w-16 px-2 py-1 rounded-md text-xs"
                                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                                    <span class="text-xs" style="color:var(--text-muted);">days</span>
                                                    <button type="submit" class="px-2.5 py-1 rounded-md text-xs font-semibold text-white" style="background:var(--brand-button);">Save</button>
                                                    <button type="button" @click="action = null" class="text-xs" style="color:var(--text-muted);">Cancel</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach

                            {{-- Overdue events --}}
                            @foreach($inboxOverdueEvents as $event)
                                @php
                                    // Resolve click destination: pillar first (property → contact),
                                    // falling back to the calendar so every inbox item is actionable.
                                    $eventLink = $event->property ? route('corex.properties.show', $event->property)
                                               : ($event->contact ? route('corex.contacts.show',  $event->contact)
                                               : route('command-center.calendar'));
                                @endphp
                                <div class="rounded-md p-3 border-l-2 cursor-pointer hover:brightness-110 transition"
                                     style="background:var(--surface-2); border-left-color:{{ $event->colour ?: 'var(--ds-crimson)' }};"
                                     x-data="{ action: null, extDays: 7 }"
                                     onclick="if(!event.target.closest('form,button,input,a')){window.location.href='{{ $eventLink }}'}">
                                    <div class="flex items-start gap-2">
                                        <div class="flex-shrink-0 mt-0.5 w-3 h-3 rounded-full" style="background:{{ $event->colour ?: 'var(--ds-crimson)' }};"></div>
                                        <div class="flex-1 min-w-0">
                                            <a href="{{ $eventLink }}" class="text-sm font-medium hover:underline" style="color:var(--text-primary);">{{ $event->title }}</a>
                                            <p class="text-xs mt-0.5" style="color:var(--ds-crimson);">
                                                Was {{ $event->event_date->diffForHumans() }}
                                                @if($event->property)
                                                    <span style="color:var(--text-muted);"> · <a href="{{ route('corex.properties.show', $event->property) }}" class="hover:underline" style="color:var(--text-muted);">{{ $event->property->buildDisplayAddress() }}</a></span>
                                                @elseif($event->contact)
                                                    <span style="color:var(--text-muted);"> · <a href="{{ route('corex.contacts.show', $event->contact) }}" class="hover:underline" style="color:var(--text-muted);">{{ $event->contact->first_name }} {{ $event->contact->last_name }}</a></span>
                                                @endif
                                            </p>

                                            <div class="flex flex-wrap items-center gap-1.5 mt-2" x-show="!action">
                                                <form method="POST" action="{{ route('command-center.resolve-event', $event) }}" class="inline">
                                                    @csrf
                                                    <input type="hidden" name="resolution" value="completed">
                                                    <button type="submit" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-semibold text-white" style="background:var(--ds-green);">
                                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                                        Done
                                                    </button>
                                                </form>
                                                <button type="button" @click="action = 'extend'" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium"
                                                        style="background:color-mix(in srgb, var(--brand-icon) 12%, transparent); color:var(--brand-icon);">
                                                    Reschedule
                                                </button>
                                                <form method="POST" action="{{ route('command-center.resolve-event', $event) }}" class="inline">
                                                    @csrf
                                                    <input type="hidden" name="resolution" value="did_not_happen">
                                                    <button type="submit" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium"
                                                            style="background:var(--surface); border:1px solid var(--border); color:var(--text-muted);">
                                                        Skip
                                                    </button>
                                                </form>
                                            </div>

                                            <div x-show="action === 'extend'" x-cloak class="mt-2">
                                                <form method="POST" action="{{ route('command-center.resolve-event', $event) }}" class="flex items-center gap-2">
                                                    @csrf
                                                    <input type="hidden" name="resolution" value="extended">
                                                    <input type="number" name="extend_days" x-model="extDays" min="1" max="90"
                                                           class="w-16 px-2 py-1 rounded-md text-xs"
                                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                                    <span class="text-xs" style="color:var(--text-muted);">days</span>
                                                    <button type="submit" class="px-2.5 py-1 rounded-md text-xs font-semibold text-white" style="background:var(--brand-button);">Save</button>
                                                    <button type="button" @click="action = null" class="text-xs" style="color:var(--text-muted);">Cancel</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach

                            {{-- Candidate documents (supervisors only) --}}
                            @foreach($candidateDocs as $doc)
                                @php $docLink = route('docuperfect.signatures.review', $doc->document_id); @endphp
                                <div class="rounded-md p-3 border-l-2 cursor-pointer hover:brightness-110 transition"
                                     style="background:var(--surface-2); border-left-color:var(--ds-amber);"
                                     onclick="if(!event.target.closest('form,button,input,a')){window.location.href='{{ $docLink }}'}">
                                    <div class="flex items-start gap-2">
                                        <div class="flex-shrink-0 mt-0.5">
                                            <svg class="w-4 h-4" style="color:var(--ds-amber);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <a href="{{ $docLink }}" class="block text-sm font-medium truncate hover:underline" style="color:var(--text-primary);">{{ $doc->document->name ?? 'Untitled Document' }}</a>
                                            <p class="text-xs mt-0.5" style="color:var(--text-muted);">
                                                {{ $doc->status === 'awaiting_supervisor' ? 'Initial review' : 'Final sign-off' }}
                                                · {{ $doc->creator->name ?? 'Unknown' }}
                                            </p>
                                            <div class="mt-2">
                                                <a href="{{ route('docuperfect.signatures.review', $doc->document_id) }}"
                                                   class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-semibold text-white"
                                                   style="background:var(--ds-amber);">
                                                    Review &amp; Authorise
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach

                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════
         COMING UP — Calendar agenda widget (next 7 days)
    ═══════════════════════════════════════════════════════════════ --}}
    <div class="corex-panel">
        <div class="corex-panel-header">
            <h3 class="corex-panel-title flex items-center gap-2">
                <svg class="w-4 h-4" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                Coming up
                <span class="text-xs font-normal ml-1" style="color:var(--text-muted);">Next 7 days</span>
            </h3>
            <a href="{{ route('command-center.calendar') }}" class="text-xs font-medium hover:underline" style="color:var(--brand-icon);">View all &rarr;</a>
        </div>
        <div class="corex-panel-body">
            @php
                $widgetRagChip = [
                    'red'     => 'background:#dc2626; color:#ffffff; border:1px solid #991b1b;',
                    'amber'   => 'background:#d97706; color:#ffffff; border:1px solid #92400e;',
                    'green'   => 'background:#0d9488; color:#ffffff; border:1px solid #115e59;',
                    'neutral' => 'background:#475569; color:#ffffff; border:1px solid #334155;',
                ];
                $widgetRagDot = [
                    'red'   => 'background:#ef4444;',
                    'amber' => 'background:#f59e0b;',
                    'green' => 'background:#14b8a6;',
                ];
            @endphp

            @if($upcomingEvents->isEmpty())
                <div class="py-8 text-center">
                    <p class="text-sm" style="color:var(--text-muted);">Nothing in the next 7 days.</p>
                    <a href="{{ route('command-center.calendar') }}" class="text-xs mt-2 inline-block hover:underline" style="color:var(--brand-icon);">Open calendar</a>
                </div>
            @else
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach($upcomingByDate as $date => $dateEvents)
                        @php
                            $dateObj = \Carbon\Carbon::parse($date);
                            $dayLabel = $dateObj->isToday() ? 'Today' : ($dateObj->isTomorrow() ? 'Tomorrow' : $dateObj->format('D, j M'));
                        @endphp
                        <div>
                            <div class="text-[0.6875rem] font-semibold uppercase tracking-wider mb-2" style="color:{{ $dateObj->isToday() ? 'var(--brand-icon)' : 'var(--text-muted)' }};">
                                {{ $dayLabel }}
                            </div>
                            <div class="space-y-1.5">
                                @foreach($dateEvents as $evt)
                                    <a href="{{ route('command-center.calendar', ['view' => 'day', 'date' => $date]) }}"
                                       class="flex items-center gap-2 px-2.5 py-1.5 rounded text-xs transition hover:opacity-80"
                                       style="{{ $widgetRagChip[$evt->resolved_colour] ?? '' }}">
                                        <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="{{ $widgetRagDot[$evt->resolved_colour] ?? '' }}"></span>
                                        <span class="flex-1 truncate font-medium">{{ $evt->title }}</span>
                                        <span class="text-[10px] opacity-70 truncate flex-shrink-0">{{ $upcomingClassLabels[$evt->category] ?? $evt->category }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════
         FOOTER STRIP — thin performance summary
    ═══════════════════════════════════════════════════════════════ --}}
    <div class="corex-panel">
        <div class="corex-panel-body py-3">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="flex items-center gap-6 flex-wrap">
                    @if($scorecard)
                        @php
                            // Neutral score — never red. Per UI_DESIGN_SYSTEM.md §5 rule 3.
                            $scoreColor = match(true) {
                                $scorecard->overall_score >= 90 => 'var(--ds-green)',
                                $scorecard->overall_score >= 70 => 'var(--brand-icon)',
                                default                         => 'var(--ds-amber)',
                            };
                        @endphp
                        <div class="flex items-center gap-2">
                            <div class="w-9 h-9 rounded-full flex items-center justify-center text-xs font-bold text-white" style="background:{{ $scoreColor }};">
                                {{ $scorecard->overall_score }}
                            </div>
                            <div>
                                <p class="text-xs" style="color:var(--text-muted);">Weekly score</p>
                                <p class="text-xs font-medium" style="color:var(--text-primary);">
                                    {{ number_format($scorecard->tasks_completed) }}/{{ number_format($scorecard->tasks_total) }} tasks
                                </p>
                            </div>
                        </div>
                    @endif

                    <div>
                        <p class="text-xs" style="color:var(--text-muted);">Activity points (MTD)</p>
                        <p class="text-sm font-semibold" style="color:var(--text-primary);">
                            {{ number_format($mtdPoints) }}@if($monthlyTarget > 0) <span class="font-normal text-xs" style="color:var(--text-muted);"> / {{ number_format($monthlyTarget) }}</span>@endif
                        </p>
                        @if($monthlyTarget > 0)
                            <div class="ds-progress-track w-32 mt-1" style="height:4px;">
                                <div class="ds-progress-bar" style="width:{{ $pct }}%; background:var(--brand-icon);"></div>
                            </div>
                        @endif
                    </div>

                    <div>
                        <p class="text-xs" style="color:var(--text-muted);">Open tasks</p>
                        <p class="text-sm font-semibold" style="color:var(--text-primary);">{{ $taskSummary['open'] ?? 0 }}</p>
                    </div>
                </div>

                <a href="{{ route('command-center.performance') }}"
                   class="inline-flex items-center gap-1 text-sm font-medium"
                   style="color:var(--brand-icon);">
                    View Performance
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                </a>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════
         QUICK-ADD MODAL — task OR event toggle
    ═══════════════════════════════════════════════════════════════ --}}
    <div x-show="showQuickAdd" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
         @keydown.escape.window="showQuickAdd = false">
        <div class="w-full max-w-lg rounded-md shadow-xl" style="background:var(--surface);"
             @click.outside="showQuickAdd = false">

            {{-- Toggle header --}}
            <div class="px-6 py-4 border-b flex items-center justify-between" style="border-color:var(--border);">
                <h3 class="text-lg font-semibold" style="color:var(--text-primary);">Quick Add</h3>
                <div class="inline-flex rounded-md overflow-hidden" style="background:var(--surface-2);">
                    <button type="button" @click="quickAddKind = 'task'"
                            :class="quickAddKind === 'task' ? 'text-white' : ''"
                            :style="quickAddKind === 'task' ? 'background:var(--brand-button);' : 'color:var(--text-secondary);'"
                            class="px-3 py-1.5 text-sm font-medium transition-colors">
                        Task
                    </button>
                    <button type="button" @click="quickAddKind = 'event'"
                            :class="quickAddKind === 'event' ? 'text-white' : ''"
                            :style="quickAddKind === 'event' ? 'background:var(--brand-button);' : 'color:var(--text-secondary);'"
                            class="px-3 py-1.5 text-sm font-medium transition-colors">
                        Event
                    </button>
                </div>
            </div>

            {{-- Task form --}}
            <form x-show="quickAddKind === 'task'" method="POST" action="{{ route('command-center.tasks.store') }}">
                @csrf
                <div class="px-6 py-4 space-y-4">
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Title</label>
                        <input type="text" name="title" required autofocus
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Priority</label>
                            <select name="priority" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                <option value="normal">Normal</option>
                                <option value="low">Low</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Due date</label>
                            <input type="date" name="due_date"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Description</label>
                        <textarea name="description" rows="2"
                                  class="w-full rounded-md px-3 py-2 text-sm"
                                  style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                    </div>
                    <label class="flex items-center gap-2 text-sm" style="color:var(--text-secondary);">
                        <input type="hidden" name="send_reminder" value="0">
                        <input type="checkbox" name="send_reminder" value="1" checked class="rounded">
                        Remind me before due
                    </label>
                </div>
                <div class="px-6 py-4 border-t flex justify-end gap-2" style="border-color:var(--border);">
                    <button type="button" @click="showQuickAdd = false" class="corex-btn-outline">Cancel</button>
                    <button type="submit" class="corex-btn-primary">Create Task</button>
                </div>
            </form>

            {{-- Event form --}}
            <form x-show="quickAddKind === 'event'" x-cloak method="POST" action="{{ route('command-center.calendar.store') }}">
                @csrf
                <div class="px-6 py-4 space-y-4">
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Title</label>
                        <input type="text" name="title" required
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">When</label>
                            <input type="datetime-local" name="event_date" required
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Priority</label>
                            <select name="priority" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                <option value="normal">Normal</option>
                                <option value="low">Low</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Description</label>
                        <textarea name="description" rows="2"
                                  class="w-full rounded-md px-3 py-2 text-sm"
                                  style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                    </div>
                    <label class="flex items-center gap-2 text-sm" style="color:var(--text-secondary);">
                        <input type="hidden" name="send_reminder" value="0">
                        <input type="checkbox" name="send_reminder" value="1" checked class="rounded">
                        Remind me before the event
                    </label>
                </div>
                <div class="px-6 py-4 border-t flex justify-end gap-2" style="border-color:var(--border);">
                    <button type="button" @click="showQuickAdd = false" class="corex-btn-outline">Cancel</button>
                    <button type="submit" class="corex-btn-primary">Add Event</button>
                </div>
            </form>
        </div>
    </div>

</div>

<script>
function cockpit() {
    return {
        showQuickAdd: false,
        quickAddKind: 'task',
    };
}
</script>
@endsection
