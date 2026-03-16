<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Letting Mandate (V5) 13:49 — Home Finders Coastal</title>
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

        .field {
            display: inline-block;
            min-width: 120pt;
            border-bottom: 1px solid #333;
            padding: 1pt 4pt;
            min-height: 14pt;
        }

        .field-short {
            min-width: 40pt;
        }

        strong { font-weight: bold; }
        em { font-style: italic; }
    </style>
</head>
<body>
<div class="page">
    @include('docuperfect.web-templates.components.company-header')

<p class="corex-para"><strong>Mandate entered into between</strong></p><p class="corex-para"><strong>The Parties</strong></p><p class="corex-para">The Owner/s: <span class="field" data-field="contact.full_name">{{ $contact_full_name ?? '' }}</span></p><p class="corex-para">Home Finders Coastal (Agent):	<span class="field" data-field="agent.full_name">{{ $agent_full_name ?? '' }}</span></p><p class="list-paragraph corex-para"> The owner hereby grants to the Agent a Mandate to offer to let the property known </p><p class="list-paragraph corex-para">as <span class="field" data-field="property.address_full">{{ $property_address_full ?? '' }}</span></p><p class="list-paragraph corex-para">subject to the conditions set out in this agreement.</p><p class="list-paragraph corex-para">The rental amount required by the Owner for the property is R<span class="field" data-field="deal.rental_amount">{{ $deal_rental_amount ?? '' }}</span> which includes the commission as stated in clause 4.  In the event of the Agency not finding a suitable Tenant to rent the property at such rental amount, then, between the Owner and the Agency they will agree to an acceptable rental amount prior to allowing any tenant taking occupation of the said property, which includes commission as stated in clause 4.</p><p class="list-paragraph corex-para">The sole mandate hereby granted shall commence on date of signature hereof and shall remain in force until 22h00 on the <span class="field" data-field="deal.lease_end">{{ $deal_lease_end ?? '' }}</span>.</p><p class="list-paragraph corex-para">The Owner will pay to the Agent a commission, calculated at a percentage of <span class="field" data-field="deal.commission_percent">{{ $deal_commission_percent ?? '' }}</span><span class="field" data-field="skip">{{ $skip ?? '' }}</span> plus VAT on the letting price of the property.</p><p class="list-paragraph corex-para">The Agency will screen all possible tenants prior to occupation to ensure a hassle free letting of the property.</p><p class="list-paragraph corex-para">The Agent will deposit the monthly rental collections into the following Bank Account supplied by the Owner, by no later than the 7<sup>th</sup> day of every month.</p><p class="corex-para">Account Holder's Name:		<span class="field" data-field="deal.account_holder">{{ $deal_account_holder ?? '' }}</span></p><p class="corex-para">Bank Name: <span class="field" data-field="deal.bank_name">{{ $deal_bank_name ?? '' }}</span></p><p class="corex-para">Account Number:		<span class="field" data-field="deal.account_number">{{ $deal_account_number ?? '' }}</span></p><p class="corex-para">Branch Name and Code:	<span class="field" data-field="deal.branch_code">{{ $deal_branch_code ?? '' }}</span></p><p class="corex-para">Owner's Contact details:	<span class="field" data-field="contact.cell">{{ $contact_cell ?? '' }}</span></p><p class="corex-para">Owner's Email Address:		<span class="field" data-field="contact.email">{{ $contact_email ?? '' }}</span></p><p class="list-paragraph corex-para">  The Owner shall supply the Agency with water and lights service usage charges every month, so the Agency may add this to the statement forwarded to the Tenant.</p>

    @include('docuperfect.web-templates.components.signature-block')
</div>
</body>
</html>