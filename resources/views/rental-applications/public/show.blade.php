<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Rental Application — {{ $application->agency->name ?? 'Agency' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 min-h-screen p-4">
<div class="w-full max-w-2xl mx-auto">

    <div class="text-center mb-6">
        <h1 class="text-xl font-bold text-slate-800">Rental Application</h1>
        <p class="text-sm text-slate-500">{{ $application->agency->name ?? '' }}</p>
    </div>

    @if(session('success'))
        <div class="p-3 rounded-xl bg-emerald-50 border border-emerald-100 text-emerald-700 text-sm mb-4">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="p-3 rounded-xl bg-red-50 border border-red-100 text-red-700 text-sm mb-4">{{ session('error') }}</div>
    @endif

    {{--
        AT-392, Johan 2026-09-07 — the controller's own show() already routes
        ANY 'returned'-or-later status to the separate already-submitted.blade.php
        view before this template ever renders (see
        RentalApplicationSigningController::show()). A status check here was
        dead code — this template is never reached in that state — removed
        rather than left as misleading, unreachable duplication.
    --}}
    @if($errors->any())
        <div class="p-3 rounded-xl bg-red-50 border border-red-100 text-red-700 text-sm mb-4">
            Please check the highlighted field{{ $errors->count() > 1 ? 's' : '' }} below.
        </div>
    @endif

    <form method="POST" action="{{ route('rental-applications.public.submit', $application->token) }}"
          x-data="rentalApplicationForm()" @submit="beforeSubmit">
        @csrf
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 space-y-6">

            <section>
                <h2 class="font-semibold text-slate-700 mb-3">Personal Details</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="sm:col-span-2">
                        <label class="block text-xs text-slate-500 mb-1">Property applying for</label>
                        <input type="text" value="{{ $application->property?->buildDisplayAddress() ?? $application->property_address_override ?? '' }}" disabled
                               class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500">
                    </div>
                    <x-rental-application-field name="full_name" label="Full name and surname" :value="$application->full_name" />
                    <x-rental-application-field name="id_number" label="ID number" :value="$application->id_number" />
                    <x-rental-application-field name="marital_status" label="Marital status" :value="$application->marital_status" />
                    <x-rental-application-field name="citizenship" label="Citizenship" :value="$application->citizenship" />
                    <x-rental-application-field name="spouse_name" label="Spouse full name" :value="$application->spouse_name" />
                    <x-rental-application-field name="spouse_id" label="Spouse ID number" :value="$application->spouse_id" />
                    <x-rental-application-field name="email" label="Email address" type="email" :value="$application->email" />
                    <x-rental-application-field name="cell" label="Cell number" :value="$application->cell" />
                    <x-rental-application-field name="work_number" label="Work number" :value="$application->work_number" />
                    <div class="sm:col-span-2">
                        <label class="block text-xs text-slate-500 mb-1">Current residential address</label>
                        <textarea name="current_residential_address" rows="2" class="w-full rounded-lg border px-3 py-2 text-sm {{ $errors->has('current_residential_address') ? 'border-red-400' : 'border-slate-300' }}">{{ old('current_residential_address', $application->current_residential_address) }}</textarea>
                        @error('current_residential_address') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            <section>
                <h2 class="font-semibold text-slate-700 mb-3">Emergency Contact</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <x-rental-application-field name="emergency_contact_name" label="Name" :value="$application->emergency_contact_name" />
                    <x-rental-application-field name="emergency_contact_cell" label="Cell" :value="$application->emergency_contact_cell" />
                    <x-rental-application-field name="emergency_contact_work" label="Work" :value="$application->emergency_contact_work" />
                </div>
            </section>

            <section>
                <h2 class="font-semibold text-slate-700 mb-3">Current Landlord</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <x-rental-application-field name="current_landlord_name" label="Name" :value="$application->current_landlord_name" />
                    <x-rental-application-field name="current_landlord_tel" label="Tel" :value="$application->current_landlord_tel" />
                    <x-rental-application-field name="current_rental_amount" label="Current rental amount (R)" type="number" :value="$application->current_rental_amount" />
                    <div></div>
                    <x-rental-application-field name="current_rental_from" label="From" type="date" :value="optional($application->current_rental_from)->format('Y-m-d')" />
                    <x-rental-application-field name="current_rental_to" label="To" type="date" :value="optional($application->current_rental_to)->format('Y-m-d')" />
                </div>
            </section>

            <section>
                <h2 class="font-semibold text-slate-700 mb-3">Employment</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="sm:col-span-2">
                        <label class="block text-xs text-slate-500 mb-1">Employment type</label>
                        @php $employmentType = old('employment_type', $application->employment_type) @endphp
                        <select name="employment_type" class="w-full rounded-lg border px-3 py-2 text-sm {{ $errors->has('employment_type') ? 'border-red-400' : 'border-slate-300' }}">
                            <option value="">— Select —</option>
                            <option value="permanently_employed" @selected($employmentType === 'permanently_employed')>Permanently employed</option>
                            <option value="business_owner_personal_account" @selected($employmentType === 'business_owner_personal_account')>Business owner — personal account</option>
                            <option value="business_owner_business_account" @selected($employmentType === 'business_owner_business_account')>Business owner — business account</option>
                        </select>
                        @error('employment_type') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <x-rental-application-field name="employer_name" label="Employer" :value="$application->employer_name" />
                    <x-rental-application-field name="employer_position" label="Position" :value="$application->employer_position" />
                    <x-rental-application-field name="employer_tel" label="Employer tel" :value="$application->employer_tel" />
                    <x-rental-application-field name="monthly_salary" label="Monthly salary (R)" type="number" :value="$application->monthly_salary" />
                    <div class="sm:col-span-2">
                        <label class="block text-xs text-slate-500 mb-1">Employer address</label>
                        <textarea name="employer_address" rows="2" class="w-full rounded-lg border px-3 py-2 text-sm {{ $errors->has('employer_address') ? 'border-red-400' : 'border-slate-300' }}">{{ old('employer_address', $application->employer_address) }}</textarea>
                        @error('employer_address') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            <section>
                <h2 class="font-semibold text-slate-700 mb-3">Lease Requirement</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <x-rental-application-field name="occupation_date" label="Effective date of occupation" type="date" :value="optional($application->occupation_date)->format('Y-m-d')" />
                    <x-rental-application-field name="rental_terms" label="Rental terms required" :value="$application->rental_terms" />
                    <x-rental-application-field name="adults" label="Adults" type="number" :value="$application->adults" />
                    <x-rental-application-field name="children" label="Children" type="number" :value="$application->children" />
                    <div class="sm:col-span-2">
                        <label class="block text-xs text-slate-500 mb-1">Special conditions</label>
                        <textarea name="special_conditions" rows="2" class="w-full rounded-lg border px-3 py-2 text-sm {{ $errors->has('special_conditions') ? 'border-red-400' : 'border-slate-300' }}">{{ old('special_conditions', $application->special_conditions) }}</textarea>
                        @error('special_conditions') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            <section>
                <h2 class="font-semibold text-slate-700 mb-2">Declaration</h2>
                <p class="text-xs text-slate-500 mb-2">I hereby declare that all the above information given is true and accurate.</p>
                @include('rental-applications.public._signature-pad', ['field' => 'declaration_signature', 'label' => 'declaration'])
            </section>

            <section>
                <h2 class="font-semibold text-slate-700 mb-2">Tenant Profile Network Consent</h2>
                <p class="text-xs text-slate-500 mb-2">
                    The tenant hereby consents that, and authorises the Landlord or agent to, at all times contact,
                    request and obtain information from any credit provider or registered credit bureau relevant to
                    an assessment of the tenant's creditworthiness.
                </p>
                @include('rental-applications.public._signature-pad', ['field' => 'tpn_consent_signature', 'label' => 'TPN consent'])
            </section>

            <input type="hidden" name="declaration_signature" x-ref="declaration_signature_input">
            <input type="hidden" name="tpn_consent_signature" x-ref="tpn_consent_signature_input">

            {{--
                AT-392, Johan 2026-09-07 — "no submit application button".
                Root cause: this button used the raw Tailwind arbitrary-value
                class bg-[#0b2a4a], which only exists in the compiled CSS if
                a build ran AFTER this class appeared in a scanned file. The
                last build on this box predates this file by ~2 weeks, so the
                class compiled to nothing — the button was genuinely present,
                correctly sized, Alpine-hydrated, zero console errors (proven
                with a real headless-browser render), but rendered as white
                text on an unstyled background: invisible, not absent.
                Fixed with an inline style using the SAME var()-with-fallback
                pattern UI_DESIGN_SYSTEM.md already requires elsewhere
                (var(--brand-default, #0b2a4a)) — this can never depend on a
                Tailwind rebuild, so it can't regress the same way again.
            --}}
            <button type="submit" class="w-full rounded-lg text-white font-semibold py-3 text-sm"
                    style="background: var(--brand-default, #0b2a4a);"
                    :disabled="submitting">
                <span x-show="!submitting">Submit Application</span>
                <span x-show="submitting" x-cloak>Submitting…</span>
            </button>
            <p class="text-xs text-red-600" x-show="error" x-text="error" x-cloak></p>
        </div>
    </form>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 mt-4">
        <h2 class="font-semibold text-slate-700 mb-2">Supporting Documents</h2>
        <p class="text-xs text-slate-500 mb-3">Upload payslips, bank statements, ID or proof of residence — whatever you have. Nothing here is required to submit.</p>

        @include('rental-applications.public._document-list', ['application' => $application])

        {{--
            AT-392, Johan 2026-09-07 — a rejected upload (wrong file type,
            too large, too many files) previously redirected back with the
            validation error sitting unread in $errors — neither the main
            form's error banner (a different set of field keys) nor anything
            here ever displayed it, so a rejected upload looked identical to
            a silently-ignored click. Confirmed this exact silent failure
            with a real HTTP test before adding this.
        --}}
        @error('supporting_files')
            <p class="text-xs text-red-600 mb-2">{{ $message }}</p>
        @enderror
        @error('supporting_files.*')
            <p class="text-xs text-red-600 mb-2">{{ $message }}</p>
        @enderror

        <form method="POST" action="{{ route('rental-applications.public.documents', $application->token) }}" enctype="multipart/form-data">
            @csrf
            <input type="file" name="supporting_files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                   class="block w-full text-sm text-slate-600 mb-3">
            <button type="submit" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700">
                Upload
            </button>
        </form>
    </div>

</div>

<script>
function rentalApplicationForm() {
    return {
        submitting: false,
        error: '',
        beforeSubmit(e) {
            const decl = this.$refs.declaration_signature_input.value;
            const tpn = this.$refs.tpn_consent_signature_input.value;
            if (!decl || !tpn) {
                e.preventDefault();
                this.error = 'Please sign both the declaration and the TPN consent before submitting.';
                return;
            }
            this.error = '';
            this.submitting = true;
        },
    };
}
</script>
</body>
</html>
