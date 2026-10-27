{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Edit a training video. Owner-only.
    Spec: .ai/specs/training-videos.md §4.1
--}}
@extends('layouts.corex')

@section('title', 'Edit training video')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Edit training video</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    @if ($video->trashed())
                        This video is archived and not on the public page. Restore it from the list to publish it again.
                    @else
                        Changes show on the public Training Videos page as soon as you save.
                    @endif
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

    {{-- Preview of the saved video, as the public will see it. --}}
    @if ($video->isUpload())
        @if ($video->status === 'processing' && ! $video->file_name)
            <div class="max-w-3xl rounded-md px-4 py-3 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                CoreX is shrinking this video now. It is not on the public page yet — it appears when the list shows Ready.
                @if ($video->isStuck())
                    <div class="mt-1 text-xs" style="color: var(--ds-amber);">Still processing after an hour — the video worker may not be running on this server.</div>
                @endif
            </div>
        @elseif ($video->status === 'failed' && ! $video->file_name)
            <div role="alert" class="max-w-3xl rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
                <strong>This video could not be processed.</strong> {{ $video->failure_reason }}
                <div class="mt-2 flex items-center gap-2">
                    @if ($video->canRetry())
                        <form method="POST" action="{{ route('admin.training-videos.retry', $video) }}" class="inline">@csrf
                            <button type="submit" class="corex-btn-outline text-xs">Try again</button>
                        </form>
                    @endif
                    <span class="text-xs" style="color: var(--text-muted);">Or upload a different file below.</span>
                </div>
            </div>
        @else
            @if ($video->trashed())
                <div class="max-w-3xl rounded-md px-4 py-3 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);">
                    This video is archived, so its file is not available to watch here. Restore it from the list to preview and publish it again.
                </div>
            @else
                <div class="max-w-3xl rounded-md overflow-hidden" style="background: #000; aspect-ratio: 16/9;">
                    <video controls preload="metadata" playsinline class="w-full h-full" poster="{{ $video->posterUrl() }}" src="{{ $video->fileUrl() }}"></video>
                </div>
            @endif
            <div class="max-w-3xl text-xs" style="color: var(--text-muted);">
                {{ $video->sizeSummary() }}@if ($video->durationLabel()) · {{ $video->durationLabel() }}@endif
            </div>
            @if ($video->isReplacing())
                <div class="max-w-3xl text-xs" style="color: var(--ds-amber);">A new file is being shrunk. This video keeps playing until it is ready.</div>
            @elseif ($video->replacementFailed())
                <div role="alert" class="max-w-3xl text-xs" style="color: var(--ds-crimson);">
                    The replacement file could not be processed: {{ $video->failure_reason }} This video is still playing.
                    @if ($video->canRetry())
                        <form method="POST" action="{{ route('admin.training-videos.retry', $video) }}" class="inline">@csrf
                            <button type="submit" class="underline ml-1">Try again</button>
                        </form>
                    @endif
                </div>
            @endif
        @endif
    @else
        <div class="max-w-3xl rounded-md overflow-hidden" style="background: #000; aspect-ratio: 16/9;">
            <iframe src="https://www.youtube-nocookie.com/embed/{{ $video->youtube_video_id }}?rel=0"
                    title="{{ $video->title }}" class="w-full h-full" style="border: 0;"
                    allow="accelerometer; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.training-videos.update', $video) }}" class="max-w-3xl space-y-4"
          x-data="tvForm({ source: @js($video->source), isEdit: true })"
          @tv-upload-state="uploadBusy = $event.detail.busy; uploadDone = $event.detail.done">
        @csrf
        @method('PUT')

        @include('admin.training-videos._form', ['video' => $video])

        <div class="flex items-center gap-2">
            <button type="submit" class="corex-btn-primary text-xs" :disabled="blocked" :class="blocked ? 'opacity-50 cursor-not-allowed' : ''">Save changes</button>
            <a href="{{ route('admin.training-videos.index') }}" class="corex-btn-outline text-xs">Cancel</a>
        </div>
    </form>

</div>
@endsection
