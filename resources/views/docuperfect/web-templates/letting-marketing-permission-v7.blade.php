<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marketing Permission — Home Finders Coastal</title>
    <style>
        /* ============================================================
           Letting Marketing Permission V7 — Print-quality A4 document
           ============================================================ */
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
            .page-break {
                page-break-before: always;
            }
        }

        /* ---- Company Header ---- */
        .company-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding-bottom: 10pt;
            border-bottom: 2pt solid #1a1a1a;
            margin-bottom: 12pt;
        }

        .header-left {
            flex: 1;
        }

        .header-logo {
            max-height: 60px;
            width: auto;
            margin-bottom: 4pt;
            display: block;
        }

        .trading-name {
            font-size: 9pt;
            color: #555;
            margin-bottom: 1pt;
        }

        .company-name {
            font-size: 16pt;
            font-weight: bold;
            letter-spacing: 0.5pt;
            color: #0b2a4a;
        }

        .tagline {
            font-size: 9pt;
            font-weight: bold;
            color: #0b2a4a;
            letter-spacing: 1pt;
            text-transform: uppercase;
            margin-top: 2pt;
        }

        .header-right {
            text-align: right;
            font-size: 8.5pt;
            color: #333;
            max-width: 52%;
        }

        .header-address {
            font-size: 8.5pt;
            margin-bottom: 3pt;
        }

        .header-details {
            margin-left: auto;
            border-collapse: collapse;
            font-size: 8.5pt;
        }

        .header-details td {
            padding: 0 0 0 8pt;
            text-align: right;
        }

        .header-details td:first-child {
            font-weight: 600;
            padding-left: 0;
        }

        .header-contact {
            margin-top: 3pt;
            font-size: 8pt;
            color: #555;
        }

        .header-contact span {
            margin-left: 8pt;
        }

        .header-contact span:first-child {
            margin-left: 0;
        }

        /* ---- Document Title ---- */
        .doc-title {
            font-size: 13pt;
            font-weight: bold;
            margin: 14pt 0 10pt;
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

        /* ---- Section Labels ---- */
        .section-label {
            font-weight: bold;
            text-decoration: underline;
            margin: 10pt 0 6pt;
        }

        /* ---- Clause blocks ---- */
        .clause {
            margin: 2pt 0;
            padding-left: 20pt;
            text-indent: -20pt;
        }

        .clause p {
            margin-bottom: 2pt;
        }

        /* ---- Financial Table ---- */
        .financial-table {
            width: 100%;
            border-collapse: collapse;
            margin: 8pt 0;
        }

        .financial-table td {
            padding: 4pt 4pt;
            vertical-align: bottom;
        }

        .financial-table td:first-child {
            width: 220pt;
            font-weight: 600;
        }

        .financial-table td:last-child {
            border-bottom: 1pt solid #1a1a1a;
        }

        /* ---- Numbered List ---- */
        .numbered-list {
            margin: 6pt 0 6pt 20pt;
        }

        .numbered-list li {
            margin-bottom: 3pt;
        }

        /* ---- Signature Section ---- */
        .signature-section {
            margin-top: 16pt;
        }

        .signature-section p {
            margin-bottom: 4pt;
        }

        .signature-grid {
            display: grid;
            gap: 20pt;
            margin-top: 20pt;
        }

        .signature-col {
            text-align: center;
        }

        .signature-line {
            border-bottom: 1pt solid #1a1a1a;
            height: 30pt;
            margin-bottom: 3pt;
        }

        .signature-label {
            font-size: 9pt;
            font-weight: 600;
            text-transform: uppercase;
        }

        /* ---- Footer ---- */
        .doc-footer {
            margin-top: 12pt;
            text-align: right;
            font-size: 8pt;
            color: #999;
        }

        /* ---- Initials Row ---- */
        .initials-row { display:flex; justify-content:flex-end; gap:20pt; margin-top:10pt; }
        .initial-block { text-align:center; }
        .initial-line { border-bottom:1pt solid #000; width:40pt; margin-bottom:2pt; }
        .initial-label { font-size:7pt; }

        /* ---- Page break ---- */
        .page-break {
            margin-top: 0;
            padding-top: 18mm;
        }
    </style>
</head>
<body>

{{-- ============================================================
     PAGE 1 — Marketing Permission
     ============================================================ --}}
<div class="page">

    {{-- Company Header --}}
    @include('docuperfect.web-templates.components.company-header')

    <div class="doc-title">MARKETING PERMISSION</div>

    <div class="clause">
        <p>I / We <span class="field field-wide" data-field="owner_names">{{ $owner_names ?? '' }}@if(!empty($lessor_name_2)) &amp; {{ $lessor_name_2 }}@endif</span>
            the undersigned, being the registered owner/s, or duly authorised
            representative/s of the Lessor of the</p>

        <p>Property Erf / Sectional Scheme / Unit no <span class="field field-medium" data-field="erf_unit_no">{{ $erf_unit_no ?? '' }}</span> in the Complex / Estate
            known as <span class="field" data-field="complex_name">{{ $complex_name ?? '' }}</span></p>

        <p>in <span class="field" data-field="street">{{ $street ?? '' }}</span> (Street)</p>

        <p>in <span class="field field-medium" data-field="township">{{ $township ?? '' }}</span> (Township)</p>

        <p><span class="field" data-field="district">{{ $district ?? '' }}</span> (District)</p>

        <p>together with all fixtures and fittings of a permanent nature pertaining to the
            property, do hereby, irrevocably, grant to Home Finders Coastal the marketing
            permission, right and authority to rent the abovementioned property.</p>
    </div>

    {{-- Domicilium --}}
    <p class="section-label">Domicilium Citandi et Executandi</p>

    <div class="clause">
        <p><strong>Lessor 1:</strong></p>
        <p>Physical address <span class="field" data-field="lessor1_address">{{ $lessor1_address ?? '' }}</span></p>
        <p>Tel: <span class="field field-medium" data-field="lessor1_tel">{{ $lessor1_tel ?? '' }}</span>
            Email: <span class="field field-medium" data-field="lessor1_email">{{ $lessor1_email ?? '' }}</span></p>
    </div>

    <div class="clause">
        <p><strong>Lessor 2:</strong></p>
        <p>Physical address <span class="field" data-field="lessor2_address">{{ $lessor2_address ?? '' }}</span></p>
        <p>Tel: <span class="field field-medium" data-field="lessor2_tel">{{ $lessor2_tel ?? '' }}</span>
            Email: <span class="field field-medium" data-field="lessor2_email">{{ $lessor2_email ?? '' }}</span></p>
    </div>

    {{-- Rental Amount --}}
    <div class="clause">
        <p>The rental amount required by the owner of the property is</p>
        <p><span class="field field-medium field-currency" data-field="rental_amount">{{ $rental_amount ?? '' }}</span></p>
        <p>( <span class="field" data-field="rental_in_words">{{ $rental_in_words ?? '' }}</span> )</p>
        <p>Which includes Agencies commission of <span class="field field-medium field-currency" data-field="commission_amount">{{ $commission_amount ?? '' }}</span>
            ( <span class="field field-short" data-field="commission_percent">{{ $commission_percent ?? '' }}</span> %) plus VAT</p>
    </div>

    {{-- To Let Board Consent --}}
    <div class="clause">
        <p>The Lessor hereby gives consent to Home Finders Coastal to place a &ldquo;To Let&rdquo;
            board on the Property.</p>

        <p style="margin-top: 6pt;">Other: <span class="field field-wide" data-field="other_notes_1">{{ $other_notes_1 ?? '' }}</span></p>
        <p><span class="field field-wide" data-field="other_notes_2">{{ $other_notes_2 ?? '' }}</span></p>
    </div>

    @php
        $sigParties = [];
        $sigNames = [];
        if (!empty($lessor_signature_name ?? '')) { $sigParties[] = 'Owner'; $sigNames[] = $lessor_signature_name; }
        if (!empty($lessor_signature_name_2 ?? '')) { $sigParties[] = 'Owner'; $sigNames[] = $lessor_signature_name_2; }
        if (!empty($agent_signature_name ?? '')) { $sigParties[] = 'Agent'; $sigNames[] = $agent_signature_name; }
    @endphp

    {{-- Closing & Signature --}}
    <div class="clause">
        <p>This Marketing Permission was done and signed by the Lessor at</p>
        <p><span class="field" data-field="signed_at_location">{{ $signed_at_location ?? '' }}</span></p>
        <p>on this <span class="field field-short" data-field="signed_day">{{ $signed_day ?? '' }}</span> day
            of <span class="field field-medium" data-field="signed_month">{{ $signed_month ?? '' }}</span>
            20<span class="field field-tiny" data-field="signed_year">{{ $signed_year ?? '' }}</span>
            at <span class="field field-short" data-field="signed_time">{{ $signed_time ?? '' }}</span> am / pm.</p>
    </div>

    <div class="signature-section">
        <div class="signature-grid" style="grid-template-columns: 1fr 1fr;">
            <div class="signature-col" data-marker-party="owner" data-marker-index="0">
                <div class="signature-line"></div>
                <div class="signature-label">Lessor</div>
            </div>
            <div class="signature-col">
                <div class="signature-line"></div>
                <div class="signature-label">Witness</div>
            </div>
        </div>
        <p style="margin-top: 4pt; font-size: 9pt; text-align: center;">(Registered Owner/s or Duly Authorised Representatives)</p>

        <p style="margin-top: 12pt;">Marketing Permission Agent: <span class="field" data-field="marketing_agent">{{ $marketing_agent ?? '' }}</span></p>
    </div>

    {{-- Footer --}}
    <div class="doc-footer">Version 7</div>

</div>

{{-- ============================================================
     PAGE 2 — Addendum A: Service Fee
     ============================================================ --}}
<div class="page page-break">

    {{-- Company Header --}}
    @include('docuperfect.web-templates.components.company-header')

    <div class="doc-title">Addendum A &ndash; Service Fee</div>

    <div class="clause">
        <p>The parties hereby agree that the agent will be responsible for the following:</p>
    </div>

    <ol class="numbered-list">
        <li>Source a lessee</li>
        <li>Negotiate a rental contract</li>
        <li>Secure deposit</li>
        <li>Secure first month&rsquo;s rental</li>
        <li>Report on defects to the lessor</li>
        <li>Collect the monthly rental</li>
        <li>Ongoing liaison with the lessee</li>
        <li>Collect the monthly Municipal/Eskom account from lessor and pay over to the selected person.</li>
    </ol>

    <div class="clause">
        <p>The Agent shall earn an ongoing Service Fee equal to 10% (plus VAT) of the
            monthly rental for the duration of the lease and any extension thereof.</p>
    </div>

    {{-- Financial Breakdown --}}
    <p style="font-weight: bold; margin: 8pt 0 4pt;">Breakdown</p>
    <table class="financial-table">
        <tr>
            <td>Total Rental Amount</td>
            <td><span class="field" data-field="total_rental" style="min-width:auto;width:100%;border-bottom:none;">{{ $total_rental ?? '' }}</span></td>
        </tr>
        <tr>
            <td>Less Agent&rsquo;s Service Fee<br>(Including VAT)</td>
            <td><span class="field" data-field="service_fee" style="min-width:auto;width:100%;border-bottom:none;">{{ $service_fee ?? '' }}</span></td>
        </tr>
        <tr>
            <td>Let&rsquo;s Assist</td>
            <td><span class="field" data-field="lets_assist" style="min-width:auto;width:100%;border-bottom:none;">{{ $lets_assist ?? '' }}</span></td>
        </tr>
        <tr>
            <td>Net Amount to Lessor</td>
            <td><span class="field" data-field="net_to_lessor" style="min-width:auto;width:100%;border-bottom:none;">{{ $net_to_lessor ?? '' }}</span></td>
        </tr>
    </table>

    {{-- Signature Block --}}
    <div class="signature-section">
        <div class="signature-grid" style="grid-template-columns: 1fr 1fr;">
            <div class="signature-col" data-marker-party="owner" data-marker-index="0">
                <div class="signature-line"></div>
                <div class="signature-label">Lessor</div>
            </div>
            <div class="signature-col" data-marker-party="agent" data-marker-index="1">
                <div class="signature-line"></div>
                <div class="signature-label">Lessee Agent</div>
            </div>
        </div>

        <div class="signature-grid" style="grid-template-columns: 1fr 1fr; margin-top: 10pt;">
            <div>
                <p>Date: <span class="field field-medium" data-field="addendum_lessor_date">{{ $addendum_lessor_date ?? '' }}</span></p>
            </div>
            <div>
                <p>Date: <span class="field field-medium" data-field="addendum_agent_date">{{ $addendum_agent_date ?? '' }}</span></p>
            </div>
        </div>
    </div>

    {{-- Footer --}}
    <div class="doc-footer">Version 7</div>

</div>

</body>
</html>
