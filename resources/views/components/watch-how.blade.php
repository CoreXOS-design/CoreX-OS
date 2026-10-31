@props(['inline' => false])
{{-- AT-525 (spec .ai/specs/training-videos.md section 9) - the "Watch how (1:24)" button. <x-watch-how /> floats bottom-right; <x-watch-how inline /> sits in a page header (white on navy, like the MIC header buttons) and then the floating one is not drawn on that page. Appears on any screen that has a generated how-to video: found by the route name in the
     scripts (resources/training/scripts), shown only when the video exists and the user holds training_videos.view. A small pill above the help button; the player opens in a
     dialog right there (the same authenticated file routes as the Training library). Nothing here for a screen without a video. --}}
@auth
@php
    $__whScript = null; $__whVideo = null;
    try {
        if (auth()->user()->hasPermission('training_videos.view')) {
            $__whScript = app(\App\Services\TrainingVideos\Auto\TrainingScriptRepository::class)->forRoute(optional(request()->route())->getName())
                ?? app(\App\Services\TrainingVideos\Auto\TrainingScriptRepository::class)->forPath(request()->path());
            if ($__whScript && ($__whScript->audience() === [] || in_array((string) auth()->user()->role, $__whScript->audience(), true) || auth()->user()->isOwnerRole())) {
                $__whVideo = \App\Models\TrainingVideo::where('source', 'generated')->where('screen_key', $__whScript->key())->where('status', 'ready')->first();
            }
        }
    } catch (\Throwable $e) { $__whVideo = null; }
@endphp
@if($__whVideo)
@php request()->attributes->set('watch_how_rendered', true); @endphp
<div @if($inline) style="display:inline-block;" @endif x-data="{ open: false }" x-on:keydown.escape.window="open = false; $refs.v && $refs.v.pause()" data-watch-how="{{ $__whScript->key() }}">
    <button type="button" x-on:click="open = true; $nextTick(() => $refs.v && $refs.v.play())" data-watch-how-button
            @if($inline)
            class="corex-btn-outline text-sm" style="display:inline-flex; align-items:center; gap:6px; color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);"
            @else
            class="fixed z-40 flex items-center gap-2 text-xs font-semibold rounded-full shadow-lg px-4 py-2" style="right: 16px; bottom: 72px; background: var(--brand-icon, #0ea5e9); color: #fff;"
            @endif>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width:14px;height:14px;"><path d="M8 5v14l11-7z"/></svg>
        Watch how ({{ $__whVideo->durationLabel() }})
    </button>
    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 flex items-center justify-center p-4" style="z-index: 90000; background: rgba(15,23,42,.65);" role="dialog" aria-modal="true" aria-label="{{ $__whScript->title() }}">
            <div class="w-full rounded-md overflow-hidden" style="max-width: 960px; background: var(--surface); border: 1px solid var(--border);" x-on:click.outside="open = false; $refs.v.pause()">
                <div class="flex items-center justify-between px-4 py-2"><div class="text-sm font-semibold">{{ $__whScript->title() }}</div>
                    <div class="flex items-center gap-3 text-xs"><a class="underline" href="{{ route('corex.training-library.show', $__whScript->key()) }}">Open in the library</a>
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
