{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Video ads — the script editor (Question -> Solution shots -> Call to action). System Owner only.
    Spec: .ai/specs/training-videos.md 9.21.3
--}}
@extends('layouts.corex')

@section('title', 'New video ad')

@section('corex-content')
@php
    $field = 'rounded-md px-3 py-2 text-sm w-full';
    $fieldStyle = 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);';
    $card = 'rounded-md p-4 space-y-3';
    $cardStyle = 'background: var(--surface); border: 1px solid var(--border);';
    $lbl = 'block text-xs font-medium mb-1';
    $draft['post']['hashtagsText'] = implode(' ', array_map(fn ($h) => '#' . ltrim((string) $h, '#'), (array) ($draft['post']['hashtags'] ?? [])));
@endphp
<div class="w-full space-y-5" x-data="adEditor(@js($draft), @js($steps), @js(route('api.v1.video-ads.validate')), @js($check))" x-cloak>

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $heading }} · {{ $script->title() }}</h1>
                <p class="text-xs" style="color: var(--text-muted);">Generate saves this as <strong>{{ $nextName }}</strong>. Once made, an ad never changes; to tweak it, use Duplicate.</p>
            </div>
            <a href="{{ route('admin.video-ads.index', ['feature' => $script->key()]) }}" class="corex-btn-outline corex-btn-on-brand text-xs">Back to Video ads</a>
        </div>
    </div>

    @if ($errors->any())
        <div role="alert" class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <p class="font-medium">This ad can't be made yet:</p>
            <ul class="list-disc pl-5 mt-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.video-ads.store') }}" @submit="$refs.draft.value = JSON.stringify(payload())" class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        @csrf
        <input type="hidden" name="draft" x-ref="draft">

        <div class="lg:col-span-2 space-y-5">

            {{-- QUESTION --}}
            <section class="{{ $card }}" style="{{ $cardStyle }}" data-qa="editor-question">
                <h2 class="text-sm font-bold" style="color: var(--text-primary);">1. Question</h2>
                <p class="text-xs" style="color: var(--text-muted);">The headline, shown in capitals in the first 3 seconds. Put ONE word between asterisks to show it in cyan, like <code>*guessing*</code>.</p>
                @if (count($questions) > 1)
                    <div class="flex flex-wrap gap-2">
                        @foreach ($questions as $v => $text)
                            <button type="button" class="corex-btn-outline text-xs" @click="draft.question = @js($text); touch()">Use variant {{ strtoupper($v) }}: {{ str_replace('*', '', $text) }}</button>
                        @endforeach
                    </div>
                @endif
                <input type="text" x-model="draft.question" @input="touch()" class="{{ $field }}" style="{{ $fieldStyle }}" maxlength="80" data-qa="question-input">
                <div class="rounded-md px-4 py-3 text-center font-extrabold tracking-wide" style="background: {{ $brand['colours']['primary'] }}; color: {{ $brand['colours']['text'] }}; font-size: 1.1rem;" x-html="questionPreview()"></div>
                <div>
                    <label class="{{ $lbl }}" style="color: var(--text-secondary);">Sub-line (short, punchy, under the headline - optional)</label>
                    <input type="text" x-model="draft.subline" @input="touch()" class="{{ $field }}" style="{{ $fieldStyle }}" maxlength="80" data-qa="subline-input">
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="{{ $lbl }}" style="color: var(--text-secondary);">Stat badge (cyan circle, optional)</label>
                        <input type="text" x-model="draft.badge.text" @input="touch()" class="{{ $field }}" style="{{ $fieldStyle }}" maxlength="14" placeholder="e.g. 3 in 10">
                    </div>
                    <div>
                        <label class="{{ $lbl }}" style="color: var(--text-secondary);">Source of the number</label>
                        <input type="text" x-model="draft.badge.source" @input="touch()" class="{{ $field }}" style="{{ $fieldStyle }}" maxlength="120">
                    </div>
                    <label class="flex items-center gap-2 text-xs self-end pb-2" style="color: var(--text-secondary);"><input type="checkbox" x-model="draft.badge.example" @change="touch()"> This is an example number</label>
                </div>
            </section>

            {{-- SOLUTION SHOTS --}}
            <section class="{{ $card }}" style="{{ $cardStyle }}" data-qa="editor-shots">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-bold" style="color: var(--text-primary);">2. Solution shots</h2>
                    <button type="button" class="corex-btn-outline text-xs" @click="addShot()" :disabled="draft.shots.length >= {{ $limits['shots_max'] }}">Add shot</button>
                </div>
                <p class="text-xs" style="color: var(--text-muted);">Each shot is the words on the screen (also spoken) and the part of the real screen it shows. Up to {{ $limits['shots_max'] }}.</p>
                <template x-for="(s, i) in draft.shots" :key="i">
                    <div class="rounded-md p-3 space-y-2" style="background: var(--surface-2); border: 1px solid var(--border);" data-qa="shot">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold" style="color: var(--text-secondary);" x-text="'Shot ' + (i + 1)"></span>
                            <span class="flex gap-1">
                                <button type="button" class="corex-btn-outline text-xs" style="padding: 1px 8px;" @click="move(i, -1)" :disabled="i === 0" aria-label="Move up">↑</button>
                                <button type="button" class="corex-btn-outline text-xs" style="padding: 1px 8px;" @click="move(i, 1)" :disabled="i === draft.shots.length - 1" aria-label="Move down">↓</button>
                                <button type="button" class="corex-btn-outline text-xs" style="padding: 1px 8px;" @click="removeShot(i)" :disabled="draft.shots.length <= {{ $limits['shots_min'] }}">Remove</button>
                            </span>
                        </div>
                        <div>
                            <label class="{{ $lbl }}" style="color: var(--text-secondary);">Words on the screen</label>
                            <input type="text" x-model="s.caption" @input="touch()" class="{{ $field }}" style="{{ $fieldStyle }}" maxlength="90" data-qa="shot-caption">
                        </div>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                            <div class="col-span-2">
                                <label class="{{ $lbl }}" style="color: var(--text-secondary);">Screen step it shows</label>
                                <select x-model="s.step" @change="touch()" class="{{ $field }}" style="{{ $fieldStyle }}">
                                    <option value="">Choose a step…</option>
                                    @foreach ($steps as $st)
                                        <option value="{{ $st['index'] }}">{{ $st['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="{{ $lbl }}" style="color: var(--text-secondary);">Action</label>
                                <select x-model="s.action" @change="touch()" class="{{ $field }}" style="{{ $fieldStyle }}">
                                    <option value="highlight">Highlight</option>
                                    <option value="click">Click</option>
                                </select>
                            </div>
                            <div>
                                <label class="{{ $lbl }}" style="color: var(--text-secondary);">Zoom (1–2.5)</label>
                                <input type="number" step="0.1" min="{{ $limits['zoom_min'] }}" max="{{ $limits['zoom_max'] }}" x-model.number="s.zoom" @input="touch()" class="{{ $field }}" style="{{ $fieldStyle }}">
                            </div>
                        </div>
                        <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);">
                            <input type="checkbox" x-model="s.proof" @change="touch()"> Proof shot (uses the invented training data only)
                        </label>
                    </div>
                </template>
            </section>

            {{-- CALL TO ACTION --}}
            <section class="{{ $card }}" style="{{ $cardStyle }}" data-qa="editor-cta">
                <h2 class="text-sm font-bold" style="color: var(--text-primary);">3. Call to action</h2>
                <p class="text-xs" style="color: var(--text-muted);">The end card comes from <a href="{{ route('admin.video-ads.brand') }}" class="hover:underline" style="color: var(--corex-accent);">Brand settings</a>: logo, website, email, phone, social handles and tagline. You can change the two lines for this ad only; leave blank to use the brand's.</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="{{ $lbl }}" style="color: var(--text-secondary);">Join line</label>
                        <input type="text" x-model="draft.cta.join_line" @input="touch()" placeholder="{{ $brand['join_line'] }}" class="{{ $field }}" style="{{ $fieldStyle }}" maxlength="60">
                    </div>
                    <div>
                        <label class="{{ $lbl }}" style="color: var(--text-secondary);">Call to action</label>
                        <input type="text" x-model="draft.cta.cta_line" @input="touch()" placeholder="{{ $brand['cta_line'] }}" class="{{ $field }}" style="{{ $fieldStyle }}" maxlength="100">
                    </div>
                </div>
            </section>

            {{-- POST TEXT --}}
            <section class="{{ $card }}" style="{{ $cardStyle }}" data-qa="editor-post">
                <h2 class="text-sm font-bold" style="color: var(--text-primary);">Post text</h2>
                <p class="text-xs" style="color: var(--text-muted);">Ready to paste with the video. <code>{question}</code>, <code>{cta}</code> and <code>{website}</code> are filled in when you press Generate.</p>
                <textarea rows="6" x-model="draft.post.caption" @input="touch()" class="{{ $field }}" style="{{ $fieldStyle }}"></textarea>
                <div>
                    <label class="{{ $lbl }}" style="color: var(--text-secondary);">Hashtags</label>
                    <input type="text" x-model="draft.post.hashtagsText" @input="touch()" class="{{ $field }}" style="{{ $fieldStyle }}" placeholder="#RealEstate #CoreX">
                </div>
            </section>
        </div>

        {{-- RIGHT: voice, length, formats, live check, Generate --}}
        <aside class="space-y-5 lg:sticky lg:top-4 self-start">
            <section class="{{ $card }}" style="{{ $cardStyle }}">
                <div>
                    <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="voice">Voice</label>
                    <select id="voice" x-model="draft.voice" @change="touch()" class="{{ $field }}" style="{{ $fieldStyle }}">
                        <option value="">Platform default voice</option>
                        @foreach ($voiceOptions as $v)
                            <option value="{{ $v['key'] }}">{{ $v['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="length">Length</label>
                    <select id="length" x-model.number="draft.length" @change="touch()" class="{{ $field }}" style="{{ $fieldStyle }}">
                        @foreach ($lengths as $l)<option value="{{ $l }}">{{ $l }} seconds</option>@endforeach
                    </select>
                </div>
                <fieldset>
                    <legend class="{{ $lbl }}" style="color: var(--text-secondary);">Formats</legend>
                    @foreach ($formats as $fmt => $for)
                        <label class="flex items-center gap-2 text-xs py-0.5" style="color: var(--text-secondary);">
                            <input type="checkbox" value="{{ $fmt }}" x-model="draft.formats" @change="touch()"> <strong>{{ $fmt }}</strong> {{ $for }}
                        </label>
                    @endforeach
                </fieldset>
            </section>

            <section class="{{ $card }}" style="{{ $cardStyle }}" data-qa="live-check">
                <h2 class="text-sm font-bold" style="color: var(--text-primary);">Check</h2>
                <p class="text-xs" style="color: var(--text-secondary);">About <strong x-text="Math.ceil(check.seconds)"></strong> of <strong x-text="draft.length"></strong> seconds.</p>
                <template x-if="check.problems.length === 0"><p class="text-xs" style="color: var(--ds-emerald);" data-qa="check-ok">Looks good - plain words, fits the length.</p></template>
                <ul class="text-xs list-disc pl-4 space-y-1" style="color: var(--ds-crimson);" x-show="check.problems.length > 0" data-qa="check-problems">
                    <template x-for="p in check.problems" :key="p"><li x-text="p"></li></template>
                </ul>
                <button type="submit" class="corex-btn-primary text-sm w-full" :disabled="check.problems.length > 0" data-qa="generate">Generate {{ $nextName }}</button>
            </section>
        </aside>
    </form>
</div>

<script>
function adEditor(draft, steps, validateUrl, check) {
    draft.shots = (draft.shots || []).map(function (s) { s.step = (s.step === null || s.step === undefined) ? '' : String(s.step); s.selector = s.selector || ''; return s; });
    draft.cta = draft.cta || { join_line: '', cta_line: '' };
    draft.subline = draft.subline || '';
    draft.badge = draft.badge || { text: '', example: false, source: '' };
    draft.post = draft.post || { caption: '', hashtagsText: '' };
    draft.voice = draft.voice || '';
    var timer = null;
    var esc = function (t) { return String(t).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); };
    return {
        draft: draft, steps: steps, check: check,
        payload: function () {
            var d = JSON.parse(JSON.stringify(this.draft));
            d.shots = d.shots.map(function (s) { s.step = s.step === '' ? null : parseInt(s.step, 10); return s; });
            d.post.hashtags = (d.post.hashtagsText || '').split(/[\s,]+/).filter(Boolean);
            delete d.post.hashtagsText;
            return d;
        },
        questionPreview: function () {
            var q = esc(this.draft.question || '').toUpperCase();
            return q.replace(/\*([^*]+)\*/, '<span style="color:{{ $brand['colours']['accent'] }}">$1</span>').replace(/\*/g, '');
        },
        addShot: function () { this.draft.shots.push({ caption: '', step: '', selector: '', action: 'highlight', zoom: 1.4, proof: false, hold: 0 }); this.touch(); },
        removeShot: function (i) { this.draft.shots.splice(i, 1); this.touch(); },
        move: function (i, by) { var j = i + by; if (j < 0 || j >= this.draft.shots.length) return; var t = this.draft.shots.splice(i, 1)[0]; this.draft.shots.splice(j, 0, t); this.touch(); },
        touch: function () {
            var self = this; clearTimeout(timer);
            timer = setTimeout(function () {
                fetch(validateUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]') ? document.querySelector('meta[name=csrf-token]').content : '' },
                    body: JSON.stringify({ draft: self.payload() }) })
                    .then(function (r) { return r.json(); }).then(function (j) { self.check = j; }).catch(function () {});
            }, 350);
        }
    };
}
</script>
@endsection
