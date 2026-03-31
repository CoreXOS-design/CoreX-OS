@extends('layouts.corex')

@section('corex-content')
@php
    $pct = $monthlyTarget > 0 ? min(100, round(($mtdPoints / $monthlyTarget) * 100)) : 0;
    $monthLabel = \Carbon\Carbon::now()->format('F Y');
    $today = \Carbon\Carbon::now();
    $daysInMonth = $today->daysInMonth;
    $firstDayOfWeek = $today->copy()->startOfMonth()->dayOfWeekIso; // 1=Mon
@endphp

<div class="space-y-6" x-data="commandCenter()">

    {{-- ═══════════════════════════════════════════════════════════════
         HEADER ROW
    ═══════════════════════════════════════════════════════════════ --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight" style="color:var(--text-primary);">
                Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 17 ? 'afternoon' : 'evening') }}, {{ $user->name }}
            </h1>
            <p class="text-sm mt-0.5" style="color:var(--text-secondary);">
                {{ $today->format('l, d F Y') }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('command-center.calendar') }}"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-medium transition-colors duration-200"
               style="background:var(--surface-2); color:var(--text-secondary);"
               onmouseover="this.style.background='var(--brand-button)'; this.style.color='#fff'"
               onmouseout="this.style.background='var(--surface-2)'; this.style.color='var(--text-secondary)'">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                Full Calendar
            </a>
            <a href="{{ route('command-center.tasks') }}"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-medium transition-colors duration-200"
               style="background:var(--surface-2); color:var(--text-secondary);"
               onmouseover="this.style.background='var(--brand-button)'; this.style.color='#fff'"
               onmouseout="this.style.background='var(--surface-2)'; this.style.color='var(--text-secondary)'">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                All Tasks
            </a>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════
         STAT CARDS ROW
    ═══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {{-- Today --}}
        <div class="corex-panel">
            <div class="corex-panel-body flex items-center gap-3 py-4">
                <div class="flex-shrink-0 w-10 h-10 rounded-md flex items-center justify-center" style="background: rgba(59,130,246,0.15);">
                    <svg class="w-5 h-5" style="color:#3b82f6;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                </div>
                <div>
                    <p class="text-2xl font-bold" style="color:var(--text-primary);">{{ $todayEvents->count() }}</p>
                    <p class="text-xs" style="color:var(--text-muted);">Today's Events</p>
                </div>
            </div>
        </div>

        {{-- Overdue --}}
        <div class="corex-panel" style="{{ $totalOverdue > 0 ? 'border-left: 3px solid #ef4444;' : '' }}">
            <div class="corex-panel-body flex items-center gap-3 py-4">
                <div class="flex-shrink-0 w-10 h-10 rounded-md flex items-center justify-center" style="background: rgba(239,68,68,0.15);">
                    <svg class="w-5 h-5" style="color:#ef4444;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                </div>
                <div>
                    <p class="text-2xl font-bold" style="color: {{ $totalOverdue > 0 ? '#ef4444' : 'var(--text-primary)' }};">{{ $totalOverdue }}</p>
                    <p class="text-xs" style="color:var(--text-muted);">Overdue</p>
                </div>
            </div>
        </div>

        {{-- This Week --}}
        <div class="corex-panel">
            <div class="corex-panel-body flex items-center gap-3 py-4">
                <div class="flex-shrink-0 w-10 h-10 rounded-md flex items-center justify-center" style="background: rgba(16,185,129,0.15);">
                    <svg class="w-5 h-5" style="color:#10b981;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                </div>
                <div>
                    <p class="text-2xl font-bold" style="color:var(--text-primary);">{{ $weekSummary['total'] }}</p>
                    <p class="text-xs" style="color:var(--text-muted);">This Week</p>
                </div>
            </div>
        </div>

        {{-- Activity Points --}}
        <div class="corex-panel">
            <div class="corex-panel-body flex items-center gap-3 py-4">
                <div class="flex-shrink-0 w-10 h-10 rounded-md flex items-center justify-center" style="background: rgba(14,165,233,0.15);">
                    <svg class="w-5 h-5" style="color:#0ea5e9;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-2xl font-bold" style="color:var(--text-primary);">{{ number_format($mtdPoints) }}</p>
                    <p class="text-xs" style="color:var(--text-muted);">
                        Activity Pts {{ $monthlyTarget > 0 ? '/ ' . number_format($monthlyTarget) : '' }}
                    </p>
                    @if($monthlyTarget > 0)
                        <div class="w-full h-1.5 rounded-full mt-1" style="background:var(--surface-2);">
                            <div class="h-1.5 rounded-full transition-all duration-300" style="width:{{ $pct }}%; background:var(--brand-icon,#0ea5e9);"></div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════
         CANDIDATE DOCUMENTS (preserved from original dashboard)
    ═══════════════════════════════════════════════════════════════ --}}
    @if(isset($candidateDocs) && $candidateDocs->count() > 0)
        <div class="corex-panel border-l-4" style="border-left-color: #f59e0b;">
            <div class="corex-panel-header">
                <h3 class="corex-panel-title" style="color: #b45309;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 inline-block mr-1 -mt-0.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                    CANDIDATE DOCUMENTS — NEEDS AUTHORISATION
                </h3>
                <span class="text-xs font-medium px-2 py-0.5 rounded-full" style="background: #fef3c7; color: #92400e;">
                    {{ $candidateDocs->count() }} pending
                </span>
            </div>
            <div class="corex-panel-body">
                <div class="divide-y" style="border-color: var(--border-default);">
                    @foreach($candidateDocs as $doc)
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between py-3 gap-2 {{ !$loop->first ? 'pt-3' : '' }}">
                            <div class="flex-1 min-w-0">
                                <p class="font-semibold text-sm truncate" style="color: var(--text-primary);">
                                    {{ $doc->document->name ?? 'Untitled Document' }}
                                </p>
                                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-1 text-xs" style="color: var(--text-secondary);">
                                    <span>Candidate: <strong>{{ $doc->creator->name ?? 'Unknown' }}</strong></span>
                                    <span>Created: {{ $doc->created_at->format('d M Y') }}</span>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                        {{ $doc->status === 'awaiting_supervisor' ? 'bg-yellow-100 text-yellow-800' : 'bg-orange-100 text-orange-800' }}">
                                        {{ $doc->status === 'awaiting_supervisor' ? 'Initial Review' : 'Final Sign-off' }}
                                    </span>
                                </div>
                            </div>
                            <div class="flex-shrink-0">
                                <a href="{{ route('docuperfect.signatures.review', $doc->document_id) }}"
                                   class="inline-flex items-center gap-1.5 px-4 py-2 rounded-md text-sm font-semibold text-white shadow transition-all duration-200 hover:opacity-90"
                                   style="background: #f59e0b;">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                    </svg>
                                    Review & Authorise
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════════
         MAIN CONTENT: Two-column layout
    ═══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- LEFT COLUMN (2/3) --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- TODAY'S AGENDA --}}
            <div class="corex-panel">
                <div class="corex-panel-header">
                    <h3 class="corex-panel-title flex items-center gap-2">
                        <svg class="w-4 h-4" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        Today's Agenda
                    </h3>
                    <a href="{{ route('command-center.calendar') }}" class="text-xs font-medium" style="color:var(--brand-icon);">View Calendar</a>
                </div>
                <div class="corex-panel-body">
                    @if($todayEvents->isEmpty())
                        <div class="py-6 text-center">
                            <svg class="w-10 h-10 mx-auto mb-2" style="color:var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                            <p class="text-sm" style="color:var(--text-muted);">No events scheduled for today</p>
                            <button @click="showCreateEvent = true"
                                    class="mt-2 text-xs font-medium px-3 py-1 rounded-md transition-colors"
                                    style="color:var(--brand-icon); background:var(--surface-2);">
                                + Add Event
                            </button>
                        </div>
                    @else
                        <div class="divide-y" style="border-color:var(--border-default);">
                            @foreach($todayEvents as $event)
                                <div class="flex items-start gap-3 py-3 group">
                                    <div class="flex-shrink-0 w-1.5 h-full min-h-[2.5rem] rounded-full mt-1" style="background:{{ $event->colour }};"></div>
                                    <div class="flex-shrink-0 text-xs font-mono pt-0.5" style="color:var(--text-muted); min-width:3rem;">
                                        {{ $event->all_day ? 'All day' : $event->event_date->format('H:i') }}
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium truncate" style="color:var(--text-primary);">{{ $event->title }}</p>
                                        @if($event->property_id)
                                            <p class="text-xs mt-0.5" style="color:var(--text-muted);">
                                                {{ $event->property?->buildDisplayAddress() ?? '' }}
                                            </p>
                                        @endif
                                    </div>
                                    <div class="flex-shrink-0 flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <form method="POST" action="{{ route('command-center.calendar.complete', $event) }}">
                                            @csrf
                                            <button type="submit" class="p-1 rounded hover:bg-green-500/10" title="Complete">
                                                <svg class="w-4 h-4 text-green-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- OVERDUE ITEMS --}}
            @if($overdueEvents->count() > 0 || $overdueTasks->count() > 0)
                <div class="corex-panel border-l-4" style="border-left-color: #ef4444;">
                    <div class="corex-panel-header">
                        <h3 class="corex-panel-title flex items-center gap-2" style="color:#ef4444;">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                            Overdue
                        </h3>
                        <span class="text-xs font-medium px-2 py-0.5 rounded-full" style="background:rgba(239,68,68,0.1); color:#ef4444;">
                            {{ $totalOverdue }}
                        </span>
                    </div>
                    <div class="corex-panel-body">
                        <div class="divide-y" style="border-color:var(--border-default);">
                            @foreach($overdueEvents as $event)
                                <div class="flex items-center gap-3 py-2.5">
                                    <div class="w-1.5 h-8 rounded-full flex-shrink-0" style="background:{{ $event->colour }};"></div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium truncate" style="color:var(--text-primary);">{{ $event->title }}</p>
                                        <p class="text-xs" style="color:#ef4444;">{{ $event->event_date->diffForHumans() }}</p>
                                    </div>
                                    <form method="POST" action="{{ route('command-center.calendar.complete', $event) }}">
                                        @csrf
                                        <button type="submit" class="text-xs px-2 py-1 rounded-md font-medium" style="background:var(--surface-2); color:var(--text-secondary);">Done</button>
                                    </form>
                                </div>
                            @endforeach
                            @foreach($overdueTasks as $task)
                                <div class="flex items-center gap-3 py-2.5">
                                    <div class="w-1.5 h-8 rounded-full flex-shrink-0" style="background:#f97316;"></div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium truncate" style="color:var(--text-primary);">{{ $task->title }}</p>
                                        <p class="text-xs" style="color:#ef4444;">Due {{ $task->due_date->diffForHumans() }}</p>
                                    </div>
                                    <form method="POST" action="{{ route('command-center.tasks.complete', $task) }}">
                                        @csrf
                                        <button type="submit" class="text-xs px-2 py-1 rounded-md font-medium" style="background:var(--surface-2); color:var(--text-secondary);">Done</button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            {{-- MY TASKS --}}
            <div class="corex-panel">
                <div class="corex-panel-header">
                    <h3 class="corex-panel-title flex items-center gap-2">
                        <svg class="w-4 h-4" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        My Tasks
                    </h3>
                    <div class="flex items-center gap-2">
                        <span class="text-xs px-2 py-0.5 rounded-full" style="background:var(--surface-2); color:var(--text-muted);">{{ $taskSummary['open'] }} open</span>
                        <a href="{{ route('command-center.tasks') }}" class="text-xs font-medium" style="color:var(--brand-icon);">View All</a>
                    </div>
                </div>
                <div class="corex-panel-body">
                    @if($myTasks->isEmpty())
                        <div class="py-6 text-center">
                            <svg class="w-10 h-10 mx-auto mb-2" style="color:var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                            <p class="text-sm" style="color:var(--text-muted);">No open tasks</p>
                            <button @click="showCreateTask = true"
                                    class="mt-2 text-xs font-medium px-3 py-1 rounded-md transition-colors"
                                    style="color:var(--brand-icon); background:var(--surface-2);">
                                + Add Task
                            </button>
                        </div>
                    @else
                        <div class="space-y-1">
                            @foreach($myTasks as $task)
                                <div class="flex items-center gap-3 py-2 px-1 rounded-md group hover:bg-white/5 transition-colors">
                                    <form method="POST" action="{{ route('command-center.tasks.complete', $task) }}" class="flex-shrink-0">
                                        @csrf
                                        <button type="submit" class="w-5 h-5 rounded border-2 flex items-center justify-center transition-colors hover:border-green-500 hover:bg-green-500/10"
                                                style="border-color:var(--border-default);">
                                        </button>
                                    </form>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm truncate" style="color:var(--text-primary);">{{ $task->title }}</p>
                                        <div class="flex items-center gap-2 mt-0.5">
                                            @if($task->property)
                                                <span class="text-xs" style="color:var(--text-muted);">{{ $task->property->buildDisplayAddress() }}</span>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 flex-shrink-0">
                                        @if($task->priority === 'critical')
                                            <span class="text-xs px-1.5 py-0.5 rounded font-medium" style="background:rgba(239,68,68,0.1); color:#ef4444;">Critical</span>
                                        @elseif($task->priority === 'high')
                                            <span class="text-xs px-1.5 py-0.5 rounded font-medium" style="background:rgba(245,158,11,0.1); color:#f59e0b;">High</span>
                                        @endif
                                        @if($task->due_date)
                                            <span class="text-xs" style="color: {{ $task->isOverdue() ? '#ef4444' : 'var(--text-muted)' }};">
                                                {{ $task->due_date->isToday() ? 'Today' : ($task->due_date->isTomorrow() ? 'Tomorrow' : $task->due_date->format('d M')) }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- PROPERTIES NEEDING ATTENTION --}}
            <div class="corex-panel">
                <div class="corex-panel-header">
                    <h3 class="corex-panel-title flex items-center gap-2">
                        <svg class="w-4 h-4" style="color:#f97316;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3H21m-3.75 3H21" /></svg>
                        Properties Needing Attention
                    </h3>
                    <div class="flex items-center gap-2 text-xs">
                        @if($propHealthSummary['critical'] > 0)
                            <span class="px-2 py-0.5 rounded-full font-medium" style="background:rgba(239,68,68,0.1); color:#ef4444;">{{ $propHealthSummary['critical'] }} critical</span>
                        @endif
                        @if($propHealthSummary['attention'] > 0)
                            <span class="px-2 py-0.5 rounded-full font-medium" style="background:rgba(245,158,11,0.1); color:#f59e0b;">{{ $propHealthSummary['attention'] }} attention</span>
                        @endif
                        <span class="px-2 py-0.5 rounded-full font-medium" style="background:rgba(16,185,129,0.1); color:#10b981;">{{ $propHealthSummary['good'] }} good</span>
                    </div>
                </div>
                <div class="corex-panel-body">
                    @if($propsNeedingAttention->isEmpty())
                        <div class="py-6 text-center">
                            <svg class="w-10 h-10 mx-auto mb-2 text-green-500" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            <p class="text-sm" style="color:var(--text-muted);">All properties in good health</p>
                        </div>
                    @else
                        <div class="divide-y" style="border-color:var(--border-default);">
                            @foreach($propsNeedingAttention as $health)
                                @php
                                    $prop = $health->property;
                                    $gradeColor = match($health->grade) {
                                        'critical' => '#ef4444',
                                        'attention' => '#f59e0b',
                                        default => '#10b981',
                                    };
                                @endphp
                                <div class="flex items-center gap-3 py-3">
                                    <div class="flex-shrink-0 w-10 h-10 rounded-md flex items-center justify-center text-sm font-bold text-white" style="background:{{ $gradeColor }};">
                                        {{ $health->score }}
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        @if($prop)
                                            <a href="{{ route('corex.properties.show', $prop) }}" class="text-sm font-medium truncate block hover:underline" style="color:var(--text-primary);">
                                                {{ $prop->buildDisplayAddress() ?: ($prop->title ?: 'Property #' . $prop->id) }}
                                            </a>
                                        @endif
                                        <div class="flex flex-wrap gap-x-3 gap-y-0.5 mt-0.5">
                                            @if(is_array($health->factors))
                                                @foreach($health->factors as $key => $factor)
                                                    @if(($factor['penalty'] ?? 0) > 0)
                                                        <span class="text-xs" style="color:{{ $factor['status'] === 'critical' ? '#ef4444' : '#f59e0b' }};">{{ $factor['label'] }}</span>
                                                    @endif
                                                @endforeach
                                            @endif
                                        </div>
                                    </div>
                                    <div class="flex-shrink-0 text-xs" style="color:var(--text-muted);">
                                        {{ $prop?->agent?->name ?? 'Unassigned' }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- RIGHT COLUMN (1/3) --}}
        <div class="space-y-6">

            {{-- MINI CALENDAR --}}
            <div class="corex-panel">
                <div class="corex-panel-header">
                    <h3 class="corex-panel-title">{{ $today->format('F Y') }}</h3>
                </div>
                <div class="corex-panel-body">
                    <div class="grid grid-cols-7 gap-0 text-center text-xs mb-1">
                        @foreach(['Mo','Tu','We','Th','Fr','Sa','Su'] as $day)
                            <div class="py-1 font-medium" style="color:var(--text-muted);">{{ $day }}</div>
                        @endforeach
                    </div>
                    <div class="grid grid-cols-7 gap-0 text-center text-xs">
                        {{-- Empty cells before first day --}}
                        @for($i = 1; $i < $firstDayOfWeek; $i++)
                            <div class="py-1.5"></div>
                        @endfor
                        @for($d = 1; $d <= $daysInMonth; $d++)
                            @php
                                $dateStr = $today->copy()->day($d)->toDateString();
                                $hasEvents = isset($monthEvents[$dateStr]) && count($monthEvents[$dateStr]) > 0;
                                $isToday = $d === $today->day;
                            @endphp
                            <a href="{{ route('command-center.calendar', ['year' => $today->year, 'month' => $today->month, 'view' => 'agenda']) }}"
                               class="relative py-1.5 rounded-md transition-colors {{ $isToday ? 'font-bold' : '' }}"
                               style="{{ $isToday ? 'background:var(--brand-button); color:#fff;' : 'color:var(--text-primary);' }}"
                               onmouseover="if(!{{ $isToday ? 'true' : 'false' }}) this.style.background='var(--surface-2)'"
                               onmouseout="if(!{{ $isToday ? 'true' : 'false' }}) this.style.background='transparent'">
                                {{ $d }}
                                @if($hasEvents)
                                    <span class="absolute bottom-0.5 left-1/2 -translate-x-1/2 w-1 h-1 rounded-full" style="background:{{ $isToday ? '#fff' : 'var(--brand-icon)' }};"></span>
                                @endif
                            </a>
                        @endfor
                    </div>
                </div>
            </div>

            {{-- AGENT SCORECARD --}}
            <div class="corex-panel">
                <div class="corex-panel-header">
                    <h3 class="corex-panel-title flex items-center gap-2">
                        <svg class="w-4 h-4" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
                        My Scorecard
                    </h3>
                    <span class="text-xs" style="color:var(--text-muted);">This Week</span>
                </div>
                <div class="corex-panel-body">
                    @if($scorecard)
                        {{-- Overall score --}}
                        <div class="text-center mb-4">
                            @php
                                $scoreColor = match(true) {
                                    $scorecard->overall_score >= 90 => '#10b981',
                                    $scorecard->overall_score >= 70 => '#3b82f6',
                                    $scorecard->overall_score >= 50 => '#f59e0b',
                                    default => '#ef4444',
                                };
                            @endphp
                            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full text-xl font-bold text-white" style="background:{{ $scoreColor }};">
                                {{ $scorecard->overall_score }}
                            </div>
                            <p class="text-xs mt-1.5" style="color:var(--text-muted);">Overall Score</p>
                        </div>

                        <div class="space-y-2.5 text-sm">
                            @php
                                $metrics = [
                                    ['label' => 'Tasks Completed', 'value' => $scorecard->tasks_completed . '/' . $scorecard->tasks_total, 'pct' => $scorecard->tasks_total > 0 ? round(($scorecard->tasks_completed / $scorecard->tasks_total) * 100) : 0],
                                    ['label' => 'Properties Attended', 'value' => $scorecard->properties_attended . '/' . $scorecard->properties_total, 'pct' => $scorecard->properties_total > 0 ? round(($scorecard->properties_attended / $scorecard->properties_total) * 100) : 0],
                                    ['label' => 'Events Completed', 'value' => $scorecard->events_completed . '/' . $scorecard->events_total, 'pct' => $scorecard->events_total > 0 ? round(($scorecard->events_completed / $scorecard->events_total) * 100) : 0],
                                    ['label' => 'Docs Uploaded', 'value' => $scorecard->documents_uploaded, 'pct' => null],
                                ];
                            @endphp
                            @foreach($metrics as $m)
                                <div>
                                    <div class="flex justify-between mb-0.5">
                                        <span style="color:var(--text-secondary);">{{ $m['label'] }}</span>
                                        <span class="font-medium" style="color:var(--text-primary);">{{ $m['value'] }}</span>
                                    </div>
                                    @if($m['pct'] !== null)
                                        <div class="w-full h-1.5 rounded-full" style="background:var(--surface-2);">
                                            <div class="h-1.5 rounded-full transition-all duration-300" style="width:{{ $m['pct'] }}%; background:var(--brand-icon);"></div>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        @if($scorecard->tasks_overdue > 0)
                            <div class="mt-3 px-3 py-2 rounded-md text-xs" style="background:rgba(239,68,68,0.08); color:#ef4444;">
                                {{ $scorecard->tasks_overdue }} overdue task(s)
                            </div>
                        @endif
                    @else
                        <div class="py-4 text-center">
                            <p class="text-sm" style="color:var(--text-muted);">Scorecard will be available once data accumulates</p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- QUICK ACTIONS --}}
            <div class="corex-panel">
                <div class="corex-panel-header">
                    <h3 class="corex-panel-title">Quick Actions</h3>
                </div>
                <div class="corex-panel-body space-y-2">
                    <a href="{{ route('agent.daily') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-md transition-colors"
                       style="color:var(--text-primary);"
                       onmouseover="this.style.background='var(--surface-2)'"
                       onmouseout="this.style.background='transparent'">
                        <svg class="w-4 h-4 flex-shrink-0" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
                        <span class="text-sm">Capture Daily Activity</span>
                    </a>
                    <button @click="showCreateTask = true"
                            class="flex items-center gap-3 px-3 py-2.5 rounded-md transition-colors w-full text-left"
                            style="color:var(--text-primary);"
                            onmouseover="this.style.background='var(--surface-2)'"
                            onmouseout="this.style.background='transparent'">
                        <svg class="w-4 h-4 flex-shrink-0" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        <span class="text-sm">Create Task</span>
                    </button>
                    <button @click="showCreateEvent = true"
                            class="flex items-center gap-3 px-3 py-2.5 rounded-md transition-colors w-full text-left"
                            style="color:var(--text-primary);"
                            onmouseover="this.style.background='var(--surface-2)'"
                            onmouseout="this.style.background='transparent'">
                        <svg class="w-4 h-4 flex-shrink-0" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5m-9-6h.008v.008H12v-.008ZM12 15h.008v.008H12V15Zm0 2.25h.008v.008H12v-.008Z" /></svg>
                        <span class="text-sm">Add Calendar Event</span>
                    </button>
                    <a href="{{ route('corex.properties.index') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-md transition-colors"
                       style="color:var(--text-primary);"
                       onmouseover="this.style.background='var(--surface-2)'"
                       onmouseout="this.style.background='transparent'">
                        <svg class="w-4 h-4 flex-shrink-0" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3H21m-3.75 3H21" /></svg>
                        <span class="text-sm">View Properties</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════
         CREATE TASK MODAL
    ═══════════════════════════════════════════════════════════════ --}}
    <div x-show="showCreateTask" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="background:rgba(0,0,0,0.5);"
         @keydown.escape.window="showCreateTask = false">
        <div class="w-full max-w-lg rounded-lg shadow-xl" style="background:var(--surface);"
             @click.outside="showCreateTask = false">
            <form method="POST" action="{{ route('command-center.tasks.store') }}">
                @csrf
                <div class="px-6 py-4 border-b" style="border-color:var(--border-default);">
                    <h3 class="text-lg font-semibold" style="color:var(--text-primary);">Create Task</h3>
                </div>
                <div class="px-6 py-4 space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary);">Title</label>
                        <input type="text" name="title" required
                               class="w-full px-3 py-2 rounded-md text-sm border"
                               style="background:var(--surface-2); border-color:var(--border-default); color:var(--text-primary);">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary);">Priority</label>
                            <select name="priority" class="w-full px-3 py-2 rounded-md text-sm border" style="background:var(--surface-2); border-color:var(--border-default); color:var(--text-primary);">
                                <option value="normal">Normal</option>
                                <option value="low">Low</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary);">Due Date</label>
                            <input type="date" name="due_date"
                                   class="w-full px-3 py-2 rounded-md text-sm border"
                                   style="background:var(--surface-2); border-color:var(--border-default); color:var(--text-primary);">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary);">Description</label>
                        <textarea name="description" rows="3"
                                  class="w-full px-3 py-2 rounded-md text-sm border"
                                  style="background:var(--surface-2); border-color:var(--border-default); color:var(--text-primary);"></textarea>
                    </div>
                    <div>
                        <label class="flex items-center gap-2 text-sm" style="color:var(--text-secondary);">
                            <input type="hidden" name="send_reminder" value="0">
                            <input type="checkbox" name="send_reminder" value="1" checked class="rounded">
                            Send me a reminder before this task is due
                        </label>
                    </div>
                </div>
                <div class="px-6 py-4 border-t flex justify-end gap-2" style="border-color:var(--border-default);">
                    <button type="button" @click="showCreateTask = false"
                            class="px-4 py-2 rounded-md text-sm font-medium"
                            style="background:var(--surface-2); color:var(--text-secondary);">
                        Cancel
                    </button>
                    <button type="submit"
                            class="px-4 py-2 rounded-md text-sm font-semibold text-white"
                            style="background:var(--brand-button);">
                        Create Task
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════
         OVERDUE REVIEW POPUP (shows on dashboard load if items exist)
    ═══════════════════════════════════════════════════════════════ --}}
    @if($overduePopupTasks->count() > 0 || $overduePopupEvents->count() > 0)
    <div x-show="showOverduePopup" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="background:rgba(0,0,0,0.6);"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
        <div class="w-full max-w-2xl rounded-lg shadow-2xl max-h-[80vh] flex flex-col" style="background:var(--surface);">
            {{-- Header --}}
            <div class="px-6 py-4 border-b flex items-center justify-between flex-shrink-0" style="border-color:var(--border-default);">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-md flex items-center justify-center" style="background:rgba(239,68,68,0.15);">
                        <svg class="w-5 h-5" style="color:#ef4444;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold" style="color:var(--text-primary);">Overdue Items Need Your Attention</h3>
                        <p class="text-xs" style="color:var(--text-muted);">{{ $overduePopupTasks->count() + $overduePopupEvents->count() }} item(s) past due — please review each one</p>
                    </div>
                </div>
                <button @click="showOverduePopup = false" class="p-1.5 rounded-md hover:bg-white/10 transition-colors" style="color:var(--text-muted);">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                </button>
            </div>

            {{-- Scrollable body --}}
            <div class="flex-1 overflow-y-auto px-6 py-4 space-y-3">

                {{-- Overdue Tasks --}}
                @foreach($overduePopupTasks as $task)
                <div class="p-4 rounded-md" style="background:var(--surface-2); border:1px solid var(--border-default);" x-data="{ action: null, extDays: 7 }">
                    <div class="flex items-start gap-3">
                        <div class="flex-shrink-0 mt-0.5">
                            <svg class="w-4 h-4" style="color:#f97316;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium" style="color:var(--text-primary);">{{ $task->title }}</p>
                            <div class="flex items-center gap-3 mt-1 text-xs" style="color:var(--text-muted);">
                                <span style="color:#ef4444;">Due {{ $task->due_date->diffForHumans() }}</span>
                                @if($task->property)
                                    <span>{{ $task->property->buildDisplayAddress() }}</span>
                                @endif
                            </div>

                            {{-- Action buttons --}}
                            <div class="flex flex-wrap items-center gap-2 mt-3" x-show="!action">
                                <form method="POST" action="{{ route('command-center.resolve-task', $task) }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="resolution" value="completed">
                                    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium text-white transition-colors" style="background:#10b981;">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                        Completed
                                    </button>
                                </form>
                                <button @click="action = 'extend'" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium transition-colors" style="background:rgba(59,130,246,0.15); color:#3b82f6;">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                    Extend Time
                                </button>
                                <form method="POST" action="{{ route('command-center.resolve-task', $task) }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="resolution" value="did_not_happen">
                                    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium transition-colors" style="background:rgba(107,114,128,0.15); color:#6b7280;">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                        Did Not Take Place
                                    </button>
                                </form>
                            </div>

                            {{-- Extend form --}}
                            <div x-show="action === 'extend'" x-cloak class="mt-3">
                                <form method="POST" action="{{ route('command-center.resolve-task', $task) }}" class="flex items-end gap-2">
                                    @csrf
                                    <input type="hidden" name="resolution" value="extended">
                                    <div>
                                        <label class="block text-xs mb-1" style="color:var(--text-muted);">Extend by (days)</label>
                                        <input type="number" name="extend_days" x-model="extDays" min="1" max="90"
                                               class="w-20 px-2 py-1.5 rounded-md text-xs border" style="background:var(--surface); border-color:var(--border-default); color:var(--text-primary);">
                                    </div>
                                    <button type="submit" class="px-3 py-1.5 rounded-md text-xs font-medium text-white" style="background:#3b82f6;">Reschedule</button>
                                    <button type="button" @click="action = null" class="px-3 py-1.5 rounded-md text-xs font-medium" style="background:var(--surface); color:var(--text-muted); border:1px solid var(--border-default);">Cancel</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                @endforeach

                {{-- Overdue Events --}}
                @foreach($overduePopupEvents as $event)
                <div class="p-4 rounded-md" style="background:var(--surface-2); border:1px solid var(--border-default);" x-data="{ action: null, extDays: 7 }">
                    <div class="flex items-start gap-3">
                        <div class="flex-shrink-0 mt-0.5">
                            <div class="w-4 h-4 rounded-full" style="background:{{ $event->colour }};"></div>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium" style="color:var(--text-primary);">{{ $event->title }}</p>
                            <div class="flex items-center gap-3 mt-1 text-xs" style="color:var(--text-muted);">
                                <span style="color:#ef4444;">Was {{ $event->event_date->diffForHumans() }}</span>
                                <span class="px-1.5 py-0.5 rounded" style="background:{{ $event->colour }}22; color:{{ $event->colour }};">{{ ucfirst($event->event_type) }}</span>
                                @if($event->property)
                                    <span>{{ $event->property->buildDisplayAddress() }}</span>
                                @endif
                            </div>

                            {{-- Action buttons --}}
                            <div class="flex flex-wrap items-center gap-2 mt-3" x-show="!action">
                                <form method="POST" action="{{ route('command-center.resolve-event', $event) }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="resolution" value="completed">
                                    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium text-white transition-colors" style="background:#10b981;">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                        Completed
                                    </button>
                                </form>
                                <button @click="action = 'extend'" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium transition-colors" style="background:rgba(59,130,246,0.15); color:#3b82f6;">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                    Reschedule
                                </button>
                                <form method="POST" action="{{ route('command-center.resolve-event', $event) }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="resolution" value="did_not_happen">
                                    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium transition-colors" style="background:rgba(107,114,128,0.15); color:#6b7280;">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                        Did Not Take Place
                                    </button>
                                </form>
                            </div>

                            {{-- Extend form --}}
                            <div x-show="action === 'extend'" x-cloak class="mt-3">
                                <form method="POST" action="{{ route('command-center.resolve-event', $event) }}" class="flex items-end gap-2">
                                    @csrf
                                    <input type="hidden" name="resolution" value="extended">
                                    <div>
                                        <label class="block text-xs mb-1" style="color:var(--text-muted);">Reschedule by (days)</label>
                                        <input type="number" name="extend_days" x-model="extDays" min="1" max="90"
                                               class="w-20 px-2 py-1.5 rounded-md text-xs border" style="background:var(--surface); border-color:var(--border-default); color:var(--text-primary);">
                                    </div>
                                    <button type="submit" class="px-3 py-1.5 rounded-md text-xs font-medium text-white" style="background:#3b82f6;">Reschedule</button>
                                    <button type="button" @click="action = null" class="px-3 py-1.5 rounded-md text-xs font-medium" style="background:var(--surface); color:var(--text-muted); border:1px solid var(--border-default);">Cancel</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                @endforeach

            </div>

            {{-- Footer --}}
            <div class="px-6 py-3 border-t flex items-center justify-between flex-shrink-0" style="border-color:var(--border-default);">
                <p class="text-xs" style="color:var(--text-muted);">Resolve items above or dismiss to review later</p>
                <button @click="showOverduePopup = false"
                        class="px-4 py-2 rounded-md text-sm font-medium"
                        style="background:var(--surface-2); color:var(--text-secondary);">
                    Review Later
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════════
         CREATE EVENT MODAL
    ═══════════════════════════════════════════════════════════════ --}}
    <div x-show="showCreateEvent" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="background:rgba(0,0,0,0.5);"
         @keydown.escape.window="showCreateEvent = false">
        <div class="w-full max-w-lg rounded-lg shadow-xl" style="background:var(--surface);"
             @click.outside="showCreateEvent = false">
            <form method="POST" action="{{ route('command-center.calendar.store') }}">
                @csrf
                <div class="px-6 py-4 border-b" style="border-color:var(--border-default);">
                    <h3 class="text-lg font-semibold" style="color:var(--text-primary);">Add Calendar Event</h3>
                </div>
                <div class="px-6 py-4 space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary);">Title</label>
                        <input type="text" name="title" required
                               class="w-full px-3 py-2 rounded-md text-sm border"
                               style="background:var(--surface-2); border-color:var(--border-default); color:var(--text-primary);">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary);">Date</label>
                            <input type="datetime-local" name="event_date" required
                                   class="w-full px-3 py-2 rounded-md text-sm border"
                                   style="background:var(--surface-2); border-color:var(--border-default); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary);">Priority</label>
                            <select name="priority" class="w-full px-3 py-2 rounded-md text-sm border" style="background:var(--surface-2); border-color:var(--border-default); color:var(--text-primary);">
                                <option value="normal">Normal</option>
                                <option value="low">Low</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary);">Description</label>
                        <textarea name="description" rows="3"
                                  class="w-full px-3 py-2 rounded-md text-sm border"
                                  style="background:var(--surface-2); border-color:var(--border-default); color:var(--text-primary);"></textarea>
                    </div>
                    <div>
                        <label class="flex items-center gap-2 text-sm" style="color:var(--text-secondary);">
                            <input type="hidden" name="send_reminder" value="0">
                            <input type="checkbox" name="send_reminder" value="1" checked class="rounded">
                            Send me a reminder before this event
                        </label>
                    </div>
                </div>
                <div class="px-6 py-4 border-t flex justify-end gap-2" style="border-color:var(--border-default);">
                    <button type="button" @click="showCreateEvent = false"
                            class="px-4 py-2 rounded-md text-sm font-medium"
                            style="background:var(--surface-2); color:var(--text-secondary);">
                        Cancel
                    </button>
                    <button type="submit"
                            class="px-4 py-2 rounded-md text-sm font-semibold text-white"
                            style="background:var(--brand-button);">
                        Add Event
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>

<script>
function commandCenter() {
    return {
        showCreateTask: false,
        showCreateEvent: false,
        showOverduePopup: {{ ($overduePopupTasks->count() + $overduePopupEvents->count()) > 0 ? 'true' : 'false' }},
    };
}
</script>
@endsection
