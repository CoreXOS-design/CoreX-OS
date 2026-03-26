<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mandate entered into between</title>
    <link href="/css/corex-document.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<div class="corex-document-wrapper">
<div class="corex-page">

@include("docuperfect.web-templates.components.company-header")

<div class="corex-h2">Mandate entered into between</div>
<div class="corex-h2">The Parties</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">The Owner/s:	<span class="corex-field-value" style="border-bottom:1px solid #333; min-width:80pt; display:inline-block;">&nbsp;</span></span>		</span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Home Finders Coastal (Agent):	<span class="corex-field-value" style="border-bottom:1px solid #333; min-width:80pt; display:inline-block;">&nbsp;</span></span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-number">1</span> <span class="corex-clause-text"> The owner hereby grants to the Agent a Mandate to offer to let the property known </span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">as <span class="corex-field-value" style="border-bottom:1px solid #333; min-width:80pt; display:inline-block;">&nbsp;</span></span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">subject to the conditions set out in this agreement.</span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-number">2</span> <span class="corex-clause-text">The rental amount required by the Owner for the property is R<span class="corex-field-value" data-field="deal_amount">{{ $deal_amount ?? '' }}</span></span> which includes the commission as stated in clause 4.  In the event of the Agency not finding a suitable Tenant to rent the property at such rental amount, then, between the Owner and the Agency they will agree to an acceptable rental amount prior to allowing any tenant taking occupation of the said property, which includes commission as stated in clause 4.</span></div>
@include("docuperfect.web-templates.components.signature-line", ['party' => 'landlord'])
@include("docuperfect.web-templates.components.signature-line", ['party' => 'agent'])</div>

@include("docuperfect.web-templates.components.signature-block", ["parties" => ["Lessor", "Agent"]])

</div>
</div>

</body>
</html>
