<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>[COMPANY HEADER]</title>
    <link href="/css/corex-document.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<div class="corex-document-wrapper">
<div class="corex-page">

@include("docuperfect.web-templates.components.company-header")

<div class="corex-h1"><span style="letter-spacing: 0.8px;">MARKETING PERMISSION</span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text"><em>Your authority for us to market and sell your property</em></span></div>
<div class="corex-h1">SELLER DETAILS</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Please provide the details of the registered owner(s) of the property. Where there is more than one seller, complete the second seller block; where there is one seller, leave the second block blank.</span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text"><strong>Seller -&nbsp;</strong><span class="corex-field-value" data-field="seller_name_surname_id">{{ $seller_name_surname_id ?? '' }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><strong style="font-size: 10.5pt;">Seller - Marital status (Unmarried / C</strong><strong style="font-size: 10.5pt;">OP / </strong><strong style="font-size: 10.5pt;">ANC / Other):  </strong><span class="corex-field-value" data-field="marital_status">{{ $marital_status ?? '' }}</span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text"><strong>Seller - Physical address:  </strong><span class="corex-field-value" data-field="seller_address">{{ $seller_address ?? '' }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text"><strong>Seller - Telephone number:  </strong><span class="corex-field-value" data-field="seller_phone">{{ $seller_phone ?? '' }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text"><strong>Seller - Email address:  </strong><span class="corex-field-value" data-field="seller_email">{{ $seller_email ?? '' }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span style="color: rgb(15, 23, 42); font-size: 13px; font-weight: 600; letter-spacing: 0.8px; text-align: left; text-transform: uppercase;">PROPERTY DETAILS</span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text"><strong>Street address:  </strong><span class="corex-field-value" data-field="property_street">{{ $property_street ?? '' }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text"><strong>Erf / Sectional Scheme / Unit number:  </strong><span class="corex-field-value" data-field="property_erf_number">{{ $property_erf_number ?? '' }}</span> <strong>Complex / Estate name:  </strong><span class="corex-field-value" data-field="property_complex_name">{{ $property_complex_name ?? '' }}</span>  </span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text"><strong>Township / Suburb:  </strong><span class="corex-field-value" data-field="property_township">{{ $property_township ?? '' }}</span>  <strong>Property - District:  </strong><span class="corex-field-value" data-field="property_district">{{ $property_district ?? '' }}</span></span></div>
<div class="corex-h1">GRANT OF MARKETING PERMISSION</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">I / We, the seller(s) identified above, being the registered owner(s) or duly authorised representative(s) of the owner(s) of the property described above, together with all fixtures and fittings of a permanent nature pertaining to the property, do hereby grant to Home Finders Coastal the marketing permission, right and authority to market and sell the abovementioned property.</span></div>
<div class="corex-h1">PRICE AND PROFESSIONAL FEE</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text"><strong>Asking price (Rand):  </strong><span class="corex-field-value" data-field="price">{{ $price ?? '' }}</span>      <strong>Asking price in words:  </strong><span class="corex-field-value" data-field="price_in_words">{{ $price_in_words ?? '' }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">The asking price is the price at which the property will be advertised, or such other price as may be agreed upon by the Seller.</span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text"><strong>Professional fee percentage (%):  </strong><span class="corex-field-value" data-field="property_commission_percent">{{ $property_commission_percent ?? '' }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">The Seller shall pay Home Finders Coastal the professional fee stated above, plus VAT where applicable, of the price at which the property is sold. The professional fee is payable on registration of transfer.</span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">The Seller consents to Home Finders Coastal placing a "For Sale" board on the property.</span></div>
<div class="corex-h1">FICA AND POPIA</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Before marketing begins, the Seller will complete the Mandatory Disclosure Form and provide the FICA documents required to verify the Seller’s identity. Home Finders Coastal will handle the Seller’s personal information in line with the Protection of Personal Information Act (POPIA).</span></div>
<div class="corex-h1">FIDELITY FUND CERTIFICATE WARRANTY</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text"><em>[ FFC WARRANTY CLAUSE - INSERT THE EXACT, VERBATIM STATUTORY WORDING HERE. This clause is prescribed and must be reproduced word-for-word. Johan to insert the approved text from the attorney source before this template is used. ]</em></span></div>
<div class="corex-h1">SIGNING</div><div class="corex-h1"><br></div>
<div class="corex-clause corex-clause-indent-1"><span style="color: rgb(100, 116, 139); font-size: 11px; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase;">THUS DONE AND SIGNED&nbsp;</span></div><div class="corex-signature-section"><div class="corex-clause"><span class="corex-clause-text"><br></span></div><div class="corex-clause"><span class="corex-clause-text">Marketing Permission Agent Marketing Permission V6</span></div></div>

@include("docuperfect.web-templates.components.signature-block", ["parties" => ["Lessor", "Agent"]])

</div>
</div>

</body>
</html>
