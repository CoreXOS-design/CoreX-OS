{{--
    DESIGN SYSTEM COMPLIANCE: this is a print/legal A4 document template,
    not a CoreX UI surface — it follows the established web-template
    convention (see letting-marketing-permission-v7.blade.php), NOT the
    app design tokens.

    Source of truth: resources/docs/source/HFC_Marketing_Permission_V6.docx
    Document text is reproduced VERBATIM (STANDARDS: Document Fidelity is
    Non-Negotiable). The FIDELITY FUND CERTIFICATE WARRANTY clause is a
    bracketed placeholder in the source docx and is reproduced exactly as
    written — no statutory text is invented here.

    Field mechanism: every fillable field is a
    <span class="field" data-field="KEY">{{ $KEY ?? '' }}</span>.
    Recipient/property fields carry a named_field_id source mapping
    (MarketingPermissionV6Seeder) so autoFillFields resolves seller +
    property data into them; choice fields (Marital Status, SA Resident,
    Price basis) are agent-selected via fields_json type:'selection'.
    Signatures use the shared recipient-driven signature-block partial
    (one cell per actual signer; empty signer slots suppressed; emits
    data-marker-type="signature") — the proven path used by
    cds/template-117 and cds/template-120.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marketing Permission{{ !empty($docAgency['name']) ? ' — ' . $docAgency['name'] : '' }}</title>
    <style>
        @page { size: A4; margin: 16mm 18mm 14mm 18mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Segoe UI", Arial, sans-serif; font-size: 10pt; color: #1a1a1a; line-height: 1.45; }
        .doc { width: 210mm; max-width: 100%; margin: 0 auto; padding: 0 2mm; }

        .letterhead { text-align: center; border-bottom: 2pt solid #0b2a4a; padding-bottom: 6pt; margin-bottom: 10pt; }
        .letterhead h1 { font-size: 16pt; letter-spacing: 1pt; color: #0b2a4a; }
        .letterhead p { font-size: 7.5pt; color: #444; margin-top: 2pt; }

        .doc-title { text-align: center; margin: 10pt 0 4pt; }
        .doc-title h2 { font-size: 13pt; text-transform: uppercase; letter-spacing: 1.5pt; color: #0b2a4a; }
        .doc-title .sub { font-size: 9pt; font-style: italic; color: #555; margin-top: 2pt; }

        .section-head { background: #0b2a4a; color: #fff; font-size: 9pt; font-weight: 700;
            text-transform: uppercase; letter-spacing: 1pt; padding: 3pt 6pt; margin: 12pt 0 6pt; }

        table.details { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
        table.details td { border: 0.6pt solid #b9c2cc; padding: 4pt 6pt; vertical-align: top; }
        table.details td.lbl { width: 32%; background: #eef2f6; font-weight: 600; }

        .field { display: inline-block; min-width: 60pt; border-bottom: 0.7pt dotted #555;
            line-height: 1.4; padding: 0 2pt; }
        .field-wide { min-width: 220pt; }
        .field-medium { min-width: 120pt; }
        .field-short { min-width: 40pt; }
        .field-block { display: block; min-width: 100%; }

        .choice-row td { background: #fbfcfd; }
        .choice { font-size: 9.5pt; }

        p.clause { margin: 6pt 0; text-align: justify; }
        .ffc-warranty { border: 0.8pt solid #b9c2cc; background: #fbfbf4;
            padding: 8pt; margin: 8pt 0; font-size: 9pt; font-style: italic; color: #555; }

        .signed-line { margin: 14pt 0 6pt; font-size: 9.5pt; }

        .signature-section { margin-top: 14pt; }
        .signature-grid { display: grid; gap: 18pt; margin-top: 16pt; }
        .signature-col { text-align: center; }
        .signature-line { border-bottom: 1pt solid #1a1a1a; height: 30pt; margin-bottom: 3pt; }
        .signature-label { font-size: 9pt; font-weight: 600; text-transform: uppercase; }

        .doc-footer { margin-top: 14pt; text-align: right; font-size: 8pt; color: #999; }
    </style>
</head>
<body>
<div class="doc corex-document-wrapper">

    {{-- ===== Letterhead (verbatim) ===== --}}
    @include('docuperfect.web-templates.components.company-header')

    <div class="doc-title">
        <h2>MARKETING PERMISSION</h2>
        <div class="sub">Your authority for us to market and sell your property</div>
    </div>

    {{-- ===== Seller & Property details ===== --}}
    <div class="section-head">SELLER &amp; PROPERTY DETAILS</div>
    <table class="details">
        <tr>
            <td class="lbl">Seller 1 Name</td>
            <td><span class="field field-wide" data-field="seller1_name">{{ $seller1_name ?? '' }}</span></td>
        </tr>
        <tr>
            <td class="lbl">ID / Entity No</td>
            <td><span class="field field-medium" data-field="seller1_id">{{ $seller1_id ?? '' }}</span></td>
        </tr>
        <tr>
            <td class="lbl">Seller 2 Name</td>
            <td><span class="field field-wide" data-field="seller2_name">{{ $seller2_name ?? '' }}</span></td>
        </tr>
        <tr>
            <td class="lbl">ID / Entity No</td>
            <td><span class="field field-medium" data-field="seller2_id">{{ $seller2_id ?? '' }}</span></td>
        </tr>
        <tr class="choice-row">
            <td class="lbl">Marital Status</td>
            <td class="choice"><span class="field field-medium" data-field="marital_status">{{ $marital_status ?? '' }}</span>
                <span style="font-size:8pt;color:#777;">&nbsp;(Unmarried / In Community of Property / Out of Community (ANC) / Other)</span></td>
        </tr>
        <tr class="choice-row">
            <td class="lbl">SA Resident</td>
            <td class="choice"><span class="field field-short" data-field="sa_resident">{{ $sa_resident ?? '' }}</span>
                <span style="font-size:8pt;color:#777;">&nbsp;(Yes / No)</span></td>
        </tr>
        <tr>
            <td class="lbl">Contact (Tel / Email)</td>
            <td><span class="field field-wide" data-field="contact_tel_email">{{ $contact_tel_email ?? '' }}</span></td>
        </tr>
        <tr>
            <td class="lbl">Property Address</td>
            <td><span class="field field-wide" data-field="property_address">{{ $property_address ?? '' }}</span></td>
        </tr>
        <tr>
            <td class="lbl">Erf / Unit No</td>
            <td><span class="field field-medium" data-field="erf_unit_no">{{ $erf_unit_no ?? '' }}</span></td>
        </tr>
        <tr>
            <td class="lbl">Complex / Estate</td>
            <td><span class="field field-medium" data-field="complex_estate">{{ $complex_estate ?? '' }}</span></td>
        </tr>
        <tr>
            <td class="lbl">Township / Suburb</td>
            <td><span class="field field-medium" data-field="township_suburb">{{ $township_suburb ?? '' }}</span></td>
        </tr>
        <tr>
            <td class="lbl">District</td>
            <td><span class="field field-medium" data-field="district">{{ $district ?? '' }}</span></td>
        </tr>
        <tr>
            <td class="lbl">Asking Price (R)</td>
            <td><span class="field field-medium" data-field="asking_price">{{ $asking_price ?? '' }}</span></td>
        </tr>
        <tr>
            <td class="lbl">Amount in Words</td>
            <td><span class="field field-wide" data-field="amount_in_words">{{ $amount_in_words ?? '' }}</span></td>
        </tr>
        <tr class="choice-row">
            <td class="lbl">Price is</td>
            <td class="choice"><span class="field field-medium" data-field="price_basis">{{ $price_basis ?? '' }}</span>
                <span style="font-size:8pt;color:#777;">&nbsp;(Inclusive of our professional fee / Exclusive of our professional fee)</span></td>
        </tr>
    </table>

    {{-- ===== Our Agreement (verbatim, 5 paragraphs) ===== --}}
    <div class="section-head">OUR AGREEMENT</div>
    <p class="clause">By signing this document I/we, the registered owner/s of the property described above (or the person/s duly authorised to act on the owner&rsquo;s behalf), give {{ $docAgency['name'] }} permission and the authority to market and sell this property.</p>
    <p class="clause">This permission stays in place until the property is sold, or until either party cancels it on 7 (seven) days&rsquo; written notice. It is given on an open, non-exclusive basis unless we have separately agreed a sole mandate in writing.</p>
    <p class="clause">We may market the property on property portals, our website, social media and other channels, and place &ldquo;For Sale&rdquo; and &ldquo;Sold&rdquo; boards on the property. We will qualify prospective buyers, accompany viewings by appointment, present every offer to you, and keep you informed of feedback.</p>
    <p class="clause">You agree to pay {{ $docAgency['name'] }} a professional fee of 7.5% (seven comma five per cent) plus VAT, calculated on the selling price, payable on registration of transfer. You also confirm that, before marketing begins, you will complete the Mandatory Disclosure Form and provide the FICA documents we need to verify your identity.</p>
    <p class="clause">We will handle your personal information in line with POPIA{{ !empty($docAgency['privacy_url']) ? ' &mdash; ' : '.' }}{{ !empty($docAgency['privacy_url']) ? 'see our privacy policy at ' . $docAgency['privacy_url'] . '.' : '' }}</p>

    {{-- ===== FFC Warranty (verbatim placeholder from source docx) ===== --}}
    <div class="section-head">FIDELITY FUND CERTIFICATE WARRANTY</div>
    <p class="ffc-warranty">[ FFC WARRANTY CLAUSE &mdash; verbatim statutory wording to be inserted here. This clause is prescribed and must be reproduced word-for-word; insert the exact text from the approved v11 / attorney source. ]</p>

    {{-- ===== Execution & signatures =====
         Recipient-driven shared partial (proven path — cds/template-117,
         cds/template-120): one signature cell per actual signer of each
         role, empty slots suppressed (no phantom "Seller 2" for a single
         seller), each cell a real [data-marker-party][data-marker-type=
         "signature"] surface. The partial renders the full "Thus done and
         signed … at … on this … day of …" execution ceremony per role. --}}
    @include('docuperfect.web-templates.components.signature-block', ['parties' => ['Seller', 'Witness', 'Agent']])

    <p style="margin-top: 4pt; font-size: 9pt; text-align: center;">(Registered Owner/s or Duly Authorised Representatives)</p>

    <div class="doc-footer">Marketing Permission V6</div>

</div>
</body>
</html>
