<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mandatory Disclosure — Home Finders Coastal</title>
    <style>
        /* ============================================================
           Letting Mandatory Disclosure V7 — Print-quality A4 document
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
            font-size: 12pt;
            font-weight: bold;
            text-transform: uppercase;
            margin: 14pt 0 4pt;
            line-height: 1.4;
        }

        .doc-subtitle {
            text-align: center;
            font-size: 10pt;
            margin: 0 0 12pt;
            line-height: 1.4;
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

        /* ---- Clauses ---- */
        .clause {
            margin: 2pt 0;
            padding-left: 20pt;
            text-indent: -20pt;
        }

        .clause-heading {
            font-weight: bold;
            margin-bottom: 4pt;
        }

        .clause p {
            margin-bottom: 4pt;
        }

        .sub-clause {
            padding-left: 16pt;
            margin: 4pt 0;
        }

        /* ---- Section Labels ---- */
        .section-label {
            font-weight: bold;
            text-decoration: underline;
            margin: 10pt 0 6pt;
        }

        /* ---- Disclosure Table ---- */
        .disclosure-table {
            width: 100%;
            border-collapse: collapse;
            margin: 8pt 0;
            font-size: 10pt;
        }

        .disclosure-table th,
        .disclosure-table td {
            border: 1pt solid #1a1a1a;
            padding: 6pt;
            vertical-align: top;
        }

        .disclosure-table th {
            background: #f0f0f0;
            font-weight: bold;
            text-align: center;
            font-size: 10pt;
        }

        .disclosure-table td:first-child {
            text-align: left;
        }

        .disclosure-table .col-check {
            width: 60px;
            text-align: center;
            vertical-align: middle;
        }

        .disclosure-table tr:nth-child(even) {
            background: #fafafa;
        }

        /* ---- Additional Info Lines ---- */
        .info-line {
            border-bottom: 1pt solid #1a1a1a;
            height: 24pt;
            margin-bottom: 2pt;
        }

        /* ---- Signature Section ---- */
        .signature-section {
            margin-top: 16pt;
        }

        .signature-section p {
            margin-bottom: 4pt;
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
     PAGE 1
     ============================================================ --}}
<div class="page">

    {{-- Company Header --}}
    @include('docuperfect.web-templates.components.company-header')

    {{-- Document Title --}}
    <div class="doc-title">
        Immovable Property Condition Report in Relation to the Lease of Any<br>
        Immovable Property
    </div>
    <div class="doc-subtitle">
        (Property Practitioners Act 22 of 2019, Section 70 &ndash; Property Practitioners<br>
        Regulations 2022 Section 36 &ndash; Mandatory Disclosure)
    </div>

    {{-- 1. Disclaimer --}}
    <div class="clause">
        <div class="clause-heading">1. Disclaimer</div>
        <p>This condition report concerns the immovable property situated at
            <span class="field field-wide" data-field="property_address">{{ $property_address ?? '' }}</span>
            (the &ldquo;Property&rdquo;).</p>
        <p>This report does not constitute a guarantee or warranty of any kind by the lessor
            of the Property or by the property practitioners representing that lessor in any
            transaction. This report should, therefore, not be regarded as a substitute for
            any inspections or warranties that prospective tenant may wish to obtain prior to
            concluding an agreement of lease in respect of the Property.</p>
    </div>

    {{-- 2. Definitions --}}
    <div class="clause">
        <div class="clause-heading">2. Definitions</div>
        <p>In this form &ndash;</p>
        <div class="sub-clause">2.1 &ldquo;to be aware&rdquo; means to have actual notice or knowledge of a certain fact or
            state of affairs; and</div>
        <div class="sub-clause">2.2 &ldquo;defect&rdquo; means any condition, whether latent or patent, that would or could
            have a significant deleterious or adverse impact on, or affect, the value of the
            property, that would or could significantly impair or impact upon the health or
            safety of any future occupants of the property or that, if not repaired, removed,
            or replaced, would or could significantly shorten or adversely affect the expected
            normal lifespan of the Property.</div>
    </div>

    {{-- 3. Disclosure of information --}}
    <div class="clause">
        <div class="clause-heading">3. Disclosure of information</div>
        <p>The lessor of the Property discloses the information hereunder in the full
            knowledge that, even though this is not to be construed as a warranty, prospective
            tenants of the Property may rely on such information when deciding whether, and on
            what terms, to lease the Property. The lessor hereby authorises the appointed
            property practitioner marketing the Property for sale to provide a copy of this
            statement, and to disclose any information contained in this statement, to any
            person in connection with any actual or anticipated lease of the Property.</p>
    </div>

    {{-- 4. Provision of additional information --}}
    <div class="clause">
        <div class="clause-heading">4. Provision of additional information</div>
        <p>The lessor represents that to the best of his or her knowledge the responses to
            the statements in respect of the Property contained herein have been accurately
            noted as &ldquo;yes&rdquo;, &ldquo;no&rdquo; or &ldquo;not applicable&rdquo;. Should the lessor have responded to any
            of the statements with a &ldquo;yes&rdquo;, the lessor shall be obliged to provide, in the
            additional information area of this form, a full explanation as to the response to
            the statement concerned.</p>
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
    <div class="doc-footer">Version 7</div>

</div>

{{-- ============================================================
     PAGE 2 — Disclosure Table
     ============================================================ --}}
<div class="page page-break">

    {{-- Company Header --}}
    @include('docuperfect.web-templates.components.company-header')

    {{-- 5. Statements in connection with Property --}}
    <div class="clause">
        <div class="clause-heading">5. Statements in connection with Property</div>
    </div>

    <table class="disclosure-table">
        <thead>
            <tr>
                <th>Statement</th>
                <th class="col-check">YES</th>
                <th class="col-check">NO</th>
                <th class="col-check">N/A</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1. I am aware of the defects in the roof</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
            </tr>
            <tr>
                <td>2. I am aware of the defects in the electrical systems</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
            </tr>
            <tr>
                <td>3. I am aware of the defects in the plumbing system, including in the swimming pool (if any)</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
            </tr>
            <tr>
                <td>4. I am aware of the defects in the heating and air conditioning systems, including the air filters and humidifiers</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
            </tr>
            <tr>
                <td>5. I am aware of the defects in the septic or other sanitary disposal systems</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
            </tr>
            <tr>
                <td>6. I am aware of any defects to the property and/or in the basement or foundations of the property, including cracks, seepage and bulges. Other such defects include, but are not limited to, flooding, dampness or wet walls and unsafe concentrations of mould or defects in drain tiling or sump pumps</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
            </tr>
            <tr>
                <td>7. I am aware of structural defects in the Property</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
            </tr>
            <tr>
                <td>8. I am aware of boundary line dispute, encroachments, or encumbrances in connection with the Property</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
            </tr>
            <tr>
                <td>9. I am aware that remodelling and refurbishment have affected the structure of the Property</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
            </tr>
            <tr>
                <td>10. I am aware that any additions or improvements made to or any erections made on the property, have been done or were made, only after the required consents, permissions and permits to do so were properly obtained.</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
            </tr>
            <tr>
                <td>11. I am aware that a structure on the Property has been earmarked as a historic structure or heritage site</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
            </tr>
        </tbody>
    </table>

    {{-- Extra Information --}}
    <div class="clause">
        <div class="clause-heading">Extra Information</div>
    </div>

    <table class="disclosure-table">
        <thead>
            <tr>
                <th>Statement</th>
                <th class="col-check">YES</th>
                <th class="col-check">NO</th>
                <th class="col-check">N/A</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1. Are there registered building plans for the whole property, all improvements and solid roofed structures (e.g. carports, pools, etc.)</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
            </tr>
            <tr>
                <td>
                    2. Are you in possession of a valid Certificate of Compliance for the following:
                    <div style="padding-left: 12pt; margin-top: 4pt;">
                        &bull; Electrical Compliance Certificate &ndash; If Yes, when was it issued? <span class="field field-medium" data-field="electrical_cert_date">{{ $electrical_cert_date ?? '' }}</span><br>
                        &bull; Electrical Fence Certificate &ndash; If Yes, when was it issued? <span class="field field-medium" data-field="fence_cert_date">{{ $fence_cert_date ?? '' }}</span><br>
                        &bull; Gas Compliance Certificate &ndash; If Yes, when was it issued? <span class="field field-medium" data-field="gas_cert_date">{{ $gas_cert_date ?? '' }}</span><br>
                        &bull; Entomology Certificate &ndash; If Yes, when was it issued? <span class="field field-medium" data-field="entomology_cert_date">{{ $entomology_cert_date ?? '' }}</span>
                    </div>
                </td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
                <td class="col-check">&square;</td>
            </tr>
        </tbody>
    </table>

    {{-- Additional Information --}}
    <div class="clause">
        <div class="clause-heading">Additional Information</div>
    </div>
    <div class="info-line"></div>
    <div class="info-line"></div>
    <div class="info-line"></div>
    <div class="info-line"></div>
    <div class="info-line"></div>
    <div class="info-line"></div>

    <div class="initials-row">
        @foreach($initialsParties ?? [] as $party)
            <div class="initial-block">
                <div class="initial-line"></div>
                <div class="initial-label">{{ $party }}</div>
            </div>
        @endforeach
    </div>

    {{-- Footer --}}
    <div class="doc-footer">Version 7</div>

</div>

{{-- ============================================================
     PAGE 3 — Clauses 6-9 + Signatures
     ============================================================ --}}
<div class="page page-break">

    {{-- Company Header --}}
    @include('docuperfect.web-templates.components.company-header')

    {{-- 6. Lessor's certification --}}
    <div class="clause">
        <div class="clause-heading">6. Lessor&rsquo;s certification</div>
        <p>The lessor hereby certifies that the information provided in this report is, to
            the best of the lessor&rsquo;s knowledge and belief, true and correct as at the date
            when the lessor signs this report.</p>
    </div>

    {{-- 7. Certification by person supplying information --}}
    <div class="clause">
        <div class="clause-heading">7. Certification by person supplying information</div>
        <p>If a person other than the lessor of the property provides the required
            information that person must certify that he/she is duly authorised by the lessor
            to supply the information and that he/she has supplied the correct information on
            which the lessor relied for the purposes of this report and, in addition, that the
            information contained herein is, to the best of that person&rsquo;s knowledge and
            belief, true and correct as at the date on which that person signs this report.</p>
    </div>

    {{-- 8. Notice regarding advice or inspections --}}
    <div class="clause">
        <div class="clause-heading">8. Notice regarding advice or inspections</div>
        <p>Both the lessor as well as potential tenants of the property may wish to obtain
            professional advice and/or to undertake a professional inspection of the property.
            Under such circumstances adequate provisions must be contained in any agreement of
            lease to be concluded between the parties pertaining to the obtaining of any such
            professional advice and/or the conducting of required inspections and/or the
            disclosure of defects and/or the making of required warranties.</p>
    </div>

    {{-- 9. Tenant's acknowledgement --}}
    <div class="clause">
        <div class="clause-heading">9. Tenant&rsquo;s acknowledgement</div>
        <p>The prospective tenant acknowledges that he/she has been informed that professional
            expertise and/or technical skill and knowledge may be required to detect defects
            in, and non-compliant aspects concerning, the property. The prospective tenant
            acknowledges receipt of a copy of this statement.</p>
    </div>

    {{-- 10. Signatures --}}
    <div class="clause">
        <div class="clause-heading">10. Signatures</div>
    </div>

    @php
        $sigParties = [];
        $sigNames = [];
        if (!empty($lessor_signature_name ?? '')) { $sigParties[] = 'Owner'; $sigNames[] = $lessor_signature_name; }
        if (!empty($lessor_signature_name_2 ?? '')) { $sigParties[] = 'Owner'; $sigNames[] = $lessor_signature_name_2; }
        if (!empty($lessee_signature_name ?? '')) { $sigParties[] = 'Tenant'; $sigNames[] = $lessee_signature_name; }
        if (!empty($agent_signature_name ?? '')) { $sigParties[] = 'Agent'; $sigNames[] = $agent_signature_name; }
    @endphp

    {{-- Lessor Signature --}}
    <div class="signature-section" data-marker-party="owner" data-marker-index="0">
        <p><strong>Lessor</strong></p>
        <p>Signed at <span class="field field-medium" data-field="lessor_signed_at">{{ $lessor_signed_at ?? '' }}</span>
            on <span class="field field-medium" data-field="lessor_signed_date">{{ $lessor_signed_date ?? '' }}</span></p>
        <p style="margin-top: 8pt;">Signature of Lessor <span class="field" data-field="lessor_signature">{{ $lessor_signature ?? '' }}</span></p>
    </div>

    {{-- Tenant Signature --}}
    <div class="signature-section" data-marker-party="tenant" data-marker-index="1">
        <p><strong>Tenant</strong></p>
        <p>Signed at <span class="field field-medium" data-field="tenant_signed_at">{{ $tenant_signed_at ?? '' }}</span>
            on <span class="field field-medium" data-field="tenant_signed_date">{{ $tenant_signed_date ?? '' }}</span></p>
        <p style="margin-top: 8pt;">Signature of Tenant <span class="field" data-field="tenant_signature">{{ $tenant_signature ?? '' }}</span></p>
    </div>

    {{-- Property Practitioner Signature --}}
    <div class="signature-section" data-marker-party="agent" data-marker-index="2">
        <p><strong>Property Practitioner</strong></p>
        <p>Signed at <span class="field field-medium" data-field="practitioner_signed_at">{{ $practitioner_signed_at ?? '' }}</span>
            on <span class="field field-medium" data-field="practitioner_signed_date">{{ $practitioner_signed_date ?? '' }}</span></p>
        <p style="margin-top: 8pt;">Signature of Property Practitioner <span class="field" data-field="practitioner_signature">{{ $practitioner_signature ?? '' }}</span></p>
        <p style="margin-top: 8pt;">Co-signature (if required)</p>
        <p>Signature of Property Practitioner <span class="field" data-field="co_signature">{{ $co_signature ?? '' }}</span></p>
    </div>

    {{-- Footer --}}
    <div class="doc-footer">Version 7</div>

</div>

</body>
</html>
