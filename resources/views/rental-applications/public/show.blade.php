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
{{--
    Progress indicator, 2026-09-12 — applicant journey audit finding: seven
    sections on one long phone scroll with no sense of how much is left is
    an abandonment pattern. Deliberately minimal per instruction — a thin
    line and a count, not a decorative stepper that costs screen space a
    phone doesn't have. The line reflects actual scroll position through
    the page (honest: it's exactly how far down the page they've scrolled,
    not a guess at how "complete" the form is — completion isn't knowable
    since almost every field is optional). The count reflects whichever
    named <section data-progress-section="..."> is currently nearest the
    top of the viewport.
--}}
<div class="fixed top-0 left-0 w-full h-1 bg-slate-200 z-50" style="pointer-events:none;">
    <div class="h-full bg-blue-600" :style="'width: ' + scrollProgressPercent + '%; transition: width 100ms linear;'"></div>
</div>
<div class="w-full max-w-2xl mx-auto" x-data="rentalApplicationForm()" x-init="initAutosave(); initProgress();">

    <div class="text-center mb-6">
        <h1 class="text-xl font-bold text-slate-800">Rental Application</h1>
        <p class="text-sm text-slate-500">{{ $application->agency->name ?? '' }}</p>
        <p class="text-xs mt-1" style="color: var(--text-muted, #94a3b8);" x-show="currentSectionLabel" x-cloak x-text="currentSectionLabel"></p>
        {{--
            Applicant-side autosave, 2026-09-12 — a quiet "Saved" indicator,
            the same reassurance pattern as Google Docs/Notion, so an
            applicant on a shaky connection can SEE their answers are
            landing rather than wondering. Never an error state here — a
            failed autosave degrades completely silently (see
            autosaveField() below), so this only ever shows "Saving…" or a
            past-tense "Saved" timestamp, never a failure.
        --}}
        <p class="text-xs mt-1" style="color: var(--text-muted, #94a3b8);" x-show="autosaveStatus" x-cloak x-text="autosaveStatus"></p>
    </div>

    {{--
        Rate-limit warning, 2026-09-12 — the ONE autosave failure that must
        NOT degrade silently: everything typed before this point is already
        safely saved, but nothing further will be until the applicant acts.
        Deliberately sticky (no auto-dismiss, no timeout) and placed above
        the fold so it can't be scrolled past unnoticed.
    --}}
    <div x-show="rateLimited" x-cloak class="p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-sm mb-4">
        <p class="font-semibold mb-1">Your answers have stopped saving automatically.</p>
        <p>Everything you'd typed up to now is safe. Please finish and submit soon, or copy your remaining answers somewhere safe until you can.</p>
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
    @elseif($application->status === 'in_progress')
        {{--
            Applicant-side autosave, 2026-09-12 — Johan's rule: reopening
            (and, by the same logic, returning to an in-progress link)
            PRE-FILLS previous answers, never a blank form. 'in_progress' can
            now only be reached via a prior autosave or document upload —
            never the very first visit ('sent') — so this is a reliable
            "there is a restored draft" signal with no new column needed.
        --}}
        <div class="p-4 rounded-xl bg-sky-50 border border-sky-200 text-sky-800 text-sm mb-4">
            <p class="font-semibold">Welcome back — we've restored what you'd already typed.</p>
            @if($application->draft_saved_at)
                <p class="text-xs mt-1">Last saved {{ $application->draft_saved_at->diffForHumans() }}.</p>
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

            <section data-progress-section="Personal Details">
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

            <section data-progress-section="Emergency Contact">
                <h2 class="font-semibold text-slate-700 mb-3">Emergency Contact</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <x-rental-application-field name="emergency_contact_name" label="Name" :value="$application->emergency_contact_name" />
                    <x-rental-application-field name="emergency_contact_cell" label="Cell" :value="$application->emergency_contact_cell" />
                    <x-rental-application-field name="emergency_contact_work" label="Work" :value="$application->emergency_contact_work" />
                </div>
            </section>

            @php
                // Backward compatibility — an application from before this
                // field existed has current_living_situation = null but may
                // already carry real landlord data (typed under the old
                // "Current Landlord" section). Defaulting an unanswered
                // situation to 'renting' whenever that's true means an
                // existing applicant reopening/resubmitting still sees
                // their own answer, not a blank selector hiding real data.
                $situationDefault = $application->current_living_situation
                    ?? ($application->current_landlord_name ? 'renting' : '');
            @endphp
            <section data-progress-section="Current Living Situation" x-data="{ situation: {{ Js::from(old('current_living_situation', $situationDefault)) }} }">
                <h2 class="font-semibold text-slate-700 mb-3">Current Living Situation</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="sm:col-span-2">
                        <label class="block text-xs text-slate-500 mb-1">Which best describes your situation right now?</label>
                        <select name="current_living_situation" x-model="situation"
                                class="w-full rounded-lg border px-3 py-2 text-sm {{ $errors->has('current_living_situation') ? 'border-red-400' : 'border-slate-300' }}">
                            <option value="">— Select —</option>
                            @foreach(\App\Models\RentalApplication::CURRENT_LIVING_SITUATION_LABELS as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('current_living_situation') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{--
                    Johan: "we need to include a part here for a person who
                    has sold his house and is going to rent for the first
                    time now." The landlord fields only apply when the
                    applicant is actually renting from someone right now —
                    shown/hidden by the answer above, never demanded of
                    someone they don't apply to. x-show (not x-if) so
                    anything already typed here is never lost by toggling
                    the answer back and forth, and every field stays
                    genuinely optional server-side either way.
                --}}
                <div x-show="situation === 'renting'" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                    <x-rental-application-field name="current_landlord_name" label="Landlord name" :value="$application->current_landlord_name" />
                    <x-rental-application-field name="current_landlord_tel" label="Landlord tel" :value="$application->current_landlord_tel" />
                    <x-rental-application-field name="current_rental_amount" label="Current rental amount (R)" type="text" inputmode="decimal" :value="$application->current_rental_amount" />
                    <x-rental-application-field name="current_rental_due_day" label="Rent due on which day of the month?" type="number" min="1" max="31" :value="$application->current_rental_due_day"
                        hint="e.g. 1 if your rent is due on the 1st of every month." />
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

                {{--
                    Johan: "a free text section where the applicant can
                    capture their own explanation of where they live /
                    lived." Deliberately open and always available — not
                    gated behind any particular answer above, since a
                    person's housing history doesn't always fit a box.
                --}}
                <div class="mt-3">
                    <label class="block text-xs text-slate-500 mb-1">Tell us more about where you live or have lived, in your own words (optional)</label>
                    <textarea name="current_living_situation_notes" rows="4"
                              class="w-full rounded-lg border px-3 py-2 text-sm {{ $errors->has('current_living_situation_notes') ? 'border-red-400' : 'border-slate-300' }}"
                              placeholder="Anything you'd like your agent to know about your living situation.">{{ old('current_living_situation_notes', $application->current_living_situation_notes) }}</textarea>
                    @error('current_living_situation_notes') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </section>

            <section data-progress-section="Employment">
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

            <section data-progress-section="Lease Requirement">
                <h2 class="font-semibold text-slate-700 mb-3">Lease Requirement</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <x-rental-application-field name="occupation_date" label="Effective date of occupation" type="date" :value="optional($application->occupation_date)->format('Y-m-d')" />
                    <div x-data="{ months: {{ old('rental_term_months', $application->rental_term_months) ?: 'null' }} }" class="sm:col-span-2">
                        <label class="block text-xs text-slate-500 mb-1">Rental terms required</label>
                        <input type="hidden" name="rental_term_months" :value="months">
                        <div class="flex gap-2">
                            <template x-for="m in [6, 12, 24]" :key="m">
                                {{-- scheduleAutosave() called explicitly — an Alpine :value binding
                                     (not x-model) never dispatches a native input/change event, so
                                     the form-level listeners in initAutosave() would otherwise never
                                     see this choice. --}}
                                <button type="button" @click="months = m; scheduleAutosave()"
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

            <section data-progress-section="Declaration">
                <h2 class="font-semibold text-slate-700 mb-2">Declaration</h2>
                <p class="text-xs text-slate-500 mb-2">I hereby declare that all the above information given is true and accurate.</p>
                @include('rental-applications.public._signature-pad', ['field' => 'declaration_signature', 'label' => 'declaration'])
            </section>

            <section data-progress-section="Tenant Profile Network Consent">
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

        // Applicant-side autosave, 2026-09-12 — Johan: "a member of the
        // public part-way through a rental application... losing everything
        // they have typed is a defect on a public form." Debounced (agency-
        // configurable, never hardcoded) + on blur; never fires on every
        // keystroke; degrades completely silently on any failure — an
        // applicant on a patchy connection must never see an error here.
        //
        // Applicant journey audit, 2026-09-12 — the debounce/blur mechanism
        // above left exactly one gap: whatever was typed in the LAST few
        // seconds before the tab is closed or backgrounded, if no blur
        // happened first, was never saved at all — proven directly (typed a
        // full sentence, closed the tab 1.5s later with no tap elsewhere:
        // nothing saved; waited 6s instead: saved correctly). This is the
        // exact real-world pattern a phone applicant produces constantly —
        // a notification, a call, the screen locking, closing the tab
        // meaning to finish "in a minute" — and it is precisely the failure
        // this whole feature exists to close. Two additions:
        //   1. `dirty` tracks whether anything has changed since the last
        //      successful save (of EITHER kind below).
        //   2. `visibilitychange`/`pagehide` listeners fire an IMMEDIATE
        //      save via navigator.sendBeacon() the instant the page is
        //      hidden or torn down — NOT beforeunload/unload, which mobile
        //      Safari and Chrome frequently never fire for a backgrounded
        //      tab that gets killed outright. A normal fetch() would be
        //      cancelled mid-flight the moment the page goes away;
        //      sendBeacon() is purpose-built to survive that and is
        //      queued/sent by the browser itself, not the page.
        autosaveStatus: '',
        // Sticky, visible, never auto-clears — the applicant must actually
        // notice this one. Everything saved before this point is safe;
        // only further typing is at risk until they submit.
        rateLimited: false,
        autosaveTimer: null,
        autosaveInFlight: false,
        autosavePending: false,
        autosaveDebounceMs: {{ (int) $autosaveDebounceSeconds * 1000 }},
        autosaveUrl: '{{ route('rental-applications.public.autosave', $application->token) }}',
        dirty: false,
        lastSavedAt: null,
        savedTickTimer: null,

        // Progress indicator, 2026-09-12 — deliberately just these two
        // numbers, nothing more: a scroll percentage for the thin line, and
        // an index/count for the small text label.
        scrollProgressPercent: 0,
        currentSectionLabel: '',
        progressSections: [],

        initProgress() {
            this.progressSections = Array.from(document.querySelectorAll('[data-progress-section]'));
            if (this.progressSections.length === 0) return;

            const update = () => {
                const doc = document.documentElement;
                const scrollable = doc.scrollHeight - doc.clientHeight;
                this.scrollProgressPercent = scrollable > 0 ? Math.min(100, Math.max(0, Math.round((window.scrollY / scrollable) * 100))) : 0;

                // The section whose heading has scrolled up past a point
                // just below the sticky top area is the one "currently
                // being read" — the same logic a sticky table-of-contents
                // nav uses, without needing IntersectionObserver for
                // something this simple.
                const threshold = 120;
                let current = this.progressSections[0];
                for (const section of this.progressSections) {
                    if (section.getBoundingClientRect().top <= threshold) current = section;
                }
                const index = this.progressSections.indexOf(current) + 1;
                this.currentSectionLabel = `Section ${index} of ${this.progressSections.length} — ${current.dataset.progressSection}`;
            };

            let ticking = false;
            window.addEventListener('scroll', () => {
                if (ticking) return;
                ticking = true;
                requestAnimationFrame(() => { update(); ticking = false; });
            }, { passive: true });

            update();
        },

        csrfToken() {
            return document.querySelector('meta[name="csrf-token"]').content;
        },

        initAutosave() {
            const form = document.getElementById('rentalApplicationSubmitForm');
            if (!form) return;

            // Debounced on typing/change — resets on every qualifying event
            // so a fast typist never triggers a save mid-word.
            form.addEventListener('input', () => { this.dirty = true; this.scheduleAutosave(); });
            form.addEventListener('change', () => { this.dirty = true; this.scheduleAutosave(); });

            // Immediate on blur (leaving a field) — bubbling focusout covers
            // every field without a per-input listener. Cancels any pending
            // debounce first so a blur right after typing never double-saves.
            form.addEventListener('focusout', (e) => {
                if (!e.target || !e.target.name) return;
                if (this.isSignatureField(e.target.name)) return;
                clearTimeout(this.autosaveTimer);
                this.autosaveNow();
            });

            // The last-resort net: fires the moment the page is hidden for
            // ANY reason (tab switch, app switch, screen lock, navigation,
            // the OS backgrounding the browser) — this is what catches the
            // typing that happened AFTER the last blur but BEFORE the
            // debounce timer had a chance to fire.
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') this.beaconSaveIfDirty();
            });
            // Belt-and-braces alongside visibilitychange — pagehide fires on
            // actual navigation/tab-close where visibilitychange sometimes
            // races it; harmless to fire twice since a beacon with nothing
            // new to say is just as cheap as one that saves something.
            window.addEventListener('pagehide', () => this.beaconSaveIfDirty());

            // Keep the "Saved Xs ago" text honest without polling the
            // server — ticks once a second, purely a display refresh
            // against the last known save time.
            this.savedTickTimer = setInterval(() => { if (this.lastSavedAt && !this.autosaveInFlight) this.autosaveStatus = this.formatSavedAgo(); }, 1000);
        },

        isSignatureField(name) {
            return name === 'declaration_signature' || name === 'tpn_consent_signature';
        },

        scheduleAutosave() {
            clearTimeout(this.autosaveTimer);
            this.autosaveTimer = setTimeout(() => this.autosaveNow(), this.autosaveDebounceMs);
        },

        formatSavedAgo() {
            const seconds = Math.max(0, Math.round((Date.now() - this.lastSavedAt) / 1000));
            if (seconds < 5) return 'Saved just now';
            if (seconds < 60) return `Saved ${seconds}s ago`;
            const minutes = Math.round(seconds / 60);
            return `Saved ${minutes} minute${minutes === 1 ? '' : 's'} ago`;
        },

        // Collects only the rental-application's own answer fields — never
        // the signature hidden inputs (signatures are NOT autosaved; they
        // stay an explicit act, both here and structurally on the server,
        // which only ever reads keys from RentalApplication::
        // fieldValidationRules() — a signature key posted here would be
        // ignored server-side too, this is belt-and-braces, not the only
        // guard) and never a file input (documents already upload and save
        // themselves independently, on selection).
        collectAutosavePayload() {
            const form = document.getElementById('rentalApplicationSubmitForm');
            const data = Object.fromEntries(new FormData(form).entries());
            delete data._token;
            delete data.declaration_signature;
            delete data.tpn_consent_signature;
            return data;
        },

        // Never throws, never surfaces an error to the applicant — a failed
        // autosave (network drop, expired/locked link, a transient
        // validation hiccup on one field) simply tries again on the next
        // debounce or blur. The server itself is equally tolerant: it saves
        // whatever validates and silently skips the rest rather than
        // rejecting the whole round.
        async autosaveNow() {
            if (this.autosaveInFlight) { this.autosavePending = true; return; }
            this.autosaveInFlight = true;
            this.autosaveStatus = 'Saving…';

            try {
                const res = await fetch(this.autosaveUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken(),
                    },
                    body: JSON.stringify(this.collectAutosavePayload()),
                });
                if (res.ok) {
                    const data = await res.json().catch(() => ({}));
                    if (data.rate_limited) {
                        // The ONE autosave failure that must NOT degrade
                        // silently — unlike a network blip this will not
                        // self-resolve on the next debounce, so an
                        // applicant left unaware would keep typing into a
                        // form that has stopped saving. Everything already
                        // saved up to this point is untouched; only what
                        // they type from here on is at risk.
                        this.autosaveStatus = '';
                        this.rateLimited = true;
                    } else if (data.saved) {
                        this.dirty = false;
                        this.lastSavedAt = Date.now();
                        this.autosaveStatus = this.formatSavedAgo();
                    } else {
                        this.autosaveStatus = '';
                    }
                } else {
                    // Every OTHER failure degrades silently — no visible
                    // error, just stop announcing a save that didn't
                    // happen. A network blip or a transient hiccup
                    // resolves itself on the next debounce.
                    this.autosaveStatus = '';
                }
            } catch (e) {
                this.autosaveStatus = '';
            } finally {
                this.autosaveInFlight = false;
                if (this.autosavePending) {
                    this.autosavePending = false;
                    this.autosaveNow();
                }
            }
        },

        // The page-is-going-away save. navigator.sendBeacon() is the only
        // API that guarantees the browser itself queues and sends the
        // request even after this page's own JS has stopped running — a
        // normal fetch() gets cancelled mid-flight the instant the page is
        // torn down. sendBeacon() cannot set custom headers, so the CSRF
        // token travels in the BODY (Laravel's VerifyCsrfToken accepts
        // `_token` in the request body exactly as a plain HTML form would)
        // rather than the X-CSRF-TOKEN header the normal fetch uses.
        // Otherwise this hits the EXACT SAME /autosave endpoint, with the
        // EXACT SAME payload shape (signatures still stripped) — every
        // guard on that endpoint (terminal-status refusal, expiry, the
        // per-application rate limit, field validation/sanitisation)
        // applies identically; there is no separate, weaker code path here.
        // No response is ever readable from a beacon, so this can only ever
        // be optimistic — `dirty` is cleared and the indicator updated on
        // the assumption it landed, exactly like the applicant's own
        // confidence at the moment they look away from a closing tab.
        beaconSaveIfDirty() {
            if (!this.dirty || typeof navigator.sendBeacon !== 'function') return;
            const payload = this.collectAutosavePayload();
            payload._token = this.csrfToken();
            const params = new URLSearchParams(payload);
            const sent = navigator.sendBeacon(this.autosaveUrl, params);
            if (sent) {
                this.dirty = false;
                this.lastSavedAt = Date.now();
                this.autosaveStatus = this.formatSavedAgo();
            }
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
