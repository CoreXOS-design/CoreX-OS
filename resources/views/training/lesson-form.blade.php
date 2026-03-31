@extends('layouts.corex')

@section('corex-content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    <div style="background:var(--brand-default, #0b2a4a); border-radius:6px; padding:20px 24px;">
        <div class="flex items-center gap-2 mb-1">
            <a href="{{ route('training.edit-course', $course) }}" class="text-xs no-underline" style="color:rgba(255,255,255,0.5);">{{ $course->title }}</a>
            <span style="color:rgba(255,255,255,0.3);">/</span>
        </div>
        <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">{{ $lesson ? 'Edit Lesson' : 'New Lesson' }}</h2>
    </div>

    @if($errors->any())
        <div class="rounded-md border px-4 py-3 text-sm" style="border-color:#fecaca; background:#fef2f2; color:#991b1b;">
            @foreach($errors->all() as $error) <div>{{ $error }}</div> @endforeach
        </div>
    @endif

    <form method="POST"
          action="{{ $lesson ? route('training.update-lesson', $lesson) : route('training.store-lesson', $course) }}"
          enctype="multipart/form-data" class="space-y-5">
        @csrf
        @if($lesson) @method('PUT') @endif

        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;">
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Title *</label>
                    <input type="text" name="title" value="{{ old('title', $lesson?->title) }}" required
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Content Type *</label>
                        <select name="content_type" required class="w-full rounded-md px-3 py-2 text-sm"
                                style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                x-data x-ref="ctype">
                            @foreach(['text' => 'Text', 'video_url' => 'Video URL', 'document' => 'Document', 'link' => 'External Link'] as $key => $label)
                                <option value="{{ $key }}" {{ old('content_type', $lesson?->content_type ?? 'text') === $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Duration (min)</label>
                        <input type="number" name="duration_minutes" value="{{ old('duration_minutes', $lesson?->duration_minutes ?? 10) }}" min="1" max="480"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Sort Order</label>
                        <input type="number" name="sort_order" value="{{ old('sort_order', $lesson?->sort_order ?? 0) }}" min="0"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Content (for text type)</label>
                    <textarea name="content" rows="10" class="w-full rounded-md px-3 py-2 text-sm font-mono"
                              style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">{{ old('content', $lesson?->content) }}</textarea>
                </div>

                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Video URL</label>
                    <input type="url" name="video_url" value="{{ old('video_url', $lesson?->video_url) }}" placeholder="https://www.youtube.com/embed/..."
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                </div>

                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">External Link</label>
                    <input type="url" name="external_link" value="{{ old('external_link', $lesson?->external_link) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                </div>

                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Document File</label>
                    <input type="file" name="document_file" class="text-sm" style="color:var(--text-secondary);">
                    @if($lesson?->document_path)
                    <div class="text-xs mt-1" style="color:var(--text-muted);">Current: {{ basename($lesson->document_path) }}</div>
                    @endif
                </div>

                <label class="flex items-center gap-2 text-sm cursor-pointer" style="color:var(--text-secondary);">
                    <input type="checkbox" name="is_published" value="1" {{ old('is_published', $lesson?->is_published ?? true) ? 'checked' : '' }}> Published
                </label>
            </div>
        </div>

        <div class="flex items-center justify-between">
            <a href="{{ route('training.edit-course', $course) }}" class="text-sm no-underline" style="color:var(--text-secondary);">Cancel</a>
            <button type="submit" class="corex-btn-primary text-sm px-6 py-2.5">{{ $lesson ? 'Update Lesson' : 'Add Lesson' }}</button>
        </div>
    </form>
</div>
@endsection
