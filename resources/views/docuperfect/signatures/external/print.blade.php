<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $document->name ?? 'Document' }} — Print</title>
    <link href="/css/corex-document.css" rel="stylesheet">
    <style>
        /* Screen: show document centered with subtle background */
        body {
            margin: 0;
            padding: 0;
            background: #f1f5f9;
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
        }

        .print-toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 100;
            background: #0b2a4a;
            color: white;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .print-toolbar-title {
            font-size: 14px;
            font-weight: 600;
        }
        .print-toolbar-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .print-btn {
            padding: 8px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: background 0.2s;
        }
        .print-btn-primary {
            background: #10b981;
            color: white;
        }
        .print-btn-primary:hover { background: #059669; }
        .print-btn-secondary {
            background: rgba(255,255,255,0.15);
            color: white;
        }
        .print-btn-secondary:hover { background: rgba(255,255,255,0.25); }

        .document-container {
            margin-top: 64px;
            padding: 24px;
        }

        /* Document content: white A4-like container */
        .document-content {
            max-width: 210mm;
            margin: 0 auto;
            background: white;
            padding: 20mm 18mm 25mm 18mm;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            box-sizing: border-box;
        }

        /* Hide interactive signing UI elements in print view */
        .web-sig-prompt { display: none !important; }
        .web-sig-interactive {
            border: 1px solid #ccc !important;
            background: transparent !important;
            cursor: default !important;
        }
        .web-sig-other-party { opacity: 1 !important; pointer-events: auto !important; }
        .web-sig-signed-img { display: block; max-height: 50px; }

        /* Page break markers: show as visual dividers on screen, actual breaks in print */
        .corex-page-break {
            border-top: 1px dashed #cbd5e1;
            margin: 16px 0;
            padding: 8px 0;
        }
        .corex-page-initials {
            width: 60px;
            height: 30px;
            border: 1px solid #94a3b8;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 9px;
            color: #64748b;
        }

        /* Print styles */
        @media print {
            body { background: white; margin: 0; padding: 0; }
            .print-toolbar { display: none !important; }
            .document-container { margin-top: 0; padding: 0; }
            .document-content {
                max-width: none;
                padding: 0;
                box-shadow: none;
                margin: 0;
            }
            .corex-document-wrapper { padding: 0; background: white; }
            .corex-page { box-shadow: none; margin: 0; }
            .corex-page-break {
                page-break-before: always;
                border-top: none;
                margin: 0;
                padding: 4px 0;
            }
        }
    </style>
</head>
<body>
    <div class="print-toolbar no-print">
        <div class="print-toolbar-title">{{ $document->name ?? 'Document' }}</div>
        <div class="print-toolbar-actions">
            <a href="{{ route('signatures.external', $token) }}" class="print-btn print-btn-secondary">
                &larr; Back to Signing
            </a>
            <button onclick="window.print()" class="print-btn print-btn-primary">
                Print / Save as PDF
            </button>
        </div>
    </div>

    <div class="document-container">
        <div class="document-content">
            {!! $mergedHtml !!}
        </div>
    </div>

    <script>
        // Auto-trigger print dialog after page loads
        window.addEventListener('load', function() {
            setTimeout(function() { window.print(); }, 600);
        });
    </script>
</body>
</html>
