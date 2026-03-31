@extends('layouts.corex')

@section('corex-content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    <div class="flex items-center justify-between gap-4" style="background:var(--brand-default, #0b2a4a); border-radius:6px; padding:20px 24px;">
        <div>
            <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">Training Management</h2>
            <div style="font-size:0.875rem; color:rgba(255,255,255,0.55);">Create and manage training courses and lessons.</div>
        </div>
        <a href="{{ route('training.create-course') }}" class="corex-btn-primary text-sm px-4 py-2 no-underline">+ New Course</a>
    </div>

    @if(session('success'))
        <div class="rounded-md border px-4 py-3 text-sm font-medium" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">{{ session('success') }}</div>
    @endif

    @if($courses->isEmpty())
        <div class="rounded-md p-8 text-center" style="background:var(--surface); border:1px solid var(--border);">
            <div class="text-sm" style="color:var(--text-secondary);">No courses yet. Create your first course to get started.</div>
        </div>
    @else
    <div class="space-y-3">
        @foreach($courses as $course)
        @php $catColor = \App\Models\TrainingCourse::CATEGORY_COLORS[$course->category] ?? ['bg' => 'rgba(148,163,184,0.12)', 'color' => '#94a3b8']; @endphp
        <div class="flex items-center gap-4 p-4 rounded-lg" style="background:var(--surface); border:1px solid var(--border);">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-sm font-bold" style="color:var(--text-primary);">{{ $course->title }}</span>
                    <span class="px-2 py-0.5 rounded text-[10px] font-semibold" style="background:{{ $catColor['bg'] }}; color:{{ $catColor['color'] }};">{{ $course->category_label }}</span>
                    @if($course->is_required)
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold" style="background:rgba(239,68,68,0.12); color:#ef4444;">Required</span>
                    @endif
                    @if($course->is_required_for_activation)
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold" style="background:rgba(245,158,11,0.12); color:#f59e0b;">For Activation</span>
                    @endif
                    @unless($course->is_published)
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold" style="background:rgba(148,163,184,0.12); color:#94a3b8;">Draft</span>
                    @endunless
                </div>
                <div class="text-xs mt-1" style="color:var(--text-muted);">{{ $course->lessons_count }} lesson{{ $course->lessons_count !== 1 ? 's' : '' }}</div>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <a href="{{ route('training.create-lesson', $course) }}" class="text-xs px-3 py-1.5 rounded no-underline" style="color:#0ea5e9; border:1px solid rgba(14,165,233,0.3);">+ Lesson</a>
                <a href="{{ route('training.edit-course', $course) }}" class="text-xs px-3 py-1.5 rounded no-underline" style="color:var(--text-secondary); border:1px solid var(--border);">Edit</a>
            </div>
        </div>
        @endforeach
    </div>
    @endif
</div>
@endsection
