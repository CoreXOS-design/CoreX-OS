<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Letting Mandate (V5) Again testing — Home Finders Coastal</title>
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

<p class="corex-para"><strong>Mandate entered into between</strong></p><p class="corex-para"><strong>The Parties</strong></p><p class="corex-para">The Owner/s:<span class="field-group" data-group-id="3" data-layout="horizontal"><span class="field" data-field="property.rental_amount" data-label="Amount">{{ $rentalAmount ?? '' }}</span> <span class="field" data-field="contact.bank_account_number" data-contact-type="Lessor" data-label="Account Number">{{ $bankAccountNumber ?? '' }}</span> <span class="field" data-field="contact.bank_name" data-contact-type="Lessor" data-label="Lease Bank Name">{{ $bankName ?? '' }}</span></span> </p><p class="corex-para">Home Finders Coastal (Agent):	</p><p class="list-paragraph corex-para"> The owner hereby grants to the Agent a Mandate to offer to let the property known </p><p class="list-paragraph corex-para">as <span class="field field-manual" data-field="manual.field_1">{{ $manual_field_1 ?? '' }}</span></p><p class="list-paragraph corex-para">subject to the conditions set out in this agreement.</p><p class="list-paragraph corex-para">The rental amount required by the Owner for the property is R<span class="field field-manual" data-field="manual.field_2">{{ $manual_field_2 ?? '' }}</span> which includes the commission as stated in clause 4.  In the event of the Agency not finding a suitable Tenant to rent the property at such rental amount, then, between the Owner and the Agency they will agree to an acceptable rental amount prior to allowing any tenant taking occupation of the said property, which includes commission as stated in clause 4.</p><p class="list-paragraph corex-para">The sole mandate hereby granted shall commence on date of signature hereof and shall remain in force until 22h00 on the .</p><p class="list-paragraph corex-para">The Owner will pay to the Agent a commission, calculated at a percentage of  plus VAT on the letting price of the property.</p><p class="list-paragraph corex-para">The Agency will screen all possible tenants prior to occupation to ensure a hassle free letting of the property.</p><p class="list-paragraph corex-para">The Agent will deposit the monthly rental collections into the following Bank Account supplied by the Owner, by no later than the 7<sup>th</sup> day of every month.</p><p class="corex-para">Account Holder's Name:		</p><p class="corex-para">Bank Name: <span class="field field-manual" data-field="manual.field_3">{{ $manual_field_3 ?? '' }}</span></p><p class="corex-para">Account Number:		<span class="field field-manual" data-field="manual.field_4">{{ $manual_field_4 ?? '' }}</span></p><p class="corex-para">Branch Name and Code:	<span class="field field-manual" data-field="manual.field_5">{{ $manual_field_5 ?? '' }}</span></p><p class="corex-para">Owner's Contact details:	</p><p class="corex-para">Owner's Email Address:		<span class="field field-manual" data-field="manual.field_6">{{ $manual_field_6 ?? '' }}</span></p><p class="list-paragraph corex-para">  The Owner shall supply the Agency with water and lights service usage charges every month, so the Agency may add this to the statement forwarded to the Tenant.</p><p class="corex-para"><div class="sig-block" data-sig-number="7" data-variant="sig_full" data-parties="[&quot;Lessor&quot;,&quot;Agent&quot;]"><p class="sig-preamble">This agreement has been accepted and signed at <span class="field field-tiny">{{ $signedAt ?? '' }}</span> on the <span class="field field-short">{{ $signedDay ?? '' }}</span> day of <span class="field field-medium">{{ $signedMonth ?? '' }}</span> <span class="field field-tiny">{{ $signedYear ?? '' }}</span></p><div class="sig-block-party"><div class="sig-line">_________________________________</div><div class="sig-name">{{ $lessorName ?? 'Lessor' }}</div></div><div class="sig-block-party"><div class="sig-line">_________________________________</div><div class="sig-name">{{ $agentName ?? 'Agent' }}</div></div></div><br></p>

</div>
</body>
</html>