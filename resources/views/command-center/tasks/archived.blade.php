@extends('layouts.corex')

@section('corex-content')
@php
    $pillarStyle = [
        'property' => ['bg' => 'rgba(249,115,22,0.15)',  'fg' => '#f97316', 'label' => 'Property'],
        'deal'     => ['bg' => 'rgba(59,130,246,0.15)',  'fg' => '#3b82f6', 'label' => 'Deal'],
        'contact'  => ['bg' => 'rgba(139,92,246,0.15)',  'fg' => '#8b5cf6', 'label' => 'Contact'],
    ];
@endphp

<div class="space-y-4">

    <div class="flex items-center justify-between gap-2">
        <div>
            <h1 class="text-xl font-bold" style="color:var(--text-primary);">Archived Tasks</h1>
            <p class="text-xs mt-0.5" style="color:var(--text-muted);">
                {{ $total }} archived task(s), grouped by day archived. Restore moves a task back to Done.
            </p>
        </div>
        <a href="{{ route('command-center.tasks') }}"
           class="inline-flex items-center gap-1 px-3 py-1.5 rounded-md text-xs font-medium"
           style="background:var(--surface-2); color:var(--text-secondary);">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
            Back to Board
        </a>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-2 text-sm" style="background:#f0fdf4; border:1px solid #bbf7d0; color:#166534;">
            {{ session('success') }}
        </div>
    @endif

    @if($total === 0)
        <div class="corex-panel">
            <div class="corex-panel-body py-12 text-center">
                <svg class="w-12 h-12 mx-auto mb-3 opacity-40" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" style="color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5-1.5 11.645a1.125 1.125 0 0 1-.964.544H4.214a1.125 1.125 0 0 1-.965-.544L1.5 7.5m18.75 0h-18m18.75 0A1.125 1.125 0 0 0 22.5 6.375v-1.5A1.125 1.125 0 0 0 21.375 3.75H2.625A1.125 1.125 0 0 0 1.5 4.875v1.5A1.125 1.125 0 0 0 2.625 7.5m0 0 .75 12.75A1.125 1.125 0 0 0 4.5 21.375h15a1.125 1.125 0 0 0 1.125-1.125l.75-12.75" /></svg>
                <p class="text-sm" style="color:var(--text-primary);">Nothing archived yet.</p>
                <p class="text-xs mt-1" style="color:var(--text-muted);">Completed tasks you archive from the board will show here, grouped by date.</p>
            </div>
        </div>
    @else
        <div class="space-y-4">
            @foreach($grouped as $date => $dayTasks)
                @php
                    $d = $date ? \Carbon\Carbon::parse($date) : null;
                    $label = $d
                        ? ($d->isToday() ? 'Today · ' . $d->format('d M Y')
                          : ($d->isYesterday() ? 'Yesterday · ' . $d->format('d M Y')
                          : $d->format('l · d M Y')))
                        : 'Unknown date';
                @endphp
                <div class="corex-panel">
                    <div class="corex-panel-header">
                        <h3 class="corex-panel-title text-sm">{{ $label }}</h3>
                        <span class="text-xs" style="color:var(--text-muted);">{{ $dayTasks->count() }} task(s)</span>
                    </div>
                    <div class="corex-panel-body p-0">
                        <div class="divide-y" style="border-color:var(--border-default);">
                            @foreach($dayTasks as $task)
                                @php
                                    $tag = $task->pillarTag();
                                    $taskLink = $task->property ? route('corex.properties.show', $task->property)
                                              : ($task->contact  ? route('corex.contacts.show',  $task->contact)
                                              : ($task->deal_id  ? route('deals-v2.show',        $task->deal_id) : null));
                                @endphp
                                <div class="flex items-center gap-3 px-3 py-2">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            @if($tag && isset($pillarStyle[$tag]))
                                                <span class="text-[9px] font-bold uppercase px-1 py-px rounded"
                                                      style="background:{{ $pillarStyle[$tag]['bg'] }}; color:{{ $pillarStyle[$tag]['fg'] }}; letter-spacing:0.5px;">
                                                    {{ $pillarStyle[$tag]['label'] }}
                                                </span>
                                            @endif
                                            @if($taskLink)
                                                <a href="{{ $taskLink }}" class="text-sm font-medium hover:underline line-through opacity-70" style="color:var(--text-primary);">{{ $task->title }}</a>
                                            @else
                                                <span class="text-sm font-medium line-through opacity-70" style="color:var(--text-primary);">{{ $task->title }}</span>
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-3 mt-0.5 text-[11px]" style="color:var(--text-muted);">
                                            @if($task->property)
                                                <span>{{ $task->property->buildDisplayAddress() }}</span>
                                            @elseif($task->contact)
                                                <span>{{ $task->contact->first_name }} {{ $task->contact->last_name }}</span>
                                            @endif
                                            @if($task->completed_at)
                                                <span>Completed {{ $task->completed_at->format('d M H:i') }}</span>
                                            @endif
                                            <span>Archived {{ $task->deleted_at?->format('H:i') }}</span>
                                        </div>
                                    </div>
                                    <form method="POST" action="{{ route('command-center.tasks.restore', $task->id) }}">
                                        @csrf
                                        <button type="submit"
                                                class="text-xs px-2.5 py-1 rounded-md font-medium"
                                                style="background:var(--surface-2); color:var(--text-secondary);">
                                            Restore
                                        </button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
