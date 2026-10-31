{{-- AT-525 - one how-to video: the player (captions on), chapters, related videos, and Regenerate for people who may. --}}
@extends('layouts.corex')

@section('corex-content')
<div class="p-4 md:p-6 max-w-5xl mx-auto space-y-4" data-training-show="{{ $script->key() }}">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <a href="{{ route('corex.training-library.index') }}" class="text-xs underline" style="color: var(--text-muted);">&larr; Training library</a>
            <h1 class="text-base font-bold">{{ $script->title() }}</h1>
        </div>
        @if($canGenerate)
            <form method="POST" action="{{ route('corex.training-library.generate', $script->key()) }}">@csrf
                <button type="submit" class="{{ $outOfDate ? 'corex-btn-primary' : 'corex-btn-outline' }} text-xs" data-training-generate>Regenerate</button>
            </form>
        @endif
    </div>
    @if(session('success'))<div class="rounded-md px-4 py-2 text-sm" style="border: 1px solid var(--ds-green,#16a34a);">{{ session('success') }}</div>@endif
    @if($outOfDate)
        <div class="rounded-md px-4 py-2 text-sm font-semibold" style="background: color-mix(in srgb, var(--ds-amber,#f59e0b) 16%, var(--surface)); border: 1px solid var(--ds-amber,#f59e0b);" data-out-of-date>This video may be out of date - the screen, its tour or its spec changed since it was made.@if($canGenerate) Press Regenerate.@endif</div>
    @endif
    <video id="tl-player" controls preload="metadata" playsinline class="w-full rounded-md" style="background:#000; aspect-ratio: 16/9;" poster="{{ route('corex.training-library.file', [$script->key(), 'jpg']) }}">
        <source src="{{ route('corex.training-library.file', [$script->key(), 'mp4']) }}" type="video/mp4">
        <track kind="subtitles" srclang="en" label="English" src="{{ route('corex.training-library.file', [$script->key(), 'vtt']) }}" default>
    </video>
    <div class="text-xs" style="color: var(--text-muted);">{{ $video->durationLabel() }} · made {{ $video->generated_at?->format('j M Y') }} · voice-over: {{ $video->tts_voice }}@if($script->route()) · <a class="underline" href="{{ $script->url() }}">Open this screen</a>@endif</div>
    @if(! empty($video->chapters))
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);" data-chapters>
            <h2 class="text-sm font-semibold mb-2">In this video</h2>
            <ol class="space-y-1 text-sm">
                @foreach($video->chapters as $c)
                    <li><button type="button" class="underline" style="color: var(--brand-icon,#0ea5e9);" onclick="var p=document.getElementById('tl-player'); p.currentTime={{ (int) $c['at'] }}; p.play();">{{ intdiv((int) $c['at'], 60) }}:{{ str_pad((string) ((int) $c['at'] % 60), 2, '0', STR_PAD_LEFT) }}</button> {{ $c['title'] }}</li>
                @endforeach
            </ol>
        </div>
    @endif
    @if($related->isNotEmpty())
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);" data-related>
            <h2 class="text-sm font-semibold mb-2">Watch next</h2>
            <ul class="space-y-1 text-sm">@foreach($related as [$rs, $rv])<li><a class="underline" href="{{ route('corex.training-library.show', $rs->key()) }}">{{ $rs->title() }}</a> ({{ $rv->durationLabel() }})</li>@endforeach</ul>
        </div>
    @endif
</div>
@endsection
