<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CoreX Document Renderer Test</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="{{ asset('css/corex-document.css') }}" rel="stylesheet">
    <style>
        body {
            margin: 0;
            padding: 0;
            background: #f1f5f9;
        }
        .test-bar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: #0f172a;
            color: white;
            padding: 10px 24px;
            font-family: 'Figtree', sans-serif;
            font-size: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .test-bar a {
            color: #00d4aa;
            text-decoration: none;
        }
        .test-bar .badge {
            background: #dc2626;
            color: white;
            padding: 2px 8px;
            border-radius:6px;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
    </style>
</head>
<body>

<div class="test-bar no-print">
    <div>
        <span class="badge">TEST</span>
        &nbsp; CoreX Document Renderer &mdash; Sample Letting Mandate
    </div>
    <div>
        <a href="{{ route('docuperfect.templates.index') }}">Back to Templates</a>
    </div>
</div>

<x-corex-document
    title="LETTING MANDATE"
    subtitle="Sole and Exclusive Letting and Management Authority"
    reference="LM-2026-00142"
    :date="now()->format('d F Y')"
    :parties="[
        ['name' => 'Johan Reichel'],
        ['name' => 'Maria Reichel'],
        ['name' => 'Home Finders Coastal'],
    ]"
>

    {{-- ============================================================
         SECTION 1: DEFINITIONS
         ============================================================ --}}
    <h2 class="corex-h1">1. Definitions</h2>

    <div class="corex-clause corex-clause-indent-1">
        <span class="corex-clause-number">1.1</span>
        <span class="corex-clause-text">
            "The Landlord" means
            <span class="corex-field" data-field="landlord_name">
                <span class="corex-field-label">Landlord Full Name</span>
            </span>
            (ID:
            <span class="corex-field" data-field="landlord_id">
                <span class="corex-field-label">ID Number</span>
            </span>)
            of
            <span class="corex-field" data-field="landlord_address">
                <span class="corex-field-label">Landlord Address</span>
            </span>
        </span>
    </div>

    <div class="corex-clause corex-clause-indent-1">
        <span class="corex-clause-number">1.2</span>
        <span class="corex-clause-text">
            "The Agent" means
            <span class="corex-field" data-filled="true">Home Finders Coastal</span>,
            a duly authorised property practitioner registered with the PPRA.
        </span>
    </div>

    <div class="corex-clause corex-clause-indent-1">
        <span class="corex-clause-number">1.3</span>
        <span class="corex-clause-text">
            "The Property" means the property situated at
            <span class="corex-field" data-field="property_address">
                <span class="corex-field-label">Property Address</span>
            </span>
        </span>
    </div>


    {{-- ============================================================
         SECTION 2: PROPERTY DETAILS (table)
         ============================================================ --}}
    <h2 class="corex-h1">2. Property Details</h2>

    <table class="corex-table">
        <thead>
            <tr>
                <th style="width:40%">Detail</th>
                <th>Value</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Property Address</td>
                <td><span class="corex-field" data-field="property_address"><span class="corex-field-label">Address</span></span></td>
            </tr>
            <tr>
                <td>Erf / Unit Number</td>
                <td><span class="corex-field" data-field="erf_no"><span class="corex-field-label">Erf No</span></span></td>
            </tr>
            <tr>
                <td>Property Type</td>
                <td><span class="corex-field" data-field="property_type"><span class="corex-field-label">Type</span></span></td>
            </tr>
            <tr>
                <td>Number of Bedrooms</td>
                <td><span class="corex-field" data-field="bedrooms"><span class="corex-field-label">Bedrooms</span></span></td>
            </tr>
            <tr>
                <td>Monthly Rental</td>
                <td><span class="corex-field" data-field="rental_amount"><span class="corex-field-label">R Amount</span></span></td>
            </tr>
            <tr>
                <td>Deposit Required</td>
                <td><span class="corex-field" data-field="deposit_amount"><span class="corex-field-label">R Amount</span></span></td>
            </tr>
        </tbody>
    </table>


    {{-- ============================================================
         SECTION 3: APPOINTMENT (level 2 headings + sub-clauses)
         ============================================================ --}}
    <h2 class="corex-h1">3. Appointment of Agent</h2>

    <div class="corex-clause corex-clause-indent-1">
        <span class="corex-clause-number">3.1</span>
        <span class="corex-clause-text">
            The Landlord hereby appoints the Agent as sole and exclusive agent to let and manage the Property
            for a period commencing on
            <span class="corex-field" data-field="mandate_start"><span class="corex-field-label">Start Date</span></span>
            and terminating on
            <span class="corex-field" data-field="mandate_end"><span class="corex-field-label">End Date</span></span>.
        </span>
    </div>

    <h3 class="corex-h2">3.2 Commission</h3>

    <div class="corex-clause corex-clause-indent-2">
        <span class="corex-clause-number">3.2.1</span>
        <span class="corex-clause-text">
            The Landlord shall pay the Agent a procurement fee equal to
            <span class="corex-field" data-field="procurement_fee"><span class="corex-field-label">% Fee</span></span>
            of one month's rental plus VAT, payable upon successful placement of a tenant.
        </span>
    </div>

    <div class="corex-clause corex-clause-indent-2">
        <span class="corex-clause-number">3.2.2</span>
        <span class="corex-clause-text">
            The Landlord shall pay the Agent a monthly management fee of
            <span class="corex-field" data-field="management_fee"><span class="corex-field-label">% Fee</span></span>
            of the monthly rental plus VAT, deducted from rental collected.
        </span>
    </div>

    <div class="corex-clause corex-clause-indent-3">
        <span class="corex-clause-number">3.2.2.1</span>
        <span class="corex-clause-text">
            In the event that the Landlord terminates this mandate prior to the expiry date,
            the Agent shall be entitled to the management fee for the remaining period of the mandate.
        </span>
    </div>


    {{-- ============================================================
         SECTION 4: CONDITIONAL â€” VAT TOGGLE
         ============================================================ --}}
    <h2 class="corex-h1">4. Value Added Tax</h2>

    <div class="corex-condition-toggle no-print" x-data="{ vatRegistered: false }">
        <span style="font-size:11px; color:#64748b; font-weight:500;">Is the Landlord VAT registered?</span>
        <button class="corex-condition-option"
                :data-selected="vatRegistered ? 'true' : 'false'"
                @click="vatRegistered = true">Yes</button>
        <button class="corex-condition-option"
                :data-selected="!vatRegistered ? 'true' : 'false'"
                @click="vatRegistered = false">No</button>
    </div>

    <div class="corex-conditional" x-data="{ show: true }">
        <div class="corex-clause corex-clause-indent-1">
            <span class="corex-clause-number">4.1</span>
            <span class="corex-clause-text">
                All amounts stated herein are exclusive of Value Added Tax at the rate of 15%.
                The Landlord
                <span class="corex-field" data-field="vat_status"><span class="corex-field-label">is / is not</span></span>
                registered for VAT.
            </span>
        </div>
    </div>

    <div class="corex-conditional">
        <div class="corex-clause corex-clause-indent-1">
            <span class="corex-clause-number">4.2</span>
            <span class="corex-clause-text">
                Where the Landlord is a registered VAT vendor, the VAT registration number is
                <span class="corex-field" data-field="vat_number"><span class="corex-field-label">VAT Number</span></span>.
            </span>
        </div>
    </div>


    {{-- ============================================================
         SECTION 5: OTHER CONDITIONS (dashed zone)
         ============================================================ --}}
    <h2 class="corex-h1">5. Other Conditions</h2>

    <div class="corex-other-conditions">
        <div class="corex-other-conditions-empty">
            Additional conditions agreed between the parties may be recorded here.
            Each condition must be individually initialled by all parties.
        </div>
    </div>


    {{-- ============================================================
         SECTION 6: TENANT BANKING (recipient fill-in)
         ============================================================ --}}
    <h2 class="corex-h1">6. Landlord Banking Details</h2>

    <div class="corex-clause corex-clause-indent-1">
        <span class="corex-clause-number">6.1</span>
        <span class="corex-clause-text">
            Rental proceeds shall be deposited into the following account:
        </span>
    </div>

    <table class="corex-table">
        <tbody>
            <tr>
                <td style="width:35%; font-weight:500;">Account Holder</td>
                <td><span class="corex-field corex-field-recipient" data-field="bank_holder"><span class="corex-field-label">Recipient</span></span></td>
            </tr>
            <tr>
                <td style="font-weight:500;">Bank Name</td>
                <td><span class="corex-field corex-field-recipient" data-field="bank_name"><span class="corex-field-label">Recipient</span></span></td>
            </tr>
            <tr>
                <td style="font-weight:500;">Account Number</td>
                <td><span class="corex-field corex-field-recipient" data-field="bank_account"><span class="corex-field-label">Recipient</span></span></td>
            </tr>
            <tr>
                <td style="font-weight:500;">Branch Code</td>
                <td><span class="corex-field corex-field-recipient" data-field="bank_branch"><span class="corex-field-label">Recipient</span></span></td>
            </tr>
        </tbody>
    </table>


    {{-- ============================================================
         SIGNATURE SECTION
         ============================================================ --}}
    <div class="corex-signature-section">
        <div class="corex-signature-section-title">
            Thus done and signed
        </div>

        <div class="corex-signature-grid">
            {{-- Landlord 1 --}}
            <div class="corex-signature-block">
                <div class="corex-signature-role">Landlord</div>
                <div class="corex-signature-name">Johan Reichel</div>
                <div class="corex-signature-line">
                    <span class="corex-signature-prompt">Sign here</span>
                </div>
                <div class="corex-signature-date">Date: ____________________</div>
            </div>

            {{-- Landlord 2 --}}
            <div class="corex-signature-block">
                <div class="corex-signature-role">Landlord</div>
                <div class="corex-signature-name">Maria Reichel</div>
                <div class="corex-signature-line">
                    <span class="corex-signature-prompt">Sign here</span>
                </div>
                <div class="corex-signature-date">Date: ____________________</div>
            </div>

            {{-- Agent --}}
            <div class="corex-signature-block">
                <div class="corex-signature-role">Agent</div>
                <div class="corex-signature-name">Home Finders Coastal</div>
                <div class="corex-signature-line">
                    <span class="corex-signature-prompt">Sign here</span>
                </div>
                <div class="corex-signature-date">Date: ____________________</div>
            </div>

            {{-- Witness --}}
            <div class="corex-signature-block corex-signature-block-witness">
                <div class="corex-signature-role">Witness</div>
                <div class="corex-signature-name">&nbsp;</div>
                <div class="corex-signature-line">
                    <span class="corex-signature-prompt">Sign here</span>
                </div>
                <div class="corex-signature-date">Date: ____________________</div>
            </div>
        </div>
    </div>

</x-corex-document>

{{-- Alpine.js for condition toggles --}}
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

</body>
</html>
