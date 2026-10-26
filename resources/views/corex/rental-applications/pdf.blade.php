<!--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    AT-392 — Rental Application PDF. No literals: agency email/phone/website
    come from the agency/branch record via the shared company-header
    component's $d() accessor, exactly like every other filed document.
-->
<div class="corex-document-wrapper">
<div class="corex-page">

@if($branch)
    @include('docuperfect.web-templates.components.company-header', ['branch' => $branch])
@else
    @include('docuperfect.web-templates.components.company-header', ['previewAgency' => $agency])
@endif

<div class="corex-h1">Rental Application Process and Requirements</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">
    @php
        $policySentence = \App\Models\RentalApplicationQualifyingSetting::renderedInvitePolicySentenceFor($application->agency_id ?? null, isset($agency) && $agency ? \App\Models\Agency::displayNameFor($agency) : null);
    @endphp
    {{ $policySentence }} Please complete this form and submit it with your
    supporting documents to
    @isset($branch)
        {{ $branch->email ?: ($agency->email ?? '') }}
    @else
        {{ $agency->email ?? '' }}
    @endisset
    .
</span></div>

<div class="corex-h2">Personal Details</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Address of property: <span class="corex-field-value">{{ $application->property?->buildDisplayAddress() ?? $application->property_address_override ?? '' }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Full name and Surname: <span class="corex-field-value">{{ $application->full_name }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">I.D Number: <span class="corex-field-value">{{ $application->id_number }}</span> &nbsp; Marital Status: <span class="corex-field-value">{{ $application->marital_status }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Income Tax No: <span class="corex-field-value">{{ $application->income_tax_no }}</span> &nbsp; How Married: <span class="corex-field-value">{{ $application->marriage_regime }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Spouse Full Name: <span class="corex-field-value">{{ $application->spouse_name }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Spouse I.D Number: <span class="corex-field-value">{{ $application->spouse_id }}</span> &nbsp; Citizenship: <span class="corex-field-value">{{ $application->citizenship }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Current Residential Address: <span class="corex-field-value">{{ $application->current_residential_address }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Email Address: <span class="corex-field-value">{{ $application->email }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Contact Numbers: (Cell) <span class="corex-field-value">{{ $application->cell }}</span> (Work): <span class="corex-field-value">{{ $application->work_number }}</span></span></div>

<div class="corex-h2">Emergency Contact (not staying with you)</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Name: <span class="corex-field-value">{{ $application->emergency_contact_name }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Contact Numbers: (Cell) <span class="corex-field-value">{{ $application->emergency_contact_cell }}</span> (Work): <span class="corex-field-value">{{ $application->emergency_contact_work }}</span></span></div>

<?php
    // Current-living-situation ruling, 2026-09-11 — an application from
    // before this field existed has current_living_situation = null but
    // may already carry real landlord data; falling back to 'renting'
    // whenever that's true means an old application's PDF still shows its
    // real landlord section instead of a blank "situation" line.
    $situationValue = $application->current_living_situation
        ?? ($application->current_landlord_name ? 'renting' : null);
    $situationLabel = \App\Models\RentalApplication::currentLivingSituationLabel($situationValue);
?>
<div class="corex-h2">Current Living Situation</div>
@if($situationLabel)
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">{{ $situationLabel }}</span></div>
@endif
@if($situationValue === 'renting')
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Landlord / Agent / Owner Name: <span class="corex-field-value">{{ $application->current_landlord_name }}</span> &nbsp; Tel No: <span class="corex-field-value">{{ $application->current_landlord_tel }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Current Rental Amount: R<span class="corex-field-value">{{ $application->current_rental_amount }}</span> &nbsp; Due Day: <span class="corex-field-value">{{ $application->current_rental_due_day ?? '—' }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">From: <span class="corex-field-value">{{ optional($application->current_rental_from)->format('d/m/Y') }}</span> &nbsp; To: <span class="corex-field-value">{{ $application->current_rental_still_living ? 'Still living there' : optional($application->current_rental_to)->format('d/m/Y') }}</span></span></div>
@endif
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Previous Landlord / Agent / Owner Name: <span class="corex-field-value">{{ $application->previous_landlord_name }}</span> &nbsp; Tel No: <span class="corex-field-value">{{ $application->previous_landlord_tel }}</span></span></div>
@if($application->current_living_situation_notes)
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">In their own words: <span class="corex-field-value">{{ $application->current_living_situation_notes }}</span></span></div>
@endif

<div class="corex-h2">Employment Details</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Name of Employer: <span class="corex-field-value">{{ $application->employer_name }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Position: <span class="corex-field-value">{{ $application->employer_position }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Employer Address: <span class="corex-field-value">{{ $application->employer_address }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Tel No of Employer: <span class="corex-field-value">{{ $application->employer_tel }}</span> &nbsp; Monthly Salary: <span class="corex-field-value">{{ $application->monthly_salary }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Years Employed: <span class="corex-field-value">{{ $application->years_employed }}</span> &nbsp; Pay Day: <span class="corex-field-value">{{ $application->pay_date }}</span></span></div>

<div class="corex-h2">Banking and Vehicle</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Bank: <span class="corex-field-value">{{ $application->bank_name }}</span> &nbsp; Account No: <span class="corex-field-value">{{ $application->bank_account_no }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Any judgments against you? <span class="corex-field-value">{{ $application->judgments ? ucfirst($application->judgments) : '' }}</span> &nbsp; If yes, please specify: <span class="corex-field-value">{{ $application->judgments_details }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Car Make: <span class="corex-field-value">{{ $application->car_make }}</span> &nbsp; Colour: <span class="corex-field-value">{{ $application->car_colour }}</span> &nbsp; Year: <span class="corex-field-value">{{ $application->car_year }}</span> &nbsp; Registration: <span class="corex-field-value">{{ $application->car_reg }}</span></span></div>

@php
    $pdfCo = (array) ($application->co_applicant ?? []);
    $pdfOcc = (array) ($application->co_occupants ?? []);
    $pdfPets = (array) ($application->pets ?? []);
    $pdfAfford = (array) ($application->affordability ?? []);
    $pdfMoney = fn ($v) => $v === null || $v === '' ? '' : 'R ' . number_format((float) $v, 2, '.', ',');
    if ($pdfOcc === []) { $pdfOcc = array_fill(0, 3, []); }
    if ($pdfPets === []) { $pdfPets = array_fill(0, 2, []); }
    $pdfIncome = ($pdfAfford['income'] ?? []) ?: array_map(fn ($l) => ['label' => $l, 'amount' => null], \App\Models\RentalApplication::AFFORDABILITY_INCOME_LINES);
    $pdfExpenses = ($pdfAfford['expenses'] ?? []) ?: array_map(fn ($l) => ['label' => $l, 'amount' => null], \App\Models\RentalApplication::AFFORDABILITY_EXPENSE_LINES);
    $pdfIncomeTotal = $application->affordabilityTotal('income');
    $pdfExpenseTotal = $application->affordabilityTotal('expenses');
    $pdfFilled = ! empty($pdfAfford);
    $pdfTerms = \App\Services\Rentals\ApplicationTerms::for(
        $application->agency_id,
        $application->property && $application->property->rental_amount ? (float) $application->property->rental_amount : null,
        1 + (filled($pdfCo['full_name'] ?? null) ? 1 : 0),
        $application->property?->buildDisplayAddress() ?: $application->property_address_override,
        $application->full_name,
        null,
    );
@endphp

<div class="corex-h2">Co-applicant / Spouse</div>
@foreach(\App\Models\RentalApplication::CO_APPLICANT_FIELDS as $coKey => [$coLabel, $coKind])
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">{{ $coLabel }}: <span class="corex-field-value">{{ $coKind === 'money' ? $pdfMoney($pdfCo[$coKey] ?? null) : (($pdfCo[$coKey] ?? '') !== '' && $coKind === 'yesno' ? ucfirst($pdfCo[$coKey]) : ($pdfCo[$coKey] ?? '')) }}</span></span></div>
@endforeach

<div class="corex-h2">Other People Who Will Live in the Property</div>
<table class="corex-table"><thead><tr><th>Name</th><th>Relationship</th><th>Age</th></tr></thead>
<tbody>@foreach($pdfOcc as $r)<tr><td>{{ $r['name'] ?? '' }}&nbsp;</td><td>{{ $r['relationship'] ?? '' }}</td><td>{{ $r['age'] ?? '' }}</td></tr>@endforeach</tbody></table>

<div class="corex-h2">Pets</div>
<table class="corex-table"><thead><tr><th>Type</th><th>Breed</th><th>Size</th></tr></thead>
<tbody>@foreach($pdfPets as $r)<tr><td>{{ $r['type'] ?? '' }}&nbsp;</td><td>{{ $r['breed'] ?? '' }}</td><td>{{ $r['size'] ?? '' }}</td></tr>@endforeach</tbody></table>

<div class="corex-h2">Monthly Income and Expenses</div>
<table class="corex-table"><thead><tr><th>Income per month</th><th>Amount</th><th>Expenses per month</th><th>Amount</th></tr></thead>
<tbody>
@for($i = 0; $i < max(count($pdfIncome), count($pdfExpenses)); $i++)
<tr><td>{{ $pdfIncome[$i]['label'] ?? '' }}&nbsp;</td><td>{{ $pdfMoney($pdfIncome[$i]['amount'] ?? null) }}</td><td>{{ $pdfExpenses[$i]['label'] ?? '' }}</td><td>{{ $pdfMoney($pdfExpenses[$i]['amount'] ?? null) }}</td></tr>
@endfor
<tr><td><strong>Total income</strong></td><td>{{ $pdfFilled ? $pdfMoney($pdfIncomeTotal) : '' }}</td><td><strong>Total expenses</strong></td><td>{{ $pdfFilled ? $pdfMoney($pdfExpenseTotal) : '' }}</td></tr>
</tbody></table>

<div class="corex-h2">Requirement of Lease</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Effective Date of Occupation: <span class="corex-field-value">{{ optional($application->occupation_date)->format('d/m/Y') }}</span> &nbsp; Rental Terms Required: <span class="corex-field-value">{{ $application->rental_term_months ? $application->rental_term_months . ' months' : $application->rental_terms }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Special Conditions: <span class="corex-field-value">{{ $application->special_conditions }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Number of Occupants: Adults: <span class="corex-field-value">{{ $application->adults }}</span> &nbsp; Children: <span class="corex-field-value">{{ $application->children }}</span></span></div>

@if($pdfTerms['amounts'] !== [])
<div class="corex-h2">Amounts Payable</div>
@foreach($pdfTerms['amounts'] as $row)
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">{{ $row['label'] }}: <span class="corex-field-value">{{ $row['value'] }}</span></span></div>
@endforeach
@endif
@if($pdfTerms['bank'] !== null)
<div class="corex-h2">Deposit Banking Details</div>
@foreach($pdfTerms['bank']['rows'] as $row)
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">{{ $row['label'] }}: <span class="corex-field-value">{{ $row['value'] }}</span></span></div>
@endforeach
@if($pdfTerms['bank']['reference'] !== '')
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Payment reference: <span class="corex-field-value">{{ $pdfTerms['bank']['reference'] }}</span></span></div>
@endif
@endif
@if($pdfTerms['documents'] !== [])
<div class="corex-h2">Documents to Accompany This Application</div>
@foreach($pdfTerms['documents'] as $line)
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">&bull; {{ $line }}</span></div>
@endforeach
@endif
@if($pdfTerms['summary'] !== [])
<div class="corex-h2">Tenant Information Summary</div>
@foreach($pdfTerms['summary'] as $line)
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">&bull; {{ $line }}</span></div>
@endforeach
@endif

<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">I hereby declare that all the above information given is true and accurate.</span></div>
<div class="corex-clause corex-clause-indent-1">
    @if($declaration = $application->declarationSignature())
        <img src="{{ \Illuminate\Support\Facades\Storage::disk('local')->path($declaration->signature_path) }}" style="height:40px;">
    @else
        <span class="corex-field-value">_________________________</span>
    @endif
</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Applicant Signature</span></div>

@php
    // Johan, 2026-09-20 — "hfc uses tpn so thats why we have that." The
    // heading/caption below used to hardcode "TPN"/"Tenant Profile
    // Network" — HFC's own choice of bureau, not a universal one.
    // displayFieldConfig() (not resolvedFieldConfigFor()/today's live
    // setting directly) so a regenerated PDF for an OLD submitted
    // application keeps naming whichever bureau it actually disclosed at
    // submission time — same historical-integrity rule as every other
    // frozen field, never today's config reaching backward into a signed
    // record. The clause text itself was always bureau-agnostic ("any
    // credit provider or registered credit bureau") and needs no change.
    $creditBureauConsentLabel = $application->displayFieldConfig()['tpn_consent_signature']['label']
        ?? \App\Models\RentalApplication::creditBureauConsentLabel(null);
@endphp
<div class="corex-h2">{{ $creditBureauConsentLabel }}</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">The tenant hereby consents that, and authorises the Landlord or agent to, at all times contact, request and obtain information from any credit provider or registered credit bureau relevant to an assessment of the behaviour, profile, payment patterns, indebtedness, whereabouts, and creditworthiness of the tenant.</span></div>
<div class="corex-clause corex-clause-indent-1">
    @if($tpn = $application->tpnConsentSignature())
        <img src="{{ \Illuminate\Support\Facades\Storage::disk('local')->path($tpn->signature_path) }}" style="height:40px;">
    @else
        <span class="corex-field-value">_________________________</span>
    @endif
</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">{{ \App\Models\RentalApplication::creditBureauConsentCaption($creditBureauConsentLabel) }}</span></div>

@if($application->token)
<div class="corex-h2">Returning this application</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">
    Prefer to complete this online instead? Visit
    {{ route('rental-applications.public.show', $application->token) }}
</span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">
    Completed this by hand? Upload your signed form and supporting documents at the same link above.
</span></div>
@endif

</div>
</div>
