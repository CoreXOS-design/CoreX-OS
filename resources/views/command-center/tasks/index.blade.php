@extends('layouts.corex')

@section('corex-content')
@php
    // Shared chip colour lookup for pillar tags
    $pillarStyle = [
        'property' => ['bg' => 'rgba(249,115,22,0.15)',  'fg' => '#f97316', 'label' => 'Property'],
        'deal'     => ['bg' => 'rgba(59,130,246,0.15)',  'fg' => '#3b82f6', 'label' => 'Deal'],
        'contact'  => ['bg' => 'rgba(139,92,246,0.15)',  'fg' => '#8b5cf6', 'label' => 'Contact'],
    ];
@endphp

<div class="space-y-3" x-data="taskBoard()">

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
        <div>
            <h1 class="text-xl font-bold" style="color:var(--text-primary);">Tasks</h1>
            <div class="flex items-center gap-3 mt-0.5 text-xs" style="color:var(--text-muted);">
                <span>{{ $summary['open'] }} open</span>
                @if($summary['overdue'] > 0)
                    <span style="color:#ef4444;">{{ $summary['overdue'] }} overdue</span>
                @endif
                <span>{{ $summary['today'] }} today</span>
                <span>{{ $summary['thisWeek'] }} this week</span>
            </div>
        </div>

        <div class="flex items-center gap-1.5 flex-wrap">
            {{-- View mode: Board / List --}}
            <div class="inline-flex rounded-md overflow-hidden" style="background:var(--surface-2);">
                @foreach(['kanban' => 'Board', 'list' => 'List'] as $vKey => $vLabel)
                    <a href="{{ route('command-center.tasks', array_merge(request()->query(), ['view' => $vKey])) }}"
                       class="px-2.5 py-1 text-xs font-medium"
                       style="{{ $currentView === $vKey ? 'background:var(--brand-button); color:#fff;' : 'color:var(--text-secondary);' }}">
                        {{ $vLabel }}
                    </a>
                @endforeach
            </div>

            {{-- Group by (kanban only) --}}
            @if($currentView === 'kanban')
                <div class="inline-flex rounded-md overflow-hidden" style="background:var(--surface-2);">
                    @php $group = request('group', 'status'); @endphp
                    @foreach(['status' => 'Status', 'pillar' => 'Pillar'] as $gKey => $gLabel)
                        <a href="{{ route('command-center.tasks', array_merge(request()->query(), ['group' => $gKey])) }}"
                           class="px-2.5 py-1 text-xs font-medium"
                           style="{{ $group === $gKey ? 'background:var(--brand-button); color:#fff;' : 'color:var(--text-secondary);' }}">
                            {{ $gLabel }}
                        </a>
                    @endforeach
                </div>
            @endif

            <a href="{{ route('command-center.tasks.archived') }}"
               class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium"
               style="background:var(--surface-2); color:var(--text-secondary);">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5-1.5 11.645a1.125 1.125 0 0 1-.964.544H4.214a1.125 1.125 0 0 1-.965-.544L1.5 7.5m18.75 0h-18m18.75 0A1.125 1.125 0 0 0 22.5 6.375v-1.5A1.125 1.125 0 0 0 21.375 3.75H2.625A1.125 1.125 0 0 0 1.5 4.875v1.5A1.125 1.125 0 0 0 2.625 7.5m0 0 .75 12.75A1.125 1.125 0 0 0 4.5 21.375h15a1.125 1.125 0 0 0 1.125-1.125l.75-12.75" /></svg>
                Archived
            </a>

            <form method="POST" action="{{ route('command-center.tasks.archive-done') }}"
                  onsubmit="return confirm('Archive all Done tasks? They can be restored from the Archived view.');"
                  class="inline">
                @csrf
                <button type="submit"
                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium"
                        style="background:var(--surface-2); color:var(--text-secondary);">
                    Clear Done
                </button>
            </form>

            <button @click="showCreateTask = true"
                    class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-semibold text-white"
                    style="background:var(--brand-button);">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                New Task
            </button>
        </div>
    </div>

    @if($currentView === 'kanban')
        @php
            $group  = request('group', 'status');
            $allOpen = collect($columns)->flatten(1);
        @endphp

        @if($group === 'pillar')
            {{-- ══════ GROUPED BY PILLAR ══════ --}}
            @php
                $pillarGroups = [
                    'property' => $allOpen->filter(fn($t) => $t->pillarTag() === 'property')->values(),
                    'deal'     => $allOpen->filter(fn($t) => $t->pillarTag() === 'deal')->values(),
                    'contact'  => $allOpen->filter(fn($t) => $t->pillarTag() === 'contact')->values(),
                    'other'    => $allOpen->filter(fn($t) => $t->pillarTag() === null)->values(),
                ];
            @endphp
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
                @foreach($pillarGroups as $pKey => $pTasks)
                    @php
                        $pStyle = $pillarStyle[$pKey] ?? ['bg' => 'var(--surface-2)', 'fg' => 'var(--text-muted)', 'label' => 'Other'];
                    @endphp
                    <div class="flex flex-col">
                        <div class="flex items-center justify-between px-2.5 py-1.5 rounded-t-md" style="background:{{ $pStyle['bg'] }};">
                            <div class="flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full" style="background:{{ $pStyle['fg'] }};"></span>
                                <span class="text-xs font-semibold" style="color:var(--text-primary);">{{ $pStyle['label'] }}</span>
                            </div>
                            <span class="text-[10px] px-1.5 py-0.5 rounded-full" style="background:var(--surface-2); color:var(--text-muted);">{{ $pTasks->count() }}</span>
                        </div>
                        <div class="flex-1 space-y-1.5 p-1.5 rounded-b-md min-h-[8rem]" style="background:var(--surface-2); border:1px solid var(--border-default); border-top:none;">
                            @forelse($pTasks as $task)
                                @include('command-center.partials.task-card', ['task' => $task, 'compact' => true, 'showPillar' => false, 'pillarStyle' => $pillarStyle, 'statusKey' => $task->status])
                            @empty
                                <div class="py-4 text-center text-[11px]" style="color:var(--text-muted);">No tasks</div>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            {{-- ══════ KANBAN BOARD (BY STATUS) — COMPACT ══════ --}}
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
                @foreach(['todo' => 'To Do', 'in_progress' => 'In Progress', 'awaiting' => 'Awaiting', 'done' => 'Done'] as $statusKey => $statusLabel)
                    @php
                        $colTasks = $columns[$statusKey] ?? collect();
                        $headerColors = [
                            'todo' => '#6b7280',
                            'in_progress' => '#3b82f6',
                            'awaiting' => '#f59e0b',
                            'done' => '#10b981',
                        ];
                    @endphp
                    <div class="flex flex-col">
                        <div class="flex items-center justify-between px-2.5 py-1.5 rounded-t-md" style="background:{{ $headerColors[$statusKey] }}15;">
                            <div class="flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full" style="background:{{ $headerColors[$statusKey] }};"></span>
                                <span class="text-xs font-semibold" style="color:var(--text-primary);">{{ $statusLabel }}</span>
                            </div>
                            <span class="text-[10px] px-1.5 py-0.5 rounded-full" style="background:var(--surface-2); color:var(--text-muted);">{{ $colTasks->count() }}</span>
                        </div>
                        <div class="flex-1 space-y-1.5 p-1.5 rounded-b-md min-h-[8rem]" style="background:var(--surface-2); border:1px solid var(--border-default); border-top:none;">
                            @forelse($colTasks as $task)
                                @include('command-center.partials.task-card', ['task' => $task, 'compact' => true, 'showPillar' => true, 'pillarStyle' => $pillarStyle, 'statusKey' => $statusKey])
                            @empty
                                <div class="py-4 text-center text-[11px]" style="color:var(--text-muted);">No tasks</div>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @else
        {{-- ══════ LIST VIEW ══════ --}}
        <div class="corex-panel">
            <div class="corex-panel-body overflow-x-auto p-0">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b" style="border-color:var(--border-default);">
                            <th class="text-left py-2 px-3 font-medium text-xs" style="color:var(--text-muted);">Task</th>
                            <th class="text-left py-2 px-3 font-medium text-xs" style="color:var(--text-muted);">Pillar</th>
                            <th class="text-left py-2 px-3 font-medium text-xs" style="color:var(--text-muted);">Status</th>
                            <th class="text-left py-2 px-3 font-medium text-xs" style="color:var(--text-muted);">Priority</th>
                            <th class="text-left py-2 px-3 font-medium text-xs" style="color:var(--text-muted);">Due</th>
                            <th class="text-left py-2 px-3 font-medium text-xs" style="color:var(--text-muted);">Property / Contact</th>
                            <th class="py-2 px-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $allTasks = collect($columns)->flatten(1)->sortBy('due_date'); @endphp
                        @forelse($allTasks as $task)
                            @php
                                $statusColors = [
                                    'todo' => '#6b7280', 'in_progress' => '#3b82f6',
                                    'awaiting' => '#f59e0b', 'done' => '#10b981', 'dismissed' => '#9ca3af',
                                ];
                                $taskLink = $task->property ? route('corex.properties.show', $task->property)
                                          : ($task->contact  ? route('corex.contacts.show',  $task->contact)
                                          : ($task->deal_id  ? route('deals-v2.show',        $task->deal_id) : null));
                                $tag = $task->pillarTag();
                            @endphp
                            <tr class="border-b hover:bg-white/5 transition-colors" style="border-color:var(--border-default);">
                                <td class="py-2 px-3" style="color:var(--text-primary);">
                                    @if($taskLink)
                                        <a href="{{ $taskLink }}" class="text-sm hover:underline {{ $task->status === 'done' ? 'line-through opacity-60' : '' }}">{{ $task->title }}</a>
                                    @else
                                        <span class="text-sm {{ $task->status === 'done' ? 'line-through opacity-60' : '' }}">{{ $task->title }}</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3">
                                    @if($tag)
                                        <span class="text-[10px] font-semibold uppercase px-1.5 py-0.5 rounded" style="background:{{ $pillarStyle[$tag]['bg'] }}; color:{{ $pillarStyle[$tag]['fg'] }};">{{ $pillarStyle[$tag]['label'] }}</span>
                                    @else
                                        <span class="text-[10px]" style="color:var(--text-muted);">—</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3">
                                    <span class="text-[11px] px-1.5 py-0.5 rounded-full font-medium" style="background:{{ $statusColors[$task->status] ?? '#6b7280' }}20; color:{{ $statusColors[$task->status] ?? '#6b7280' }};">
                                        {{ str_replace('_', ' ', ucfirst($task->status)) }}
                                    </span>
                                </td>
                                <td class="py-2 px-3">
                                    @if($task->priority === 'critical')
                                        <span class="text-xs font-medium" style="color:#ef4444;">Critical</span>
                                    @elseif($task->priority === 'high')
                                        <span class="text-xs font-medium" style="color:#f59e0b;">High</span>
                                    @else
                                        <span class="text-xs" style="color:var(--text-muted);">{{ ucfirst($task->priority) }}</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3 text-xs" style="color:{{ $task->isOverdue() ? '#ef4444' : 'var(--text-muted)' }};">
                                    {{ $task->due_date ? $task->due_date->format('d M Y') : '—' }}
                                </td>
                                <td class="py-2 px-3 text-xs truncate max-w-[12rem]" style="color:var(--text-muted);">
                                    @if($task->property)
                                        <a href="{{ route('corex.properties.show', $task->property) }}" class="hover:underline">{{ $task->property->buildDisplayAddress() }}</a>
                                    @elseif($task->contact)
                                        <a href="{{ route('corex.contacts.show', $task->contact) }}" class="hover:underline">{{ $task->contact->first_name }} {{ $task->contact->last_name }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="py-2 px-3">
                                    @if($task->status !== 'done')
                                        <form method="POST" action="{{ route('command-center.tasks.complete', $task) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-xs px-2 py-1 rounded-md font-medium" style="background:var(--surface-2); color:var(--text-secondary);">Done</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-8 text-center text-sm" style="color:var(--text-muted);">No tasks</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- CREATE TASK MODAL --}}
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
                        <input type="text" name="title" required class="w-full px-3 py-2 rounded-md text-sm border" style="background:var(--surface-2); border-color:var(--border-default); color:var(--text-primary);">
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
                            <input type="date" name="due_date" class="w-full px-3 py-2 rounded-md text-sm border" style="background:var(--surface-2); border-color:var(--border-default); color:var(--text-primary);">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary);">Type</label>
                        <select name="task_type" class="w-full px-3 py-2 rounded-md text-sm border" style="background:var(--surface-2); border-color:var(--border-default); color:var(--text-primary);">
                            <option value="custom">General</option>
                            <option value="document_upload">Document Upload</option>
                            <option value="follow_up">Follow-up</option>
                            <option value="compliance">Compliance</option>
                            <option value="review">Review</option>
                            <option value="deal_action">Deal Action</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary);">Description</label>
                        <textarea name="description" rows="3" class="w-full px-3 py-2 rounded-md text-sm border" style="background:var(--surface-2); border-color:var(--border-default); color:var(--text-primary);"></textarea>
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
                    <button type="button" @click="showCreateTask = false" class="px-4 py-2 rounded-md text-sm font-medium" style="background:var(--surface-2); color:var(--text-secondary);">Cancel</button>
                    <button type="submit" class="px-4 py-2 rounded-md text-sm font-semibold text-white" style="background:var(--brand-button);">Create Task</button>
                </div>
            </form>
        </div>
    </div>

</div>

<script>
function taskBoard() {
    return {
        showCreateTask: false,
    };
}
</script>
@endsection
