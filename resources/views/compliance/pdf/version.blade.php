<!DOCTYPE html>
{{-- Standalone print document for an RMCP / policy version. Printed by CompliancePdf (headless Chromium):
     no app layout, no CSS variables, no remote assets — everything the page needs is in this file. --}}
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }} v{{ $version->version_number }} — {{ $agency->name }}</title>
    <style>
        @page { size: A4; margin: 20mm 18mm 22mm 18mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 10.5pt; line-height: 1.55; color: #1f2937; }
        .cover { text-align: center; padding-top: 28mm; page-break-after: always; }
        .cover img.logo { max-height: 26mm; max-width: 70mm; margin-bottom: 8mm; }
        .cover .kicker { font-size: 8.5pt; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: {{ $accent }}; }
        .cover h1 { font-size: 24pt; line-height: 1.2; margin: 5mm 0 2mm; color: #111827; }
        .cover .legal { font-size: 10pt; color: #6b7280; margin: 0; }
        .cover .ver { font-size: 12pt; margin: 8mm 0 0; color: #374151; }
        .cover .agency { font-size: 11pt; font-weight: 700; margin: 4mm 0 0; color: #111827; }
        .status { display: inline-block; margin-top: 8mm; padding: 2.5mm 6mm; border-radius: 3mm; font-size: 9.5pt; font-weight: 700; }
        .status.active { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .status.draft { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
        .status.superseded { background: #f3f4f6; color: #4b5563; border: 1px solid #d1d5db; }
        .toc { text-align: left; max-width: 120mm; margin: 14mm auto 0; font-size: 9.5pt; color: #374151; }
        .toc h3 { font-size: 8.5pt; letter-spacing: 1px; text-transform: uppercase; color: #6b7280; margin: 0 0 2mm; }
        .toc div { padding: 0.8mm 0; border-bottom: 1px dotted #d1d5db; }
        h2.section { font-size: 13pt; margin: 0 0 3mm; padding-bottom: 1.5mm; border-bottom: 2px solid {{ $accent }}; color: #111827; page-break-after: avoid; }
        .section-block { margin-bottom: 9mm; }
        .body h1, .body h2, .body h3, .body h4 { color: #111827; margin: 5mm 0 2mm; page-break-after: avoid; }
        .body h3 { font-size: 11.5pt; } .body h4 { font-size: 10.5pt; }
        .body p { margin: 0 0 2.5mm; }
        .body ul, .body ol { margin: 0 0 3mm; padding-left: 6mm; }
        .body li { margin-bottom: 1mm; }
        .body table { width: 100%; border-collapse: collapse; margin: 2mm 0 4mm; font-size: 9.5pt; }
        .body th, .body td { border: 1px solid #d1d5db; padding: 1.5mm 2.5mm; text-align: left; vertical-align: top; }
        .body th { background: #f3f4f6; }
        .body tr { page-break-inside: avoid; }
        .approval { margin-top: 10mm; padding-top: 4mm; border-top: 2px solid {{ $accent }}; page-break-inside: avoid; font-size: 10pt; }
        .approval table { width: 100%; border-collapse: collapse; }
        .approval td { vertical-align: top; width: 50%; padding: 0 0 1mm; }
    </style>
</head>
<body>
    <div class="cover">
        @if($logo)<img class="logo" src="{{ $logo }}" alt="">@endif
        <div class="kicker">{{ $kicker }}</div>
        <h1>{{ $title }}</h1>
        @if($legalLine)<p class="legal">{{ $legalLine }}</p>@endif
        <p class="ver">Version {{ $version->version_number }}</p>
        <p class="agency">{{ $variables['agency.name'] ?? $agency->name }}</p>

        @if($version->status === 'active')
            <div class="status active">Active — approved on {{ $version->approved_at?->format('d F Y') }} by {{ $version->approver?->name ?? 'Unknown' }}</div>
        @elseif($version->status === 'draft')
            <div class="status draft">Draft — not yet approved</div>
        @else
            <div class="status superseded">Superseded{{ $version->superseded_at ? ' on ' . $version->superseded_at->format('d F Y') : '' }}</div>
        @endif

        @if($version->sections->isNotEmpty())
        <div class="toc">
            <h3>Contents</h3>
            @foreach($version->sections as $section)
                <div>{{ $section->section_number }}. {{ $section->title }}</div>
            @endforeach
        </div>
        @endif
    </div>

    @foreach($version->sections as $section)
    <div class="section-block">
        <h2 class="section">{{ $section->section_number }}. {{ $section->title }}</h2>
        <div class="body">{!! $section->renderedBody($variables) !!}</div>
    </div>
    @endforeach

    @if($version->approved_at)
    <div class="approval">
        <table>
            <tr>
                <td>
                    <strong>Approved by:</strong> {{ $version->approver?->name }}<br>
                    <strong>Title:</strong> {{ $version->approver_title }}
                </td>
                <td>
                    <strong>Approved on:</strong> {{ $version->approved_at->format('d F Y') }}<br>
                    <strong>Effective from:</strong> {{ $version->effective_from?->format('d F Y') }}<br>
                    <strong>Next review due:</strong> {{ $version->next_review_due?->format('d F Y') }}
                </td>
            </tr>
        </table>
    </div>
    @endif
</body>
</html>
