<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lease Agreement — Home Finders Coastal</title>
    <style>
        /* ============================================================
           Lease Agreement POPI V8 — Print-quality A4 document
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
            text-transform: uppercase;
            margin: 14pt 0 10pt;
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

        /* ---- Section & Clause ---- */
        .section-heading {
            font-weight: bold;
            text-transform: uppercase;
            margin: 12pt 0 6pt;
            font-size: 11pt;
        }

        .clause {
            margin: 2pt 0;
            padding-left: 20pt;
            text-indent: -20pt;
        }

        .clause p {
            margin-bottom: 2pt;
        }

        .sub-clause {
            padding-left: 20pt;
            margin: 3pt 0;
        }

        .sub-sub-clause {
            padding-left: 40pt;
            margin: 3pt 0;
        }

        /* ---- CPA Notice ---- */
        .cpa-notice {
            border: 1pt solid #999;
            padding: 8pt 10pt;
            margin: 10pt 0;
            font-size: 9pt;
            line-height: 1.4;
        }

        .cpa-notice p {
            margin-bottom: 4pt;
        }

        .cpa-notice ul {
            margin: 4pt 0 4pt 16pt;
        }

        .cpa-notice ul li {
            margin-bottom: 3pt;
        }

        /* ---- Info Line ---- */
        .info-line {
            border-bottom: 1pt solid #1a1a1a;
            height: 24pt;
            margin-bottom: 2pt;
        }

        /* ---- Financial Table ---- */
        .financial-table {
            width: 100%;
            border-collapse: collapse;
            margin: 8pt 0;
        }

        .financial-table td {
            padding: 4pt 4pt;
            vertical-align: bottom;
        }

        .financial-table td:first-child {
            width: 280pt;
            font-weight: 600;
        }

        .financial-table td:last-child {
            border-bottom: 1pt solid #1a1a1a;
        }

        /* ---- Numbered List ---- */
        .numbered-list {
            margin: 6pt 0 6pt 20pt;
        }

        .numbered-list li {
            margin-bottom: 3pt;
        }

        /* ---- Signature Section ---- */
        .signature-section {
            margin-top: 14pt;
        }

        .signature-section p {
            margin-bottom: 4pt;
        }

        .signature-grid {
            display: grid;
            gap: 16pt;
            margin-top: 14pt;
        }

        .signature-col {
            text-align: center;
        }

        .signature-line {
            border-bottom: 1pt solid #1a1a1a;
            height: 28pt;
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

        /* ---- Page break ---- */
        .page-break {
            margin-top: 0;
            padding-top: 18mm;
        }

        /* ---- Initials ---- */
        .initials-row { display:flex; justify-content:flex-end; gap:20pt; margin-top:10pt; }
        .initial-block { text-align:center; }
        .initial-line { border-bottom:1pt solid #000; width:40pt; margin-bottom:2pt; }
        .initial-label { font-size:7pt; }
    </style>
</head>
<body>

{{-- ============================================================
     PAGE 1 — Parties & Interpretation
     ============================================================ --}}
<div class="page">

    @include('docuperfect.web-templates.components.company-header')

    <div class="doc-title">Lease Agreement - Residential</div>

    {{-- Section 1: Parties --}}
    <div class="section-heading">1. Parties</div>

    <div class="clause">
        <p>1. <span class="field" data-field="lessor_name">{{ $lessor_name ?? '' }}</span>@if(!empty($lessor_name_2)) &amp; <span class="field" data-field="lessor_name_2">{{ $lessor_name_2 }}</span>@endif (Lessor / Landlord)<br>
            Of (address) <span class="field" data-field="lessor_address">{{ $lessor_address ?? '' }}</span><br>
            ID/Passport/Registration No: <span class="field" data-field="lessor_id">{{ $lessor_id ?? '' }}</span></p>

        <p style="text-align: center; margin: 6pt 0; font-weight: bold;">AND</p>

        <p>2. <span class="field" data-field="lessee_name">{{ $lessee_name ?? '' }}</span>@if(!empty($lessee_name_2)) &amp; <span class="field" data-field="lessee_name_2">{{ $lessee_name_2 }}</span>@endif (Lessee / tenant / Occupant)<br>
            of (address) <span class="field" data-field="lessee_address">{{ $lessee_address ?? '' }}</span><br>
            ID/Passport/Registration No: <span class="field" data-field="lessee_id">{{ $lessee_id ?? '' }}</span></p>

        <p style="margin-top: 6pt;">It is agreed that, from date of occupation, the DOMICILIUM CITANDI ET
            EXECUTANDI will be the property address.</p>
    </div>

    {{-- CPA Notice --}}
    <div class="cpa-notice">
        <p><strong>Please note</strong> that according to the Consumer Protection Act 68 of 2008 the
            following is applicable to the Lessee:</p>
        <ul>
            <li>If you are a Juristic Entity (Company / Trust / Closed Corporation) with
                assets or turnover less than R2 million per annum the CPA is applicable.</li>
            <li>If you are an individual, private person leasing a property through an
                estate agency the CPA is applicable.</li>
            <li>If the Lessor and Lessee are Juristic persons the cancellation provisions
                of the CPA are not applicable regardless of turnover or assets.</li>
            <li>Should this lease agreement have been entered into as a result of direct
                marketing, the Lessee shall have 5 business days cooling off period,
                therefore the Landlord may continue to advertise and market the property
                until the 5 day period lapsed.</li>
            <li>Where the CPA is applicable the lessee may give a 20 business day notice,
                equal to 1 (one) month at any stage during the lease. By signing this
                Lease Agreement, the Lessee acknowledges that he/she is aware of the
                cancellation fees stipulated in this Lease Agreement.</li>
            <li>Please note that no Lease Agreement may be for a period longer than 24
                (twenty four) months.</li>
        </ul>
    </div>

    {{-- Section 2: Interpretation --}}
    <div class="section-heading">2. Interpretation</div>

    <div class="clause">
        <div class="sub-clause">2.1 The premises: being<br>
            Erf no: <span class="field field-short" data-field="erf_no">{{ $erf_no ?? '' }}</span>, (street address) <span class="field" data-field="street_address">{{ $street_address ?? '' }}</span><br>
            Unit no: <span class="field field-short" data-field="unit_no">{{ $unit_no ?? '' }}</span>, Complex: <span class="field" data-field="complex_name">{{ $complex_name ?? '' }}</span></div>

        <div class="sub-clause">2.2 The Rental: being the amount referred to in 4.1 or 5.2.2 as escalated
            in terms of 4.2.</div>

        <div class="sub-clause">2.3 The Estate Agent being: The firm: Home Finders Coastal</div>

        <div class="sub-clause">2.4 The Deposit: as referred to in clause 6.</div>

        <div class="sub-clause">2.5 Home Finders Coastal is the Agent, not the Landlord, and can only act
            on the instructions of the Landlord.</div>

        <div class="sub-clause">2.6 The LANDLORD remains the responsible party for any concerns, disputes
            or claims arising from the LEASE.</div>
    </div>

    <div class="initials-row">
        @foreach($initialsParties ?? [] as $party)
            <div class="initial-block">
                <div class="initial-line"></div>
                <div class="initial-label">{{ $party }}</div>
            </div>
        @endforeach
    </div>

    <div class="doc-footer">Version 8</div>

</div>

{{-- ============================================================
     PAGE 2 — Letting, Rental, Lease Period
     ============================================================ --}}
<div class="page page-break">

    @include('docuperfect.web-templates.components.company-header')

    {{-- Section 3: Letting and Hiring --}}
    <div class="section-heading">3. Letting and Hiring</div>

    <div class="clause">
        <div class="sub-clause">3.1 The Lessor hereby lets to the Lessee, who hereby hires, the Premises
            subject to the terms and conditions contained in this Agreement.</div>

        <div class="sub-clause">3.2 The Premises shall be personally occupied by <span class="field field-short" data-field="adults">{{ $adults ?? '' }}</span> Adults and
            not more than <span class="field field-short" data-field="other_persons">{{ $other_persons ?? '' }}</span> other persons.</div>
    </div>

    {{-- Section 4: Rental --}}
    <div class="section-heading">4. Rental</div>
    <p style="font-style: italic; font-size: 9pt; margin-bottom: 4pt;">Note: Delete 4.2 if not applicable.</p>

    <div class="clause">
        <div class="sub-clause">4.1 The Rental shall be <span class="field field-medium field-currency" data-field="rental_amount">{{ $rental_amount ?? '' }}</span> (in words)
            (<span class="field" data-field="rental_in_words">{{ $rental_in_words ?? '' }}</span> Rand) per month,
            subject to 4.2 and shall be paid monthly in advance on the 1st day of
            each month, free of any set-off, by means of bank/internet transfer of
            cash deposits into the Agency&rsquo;s banking account. The LESSEE must ensure
            that the Agency receives in their account, on or before the due date,
            all payments due. The LESSEE must make allowance for bank transfer
            delays. The Agency is required to report all cash deposits over R50 000
            to FICA. The LESSEE acknowledges that all cash deposit fees charged by
            the bank will be for the account of the LESSEE.</div>

        <div class="sub-clause">4.2 The Rental shall be subject to an escalation of <span class="field field-short" data-field="escalation_percent">{{ $escalation_percent ?? '' }}</span>%
            (<span class="field field-medium" data-field="escalation_in_words">{{ $escalation_in_words ?? '' }}</span>) per annum from the 1st day of
            <span class="field field-medium" data-field="escalation_month">{{ $escalation_month ?? '' }}</span> each year, and the amount referred to in
            4.1, escalated as aforesaid, shall then with effect from the said date
            constitute the Rental.</div>

        <div class="sub-clause">4.3 The LANDLORD or his Agent shall, on written request only provide the
            LESSEE with written receipt for all payments received. (Please refer
            the electronic invoice and payment link sent via email for your
            statement.)</div>
    </div>

    {{-- Section 5: Lease Period --}}
    <div class="section-heading">5. Lease Period</div>

    <div class="clause">
        <div class="sub-clause">5.1 The lease shall commence on <span class="field field-medium" data-field="lease_start">{{ $lease_start ?? '' }}</span>, and shall
            continue thereafter until terminated by either party giving the other
            not more than 80 (eighty) and not less than 40 (forty) business days&rsquo;
            notice of termination. Provided that such notice of termination &mdash;
            <div class="sub-sub-clause">5.1.1 may not be given by either party to expire prior to the
                <span class="field field-short" data-field="min_term_day">{{ $min_term_day ?? '' }}</span> day of <span class="field field-medium" data-field="min_term_month">{{ $min_term_month ?? '' }}</span> in the year <span class="field field-short" data-field="min_term_year">{{ $min_term_year ?? '' }}</span>; and</div>
            <div class="sub-sub-clause">5.1.2 shall be given only on or before the 1st day of any calendar month.</div>
        </div>

        <div class="sub-clause">5.2 The lease shall expire at midnight on <span class="field field-medium" data-field="lease_end">{{ $lease_end ?? '' }}</span> (the
            expiry date). With written consent from the Lessor, the Lessee has the
            option to renew the lease for a further period of <span class="field field-short" data-field="renewal_months">{{ $renewal_months ?? '' }}</span> months (the
            renewal period), commencing on the first day after the expiry date, on
            the same terms and conditions contained in this Agreement. Provided that &mdash;
            <div class="sub-sub-clause">5.2.1 The Lessee shall exercise this option by giving written notice to
                the Lessor on or before two months prior to expiry date as per
                clause 5.1.1 and 5.2 above failing which the option shall lapse.</div>
        </div>

        <div class="sub-clause">5.3 The Lessee agrees that the Agency may erect a Let by Board outside the
            property for 30 days after occupation of the property by the Lessee.</div>

        <div class="sub-clause">5.4 The lease and all provisions of the Lease shall continue on a month to
            month basis if this lease period expires and no renewal or extension to
            this lease has been signed and agreed to by the Lessee, Lessor and or
            Agency. Please refer to clause 17 on how the termination of a lease
            agreement works.</div>
    </div>

    <div class="initials-row">
        @foreach($initialsParties ?? [] as $party)
            <div class="initial-block">
                <div class="initial-line"></div>
                <div class="initial-label">{{ $party }}</div>
            </div>
        @endforeach
    </div>

    <div class="doc-footer">Version 8</div>

</div>

{{-- ============================================================
     PAGE 3 — Deposit, Electricity, Additional Payments, Lessee Obligations
     ============================================================ --}}
<div class="page page-break">

    @include('docuperfect.web-templates.components.company-header')

    {{-- Section 6: Deposit --}}
    <div class="section-heading">6. Deposit and Electricity Deposit</div>

    <div class="clause">
        <div class="sub-clause">6.1 The Lessee shall within 48 hours, after the Lessor has signed this
            Agreement pay a deposit equal to 1 month&rsquo;s rental to the Agency to be
            kept by him in trust until termination of the lease.</div>

        <div class="sub-clause">6.2 As and when the monthly rental payment increase in accordance with this
            agreement, the amount of the deposit shall automatically increase in the
            same proportion to the increase in the rental as stipulated in Clause
            1.2, which deposit shall be due and payable on or before the first day
            of the month following the month in which the increase monthly rental
            amount is payable.</div>

        <div class="sub-clause">6.3 On termination of the lease, the deposit shall be refunded to the
            Lessee as soon as possible less any amounts which the Lessor in his
            discretion may deduct for the payment of all amounts for which the
            Lessee is liable under this Agreement including but without limitation,
            arrear Rental, unpaid electricity and telephone accounts, the cost of
            repairing damage to the Premises, and/or replacing lost keys.</div>

        <div class="sub-clause">6.4 The Lessee shall not be entitled to use the deposit as rental for any
            month or for the last month(s) period of the lease.</div>

        <div class="sub-clause">6.5 Interest earned on the deposit while kept in an Interest bearing
            account accrues to the Lessee.</div>
    </div>

    {{-- Section 7: Electricity --}}
    <div class="section-heading">7. Electricity</div>

    <div class="clause">
        <p>Municipality electricity account to remain in the name of the Lessor. The
            monthly statements to be sent to the Lessee by the Lessor for settlement:<br>
            Direct settlement to the Agency <span class="field field-medium" data-field="electricity_settlement">{{ $electricity_settlement ?? '' }}</span></p>
    </div>

    {{-- Section 8: Additional Payments --}}
    <div class="section-heading">8. Additional Payments by Lessee</div>

    <div class="clause">
        <div class="sub-clause">8.1 The costs of drawing up this Lease Agreement and auxiliary expenses
            amounting to R1 200.00 (One Thousand Two Hundred Rand only) inclusive
            of VAT thereon, payable together with the deposit as per clause 6.
            The Lessee shall on demand pay to the Lessor all legal costs, as
            between attorney and client, incurred by the Lessor in respect of any
            legal steps taken by him against the Lessee to enforce any of the
            Lessee&rsquo;s obligations in terms of this Agreement. Except insofar as they
            are included in any levy payable by the Lessor in terms of clause 10,
            the Lessee shall from the date of commencement of this lease promptly
            pay for &mdash;<br>
            (a) All electricity;<br>
            (b) Any other fees payable in respect of services rendered to the
                Premises during the period of the lease.</div>

        <div class="sub-clause">8.2 In the event of the Lessee failing to make payment of any of the
            foregoing, the Lessor shall have the right without prejudice to his
            other rights in law or under this Agreement to effect payment himself
            and recover the amount/s so expended from the Lessee.</div>

        <div class="sub-clause">8.3 If rental is received late, as specified in Clause 4 above, more than
            three times during the Lease Agreement period, the Lessee shall be
            liable to pay a further 50% (fifty percent) of the existing Monthly
            Rental as an additional deposit.</div>
    </div>

    {{-- Section 9: Lessee's Obligations --}}
    <div class="section-heading">9. Lessee&rsquo;s Obligations</div>
    <p>The Lessee acknowledges that he shall:</p>

    <div class="clause">
        <div class="sub-clause">9.1 Maintain the whole interior of the premises and undertakes to deliver
            the premises to the landlord at termination of this lease in the same
            good order and condition as received.</div>

        <div class="sub-clause">9.2 Replace at his expense all light bulbs, fluorescent tubes, fluorescent
            starters, tap washers and water ballasts on the PREMISES as and when
            necessary. All repairs effected shall be to a level of quality
            acceptable to the LANDLORD.</div>

        <div class="sub-clause">9.3 Ensure that neither he nor any other person shall mark, paint or drive
            nails or affix screws or hooks which would, in any way, deface or
            damage the doors, walls, floors, or any part of the PREMISES, without
            the express prior written permission of the LANDLORD, which shall not
            be unreasonably withheld.</div>

        <div class="sub-clause">9.4 Keep and maintain all sewerage systems and waste/water/down pipes,
            guttering, and drains free from obstruction and blockages and shall
            remove, at his cost, any blockages or obstructions.</div>

        <div class="sub-clause">9.5 Clean all curtains, blinds, carpets and other floor coverings and
            tiles regularly, it being recorded that these items shall be replaced
            completely at the expense of the Lessee should they be damaged by the
            Lessee, or deteriorate in a manner not commensurate with ordinary wear
            and tear. It is recorded that all curtains, blinds and carpets on the
            PREMISES are to be professionally steam cleaned by the Lessee prior to
            his vacation of the PREMISES and proof of such to be handed to the
            Agency or the LANDLORD.</div>

        <div class="sub-clause">9.6 If applicable, keep the grounds of the PREMISES in a clean and tidy
            condition, free from all litter, and keep hedges and lawns trimmed,
            and the flowerbeds neat, tidy and free from weeds and the entire
            garden watered regularly.</div>

        <div class="sub-clause">9.7 If applicable, maintain the swimming pool, its filtration plant and
            keep all pool cleaning equipment in good working order and free from
            all obstruction and contamination. The Lessee shall pay for and
            administer such chemicals as are necessary for the proper maintenance
            of the pool, including the proper and frequent filtration of the water.
            The LANDLORD to arrange a pool net as per the Municipal Bylaw.</div>

        <div class="sub-clause">9.8 The Lessee is responsible for the upkeep of the garden including the
            trimming of hedges and trees, but will not cut or remove trees or
            plants or effect any alterations to the garden without written consent
            from the Landlord.</div>

        <div class="sub-clause">9.9 Should the LANDLORD agree to allow pets, subject to the rules of the
            Bodies mentioned above, the LANDLORD gives permission to only the
            following type and number of pets on the PREMISES:<br>
            <span class="field field-wide" data-field="pets_1">{{ $pets_1 ?? '' }}</span><br>
            <span class="field field-wide" data-field="pets_2">{{ $pets_2 ?? '' }}</span></div>

        <div class="sub-clause">9.10 Keep the electrical system in good working order and condition, fair
            wear and tear excepted.</div>

        <div class="sub-clause">9.11 All goods brought onto the premises by the Lessee shall be at the
            sole risk of the Lessee.</div>

        <div class="sub-clause">9.12 The Lessor shall not be liable for any loss sustained by the Lessee
            by reason of any burglary of or fire on the Premises or for any
            damage suffered by the Lessee as the result of any act or omission on
            the part of the Lessor as a result of any defect in the Premises.</div>

        <div class="sub-clause">9.13 The Lessee shall allow the Lessor to inspect the premises at all
            reasonable times after giving 24 (twenty four) hours&rsquo; notice to the
            Lessee.</div>
    </div>

    <div class="initials-row">
        @foreach($initialsParties ?? [] as $party)
            <div class="initial-block">
                <div class="initial-line"></div>
                <div class="initial-label">{{ $party }}</div>
            </div>
        @endforeach
    </div>

    <div class="doc-footer">Version 8</div>

</div>

{{-- ============================================================
     PAGE 4 — Lessor Obligations through Breach
     ============================================================ --}}
<div class="page page-break">

    @include('docuperfect.web-templates.components.company-header')

    {{-- Section 10: Lessor's Obligations --}}
    <div class="section-heading">10. Lessor&rsquo;s Obligations</div>

    <div class="clause">
        <div class="sub-clause">10.1 The Lessor shall be liable to pay all rates and taxes/levies payable
            in respect of the Premises to the local authority/body
            corporate/share block company/home owners&rsquo; association concerned.</div>

        <div class="sub-clause">10.2 The Lessor shall also keep the premises insured against fire and
            other unusual risks.</div>

        <div class="sub-clause">10.3 The Lessor shall keep all external windows, doors and walls, and
            roofs in order, but shall not be responsible for any damages to any
            of the Lessee&rsquo;s possessions as a result of any defect of any nature
            whatsoever.</div>

        <div class="sub-clause">10.4 The Lessor shall be responsible for all maintenance repairs related
            to all plumbing installations, including the hot water cylinder(s)
            on the premises.</div>
    </div>

    {{-- Section 11: Prohibition Against Subletting --}}
    <div class="section-heading">11. Prohibition Against Subletting and Parting with Possession</div>

    <div class="clause">
        <p>The Lessee shall not &mdash;</p>
        <div class="sub-clause">11.1 cede his rights or assign his obligations hereunder; or</div>
        <div class="sub-clause">11.2 sublet the Premises or any portion thereof; or</div>
        <div class="sub-clause">11.3 part with possession of the Premises or any portion thereof without
            the Lessor&rsquo;s prior written consent which, in the case of 11.2 and
            11.3, shall not be unreasonably withheld.</div>
    </div>

    {{-- Section 12: Occupation --}}
    <div class="section-heading">12. Occupation</div>

    <div class="clause">
        <div class="sub-clause">12.1 Notwithstanding any receipt given for rental or deposit paid in terms
            of the lease, the Lessee shall have no claim for damages or other
            right action against the Lessor, nor be entitled to cancel this
            lease, should the Lessor be unable to give the Lessee occupation of
            the premises on the date of commencement of the lease for any reason
            whatsoever not attributable to willful default on the part of the
            Lessor, and the lessee undertake to accept occupation from whatever
            date the Premises are available, subject to a remission of rental in
            respect of the period of non-occupation.</div>

        <div class="sub-clause">12.2 Should the Lessee fail to take occupation of the Premises on the date
            upon which the Premises are made available to him for occupation; the
            Lessor may without incurring any liability whatsoever towards the
            Lessee immediately cancel this Agreement without notice, whereupon
            the Lessee shall forfeit the Deposit paid by him while remaining
            liable for any loss of rental or other losses sustained by the
            Lessor. Provided that this clause shall not apply if the Lessor and
            Lessee have agreed in writing that the Lessee will not take physical
            occupation of the Premises on the said date.</div>

        <div class="sub-clause">12.3 In the event of the Lessee not being able to enjoy the beneficial
            occupation of the Premises as a result of them having been materially
            damaged by fire, earthquakes, weather storms, riot activity or the
            like and Lessor &mdash;
            <div class="sub-sub-clause">12.3.1 Failing within 30 days of the date of the damage to give the
                Lessee written notice that he intends to keep this lease alive,
                this lease shall be deemed to have been cancelled on the date
                that the damage occurred and the Lessor shall refund to the
                Lessee all rental paid in advance beyond the date of such
                damage; or</div>
            <div class="sub-sub-clause">12.3.2 Having given notice to the Lessee as aforesaid, the Lessor
                shall restore the Premises to a tenantable condition as
                expeditiously as practicable and the Lessee shall be entitled
                to a total or partial remission of rental according to the
                extent to which and the period for which he was deprived of
                beneficial occupation of the Premises. Save as provided in
                11.3.1 and 11.3.2 the Lessee shall have no other claims
                whatsoever against the Lessor.</div>
        </div>

        <div class="sub-clause">12.4 The Lessee may not without the Lessor&rsquo;s prior written consent which
            shall not be unreasonably withheld &mdash;<br>
            (a) Allow the Premises to remain unoccupied for any period exceeding
                six weeks; or<br>
            (b) Permit or allow any person other than those persons referred to
                in 3.2 above to occupy the Premises or to reside therein.</div>
    </div>

    {{-- Section 13: Defects, Inspections and Maintenance --}}
    <div class="section-heading">13. Defects, Inspections and Maintenance</div>

    <div class="clause">
        <div class="sub-clause">13.1 The Lessor and the Lessee shall jointly inspect the Premises before
            the Lessee moves into the Premises. After the inspection has been
            done by the Lessee and Lessor the Lessee has 7 (seven) days from the
            commencement date as specified in clause 5 to report any other
            damages to the property. Should the Lessee not attend the ingoing
            inspection with the Lessor, or report any damages within the 7
            (seven) days, the Lessee agrees that there are no damages in or to
            the Premises.</div>

        <div class="sub-clause">13.2 Any damages in or to the Premises at the ingoing inspection must be
            recorded on the Inspection list which shall be signed and dated by
            the Lessee and the Landlord, and attached to the Lease Agreement
            marked Annexure A.</div>

        <div class="sub-clause">13.3 The property is let and rented in the condition in which it is at the
            beginning of the Lease Agreement. No obligations shall rest on the
            Landlord to repair any of the defects listed in Annexure A, unless
            specially specified as a special condition of lease.</div>

        <div class="sub-clause">13.4 Should the outgoing inspection of the property be the same time as
            the ingoing inspection for another lessee, then the Lessee agrees to
            do this outgoing inspection the same time as the new Lessee&rsquo;s
            incoming inspection.</div>

        <div class="sub-clause">13.5 At the end of the Lease Agreement, the Lessor and the Lessee will
            jointly inspect the Premises for any damages on the last day of the
            lease, or up to 3 days after vacating the premises.</div>

        <div class="sub-clause">13.6 The Lessor may use any amount of the deposit/deposits, as set out in
            clause 6 and the interest thereon, towards the payment of the costs
            to repair any damage and/or lost keys to the Premises.</div>

        <div class="sub-clause">13.7 The Lessee agrees to the new Lessee of the Premises being present at
            such outgoing inspection with the Lessor.</div>

        <div class="sub-clause">13.8 On expiry of the lease the Lessee shall hand over the keys of the
            premises to the Lessor following the outgoing inspection by no later
            than 8 am on the day after the Termination date of the Lease
            Agreement unless otherwise agreed to in writing by the relevant
            parties. Failure to do this will entitle the Lessor to deduct from
            the deposit any damages suffered as a result of any loss of rental
            income.</div>

        <div class="sub-clause">13.9 The Lessee undertakes to maintain at his own cost the whole of the
            Premises, excluding the structure and roof of the house, for the full
            duration of the lease in the same state as they were received by him,
            reasonable fair wear and tear excepted, alternatively to reimburse
            the Lessor for the cost of replacing or repairing any breakages.</div>

        <div class="sub-clause">13.10 Should the Lessee fail to maintain the Premises and/or goods in the
            manner specified in this clause, the Lessor shall be entitled to
            carry out the necessary maintenance work at his discretion and to
            recover the full cost thereof from the Lessee.</div>

        <div class="sub-clause">13.11 On termination of the lease, the Lessee shall restore the whole of
            the Premises and the goods to the Lessor in the same good order and
            condition as they are at present, fair wear and tear excluded. The
            Lessor shall within 7 days after restoration of the Premises to him
            inspect the Premises and notify the Lessee in writing of all damages
            to or defects in the Premises for which the Lessee is liable in
            terms of clause 13.</div>
    </div>

    {{-- Section 14: Use of the Premises --}}
    <div class="section-heading">14. Use of the Premises by the Lessee</div>

    <div class="clause">
        <div class="sub-clause">14.1 The Lessee shall use the Premises solely for residential purposes and
            hereby specifically undertakes not to contravene any law, bylaw,
            ordinance or regulation applicable in respect of Premises in
            particular (if applicable) the rules applicable to the sectional
            title scheme of which the Premises forms part.</div>
        <div class="sub-clause">14.2 cause or permit any nuisance upon the Premises; or</div>
        <div class="sub-clause">14.3 allow pets or other animals to damage the Premises; or</div>
        <div class="sub-clause">14.4 deface, mark, paint or drive nails, hooks or screws into the doors,
            walls, ceilings or floors of the Premises, or place or display
            advertisements or notices of whatever nature on any part of the
            Premises, without the written consent of the Lessor; or keep any
            pets in or on the Premises without the Lessors&rsquo; prior written
            consent.</div>
    </div>

    {{-- Section 15: Improvements --}}
    <div class="section-heading">15. Improvements</div>

    <div class="clause">
        <p>Any improvements made by the Lessee on or to the Premises during the
            period of the lease shall become the property of the Lessor on termination
            of the lease and the Lessee shall not be entitled to remove any such
            improvement or claim from the Lessor any compensation in respect thereof.
            The Lessor shall be entitled at the termination of the lease to demand in
            writing that any improvement or addition made by the Lessee be removed by
            the Lessee at his own cost. The Lessee shall at his own expense and to the
            satisfaction of the Lessor repair all damage and/or defects caused by such
            removal.</p>
    </div>

    {{-- Section 16: Breach --}}
    <div class="section-heading">16. Breach</div>

    <div class="clause">
        <div class="sub-clause">16.1 In the event of either one of the parties (the defaulting party)
            committing a breach of any of the terms of this Agreement and failing
            to remedy such breach within a period of 7 (seven) days after receipt
            of a written notice from the other party (&ldquo;the aggrieved party&rdquo;)
            calling upon the defaulting party to remedy the breach complained of,
            then the aggrieved party shall be entitled at his sole discretion and
            without prejudice to any rights in law and/or in terms of this
            Agreement, either to claim specific performance of the terms of this
            Agreement or to cancel this Agreement forthwith and without further
            notice and claim damages from the defaulting party.</div>

        <div class="sub-clause">16.2 Provided that if the Lessee commits a breach of the provisions of
            this Agreement three times in any calendar year, then upon the third
            breach, the Lessor shall be entitled immediately to implement either
            of the remedies referred to above, without first having to give the
            Lessee written notice to rectify such breach.</div>

        <div class="sub-clause">16.3 Should this Agreement be cancelled by the Lessor for any reason
            whatsoever, the Lessee and or any other person occupying the Premises
            on the Lessee&rsquo;s behalf, shall immediately vacate the Premises and
            allow the Lessor to take occupation thereof.</div>

        <div class="sub-clause">16.4 In the event of this Lease Agreement being cancelled during the fixed
            or any Renewal Periods of the Lease by the Lessee, a Lease
            cancellation fee amounting to 10% (Ten percent) of the monthly
            rental for the remaining periods of the Lease is payable by the
            Lessee to the LANDLORD. Payment of a Lease cancellation fee does not
            constitute a waiver of the LANDLORD&rsquo;s rights to institute a damaged
            claim in respect of a breach of this Lease Agreement by the Lessee.
            It is agreed that this damages claim can be settled against the
            damage deposit.</div>
    </div>

    <div class="initials-row">
        @foreach($initialsParties ?? [] as $party)
            <div class="initial-block">
                <div class="initial-line"></div>
                <div class="initial-label">{{ $party }}</div>
            </div>
        @endforeach
    </div>

    <div class="doc-footer">Version 8</div>

</div>

{{-- ============================================================
     PAGE 5 — Termination through Signatures
     ============================================================ --}}
<div class="page page-break">

    @include('docuperfect.web-templates.components.company-header')

    {{-- Section 17: Termination --}}
    <div class="section-heading">17. Termination of the Lease Agreement</div>

    <div class="clause">
        <div class="sub-clause">17.1 The Lease agreement shall only be terminated when the Lessee vacates
            the premises and an out inspection has been done by the Agency. The
            Lessee and Lessor hereby agree that no Private letting shall be
            allowed for as long as the Lessee is occupying the premises.</div>

        <div class="sub-clause">17.2 Once the Lease Agreement has ended, the Lessor and/or Lessee may
            give the other party 20 business days&rsquo; notice of Termination of the
            Lease Agreement.</div>

        <div class="sub-clause">17.3 Should the Lessee cancel the Lease Agreement at any stage during the
            initial lease term as referred to in clause 5, the Lessee will be
            held liable for the following cancellation costs to procure a new
            Lessee:<br>
            The Lessee shall be held liable to pay the lease cancellation fee of
            R2 000.00 (Two Thousand Rand) for administration costs.<br>
            Signature <span class="field" data-field="cancellation_signature">{{ $cancellation_signature ?? '' }}</span><br>
            The Lessee shall be held responsible for the monthly rental payments
            and advertising costs until such time as a new Lessee has been
            procured.</div>
    </div>

    {{-- Section 18: Domicile --}}
    <div class="section-heading">18. Domicile</div>

    <div class="clause">
        <p>Each party choose domicillium citandi et executandi at his address as set
            out in 1 above, at which address all notices and legal process in relation
            to this Agreement or any action arising there from may be effectually
            delivered and served. Any notice given by one of the parties to the other
            (&ldquo;the addressee&rdquo;) which &mdash;</p>

        <div class="sub-clause">18.1.1 Is delivered by hand to the addressee&rsquo;s domicilium citandi et
            executandi shall be presumed until the contrary is proved to have
            been received by the addressee on the date of delivery; or</div>

        <div class="sub-clause">18.1.2 Is posted by prepaid registered post from an address within the
            Republic of South Africa to the addressee at the addressee&rsquo;s
            domicilium executandi shall be presumed until the contrary is
            proved to have been received by the addressee on the fifth day of
            the date of posting.</div>

        <div class="sub-clause">18.1.3 Is emailed with a delivery response to the email address as
            detailed in this agreement shall be presumed until the contrary is
            proved to have been received by the addressee on the date of the
            delivery response.</div>
    </div>

    {{-- Section 19: Jurisdiction --}}
    <div class="section-heading">19. Jurisdiction</div>

    <div class="clause">
        <p>The parties agree to the jurisdiction of the magistrate&rsquo;s court in
            connection with any action or suit arising from this Agreement or the
            cancellation thereof. Should two or more persons sign this Agreement as
            Lessors or Lessees, the said person shall be liable in solidum for the
            performance of their obligations in terms of this agreement. This
            Agreement constitutes the sole and entire agreement between the parties
            and no warranties, representations, guarantees or other terms and
            conditions of whatsoever nature not contained herein shall be of any force
            or effect.</p>
    </div>

    {{-- Section 20: Estate Agents Commission --}}
    <div class="section-heading">20. Estate Agents Commission</div>

    <div class="clause">
        <p>The Agency shall earn an ongoing commission equal to 10 (Ten) percent,
            plus VAT of the monthly rental for the duration of the lease and any
            extension thereof. (Please see Clause 17 on how a lease agreement shall
            be terminated.)</p>
    </div>

    {{-- Section 21: Sale of the Property --}}
    <div class="section-heading">21. Sale of the Property / Lease of Property Not Through Home Finders Coastal</div>

    <div class="clause">
        <div class="sub-clause">21.1 Should the Lessee or nominee enter into an agreement to purchase the
            property from the Lessor, the commission equal to 8.6% calculated on
            the selling price of the property, will be due to the Agency by the
            Lessor upon registration of such property.</div>

        <div class="sub-clause">21.2 Should the Lessee or nominee enter into an agreement to lease the
            property privately, from the Lessor, the commission as calculated in
            clause 20, will be due to the Agency by the Lessor by the first of
            every month. (Please see Clause 17 on how a lease agreement shall be
            terminated.)</div>

        <div class="sub-clause">21.3 Should the Lessor attempt to sell or rent the property during the
            duration of this lease, it is agreed that Home Finders Coastal will
            have the Sole Mandate to market and/or rent the property.</div>

        <div class="sub-clause">21.4 The Lessee hereby confirms having been made aware that the Lessor may
            wish to sell the Property during the lease period and hereby
            undertakes to grant the agent access to the Property for this purpose
            provided that the requests for access are at reasonable times.</div>
    </div>

    {{-- Section 22: POPI --}}
    <div class="section-heading">22. Protection of Personal Information</div>

    <div class="clause">
        <p>The Landlord/s and the Tenant/s hereby give their consent to the estate
            agency/ies involved in the lease, to process our personal information for
            all purposes related to this lease, in accordance with the provisions of
            the Protection of Personal Information Act. Such consent specifically
            includes the consent to work with and disclose our bank account details to
            facilitate the payment of the deposit and the monthly rent to the
            Landlord/s, and for the refund of the deposit to the Tenant/s.</p>
    </div>

    {{-- Section 23: Other Conditions --}}
    <div class="section-heading">23. Other Conditions</div>

    <div class="info-line"></div>
    <div class="info-line"></div>
    <div class="info-line"></div>
    <div class="info-line"></div>
    <div class="info-line"></div>
    <div class="info-line"></div>

    @php
        $sigParties = [];
        $sigNames = [];
        if (!empty($lessor_signature_name ?? '')) { $sigParties[] = 'Owner'; $sigNames[] = $lessor_signature_name; }
        if (!empty($lessor_signature_name_2 ?? '')) { $sigParties[] = 'Owner'; $sigNames[] = $lessor_signature_name_2; }
        if (!empty($lessee_signature_name ?? '')) { $sigParties[] = 'Tenant'; $sigNames[] = $lessee_signature_name; }
        if (!empty($agent_signature_name ?? '')) { $sigParties[] = 'Agent'; $sigNames[] = $agent_signature_name; }
    @endphp

    {{-- Section 24: Signatures --}}
    <div class="section-heading">24. Signatures</div>

    {{-- Lessor Signature --}}
    <div class="signature-section">
        <p><strong>LESSOR</strong></p>
        <p>Thus done and signed by the Lessor at <span class="field field-medium" data-field="lessor_signed_at">{{ $lessor_signed_at ?? '' }}</span> on this
            <span class="field field-short" data-field="lessor_signed_day">{{ $lessor_signed_day ?? '' }}</span> day of <span class="field field-medium" data-field="lessor_signed_month">{{ $lessor_signed_month ?? '' }}</span>
            20<span class="field field-tiny" data-field="lessor_signed_year">{{ $lessor_signed_year ?? '' }}</span> at <span class="field field-short" data-field="lessor_signed_time">{{ $lessor_signed_time ?? '' }}</span> am / pm.</p>

        <div class="signature-grid" style="grid-template-columns: 1fr 1fr;">
            <div class="signature-col" data-marker-party="owner" data-marker-index="0">
                <div class="signature-line"></div>
                <div class="signature-label">Lessor</div>
            </div>
            <div class="signature-col">
                <div class="signature-line"></div>
                <div class="signature-label">Lessor</div>
            </div>
        </div>
        <div class="signature-grid" style="grid-template-columns: 1fr 1fr; margin-top: 8pt;">
            <div class="signature-col">
                <div class="signature-line"></div>
                <div class="signature-label">As Witness</div>
            </div>
            <div class="signature-col">
                <div class="signature-line"></div>
                <div class="signature-label">As Witness</div>
            </div>
        </div>
        <div class="signature-grid" style="grid-template-columns: 1fr 1fr; margin-top: 4pt;">
            <div class="signature-col">
                <div class="signature-line"></div>
                <div class="signature-label">Name of Witness</div>
            </div>
            <div class="signature-col">
                <div class="signature-line"></div>
                <div class="signature-label">Name of Witness</div>
            </div>
        </div>
    </div>

    {{-- Lessee Signature --}}
    <div class="signature-section">
        <p><strong>LESSEE</strong></p>
        <p>Thus done and signed by the Lessee at <span class="field field-medium" data-field="lessee_signed_at">{{ $lessee_signed_at ?? '' }}</span> on this
            <span class="field field-short" data-field="lessee_signed_day">{{ $lessee_signed_day ?? '' }}</span> day of <span class="field field-medium" data-field="lessee_signed_month">{{ $lessee_signed_month ?? '' }}</span>
            20<span class="field field-tiny" data-field="lessee_signed_year">{{ $lessee_signed_year ?? '' }}</span> at <span class="field field-short" data-field="lessee_signed_time">{{ $lessee_signed_time ?? '' }}</span> am / pm.</p>

        <div class="signature-grid" style="grid-template-columns: 1fr 1fr;">
            <div class="signature-col" data-marker-party="tenant" data-marker-index="1">
                <div class="signature-line"></div>
                <div class="signature-label">Lessee</div>
            </div>
            <div class="signature-col">
                <div class="signature-line"></div>
                <div class="signature-label">Lessee</div>
            </div>
        </div>
        <div class="signature-grid" style="grid-template-columns: 1fr 1fr; margin-top: 8pt;">
            <div class="signature-col">
                <div class="signature-line"></div>
                <div class="signature-label">As Witness</div>
            </div>
            <div class="signature-col">
                <div class="signature-line"></div>
                <div class="signature-label">As Witness</div>
            </div>
        </div>
        <div class="signature-grid" style="grid-template-columns: 1fr 1fr; margin-top: 4pt;">
            <div class="signature-col">
                <div class="signature-line"></div>
                <div class="signature-label">Name of Witness</div>
            </div>
            <div class="signature-col">
                <div class="signature-line"></div>
                <div class="signature-label">Name of Witness</div>
            </div>
        </div>
    </div>

    {{-- Agent Signature --}}
    <div class="signature-section">
        <p><strong>AGENT</strong></p>
        <p>Thus done and signed by the Agent at <span class="field field-medium" data-field="agent_signed_at">{{ $agent_signed_at ?? '' }}</span> on this
            <span class="field field-short" data-field="agent_signed_day">{{ $agent_signed_day ?? '' }}</span> day of <span class="field field-medium" data-field="agent_signed_month">{{ $agent_signed_month ?? '' }}</span>
            20<span class="field field-tiny" data-field="agent_signed_year">{{ $agent_signed_year ?? '' }}</span> at <span class="field field-short" data-field="agent_signed_time">{{ $agent_signed_time ?? '' }}</span> am / pm.</p>

        <div class="signature-grid" style="grid-template-columns: 1fr 1fr;">
            <div class="signature-col" data-marker-party="agent" data-marker-index="2">
                <div class="signature-line"></div>
                <div class="signature-label">Agent</div>
            </div>
            <div class="signature-col">
                <div class="signature-line"></div>
                <div class="signature-label">Agent Name</div>
            </div>
        </div>
        <div class="signature-grid" style="grid-template-columns: 1fr 1fr; margin-top: 8pt;">
            <div class="signature-col">
                <div class="signature-line"></div>
                <div class="signature-label">Co-Signature Name</div>
            </div>
            <div class="signature-col">
                <div class="signature-line"></div>
                <div class="signature-label">Co-Signature Name</div>
            </div>
        </div>
    </div>

    <div class="doc-footer">Version 8</div>

</div>

{{-- ============================================================
     PAGE 6 — Addendum A: Service Fee
     ============================================================ --}}
<div class="page page-break">

    @include('docuperfect.web-templates.components.company-header')

    <div class="doc-title" style="text-transform: none;">Addendum A &ndash; Service Fee</div>

    <div class="clause">
        <p>The parties hereby agree that the agent will be responsible for the following:</p>
    </div>

    <ol class="numbered-list">
        <li>Source a tenant</li>
        <li>Negotiate a rental contract</li>
        <li>Secure deposit</li>
        <li>Secure first month&rsquo;s rental</li>
        <li>Report on defects to the lessor</li>
        <li>Collect the monthly rental</li>
        <li>Ongoing liaison with the lessee</li>
        <li>Collect the monthly Municipal/Eskom account from owner and pay over to the selected person.</li>
    </ol>

    <div class="clause">
        <p>The Agent shall earn an ongoing Service Fee equal to 10% (plus VAT) of the
            monthly rental for the duration of the lease and any extension thereof.</p>
    </div>

    {{-- Financial Breakdown --}}
    <p style="font-weight: bold; margin: 8pt 0 4pt;">Breakdown</p>
    <table class="financial-table">
        <tr>
            <td>Total Rental Amount</td>
            <td><span class="field" data-field="total_rental" style="min-width:auto;width:100%;border-bottom:none;">{{ $total_rental ?? '' }}</span></td>
        </tr>
        <tr>
            <td>Less Agent&rsquo;s Service Fee (Including VAT)</td>
            <td><span class="field" data-field="service_fee" style="min-width:auto;width:100%;border-bottom:none;">{{ $service_fee ?? '' }}</span></td>
        </tr>
        <tr>
            <td>Let&rsquo;s Assist Fee</td>
            <td><span class="field" data-field="lets_assist" style="min-width:auto;width:100%;border-bottom:none;">{{ $lets_assist ?? '' }}</span></td>
        </tr>
        <tr>
            <td>Net Amount to Owner</td>
            <td><span class="field" data-field="net_to_owner" style="min-width:auto;width:100%;border-bottom:none;">{{ $net_to_owner ?? '' }}</span></td>
        </tr>
    </table>

    {{-- Signature Block --}}
    <div class="signature-section">
        <div class="signature-grid" style="grid-template-columns: 1fr 1fr 1fr;">
            <div class="signature-col">
                <div class="signature-line"></div>
                <div class="signature-label">Lessor</div>
            </div>
            <div class="signature-col">
                <div class="signature-line"></div>
                <div class="signature-label">Tenant</div>
            </div>
            <div class="signature-col">
                <div class="signature-line"></div>
                <div class="signature-label">Agent</div>
            </div>
        </div>

        <div class="signature-grid" style="grid-template-columns: 1fr 1fr 1fr; margin-top: 8pt;">
            <div>
                <p>Date: <span class="field field-short" data-field="addendum_lessor_date">{{ $addendum_lessor_date ?? '' }}</span></p>
            </div>
            <div>
                <p>Date: <span class="field field-short" data-field="addendum_tenant_date">{{ $addendum_tenant_date ?? '' }}</span></p>
            </div>
            <div>
                <p>Date: <span class="field field-short" data-field="addendum_agent_date">{{ $addendum_agent_date ?? '' }}</span></p>
            </div>
        </div>
    </div>

    <div class="doc-footer">Version 8</div>

</div>

</body>
</html>
