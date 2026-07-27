<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Immovable Property Condition Report (Mandatory Disclosure)</title>
    <link href="/css/corex-document.css" rel="stylesheet">
    <style>
        /* Prescribed-form fidelity (Property Practitioners Act 22 of 2019, s70 /
           Regulations 2022 s36). Inline so the PDF (which inlines corex-document.css)
           and the screen render identically. ONLY the letterhead varies per agency. */
        .mdf-title { font-size: 12pt; font-weight: 700; text-align: center; line-height: 1.3; margin: 6pt 0 12pt; }
        .mdf-title small { display: block; font-size: 8.5pt; font-weight: 600; margin-top: 3pt; }
        .mdf-clause { margin: 0 0 9pt; }
        .mdf-h { font-weight: 700; font-size: 10pt; margin-bottom: 2pt; }
        .mdf-clause p { margin: 0 0 4pt; text-align: justify; }
        .mdf-sub { margin-left: 18pt; }
        .mdf-blank { display: inline-block; min-width: 220pt; border-bottom: 1px solid #333; line-height: 1.4; }
        /* ADDITIONAL INFORMATION is powered by the reusable frames engine (each
           entry = a frame carrying its own all-party initials). Strip the generic
           block chrome so the prescribed "ADDITIONAL INFORMATION" heading stands
           alone and the section reads like the form. */
        .corex-page .insertable-block { border-left: none !important; background: transparent !important; padding: 0 !important; margin: 4pt 0 0 !important; }
        .corex-page .insertable-block .block-header { display: none !important; }
        .mdf-sig { margin: 10pt 0 0; }
        .mdf-sig-title { font-weight: 700; margin-bottom: 3pt; }
        .mdf-field { display: inline-block; min-width: 120pt; border-bottom: 1px solid #333; }
        .mdf-field-sm { min-width: 28pt; text-align: center; }
        .mdf-sig-line { display: inline-block; min-width: 220pt; border-bottom: 1px solid #333; min-height: 26pt; vertical-align: bottom; }
    </style>
</head>
<body>
<div class="corex-document-wrapper">
<div class="corex-page">

@include("docuperfect.web-templates.components.company-header")

<div class="mdf-title">
    IMMOVABLE PROPERTY CONDITION REPORT IN RELATION TO THE SALE OF ANY IMMOVABLE PROPERTY
    <small>(Property Practitioner Act 22 of 2019, Section 70 &ndash; Property Practitioners Regulations 2022 Section 36 &ndash; Mandatory Disclosure)</small>
</div>

{{-- 1. Disclaimer --}}
<div class="mdf-clause">
    <div class="mdf-h">1. Disclaimer</div>
    <p>This condition report concerns the immovable property situated at
        <span class="mdf-blank corex-field-value" data-field="property_full_address">{{ $property_full_address ?? '' }}</span>
        (the &ldquo;Property&rdquo;). This report does not constitute a guarantee or warranty of any kind by the
        owner of the Property or by the property practitioners representing that owner in any transaction.
        This report should, therefore, not be regarded as a substitute for any inspections or warranties
        that prospective purchasers may wish to obtain prior to concluding an agreement of sale in respect
        of the Property.</p>
</div>

{{-- 2. Definitions --}}
<div class="mdf-clause">
    <div class="mdf-h">2. Definitions</div>
    <p>In this form &ndash;</p>
    <p class="mdf-sub">2.1 &ldquo;to be aware&rdquo; means to have actual notice or knowledge of a certain fact or state of affairs; and</p>
    <p class="mdf-sub">2.2 &ldquo;defect&rdquo; means any condition, whether latent or patent, that would or could have a significant
        deleterious or adverse impact on, or affect, the value of the property, that would or could
        significantly impair or impact upon the health or safety of any future occupants of the property or
        that, if not repaired, removed, or replaced, would or could significantly shorten or adversely affect
        the expected normal lifespan of the Property.</p>
</div>

{{-- 3. Disclosure of information --}}
<div class="mdf-clause">
    <div class="mdf-h">3. Disclosure of information</div>
    <p>The owner of the Property discloses the information hereunder in the full knowledge that, even though
        this is not to be construed as a warranty, prospective purchasers of the Property may rely on such
        information when deciding whether, and on what terms, to purchase the Property. The owner hereby
        authorises the appointed property practitioner marketing the Property for sale to provide a copy of
        this statement, and to disclose any information contained in this statement, to any person in
        connection with any actual or anticipated sale of the Property.</p>
</div>

{{-- 4. Provision of additional information --}}
<div class="mdf-clause">
    <div class="mdf-h">4. Provision of additional information</div>
    <p>The owner represents that to the best of his or her knowledge the responses to the statements in
        respect of the Property contained herein have been accurately noted as &ldquo;yes&rdquo;, &ldquo;no&rdquo; or &ldquo;not
        applicable&rdquo;. Should the owner have responded to any of the statements with a &ldquo;yes&rdquo;, the owner shall be
        obliged to provide, in the additional information area of this form, a full explanation as to the
        response to the statement concerned.</p>
</div>

{{-- 5. Statements in connection with Property --}}
<div class="mdf-clause">
    <div class="mdf-h">5. Statements in connection with Property</div>
</div>
<div class="corex-disclosure-checklist" data-section-type="disclosure_checklist" data-disclosure-index="0" data-disclosure-party="owner_party" contenteditable="false"><table class="corex-disclosure-table"><thead><tr><th class="corex-disclosure-statement">Statement</th><th class="corex-disclosure-option">YES</th><th class="corex-disclosure-option">NO</th><th class="corex-disclosure-option">N/A</th></tr></thead><tbody><tr class="corex-disclosure-row" data-item-index="0"><td class="corex-disclosure-statement">1. I am aware of the defects in the roof</td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="0" data-value="yes">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="0" data-value="no">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="0" data-value="na">&#9675;</span></td></tr><tr class="corex-disclosure-row" data-item-index="1"><td class="corex-disclosure-statement">2. I am aware of the defects in the electrical systems</td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="1" data-value="yes">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="1" data-value="no">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="1" data-value="na">&#9675;</span></td></tr><tr class="corex-disclosure-row" data-item-index="2"><td class="corex-disclosure-statement">3. I am aware of the defects in the plumbing system, including in the swimming pool (if any)</td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="2" data-value="yes">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="2" data-value="no">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="2" data-value="na">&#9675;</span></td></tr><tr class="corex-disclosure-row" data-item-index="3"><td class="corex-disclosure-statement">4. I am aware of the defects in the heating and air conditioning systems, including the air filters and humidifiers</td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="3" data-value="yes">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="3" data-value="no">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="3" data-value="na">&#9675;</span></td></tr><tr class="corex-disclosure-row" data-item-index="4"><td class="corex-disclosure-statement">5. I am aware of the defects in the septic or other sanitary disposal systems</td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="4" data-value="yes">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="4" data-value="no">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="4" data-value="na">&#9675;</span></td></tr><tr class="corex-disclosure-row" data-item-index="5"><td class="corex-disclosure-statement">6. I am aware of any defects to the property and/or in the basement or foundations of the property, including cracks, seepage and bulges. Other such defects include, but are not limited to, flooding, dampness or wet walls and unsafe concentrations of mould or defects in drain tiling or sump pumps</td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="5" data-value="yes">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="5" data-value="no">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="5" data-value="na">&#9675;</span></td></tr><tr class="corex-disclosure-row" data-item-index="6"><td class="corex-disclosure-statement">7. I am aware of structural defects in the Property</td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="6" data-value="yes">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="6" data-value="no">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="6" data-value="na">&#9675;</span></td></tr><tr class="corex-disclosure-row" data-item-index="7"><td class="corex-disclosure-statement">8. I am aware of boundary line dispute, encroachments, or encumbrances in connection with the Property</td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="7" data-value="yes">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="7" data-value="no">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="7" data-value="na">&#9675;</span></td></tr><tr class="corex-disclosure-row" data-item-index="8"><td class="corex-disclosure-statement">9. I am aware that remodelling and refurbishment have affected the structure of the Property</td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="8" data-value="yes">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="8" data-value="no">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="8" data-value="na">&#9675;</span></td></tr><tr class="corex-disclosure-row" data-item-index="9"><td class="corex-disclosure-statement">10. I am aware that any additions or improvements made to or any erections made on the property, have been done or were made, only after the required consents, permissions and permits to do so were properly obtained.</td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="9" data-value="yes">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="9" data-value="no">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="9" data-value="na">&#9675;</span></td></tr><tr class="corex-disclosure-row" data-item-index="10"><td class="corex-disclosure-statement">11. I am aware that a structure on the Property has been earmarked as a historic structure or heritage site</td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="10" data-value="yes">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="10" data-value="no">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="10" data-value="na">&#9675;</span></td></tr></tbody></table></div>

<div class="mdf-clause" style="margin-top:8pt;">
    <div class="mdf-h">ADDITIONAL INFORMATION</div>
    <p style="font-size:8.5pt;color:#475569;">A full explanation for every statement answered &ldquo;yes&rdquo; above. Each entry is initialled by every party.</p>
    {{-- Powered by the reusable other-conditions FRAMES engine: each additional-
         information entry is its own frame carrying per-frame all-party initials
         (adopted ink), the same add-entry mechanism, and the recipient-add →
         agent-review → re-engagement kicker. --}}
    ~~~~OTHER_CONDITIONS~~~~
</div>

{{-- 6. Owner's certification --}}
<div class="mdf-clause">
    <div class="mdf-h">6. Owner&rsquo;s certification</div>
    <p>The owner hereby certifies that the information provided in this report is, to the best of the owner&rsquo;s
        knowledge and belief, true and correct as at the date when the owner signs this report.</p>
</div>

{{-- 7. Certification by person supplying information --}}
<div class="mdf-clause">
    <div class="mdf-h">7. Certification by person supplying information</div>
    <p>If a person other than the owner of the property provides the required information that person must
        certify that he/she is duly authorised by the owner to supply the information and that he/she has
        supplied the correct information on which the owner relied for the purposes of this report and, in
        addition, that the information contained herein is, to the best of that person&rsquo;s knowledge and belief,
        true and correct as at the date on which that person signs this report.</p>
</div>

{{-- 8. Notice regarding advice or inspections --}}
<div class="mdf-clause">
    <div class="mdf-h">8. Notice regarding advice or inspections</div>
    <p>Both the owner as well as potential buyers of the property may wish to obtain professional advice
        and/or to undertake a professional inspection of the property. Under such circumstances adequate
        provisions must be contained in any agreement of sale to be concluded between the parties pertaining
        to the obtaining of any such professional advice and/or the conducting of required inspections and/or
        the disclosure of defects and/or the making of required warranties.</p>
</div>

{{-- 9. Buyer's acknowledgement --}}
<div class="mdf-clause">
    <div class="mdf-h">9. Buyer&rsquo;s acknowledgement</div>
    <p>The prospective buyer acknowledges that he/she has been informed that professional expertise and/or
        technical skill and knowledge may be required to detect defects in, and non-compliant aspects
        concerning, the property. The prospective buyer acknowledges receipt of a copy of this statement.</p>
</div>

{{-- 10. Signatures --}}
<div class="mdf-clause">
    <div class="mdf-h">10. Signatures</div>
</div>

@php
    // Prescribed "Signed at ___ on ___" per party, captured via the standard
    // ceremony markers (location + day/month/year) — no field_mappings needed;
    // the signature itself binds on [data-marker-party][data-marker-type="signature"].
    $mdfSigners = [
        ['role' => 'seller', 'label' => 'Seller',                'sigLabel' => 'Signature of Seller'],
        ['role' => 'buyer',  'label' => 'Purchaser',             'sigLabel' => 'Signature of Purchaser'],
        ['role' => 'agent',  'label' => 'Property Practitioner', 'sigLabel' => 'Signature of Property Practitioner'],
        ['role' => 'co_signer', 'label' => 'Co-signature (if required)', 'sigLabel' => 'Signature'],
    ];
@endphp
@foreach($mdfSigners as $i => $s)
<div class="mdf-sig signature-section" data-marker-party="{{ $s['role'] }}" data-marker-index="{{ $i }}">
    <div class="mdf-sig-title">{{ $s['label'] }}</div>
    <p>Signed at
        <span class="mdf-field" data-marker-party="{{ $s['role'] }}" data-marker-type="location">&nbsp;</span>
        on
        <span class="mdf-field mdf-field-sm" data-marker-party="{{ $s['role'] }}" data-marker-type="day">&nbsp;</span>
        <span class="mdf-field mdf-field-sm" data-marker-party="{{ $s['role'] }}" data-marker-type="month">&nbsp;</span>
        <span class="mdf-field mdf-field-sm" data-marker-party="{{ $s['role'] }}" data-marker-type="year">&nbsp;</span></p>
    <p style="margin-top:6pt;">{{ $s['sigLabel'] }}:
        <span class="mdf-sig-line" data-marker-party="{{ $s['role'] }}" data-marker-type="signature">&nbsp;</span></p>
</div>
@endforeach

</div>
</div>

</body>
</html>
