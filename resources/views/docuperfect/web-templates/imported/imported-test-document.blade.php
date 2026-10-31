<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Imported Test Document</title>
    <style>
        @page {
            size: A4;
            margin: 18mm 20mm 15mm 20mm;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            line-height: 1.2;
            color: #1a1a1a;
            background: white;
        }

        p {
            margin: 0 0 2pt 0;
        }

        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 18mm 20mm 15mm 20mm;
            background: white;
        }

        @media screen {
            body {
                background: #e5e7eb;
            }
            .page {
                box-shadow: 0 2px 16px rgba(0,0,0,0.15);
                margin-top: 20px;
                margin-bottom: 20px;
            }
        }

        @media print {
            body { background: white; }
            .page {
                width: auto;
                min-height: auto;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }
        }

        /* ---- Field values (inline blanks) ---- */
        .field {
            display: inline;
            border-bottom: 1pt solid #1a1a1a;
            padding: 0 1pt;
            min-width: 80pt;
            font-weight: normal;
            vertical-align: baseline;
            line-height: inherit;
            white-space: nowrap;
        }

        .field:not(:empty) {
            font-weight: bold;
        }

        .field:empty::after {
            content: '\00a0';
        }

        .field-short {
            min-width: 80pt;
        }

        .field-tiny {
            min-width: 60pt;
        }

        .field-medium {
            min-width: 150pt;
        }

        .field-wide {
            display: block;
            width: 100%;
            min-height: 18pt;
            margin-bottom: 4pt;
        }

        .field-address {
            min-width: 250pt;
        }

        .field-currency::before {
            content: 'R';
            margin-right: 2pt;
        }

        .field-manual {
            background: #fef3c7;
        }

        .field-group {
            display: inline;
        }

        .sig-placeholder, .ini-placeholder {
            display: inline-block;
            padding: 2pt 6pt;
            border: 1px dashed #999;
            color: #666;
            font-size: 9pt;
            font-style: italic;
        }

        .sig-block {
            margin: 12pt 0;
            page-break-inside: avoid;
        }

        .sig-preamble {
            margin-bottom: 8pt;
        }

        .sig-block-party {
            margin-top: 18pt;
            display: inline-block;
            min-width: 200pt;
            vertical-align: top;
            margin-right: 20pt;
        }

        .sig-line {
            font-size: 10pt;
            line-height: 1;
            margin-bottom: 2pt;
        }

        .sig-name {
            font-size: 9pt;
            color: #333;
        }

        strong { font-weight: bold; }
        em { font-style: italic; }
    </style>
</head>
<body>
<div class="page">
    @include('docuperfect.web-templates.components.company-header')

<p>Signed: <span class="field field-manual" data-field="manual.field_1" data-label="Field 1">{{ $manual_field_1 ?? '' }}</span></p>

    @include('docuperfect.web-templates.components.signature-block', ['signing_parties' => $signing_parties ?? []])
</div>
</body>
</html>