@extends('layouts.corex-app')

@section('corex-content')
@php
    use App\Models\Compliance\Rcr\RcrAnswer;
    use App\Models\Compliance\Rcr\RcrSubmission;
    $isEditable = $submission->isEditable();
    $days = $submission->daysToDeadline();
    $statusBg = match ($submission->status) {
        RcrSubmission::STATUS_DRAFT                  => '#fef3c7',
        RcrSubmission::STATUS_IN_REVIEW              => '#dbeafe',
        RcrSubmission::STATUS_APPROVED_FOR_SUBMISSION => '#d1fae5',
        RcrSubmission::STATUS_SUBMITTED              => '#d1fae5',
        RcrSubmission::STATUS_LOCKED                 => '#e5e7eb',
        default                                       => '#f1f5f9',
    };
    $statusFg = match ($submission->status) {
        RcrSubmission::STATUS_DRAFT                  => '#92400e',
        RcrSubmission::STATUS_IN_REVIEW              => '#1e40af',
        default                                       => '#065f46',
    };
@endphp
<div style="max-width:1280px;margin:0 auto;padding:0 20px;" x-data="rcrShow({
    submissionId: {{ $submission->id }},
})">

    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:14px;flex-wrap:wrap;">
        <div>
            <a href="{{ route('corex.compliance.rcr.index') }}" style="font-size:0.75rem;color:var(--text-muted);text-decoration:none;">← Back to RCR list</a>
            <h1 style="font-size:1.25rem;font-weight:600;color:var(--text-primary);margin:6px 0 0 0;">{{ $submission->questionnaire->title }}</h1>
            <p style="font-size:0.75rem;color:var(--text-muted);margin:4px 0 0 0;">
                Reporting period {{ $submission->reporting_period_from->format('j M Y') }} → {{ $submission->reporting_period_to->format('j M Y') }} ·
                <span style="color:{{ $days <= 7 ? '#dc2626' : 'inherit' }};font-weight:{{ $days <= 7 ? '600' : '400' }};">
                    {{ $days < 0 ? abs($days) . ' day(s) OVERDUE' : 'Deadline in ' . $days . ' day(s)' }}
                </span>
            </p>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <span style="display:inline-block;padding:4px 12px;border-radius:99px;font-size:0.6875rem;font-weight:600;background:{{ $statusBg }};color:{{ $statusFg }};">
                {{ ucwords(str_replace('_', ' ', $submission->status)) }}
            </span>
            @if($isEditable)
                <form method="POST" action="{{ route('corex.compliance.rcr.auto-populate', $submission->id) }}" style="display:inline;">
                    @csrf
                    <button type="submit" class="corex-btn-outline" style="font-size:0.75rem;padding:6px 12px;">Auto-populate New Data</button>
                </form>
            @endif
            <a href="{{ route('corex.compliance.rcr.export', [$submission->id, 'pdf']) }}" class="corex-btn-outline" style="font-size:0.75rem;padding:6px 12px;text-decoration:none;">Export PDF</a>
            <a href="{{ route('corex.compliance.rcr.export', [$submission->id, 'csv']) }}" class="corex-btn-outline" style="font-size:0.75rem;padding:6px 12px;text-decoration:none;">CSV</a>
            <a href="{{ route('corex.compliance.rcr.export', [$submission->id, 'json']) }}" class="corex-btn-outline" style="font-size:0.75rem;padding:6px 12px;text-decoration:none;">JSON</a>
        </div>
    </div>

    @if (session('status'))
        <div style="background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;border-radius:6px;padding:10px 14px;font-size:0.8125rem;margin-bottom:14px;">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:6px;padding:10px 14px;font-size:0.8125rem;margin-bottom:14px;">{{ $errors->first() }}</div>
    @endif

    {{-- Progress bar --}}
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:14px;margin-bottom:14px;">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;">
            <div>
                <div style="font-size:0.625rem;color:var(--text-muted);text-transform:uppercase;">Total questions</div>
                <div style="font-size:1.25rem;font-weight:700;">{{ $stats['total'] }}</div>
            </div>
            <div>
                <div style="font-size:0.625rem;color:var(--text-muted);text-transform:uppercase;">Auto-filled</div>
                <div style="font-size:1.25rem;font-weight:700;color:#0ea5e9;">{{ $stats['auto_filled'] }}</div>
            </div>
            <div>
                <div style="font-size:0.625rem;color:var(--text-muted);text-transform:uppercase;">Answered</div>
                <div style="font-size:1.25rem;font-weight:700;color:#16a34a;">{{ $stats['answered'] }}</div>
            </div>
            <div>
                <div style="font-size:0.625rem;color:var(--text-muted);text-transform:uppercase;">Unanswered</div>
                <div style="font-size:1.25rem;font-weight:700;color:{{ $stats['unanswered'] > 0 ? '#dc2626' : 'var(--text-primary)' }};">{{ $stats['unanswered'] }}</div>
            </div>
            <div>
                <div style="font-size:0.625rem;color:var(--text-muted);text-transform:uppercase;">Progress</div>
                <div style="font-size:1.25rem;font-weight:700;">{{ $stats['progress_pct'] }}%</div>
            </div>
        </div>
        <div style="margin-top:10px;background:var(--surface-2);height:8px;border-radius:4px;overflow:hidden;">
            <div style="background:#0ea5e9;height:100%;width:{{ $stats['progress_pct'] }}%;"></div>
        </div>
    </div>

    {{-- Sections + questions --}}
    <div style="display:grid;grid-template-columns:240px 1fr;gap:16px;">
        {{-- Sidebar — section nav --}}
        <div style="position:sticky;top:14px;align-self:flex-start;background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:10px;">
            <h3 style="font-size:0.6875rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin:0 0 8px 0;">Sections</h3>
            @foreach($submission->questionnaire->sections as $section)
                @php
                    $secQs = $section->questions;
                    $secAnsweredOrAuto = $secQs->filter(fn ($q) => ($answerById[$q->id] ?? null)?->status &&
                        in_array($answerById[$q->id]->status, [RcrAnswer::STATUS_ANSWERED, RcrAnswer::STATUS_AUTO_FILLED, RcrAnswer::STATUS_REVIEWED, RcrAnswer::STATUS_APPROVED], true))->count();
                    $color = $secAnsweredOrAuto === $secQs->count() && $secQs->count() > 0
                        ? '#16a34a' : ($secAnsweredOrAuto > 0 ? '#d97706' : 'var(--text-muted)');
                @endphp
                <a href="#section-{{ $section->section_code }}" style="display:block;padding:6px 8px;font-size:0.75rem;color:var(--text-primary);text-decoration:none;border-radius:4px;">
                    <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:{{ $color }};margin-right:6px;vertical-align:middle;"></span>
                    {{ $section->section_code }}. {{ \Illuminate\Support\Str::limit($section->title, 32) }}
                    <span style="float:right;color:var(--text-muted);font-size:0.625rem;">{{ $secAnsweredOrAuto }}/{{ $secQs->count() }}</span>
                </a>
            @endforeach
        </div>

        {{-- Centre — questions --}}
        <div>
            @foreach($submission->questionnaire->sections as $section)
                <div id="section-{{ $section->section_code }}" style="background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:14px;margin-bottom:14px;">
                    <h2 class="ds-section-header" style="margin:0 0 12px 0;">{{ $section->section_code }}. {{ $section->title }}</h2>
                    @foreach($section->questions as $question)
                        @php $a = $answerById[$question->id] ?? null; @endphp
                        <div style="padding:12px 0;border-top:{{ $loop->first ? '0' : '1px solid var(--border)' }};" x-data="rcrAnswer({
                            questionId: {{ $question->id }},
                            answerId: {{ $a?->id ?? 'null' }},
                            initialValue: @js($a?->answer_value ?? ''),
                            initialStatus: @js($a?->status ?? 'unanswered'),
                            isAutoPopulated: {{ $a?->is_auto_populated ? 'true' : 'false' }},
                            manuallyEdited: {{ $a?->manually_edited ? 'true' : 'false' }},
                            saveUrl: @js($a ? route('corex.compliance.rcr.answers.save', [$submission->id, $a->id]) : null),
                        })">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:6px;">
                                <div style="flex:1;">
                                    <div style="font-size:0.625rem;color:var(--text-muted);font-weight:600;">{{ $question->question_code }} @if($question->is_required)<span style="color:#dc2626;">*</span>@endif</div>
                                    <div style="font-size:0.875rem;color:var(--text-primary);margin-top:2px;">{{ $question->question_text }}</div>
                                    @if($question->help_text)
                                        <div style="font-size:0.6875rem;color:var(--text-muted);margin-top:4px;font-style:italic;">{{ $question->help_text }}</div>
                                    @endif
                                </div>
                                <div>
                                    <span x-show="status==='auto_filled'" style="font-size:0.625rem;background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:99px;font-weight:600;">Auto-filled</span>
                                    <span x-show="status==='in_progress'" style="font-size:0.625rem;background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:99px;font-weight:600;">In progress</span>
                                    <span x-show="status==='answered'" style="font-size:0.625rem;background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:99px;font-weight:600;">Answered</span>
                                    <span x-show="status==='unanswered'" style="font-size:0.625rem;background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:99px;font-weight:600;">Unanswered</span>
                                </div>
                            </div>

                            @if($a && $a->is_auto_populated && !$a->manually_edited)
                                @php $sourceData = $a->auto_population_source_data ?? []; @endphp
                                <div style="background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;border-radius:4px;padding:8px 10px;margin-bottom:8px;font-size:0.75rem;">
                                    <strong>Auto-filled from {{ $sourceData['source'] ?? 'CoreX' }}</strong>
                                    @if(!empty($sourceData['pulled_at']))
                                        <span style="color:#1e3a8a;font-size:0.6875rem;"> · pulled {{ \Carbon\Carbon::parse($sourceData['pulled_at'])->diffForHumans() }}</span>
                                    @endif
                                    @if(!empty($sourceData['error']))
                                        <div style="color:#991b1b;font-size:0.6875rem;margin-top:4px;">{{ $sourceData['error'] }}</div>
                                    @endif
                                </div>
                            @endif

                            @if($isEditable)
                                <textarea x-model="value" @input.debounce.800ms="save()" rows="2" maxlength="65000"
                                          style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:4px;font-size:0.8125rem;font-family:inherit;"
                                          placeholder="Type your answer…"></textarea>
                                <div style="display:flex;justify-content:flex-end;gap:6px;margin-top:6px;">
                                    <button type="button" @click="markAnswered" :disabled="!value"
                                            style="font-size:0.6875rem;padding:5px 12px;background:var(--brand-button);color:#fff;border:0;border-radius:4px;cursor:pointer;font-weight:600;">
                                        Mark as answered
                                    </button>
                                </div>
                            @else
                                <div style="padding:10px;background:var(--surface-2);border-radius:4px;font-size:0.8125rem;color:var(--text-primary);white-space:pre-wrap;">{{ $a?->answer_value ?: '(unanswered)' }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach

            {{-- Submit action --}}
            @if($isEditable)
                <div style="background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:14px;margin-bottom:30px;">
                    <h2 class="ds-section-header" style="margin:0 0 10px 0;">Submit RCR</h2>
                    <p style="font-size:0.8125rem;color:var(--text-secondary);margin:0 0 12px 0;">
                        Submitting locks this RCR. The system takes an immutable snapshot for FIC audit retention and generates PDF/CSV/JSON exports. After submission, all edits require a new revision.
                    </p>
                    <form method="POST" action="{{ route('corex.compliance.rcr.submit', $submission->id) }}">
                        @csrf
                        <label style="display:block;font-size:0.6875rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">FIC goAML reference number (optional, enter after submission to goAML)</label>
                        <input type="text" name="submitted_to_platform_reference" maxlength="200"
                               style="width:320px;padding:6px 10px;border:1px solid var(--border);border-radius:4px;font-size:0.8125rem;margin-bottom:10px;">
                        <label style="display:flex;align-items:center;gap:6px;font-size:0.75rem;color:var(--text-secondary);margin-bottom:10px;">
                            <input type="checkbox" name="confirmed" value="1" required>
                            I confirm I'm about to transpose these answers into the FIC goAML platform.
                        </label>
                        <button type="submit" class="corex-btn-primary" style="font-size:0.875rem;padding:8px 18px;">
                            Approve &amp; Submit RCR
                        </button>
                    </form>
                </div>
            @endif
        </div>
    </div>
</div>

@push('scripts')
<script>
function rcrShow(cfg) {
    return {
        submissionId: cfg.submissionId,
    };
}
function rcrAnswer(cfg) {
    return {
        questionId: cfg.questionId,
        answerId: cfg.answerId,
        value: cfg.initialValue,
        status: cfg.initialStatus,
        isAutoPopulated: cfg.isAutoPopulated,
        manuallyEdited: cfg.manuallyEdited,
        saveUrl: cfg.saveUrl,
        async save() {
            if (!this.saveUrl) return;
            try {
                const resp = await fetch(this.saveUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-HTTP-Method-Override': 'PATCH',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    },
                    body: JSON.stringify({
                        answer_value: this.value,
                        status: 'in_progress',
                    }),
                });
                if (resp.ok) { this.status = 'in_progress'; this.manuallyEdited = true; }
            } catch (e) { /* silent */ }
        },
        async markAnswered() {
            if (!this.saveUrl) return;
            const resp = await fetch(this.saveUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-HTTP-Method-Override': 'PATCH',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                },
                body: JSON.stringify({
                    answer_value: this.value,
                    status: 'answered',
                }),
            });
            if (resp.ok) { this.status = 'answered'; this.manuallyEdited = true; }
        },
    };
}
</script>
@endpush
@endsection
