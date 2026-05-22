@extends('layouts.corex-app')

@section('corex-content')
<div style="max-width:1100px;margin:0 auto;padding:0 20px;">

    <div style="margin-bottom:14px;">
        <a href="{{ route('corex.admin.rcr.questionnaires.index') }}" style="font-size:0.75rem;color:var(--text-muted);text-decoration:none;">← Back</a>
        <h1 style="font-size:1.25rem;font-weight:600;margin:6px 0 0 0;">{{ $questionnaire->title }}</h1>
        <p style="font-size:0.75rem;color:var(--text-muted);margin:4px 0 0 0;">
            {{ $questionnaire->directive_reference }} · deadline {{ $questionnaire->submission_deadline->format('j F Y') }}
        </p>
    </div>

    @if (session('status'))
        <div style="background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;border-radius:6px;padding:10px 14px;font-size:0.8125rem;margin-bottom:14px;">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:6px;padding:10px 14px;font-size:0.8125rem;margin-bottom:14px;">{{ $errors->first('csv_file') }}</div>
    @endif

    <div style="background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:14px;margin-bottom:14px;">
        <h2 class="ds-section-header" style="margin:0 0 10px 0;">Import questions from CSV</h2>
        <p style="font-size:0.75rem;color:var(--text-muted);margin:0 0 10px 0;">
            CSV columns (header row required): <code>section_code, question_code, question_text, answer_type, answer_options, is_required, auto_population_source, help_text</code>.
            <code>answer_options</code> is pipe-separated for select questions. <code>is_required</code> accepts 1/0/yes/no/true/false.
            Auto-population sources documented in <code>EvidenceGatheringService</code>.
        </p>
        <form method="POST" action="{{ route('corex.admin.rcr.questionnaires.import-csv', $questionnaire->id) }}"
              enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            @csrf
            <input type="file" name="csv_file" accept=".csv,text/csv" required
                   style="font-size:0.8125rem;">
            <select name="mode" style="padding:6px 10px;border:1px solid var(--border);border-radius:4px;font-size:0.8125rem;">
                <option value="append">Append (don't touch existing)</option>
                <option value="replace">Replace (drop all then import)</option>
            </select>
            <button type="submit" class="corex-btn-primary" style="font-size:0.8125rem;padding:7px 14px;">Import</button>
        </form>
    </div>

    <div style="background:var(--surface);border:1px solid var(--border);border-radius:6px;overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;font-size:0.75rem;">
            <thead>
                <tr style="background:var(--surface-2);color:var(--text-muted);font-size:0.625rem;text-transform:uppercase;letter-spacing:0.04em;">
                    <th style="text-align:left;padding:8px 10px;">Section</th>
                    <th style="text-align:left;padding:8px 10px;">Code</th>
                    <th style="text-align:left;padding:8px 10px;">Question</th>
                    <th style="text-align:left;padding:8px 10px;">Type</th>
                    <th style="text-align:left;padding:8px 10px;">Auto source</th>
                </tr>
            </thead>
            <tbody>
                @foreach($questionnaire->sections as $section)
                    @foreach($section->questions as $q)
                        <tr style="border-top:1px solid var(--border);">
                            <td style="padding:6px 10px;color:var(--text-muted);">{{ $section->section_code }}</td>
                            <td style="padding:6px 10px;font-family:monospace;color:var(--text-primary);">{{ $q->question_code }}</td>
                            <td style="padding:6px 10px;color:var(--text-primary);">{{ \Illuminate\Support\Str::limit($q->question_text, 100) }}</td>
                            <td style="padding:6px 10px;color:var(--text-secondary);">{{ $q->answer_type }}</td>
                            <td style="padding:6px 10px;font-family:monospace;color:var(--text-muted);font-size:0.6875rem;">{{ $q->auto_population_source ?? '—' }}</td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>

</div>
@endsection
