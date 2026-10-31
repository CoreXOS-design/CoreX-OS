{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Add a training video. Owner-only.
    Spec: .ai/specs/training-videos.md §4.1
--}}
@extends('layouts.corex')

@section('title', 'Add training video')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Add training video</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    It appears on the public Training Videos page as soon as you save.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.training-videos.index') }}" class="corex-btn-outline corex-btn-on-brand text-xs">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/>
                    </svg>
                    Back to Training Videos
                </a>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.training-videos.store') }}" class="max-w-3xl space-y-4"
          x-data="tvForm({ source: @js(old('source') === 'upload' ? 'upload' : 'youtube'), isEdit: false })"
          @tv-upload-state="uploadBusy = $event.detail.busy; uploadDone = $event.detail.done">
        @csrf

        @include('admin.training-videos._form', ['video' => null])

        <div class="flex items-center gap-2">
            <button type="submit" class="corex-btn-primary text-xs" :disabled="blocked" :class="blocked ? 'opacity-50 cursor-not-allowed' : ''">Add video</button>
            <a href="{{ route('admin.training-videos.index') }}" class="corex-btn-outline text-xs">Cancel</a>
        </div>
    </form>

</div>
@endsection
