@extends('layouts.corex')

@section('corex-content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    <div style="background:var(--brand-default, #0b2a4a); border-radius:6px; padding:20px 24px;">
        <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">{{ $course ? 'Edit Course' : 'New Course' }}</h2>
    </div>

    @if($errors->any())
        <div class="rounded-md border px-4 py-3 text-sm" style="border-color:#fecaca; background:#fef2f2; color:#991b1b;">
            @foreach($errors->all() as $error) <div>{{ $error }}</div> @endforeach
        </div>
    @endif

    <form method="POST" action="{{ $course ? route('training.update-course', $course) : route('training.store-course') }}" class="space-y-5">
        @csrf
        @if($course) @method('PUT') @endif

        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;">
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Title *</label>
                    <input type="text" name="title" value="{{ old('title', $course?->title) }}" required
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Description</label>
                    <textarea name="description" rows="3" class="w-full rounded-md px-3 py-2 text-sm"
                              style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">{{ old('description', $course?->description) }}</textarea>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Category *</label>
                        <select name="category" required class="w-full rounded-md px-3 py-2 text-sm"
                                style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            @foreach(\App\Models\TrainingCourse::CATEGORY_LABELS as $key => $label)
                                <option value="{{ $key }}" {{ old('category', $course?->category) === $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Sort Order</label>
                        <input type="number" name="sort_order" value="{{ old('sort_order', $course?->sort_order ?? 0) }}" min="0"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                </div>
                <div class="flex items-center gap-6">
                    <label class="flex items-center gap-2 text-sm cursor-pointer" style="color:var(--text-secondary);">
                        <input type="checkbox" name="is_required" value="1" {{ old('is_required', $course?->is_required) ? 'checked' : '' }}> Required
                    </label>
                    <label class="flex items-center gap-2 text-sm cursor-pointer" style="color:var(--text-secondary);">
                        <input type="checkbox" name="is_required_for_activation" value="1" {{ old('is_required_for_activation', $course?->is_required_for_activation) ? 'checked' : '' }}> Required for Activation
                    </label>
                    <label class="flex items-center gap-2 text-sm cursor-pointer" style="color:var(--text-secondary);">
                        <input type="checkbox" name="is_published" value="1" {{ old('is_published', $course?->is_published ?? true) ? 'checked' : '' }}> Published
                    </label>
                </div>
            </div>
        </div>

        {{-- Lessons list if editing --}}
        @if($course && $course->lessons->count())
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Lessons ({{ $course->lessons->count() }})</h3>
            </div>
            @foreach($course->lessons as $lesson)
            <div class="flex items-center gap-3 px-5 py-2.5" style="{{ !$loop->last ? 'border-bottom:1px solid var(--border);' : '' }}">
                <span class="text-xs font-bold w-6 text-center" style="color:var(--text-muted);">{{ $lesson->sort_order }}</span>
                <span class="text-sm flex-1" style="color:var(--text-primary);">{{ $lesson->title }}</span>
                <span class="text-xs" style="color:var(--text-muted);">{{ $lesson->duration_minutes }}m</span>
                <a href="{{ route('training.edit-lesson', $lesson) }}" class="text-xs px-2 py-1 rounded no-underline" style="color:var(--text-secondary); border:1px solid var(--border);">Edit</a>
            </div>
            @endforeach
        </div>
        @endif

        <div class="flex items-center justify-between">
            <a href="{{ route('training.manage') }}" class="text-sm no-underline" style="color:var(--text-secondary);">Cancel</a>
            <button type="submit" class="corex-btn-primary text-sm px-6 py-2.5">{{ $course ? 'Update Course' : 'Create Course' }}</button>
        </div>
    </form>
</div>
@endsection
