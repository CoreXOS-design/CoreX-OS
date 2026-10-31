{{-- AT-525 (spec section 9.20) - CoreX voices: the system owner makes Chatterbox voices from a consented reference clip; every agency can then choose them. --}}
@extends('layouts.corex')

@section('corex-content')
<div class="p-4 md:p-6 max-w-5xl mx-auto space-y-4" data-voices-page>
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <a href="{{ route('corex.training-library.index') }}" class="text-xs underline" style="color: var(--text-muted);">&larr; Training library</a>
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">CoreX voices</h1>
        <p class="text-xs" style="color: var(--text-muted);">Voices you make here are offered to every agency. One of them is the platform default: every agency uses it unless its admin picks another. Only you (the system owner) can make, change or remove them.</p>
    </div>

    @if(session('success'))<div class="rounded-md px-4 py-2 text-sm" style="border: 1px solid var(--ds-green,#16a34a);">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-2 text-sm" style="color: var(--ds-crimson,#b3261e); border: 1px solid var(--ds-crimson,#b3261e);">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
    @unless($engineReady)<div class="rounded-md px-4 py-2 text-sm" style="border: 1px solid var(--ds-amber,#f59e0b);">The voice engine (Chatterbox) is not installed on this server, so new voices cannot be made here yet.</div>@endunless

    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-bold">Make a new voice</h2>
        <p class="text-xs" style="color: var(--text-muted);">Record or upload 5 to 20 seconds of one person speaking clearly, with no background noise. The recording stays private on our server: agencies never see or download it, they only hear the samples and the videos. Do not use anyone's voice without their agreement.</p>
        <form method="POST" action="{{ route('corex.training-library.voices.store') }}" enctype="multipart/form-data" class="space-y-3" id="voice-form">@csrf
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="text-xs">Name of the voice
                    <input type="text" name="name" value="{{ old('name') }}" maxlength="60" required class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                </label>
                <label class="text-xs">One line about it (optional)
                    <input type="text" name="about" value="{{ old('about') }}" maxlength="150" placeholder="e.g. South African female, calm" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                </label>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <input type="file" name="reference" id="voice-file" accept="audio/*" class="text-xs">
                <span class="text-xs" style="color: var(--text-muted);">or</span>
                <button type="button" class="corex-btn-outline text-xs" id="voice-rec">&#9679; Record now</button>
                <span class="text-xs" id="voice-rec-status" style="color: var(--text-muted);"></span>
            </div>
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="consent" value="1" required class="mt-1" data-consent>
                <span>I confirm the person in this recording agreed to their voice being used.</span>
            </label>
            <button type="submit" class="corex-btn-primary text-xs" @disabled(! $engineReady)>Save voice</button>
        </form>
        <script>
            (function () {
                var btn = document.getElementById('voice-rec'), st = document.getElementById('voice-rec-status'), fileInput = document.getElementById('voice-file');
                var rec = null, chunks = [], t0 = 0, timer = null;
                btn.addEventListener('click', function () {
                    if (rec && rec.state === 'recording') { rec.stop(); return; }
                    if (!navigator.mediaDevices || !window.MediaRecorder) { st.textContent = 'This browser cannot record - upload a file instead.'; return; }
                    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
                        chunks = []; rec = new MediaRecorder(stream);
                        rec.ondataavailable = function (e) { if (e.data.size) { chunks.push(e.data); } };
                        rec.onstop = function () {
                            clearInterval(timer); stream.getTracks().forEach(function (t) { t.stop(); });
                            var blob = new Blob(chunks, { type: rec.mimeType || 'audio/webm' });
                            var f = new File([blob], 'recording.webm', { type: blob.type });
                            var dt = new DataTransfer(); dt.items.add(f); fileInput.files = dt.files;
                            btn.innerHTML = '&#9679; Record again'; st.textContent = 'Recorded ' + Math.round((Date.now() - t0) / 1000) + ' seconds - ready to save.';
                        };
                        rec.start(); t0 = Date.now(); btn.innerHTML = '&#9632; Stop';
                        timer = setInterval(function () { var s = Math.round((Date.now() - t0) / 1000); st.textContent = 'Recording ' + s + 's' + (s >= 20 ? ' - stop now' : ''); if (s >= 25) { rec.stop(); } }, 500);
                    }).catch(function () { st.textContent = 'The microphone is not available - upload a file instead.'; });
                });
            })();
        </script>
    </div>

    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-bold">Your CoreX voices</h2>
        <p class="text-xs" style="color: var(--text-muted);">Every voice reads the same two sentences: <em>{{ $sampleText }}</em></p>
        @forelse($rows as $r)
            @php($v = $r['voice'])
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-md p-3" style="border: 1px solid {{ $v->is_default ? 'var(--brand-icon, #0ea5e9)' : 'var(--border)' }}; background: var(--surface-2);" data-platform-voice="{{ $v->voice_key }}">
                <div>
                    <strong class="text-sm">{{ $v->name }}</strong>
                    @if($v->is_default)<span class="text-xs font-semibold rounded px-2 py-0.5 ml-2" style="background: var(--brand-icon, #0ea5e9); color: #fff;" data-platform-default>Platform default</span>@endif
                    <div class="text-xs" style="color: var(--text-muted);">{{ $v->about }} &middot; {{ $v->reference_seconds }} s recording, agreed to by the speaker on {{ $v->consent_confirmed_at->format('j M Y') }}</div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if($r['sample'])
                        <button type="button" class="corex-btn-outline text-xs" data-voice-play="{{ route('corex.training-library.voice-sample', $v->voice_key) }}" onclick="window.__tlPlayVoice(this)">&#9654; Play sample</button>
                    @else
                        <span class="text-xs" style="color: var(--text-muted);" data-sample-pending>Sample is being made...</span>
                    @endif
                    @unless($v->is_default)
                        <form method="POST" action="{{ route('corex.training-library.voices.default', $v) }}">@csrf<button type="submit" class="corex-btn-primary text-xs" data-make-default>Make platform default</button></form>
                    @endunless
                    <form method="POST" action="{{ route('corex.training-library.voices.archive', $v) }}" onsubmit="return confirm('Archive this voice? Agencies using it fall back to the platform default.');">@csrf<button type="submit" class="corex-btn-outline text-xs">Archive</button></form>
                </div>
            </div>
        @empty
            <div class="text-sm" style="color: var(--text-muted);" data-no-voices>No CoreX voices yet. Make the first one above; it becomes the platform default.</div>
        @endforelse
    </div>
    <script>
        window.__tlPlayVoice = window.__tlPlayVoice || function (btn) {
            var a = window.__tlAudio || (window.__tlAudio = new Audio());
            var playing = window.__tlBtn === btn && !a.paused;
            if (window.__tlBtn) { window.__tlBtn.innerHTML = '&#9654; Play sample'; }
            a.pause(); if (playing) { return; }
            window.__tlBtn = btn; btn.innerHTML = 'Loading&hellip;'; a.src = btn.getAttribute('data-voice-play');
            a.onplaying = function () { btn.innerHTML = '&#9632; Stop'; }; a.onended = function () { btn.innerHTML = '&#9654; Play sample'; }; a.onerror = function () { btn.innerHTML = 'Could not play'; };
            a.play().catch(function () { btn.innerHTML = 'Could not play'; });
        };
    </script>
</div>
@endsection
