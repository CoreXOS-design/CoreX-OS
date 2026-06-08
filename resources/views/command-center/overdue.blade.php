@extends('layouts.corex')

@section('corex-content')
@php $total = $tasks->count() + $events->count(); @endphp
<div class="space-y-4">
    <div class="flex items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <a href="{{ route('corex.dashboard') }}" class="inline-flex items-center gap-1.5 text-sm no-underline" style="color:var(--text-secondary);">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
                Today
            </a>
            <h1 class="text-xl font-bold" style="color:var(--text-primary);">Overdue &amp; Unresolved</h1>
            @if($total > 0)
            <span class="text-xs px-2 py-0.5 rounded-md font-semibold tabular-nums"
                  style="background: color-mix(in srgb, var(--ds-crimson) 14%, transparent); color: var(--ds-crimson);">{{ $total }}</span>
            @endif
        </div>
    </div>

    @if($total === 0)
        <div class="rounded-md p-8 text-center" style="background:var(--surface);border:1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--ds-green, #059669) 12%, transparent); color: var(--ds-green, #059669);">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">Nothing overdue</h3>
            <p class="text-sm" style="color:var(--text-muted);">You're all caught up — no unresolved items.</p>
        </div>
    @else
        {{-- Overdue tasks --}}
        @foreach($tasks as $task)
            @php $daysOverdue = $task->due_date ? (int) $task->due_date->diffInDays(now()) : 0; @endphp
            <div class="rounded-md px-4 py-3 flex items-start gap-3" style="background:var(--surface);border:1px solid var(--border);border-left:3px solid var(--ds-crimson);">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-[0.625rem] uppercase tracking-wider font-semibold px-1.5 py-0.5 rounded"
                              style="background:var(--surface-2);color:var(--text-muted);">Task</span>
                        <span class="text-sm font-medium" style="color:var(--text-primary);">{{ $task->title }}</span>
                    </div>
                    <div class="text-xs mt-1" style="color:var(--text-secondary);">
                        @if($task->contact){{ $task->contact->full_name }} · @endif
                        @if($task->property){{ $task->property->title ?? $task->property->address }} · @endif
                        Due {{ $task->due_date?->format('d M Y') }}
                        <span style="color:var(--ds-crimson);">· {{ $daysOverdue }} day{{ $daysOverdue === 1 ? '' : 's' }} overdue</span>
                    </div>
                </div>
                <div class="flex items-center gap-1.5 flex-shrink-0">
                    <form method="POST" action="{{ route('command-center.resolve-task', $task) }}">
                        @csrf
                        <input type="hidden" name="resolution" value="completed">
                        <button type="submit" class="text-xs px-2.5 py-1 rounded-md font-semibold text-white" style="background:var(--ds-green, #059669);">Mark done</button>
                    </form>
                    <form method="POST" action="{{ route('command-center.resolve-task', $task) }}">
                        @csrf
                        <input type="hidden" name="resolution" value="did_not_happen">
                        <button type="submit" class="text-xs px-2.5 py-1 rounded-md font-semibold" style="background:var(--surface-2);color:var(--text-muted);border:1px solid var(--border);">Didn't happen</button>
                    </form>
                </div>
            </div>
        @endforeach

        {{-- Overdue events --}}
        @foreach($events as $event)
            @php $daysOverdue = $event->event_date ? (int) $event->event_date->diffInDays(now()) : 0; @endphp
            <div class="rounded-md px-4 py-3 flex items-start gap-3" style="background:var(--surface);border:1px solid var(--border);border-left:3px solid var(--ds-crimson);">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-[0.625rem] uppercase tracking-wider font-semibold px-1.5 py-0.5 rounded"
                              style="background:var(--surface-2);color:var(--text-muted);">Event</span>
                        <span class="text-sm font-medium" style="color:var(--text-primary);">{{ $event->title }}</span>
                    </div>
                    <div class="text-xs mt-1" style="color:var(--text-secondary);">
                        @if($event->contact){{ $event->contact->full_name }} · @endif
                        @if($event->property){{ $event->property->title ?? $event->property->address }} · @endif
                        {{ $event->event_date?->format('d M Y, H:i') }}
                        <span style="color:var(--ds-crimson);">· {{ $daysOverdue }} day{{ $daysOverdue === 1 ? '' : 's' }} overdue</span>
                    </div>
                </div>
                <div class="flex items-center gap-1.5 flex-shrink-0">
                    <form method="POST" action="{{ route('command-center.resolve-event', $event) }}">
                        @csrf
                        <input type="hidden" name="resolution" value="completed">
                        <button type="submit" class="text-xs px-2.5 py-1 rounded-md font-semibold text-white" style="background:var(--ds-green, #059669);">Mark done</button>
                    </form>
                    <form method="POST" action="{{ route('command-center.resolve-event', $event) }}">
                        @csrf
                        <input type="hidden" name="resolution" value="did_not_happen">
                        <button type="submit" class="text-xs px-2.5 py-1 rounded-md font-semibold" style="background:var(--surface-2);color:var(--text-muted);border:1px solid var(--border);">Didn't happen</button>
                    </form>
                </div>
            </div>
        @endforeach
    @endif
</div>
@endsection
