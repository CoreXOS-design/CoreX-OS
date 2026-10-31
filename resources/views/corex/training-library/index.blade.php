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

    @if($canGenerate && count($voiceRows))
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" data-voice-picker>
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h2 class="text-sm font-bold">Voice @if($isSystemOwner)<a href="{{ route('corex.training-library.voices') }}" class="text-xs underline font-normal ml-2" data-voices-link>Make or manage CoreX voices (system owner)</a>@endif</h2>
                <p class="text-xs" style="color: var(--text-muted);" data-voice-status>Your agency uses the platform default ({{ $voices->find($defaultVoice)['label'] ?? '' }}) unless you choose another. {{ $inMyVoice }} of {{ $approvedCount }} videos exist in {{ $voices->find($currentVoice)['label'] ?? 'your' }} voice.</p>
                <p class="text-xs" style="color: var(--text-muted);">Every voice below reads the same two sentences: <em>{{ $sampleText }}</em> Press play, then pick the one you like.</p>
            </div>
            @if($approvedCount > 0)
            <form method="POST" action="{{ route('corex.training-library.regenerate-all') }}" onsubmit="return confirm('Make all {{ $approvedCount }} videos again in the {{ $voices->find($currentVoice)['label'] ?? '' }} voice? Each takes a few minutes.');">@csrf
                <button type="submit" class="corex-btn-outline text-xs" data-regenerate-all>Regenerate all videos with this voice</button>
            </form>
            @endif
        </div>
        @foreach(collect($voiceRows)->groupBy('provider') as $prov => $group)
        @php($meta = config('training-videos.auto.providers.' . $prov, ['label' => ucfirst($prov), 'licence' => '', 'cost' => '']))
        <div data-voice-group="{{ $prov }}">
        <div class="text-xs mb-2" style="color: var(--text-muted);"><strong style="color: var(--text-primary);">{{ $meta['label'] }}</strong> &middot; {{ $meta['cost'] }} &middot; {{ $meta['licence'] }}</div>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach($group as $v)
                <div class="rounded-md p-3 flex flex-col gap-2" style="border: 1px solid {{ $v['current'] ? 'var(--brand-icon, #0ea5e9)' : 'var(--border)' }}; background: var(--surface-2);" data-voice="{{ $v['key'] }}">
                    <div class="flex items-center justify-between gap-2">
                        <strong class="text-sm">{{ $v['label'] }}</strong>
                        @if($v['current'])<span class="text-xs font-semibold rounded px-2 py-0.5" style="background: var(--brand-icon, #0ea5e9); color: #fff;" data-voice-current>Current voice</span>@endif
                        @if($v['platform_default'])<span class="text-xs rounded px-2 py-0.5" style="border: 1px solid var(--border);" data-voice-platform-default>Platform default</span>@endif
                    </div>
                    <div class="text-xs flex-1" style="color: var(--text-muted);">{{ $v['about'] }}</div>
                    @if($v['installed'])
                        @if($v['sample'] || $v['provider'] !== 'chatterbox')
                        <button type="button" class="corex-btn-outline text-xs" data-voice-play="{{ route('corex.training-library.voice-sample', $v['key']) }}" onclick="window.__tlPlayVoice(this)">&#9654; Play sample</button>
                        @else
                        <span class="text-xs" style="color: var(--text-muted);" data-sample-pending>Sample is being made...</span>
                        @endif
                        @unless($v['current'])
                        <form method="POST" action="{{ route('corex.training-library.voice.choose') }}">@csrf
                            <input type="hidden" name="voice" value="{{ $v['key'] }}">
                            <button type="submit" class="corex-btn-primary text-xs w-full" data-use-voice>Use this voice</button>
                        </form>
                        @endunless
                    @else
                        <div class="text-xs" style="color: var(--text-muted);">Not installed on this server yet.</div>
                    @endif
                </div>
            @endforeach
        </div>
        </div>
        @endforeach
    </div>
    <script>
        window.__tlPlayVoice = function (btn) {
            var a = window.__tlAudio || (window.__tlAudio = new Audio());
            var playing = window.__tlBtn === btn && !a.paused;
            if (window.__tlBtn) { window.__tlBtn.innerHTML = '&#9654; Play sample'; }
            a.pause();
            if (playing) { return; }
            window.__tlBtn = btn; btn.innerHTML = 'Loading&hellip;';
            a.src = btn.getAttribute('data-voice-play');
            a.onplaying = function () { btn.innerHTML = '&#9632; Stop'; };
            a.onended = function () { btn.innerHTML = '&#9654; Play sample'; };
            a.onerror = function () { btn.innerHTML = 'Could not play'; };
            a.play().catch(function () { btn.innerHTML = 'Could not play'; });
        };
    </script>
    @endif

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
                    @if($v && $v->generated_at)<div class="text-xs" style="color: var(--text-muted);">Made {{ $v->generated_at->format('j M Y') }} · voice: {{ $voices->labelFor($v->tts_voice) }}@if($canGenerate && ! str_ends_with((string) $v->tts_voice, ':' . $currentVoice)) <span data-voice-differs>(not your chosen voice)</span>@endif</div>@endif
                </div>
            </div>
        @endforeach
    </div>
    @endif
</div>
@endsection
