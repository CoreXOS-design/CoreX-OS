@php
    /** @var \App\Models\CommandCenter\CommandTask $task */
    /** @var bool $showPillar */
    /** @var array $pillarStyle */
    /** @var string $statusKey */
    $tag = $task->pillarTag();
    $taskLink = $task->property ? route('corex.properties.show', $task->property)
              : ($task->contact  ? route('corex.contacts.show',  $task->contact)
              : ($task->deal_id  ? route('deals-v2.show',        $task->deal_id) : null));
    $nextStatus = match($statusKey) {
        'todo'        => 'in_progress',
        'in_progress' => 'awaiting',
        'awaiting'    => 'done',
        default       => null,
    };
@endphp
<div class="rounded-md transition-shadow hover:shadow-sm p-2" style="background:var(--surface); border:1px solid var(--border-default);">
    {{-- Row 1: tag + priority + title --}}
    <div class="flex items-center gap-1.5 flex-wrap">
        @if(($showPillar ?? true) && $tag && isset($pillarStyle[$tag]))
            <span class="text-[9px] font-bold uppercase px-1 py-px rounded"
                  style="background:{{ $pillarStyle[$tag]['bg'] }}; color:{{ $pillarStyle[$tag]['fg'] }}; letter-spacing:0.5px;">
                {{ $pillarStyle[$tag]['label'] }}
            </span>
        @endif
        @if($task->priority === 'critical')
            <span class="text-[9px] font-bold uppercase px-1 py-px rounded" style="background:rgba(239,68,68,0.15); color:#ef4444; letter-spacing:0.5px;">Crit</span>
        @elseif($task->priority === 'high')
            <span class="text-[9px] font-bold uppercase px-1 py-px rounded" style="background:rgba(245,158,11,0.15); color:#f59e0b; letter-spacing:0.5px;">High</span>
        @endif
    </div>

    {{-- Row 2: title (link if pillar available) --}}
    @if($taskLink)
        <a href="{{ $taskLink }}" class="block text-[13px] font-medium leading-snug mt-1 hover:underline {{ $task->status === 'done' ? 'line-through opacity-70' : '' }}" style="color:var(--text-primary);">
            {{ $task->title }}
        </a>
    @else
        <p class="text-[13px] font-medium leading-snug mt-1 {{ $task->status === 'done' ? 'line-through opacity-70' : '' }}" style="color:var(--text-primary);">{{ $task->title }}</p>
    @endif

    {{-- Row 3: linked entity text (small) --}}
    @if($task->property)
        <a href="{{ route('corex.properties.show', $task->property) }}" class="block text-[11px] truncate mt-0.5 hover:underline" style="color:var(--text-muted);">
            {{ $task->property->buildDisplayAddress() ?: ($task->property->title ?: '') }}
        </a>
    @elseif($task->contact)
        <a href="{{ route('corex.contacts.show', $task->contact) }}" class="block text-[11px] truncate mt-0.5 hover:underline" style="color:var(--text-muted);">
            {{ $task->contact->first_name }} {{ $task->contact->last_name }}
        </a>
    @endif

    {{-- Row 4: due + action buttons --}}
    <div class="flex items-center justify-between mt-1.5">
        @if($task->due_date)
            <span class="text-[10px]" style="color:{{ $task->isOverdue() ? '#ef4444' : 'var(--text-muted)' }};">
                {{ $task->due_date->isToday() ? 'Today' : ($task->due_date->isTomorrow() ? 'Tomorrow' : $task->due_date->format('d M')) }}
            </span>
        @else
            <span></span>
        @endif

        <div class="flex items-center gap-0.5">
            @if($statusKey !== 'done' && $nextStatus)
                <form method="POST" action="{{ route('command-center.tasks.update-status', $task) }}">
                    @csrf @method('PATCH')
                    <input type="hidden" name="status" value="{{ $nextStatus }}">
                    <button type="submit" class="p-1 rounded hover:bg-white/10" title="Move to {{ str_replace('_',' ',$nextStatus) }}">
                        <svg class="w-3 h-3" style="color:var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
                    </button>
                </form>
                <form method="POST" action="{{ route('command-center.tasks.complete', $task) }}">
                    @csrf
                    <button type="submit" class="p-1 rounded hover:bg-green-500/10" title="Complete">
                        <svg class="w-3 h-3 text-green-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                    </button>
                </form>
            @endif
        </div>
    </div>
</div>
