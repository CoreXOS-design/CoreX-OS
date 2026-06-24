@extends('layouts.corex')

@section('corex-content')
@php
    // Shared chip colour lookup for pillar tags — token-based via color-mix
    $pillarStyle = [
        'property' => ['var' => '--ds-amber',  'label' => 'Property'],
        'deal'     => ['var' => '--ds-navy',   'label' => 'Deal'],
        'contact'  => ['var' => '--brand-icon','label' => 'Contact'],
    ];

    // Flatten all tasks once and bucket by due date for list view + at-risk strip
    $allTasks = collect($columns)->flatten(1);

    $bucketOf = function ($task) {
        if (!$task->due_date) return 'none';
        if ($task->isOverdue())             return 'overdue';
        if ($task->due_date->isToday())     return 'today';
        if ($task->due_date->isTomorrow())  return 'tomorrow';
        if ($task->due_date->between(now()->startOfWeek(), now()->endOfWeek()))
                                            return 'week';
        return 'later';
    };

    $buckets = [
        'overdue'  => ['label' => 'Overdue',     'var' => '--ds-crimson', 'tasks' => collect(), 'defaultCollapsed' => false],
        'today'    => ['label' => 'Today',       'var' => '--ds-amber',   'tasks' => collect(), 'defaultCollapsed' => false],
        'tomorrow' => ['label' => 'Tomorrow',    'var' => '--ds-navy',    'tasks' => collect(), 'defaultCollapsed' => false],
        'week'     => ['label' => 'This Week',   'var' => '--brand-icon', 'tasks' => collect(), 'defaultCollapsed' => false],
        'later'    => ['label' => 'Later',       'var' => '--text-muted', 'tasks' => collect(), 'defaultCollapsed' => true],
        'none'     => ['label' => 'No Due Date', 'var' => '--text-muted', 'tasks' => collect(), 'defaultCollapsed' => true],
    ];

    foreach ($allTasks as $t) {
        $b = $bucketOf($t);
        $buckets[$b]['tasks']->push($t);
    }
    foreach ($buckets as $k => &$bk) {
        $bk['tasks'] = $bk['tasks']->sortBy(fn($t) => $t->due_date?->timestamp ?? PHP_INT_MAX);
    }
    unset($bk);

    // At-risk strip: overdue + today, open only, sorted by due
    $atRisk = $buckets['overdue']['tasks']->merge($buckets['today']['tasks'])
        ->filter(fn($t) => !in_array($t->status, ['done','dismissed']))
        ->sortBy(fn($t) => $t->due_date?->timestamp ?? PHP_INT_MAX)
        ->values();

    $statusColumns = [
        'todo'        => ['label' => 'To Do',       'var' => '--text-muted'],
        'in_progress' => ['label' => 'In Progress', 'var' => '--ds-navy'],
        'awaiting'    => ['label' => 'Awaiting',    'var' => '--ds-amber'],
        'done'        => ['label' => 'Done',        'var' => '--ds-green'],
    ];

    $statusBadgeVariant = [
        'todo'        => 'ds-badge-default',
        'in_progress' => 'ds-badge-info',
        'awaiting'    => 'ds-badge-warning',
        'done'        => 'ds-badge-success',
        'dismissed'   => 'ds-badge-default',
    ];
@endphp

<div class="space-y-4" x-data="taskBoard()" x-init="init()">

    {{-- ══════ PAGE HEADER (Pattern A — branded) ══════ --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div data-tour="task-intro">
                <h1 class="text-xl font-bold text-white leading-tight">Tasks</h1>
                <div class="flex items-center gap-3 mt-1 text-sm flex-wrap" style="color: rgba(255,255,255,0.65);">
                    <span>{{ number_format($summary['open']) }} open</span>
                    @if($summary['overdue'] > 0)
                        <span class="inline-flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full" style="background: var(--ds-crimson);"></span>
                            {{ number_format($summary['overdue']) }} overdue
                        </span>
                    @endif
                    <span>{{ number_format($summary['today']) }} today</span>
                    <span>{{ number_format($summary['thisWeek']) }} this week</span>
                </div>
            </div>

            <div class="flex items-center gap-2 flex-wrap">
                @include('layouts.partials.tour-header-launcher')
                {{-- View mode: Board / List --}}
                <div class="inline-flex rounded-md overflow-hidden" data-tour="task-view" style="background: rgba(255,255,255,0.12);">
                    @foreach(['kanban' => 'Board', 'list' => 'List'] as $vKey => $vLabel)
                        <a href="{{ route('command-center.tasks', array_merge(request()->query(), ['view' => $vKey])) }}"
                           class="px-3 py-1.5 text-xs font-semibold transition-colors"
                           style="{{ $currentView === $vKey ? 'background: rgba(255,255,255,0.2); color: #fff;' : 'color: rgba(255,255,255,0.75);' }}">
                            {{ $vLabel }}
                        </a>
                    @endforeach
                </div>

<a href="{{ route('command-center.tasks.archived') }}" class="corex-btn-outline">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" /></svg>
                    Archived
                </a>

                <form method="POST" action="{{ route('command-center.tasks.archive-done') }}"
                      onsubmit="return confirm('Archive all Done tasks? They can be restored from the Archived view.');"
                      class="inline">
                    @csrf
                    <button type="submit" class="corex-btn-outline">
                        Clear Done
                    </button>
                </form>

                <button @click="showCreateTask = true" class="corex-btn-primary" data-tour="task-add">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    New Task
                </button>
            </div>
        </div>
    </div>

    {{-- ══════ AT-RISK STRIP (Overdue + Today, pinned) — Alert block pattern ══════ --}}
    @if($atRisk->isNotEmpty())
        <div class="rounded-md px-4 py-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);"
             x-show="!atRiskCollapsed" x-transition>
            <div class="flex items-center justify-between mb-2">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 flex-shrink-0" style="color: var(--ds-crimson);" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                    <span class="text-sm font-semibold" style="color: var(--ds-crimson);">At Risk</span>
                    <span class="ds-badge ds-badge-danger">{{ number_format($atRisk->count()) }}</span>
                </div>
                <button @click="atRiskCollapsed = true" type="button"
                        class="text-xs font-semibold transition-colors"
                        style="color: var(--ds-crimson);" title="Hide">Hide</button>
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach($atRisk as $task)
                    @php
                        $tag = $task->pillarTag();
                        $taskLink = $task->property ? route('corex.properties.show', $task->property)
                                  : ($task->contact  ? route('corex.contacts.show',  $task->contact)
                                  : ($task->deal_id  ? route('deals-v2.show',        $task->deal_id) : null));
                    @endphp
                    <a @if($taskLink) href="{{ $taskLink }}" @endif
                       class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md text-xs transition-colors"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary); white-space: nowrap;">
                        <span class="w-1.5 h-1.5 rounded-full flex-shrink-0"
                              style="background: {{ $task->isOverdue() ? 'var(--ds-crimson)' : 'var(--ds-amber)' }};"></span>
                        @if($tag && isset($pillarStyle[$tag]))
                            <span class="text-[0.6875rem] font-bold uppercase tracking-wider"
                                  style="color: var({{ $pillarStyle[$tag]['var'] }});">{{ $pillarStyle[$tag]['label'] }}</span>
                        @endif
                        <span>{{ \Illuminate\Support\Str::limit($task->title, 40) }}</span>
                        @if($task->due_date)
                            <span style="color: {{ $task->isOverdue() ? 'var(--ds-crimson)' : 'var(--text-muted)' }};">
                                · {{ $task->isOverdue() ? $task->due_date->diffForHumans(['short' => true]) : 'Today' }}
                            </span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
        <div x-show="atRiskCollapsed" x-transition>
            <button @click="atRiskCollapsed = false" type="button"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-colors"
                    style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); color: var(--ds-crimson);">
                Show At Risk ({{ number_format($atRisk->count()) }})
            </button>
        </div>
    @endif

    {{-- ══════ FILTER BAR ══════ --}}
    <div class="rounded-md p-3 flex flex-wrap items-center gap-2"
         style="background: var(--surface); border: 1px solid var(--border);">
        {{-- Search --}}
        <div class="relative">
            <input type="text" x-model="search" placeholder="Search tasks…"
                   class="pl-8 pr-3 py-1.5 rounded-md text-xs w-56"
                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            <svg class="w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2" style="color: var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
        </div>

        {{-- Due chips --}}
        <template x-for="f in [
            { key: 'overdue',  label: 'Overdue',   var: '--ds-crimson' },
            { key: 'today',    label: 'Today',     var: '--ds-amber' },
            { key: 'week',     label: 'This Week', var: '--brand-icon' },
        ]" :key="f.key">
            <button type="button" @click="toggleFilter(f.key)"
                    class="px-2.5 py-1 rounded-md text-xs font-semibold border transition-colors"
                    :style="filters[f.key]
                        ? `background: color-mix(in srgb, var(${f.var}) 12%, transparent); border-color: color-mix(in srgb, var(${f.var}) 40%, transparent); color: var(${f.var});`
                        : 'background: var(--surface-2); border-color: var(--border); color: var(--text-secondary);'">
                <span x-text="f.label"></span>
            </button>
        </template>

        <span class="w-px h-4" style="background: var(--border);"></span>

        {{-- Priority chips --}}
        <button type="button" @click="toggleFilter('critical')"
                class="px-2.5 py-1 rounded-md text-xs font-semibold border transition-colors"
                :style="filters.critical
                    ? 'background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); border-color: color-mix(in srgb, var(--ds-crimson) 40%, transparent); color: var(--ds-crimson);'
                    : 'background: var(--surface-2); border-color: var(--border); color: var(--text-secondary);'">
            Critical
        </button>
        <button type="button" @click="toggleFilter('high')"
                class="px-2.5 py-1 rounded-md text-xs font-semibold border transition-colors"
                :style="filters.high
                    ? 'background: color-mix(in srgb, var(--ds-amber) 12%, transparent); border-color: color-mix(in srgb, var(--ds-amber) 40%, transparent); color: var(--ds-amber);'
                    : 'background: var(--surface-2); border-color: var(--border); color: var(--text-secondary);'">
            High+
        </button>

        <span class="w-px h-4" style="background: var(--border);"></span>

        {{-- Pillar chips --}}
        @foreach($pillarStyle as $pk => $ps)
            <button type="button" @click="togglePillar('{{ $pk }}')"
                    class="px-2.5 py-1 rounded-md text-xs font-semibold border transition-colors"
                    :style="pillars.includes('{{ $pk }}')
                        ? 'background: color-mix(in srgb, var({{ $ps['var'] }}) 12%, transparent); border-color: color-mix(in srgb, var({{ $ps['var'] }}) 40%, transparent); color: var({{ $ps['var'] }});'
                        : 'background: var(--surface-2); border-color: var(--border); color: var(--text-secondary);'">
                {{ $ps['label'] }}
            </button>
        @endforeach

        {{-- Reset --}}
        <button type="button" @click="resetFilters()" x-show="activeFilterCount > 0"
                class="px-2.5 py-1 rounded-md text-xs font-semibold transition-colors"
                style="color: var(--text-muted);">
            Clear filters
        </button>
    </div>

    @if($currentView === 'kanban')
        {{-- ══════ KANBAN BOARD ══════ --}}
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
            @foreach($statusColumns as $statusKey => $statusMeta)
                @php
                    $colTasks    = $columns[$statusKey] ?? collect();
                    $statusLabel = $statusMeta['label'];
                    $colVar      = $statusMeta['var'];
                @endphp
                <div class="flex flex-col rounded-md overflow-hidden" data-kanban-column="{{ $statusKey }}"
                     style="background: var(--surface); border: 1px solid var(--border);"
                     x-show="!emptyColumnHidden('{{ $statusKey }}')">

                    {{-- Header (click to toggle collapse) --}}
                    <button type="button"
                            @click="toggleColumn('{{ $statusKey }}')"
                            class="flex items-center justify-between w-full px-3 py-2 text-left transition-colors"
                            style="background: color-mix(in srgb, var({{ $colVar }}) 10%, transparent); border-bottom: 1px solid var(--border);">
                        <div class="flex items-center gap-2">
                            <svg class="w-3 h-3 transition-transform"
                                 :class="colCollapsed['{{ $statusKey }}'] ? '-rotate-90' : ''"
                                 style="color: var({{ $colVar }});"
                                 fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                            <span class="w-2 h-2 rounded-full" style="background: var({{ $colVar }});"></span>
                            <span class="text-xs font-semibold uppercase tracking-wider" style="color: var(--text-primary);">{{ $statusLabel }}</span>
                        </div>
                        <span class="inline-flex items-center justify-center rounded-full text-[0.6875rem] font-semibold px-2 py-0.5"
                              style="background: var(--surface-2); color: var(--text-secondary); min-width: 1.5rem;"
                              data-col-count>{{ number_format($colTasks->count()) }}</span>
                    </button>

                    {{-- Column body (scrollable, capped height) --}}
                    <div class="flex-1 space-y-2 p-2 overflow-y-auto"
                         data-drop-zone="{{ $statusKey }}"
                         x-show="!colCollapsed['{{ $statusKey }}']" x-transition
                         style="background: var(--surface-2); max-height: calc(100vh - 320px); min-height: 8rem;">
                        @forelse($colTasks as $task)
                            @include('command-center.partials.task-card', ['task' => $task, 'compact' => true, 'showPillar' => true, 'pillarStyle' => $pillarStyle, 'statusKey' => $statusKey])
                        @empty
                            <div class="py-6 text-center text-xs" data-empty-placeholder style="color: var(--text-muted);">No tasks</div>
                        @endforelse

                        {{-- Show more / show less --}}
                        <button type="button" data-col-more
                                @click="showMoreColumn('{{ $statusKey }}')"
                                x-show="false"
                                class="w-full text-xs py-2 rounded-md font-semibold transition-colors"
                                style="background: var(--surface); color: var(--text-secondary); border: 1px dashed var(--border);">
                            Show more
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        {{-- ══════ LIST VIEW — grouped by smart date buckets ══════ --}}
        <div class="space-y-3">
            @foreach($buckets as $bKey => $bucket)
                @if($bucket['tasks']->isNotEmpty())
                    <div class="rounded-md overflow-hidden" data-list-group="{{ $bKey }}"
                         style="background: var(--surface); border: 1px solid var(--border);">
                        {{-- Group header --}}
                        <button type="button"
                                @click="toggleBucket('{{ $bKey }}')"
                                class="flex items-center justify-between w-full px-4 py-2.5 text-left transition-colors"
                                style="background: color-mix(in srgb, var({{ $bucket['var'] }}) 10%, transparent); border-bottom: 1px solid var(--border);">
                            <div class="flex items-center gap-2">
                                <svg class="w-3 h-3 transition-transform"
                                     :class="bucketCollapsed['{{ $bKey }}'] ? '-rotate-90' : ''"
                                     style="color: var({{ $bucket['var'] }});"
                                     fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                </svg>
                                <span class="w-2 h-2 rounded-full" style="background: var({{ $bucket['var'] }});"></span>
                                <span class="text-xs font-semibold uppercase tracking-wider" style="color: var(--text-primary);">{{ $bucket['label'] }}</span>
                                <span class="inline-flex items-center justify-center rounded-full text-[0.6875rem] font-semibold px-2 py-0.5"
                                      data-group-count
                                      style="background: color-mix(in srgb, var({{ $bucket['var'] }}) 18%, transparent); color: var({{ $bucket['var'] }}); min-width: 1.5rem;">{{ number_format($bucket['tasks']->count()) }}</span>
                            </div>
                        </button>

                        {{-- Group body --}}
                        <div x-show="!bucketCollapsed['{{ $bKey }}']" x-transition>
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm ds-table">
                                    <tbody>
                                        @foreach($bucket['tasks'] as $task)
                                            @php
                                                $taskLink = $task->property ? route('corex.properties.show', $task->property)
                                                          : ($task->contact  ? route('corex.contacts.show',  $task->contact)
                                                          : ($task->deal_id  ? route('deals-v2.show',        $task->deal_id) : null));
                                                $tag = $task->pillarTag();
                                                $searchHay = strtolower(trim(
                                                    ($task->title ?? '') . ' ' .
                                                    ($task->property?->buildDisplayAddress() ?? '') . ' ' .
                                                    ($task->contact ? ($task->contact->first_name . ' ' . $task->contact->last_name) : '')
                                                ));
                                                $statusVariant = $statusBadgeVariant[$task->status] ?? 'ds-badge-default';
                                            @endphp
                                            <tr class="transition-colors cursor-pointer hover:bg-black/5"
                                                data-task-row
                                                data-task-clickable
                                                data-task-id="{{ $task->id }}"
                                                data-task-title="{{ $task->title }}"
                                                data-task-due="{{ $task->due_date?->format('d M Y') }}"
                                                data-status="{{ $task->status }}"
                                                data-priority="{{ $task->priority }}"
                                                data-pillar="{{ $tag ?? '' }}"
                                                data-bucket="{{ $bKey }}"
                                                data-title="{{ $searchHay }}"
                                                style="border-top: 1px solid var(--border);">
                                                <td class="px-4 py-3" style="color: var(--text-primary);">
                                                    <div class="flex items-center gap-2 flex-wrap">
                                                        @if($tag && isset($pillarStyle[$tag]))
                                                            <span class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[0.6875rem] font-bold uppercase tracking-wider flex-shrink-0"
                                                                  style="background: color-mix(in srgb, var({{ $pillarStyle[$tag]['var'] }}) 15%, transparent); color: var({{ $pillarStyle[$tag]['var'] }}); white-space: nowrap;">
                                                                {{ $pillarStyle[$tag]['label'] }}
                                                            </span>
                                                        @endif
                                                        @if($task->priority === 'critical')
                                                            <span class="ds-badge ds-badge-danger">Critical</span>
                                                        @elseif($task->priority === 'high')
                                                            <span class="ds-badge ds-badge-warning">High</span>
                                                        @endif
                                                        @if($taskLink)
                                                            <a href="{{ $taskLink }}" @click.stop class="text-sm hover:underline {{ $task->status === 'done' ? 'line-through opacity-60' : '' }}">{{ $task->title }}</a>
                                                        @else
                                                            <span class="text-sm {{ $task->status === 'done' ? 'line-through opacity-60' : '' }}">{{ $task->title }}</span>
                                                        @endif
                                                    </div>
                                                    @if($task->property || $task->contact)
                                                        <div class="text-xs mt-1 truncate" style="color: var(--text-muted);">
                                                            @if($task->property){{ $task->property->buildDisplayAddress() }}
                                                            @elseif($task->contact){{ $task->contact->first_name }} {{ $task->contact->last_name }}
                                                            @endif
                                                        </div>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-3 w-32">
                                                    <span class="ds-badge {{ $statusVariant }}">
                                                        {{ str_replace('_', ' ', ucfirst($task->status)) }}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 text-xs w-28" style="color: {{ $task->isOverdue() ? 'var(--ds-crimson)' : 'var(--text-muted)' }};">
                                                    {{ $task->due_date ? $task->due_date->format('d M Y') : '—' }}
                                                </td>
                                                <td class="px-4 py-3 w-24 text-right">
                                                    @if($task->status !== 'done')
                                                        <form method="POST" action="{{ route('command-center.tasks.complete', $task) }}" @click.stop class="inline">
                                                            @csrf
                                                            <button type="submit" class="text-xs font-semibold transition-colors"
                                                                    style="color: var(--brand-icon);">Mark Done</button>
                                                        </form>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                @endif
            @endforeach

            @if($allTasks->isEmpty())
                {{-- Empty state (§3.10) --}}
                <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                         style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2Z" />
                        </svg>
                    </div>
                    <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No tasks yet</h3>
                    <p class="text-sm mb-4" style="color: var(--text-muted);">Create a task to start tracking your follow-ups, document checks and deal actions.</p>
                    <button type="button" @click="showCreateTask = true" class="corex-btn-primary">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        New Task
                    </button>
                </div>
            @endif
        </div>
    @endif

    {{-- ══════ TASK DETAIL MODAL (notes + checklist) ══════ --}}
    <div x-show="detail.open" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="background: rgba(0,0,0,0.5);"
         @keydown.escape.window="closeDetail()">
        <div class="w-full max-w-2xl rounded-md overflow-hidden flex flex-col"
             style="background: var(--surface); border: 1px solid var(--border); max-height: 90vh;"
             @click.outside="closeDetail()">

            {{-- Header --}}
            <div class="px-6 py-4 flex items-start justify-between gap-3" style="border-bottom: 1px solid var(--border);">
                <div class="min-w-0">
                    <div class="text-[0.6875rem] font-bold uppercase tracking-wider mb-1" style="color: var(--text-muted);">
                        Task <span x-text="detail.task.id ? '#' + detail.task.id : ''"></span>
                    </div>
                    <h3 class="text-lg font-semibold" style="color: var(--text-primary);" x-text="detail.task.title"></h3>
                    <div class="text-xs mt-1" style="color: var(--text-muted);" x-show="detail.task.due_date">
                        Due <span x-text="detail.task.due_date"></span>
                    </div>
                </div>
                <button type="button" @click="closeDetail()" class="rounded-md p-1" style="color: var(--text-muted);">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            {{-- Body --}}
            <div class="flex-1 overflow-y-auto px-6 py-5 space-y-6">

                {{-- Checklist --}}
                <section>
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="text-xs font-bold uppercase tracking-wider" style="color: var(--text-secondary);">Checklist</h4>
                        <span class="text-xs" style="color: var(--text-muted);"
                              x-text="checklistProgress"></span>
                    </div>
                    <div class="space-y-1.5">
                        <template x-for="item in detail.checklist" :key="item.id">
                            <div class="flex items-center gap-2 group">
                                <input type="checkbox" :checked="item.done"
                                       @change="toggleChecklistItem(item)"
                                       class="rounded">
                                <span class="flex-1 text-sm"
                                      :style="item.done ? 'text-decoration: line-through; color: var(--text-muted);' : 'color: var(--text-primary);'"
                                      x-text="item.text"></span>
                                <button type="button" @click="deleteChecklistItem(item)"
                                        class="opacity-0 group-hover:opacity-100 text-xs"
                                        style="color: var(--ds-crimson);">Remove</button>
                            </div>
                        </template>
                        <div x-show="!detail.checklist.length" class="text-xs italic" style="color: var(--text-muted);">No checklist items yet.</div>
                    </div>
                    <form @submit.prevent="addChecklistItem()" class="flex gap-2 mt-3">
                        <input type="text" x-model="detail.newChecklist" placeholder="Add a checklist item…"
                               class="flex-1 rounded-md px-3 py-1.5 text-sm"
                               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        <button type="submit" class="corex-btn-outline" :disabled="!detail.newChecklist.trim()">Add</button>
                    </form>
                </section>

                {{-- Notes (threaded) --}}
                <section>
                    <h4 class="text-xs font-bold uppercase tracking-wider mb-2" style="color: var(--text-secondary);">Notes</h4>

                    <form @submit.prevent="addNote()" class="mb-3">
                        <textarea x-model="detail.newNote" rows="2" placeholder="Add a note…"
                                  class="w-full rounded-md px-3 py-2 text-sm"
                                  style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"></textarea>
                        <div class="flex justify-end mt-2">
                            <button type="submit" class="corex-btn-primary" :disabled="!detail.newNote.trim() || detail.savingNote">
                                <span x-text="detail.savingNote ? 'Saving…' : 'Add Note'"></span>
                            </button>
                        </div>
                    </form>

                    <div class="space-y-2">
                        <template x-for="note in detail.notes" :key="note.id">
                            <div class="rounded-md p-3" style="background: var(--surface-2); border: 1px solid var(--border);">
                                <div class="flex items-center justify-between mb-1">
                                    <div class="text-xs font-semibold" style="color: var(--text-primary);" x-text="note.user_name || 'You'"></div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-[0.6875rem]" style="color: var(--text-muted);" x-text="formatDate(note.created_at)"></span>
                                        <button type="button" @click="deleteNote(note)"
                                                x-show="note.user_id === currentUserId"
                                                class="text-[0.6875rem]" style="color: var(--ds-crimson);">Delete</button>
                                    </div>
                                </div>
                                <div class="text-sm whitespace-pre-wrap" style="color: var(--text-primary);" x-text="note.body"></div>
                            </div>
                        </template>
                        <div x-show="!detail.notes.length && !detail.loading" class="text-xs italic" style="color: var(--text-muted);">No notes yet.</div>
                        <div x-show="detail.loading" class="text-xs" style="color: var(--text-muted);">Loading…</div>
                    </div>
                </section>
            </div>
        </div>
    </div>

    {{-- CREATE TASK MODAL --}}
    <div x-show="showCreateTask" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="background: rgba(0,0,0,0.5);"
         @keydown.escape.window="showCreateTask = false">
        <div class="w-full max-w-lg rounded-md overflow-hidden"
             style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 10px 30px rgba(0,0,0,0.18);"
             @click.outside="showCreateTask = false">
            <form method="POST" action="{{ route('command-center.tasks.store') }}">
                @csrf
                <div class="px-6 py-4 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
                    <h3 class="text-lg font-semibold" style="color: var(--text-primary);">Create Task</h3>
                    <button type="button" @click="showCreateTask = false"
                            class="rounded-md p-1 transition-colors"
                            style="color: var(--text-muted);" aria-label="Close">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label for="task-title" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Title <span class="text-red-500">*</span></label>
                        <input id="task-title" type="text" name="title" required
                               class="w-full rounded-md px-3 py-2 text-sm transition-colors"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="task-priority" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Priority</label>
                            <select id="task-priority" name="priority"
                                    class="w-full rounded-md px-3 py-2 text-sm"
                                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                <option value="normal">Normal</option>
                                <option value="low">Low</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>
                        <div>
                            <label for="task-due" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Due Date</label>
                            <input id="task-due" type="date" name="due_date"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                    </div>
                    <div>
                        <label for="task-type" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Type</label>
                        <select id="task-type" name="task_type"
                                class="w-full rounded-md px-3 py-2 text-sm"
                                style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                            <option value="custom">General</option>
                            <option value="document_upload">Document Upload</option>
                            <option value="follow_up">Follow-up</option>
                            <option value="compliance">Compliance</option>
                            <option value="review">Review</option>
                            <option value="deal_action">Deal Action</option>
                        </select>
                    </div>
                    <div>
                        <label for="task-desc" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Description</label>
                        <textarea id="task-desc" name="description" rows="4"
                                  class="w-full rounded-md px-3 py-2 text-sm"
                                  style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"></textarea>
                    </div>
                    <div>
                        <label class="flex items-center gap-2 text-sm" style="color: var(--text-secondary);">
                            <input type="hidden" name="send_reminder" value="0">
                            <input type="checkbox" name="send_reminder" value="1" checked class="rounded">
                            Send me a reminder before this task is due
                        </label>
                    </div>
                </div>
                <div class="px-6 py-4 flex justify-end gap-2" style="border-top: 1px solid var(--border); background: var(--surface-2);">
                    <button type="button" @click="showCreateTask = false" class="corex-btn-outline">Cancel</button>
                    <button type="submit" class="corex-btn-primary">Create Task</button>
                </div>
            </form>
        </div>
    </div>

</div>

<script>
function taskBoard() {
    const LS = {
        density: 'hfc_tasks_density',
        col:     'hfc_tasks_col_collapsed',
        bucket:  'hfc_tasks_bucket_collapsed',
        atRisk:  'hfc_tasks_atrisk_collapsed',
    };
    const readJSON = (k, fb) => {
        try { return JSON.parse(localStorage.getItem(k)) ?? fb; } catch (e) { return fb; }
    };

    return {
        showCreateTask: false,
        currentUserId: {{ auth()->id() ?? 'null' }},
        detail: {
            open: false,
            loading: false,
            savingNote: false,
            task: { id: null, title: '', due_date: null },
            notes: [],
            checklist: [],
            newNote: '',
            newChecklist: '',
        },
        search: '',
        filters: { overdue: false, today: false, week: false, critical: false, high: false },
        pillars: [],
        density: localStorage.getItem(LS.density) || 'comfortable',
        colCollapsed: readJSON(LS.col, { todo: false, in_progress: false, awaiting: false, done: true }),
        bucketCollapsed: readJSON(LS.bucket, { overdue: false, today: false, tomorrow: false, week: false, later: true, none: true }),
        atRiskCollapsed: localStorage.getItem(LS.atRisk) === '1',
        colShowAll: {},
        limitPerColumn: 10,

        get activeFilterCount() {
            return Object.values(this.filters).filter(Boolean).length + this.pillars.length + (this.search ? 1 : 0);
        },

        init() {
            this.$watch('density',         v => localStorage.setItem(LS.density, v));
            this.$watch('colCollapsed',    v => localStorage.setItem(LS.col, JSON.stringify(v)), { deep: true });
            this.$watch('bucketCollapsed', v => localStorage.setItem(LS.bucket, JSON.stringify(v)), { deep: true });
            this.$watch('atRiskCollapsed', v => localStorage.setItem(LS.atRisk, v ? '1' : '0'));

            ['search', 'filters', 'pillars', 'colShowAll'].forEach(k => {
                this.$watch(k, () => this.applyFilters(), { deep: true });
            });
            this.$nextTick(() => {
                this.applyFilters();
                this.initDragAndDrop();
                this.initTaskClicks();
            });
        },

        initTaskClicks() {
            document.addEventListener('click', (e) => {
                if (e.target.closest('a, button, form, input, textarea, select, [data-task-drag-handle]')) return;
                const el = e.target.closest('[data-task-clickable]');
                if (!el) return;
                this.openDetail({
                    id: el.dataset.taskId,
                    title: el.dataset.taskTitle || '',
                    due_date: el.dataset.taskDue || null,
                });
            });
        },

        async moveTask(card, newStatus) {
            if (!card) return;
            const taskId    = card.dataset.taskId;
            const oldStatus = card.dataset.status;
            if (newStatus === oldStatus) return;

            const targetZone = document.querySelector(`[data-drop-zone="${newStatus}"]`);
            if (targetZone) {
                const placeholder = targetZone.querySelector('[data-empty-placeholder]');
                targetZone.insertBefore(card, placeholder);
            }
            card.dataset.status = newStatus;

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            try {
                const res = await fetch(`/corex/command-center/tasks/${taskId}/status`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({ status: newStatus }),
                });
                if (!res.ok) throw new Error('Status update failed');
                this.applyFilters();
            } catch (err) {
                window.location.reload();
            }
        },

        initDragAndDrop() {
            let draggingCard = null;

            document.querySelectorAll('[data-task-drag-handle]').forEach(handle => {
                const card = handle.closest('[data-task-card]');
                if (!card) return;
                handle.addEventListener('dragstart', (e) => {
                    draggingCard = card;
                    card.classList.add('opacity-50');
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', card.dataset.taskId);
                });
                handle.addEventListener('dragend', () => {
                    card.classList.remove('opacity-50');
                    draggingCard = null;
                    document.querySelectorAll('[data-drop-zone]').forEach(z => z.classList.remove('ring-2', 'ring-blue-400'));
                });
            });

            document.querySelectorAll('[data-drop-zone]').forEach(zone => {
                zone.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    zone.classList.add('ring-2', 'ring-blue-400');
                });
                zone.addEventListener('dragleave', (e) => {
                    if (!zone.contains(e.relatedTarget)) {
                        zone.classList.remove('ring-2', 'ring-blue-400');
                    }
                });
                zone.addEventListener('drop', (e) => {
                    e.preventDefault();
                    zone.classList.remove('ring-2', 'ring-blue-400');
                    this.moveTask(draggingCard, zone.dataset.dropZone);
                });
            });

            document.querySelectorAll('[data-quick-move]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    const card = btn.closest('[data-task-card]');
                    this.moveTask(card, btn.dataset.quickMove);
                });
            });
        },

        toggleFilter(k) { this.filters[k] = !this.filters[k]; },
        togglePillar(p) {
            const i = this.pillars.indexOf(p);
            if (i === -1) this.pillars.push(p); else this.pillars.splice(i, 1);
        },
        toggleColumn(s) { this.colCollapsed = { ...this.colCollapsed, [s]: !this.colCollapsed[s] }; },
        toggleBucket(b) { this.bucketCollapsed = { ...this.bucketCollapsed, [b]: !this.bucketCollapsed[b] }; },
        showMoreColumn(s) { this.colShowAll = { ...this.colShowAll, [s]: !this.colShowAll[s] }; },

        get checklistProgress() {
            const items = this.detail.checklist || [];
            if (!items.length) return '';
            const done = items.filter(i => i.done).length;
            return `${done} / ${items.length}`;
        },

        formatDate(iso) {
            if (!iso) return '';
            try { return new Date(iso).toLocaleString(); } catch (e) { return iso; }
        },

        async _api(method, url, body) {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const opts = {
                method,
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            };
            if (body !== undefined) {
                opts.headers['Content-Type'] = 'application/json';
                opts.body = JSON.stringify(body);
            }
            const res = await fetch(url, opts);
            if (!res.ok) throw new Error(`${method} ${url} → ${res.status}`);
            return res.status === 204 ? null : res.json();
        },

        async openDetail(task) {
            this.detail.task = task;
            this.detail.open = true;
            this.detail.loading = true;
            this.detail.notes = [];
            this.detail.checklist = [];
            this.detail.newNote = '';
            this.detail.newChecklist = '';
            try {
                const base = `/api/v1/command-center/tasks/${task.id}`;
                const [notesRes, checklistRes] = await Promise.all([
                    this._api('GET', `${base}/notes`),
                    this._api('GET', `${base}/checklist`),
                ]);
                this.detail.notes = notesRes.notes || [];
                this.detail.checklist = checklistRes.items || [];
            } catch (e) {
                console.error(e);
            } finally {
                this.detail.loading = false;
            }
        },

        closeDetail() { this.detail.open = false; },

        async addNote() {
            const body = this.detail.newNote.trim();
            if (!body) return;
            this.detail.savingNote = true;
            try {
                const note = await this._api('POST', `/api/v1/command-center/tasks/${this.detail.task.id}/notes`, { body });
                this.detail.notes.unshift(note);
                this.detail.newNote = '';
            } catch (e) { console.error(e); }
            finally { this.detail.savingNote = false; }
        },

        async deleteNote(note) {
            if (!confirm('Delete this note?')) return;
            try {
                await this._api('DELETE', `/api/v1/command-center/tasks/${this.detail.task.id}/notes/${note.id}`);
                this.detail.notes = this.detail.notes.filter(n => n.id !== note.id);
            } catch (e) { console.error(e); }
        },

        async addChecklistItem() {
            const text = this.detail.newChecklist.trim();
            if (!text) return;
            try {
                const item = await this._api('POST', `/api/v1/command-center/tasks/${this.detail.task.id}/checklist`, { text });
                this.detail.checklist.push(item);
                this.detail.newChecklist = '';
            } catch (e) { console.error(e); }
        },

        async toggleChecklistItem(item) {
            const next = !item.done;
            try {
                const updated = await this._api('PATCH', `/api/v1/command-center/tasks/${this.detail.task.id}/checklist/${item.id}`, { done: next });
                Object.assign(item, updated);
            } catch (e) { console.error(e); }
        },

        async deleteChecklistItem(item) {
            try {
                await this._api('DELETE', `/api/v1/command-center/tasks/${this.detail.task.id}/checklist/${item.id}`);
                this.detail.checklist = this.detail.checklist.filter(i => i.id !== item.id);
            } catch (e) { console.error(e); }
        },

        resetFilters() {
            this.search = '';
            this.filters = { overdue: false, today: false, week: false, critical: false, high: false };
            this.pillars = [];
            this.colShowAll = {};
        },

        emptyColumnHidden(status) {
            if (window.innerWidth >= 768) return false;
            const col = document.querySelector(`[data-kanban-column="${status}"]`);
            if (!col) return false;
            return (col.dataset.visibleCount || '0') === '0';
        },

        matches(el) {
            const title    = el.dataset.title || '';
            const pillar   = el.dataset.pillar || '';
            const priority = el.dataset.priority || '';
            const bucket   = el.dataset.bucket || '';

            if (this.search && !title.includes(this.search.toLowerCase().trim())) return false;
            if (this.filters.overdue  && bucket !== 'overdue') return false;
            if (this.filters.today    && bucket !== 'today')   return false;
            if (this.filters.week     && !['overdue','today','tomorrow','week'].includes(bucket)) return false;
            if (this.filters.critical && priority !== 'critical') return false;
            if (this.filters.high     && !['critical','high'].includes(priority)) return false;
            if (this.pillars.length   && !this.pillars.includes(pillar)) return false;
            return true;
        },

        applyFilters() {
            document.querySelectorAll('[data-kanban-column]').forEach(col => {
                const status = col.dataset.kanbanColumn;
                const cards  = Array.from(col.querySelectorAll('[data-task-card]'));
                const matching = cards.filter(c => this.matches(c));
                cards.forEach(c => { c.style.display = 'none'; });

                const limit = this.colShowAll[status] ? matching.length : this.limitPerColumn;
                matching.forEach((c, i) => { c.style.display = i < limit ? '' : 'none'; });

                const badge = col.querySelector('[data-col-count]');
                if (badge) badge.textContent = matching.length.toLocaleString();

                const moreBtn = col.querySelector('[data-col-more]');
                if (moreBtn) {
                    const hidden = matching.length - limit;
                    if (hidden > 0 && !this.colShowAll[status]) {
                        moreBtn.textContent = `Show ${hidden} more`;
                        moreBtn.style.display = '';
                    } else if (this.colShowAll[status] && matching.length > this.limitPerColumn) {
                        moreBtn.textContent = 'Show less';
                        moreBtn.style.display = '';
                    } else {
                        moreBtn.style.display = 'none';
                    }
                }

                const empty = col.querySelector('[data-empty-placeholder]');
                if (empty) empty.style.display = matching.length === 0 ? '' : 'none';

                col.dataset.visibleCount = matching.length;
            });

            document.querySelectorAll('[data-list-group]').forEach(grp => {
                const rows = Array.from(grp.querySelectorAll('[data-task-row]'));
                let visible = 0;
                rows.forEach(r => {
                    const ok = this.matches(r);
                    r.style.display = ok ? '' : 'none';
                    if (ok) visible++;
                });
                const badge = grp.querySelector('[data-group-count]');
                if (badge) badge.textContent = visible.toLocaleString();
                grp.style.display = visible > 0 ? '' : 'none';
            });
        },
    };
}
</script>
@endsection
