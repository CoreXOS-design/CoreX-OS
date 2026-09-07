<!--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    AT-392 — Rental Application PDF. No literals: agency email/phone/website
    come from the agency/branch record via the shared company-header
    component's $d() accessor, exactly like every other filed document.
-->
<div class="corex-document-wrapper">
<div class="corex-page">

@include('docuperfect.web-templates.components.company-header', ['branch' => $branch])

<div class="corex-h1">Rental Application Process and Requirements</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">
    {{ $agency->name ?? 'The agency' }} strictly works on pre-approval of prospective tenants
    before any viewings take place. Please complete this form and submit it with your
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
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Spouse Full Name: <span class="corex-field-value">{{ $application->spouse_name }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Spouse I.D Number: <span class="corex-field-value">{{ $application->spouse_id }}</span> &nbsp; Citizenship: <span class="corex-field-value">{{ $application->citizenship }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Current Residential Address: <span class="corex-field-value">{{ $application->current_residential_address }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Email Address: <span class="corex-field-value">{{ $application->email }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Contact Numbers: (Cell) <span class="corex-field-value">{{ $application->cell }}</span> (Work): <span class="corex-field-value">{{ $application->work_number }}</span></span></div>

<div class="corex-h2">Emergency Contact (not staying with you)</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Name: <span class="corex-field-value">{{ $application->emergency_contact_name }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Contact Numbers: (Cell) <span class="corex-field-value">{{ $application->emergency_contact_cell }}</span> (Work): <span class="corex-field-value">{{ $application->emergency_contact_work }}</span></span></div>

<div class="corex-h2">Current Landlord / Agent / Owner</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Name: <span class="corex-field-value">{{ $application->current_landlord_name }}</span> &nbsp; Tel No: <span class="corex-field-value">{{ $application->current_landlord_tel }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Current Rental Amount: R<span class="corex-field-value">{{ $application->current_rental_amount }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">From: <span class="corex-field-value">{{ optional($application->current_rental_from)->format('d/m/Y') }}</span> &nbsp; To: <span class="corex-field-value">{{ optional($application->current_rental_to)->format('d/m/Y') }}</span></span></div>

<div class="corex-h2">Employment Details</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Name of Employer: <span class="corex-field-value">{{ $application->employer_name }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Position: <span class="corex-field-value">{{ $application->employer_position }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Employer Address: <span class="corex-field-value">{{ $application->employer_address }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Tel No of Employer: <span class="corex-field-value">{{ $application->employer_tel }}</span> &nbsp; Monthly Salary: <span class="corex-field-value">{{ $application->monthly_salary }}</span></span></div>

<div class="corex-h2">Requirement of Lease</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Effective Date of Occupation: <span class="corex-field-value">{{ optional($application->occupation_date)->format('d/m/Y') }}</span> &nbsp; Rental Terms Required: <span class="corex-field-value">{{ $application->rental_terms }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Special Conditions: <span class="corex-field-value">{{ $application->special_conditions }}</span></span></div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Number of Occupants: Adults: <span class="corex-field-value">{{ $application->adults }}</span> &nbsp; Children: <span class="corex-field-value">{{ $application->children }}</span></span></div>

<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">I hereby declare that all the above information given is true and accurate.</span></div>
<div class="corex-clause corex-clause-indent-1">
    @if($declaration = $application->declarationSignature())
        <img src="{{ \Illuminate\Support\Facades\Storage::disk('local')->path($declaration->signature_path) }}" style="height:40px;">
    @else
        <span class="corex-field-value">_________________________</span>
    @endif
</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Applicant Signature</span></div>

<div class="corex-h2">Tenant Profile Network Consent</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">The tenant hereby consents that, and authorises the Landlord or agent to, at all times contact, request and obtain information from any credit provider or registered credit bureau relevant to an assessment of the behaviour, profile, payment patterns, indebtedness, whereabouts, and creditworthiness of the tenant.</span></div>
<div class="corex-clause corex-clause-indent-1">
    @if($tpn = $application->tpnConsentSignature())
        <img src="{{ \Illuminate\Support\Facades\Storage::disk('local')->path($tpn->signature_path) }}" style="height:40px;">
    @else
        <span class="corex-field-value">_________________________</span>
    @endif
</div>
<div class="corex-clause corex-clause-indent-1"><span class="corex-clause-text">Applicant Signature — TPN Consent</span></div>

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
