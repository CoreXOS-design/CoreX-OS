@extends('layouts.corex-app')

@section('corex-content')
@php
    use App\Models\Compliance\Rcr\RcrAnswer;
    $section = $question->section;
    $isPeriod = $section?->has_period_columns ?? false;
    $periods = $isPeriod
        ? [RcrAnswer::PERIOD_P1, RcrAnswer::PERIOD_P2, RcrAnswer::PERIOD_P3]
        : [RcrAnswer::PERIOD_STATIC];
    $periodLabel = function (string $p) use ($periodRanges): string {
        if ($p === 'static') return 'Static';
        $r = $periodRanges[$p] ?? null;
        if (!$r) return strtoupper($p);
        $from = \Carbon\Carbon::parse($r[0])->format('M y');
        $to   = \Carbon\Carbon::parse($r[1])->format('M y');
        return strtoupper($p) . ': ' . $from . ' → ' . $to;
    };
@endphp
<div style="max-width:1400px;margin:0 auto;padding:0 20px;"
     x-data="rcrDeepView({
        submissionId: {{ $submission->id }},
        questionId:   {{ $question->id }},
        copyUrl:      @json(route('corex.compliance.rcr.answer.copied')),
        transposedUrl: @json(route('corex.compliance.rcr.answer.transposed')),
        prevUrl:      @json($prevQuestion ? route('corex.compliance.rcr.question.show', [$submission->id, $prevQuestion->question_code]) : null),
        nextUrl:      @json($nextQuestion ? route('corex.compliance.rcr.question.show', [$submission->id, $nextQuestion->question_code]) : null),
        flowMode:     localStorage.getItem('rcr_flow_mode') === '1',
     })"
     :class="flowMode ? 'rcr-flow-mode' : ''">

    {{-- HEADER STRIP --}}
    <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border);margin-bottom:14px;flex-wrap:wrap;gap:10px;"
         :style="flowMode ? 'position:sticky;top:0;z-index:50;background:var(--surface);padding:8px 12px;border-radius:0 0 6px 6px;box-shadow:0 2px 6px rgba(0,0,0,.06);' : ''">
        <div style="display:flex;align-items:center;gap:14px;">
            <a href="{{ route('corex.compliance.rcr.show', $submission->id) }}" style="font-size:0.75rem;color:var(--text-muted);text-decoration:none;">← All questions</a>
            @if($prevQuestion)
                <a href="{{ route('corex.compliance.rcr.question.show', [$submission->id, $prevQuestion->question_code]) }}"
                   style="font-size:0.75rem;color:var(--brand-button);text-decoration:none;">← {{ $prevQuestion->question_code }}</a>
            @endif
            <span style="font-size:0.8125rem;color:var(--text-secondary);">Q {{ $question->question_code }} · {{ $currentIndex }} of {{ $totalCount }}</span>
            @if($nextQuestion)
                <a href="{{ route('corex.compliance.rcr.question.show', [$submission->id, $nextQuestion->question_code]) }}"
                   style="font-size:0.75rem;color:var(--brand-button);text-decoration:none;">{{ $nextQuestion->question_code }} →</a>
            @endif
        </div>
        <div style="display:flex;gap:6px;align-items:center;">
            @foreach($periods as $p)
                @php $a = $answers->get($p); @endphp
                <span style="font-size:0.625rem;padding:3px 8px;border-radius:99px;font-weight:600;
                    background:{{ $a && $a->isAnswered() ? '#d1fae5' : '#fef3c7' }};
                    color:{{ $a && $a->isAnswered() ? '#065f46' : '#92400e' }};">
                    {{ strtoupper($p) }} {{ $a && $a->transposed_to_goaml_at ? '✓ transposed' : ($a && $a->isAnswered() ? '✓ answered' : '⚠ pending') }}
                </span>
            @endforeach
            <button type="button" @click="toggleFlowMode"
                    style="font-size:0.6875rem;padding:5px 12px;background:transparent;border:1px solid var(--border);border-radius:4px;color:var(--text-secondary);cursor:pointer;">
                <span x-show="!flowMode">▶ Flow mode</span><span x-show="flowMode">◀ Exit flow</span>
            </button>
        </div>
    </div>

    {{-- QUESTION BLOCK --}}
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:16px;margin-bottom:14px;">
        <div style="font-size:0.6875rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.04em;">
            {{ $section?->section_code }} · {{ $section?->title }}
        </div>
        <h1 style="font-size:1rem;font-weight:600;color:var(--text-primary);margin:6px 0 0 0;line-height:1.5;">
            <strong style="color:var(--text-muted);font-size:0.875rem;">{{ $question->question_code }}</strong>
            &nbsp;{{ $question->question_text }}
        </h1>
        @if($question->footnote)
            <p style="font-size:0.75rem;color:var(--text-muted);font-style:italic;margin:8px 0 0 0;border-left:3px solid var(--border);padding-left:10px;">
                {{ $question->footnote }}
            </p>
        @endif
        @if($question->auto_populate_hint)
            <p style="font-size:0.75rem;color:#0ea5e9;margin:8px 0 0 0;">
                💡 {{ $question->auto_populate_hint }}
            </p>
        @endif
    </div>

    {{-- PERIOD COLUMNS --}}
    <div style="display:grid;grid-template-columns:repeat({{ count($periods) }}, minmax(0, 1fr));gap:12px;margin-bottom:14px;">
        @foreach($periods as $p)
            @php $a = $answers->get($p); @endphp
            <div data-period="{{ $p }}"
                 style="background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:14px;display:flex;flex-direction:column;gap:10px;"
                 x-data="rcrPeriod({
                    submissionId: {{ $submission->id }},
                    questionId:   {{ $question->id }},
                    period:       '{{ $p }}',
                    initialValue: @json($a?->answer_value ?? ''),
                    initialFormat: @json($a?->final_answer_format ?? $question->answer_type),
                    initialTransposed: {{ $a && $a->transposed_to_goaml_at ? 'true' : 'false' }},
                    transposedAt: @json($a?->transposed_to_goaml_at?->format('H:i')),
                    saveUrl: @json($a ? route('corex.compliance.rcr.answers.save', [$submission->id, $a->id]) : ''),
                 })">
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <span style="font-size:0.625rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">{{ $periodLabel($p) }}</span>
                    @if($a && $a->is_auto_populated)
                        <span style="font-size:0.625rem;background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:99px;font-weight:600;">Auto</span>
                    @endif
                </div>

                {{-- Evidence summary block --}}
                @if($a && $a->auto_population_source_data)
                    @php $src = $a->auto_population_source_data; @endphp
                    <div style="background:var(--surface-2);border-radius:4px;padding:8px 10px;font-size:0.6875rem;color:var(--text-secondary);">
                        <div><strong>Source:</strong> {{ $src['source'] ?? '—' }}</div>
                        @if(!empty($src['pulled_at']))
                            <div style="color:var(--text-muted);font-size:0.625rem;">Pulled {{ \Carbon\Carbon::parse($src['pulled_at'])->diffForHumans() }}</div>
                        @endif
                        @if(!empty($src['error']))
                            <div style="color:#991b1b;margin-top:4px;">⚠ {{ $src['error'] }}</div>
                        @endif
                    </div>
                @endif

                {{-- Final answer input (driven by question.answer_type) --}}
                <label style="display:block;font-size:0.625rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.04em;">Final answer</label>
                @php $opts = $question->answer_options_json; @endphp
                @switch($question->answer_type)
                    @case('yes_no')
                    @case('yes_no_na')
                    @case('single_select')
                        <select data-final-answer x-model="value" @change="save"
                                style="padding:8px 10px;border:1px solid var(--border);border-radius:4px;font-size:0.875rem;background:var(--surface);">
                            <option value="">Select…</option>
                            @foreach(($opts ?? RcrAnswer::OPTIONS_YES_NO) as $opt)
                                <option value="{{ $opt }}">{{ $opt }}</option>
                            @endforeach
                        </select>
                        @break
                    @case('percentage')
                    @case('number')
                        <input data-final-answer type="number" step="{{ $question->answer_type === 'percentage' ? '0.1' : '1' }}"
                               x-model="value" @input.debounce.500ms="save"
                               style="padding:8px 10px;border:1px solid var(--border);border-radius:4px;font-size:0.875rem;background:var(--surface);">
                        @break
                    @case('multi_select')
                        <textarea data-final-answer x-model="value" @input.debounce.500ms="save" rows="2"
                                  placeholder="Comma-separated…"
                                  style="padding:8px 10px;border:1px solid var(--border);border-radius:4px;font-size:0.875rem;background:var(--surface);font-family:inherit;"></textarea>
                        @break
                    @default
                        <textarea data-final-answer x-model="value" @input.debounce.500ms="save" rows="3"
                                  style="padding:8px 10px;border:1px solid var(--border);border-radius:4px;font-size:0.875rem;background:var(--surface);font-family:inherit;"></textarea>
                @endswitch

                {{-- Big copy button --}}
                <button type="button" @click="copy"
                        data-copy-btn="{{ $p }}"
                        :class="copiedFlash ? 'rcr-copy-flash' : ''"
                        style="background:#00d4aa;color:#0a1628;font-weight:700;height:48px;border-radius:4px;border:0;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;font-size:0.875rem;">
                    <span x-show="!copiedFlash">📋 Copy to goAML</span>
                    <span x-show="copiedFlash">✓ Copied</span>
                </button>

                {{-- Transposed checkbox --}}
                <label style="display:flex;align-items:center;gap:8px;font-size:0.75rem;color:var(--text-secondary);cursor:pointer;">
                    <input type="checkbox" data-transposed-checkbox="{{ $p }}" x-model="transposed" @change="toggleTransposed">
                    <span x-show="!transposed">Pasted into goAML</span>
                    <span x-show="transposed" style="color:#065f46;font-weight:600;">✓ Pasted<span x-show="transposedAt" x-text="' at ' + transposedAt"></span></span>
                </label>
            </div>
        @endforeach
    </div>

    {{-- Keyboard shortcut hint --}}
    <div style="font-size:0.6875rem;color:var(--text-muted);text-align:center;padding:10px;background:var(--surface-2);border-radius:6px;">
        ⌨ <strong>Shortcuts:</strong>
        <span style="font-family:monospace;">c</span>=copy ·
        <span style="font-family:monospace;">1/2/3</span>=focus period ·
        <span style="font-family:monospace;">t</span>=toggle transposed ·
        <span style="font-family:monospace;">n</span>=next ·
        <span style="font-family:monospace;">p</span>=prev
    </div>
</div>

<style>
    .rcr-flow-mode .corex-sidebar { display:none !important; }
    .rcr-flow-mode .corex-app-main { margin-left:0 !important; padding:0; }
    .rcr-copy-flash { background:#00b594 !important; }
</style>

@push('scripts')
<script>
function rcrDeepView(cfg) {
    return {
        flowMode: cfg.flowMode,
        toggleFlowMode() {
            this.flowMode = !this.flowMode;
            localStorage.setItem('rcr_flow_mode', this.flowMode ? '1' : '0');
        },
        init() {
            const isTextField = (el) => el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable);
            window.addEventListener('keydown', (e) => {
                if (isTextField(e.target)) return;
                if (e.key === 'n' && cfg.nextUrl) { window.location = cfg.nextUrl; e.preventDefault(); }
                else if (e.key === 'p' && cfg.prevUrl) { window.location = cfg.prevUrl; e.preventDefault(); }
                else if (e.key === 'c') { document.querySelector('[data-copy-btn][data-period-focused]')?.click() ?? document.querySelector('[data-copy-btn]')?.click(); e.preventDefault(); }
                else if (e.key === 't') { document.querySelector('[data-transposed-checkbox][data-period-focused]')?.click() ?? document.querySelector('[data-transposed-checkbox]')?.click(); e.preventDefault(); }
                else if (['1','2','3'].includes(e.key)) {
                    const periodMap = {'1':'p1','2':'p2','3':'p3'};
                    document.querySelectorAll('[data-period]').forEach(el => el.removeAttribute('data-period-focused'));
                    document.querySelector(`[data-period="${periodMap[e.key]}"]`)?.setAttribute('data-period-focused','');
                    document.querySelector(`[data-period="${periodMap[e.key]}"] [data-final-answer]`)?.focus();
                    e.preventDefault();
                }
            });
        },
    };
}
function rcrPeriod(cfg) {
    return {
        value: cfg.initialValue,
        format: cfg.initialFormat,
        transposed: cfg.initialTransposed,
        transposedAt: cfg.transposedAt,
        copiedFlash: false,
        csrf() { return document.querySelector('meta[name=csrf-token]')?.content || ''; },
        async save() {
            if (!cfg.saveUrl) return;
            try {
                await fetch(cfg.saveUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type':'application/json',
                        'Accept':'application/json',
                        'X-HTTP-Method-Override':'PATCH',
                        'X-CSRF-TOKEN': this.csrf(),
                    },
                    body: JSON.stringify({ answer_value: this.value, status: 'in_progress' }),
                });
            } catch (e) { /* silent autosave */ }
        },
        async copy() {
            const formatted = this.formatForClipboard();
            try { await navigator.clipboard.writeText(formatted); } catch (e) { /* ignore — server still logs */ }
            this.copiedFlash = true;
            setTimeout(() => this.copiedFlash = false, 1500);
            try {
                const resp = await fetch(cfg.copyUrl, {
                    method: 'POST',
                    headers: { 'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN': this.csrf() },
                    body: JSON.stringify({ submission_id: cfg.submissionId, question_id: cfg.questionId, period_code: cfg.period }),
                });
                const data = await resp.json();
                if (data.transposed_cleared) { this.transposed = false; this.transposedAt = null; }
            } catch (e) { /* swallow */ }
        },
        async toggleTransposed() {
            try {
                const resp = await fetch(cfg.transposedUrl, {
                    method: 'POST',
                    headers: { 'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN': this.csrf() },
                    body: JSON.stringify({ submission_id: cfg.submissionId, question_id: cfg.questionId, period_code: cfg.period, transposed: this.transposed }),
                });
                const data = await resp.json();
                if (data.transposed_at) {
                    this.transposedAt = new Date(data.transposed_at).toTimeString().slice(0,5);
                }
            } catch (e) { /* swallow */ }
        },
        formatForClipboard() {
            const raw = (this.value ?? '').toString().trim();
            if (raw === '') return '';
            switch (this.format) {
                case 'yes_no':
                case 'yes_no_na':
                    return /^y|yes|true|1$/i.test(raw) ? 'Yes' : (/^n|no|false|0$/i.test(raw) ? 'No' : raw);
                case 'percentage':
                    return raw.endsWith('%') ? raw : raw + '%';
                case 'number':
                    return raw.replace(/[^\d.\-]/g, '');
                default:
                    return raw;
            }
        },
    };
}
</script>
@endpush
@endsection
