{{-- AT-525 (spec .ai/specs/training-videos.md section 9.25) - the "Watch how (1:24)" button, in ONE place: a slim bar at the top of the main area, drawn by the layouts
     (layouts/partials/watch-how.blade.php). It appears on any screen that has a ready how-to video: the screen is found by the current route name against the scripts'
     `route` (resources/training/scripts), and it shows only when the user holds training_videos.view and Guided Tours is on. The player opens in a dialog right there
     (the same authenticated file routes as the Training library). A screen without a video draws nothing - not even the bar. Do NOT add this to a page header. --}}
@auth
@php
    $__whScript = null; $__whVideo = null;
    try {
        if (feature('guided-tours') && auth()->user()->hasPermission('training_videos.view')) {
            $__whScript = app(\App\Services\TrainingVideos\Auto\TrainingScriptRepository::class)->forRoute(optional(request()->route())->getName())
                ?? app(\App\Services\TrainingVideos\Auto\TrainingScriptRepository::class)->forPath(request()->path());
            if ($__whScript && ($__whScript->audience() === [] || in_array((string) auth()->user()->role, $__whScript->audience(), true) || auth()->user()->isOwnerRole())) {
                $__whAgency = \App\Models\Agency::withoutGlobalScopes()->find(auth()->user()->agency_id);
                $__whVideo = app(\App\Services\TrainingVideos\Auto\TrainingVideoResolver::class)->forScreen($__whScript->key(), app(\App\Services\TrainingVideos\Tts\TrainingVoices::class)->keyFor($__whAgency));
            }
        }
    } catch (\Throwable $e) { $__whVideo = null; }
@endphp
@if($__whVideo)
@include('corex.training-library._captions-js')
<div x-data="{ open: false }" x-on:keydown.escape.window="open = false; $refs.v && $refs.v.pause()" data-watch-how="{{ $__whScript->key() }}" data-watch-how-bar
     class="flex items-center justify-end gap-3 px-4 lg:px-6 py-1.5" style="flex:0 0 auto; background:var(--surface, #fff); border-bottom:1px solid var(--border, rgba(0,0,0,0.07));">
    <span class="hidden sm:inline text-xs truncate" style="color:var(--text-secondary, #4b5563);">How-to video for this screen</span>
    <button type="button" x-on:click="open = true; $nextTick(() => $refs.v && $refs.v.play())" data-watch-how-button
            class="flex items-center gap-2 text-xs font-semibold rounded-full px-4 py-1.5" style="background: var(--brand-icon, #0ea5e9); color: #fff; flex-shrink:0;">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width:14px;height:14px;"><path d="M8 5v14l11-7z"/></svg>
        Watch how ({{ $__whVideo->durationLabel() }})
    </button>
    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 flex items-center justify-center p-4" style="z-index: 90000; background: rgba(15,23,42,.65);" role="dialog" aria-modal="true" aria-label="{{ $__whScript->title() }}">
            <div class="w-full rounded-md overflow-hidden" style="max-width: 960px; background: var(--surface); border: 1px solid var(--border);" x-on:click.outside="open = false; $refs.v.pause()">
                <div class="flex items-center justify-between px-4 py-2"><div class="text-sm font-semibold">{{ $__whScript->title() }}</div>
                    <div class="flex items-center gap-3 text-xs"><a class="underline" href="{{ route('corex.training-library.show', $__whScript->key()) }}">Open in the library</a>
                        <button type="button" class="corex-btn-outline text-xs" data-cc-toggle aria-pressed="true" x-ref="cc" x-init="$nextTick(() => window.__tvCaptionsInit($refs.v, $refs.cc))" x-on:click="window.__tvCaptions($refs.v, $refs.cc)">CC: On</button>
                        <button type="button" class="corex-btn-outline text-xs" x-on:click="open = false; $refs.v.pause()">Close</button></div></div>
                <video x-ref="v" controls playsinline preload="none" class="w-full" style="background:#000; aspect-ratio:16/9;" poster="{{ route('corex.training-library.file', [$__whScript->key(), 'jpg']) }}">
                    <source src="{{ route('corex.training-library.file', [$__whScript->key(), 'mp4']) }}" type="video/mp4">
                    <track kind="subtitles" srclang="en" label="English" src="{{ route('corex.training-library.file', [$__whScript->key(), 'vtt']) }}" default>
                </video>
            </div>
        </div>
    </template>
</div>
@endif
@endauth
