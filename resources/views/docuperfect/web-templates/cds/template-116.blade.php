<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marketing Permission v11</title>
    <link href="/css/corex-document.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
@php
    // Resolve agency for template body — same logic as company-header component
    $agency = null;
    if (isset($previewAgency)) {
        $agency = $previewAgency;
    } elseif (isset($branch)) {
        $agency = $branch->agency;
    } elseif (Auth::check()) {
        $branchId = Auth::user()->effectiveBranchId();
        if ($branchId) {
            $b = \App\Models\Branch::with('agency')->find($branchId);
            $agency = $b?->agency;
        }
    }
    if (!$agency) {
        $agency = \App\Models\Agency::where('slug', 'hfc-coastal')->first();
    }
@endphp

<div class="corex-document-wrapper" data-template-id="116">
<div class="corex-page">

@include('docuperfect.web-templates.components.company-header')

<div class="corex-title-banner">
    <h1 class="corex-doc-title">MARKETING PERMISSION</h1>
    <p class="corex-doc-subtitle">Sign this so we can legally market your property.</p>
</div>

<table class="corex-table mp-detail-table">
    <tbody>
        <tr>
            <td class="mp-row-label">Property</td>
            <td>
                <div class="mp-detail-grid">
                    <div>Erf / Unit: <span class="corex-field" data-field="property.erf">{{ $property_erf ?? '' }}</span></div>
                    <div>Street address: <span class="corex-field" data-field="property.street_address">{{ $property_street_address ?? '' }}</span></div>
                    <div>Suburb / Complex: <span class="corex-field" data-field="property.suburb">{{ $property_suburb ?? '' }}</span></div>
                    <div>District: <span class="corex-field" data-field="property.district">{{ $property_district ?? '' }}</span></div>
                </div>
            </td>
        </tr>
        <tr>
            <td class="mp-row-label">Owner 1</td>
            <td>
                <div class="mp-detail-grid">
                    <div>Name: <span class="corex-field" data-field="owner_1.full_name">{{ $owner_1_full_name ?? '' }}</span></div>
                    <div>ID: <span class="corex-field" data-field="owner_1.id_number">{{ $owner_1_id_number ?? '' }}</span></div>
                    <div>Cell: <span class="corex-field" data-field="owner_1.cell">{{ $owner_1_cell ?? '' }}</span></div>
                    <div>Email: <span class="corex-field" data-field="owner_1.email">{{ $owner_1_email ?? '' }}</span></div>
                </div>
            </td>
        </tr>
        <tr>
            <td class="mp-row-label">Owner 2</td>
            <td>
                <div class="mp-detail-grid">
                    <div>Name: <span class="corex-field" data-field="owner_2.full_name">{{ $owner_2_full_name ?? '' }}</span></div>
                    <div>ID: <span class="corex-field" data-field="owner_2.id_number">{{ $owner_2_id_number ?? '' }}</span></div>
                    <div>Cell: <span class="corex-field" data-field="owner_2.cell">{{ $owner_2_cell ?? '' }}</span></div>
                    <div>Email: <span class="corex-field" data-field="owner_2.email">{{ $owner_2_email ?? '' }}</span></div>
                </div>
            </td>
        </tr>
        <tr>
            <td class="mp-row-label">Terms</td>
            <td>
                <div class="mp-terms-checkboxes" data-field="terms.transaction_type">
                    <label><input type="checkbox" value="sale" {{ ($terms_transaction_type ?? '') === 'sale' ? 'checked' : '' }}> Sale</label>
                    <label><input type="checkbox" value="lease" {{ ($terms_transaction_type ?? '') === 'lease' ? 'checked' : '' }}> Letting</label>
                </div>
                <div class="mp-detail-grid">
                    <div>Price / Rental: R <span class="corex-field" data-field="terms.price">{{ $terms_price ?? '' }}</span></div>
                    <div>Commission: <span class="corex-field" data-field="terms.commission_pct">{{ $terms_commission_pct ?? '' }}</span> %</div>
                </div>
            </td>
        </tr>
    </tbody>
</table>

<div class="corex-clause">
    <span class="corex-clause-text">
        This is an <strong>OPEN MARKETING PERMISSION</strong> &mdash; you may list with other agents
        at the same time. The property remains on the market until it is sold or let, or until
        either party cancels on <strong>7 DAYS&rsquo; WRITTEN NOTICE</strong> (email or WhatsApp is
        fine). Commission is only payable if {{ $agency->trading_name ?? '' }} is the effective cause
        of a successful sale or lease, and is paid on transfer / lease commencement. We will market the
        property only at the price agreed above. We will not act with power of attorney on your behalf,
        and you are not obliged to use any conveyancer or service provider we recommend.
    </span>
</div>

<div class="corex-ffc-warranty">
    {{ $agency->trading_name ?? '' }} hereby warrants the validity of his/her/its Fidelity Fund
    Certificate as at the date of signature of this Agreement.
</div>

<div class="corex-clause">
    <span class="corex-clause-text corex-text-small">
        <strong>ATTACHMENTS:</strong> Signed together with the Mandatory Disclosure Form (PPA s67) and
        your FICA documents &mdash; marketing only begins once all three are complete.
        <strong>POPIA:</strong> Information processed for this property&rsquo;s marketing, retained
        5 years per law.
        @if($agency->popi_url)
            Privacy policy: {{ $agency->popi_url }}
        @endif
    </span>
</div>

@include('docuperfect.web-templates.components.signature-block', ['parties' => ['Seller', 'Agent']])

</div>
</div>

</body>
</html>
