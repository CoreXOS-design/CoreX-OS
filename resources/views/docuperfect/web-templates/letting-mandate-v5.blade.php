<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Letting Mandate — Home Finders Coastal</title>
    <style>
        /* ============================================================
           Letting Mandate V5 — Print-quality A4 document
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
            text-align: center;
            font-size: 13pt;
            font-weight: bold;
            margin: 14pt 0 10pt;
            text-decoration: underline;
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

        /* ---- Clauses ---- */
        .clause {
            margin: 2pt 0;
            padding-left: 20pt;
            text-indent: -20pt;
        }

        .clause-number {
            font-weight: bold;
            display: inline;
        }

        .clause-text {
            display: inline;
        }

        .section-label {
            font-weight: bold;
            text-decoration: underline;
            margin: 10pt 0 6pt;
        }

        /* ---- Bank Details Table ---- */
        .bank-details {
            width: 100%;
            margin: 6pt 0;
            border-collapse: collapse;
        }

        .bank-details td {
            padding: 3pt 4pt;
            vertical-align: bottom;
        }

        .bank-details td:first-child {
            width: 190pt;
            font-weight: 600;
        }

        .bank-details td:last-child {
            border-bottom: 1pt solid #1a1a1a;
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
            margin-top: 30pt;
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

        .print-line {
            border-bottom: 1pt dotted #999;
            height: 22pt;
            margin-top: 4pt;
            margin-bottom: 3pt;
        }

        .print-label {
            font-size: 8pt;
            color: #666;
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
    </style>
</head>
<body>
<div class="page">

    {{-- Company Header --}}
    @include('docuperfect.web-templates.components.company-header')

    {{-- Document Title --}}
    <div class="doc-title">Mandate entered into between</div>

    {{-- The Parties --}}
    <p class="section-label">The Parties</p>

    <p>The Owner/s: <span class="field" data-field="lessor_name">{{ $lessor_name ?? '' }}</span>@if(!empty($lessor_name_2)) &amp; <span class="field" data-field="lessor_name_2">{{ $lessor_name_2 }}</span>@endif</p>

    <p style="margin-top: 6pt;">Home Finders Coastal (Agent): <span class="field" data-field="agent_name">{{ $agent_name ?? '' }}</span></p>

    {{-- Clause 1 --}}
    <div class="clause">
        <span class="clause-number">1.</span>
        <span class="clause-text">The owner hereby grants to the Agent a Mandate to offer to let the property known
            as <span class="field field-address" data-field="property_address">{{ $property_address ?? '' }}</span>
            subject to the conditions set out in this agreement.</span>
    </div>

    {{-- Clause 2 --}}
    <div class="clause">
        <span class="clause-number">2.</span>
        <span class="clause-text">The rental amount required by the Owner for the property is
            R<span class="field field-medium" data-field="rental_amount">{{ $rental_amount ?? '' }}</span>
            which includes the commission as stated in clause 4. In the event of the Agency not finding a suitable
            Tenant to rent the property at such rental amount, then, between the Owner and the Agency they will
            agree to an acceptable rental amount prior to allowing any tenant taking occupation of the said
            property, which includes commission as stated in clause 4.</span>
    </div>

    {{-- Clause 3 --}}
    <div class="clause">
        <span class="clause-number">3.</span>
        <span class="clause-text">The sole mandate hereby granted shall commence on date of signature hereof and
            shall remain in force until 22h00 on the
            <span class="field field-short" data-field="mandate_day">{{ $mandate_day ?? '' }}</span> day
            of <span class="field field-medium" data-field="mandate_month">{{ $mandate_month ?? '' }}</span>
            20<span class="field field-tiny" data-field="mandate_year">{{ $mandate_year ?? '' }}</span></span>
    </div>

    {{-- Clause 4 --}}
    <div class="clause">
        <span class="clause-number">4.</span>
        <span class="clause-text">The Owner will pay to the Agent a commission, calculated at a percentage of
            <span class="field field-short" data-field="commission_percent">{{ $commission_percent ?? '' }}</span>% plus VAT
            on the letting price of the property.</span>
    </div>

    {{-- Clause 5 --}}
    <div class="clause">
        <span class="clause-number">5.</span>
        <span class="clause-text">The Agency will screen all possible tenants prior to occupation to ensure a
            hassle free letting of the property.</span>
    </div>

    {{-- Clause 6 --}}
    <div class="clause">
        <span class="clause-number">6.</span>
        <span class="clause-text">The Agent will deposit the monthly rental collections into the following Bank Account
            supplied by the Owner, by no later than the 7th day of every month.</span>
    </div>

    <table class="bank-details">
        <tr>
            <td>Account Holder&rsquo;s Name:</td>
            <td><span class="field" data-field="account_holder" style="min-width:auto;width:100%;border-bottom:none;">{{ $account_holder ?? '' }}</span></td>
        </tr>
        <tr>
            <td>Bank Name:</td>
            <td><span class="field" data-field="bank_name" style="min-width:auto;width:100%;border-bottom:none;">{{ $bank_name ?? '' }}</span></td>
        </tr>
        <tr>
            <td>Account Number:</td>
            <td><span class="field" data-field="account_number" style="min-width:auto;width:100%;border-bottom:none;">{{ $account_number ?? '' }}</span></td>
        </tr>
        <tr>
            <td>Branch Name and Code:</td>
            <td><span class="field" data-field="branch_name" style="min-width:auto;width:100%;border-bottom:none;">{{ $branch_name ?? '' }}</span></td>
        </tr>
    </table>

    <p style="margin-top: 8pt;">Owner&rsquo;s Contact details:
        <span class="field" data-field="owner_contact">{{ $owner_contact ?? '' }}</span></p>

    <p style="margin-top: 4pt;">Owner&rsquo;s Email Address:
        <span class="field" data-field="owner_email">{{ $owner_email ?? '' }}</span></p>

    {{-- Clause 7 --}}
    <div class="clause">
        <span class="clause-number">7.</span>
        <span class="clause-text">The Owner shall supply the Agency with water and lights service usage charges
            every month, so the Agency may add this to the statement forwarded to the Tenant.</span>
    </div>

    {{-- Signature Block --}}
    @php
        $signingParties = ['Agent'];
        $signingNames = [$agent_signature_name ?? $agent_name ?? ''];
        if (!empty($lessor_signature_name ?? $lessor_name ?? '')) {
            array_unshift($signingParties, 'Owner');
            array_unshift($signingNames, $lessor_signature_name ?? $lessor_name);
        }
        if (!empty($lessor_signature_name_2 ?? $lessor_name_2 ?? '')) {
            array_splice($signingParties, 1, 0, ['Owner']);
            array_splice($signingNames, 1, 0, [$lessor_signature_name_2 ?? $lessor_name_2]);
        }
        // Default: at least 2 Owner columns if no names yet
        if (count($signingParties) < 3) {
            array_unshift($signingParties, 'Owner');
            array_unshift($signingNames, '');
            if (count($signingParties) < 3) {
                array_splice($signingParties, 1, 0, ['Owner']);
                array_splice($signingNames, 1, 0, ['']);
            }
        }
    @endphp
    @include('docuperfect.web-templates.components.signature-block', [
        'parties' => $signingParties,
        'party_names' => $signingNames,
        'signed_at_location' => $signed_at_location ?? null,
        'signed_day' => $signed_day ?? null,
        'signed_month' => $signed_month ?? null,
        'signed_time' => $signed_time ?? null,
        'signed_ampm' => $signed_ampm ?? null,
    ])

    {{-- Footer --}}
    <div class="doc-footer">Version 5</div>

</div>
</body>
</html>
