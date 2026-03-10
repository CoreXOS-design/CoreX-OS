<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rental Application — Home Finders Coastal</title>
    <style>
        /* ============================================================
           Rental Application V8 — Print-quality A4 document
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

        .field-date {
            min-width: 80pt;
        }

        /* ---- Section Labels ---- */
        .section-label {
            font-weight: bold;
            text-decoration: underline;
            margin: 10pt 0 6pt;
        }

        /* ---- Cover Page ---- */
        .cover-heading {
            font-size: 15pt;
            font-weight: bold;
            text-align: center;
            margin: 20pt 0 14pt;
            color: #0b2a4a;
        }

        .cover-body {
            margin-bottom: 12pt;
        }

        .cover-body p {
            margin-bottom: 8pt;
        }

        .cover-body strong {
            display: block;
            margin: 12pt 0 4pt;
        }

        .cover-body ul {
            margin: 0 0 8pt 20pt;
        }

        .cover-body ul li {
            margin-bottom: 2pt;
        }

        .cover-closing {
            margin-top: 14pt;
        }

        .cover-closing p {
            margin-bottom: 4pt;
        }

        /* ---- Form Fields Table ---- */
        .form-fields {
            width: 100%;
            border-collapse: collapse;
            margin: 6pt 0;
        }

        .form-fields td {
            padding: 4pt 4pt;
            vertical-align: bottom;
        }

        .form-fields td:first-child {
            width: 200pt;
            font-weight: 600;
            white-space: nowrap;
        }

        .form-fields td:last-child {
            border-bottom: 1pt solid #1a1a1a;
        }

        .form-fields .no-border td:last-child {
            border-bottom: none;
        }

        /* ---- Instruction Block ---- */
        .instruction-block {
            background: #f0f0f0;
            text-align: center;
            font-weight: bold;
            padding: 8pt 12pt;
            margin: 12pt 0;
            font-size: 10pt;
        }

        /* ---- Please Note Block ---- */
        .please-note {
            border: 1pt solid #999;
            padding: 8pt 10pt;
            margin: 12pt 0;
            font-size: 9pt;
            line-height: 1.4;
        }

        .please-note p {
            margin-bottom: 6pt;
        }

        .please-note p:last-child {
            margin-bottom: 0;
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

        /* ---- Page break ---- */
        .page-break {
            margin-top: 0;
            padding-top: 18mm;
        }
    </style>
</head>
<body>

{{-- ============================================================
     SECTION 1: Cover Page
     ============================================================ --}}
<div class="page">

    {{-- Company Header --}}
    @include('docuperfect.web-templates.components.company-header')

    <div class="cover-heading">Rental Application Process and Requirements</div>

    <div class="cover-body">
        <p>Thank you for downloading the Home Finders rental preapproval form.</p>

        <p>Home Finders Coastal strictly works on preapproval of potential tenants before any viewings will take place. This process is to ensure that we adhere to the agreement made with owners, and to ensure that you as the tenant only view properties that you are qualified to rent.</p>

        <p>For the preapproval process you please need to complete the attached rental application form, as well as submit the following documentation with the rental application form to letting@hfcoastal.co.za:</p>

        <strong>Permanently employed natural persons:</strong>
        <ul>
            <li>Latest payslip</li>
            <li>3 months bank statements</li>
            <li>ID</li>
            <li>Proof of residence</li>
        </ul>

        <strong>Business owners operating out of their personal account:</strong>
        <ul>
            <li>6 months bank statements</li>
            <li>ID</li>
            <li>Proof of residence</li>
        </ul>

        <strong>Business owners operating out of a business account:</strong>
        <ul>
            <li>Latest financial statements from accountant / auditor</li>
            <li>6 months bank statements</li>
            <li>Company registration documents</li>
            <li>Power of attorney for authorized signatory</li>
            <li>ID of member / director who has Power of attorney</li>
            <li>Proof of company address</li>
            <li>Proof of member address</li>
        </ul>
    </div>

    <div class="cover-closing">
        <p>Please feel free to email letting@hfcoastal.co.za should you need any assistance, and you are also welcome to visit https://hfcoastal.co.za/for-rent/ to view our latest rental properties.</p>

        <p style="margin-top: 10pt;">Thank you<br>The Home Finders Coastal Rental Team</p>
    </div>

    <div class="initials-row">
        @foreach($initialsParties ?? [] as $party)
            <div class="initial-block">
                <div class="initial-line"></div>
                <div class="initial-label">{{ $party }}</div>
            </div>
        @endforeach
    </div>

    {{-- Footer --}}
    <div class="doc-footer">Version 8</div>

</div>

{{-- ============================================================
     SECTION 2: Application Form
     ============================================================ --}}
<div class="page page-break">

    {{-- Company Header --}}
    @include('docuperfect.web-templates.components.company-header')

    <div class="doc-title">Application for rental</div>

    {{-- Personal Details --}}
    <p class="section-label">Personal Details</p>

    <table class="form-fields">
        <tr>
            <td>Address of property:</td>
            <td><span class="field" data-field="property_address" style="min-width:auto;width:100%;border-bottom:none;">{{ $property_address ?? '' }}</span></td>
        </tr>
        <tr>
            <td>Full name and Surname:</td>
            <td><span class="field" data-field="full_name" style="min-width:auto;width:100%;border-bottom:none;">{{ $full_name ?? '' }}</span></td>
        </tr>
    </table>

    <p style="margin-top: 4pt;">I.D Number: <span class="field field-medium" data-field="id_number">{{ $id_number ?? '' }}</span>
        Marital Status: <span class="field field-medium" data-field="marital_status">{{ $marital_status ?? '' }}</span></p>

    <table class="form-fields">
        <tr>
            <td>Spouse Full Name:</td>
            <td><span class="field" data-field="spouse_name" style="min-width:auto;width:100%;border-bottom:none;">{{ $spouse_name ?? '' }}</span></td>
        </tr>
    </table>

    <p style="margin-top: 4pt;">Spouse I.D Number: <span class="field field-medium" data-field="spouse_id">{{ $spouse_id ?? '' }}</span>
        Citizenship: <span class="field field-medium" data-field="citizenship">{{ $citizenship ?? '' }}</span></p>

    <table class="form-fields">
        <tr>
            <td>Current Residential Address:</td>
            <td><span class="field" data-field="current_address_1" style="min-width:auto;width:100%;border-bottom:none;">{{ $current_address_1 ?? '' }}</span></td>
        </tr>
        <tr>
            <td></td>
            <td><span class="field" data-field="current_address_2" style="min-width:auto;width:100%;border-bottom:none;">{{ $current_address_2 ?? '' }}</span></td>
        </tr>
        <tr>
            <td>Email Address:</td>
            <td><span class="field" data-field="email_address" style="min-width:auto;width:100%;border-bottom:none;">{{ $email_address ?? '' }}</span></td>
        </tr>
    </table>

    <p style="margin-top: 4pt;">Contact Numbers: (Cell) <span class="field field-medium" data-field="cell_number">{{ $cell_number ?? '' }}</span>
        (Work) <span class="field field-medium" data-field="work_number">{{ $work_number ?? '' }}</span></p>

    <p style="margin-top: 8pt; font-weight: 600;">Contact Person not staying with you:</p>
    <table class="form-fields">
        <tr>
            <td>Name:</td>
            <td><span class="field" data-field="contact_person_name" style="min-width:auto;width:100%;border-bottom:none;">{{ $contact_person_name ?? '' }}</span></td>
        </tr>
    </table>
    <p style="margin-top: 4pt;">Contact Numbers: (Cell) <span class="field field-medium" data-field="contact_person_cell">{{ $contact_person_cell ?? '' }}</span>
        (Work) <span class="field field-medium" data-field="contact_person_work">{{ $contact_person_work ?? '' }}</span></p>

    <p style="margin-top: 8pt; font-weight: 600;">Current Landlord / Agent / Owner details where you are currently residing:</p>
    <p style="margin-top: 4pt;">Name: <span class="field field-medium" data-field="current_landlord_name">{{ $current_landlord_name ?? '' }}</span>
        Tel No: <span class="field field-medium" data-field="current_landlord_tel">{{ $current_landlord_tel ?? '' }}</span></p>
    <p style="margin-top: 4pt;">Current Rental Amount: <span class="field field-medium field-currency" data-field="current_rental">{{ $current_rental ?? '' }}</span></p>
    <p style="margin-top: 4pt;">From: <span class="field field-date" data-field="rental_from">{{ $rental_from ?? '' }}</span>
        To: <span class="field field-date" data-field="rental_to">{{ $rental_to ?? '' }}</span></p>

    <div class="instruction-block">
        PLEASE EMAIL THIS APPLICATION FORMS, AS WELL AS ALL SUPPORTING DOCUMENTS TO: letting@hfcoastal.co.za
    </div>

    {{-- Employment Details --}}
    <p class="section-label">Employment Details</p>

    <table class="form-fields">
        <tr>
            <td>Name of Employer:</td>
            <td><span class="field" data-field="employer_name" style="min-width:auto;width:100%;border-bottom:none;">{{ $employer_name ?? '' }}</span></td>
        </tr>
        <tr>
            <td>Position:</td>
            <td><span class="field" data-field="position" style="min-width:auto;width:100%;border-bottom:none;">{{ $position ?? '' }}</span></td>
        </tr>
        <tr>
            <td>Employer Address:</td>
            <td><span class="field" data-field="employer_address" style="min-width:auto;width:100%;border-bottom:none;">{{ $employer_address ?? '' }}</span></td>
        </tr>
    </table>

    <p style="margin-top: 4pt;">Tel No of Employer: <span class="field field-medium" data-field="employer_tel">{{ $employer_tel ?? '' }}</span>
        Monthly Salary: <span class="field field-medium field-currency" data-field="monthly_salary">{{ $monthly_salary ?? '' }}</span></p>

    {{-- Requirement of Lease --}}
    <p class="section-label">Requirement of Lease</p>

    <p style="margin-top: 4pt;">Effective Date of Occupation: <span class="field field-medium" data-field="occupation_date">{{ $occupation_date ?? '' }}</span>
        Rental Terms Required: <span class="field field-medium" data-field="rental_terms">{{ $rental_terms ?? '' }}</span></p>

    <table class="form-fields">
        <tr>
            <td>Special Conditions:</td>
            <td><span class="field" data-field="special_conditions_1" style="min-width:auto;width:100%;border-bottom:none;">{{ $special_conditions_1 ?? '' }}</span></td>
        </tr>
        <tr>
            <td></td>
            <td><span class="field" data-field="special_conditions_2" style="min-width:auto;width:100%;border-bottom:none;">{{ $special_conditions_2 ?? '' }}</span></td>
        </tr>
        <tr>
            <td></td>
            <td><span class="field" data-field="special_conditions_3" style="min-width:auto;width:100%;border-bottom:none;">{{ $special_conditions_3 ?? '' }}</span></td>
        </tr>
    </table>

    <p style="margin-top: 4pt;">Number of Occupants: Adults: <span class="field field-short" data-field="adults">{{ $adults ?? '' }}</span>
        Children: <span class="field field-short" data-field="children">{{ $children ?? '' }}</span></p>

    {{-- First Signature Block --}}
    <div class="signature-section">
        <p style="margin-top: 10pt;"><em>I hereby declare that all the above information given is true and accurate.</em></p>

        <div class="signature-grid" style="grid-template-columns: 1fr 1fr;">
            <div class="signature-col" data-marker-party="tenant" data-marker-index="0">
                <div class="signature-line"></div>
                <div class="signature-label">Signature</div>
            </div>
            <div class="signature-col">
                <div class="signature-line"></div>
                <div class="signature-label">Witness</div>
            </div>
        </div>

        <p style="margin-top: 10pt;">Date: <span class="field field-medium" data-field="signature_date_1">{{ $signature_date_1 ?? '' }}</span></p>
    </div>

    <div class="instruction-block">
        PLEASE EMAIL THIS APPLICATION FORMS, AS WELL AS ALL SUPPORTING DOCUMENTS TO: letting@hfcoastal.co.za
    </div>

    {{-- Please Note --}}
    <div class="please-note">
        <p><strong>Please Note:</strong></p>
        <p>Please submit this application to Home Finders Coastal ASAP via email or by hand delivery, for your application to be processed. Your application will be processed within 1 business day from the time of receipt of this application, 3 months bank statement, 1 months&rsquo; pay slip, copy of applicant&rsquo;s ID&rsquo;s and proof of residence.</p>
        <p>If applying through a business, 6 months bank statements of the business, company registration documents, person with signing power ID and proof of residence, and proof of business address.</p>
        <p>No Rental property will be reserved unless the Lease agreement has been signed and returned to Home Finders Coastal either via hand delivery or email, and: The initial invoice including the deposit and admin has been paid to Home Finders Coastal.</p>
        <p>For any queries on your application please contact the Rental division on: 039 315 0857 or email: letting@hfcoastal.co.za</p>
    </div>

    {{-- Tenant Profile Network Consent --}}
    <p class="section-label">Tenant Profile Network Consent:</p>

    <p style="margin-top: 4pt;">The tenant hereby consents that, and authorises the Landlord or agent to, at all times:</p>
    <ul style="margin: 6pt 0 6pt 20pt;">
        <li style="margin-bottom: 4pt;">Contact, request and obtain information from any credit provider (or potential credit provider) or registered credit bureau relevant to an assessment of the behaviour, profile, payment patterns, indebtedness, whereabouts, and creditworthiness of the tenant;</li>
        <li>Furnish information concerning the behaviour, profile, payment patterns, indebtedness, whereabouts, and creditworthiness of the tenant of any registered credit bureau or to any credit provider (or potential credit provider) seeking a trade reference regarding the tenant&rsquo;s dealings with the landlord.</li>
    </ul>

    {{-- Second Signature Block --}}
    <div class="signature-section">
        <p style="margin-top: 10pt;"><em>I hereby declare that all the above information given is true and accurate.</em></p>

        <div class="signature-grid" style="grid-template-columns: 1fr 1fr;">
            <div class="signature-col">
                <div class="signature-line"></div>
                <div class="signature-label">Signature</div>
            </div>
            <div class="signature-col">
                <div class="signature-line"></div>
                <div class="signature-label">Witness</div>
            </div>
        </div>

        <p style="margin-top: 10pt;">Date: <span class="field field-medium" data-field="signature_date_2">{{ $signature_date_2 ?? '' }}</span></p>
    </div>

    {{-- Footer --}}
    <div class="doc-footer">Version 8</div>

</div>

</body>
</html>
