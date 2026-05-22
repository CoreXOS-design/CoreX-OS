@extends('layouts.corex-app')

@section('corex-content')
@php
    use App\Models\Compliance\Rcr\RcrSubmission;
    $statusBadge = function (string $s): array {
        return match ($s) {
            RcrSubmission::STATUS_DRAFT                  => ['Draft',                  '#fef3c7', '#92400e'],
            RcrSubmission::STATUS_IN_REVIEW              => ['In Review',              '#dbeafe', '#1e40af'],
            RcrSubmission::STATUS_APPROVED_FOR_SUBMISSION => ['Approved — ready to submit', '#d1fae5', '#065f46'],
            RcrSubmission::STATUS_SUBMITTED              => ['Submitted',              '#d1fae5', '#065f46'],
            RcrSubmission::STATUS_LOCKED                 => ['Locked',                 '#e5e7eb', '#374151'],
            default                                       => ['Unknown',               '#f1f5f9', '#374151'],
        };
    };
    $active = $submissions->first(fn ($s) => in_array($s->status, RcrSubmission::EDITABLE_STATUSES, true));
@endphp
<div style="max-width:1100px;margin:0 auto;padding:0 20px;">

    <div style="margin-bottom:14px;">
        <h1 style="font-size:1.25rem;font-weight:600;color:var(--text-primary);margin:0;">Risk &amp; Compliance Returns (RCR)</h1>
        <p style="font-size:0.8125rem;color:var(--text-muted);margin:4px 0 0 0;">
            FIC Directive 11 of 2026 — submit by 31 July 2026 via the FIC goAML platform. CoreX prepares your answers; you transpose into goAML.
        </p>
    </div>

    @if (session('status'))
        <div style="background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;border-radius:6px;padding:10px 14px;font-size:0.8125rem;margin-bottom:14px;">
            {{ session('status') }}
        </div>
    @endif

    @if($active)
        @php [$lbl, $bg, $fg] = $statusBadge($active->status); $days = $active->daysToDeadline(); @endphp
        <div class="ds-status-card mb-4" style="border-left:4px solid {{ $days <= 7 ? '#dc2626' : '#0ea5e9' }};">
            <div class="flex items-start justify-between gap-4">
                <div style="flex:1;">
                    <h2 class="ds-section-header" style="margin:0 0 6px 0;">
                        Active 2026 Submission · {{ $active->questionnaire->title }}
                    </h2>
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:0.8125rem;">
                        <span style="display:inline-block;padding:3px 10px;border-radius:99px;background:{{ $bg }};color:{{ $fg }};font-weight:600;font-size:0.75rem;">{{ $lbl }}</span>
                        <span style="color:var(--text-muted);">Deadline: {{ $active->submission_deadline->format('j F Y') }}</span>
                        <span style="color:{{ $days <= 7 ? '#dc2626' : 'var(--text-secondary)' }};font-weight:{{ $days <= 7 ? '600' : '400' }};">
                            {{ $days < 0 ? abs($days) . ' day(s) OVERDUE' : ($days === 0 ? 'Due today' : $days . ' day(s) remaining') }}
                        </span>
                    </div>
                </div>
                <a href="{{ route('corex.compliance.rcr.show', $active->id) }}" class="corex-btn-primary" style="white-space:nowrap;">Continue →</a>
            </div>
        </div>
    @endif

    <div style="background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:14px;margin-bottom:14px;">
        <h2 class="ds-section-header" style="margin:0 0 10px 0;">Start a new submission</h2>
        <form method="POST" action="{{ route('corex.compliance.rcr.store') }}" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            @csrf
            <select name="questionnaire_id" required
                    style="flex:1;min-width:280px;padding:8px 10px;border:1px solid var(--border);border-radius:4px;font-size:0.8125rem;">
                @foreach($availableQuestionnaires as $aq)
                    <option value="{{ $aq->id }}">{{ $aq->title }} — due {{ $aq->submission_deadline->format('j M Y') }}</option>
                @endforeach
            </select>
            <button type="submit" class="corex-btn-primary" style="font-size:0.8125rem;padding:8px 16px;">Start submission</button>
        </form>
        <p style="margin:8px 0 0 0;font-size:0.6875rem;color:var(--text-muted);">
            Starting a new submission auto-populates known data (FICA officers, RMCP status, transaction counts) so you only fill in what CoreX can't infer.
        </p>
    </div>

    @if($submissions->isEmpty())
        <div style="padding:24px;text-align:center;background:var(--surface);border:1px dashed var(--border);border-radius:6px;color:var(--text-muted);font-size:0.875rem;">
            No RCR submissions yet. Start one above.
        </div>
    @else
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:6px;overflow:hidden;">
            <table style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                <thead>
                    <tr style="background:var(--surface-2);color:var(--text-muted);font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.04em;">
                        <th style="text-align:left;padding:10px 12px;">Questionnaire</th>
                        <th style="text-align:left;padding:10px 12px;">Period</th>
                        <th style="text-align:left;padding:10px 12px;">Deadline</th>
                        <th style="text-align:left;padding:10px 12px;">Status</th>
                        <th style="text-align:left;padding:10px 12px;">Assigned to</th>
                        <th style="text-align:right;padding:10px 12px;"></th>
                    </tr>
                </thead>
                <tbody>
                @foreach($submissions as $s)
                    @php [$lbl, $bg, $fg] = $statusBadge($s->status); @endphp
                    <tr style="border-top:1px solid var(--border);">
                        <td style="padding:10px 12px;color:var(--text-primary);font-weight:500;">{{ $s->questionnaire?->title }}</td>
                        <td style="padding:10px 12px;color:var(--text-secondary);font-size:0.75rem;">
                            {{ $s->reporting_period_from->format('j M Y') }} → {{ $s->reporting_period_to->format('j M Y') }}
                        </td>
                        <td style="padding:10px 12px;color:var(--text-secondary);font-size:0.75rem;">{{ $s->submission_deadline->format('j M Y') }}</td>
                        <td style="padding:10px 12px;">
                            <span style="display:inline-block;padding:3px 8px;border-radius:99px;font-size:0.6875rem;font-weight:600;background:{{ $bg }};color:{{ $fg }};">{{ $lbl }}</span>
                        </td>
                        <td style="padding:10px 12px;color:var(--text-secondary);font-size:0.75rem;">{{ $s->assignedCo?->name ?? '—' }}</td>
                        <td style="padding:10px 12px;text-align:right;">
                            <a href="{{ route('corex.compliance.rcr.show', $s->id) }}" style="font-size:0.6875rem;color:var(--brand-button);text-decoration:none;">Open →</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

</div>
@endsection
