<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ADDENDUM B</title>
    <link href="/css/corex-document.css" rel="stylesheet">
    <style>
        /* HFC Addendum B — standalone agency addendum (rides the same render/sign
           pipeline as any single doc). Only the letterhead varies per agency. */
        .adb-title { font-size: 13pt; font-weight: 700; text-align: center; margin: 6pt 0 4pt; letter-spacing: 0.5pt; }
        .adb-sub { font-size: 10pt; font-weight: 700; margin: 8pt 0 4pt; }
        .adb-coc-head td { background: #e8edf2; font-weight: 700; }
        .adb-when { display: inline-block; min-width: 130pt; border-bottom: 1px solid #333; }
        .adb-sig { margin: 10pt 0 0; }
        .adb-sig-title { font-weight: 700; margin-bottom: 3pt; }
        .adb-field { display: inline-block; min-width: 120pt; border-bottom: 1px solid #333; }
        .adb-field-sm { min-width: 28pt; text-align: center; }
        .adb-sig-line { display: inline-block; min-width: 220pt; border-bottom: 1px solid #333; min-height: 26pt; vertical-align: bottom; }
    </style>
</head>
<body>
<div class="corex-document-wrapper">
<div class="corex-page">

@include("docuperfect.web-templates.components.company-header")

<div class="adb-title">ADDENDUM B</div>
<div class="adb-sub">EXTRA INFORMATION</div>

<div class="corex-disclosure-checklist" data-section-type="disclosure_checklist" data-disclosure-index="0" data-disclosure-party="owner_party" contenteditable="false"><table class="corex-disclosure-table"><thead><tr><th class="corex-disclosure-statement">Statement</th><th class="corex-disclosure-option">YES</th><th class="corex-disclosure-option">NO</th></tr></thead><tbody><tr class="corex-disclosure-row" data-item-index="0"><td class="corex-disclosure-statement">Are there registered building plans for the whole property, all improvements and solid roof structures (e.g. carport, pools, etc)</td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="0" data-value="yes">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="0" data-value="no">&#9675;</span></td></tr><tr class="adb-coc-head"><td colspan="3">Are you in possession of a valid Certificate of Compliance for the following:</td></tr><tr class="corex-disclosure-row" data-item-index="1"><td class="corex-disclosure-statement">Electrical Compliance Certificate &ndash; If Yes, when was it issued? <span class="adb-when corex-field-value" data-field="coc_electrical_when">{{ $coc_electrical_when ?? '' }}</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="1" data-value="yes">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="1" data-value="no">&#9675;</span></td></tr><tr class="corex-disclosure-row" data-item-index="2"><td class="corex-disclosure-statement">Electrical Fence Certificate &ndash; If Yes, when was it issued? <span class="adb-when corex-field-value" data-field="coc_electric_fence_when">{{ $coc_electric_fence_when ?? '' }}</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="2" data-value="yes">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="2" data-value="no">&#9675;</span></td></tr><tr class="corex-disclosure-row" data-item-index="3"><td class="corex-disclosure-statement">Gas Compliance Certificate &ndash; If Yes, when was it issued? <span class="adb-when corex-field-value" data-field="coc_gas_when">{{ $coc_gas_when ?? '' }}</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="3" data-value="yes">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="3" data-value="no">&#9675;</span></td></tr><tr class="corex-disclosure-row" data-item-index="4"><td class="corex-disclosure-statement">Entomology Certificate &ndash; If Yes, when was it issued? <span class="adb-when corex-field-value" data-field="coc_entomology_when">{{ $coc_entomology_when ?? '' }}</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="4" data-value="yes">&#9675;</span></td><td class="corex-disclosure-option"><span class="corex-radio-placeholder" data-item="4" data-value="no">&#9675;</span></td></tr></tbody></table></div>

{{-- Signatures — Seller / Purchaser / Property Practitioner / Co-signature --}}
@php
    $adbSigners = [
        ['role' => 'seller', 'label' => 'Seller',                'sigLabel' => 'Signature of Seller'],
        ['role' => 'buyer',  'label' => 'Purchaser',             'sigLabel' => 'Signature of Purchaser'],
        ['role' => 'agent',  'label' => 'Property Practitioner', 'sigLabel' => 'Signature of Property Practitioner'],
        ['role' => 'co_signer', 'label' => 'Co-signature (if required)', 'sigLabel' => 'Signature'],
    ];
@endphp
@foreach($adbSigners as $i => $s)
<div class="adb-sig signature-section" data-marker-party="{{ $s['role'] }}" data-marker-index="{{ $i }}">
    <div class="adb-sig-title">{{ $s['label'] }}</div>
    <p>Signed at
        <span class="adb-field" data-marker-party="{{ $s['role'] }}" data-marker-type="location">&nbsp;</span>
        on
        <span class="adb-field adb-field-sm" data-marker-party="{{ $s['role'] }}" data-marker-type="day">&nbsp;</span>
        <span class="adb-field adb-field-sm" data-marker-party="{{ $s['role'] }}" data-marker-type="month">&nbsp;</span>
        <span class="adb-field adb-field-sm" data-marker-party="{{ $s['role'] }}" data-marker-type="year">&nbsp;</span></p>
    <p style="margin-top:6pt;">{{ $s['sigLabel'] }}:
        <span class="adb-sig-line" data-marker-party="{{ $s['role'] }}" data-marker-type="signature">&nbsp;</span></p>
</div>
@endforeach

</div>
</div>

</body>
</html>
