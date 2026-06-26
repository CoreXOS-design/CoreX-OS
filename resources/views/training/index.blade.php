{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (§2.4 Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);" data-tour="train-courses-intro">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">My Training</h1>
                <p class="text-sm text-white/60">Complete your required courses to stay compliant.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @include('layouts.partials.tour-header-launcher')
            </div>
        </div>
    </div>

    @php $userId = auth()->id(); @endphp

    @if($courses->isEmpty())
        {{-- Empty state (§3.10) --}}
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5" />
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No training courses yet</h3>
            <p class="text-sm" style="color: var(--text-muted);">Courses will appear here as soon as your agency publishes them.</p>
        </div>
    @else
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4" data-tour="train-courses-grid">
        @foreach($courses as $course)
        @php
            $pct = $course->completionPercentForUser($userId);
            $isCompleted = $course->isCompletedByUser($userId);
            $completion = $isCompleted ? $course->completionForUser($userId) : null;
            $isExpiring = $completion && $completion->expires_at && $completion->expires_at->lte(now()->addDays(30)) && $completion->expires_at->gt(now());
            $isExpired = $completion && $completion->expires_at && $completion->expires_at->lte(now());
            // Category tag colour mapped to design tokens (§3.4 tinted variant). Never red — red is danger-only (§5.3).
            $catTokens = [
                'compliance' => '--ds-navy',
                'onboarding' => '--ds-amber',
                'sales'      => '--ds-green',
                'systems'    => '--brand-icon',
                'general'    => '--text-muted',
            ];
            $catFallbacks = [
                '--ds-navy'     => '#0b2a4a',
                '--ds-amber'    => '#f59e0b',
                '--ds-green'    => '#059669',
                '--brand-icon'  => '#0ea5e9',
                '--text-muted'  => '#9ca3af',
            ];
            $catToken = $catTokens[$course->category] ?? '--text-muted';
            $catFallback = $catFallbacks[$catToken];
            $barVariant = $isCompleted ? 'ds-bar-green' : 'ds-bar-navy';
        @endphp
        <a href="{{ route('training.show', $course) }}" class="block no-underline rounded-md transition-colors"
           @if($loop->first) data-tour="train-courses-card" @endif
           style="background: var(--surface); border: 1px solid var(--border);">
            <div class="p-5">
                <div class="flex items-start justify-between gap-2 mb-2">
                    <h3 class="text-sm font-bold" style="color: var(--text-primary);">{{ $course->title }}</h3>
                    <div class="flex items-center gap-1.5 flex-shrink-0">
                        <span class="ds-badge" style="background: color-mix(in srgb, var({{ $catToken }}, {{ $catFallback }}) 14%, transparent); color: var({{ $catToken }}, {{ $catFallback }});">
                            {{ $course->category_label }}
                        </span>
                        @if($course->is_required)
                        <span class="ds-badge ds-badge-warning">Required</span>
                        @endif
                    </div>
                </div>

                @if($course->description)
                <p class="text-xs mb-3 line-clamp-2" style="color: var(--text-secondary);">{{ $course->description }}</p>
                @endif

                {{-- Progress bar (§3.13) --}}
                <div class="flex items-center gap-2 mb-2" @if($loop->first) data-tour="train-courses-progress" @endif>
                    <div class="ds-progress-track">
                        <div class="ds-progress-bar {{ $barVariant }}" style="width: {{ $pct }}%;"></div>
                    </div>
                    <span class="text-xs font-bold" style="color: var(--text-primary);">{{ $pct }}%</span>
                </div>

                <div class="flex items-center justify-between">
                    <div class="text-xs" style="color: var(--text-muted);">
                        {{ number_format($course->lessons_count) }} lesson{{ $course->lessons_count !== 1 ? 's' : '' }}
                        &middot; {{ number_format($course->totalDurationMinutes()) }} min
                    </div>
                    <div class="flex items-center gap-1.5">
                        @if($isExpired)
                            <span class="ds-badge ds-badge-danger">Expired</span>
                        @elseif($isExpiring)
                            <span class="ds-badge ds-badge-warning">Expiring {{ $completion->expires_at->format('d M') }}</span>
                        @elseif($isCompleted)
                            <span class="ds-badge ds-badge-success">Completed</span>
                        @elseif($pct > 0)
                            <span class="text-xs font-semibold" style="color: var(--brand-icon);">Continue</span>
                        @else
                            <span class="text-xs font-semibold" style="color: var(--text-secondary);">Start</span>
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
