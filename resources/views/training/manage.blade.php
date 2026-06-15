{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Training Management</h1>
                <p class="text-sm text-white/60">Create and manage training courses and lessons.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('training.create-course') }}" class="corex-btn-primary no-underline">+ New Course</a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" style="color: var(--ds-green);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <div class="flex-1">{{ session('success') }}</div>
        </div>
    @endif

    @if($courses->isEmpty())
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No courses yet</h3>
            <p class="text-sm mb-4" style="color: var(--text-muted);">Create your first course to start building your training library.</p>
            <a href="{{ route('training.create-course') }}" class="corex-btn-primary no-underline">+ New Course</a>
        </div>
    @else
    <div class="space-y-3">
        @foreach($courses as $course)
        @php $catColor = \App\Models\TrainingCourse::CATEGORY_COLORS[$course->category] ?? ['bg' => 'rgba(148,163,184,0.12)', 'color' => '#94a3b8']; @endphp
        <div class="flex items-center gap-4 p-4 rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-sm font-semibold" style="color: var(--text-primary);">{{ $course->title }}</span>
                    <span class="ds-badge" style="background: {{ $catColor['bg'] }}; color: {{ $catColor['color'] }};">{{ $course->category_label }}</span>
                    @if($course->is_required)
                    <span class="ds-badge ds-badge-warning">Required</span>
                    @endif
                    @if($course->is_required_for_activation)
                    <span class="ds-badge ds-badge-warning">For Activation</span>
                    @endif
                    @unless($course->is_published)
                    <span class="ds-badge ds-badge-default">Draft</span>
                    @endunless
                </div>
                <div class="text-xs mt-1" style="color: var(--text-muted);">{{ number_format($course->lessons_count) }} lesson{{ $course->lessons_count !== 1 ? 's' : '' }}</div>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <a href="{{ route('training.create-lesson', $course) }}" class="corex-btn-outline no-underline" style="color: var(--brand-icon); border-color: color-mix(in srgb, var(--brand-icon) 30%, transparent);">+ Lesson</a>
                <a href="{{ route('training.edit-course', $course) }}" class="corex-btn-outline no-underline">Edit</a>
            </div>
        </div>
        @endforeach
    </div>
    @endif
</div>
@endsection
