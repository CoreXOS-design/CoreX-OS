{{-- AT-525 - the Training library (spec .ai/specs/training-videos.md section 9): auto-generated how-to videos, one per screen. --}}
@extends('layouts.corex')

@section('corex-content')
<div class="p-4 md:p-6 max-w-6xl mx-auto space-y-4" data-training-library>
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Training library</h1>
        <p class="text-xs" style="color: var(--text-muted);">Short how-to videos, made by CoreX itself: what to click, and why it matters - what each step feeds elsewhere. Also on every screen that has one: the <strong>Watch how</strong> button.</p>
    </div>

    @if(session('success'))<div class="rounded-md px-4 py-2 text-sm" style="background: color-mix(in srgb, var(--ds-green,#16a34a) 12%, var(--surface)); border: 1px solid var(--ds-green,#16a34a);">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-2 text-sm" style="color: var(--ds-crimson,#b3261e); border: 1px solid var(--ds-crimson,#b3261e);">{{ $errors->first() }}</div>@endif

    @if(count($rows) === 0)
        <div class="rounded-md p-8 text-center text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);" data-training-empty>
            No how-to videos yet. They appear here as they are made.
        </div>
    @else
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach($rows as $r)
            @php($s = $r['script'])
            @php($v = $r['video'])
            <div class="rounded-md overflow-hidden flex flex-col" style="background: var(--surface); border: 1px solid var(--border);" data-training-card="{{ $s->key() }}">
                @if($v)
                    <a href="{{ route('corex.training-library.show', $s->key()) }}" class="block relative">
                        <img src="{{ route('corex.training-library.file', [$s->key(), 'jpg']) }}" alt="" class="w-full" style="aspect-ratio: 16/9; object-fit: cover; background: var(--surface-2);" loading="lazy">
                        <span class="absolute bottom-2 right-2 text-xs font-semibold rounded px-2 py-0.5" style="background: rgba(0,0,0,.7); color: #fff;">{{ $v->durationLabel() }}</span>
                    </a>
                @else
                    <div class="flex items-center justify-center text-sm" style="aspect-ratio: 16/9; background: var(--surface-2); color: var(--text-muted);">No video yet</div>
                @endif
                <div class="p-4 space-y-2 flex-1 flex flex-col">
                    <h2 class="text-sm font-semibold">{{ $v ? $s->title() : $s->title() }}</h2>
                    @if($r['outOfDate'])
                        <div class="text-xs font-semibold rounded px-2 py-1" style="background: color-mix(in srgb, var(--ds-amber,#f59e0b) 16%, var(--surface)); border: 1px solid var(--ds-amber,#f59e0b);" data-out-of-date>Out of date - the screen, its tour or its spec changed since this was made.</div>
                    @endif
                    <div class="flex flex-wrap gap-2 mt-auto pt-2">
                        @if($v)<a href="{{ route('corex.training-library.show', $s->key()) }}" class="corex-btn-primary text-xs">Watch ({{ $v->durationLabel() }})</a>@endif
                        @if($canGenerate)
                            <form method="POST" action="{{ route('corex.training-library.generate', $s->key()) }}">@csrf
                                <button type="submit" class="{{ $v && ! $r['outOfDate'] ? 'corex-btn-outline' : 'corex-btn-primary' }} text-xs" data-training-generate>{{ $v ? 'Regenerate' : 'Generate' }}</button>
                            </form>
                        @endif
                    </div>
                    @if($v && $v->generated_at)<div class="text-xs" style="color: var(--text-muted);">Made {{ $v->generated_at->format('j M Y') }} · voice-over: {{ $v->tts_voice }}</div>@endif
                </div>
            </div>
        @endforeach
    </div>
    @endif
</div>
@endsection
