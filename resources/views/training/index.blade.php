@extends('layouts.corex')

@section('corex-content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    <div style="background:var(--brand-default, #0b2a4a); border-radius:6px; padding:20px 24px;">
        <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">My Training</h2>
        <div style="font-size:0.875rem; color:rgba(255,255,255,0.55);">Complete your required courses to stay compliant.</div>
    </div>

    @php $userId = auth()->id(); @endphp

    @if($courses->isEmpty())
        <div class="rounded-md p-8 text-center" style="background:var(--surface); border:1px solid var(--border);">
            <div class="text-sm" style="color:var(--text-secondary);">No training courses available yet.</div>
        </div>
    @else
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach($courses as $course)
        @php
            $pct = $course->completionPercentForUser($userId);
            $isCompleted = $course->isCompletedByUser($userId);
            $completion = $isCompleted ? $course->completionForUser($userId) : null;
            $isExpiring = $completion && $completion->expires_at && $completion->expires_at->lte(now()->addDays(30)) && $completion->expires_at->gt(now());
            $isExpired = $completion && $completion->expires_at && $completion->expires_at->lte(now());
            $catColor = \App\Models\TrainingCourse::CATEGORY_COLORS[$course->category] ?? ['bg' => 'rgba(148,163,184,0.12)', 'color' => '#94a3b8'];
        @endphp
        <a href="{{ route('training.show', $course) }}" class="block no-underline rounded-lg transition-colors hover:opacity-95"
           style="background:var(--surface); border:1px solid var(--border);">
            <div class="p-5">
                <div class="flex items-start justify-between gap-2 mb-2">
                    <h3 class="text-sm font-bold" style="color:var(--text-primary);">{{ $course->title }}</h3>
                    <div class="flex items-center gap-1.5 flex-shrink-0">
                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold" style="background:{{ $catColor['bg'] }}; color:{{ $catColor['color'] }};">
                            {{ $course->category_label }}
                        </span>
                        @if($course->is_required)
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold" style="background:rgba(239,68,68,0.12); color:#ef4444;">Required</span>
                        @endif
                    </div>
                </div>

                @if($course->description)
                <p class="text-xs mb-3 line-clamp-2" style="color:var(--text-secondary);">{{ $course->description }}</p>
                @endif

                {{-- Progress bar --}}
                <div class="flex items-center gap-2 mb-2">
                    <div class="flex-1 h-2 rounded-full overflow-hidden" style="background:var(--border);">
                        <div class="h-full rounded-full transition-all" style="width:{{ $pct }}%; background:{{ $isCompleted ? '#22c55e' : '#0ea5e9' }};"></div>
                    </div>
                    <span class="text-xs font-bold" style="color:var(--text-primary);">{{ $pct }}%</span>
                </div>

                <div class="flex items-center justify-between">
                    <div class="text-xs" style="color:var(--text-muted);">
                        {{ $course->lessons_count }} lesson{{ $course->lessons_count !== 1 ? 's' : '' }}
                        &middot; {{ $course->totalDurationMinutes() }} min
                    </div>
                    <div class="flex items-center gap-1.5">
                        @if($isExpired)
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold" style="background:rgba(239,68,68,0.12); color:#ef4444;">Expired</span>
                        @elseif($isExpiring)
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold" style="background:rgba(245,158,11,0.12); color:#f59e0b;">Expiring {{ $completion->expires_at->format('d M') }}</span>
                        @elseif($isCompleted)
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold" style="background:rgba(34,197,94,0.12); color:#22c55e;">Completed</span>
                        @elseif($pct > 0)
                            <span class="text-xs font-medium" style="color:#0ea5e9;">Continue</span>
                        @else
                            <span class="text-xs font-medium" style="color:var(--text-secondary);">Start</span>
                        @endif
                    </div>
                </div>
            </div>
        </a>
        @endforeach
    </div>
    @endif

</div>
@endsection
