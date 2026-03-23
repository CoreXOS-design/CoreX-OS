<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $document->name ?? 'Document' }} — Print</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/css/corex-document.css" rel="stylesheet">
    @include('docuperfect.signatures.partials.a4-page-styles')
    <style>
        /* Screen: show document centered with subtle background */
        body {
            margin: 0;
            padding: 0;
            background: #f1f5f9;
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
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
            text-decoration: none;
            display: inline-flex;
            align-items: center;
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
            box-shadow: 0 2px 12px rgba(0,0,0,0.12);
            border-radius: 4px;
            box-sizing: border-box;
            line-height: 1.5;
        }

        /* Ensure document body text inherits proper styling */
        .document-content * {
            max-width: 100%;
        }
        .document-content table {
            border-collapse: collapse;
            width: 100%;
        }
        .document-content table td,
        .document-content table th {
            padding: 4px 8px;
            vertical-align: top;
        }

        /* Hide ALL interactive signing UI elements in print view */
        .web-sig-prompt { display: none !important; }
        .init-prompt { display: none !important; }
        .web-sig-interactive {
            border: 1px solid #94a3b8 !important;
            background: transparent !important;
            cursor: default !important;
            min-height: 28pt;
        }
        .web-sig-other-party {
            opacity: 1 !important;
            pointer-events: auto !important;
        }
        .web-sig-signed-img {
            display: block;
            max-height: 50px;
            object-fit: contain;
        }

        /* Radio placeholders: show as filled/empty circles */
        .corex-radio-placeholder {
            display: inline-block;
            font-size: 14pt;
            line-height: 1;
        }

        /* Hide input borders in print — show values only */
        .field-editable,
        input[data-ceremony-field="true"] {
            border: none !important;
            background: transparent !important;
            outline: none !important;
            padding: 0 !important;
            font: inherit !important;
            color: inherit !important;
        }

        /* Page break markers: show as visual dividers on screen, actual breaks in print */
        .corex-page-break {
            margin: 16px 0;
        }
        .corex-page-initials-row {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 8px;
            padding: 12px 0 4px 0;
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

        /* Clause flagging: hide flag icons in print */
        .clause-flag-icon { display: none !important; }
        .clause-flag-comment { display: none !important; }

        /* Print styles */
        @media print {
            body {
                background: white;
                margin: 0;
                padding: 0;
            }
            .print-toolbar { display: none !important; }
            .document-container {
                margin-top: 0;
                padding: 0;
            }
            .document-content {
                max-width: none;
                padding: 15mm 18mm 20mm 18mm;
                box-shadow: none;
                margin: 0;
                border-radius: 0;
            }
            .corex-document-wrapper {
                padding: 0;
                background: white;
            }
            .corex-page {
                box-shadow: none;
                margin: 0;
                padding: 0;
            }
            .corex-page-initials {
                border: 1px solid #000;
            }

            /* Ensure signatures print */
            .web-sig-signed-img {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            img {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            /* Tables: ensure borders print */
            table, td, th {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
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
        // Split into A4 pages, then clean up interactive elements
        document.addEventListener('DOMContentLoaded', function() {
            paginateDocument(document.querySelector('.document-content'), @json($signingParties ?? []));

            // Remove "Click to sign" prompts
            document.querySelectorAll('.web-sig-prompt, .init-prompt').forEach(function(el) {
                el.remove();
            });

            // Remove dashed borders on unsigned elements — show as clean signature lines
            document.querySelectorAll('.web-sig-interactive').forEach(function(el) {
                if (!el.querySelector('img')) {
                    el.style.borderStyle = 'solid';
                    el.style.borderColor = '#94a3b8';
                    el.style.borderWidth = '0 0 1px 0';
                    el.style.background = 'transparent';
                }
            });
        });

        // Auto-trigger print dialog after page loads
        window.addEventListener('load', function() {
            setTimeout(function() { window.print(); }, 800);
        });
    </script>
</body>
</html>
