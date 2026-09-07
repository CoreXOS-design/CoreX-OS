{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@php
    $checklist = $rentalApplication->employment_type
        ? \App\Models\RentalApplicationDocumentRequirement::checklistFor($rentalApplication->agency_id, $rentalApplication->employment_type)
        : collect();
    $onFileTypeIds = $rentalApplication->documents->pluck('document_type_id')->filter()->all();
@endphp

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">
                    Rental Application — {{ $rentalApplication->contact->full_name ?? '' }}
                </h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    <span class="ds-badge ds-badge-info">{{ str_replace('_', ' ', $rentalApplication->status) }}</span>
                    @if($rentalApplication->property)
                        &middot; {{ $rentalApplication->property?->buildDisplayAddress() }}
                    @endif
                </p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('corex.rental-applications.pdf', $rentalApplication) }}" class="corex-btn-outline text-xs">Download PDF</a>
                @permission('rental_applications.create')
                <form method="POST" action="{{ route('corex.rental-applications.send', $rentalApplication) }}">
                    @csrf
                    <button type="submit" class="corex-btn-primary text-xs">
                        {{ $rentalApplication->token ? 'Resend' : 'Send' }}
                    </button>
                </form>
                <form method="POST" action="{{ route('corex.rental-applications.destroy', $rentalApplication) }}"
                      onsubmit="return confirm('Archive this rental application? It can be recovered by an admin.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626);">Archive</button>
                </form>
                @endpermission
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: var(--ds-emerald-soft, #ecfdf5); color: var(--ds-emerald, #059669);">{{ session('success') }}</div>
    @endif

    @if($rentalApplication->token)
    <div class="rounded-md p-4 text-xs space-y-1" style="background: var(--surface); border: 1px solid var(--border);">
        <div><strong>Online link:</strong> <a href="{{ route('rental-applications.public.show', $rentalApplication->token) }}" target="_blank" style="color: var(--brand-icon, #2563eb);">{{ route('rental-applications.public.show', $rentalApplication->token) }}</a></div>
        <div><strong>Download link:</strong> <a href="{{ route('rental-applications.public.pdf', $rentalApplication->token) }}" style="color: var(--brand-icon, #2563eb);">{{ route('rental-applications.public.pdf', $rentalApplication->token) }}</a></div>
        <div style="color: var(--text-muted);">Expires {{ optional($rentalApplication->token_expires_at)->format('d M Y') }}</div>
    </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Signatures</h2>
            <div class="text-xs space-y-1" style="color: var(--text-secondary);">
                <div>Declaration: {{ $rentalApplication->declarationSignature() ? '✓ Signed ' . $rentalApplication->declarationSignature()->signed_at->format('d M Y H:i') : 'Not yet signed' }}</div>
                <div>TPN Consent: {{ $rentalApplication->tpnConsentSignature() ? '✓ Signed ' . $rentalApplication->tpnConsentSignature()->signed_at->format('d M Y H:i') : 'Not yet signed' }}</div>
            </div>
        </div>

        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Supporting Documents</h2>
            @if($rentalApplication->documents->isEmpty())
                <p class="text-xs" style="color: var(--text-muted);">None uploaded yet.</p>
            @else
                <ul class="text-xs space-y-1" style="color: var(--text-secondary);">
                    @foreach($rentalApplication->documents as $doc)
                        <li>✓ <a href="{{ route('corex.rental-applications.documents.download', [$rentalApplication, $doc]) }}" style="color: var(--brand-icon, #2563eb);">{{ $doc->original_name }}</a> @if($doc->documentType) ({{ $doc->documentType->label }}) @endif</li>
                    @endforeach
                </ul>
            @endif

            @if($checklist->isNotEmpty())
                <p class="text-xs font-medium mt-3 mb-1" style="color: var(--text-secondary);">Checklist ({{ str_replace('_', ' ', $rentalApplication->employment_type) }}):</p>
                <ul class="text-xs space-y-1">
                    @foreach($checklist as $type)
                        <li style="color: {{ in_array($type->id, $onFileTypeIds) ? 'var(--ds-emerald, #059669)' : 'var(--ds-amber, #d97706)' }};">
                            {{ in_array($type->id, $onFileTypeIds) ? '✓' : '○ outstanding —' }} {{ $type->label }}
                        </li>
                    @endforeach
                </ul>
                <p class="text-[11px] mt-2" style="color: var(--text-muted);">Outstanding documents never block this application — informational only.</p>
            @endif
        </div>
    </div>

    <form method="POST" action="{{ route('corex.rental-applications.update', $rentalApplication) }}" class="rounded-md p-6 space-y-6" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf
        @method('PUT')

        <div>
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Property</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <x-rental-application-field name="property_address_override" label="Property address (if not linked above)" :value="$rentalApplication->property_address_override" />
            </div>
        </div>

        <div>
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Personal Details</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <x-rental-application-field name="full_name" label="Full name and surname" :value="$rentalApplication->full_name" />
                <x-rental-application-field name="id_number" label="ID number" :value="$rentalApplication->id_number" />
                <x-rental-application-field name="marital_status" label="Marital status" :value="$rentalApplication->marital_status" />
                <x-rental-application-field name="citizenship" label="Citizenship" :value="$rentalApplication->citizenship" />
                <x-rental-application-field name="spouse_name" label="Spouse full name" :value="$rentalApplication->spouse_name" />
                <x-rental-application-field name="spouse_id" label="Spouse ID number" :value="$rentalApplication->spouse_id" />
                <x-rental-application-field name="email" label="Email address" type="email" :value="$rentalApplication->email" />
                <x-rental-application-field name="cell" label="Cell number" :value="$rentalApplication->cell" />
                <x-rental-application-field name="work_number" label="Work number" :value="$rentalApplication->work_number" />
            </div>
            <div class="mt-3">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Current residential address</label>
                <textarea name="current_residential_address" rows="2" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">{{ $rentalApplication->current_residential_address }}</textarea>
            </div>
        </div>

        <div>
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Emergency Contact (not staying with you)</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <x-rental-application-field name="emergency_contact_name" label="Name" :value="$rentalApplication->emergency_contact_name" />
                <x-rental-application-field name="emergency_contact_cell" label="Cell number" :value="$rentalApplication->emergency_contact_cell" />
                <x-rental-application-field name="emergency_contact_work" label="Work number" :value="$rentalApplication->emergency_contact_work" />
            </div>
        </div>

        <div>
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Current Landlord / Agent / Owner</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <x-rental-application-field name="current_landlord_name" label="Name" :value="$rentalApplication->current_landlord_name" />
                <x-rental-application-field name="current_landlord_tel" label="Tel number" :value="$rentalApplication->current_landlord_tel" />
                <x-rental-application-field name="current_rental_amount" label="Current rental amount (R)" type="number" :value="$rentalApplication->current_rental_amount" />
                <x-rental-application-field name="current_rental_from" label="From" type="date" :value="optional($rentalApplication->current_rental_from)->format('Y-m-d')" />
                <x-rental-application-field name="current_rental_to" label="To" type="date" :value="optional($rentalApplication->current_rental_to)->format('Y-m-d')" />
            </div>
        </div>

        <div>
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Employment</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Employment type</label>
                    <select name="employment_type" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                        <option value="">— Select —</option>
                        @foreach(\App\Models\RentalApplication::EMPLOYMENT_TYPES as $type)
                            <option value="{{ $type }}" @selected($rentalApplication->employment_type === $type)>{{ str_replace('_', ' ', ucfirst($type)) }}</option>
                        @endforeach
                    </select>
                </div>
                <x-rental-application-field name="employer_name" label="Employer" :value="$rentalApplication->employer_name" />
                <x-rental-application-field name="employer_position" label="Position" :value="$rentalApplication->employer_position" />
                <x-rental-application-field name="employer_tel" label="Employer tel number" :value="$rentalApplication->employer_tel" />
                <x-rental-application-field name="monthly_salary" label="Monthly salary (R)" type="number" :value="$rentalApplication->monthly_salary" />
            </div>
            <div class="mt-3">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Employer address</label>
                <textarea name="employer_address" rows="2" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">{{ $rentalApplication->employer_address }}</textarea>
            </div>
        </div>

        <div>
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Requirement of Lease</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <x-rental-application-field name="occupation_date" label="Effective date of occupation" type="date" :value="optional($rentalApplication->occupation_date)->format('Y-m-d')" />
                <x-rental-application-field name="rental_terms" label="Rental terms required" :value="$rentalApplication->rental_terms" />
                <x-rental-application-field name="adults" label="Number of adults" type="number" :value="$rentalApplication->adults" />
                <x-rental-application-field name="children" label="Number of children" type="number" :value="$rentalApplication->children" />
            </div>
            <div class="mt-3">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Special conditions</label>
                <textarea name="special_conditions" rows="2" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">{{ $rentalApplication->special_conditions }}</textarea>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-xs">Save</button>
        </div>
    </form>
</div>
@endsection
