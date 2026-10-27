{{--
    The "Upload a video file" control. Spec: .ai/specs/training-videos.md §8.6
    $video = the video being edited (a "Replace file" control), or null when adding.
--}}
@php
    $replacing = $video !== null;
    $maxBytes = (int) config('training-videos.max_upload_bytes');
    $maxLabel = \App\Models\TrainingVideo::formatBytes($maxBytes);
@endphp
<div x-data="trainingVideoUploader({
        startUrl: @js(route('api.v1.training-video-uploads.start')),
        chunkUrl: @js(route('api.v1.training-video-uploads.chunk', ['upload' => '__ID__'])),
        completeUrl: @js(route('api.v1.training-video-uploads.complete', ['upload' => '__ID__'])),
        maxBytes: {{ $maxBytes }},
        maxLabel: @js($maxLabel),
     })"
     :data-tv-uploading="busy ? '1' : '0'">

    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
        {{ $replacing ? 'Replace the video file' : 'Video file' }}
        @unless ($replacing) <span class="text-red-500">*</span> @endunless
    </label>

    <input type="hidden" name="upload_id" :value="state === 'done' ? uploadId : ''">

    <div x-show="state === 'idle' || state === 'error'">
        <input type="file" accept="video/*,.mov,.mkv,.avi,.m4v,.webm,.mp4" @change="pick($event)"
               class="block w-full text-sm" style="color: var(--text-primary);">
        <p class="mt-1 text-xs" style="color: var(--text-muted);">
            MP4, MOV, WebM and most other video files, up to {{ $maxLabel }}. CoreX shrinks it to 720p so it loads fast
            (a 180 MB screen recording typically ends up around 20–40 MB). The original is not kept — keep your own copy.
            @if ($replacing) The current video keeps playing until the new one is ready. @endif
        </p>
    </div>

    <div x-show="hasFile && state !== 'idle'" x-cloak class="mt-2 space-y-2">
        <div class="text-xs" style="color: var(--text-secondary);" x-text="info"></div>
        <div class="w-full rounded-full overflow-hidden" style="height: 8px; background: var(--surface-2); border: 1px solid var(--border);">
            <div class="h-full" :style="'width:' + percent + '%; background: var(--corex-accent); transition: width .2s;'"></div>
        </div>
        <div class="text-xs" style="color: var(--text-muted);">
            <span x-show="state === 'uploading'" x-text="'Uploading… ' + percent + '%'"></span>
            <span x-show="state === 'finishing'">Checking the video…</span>
            <span x-show="state === 'done'" style="color: var(--ds-emerald);">
                Upload complete. Press Save — after that you can leave this page; CoreX shrinks the video in the background.
            </span>
        </div>
        <div x-show="state === 'error'" role="alert" class="text-xs" style="color: var(--ds-crimson);">
            <span x-text="message"></span>
            <button type="button" class="underline ml-2" x-show="uploadId" @click="resume()">Resume</button>
        </div>
        <button type="button" x-show="state === 'done' || state === 'error'" @click="reset()"
                class="corex-btn-outline text-xs">Choose a different file</button>
    </div>
    <div x-show="state === 'uploading' || state === 'finishing'" class="mt-1 text-xs" style="color: var(--text-muted);">
        Keep this page open until it reaches 100%.
    </div>
</div>
