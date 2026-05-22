@extends('layouts.corex-app')

@section('corex-content')
<div style="max-width:1100px;margin:0 auto;padding:0 20px;">

    <div style="margin-bottom:14px;">
        <h1 style="font-size:1.25rem;font-weight:600;margin:0;">RCR Questionnaires (admin)</h1>
        <p style="font-size:0.8125rem;color:var(--text-muted);margin:4px 0 0 0;">
            Manage the FIC questionnaire templates. Upload the FIC's question PDF as CSV to populate the full question set.
        </p>
    </div>

    @if (session('status'))
        <div style="background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;border-radius:6px;padding:10px 14px;font-size:0.8125rem;margin-bottom:14px;">{{ session('status') }}</div>
    @endif

    <div style="background:var(--surface);border:1px solid var(--border);border-radius:6px;overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
            <thead>
                <tr style="background:var(--surface-2);color:var(--text-muted);font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.04em;">
                    <th style="text-align:left;padding:10px 12px;">Key</th>
                    <th style="text-align:left;padding:10px 12px;">Title</th>
                    <th style="text-align:left;padding:10px 12px;">Directive</th>
                    <th style="text-align:right;padding:10px 12px;">Sections</th>
                    <th style="text-align:right;padding:10px 12px;">Questions</th>
                    <th style="text-align:left;padding:10px 12px;">Deadline</th>
                    <th style="text-align:right;padding:10px 12px;"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($questionnaires as $q)
                    <tr style="border-top:1px solid var(--border);">
                        <td style="padding:10px 12px;color:var(--text-muted);font-family:monospace;font-size:0.75rem;">{{ $q->key }}</td>
                        <td style="padding:10px 12px;color:var(--text-primary);font-weight:500;">{{ $q->title }}</td>
                        <td style="padding:10px 12px;color:var(--text-secondary);font-size:0.75rem;">{{ $q->directive_reference }}</td>
                        <td style="padding:10px 12px;text-align:right;">{{ $q->sections_count }}</td>
                        <td style="padding:10px 12px;text-align:right;">{{ $q->questions_count }}</td>
                        <td style="padding:10px 12px;color:var(--text-secondary);font-size:0.75rem;">{{ $q->submission_deadline->format('j M Y') }}</td>
                        <td style="padding:10px 12px;text-align:right;">
                            <a href="{{ route('corex.admin.rcr.questionnaires.show', $q->id) }}" style="font-size:0.6875rem;color:var(--brand-button);text-decoration:none;">Open →</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

</div>
@endsection
