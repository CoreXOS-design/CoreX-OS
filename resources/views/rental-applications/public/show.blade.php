@php
    // Computed here, not inline inside @json() in the <script> block below —
    // a multi-line arrow-function/array literal nested inside a Blade
    // directive's own parentheses tripped Blade's directive-argument
    // parser (compiled to invalid PHP: a real ParseError on first render,
    // NOT caught by Blade::compileString() alone, which only proves the
    // template transforms — never assume that means the resulting PHP is
    // valid without also linting the compiled output).
    $initialDocuments = $application->documents->map(fn ($d) => [
        'id' => $d->id,
        'name' => $d->original_name,
        'view_url' => route('rental-applications.public.documents.view', [$application->token, $d->id]),
    ]);
@endphp
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
<div class="w-full max-w-2xl mx-auto" x-data="rentalApplicationForm()">

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

    @php
        // Defect fix, AT-392 (Johan via cc5's journey walk) — this page
        // never told the applicant WHY it was reopened, only the email did;
        // an applicant who lost that email had no way to act. "Fresh" means
        // the reopen hasn't been superseded by a resubmission since.
        $isFreshReopen = $application->reopened_at
            && (! $application->submitted_at || $application->submitted_at->lt($application->reopened_at));
    @endphp
    @if($isFreshReopen)
        <div class="p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-sm mb-4">
            <p class="font-semibold mb-1">Your agent has reopened this application</p>
            @if($application->reopened_note)
                <p class="whitespace-pre-line">{{ $application->reopened_note }}</p>
            @else
                <p>Please review and update the details below, then submit again.</p>
            @endif
        </div>
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

    <form id="rentalApplicationSubmitForm" method="POST" action="{{ route('rental-applications.public.submit', $application->token) }}"
          @submit="beforeSubmit">
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
                    <x-rental-application-field name="current_rental_amount" label="Current rental amount (R)" type="text" inputmode="decimal" :value="$application->current_rental_amount" />
                    <div></div>
                    <x-rental-application-field name="current_rental_from" label="From" type="date" :value="optional($application->current_rental_from)->format('Y-m-d')" />
                    <div x-data="{ stillLiving: {{ old('current_rental_still_living', $application->current_rental_still_living) ? 'true' : 'false' }} }">
                        <label class="block text-xs text-slate-500 mb-1">To</label>
                        <input type="date" name="current_rental_to" x-ref="currentRentalTo"
                               :disabled="stillLiving"
                               value="{{ old('current_rental_to', optional($application->current_rental_to)->format('Y-m-d')) }}"
                               class="w-full rounded-lg border px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-400 {{ $errors->has('current_rental_to') ? 'border-red-400' : 'border-slate-300' }}">
                        @error('current_rental_to') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        <label class="flex items-center gap-2 mt-2 text-xs text-slate-600 cursor-pointer">
                            <input type="hidden" name="current_rental_still_living" value="0">
                            <input type="checkbox" name="current_rental_still_living" value="1" x-model="stillLiving"
                                   @change="if (stillLiving) $refs.currentRentalTo.value = ''" class="rounded border-slate-300">
                            Still living here — no end date
                        </label>
                    </div>
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
                    <x-rental-application-field name="monthly_salary" label="Gross monthly income, before deductions (R)" type="text" inputmode="decimal" :value="$application->monthly_salary"
                        hint="The amount on your payslip BEFORE tax and other deductions — not what actually lands in your bank account." />
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
                    <div x-data="{ months: {{ old('rental_term_months', $application->rental_term_months) ?: 'null' }} }" class="sm:col-span-2">
                        <label class="block text-xs text-slate-500 mb-1">Rental terms required</label>
                        <input type="hidden" name="rental_term_months" :value="months">
                        <div class="flex gap-2">
                            <template x-for="m in [6, 12, 24]" :key="m">
                                <button type="button" @click="months = m"
                                        :class="months === m ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-300'"
                                        class="px-4 py-2 rounded-lg border text-sm">
                                    <span x-text="m"></span> months
                                </button>
                            </template>
                        </div>
                        @error('rental_term_months') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        <p class="text-xs text-slate-400 mt-1">Maximum 24 months by law — a longer stay is arranged as a renewal later, not on this form.</p>
                    </div>
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

            <input type="hidden" name="declaration_signature" value="{{ old('declaration_signature') }}" x-ref="declaration_signature_input">
            <input type="hidden" name="tpn_consent_signature" value="{{ old('tpn_consent_signature') }}" x-ref="tpn_consent_signature_input">
        </div>
    </form>

    {{--
        Johan, QA1 — "I complete all the information, get to the bottom,
        attach a file, click upload and the screen refreshes, and all my
        typed info is gone." / "I select docs and click submit and then on
        corex no docs arrive back because i never clicked upload."
        Root cause of BOTH: this was a synchronous form-POST — any upload
        action reloaded the whole page, and this public form has no
        separate "save" step, so anything typed but not yet submitted lived
        only in the browser and was wiped by that reload. Fixed by making
        every document action (add, replace, remove) a fetch call with NO
        navigation at all — the document list updates in place, the rest of
        the form is never touched. Submit also moved below this section —
        it was sitting above the very thing it invites people to skip.
    --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 mt-4">
        <h2 class="font-semibold text-slate-700 mb-2">Supporting Documents</h2>
        <p class="text-xs text-slate-500 mb-3">Upload payslips, bank statements, ID or proof of residence — whatever you have. Nothing here is required to submit.</p>

        <ul class="text-sm text-slate-600 mb-3 space-y-2" x-show="documents.length">
            <template x-for="doc in documents" :key="doc.id">
                <li class="flex items-center justify-between gap-2 border border-slate-100 rounded-lg px-3 py-2">
                    <a :href="doc.view_url" target="_blank" rel="noopener" class="text-slate-700 hover:underline" x-text="'✓ ' + doc.name"></a>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <label class="text-xs font-medium text-slate-500 hover:text-slate-700 cursor-pointer">
                            Replace
                            <input type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="hidden"
                                   @change="replaceDoc(doc, $event.target.files[0]); $event.target.value = ''">
                        </label>
                        <button type="button" class="text-xs font-medium text-red-500 hover:text-red-700"
                                @click="removeDoc(doc)">Remove</button>
                    </div>
                </li>
            </template>
        </ul>

        <template x-for="u in uploading" :key="u.tempId">
            <p class="text-xs mb-2" :class="u.error ? 'text-red-600' : 'text-slate-500'">
                <span x-show="!u.error" x-text="'Uploading ' + u.name + '…'"></span>
                <span x-show="u.error" x-text="u.name + ': ' + u.error"></span>
            </p>
        </template>

        <input type="file" x-ref="fileInput" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
               class="block w-full text-sm text-slate-600 mb-1"
               @change="onFilesSelected($event.target.files); $event.target.value = ''">
        <p class="text-[11px]" style="color: var(--text-muted, #94a3b8);">Files attach automatically — no separate upload button needed.</p>
    </div>

    <div class="mt-4">
        {{--
            AT-392, Johan 2026-09-07 — "no submit application button" (see
            the historical note this replaced — same inline var()-with-
            fallback fix, unaffected by this move). Now outside the <form>
            tag (documents sit between them in the DOM per Johan's design
            instruction) and associated via the HTML5 form= attribute —
            the browser treats it exactly as if it were still inside.
        --}}
        <button type="submit" form="rentalApplicationSubmitForm" class="w-full rounded-lg text-white font-semibold py-3 text-sm"
                style="background: var(--brand-default, #0b2a4a);"
                :disabled="submitting">
            <span x-show="!submitting">Submit Application</span>
            <span x-show="submitting" x-cloak>Submitting…</span>
        </button>
        <p class="text-xs text-red-600 mt-2" x-show="error" x-text="error" x-cloak></p>
    </div>

</div>

<script>
function rentalApplicationForm() {
    return {
        submitting: false,
        error: '',
        documents: @json($initialDocuments),
        uploading: [],

        csrfToken() {
            return document.querySelector('meta[name="csrf-token"]').content;
        },

        // Johan, QA1 — every document action is a fetch call with no page
        // navigation at all, so nothing typed in the form above is ever
        // touched by attaching, replacing, or removing a document.
        async uploadFile(file) {
            const tempId = 'u' + Date.now() + Math.random();
            this.uploading.push({ tempId, name: file.name, error: null });
            const formData = new FormData();
            formData.append('supporting_files[]', file);
            formData.append('_token', this.csrfToken());
            try {
                const res = await fetch('{{ route('rental-applications.public.documents', $application->token) }}', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken() },
                    body: formData,
                });
                const data = await res.json().catch(() => ({}));
                const item = this.uploading.find(u => u.tempId === tempId);
                if (!res.ok) {
                    item.error = (data.errors && Object.values(data.errors)[0]?.[0]) || data.message || 'Upload failed.';
                    return false;
                }
                this.documents.push(...(data.documents || []));
                this.uploading = this.uploading.filter(u => u.tempId !== tempId);
                return true;
            } catch (e) {
                const item = this.uploading.find(u => u.tempId === tempId);
                if (item) item.error = 'Network error — please try again.';
                return false;
            }
        },

        async onFilesSelected(fileList) {
            await Promise.all(Array.from(fileList).map(file => this.uploadFile(file)));
        },

        async removeDoc(doc) {
            if (!confirm('Remove ' + doc.name + '?')) return;
            try {
                const res = await fetch(`{{ url('/rental-application/' . $application->token . '/documents') }}/${doc.id}/remove`, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken() },
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    this.error = data.message || 'Could not remove this document.';
                    return;
                }
                this.documents = this.documents.filter(d => d.id !== doc.id);
            } catch (e) {
                this.error = 'Network error — please try again.';
            }
        },

        async replaceDoc(doc, file) {
            if (!file) return;
            const formData = new FormData();
            formData.append('replacement_file', file);
            formData.append('_token', this.csrfToken());
            try {
                const res = await fetch(`{{ url('/rental-application/' . $application->token . '/documents') }}/${doc.id}/replace`, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken() },
                    body: formData,
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    this.error = (data.errors && Object.values(data.errors)[0]?.[0]) || data.message || 'Could not replace this document.';
                    return;
                }
                this.documents = this.documents.filter(d => d.id !== doc.id);
                this.documents.push(data.document);
            } catch (e) {
                this.error = 'Network error — please try again.';
            }
        },

        // Johan, QA1 — "docs never gets attached" if the applicant chose
        // files but never triggered the (now-removed) manual Upload
        // button. Files upload immediately on selection now, but this is
        // the belt-and-braces safety net Johan explicitly asked to keep:
        // anything still sitting in the file picker, or any upload still
        // in flight, is resolved before Submit is ever allowed to proceed
        // — and if any of it fails, Submit is blocked and nothing already
        // typed is lost (this never reloads the page to find out).
        async beforeSubmit(e) {
            e.preventDefault();

            const picker = this.$refs.fileInput;
            if (picker && picker.files.length) {
                await this.onFilesSelected(picker.files);
                picker.value = '';
            }

            while (this.uploading.some(u => !u.error)) {
                await new Promise(r => setTimeout(r, 150));
            }

            const failed = this.uploading.filter(u => u.error);
            if (failed.length) {
                this.error = 'Could not attach: ' + failed.map(f => `${f.name} (${f.error})`).join(', ') + '. Fix this before submitting.';
                return;
            }

            const decl = this.$refs.declaration_signature_input.value;
            const tpn = this.$refs.tpn_consent_signature_input.value;
            if (!decl || !tpn) {
                this.error = 'Please sign both the declaration and the TPN consent before submitting.';
                return;
            }

            this.error = '';
            this.submitting = true;
            e.target.submit();
        },
    };
}
</script>
</body>
</html>
