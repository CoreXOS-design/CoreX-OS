<!DOCTYPE html>
{{--
    FICA Questions & answers — the printable / downloadable form for the agent's file (.ai/specs/compliance.md).
    Rendered to PDF by FicaQuestionsAnswersDocument::pdf() and shown as-is on the print page. Document styling only
    (no app chrome). Every question, option and "not answered" comes from FicaQuestionnaire::forSubmission() — the same
    call the on-screen partial makes; no wording lives here. Needs: $submission $qa $agency $brandColor $logo
    $clientName $signature $declarationText $forPrint.
--}}
@php
    $data = is_array($submission->form_data) ? $submission->form_data : [];
    $signedBy = trim((string) data_get($data, 'personal.full_name')) ?: $clientName;
    $location = trim((string) data_get($data, 'declaration.signed_at_location'));
@endphp
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>FICA questions &amp; answers — {{ $clientName }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 9.5px; color: #1e293b; line-height: 1.4; }
        @page { size: A4; margin: 14mm 14mm 18mm 14mm; }
        @media screen { body { max-width: 182mm; margin: 0 auto; padding: 14mm 0; } }
        .letterhead { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid {{ $brandColor }}; padding-bottom: 8px; margin-bottom: 10px; }
        .letterhead img { max-height: 44px; max-width: 200px; }
        .letterhead .agency { font-size: 13px; font-weight: 700; }
        h1 { font-size: 15px; margin-bottom: 1px; }
        .sub { font-size: 9px; color: #64748b; margin-bottom: 8px; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .meta td { padding: 2px 6px 2px 0; vertical-align: top; width: 50%; }
        .meta .k { font-size: 8px; text-transform: uppercase; letter-spacing: .4px; color: #94a3b8; display: block; }
        .caution { border: 1px solid #f59e0b; background: #fffbeb; padding: 4px 6px; margin-bottom: 8px; font-size: 9px; }
        .section { background: #f1f5f9; border-top: 1.5px solid {{ $brandColor }}; padding: 3px 6px; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; margin-top: 8px; break-after: avoid; }
        .row { display: flex; gap: 10px; padding: 2.5px 6px; border-bottom: 1px solid #e2e8f0; break-inside: avoid; }
        .row .q { flex: 0 0 52%; color: #475569; }
        .row .q.follow { padding-left: 10px; border-left: 2px solid #cbd5e1; }
        .row .a { flex: 1 1 auto; color: #0f172a; word-break: break-word; }
        .none { color: #94a3b8; font-style: italic; }
        .flag { font-weight: 700; color: #b91c1c; }
        .muted { color: #94a3b8; }
        .declaration { margin-top: 10px; border: 1px solid #cbd5e1; padding: 8px; break-inside: avoid; }
        .declaration .title { font-weight: 700; font-size: 10px; margin-bottom: 4px; }
        .declaration .text { font-size: 9px; margin-bottom: 8px; }
        .sig { display: flex; align-items: flex-end; gap: 24px; }
        .sig img { max-height: 70px; max-width: 260px; border: 1px solid #cbd5e1; padding: 4px; background: #fff; }
        .sig .k { font-size: 8px; text-transform: uppercase; letter-spacing: .4px; color: #94a3b8; }
        .sig .v { font-size: 10px; }
        .gen { margin-top: 8px; font-size: 8px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="letterhead">
        <div>
            @if($logo)<img src="{{ $logo }}" alt="{{ $agency?->name }}">@else<div class="agency">{{ $agency?->name }}</div>@endif
        </div>
        <div style="text-align:right;">
            @if($logo)<div class="agency">{{ $agency?->name }}</div>@endif
        </div>
    </div>

    <h1>FICA Questions &amp; Answers</h1>
    <div class="sub">Financial Intelligence Centre Act — what the client was asked, what they answered, and their signed declaration</div>

    <table class="meta">
        <tr>
            <td><span class="k">Client / entity</span>{{ $clientName }}</td>
            <td><span class="k">FICA reference</span>FICA #{{ $submission->id }}</td>
        </tr>
        <tr>
            <td><span class="k">Entity type</span>{{ \App\Support\Compliance\FicaQuestionnaire::ENTITY_TYPES[$qa['entity_type']] ?? ucfirst($qa['entity_type']) }}</td>
            <td><span class="k">Submitted</span>{{ $submission->signed_at?->format('d M Y H:i') }} by {{ $signedBy }}</td>
        </tr>
        <tr>
            <td><span class="k">Form link sent by</span>{{ $submission->requestedBy->name ?? 'unknown' }} on {{ $submission->created_at?->format('d M Y') }}</td>
            <td><span class="k">Form wording version</span>{{ $qa['version'] }}</td>
        </tr>
    </table>

    @if($qa['caution'])
        <div class="caution">{{ $qa['caution'] }}</div>
    @endif

    @foreach($qa['sections'] as $section)
        <div class="section">{{ $section['title'] }}</div>
        @foreach($section['rows'] as $row)
            @continue($row['type'] === 'signature')
            <div class="row">
                <div class="q {{ $row['followUp'] ? 'follow' : '' }}">{{ $row['label'] }}</div>
                <div class="a">
                    @if($row['type'] === 'doc')
                        @forelse($row['docs'] as $doc)
                            <div>{{ $doc->file_name }}</div>
                        @empty
                            <span class="none">not uploaded</span>
                        @endforelse
                    @elseif(! $row['answered'])
                        <span class="none">not answered</span>
                    @elseif($row['type'] === 'list')
                        @foreach($row['items'] as $item)
                            <div>
                                @foreach($item as $part)
                                    <span class="muted">{{ $part['label'] }}</span> {{ $part['value'] }}@if(! $loop->last) <span class="muted">&middot;</span> @endif
                                @endforeach
                            </div>
                        @endforeach
                    @elseif($row['type'] === 'multi')
                        @foreach($row['list'] as $picked)
                            <div>{{ $picked }}</div>
                        @endforeach
                    @else
                        <span class="{{ $row['flag'] ? 'flag' : '' }}">{{ $row['text'] }}</span>
                    @endif
                </div>
            </div>
        @endforeach
    @endforeach

    @if(count($qa['other_answers']))
        <div class="section">Other recorded answers</div>
        @foreach($qa['other_answers'] as $o)
            <div class="row"><div class="q muted">{{ $o['key'] }}</div><div class="a">{{ $o['value'] }}</div></div>
        @endforeach
    @endif

    @if($qa['other_documents']->isNotEmpty())
        <div class="section">Other documents on this record</div>
        @foreach($qa['other_documents'] as $doc)
            <div class="row"><div class="q">{{ $doc->document_type_label }}</div><div class="a">{{ $doc->file_name }}</div></div>
        @endforeach
    @endif

    <div class="declaration">
        <div class="title">Declaration &amp; signature</div>
        <div class="text">{{ $declarationText }}</div>
        <div class="sig">
            @if($signature)
                <img src="{{ $signature }}" alt="Client signature">
            @else
                <div class="none">Signature not captured</div>
            @endif
            <div>
                <div class="k">Signed by</div><div class="v">{{ $signedBy }}</div>
                <div class="k" style="margin-top:4px;">Signed on</div><div class="v">{{ $submission->signed_at?->format('d M Y H:i') }}</div>
                @if($location !== '')
                    <div class="k" style="margin-top:4px;">Signed at</div><div class="v">{{ $location }}</div>
                @endif
            </div>
        </div>
    </div>

    <div class="gen">Generated {{ now()->format('d M Y H:i') }} from the client's online FICA submission #{{ $submission->id }}.</div>

    @if($forPrint)
        <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
    @endif
</body>
</html>
