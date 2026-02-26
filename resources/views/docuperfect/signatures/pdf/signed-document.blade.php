<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; size: A4 portrait; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Helvetica, Arial, sans-serif; }

        .page-container {
            position: relative;
            width: 210mm;
            height: 297mm;
            overflow: hidden;
            page-break-after: always;
        }
        .page-container:last-child {
            page-break-after: auto;
        }

        .page-image {
            width: 210mm;
            height: 297mm;
            display: block;
        }

        .signature-overlay {
            position: absolute;
        }

        .signature-image {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .signature-label {
            position: absolute;
            bottom: -12px;
            left: 0;
            width: 100%;
            text-align: center;
            font-size: 6px;
            color: #555;
            white-space: nowrap;
            overflow: hidden;
        }

        .typed-signature {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Brush Script MT', 'Dancing Script', cursive, serif;
            font-size: 18px;
            color: #1a1a8a;
        }

        .wet-ink-stamp {
            width: 100%;
            height: 100%;
            border: 2px solid #276749;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background-color: rgba(240, 255, 244, 0.8);
        }
        .wet-ink-stamp-text {
            font-size: 8px;
            font-weight: bold;
            color: #276749;
            text-align: center;
        }

        .field-overlay {
            position: absolute;
            overflow: hidden;
            color: #1a1a1a;
            font-family: Helvetica, Arial, sans-serif;
            line-height: 1.2;
            word-wrap: break-word;
        }

        /* Audit Certificate Styles */
        .audit-page {
            page-break-before: always;
            padding: 40px 50px;
            font-size: 11px;
            color: #333;
            line-height: 1.5;
        }
        .audit-header {
            text-align: center;
            border: 2px solid #1a365d;
            padding: 20px;
            margin-bottom: 30px;
            background-color: #f7fafc;
        }
        .audit-header h1 {
            font-size: 18px;
            color: #1a365d;
            margin-bottom: 5px;
        }
        .audit-header p {
            font-size: 10px;
            color: #666;
        }
        .audit-section {
            margin-bottom: 20px;
        }
        .audit-section-title {
            font-size: 12px;
            font-weight: bold;
            color: #1a365d;
            border-bottom: 1px solid #1a365d;
            padding-bottom: 4px;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .audit-doc-info {
            margin-bottom: 15px;
        }
        .audit-doc-info table {
            width: 100%;
            border-collapse: collapse;
        }
        .audit-doc-info td {
            padding: 3px 8px;
            font-size: 10px;
        }
        .audit-doc-info td:first-child {
            width: 140px;
            font-weight: bold;
            color: #555;
        }
        .party-row {
            margin-bottom: 12px;
            padding: 10px;
            border: 1px solid #e0e0e0;
            background-color: #fafafa;
        }
        .party-name {
            font-weight: bold;
            font-size: 11px;
        }
        .party-role {
            text-transform: uppercase;
            font-size: 9px;
            color: #888;
            letter-spacing: 1px;
        }
        .party-detail {
            font-size: 9px;
            color: #555;
            margin-top: 2px;
        }
        .audit-trail-item {
            font-size: 9px;
            padding: 3px 0;
            border-bottom: 1px dotted #e0e0e0;
        }
        .audit-trail-time {
            display: inline-block;
            width: 120px;
            color: #888;
            font-size: 9px;
        }
        .audit-trail-desc {
            color: #333;
        }
        .audit-footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 2px solid #1a365d;
            font-size: 9px;
            color: #666;
            text-align: center;
        }
        .hash-display {
            font-family: 'Courier New', monospace;
            font-size: 8px;
            color: #888;
            word-break: break-all;
        }
        .verified-badge {
            color: #276749;
            font-weight: bold;
        }
    </style>
</head>
<body>
    {{-- Document pages with signature overlays --}}
    @foreach($pages as $i => $page)
        <div class="page-container">
            @if($page['image_base64'])
                <img class="page-image" src="{{ $page['image_base64'] }}" alt="Page {{ $i + 1 }}">
            @else
                <div style="width: 100%; height: 800px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; color: #999;">
                    Page {{ $i + 1 }} — Image not available
                </div>
            @endif

            {{-- Field value overlays --}}
            @if(!empty($page['fields']))
                @foreach($page['fields'] as $field)
                    <div class="field-overlay" style="left: {{ $field['x'] }}%; top: {{ $field['y'] }}%; width: {{ $field['w'] }}%; height: {{ $field['h'] }}%; font-size: {{ $field['fontSize'] ?? 10 }}px;{{ !empty($field['bold']) ? ' font-weight: bold;' : '' }}{{ !empty($field['underline']) ? ' text-decoration: underline;' : '' }}{{ !empty($field['solidBackground']) ? ' background-color: #ffffff;' : '' }}">
                        {{ $field['value'] }}
                    </div>
                @endforeach
            @endif

            {{-- Signature marker overlays --}}
            @foreach($page['markers'] as $marker)
                <div class="signature-overlay" style="left: {{ $marker['x'] }}%; top: {{ $marker['y'] }}%; width: {{ $marker['w'] }}%; height: {{ $marker['h'] }}%;">
                    @if($marker['has_signature'] && $marker['signature_type'] === 'drawn' && $marker['signature_data'])
                        <img class="signature-image" src="{{ $marker['signature_data'] }}" alt="Signature">
                        <div class="signature-label">
                            Signed: {{ $marker['signer_name'] }} &mdash; {{ $marker['signed_at']?->format('d M Y H:i') }}
                        </div>
                    @elseif($marker['has_signature'] && $marker['signature_type'] === 'typed')
                        <div class="typed-signature">{{ $marker['signer_name'] }}</div>
                        <div class="signature-label">
                            Signed: {{ $marker['signer_name'] }} &mdash; {{ $marker['signed_at']?->format('d M Y H:i') }}
                        </div>
                    @elseif($marker['is_wet_ink'] && $marker['wet_ink_approved'])
                        <div class="wet-ink-stamp">
                            <div class="wet-ink-stamp-text">WET INK</div>
                            <div class="wet-ink-stamp-text">VERIFIED</div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach

    {{-- Audit Certificate (included when $includeAuditCert is true) --}}
    @if(!empty($includeAuditCert))
        @include('docuperfect.signatures.pdf.audit-certificate', [
            'template' => $template,
            'document' => $document,
            'parties' => $parties,
            'progress' => $progress,
            'auditLogs' => $auditLogs,
            'documentHash' => $documentHash,
        ])
    @endif
</body>
</html>
