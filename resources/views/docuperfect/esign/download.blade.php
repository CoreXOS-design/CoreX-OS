<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $document->name ?? 'Document' }} — CoreX</title>
    <link href="/css/corex-document.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 0;
            background: #f1f5f9;
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
        }

        /* Document container — A4 centred page appearance */
        .download-document-area {
            max-width: 210mm;
            margin: 24px auto 100px auto;
            background: white;
            box-shadow: 0 2px 12px rgba(0,0,0,0.12);
            border-radius: 4px;
            overflow: hidden;
        }

        /* Bottom sticky action bar */
        .download-action-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-top: 1px solid #e2e8f0;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            z-index: 50;
            box-shadow: 0 -2px 8px rgba(0,0,0,0.06);
        }

        .btn-print {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 24px;
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
            background: white;
            border: 1.5px solid #cbd5e1;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-print:hover {
            background: #f8fafc;
            border-color: #94a3b8;
        }

        .btn-download {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 24px;
            font-size: 14px;
            font-weight: 600;
            color: white;
            background: #0d9488;
            border: 1.5px solid #0d9488;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s;
            text-decoration: none;
        }
        .btn-download:hover {
            background: #0f766e;
            border-color: #0f766e;
        }
        .btn-download:disabled,
        .btn-download.disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-back {
            position: absolute;
            left: 24px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 500;
            color: #64748b;
            text-decoration: none;
            transition: color 0.15s;
        }
        .btn-back:hover {
            color: #1e293b;
        }

        /* Print styles — hide everything except the document */
        @media print {
            body {
                background: white !important;
                margin: 0;
                padding: 0;
            }
            .download-action-bar {
                display: none !important;
            }
            .download-document-area {
                max-width: 100% !important;
                margin: 0 !important;
                box-shadow: none !important;
                border-radius: 0 !important;
            }
            @page {
                size: A4;
                margin: 18mm 20mm;
            }
        }
    </style>
</head>
<body>

<div class="download-document-area" id="document-content">
    @if($mergedHtml)
        {!! $mergedHtml !!}
    @else
        <div style="text-align:center; padding:60px 24px; color:#94a3b8;">
            <p style="font-size:18px; font-weight:600; margin-bottom:8px;">Document preview not available</p>
            <p style="font-size:14px;">This document does not have a web-rendered preview.</p>
        </div>
    @endif
</div>

<div class="download-action-bar">
    <a href="{{ route('docuperfect.esign.create') }}" class="btn-back">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        Back to E-Sign
    </a>

    <button type="button" onclick="window.print()" class="btn-print">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
        Print
    </button>

    <a href="{{ route('docuperfect.esign.downloadDocumentPdf', $document->id) }}"
       class="btn-download"
       id="downloadPdfBtn"
       onclick="this.classList.add('disabled'); this.textContent='Generating PDF…'; return true;">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17v3a2 2 0 002 2h14a2 2 0 002-2v-3"/></svg>
        Download PDF
    </a>
</div>

</body>
</html>
