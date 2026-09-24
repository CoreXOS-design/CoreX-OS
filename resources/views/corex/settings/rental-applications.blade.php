{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Rental Application Settings</h1>
        <p class="text-xs" style="color: var(--text-muted);">
            The supporting-document checklist shown per employment type. Nothing here is ever
            enforced at submission — it only drives what shows as outstanding on a returned application.
        </p>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: var(--ds-emerald-soft, #ecfdf5); color: var(--ds-emerald, #059669);">{{ session('success') }}</div>
    @endif

    {{-- 2026-09-10 (cc5 regression pass) — this whole page had no error
         display at all: a validation failure on any form here (an invalid
         colour, and now a duplicate highlighter label) redirected back with
         $errors populated but nothing ever rendered it — a rejected save
         that looked identical to a silently accepted one. Generic, page-
         wide, not per-form: this page has many small forms and the failing
         one isn't always obvious from where the page scrolls back to. --}}
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: var(--ds-red-soft, #fef2f2); color: var(--ds-red, #dc2626); border: 1px solid var(--ds-red, #dc2626);">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('corex.settings.rental-applications.update') }}" class="space-y-4">
        @csrf

        @foreach(\App\Models\RentalApplication::EMPLOYMENT_TYPES as $type)
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold" style="color: var(--text-primary);">{{ str_replace('_', ' ', ucfirst($type)) }}</h2>
                @if($isConfigured[$type])
                    <span class="ds-badge ds-badge-info">Saved{{ empty($checklists[$type]) ? ' — none required' : '' }}</span>
                @else
                    <span class="ds-badge ds-badge-default" title="Showing the standard checklist — not yet saved for this agency">Default (not yet saved)</span>
                @endif
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                @foreach($documentTypes as $dt)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="checklists[{{ $type }}][]" value="{{ $dt->id }}"
                               @checked(in_array($dt->id, $checklists[$type]))>
                        {{ $dt->label }}
                    </label>
                @endforeach
            </div>
        </div>
        @endforeach

        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-xs">Save Checklist</button>
        </div>
    </form>

    {{-- AT-392 — Johan: "validity windows are per document type PER
         PURPOSE, agency-configurable — 2 months for the rental application,
         3 months for FICA including the ID copy. A stale document warns
         naming the purpose it fails and by how long, in plain language."
         Separate form/route, same reasoning as every other section on this
         page — one save can never interfere with another. --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);"
         x-data="{ rows: {{ Js::from($validityOverrides->map(fn ($o) => ['purpose' => $o->purpose, 'document_type_id' => $o->document_type_id, 'validity_days' => $o->validity_days])->values()) }} }">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Document Validity Windows</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            How old a supporting document may be before an agent sees a staleness warning on it. Applies wherever a
            document is picked or reviewed on the rental-application screen — never blocks anything by itself.
        </p>
        <form method="POST" action="{{ route('corex.settings.rental-applications.validity-windows') }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-2 gap-4 max-w-md">
                @foreach(\App\Models\RentalApplicationDocumentValidityWindow::PURPOSES as $purpose)
                    <label class="text-xs font-medium" style="color: var(--text-primary);">
                        {{ \App\Models\RentalApplicationDocumentValidityWindow::PURPOSE_LABELS[$purpose] }} default (days)
                        <input type="number" min="1" max="730" name="defaults[{{ $purpose }}]" value="{{ $validityDefaults[$purpose] }}"
                               class="mt-1 block w-full rounded-md text-sm" style="border: 1px solid var(--border); padding: 6px 8px;">
                    </label>
                @endforeach
            </div>

            <div>
                <h3 class="text-xs font-semibold mb-2" style="color: var(--text-primary);">Per-document-type overrides</h3>
                <template x-for="(row, i) in rows" :key="i">
                    <div class="flex items-center gap-2 mb-2 text-xs">
                        <select :name="'overrides[' + i + '][purpose]'" x-model="row.purpose" class="rounded-md" style="border: 1px solid var(--border); padding: 4px 6px;">
                            @foreach(\App\Models\RentalApplicationDocumentValidityWindow::PURPOSES as $purpose)
                                <option value="{{ $purpose }}">{{ \App\Models\RentalApplicationDocumentValidityWindow::PURPOSE_LABELS[$purpose] }}</option>
                            @endforeach
                        </select>
                        <select :name="'overrides[' + i + '][document_type_id]'" x-model.number="row.document_type_id" class="rounded-md flex-1" style="border: 1px solid var(--border); padding: 4px 6px;">
                            <option value="">Document type…</option>
                            @foreach($documentTypes as $dt)
                                <option value="{{ $dt->id }}">{{ $dt->label }}</option>
                            @endforeach
                        </select>
                        <input type="number" min="1" max="730" :name="'overrides[' + i + '][validity_days]'" x-model.number="row.validity_days" placeholder="days" class="w-20 rounded-md" style="border: 1px solid var(--border); padding: 4px 6px;">
                        <button type="button" @click="rows.splice(i, 1)" style="color: var(--ds-red, #dc2626);">Remove</button>
                    </div>
                </template>
                <button type="button" @click="rows.push({purpose: 'rental_application', document_type_id: '', validity_days: 60})"
                        class="text-xs font-medium" style="color: var(--brand-icon, #2563eb);">+ Add override</button>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="corex-btn-primary text-xs">Save Validity Windows</button>
            </div>
        </form>
    </div>

    {{-- AT-392 Phase 2 — Johan: "qualifying formula - agency can set this."
         Separate <form>/route so this save can never interfere with the
         checklist form above.
         2026-09-08 — Johan, from his own reading of the law: "the law
         states you may not spend more than 30% of your gross income on
         rentals... its not 3.5 or what you created it as of nett
         disposable income. its of the gross income." Rewritten from a
         "multiplier of rent" (the same arithmetic wearing a disguise) to
         the actual legal figure — a percentage OF GROSS INCOME. The law
         sets a CEILING (30%), not a fixed number: an agency may set a
         STRICTER (lower) figure, but the figure it applies to (gross
         income) is never itself configurable. --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Qualifying Formula</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            Used on the application review screen to suggest whether a tenant's stated GROSS
            income covers the rent — a prompt for the agent to look closer, never a rule that
            blocks or decides anything. The legal guideline (Rental Housing Act) is that rent
            should not exceed 30% of gross income — you may set a stricter (lower) figure for
            your own agency.
        </p>

        {{-- Persistent, not a one-time toast — Johan: "do not silently
             accept it as normal." A toast on save vanishes in a few
             seconds; a legal-compliance concern shouldn't be that easy to
             miss on a later visit to this same screen. --}}
        @if($qualifyingExceedsLegalCeiling)
            <div class="rounded-md px-3 py-2 text-xs mb-3" style="background: var(--ds-amber-soft, #fffbeb); color: var(--ds-amber, #b45309); border: 1px solid var(--ds-amber, #b45309);">
                This agency's qualifying formula ({{ number_format($qualifyingMaxRentPercent, 2) }}% of gross income) is above the legal guideline of 30%. Confirm this is intentional.
            </div>
        @endif

        <form method="POST" action="{{ route('corex.settings.rental-applications.qualifying-formula') }}" class="flex items-end gap-3">
            @csrf
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                    Rent must not exceed this % of GROSS monthly income
                </label>
                <input type="number" name="max_rent_percent_of_gross_income" step="0.01" min="0.1" max="100"
                       value="{{ old('max_rent_percent_of_gross_income', $qualifyingMaxRentPercent) }}"
                       class="corex-input text-sm" style="width: 100px;">
                <p class="text-[11px] mt-1" style="color: var(--text-muted);">Legal guideline: 30%. Set lower for a stricter agency policy.</p>
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Save Formula</button>
        </form>
    </div>

    {{-- Johan, 2026-09-20 — "hfc uses tpn so thats why we have that." Blank
         reads as generic "Credit Bureau Consent" wording everywhere this
         name is shown (the applicant form's heading and signature caption,
         this screen's own field label and section heading, the PDF) —
         correct both for an agency that hasn't set this yet and for one
         that genuinely runs no bureau check at all. --}}
    <div class="rounded-md p-4 mb-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Credit Bureau</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            Named in the applicant's consent wording and on the application PDF. Leave blank for
            generic "Credit Bureau" wording — also correct if your agency doesn't use one.
        </p>
        <form method="POST" action="{{ route('corex.settings.rental-applications.credit-bureau') }}" class="flex items-end gap-3">
            @csrf
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                    Credit bureau name
                </label>
                <input type="text" name="credit_bureau_name" maxlength="100"
                       value="{{ old('credit_bureau_name', $creditBureauName) }}"
                       placeholder="e.g. TPN"
                       class="corex-input text-sm" style="width: 220px;">
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Save Credit Bureau</button>
        </form>
    </div>

    {{-- Johan, from his own live walk, 2026-09-21 — an approved application
         linked to an active lease is a further state, not a new decision
         competing with Approved. Shown identically on the applications
         list tile, the application detail screen, and the contact record. --}}
    <div class="rounded-md p-4 mb-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Tenant Placed Label</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            Shown once an approved application is linked to an active lease — on the applications list,
            the application itself, and the contact record. Blank uses the default shown below.
        </p>
        <form method="POST" action="{{ route('corex.settings.rental-applications.tenanted-label') }}" class="flex items-end gap-3">
            @csrf
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                    Label
                </label>
                <input type="text" name="tenanted_label" maxlength="60"
                       value="{{ old('tenanted_label', $tenantedLabel) }}"
                       placeholder="{{ \App\Models\RentalApplicationQualifyingSetting::DEFAULT_TENANTED_LABEL }}"
                       class="corex-input text-sm" style="width: 220px;">
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Save Label</button>
        </form>
    </div>

    {{-- Reopen/resubmit, 2026-09-08 — "every threshold, window and business
         rule an agency-configurable setting with a sensible default. Nothing
         hardcoded." A reopened application's applicant link expires after
         this many days, same as the original invite link (14 by default). --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Reopened Application Link Expiry</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            When an agent reopens a returned application (to let the applicant fix an answer and
            re-sign), the applicant's link stays valid for this many days.
        </p>

        <form method="POST" action="{{ route('corex.settings.rental-applications.reopen-link-expiry') }}" class="flex items-end gap-3">
            @csrf
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Days before a reopened link expires</label>
                <input type="number" name="reopen_link_expiry_days" step="1" min="1" max="90"
                       value="{{ old('reopen_link_expiry_days', $reopenLinkExpiryDays) }}"
                       class="corex-input text-sm" style="width: 100px;">
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Save</button>
        </form>
    </div>

    {{-- Applicant-side autosave, 2026-09-12 — "every threshold, window and
         business rule an agency-configurable setting with a sensible
         default. Nothing hardcoded." How long the public application form
         waits after the applicant stops typing before it saves their
         answers in the background. Signatures are never autosaved — only
         the typed answers. --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Applicant Autosave Delay</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            While an applicant is filling out the online form, their typed answers save automatically
            after they pause typing for this long (and whenever they leave a field). Signatures are
            never autosaved — the applicant always signs as a separate, explicit step.
        </p>

        <form method="POST" action="{{ route('corex.settings.rental-applications.autosave-debounce') }}" class="flex items-end gap-3">
            @csrf
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Seconds of inactivity before autosaving</label>
                <input type="number" name="autosave_debounce_seconds" step="1" min="2" max="60"
                       value="{{ old('autosave_debounce_seconds', $autosaveDebounceSeconds) }}"
                       class="corex-input text-sm" style="width: 100px;">
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Save</button>
        </form>
    </div>

    {{-- Autosave volume cap, 2026-09-12 — a security/abuse-prevention limit
         on the public autosave endpoint (it accepts writes with no login),
         not a preference an agency would normally need to touch. Default is
         set generously above any real applicant's worst-case typing rate —
         raising or lowering it is rarely needed, but it must never be
         hardcoded per Johan's standing rule. --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Applicant Autosave Volume Cap</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            A safety limit on the public application form's background saving — caps how many
            autosave writes one application can receive in a rolling window, regardless of how
            many different devices or connections are used. The default is set far above anything
            a real applicant would ever produce; this exists to stop abuse of the public link, not
            to limit genuine use.
        </p>

        <form method="POST" action="{{ route('corex.settings.rental-applications.autosave-rate-limit') }}" class="flex items-end gap-3">
            @csrf
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Maximum autosaves</label>
                <input type="number" name="autosave_rate_limit_max" step="1" min="100" max="100000"
                       value="{{ old('autosave_rate_limit_max', $autosaveRateLimitMax) }}"
                       class="corex-input text-sm" style="width: 120px;">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Per this many minutes</label>
                <input type="number" name="autosave_rate_limit_window_minutes" step="1" min="5" max="1440"
                       value="{{ old('autosave_rate_limit_window_minutes', $autosaveRateLimitWindowMinutes) }}"
                       class="corex-input text-sm" style="width: 100px;">
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Save</button>
        </form>
    </div>

    {{-- Document upload volume cap, 2026-09-13 — AT-392. Johan was blocked
         live on QA1 by the PRE-EXISTING per-IP throttle on the public
         document upload/replace/remove routes: a real applicant's file
         picker fires one POST per file, concurrently, so a full document
         set plus one retry can legitimately run past a tight generic cap.
         Re-keyed to the application token (so one applicant can never
         exhaust another's allowance) and made agency-configurable here,
         same pattern as the autosave cap above — never hardcoded. --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Document Upload Volume Cap</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            A safety limit on the public application form's document upload, replace, and remove
            actions — caps how many of these an individual application can perform in a rolling
            window. The default is sized for a real applicant uploading a full document set with
            retries; this exists to stop abuse of the public link, not to limit genuine use.
        </p>

        <form method="POST" action="{{ route('corex.settings.rental-applications.document-rate-limit') }}" class="flex items-end gap-3">
            @csrf
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Maximum uploads</label>
                <input type="number" name="document_rate_limit_max" step="1" min="20" max="10000"
                       value="{{ old('document_rate_limit_max', $documentRateLimitMax) }}"
                       class="corex-input text-sm" style="width: 120px;">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Per this many minutes</label>
                <input type="number" name="document_rate_limit_window_minutes" step="1" min="1" max="1440"
                       value="{{ old('document_rate_limit_window_minutes', $documentRateLimitWindowMinutes) }}"
                       class="corex-input text-sm" style="width: 100px;">
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Save</button>
        </form>
    </div>

    {{-- Document closure on withdrawn/declined/approved, AT-392 round 2,
         2026-09-13 — cc3's finding: the document routes never checked
         status, so a withdrawn or declined applicant's link kept
         accepting files indefinitely. Withdrawn and declined are ALWAYS
         closed (no setting, Johan's ruling) — the only door back in is
         reopening the application. Approved stays open by default, but
         an agency may want to close it once a tenant is approved. --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Documents After Approval</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            Withdrawn and declined applications always stop accepting new documents — an agent can
            reopen the application if the applicant needs to send something more. For an APPROVED
            application, choose whether documents can still be added.
        </p>

        <form method="POST" action="{{ route('corex.settings.rental-applications.document-uploads-after-approval') }}" class="flex items-center gap-3">
            @csrf
            <input type="hidden" name="document_uploads_open_after_approval" value="0">
            <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);">
                <input type="checkbox" name="document_uploads_open_after_approval" value="1"
                       @checked(old('document_uploads_open_after_approval', $documentUploadsOpenAfterApproval))>
                Allow document uploads after an application is approved
            </label>
            <button type="submit" class="corex-btn-primary text-xs">Save</button>
        </form>
    </div>

    {{-- FICA-mandatory, AT-392 round 3, 2026-09-13 — Johan, a legal
         position: "technically we not allowed to work with anyone if did
         not fica." The application itself is ALWAYS received regardless
         of this setting — it only controls whether FICA must be complete
         before the application can be sent to the authoriser. --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">FICA Before Authorisation</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            The application is always received and the agent notified, whether or not FICA is finished.
            This setting only controls whether the application can be sent to the authoriser while FICA
            is still outstanding for the applicant.
        </p>

        <form method="POST" action="{{ route('corex.settings.rental-applications.require-fica-before-authorisation') }}" class="flex items-center gap-3">
            @csrf
            <input type="hidden" name="require_fica_before_authorisation" value="0">
            <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);">
                <input type="checkbox" name="require_fica_before_authorisation" value="1"
                       @checked(old('require_fica_before_authorisation', $requireFicaBeforeAuthorisation))>
                Require FICA to be complete before an application can go to the authoriser
            </label>
            <button type="submit" class="corex-btn-primary text-xs">Save</button>
        </form>
    </div>

    {{-- AT-430 Part A, 2026-09-24 — Johan, via Sherry (single-person Cape
         Town agency): today's flow always hands an application to a SECOND
         person for authorisation. For a one-person agency that hand-off is
         a screen she sends to herself. One step still requires the same
         RO/CO tier as today (Settings → Reviewers/Override below) — this
         setting removes a STEP, never a CHECK. --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Application Approval</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            Use one step if the same person handles and approves applications. Turn it on for
            single-person agencies.
        </p>

        <form method="POST" action="{{ route('corex.settings.rental-applications.approval-mode') }}">
            @csrf
            <div class="space-y-2 mb-3">
                <label class="flex items-start gap-2 text-xs cursor-pointer" style="color: var(--text-secondary);">
                    <input type="radio" name="approval_mode" value="two_step" class="mt-0.5"
                           @checked(old('approval_mode', $approvalMode) === 'two_step')>
                    <span><strong>Two step</strong> — agent submits, authoriser approves.</span>
                </label>
                <label class="flex items-start gap-2 text-xs cursor-pointer" style="color: var(--text-secondary);">
                    <input type="radio" name="approval_mode" value="one_step" class="mt-0.5"
                           @checked(old('approval_mode', $approvalMode) === 'one_step')>
                    <span><strong>One step</strong> — the agent approves directly.</span>
                </label>
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Save</button>
        </form>
    </div>

    {{--
        Submission hard floor, AT-392 round 5, 2026-09-13 — Johan, twice
        ruled: every field on the applicant form gets its own compulsory
        tick, no locked set — "we provide the system, they set it up the
        way they want to use it." Grouped by when a field actually applies,
        with the condition spelled out in plain words next to it, so ticking
        "Employer name" can never be misread as "always required" — a
        self-employed applicant must never be blocked by it.
    --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Compulsory Fields</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            Tick anything the applicant must fill in before they can submit. Everything else stays optional —
            an incomplete application is still received, and your team can follow up on any gaps from the
            review screen. Signatures aside, nothing here is required by CoreX itself; it is entirely your call.
        </p>

        @php
            $alwaysFields = collect($fieldRegistry)->whereNull('group');
            $groupedFields = [
                'employed' => ['label' => 'Only applies if the applicant says they are permanently employed', 'fields' => collect($fieldRegistry)->where('group', 'employed')],
                'renting' => ['label' => 'Only applies if the applicant says they are currently renting', 'fields' => collect($fieldRegistry)->where('group', 'renting')],
                'married' => ['label' => 'Only applies if the applicant\'s marital status is one you\'ve marked as implying a spouse (see Marital Status Options below)', 'fields' => collect($fieldRegistry)->where('group', 'married')],
            ];
        @endphp

        <form method="POST" action="{{ route('corex.settings.rental-applications.required-fields') }}">
            @csrf
            <input type="hidden" name="required_fields_submitted" value="1">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-4">
                @foreach($alwaysFields as $field)
                    <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);">
                        <input type="checkbox" name="required_field_keys[]" value="{{ $field['key'] }}"
                               @checked(in_array($field['key'], old('required_field_keys', $requiredFieldKeys), true))>
                        {{ $field['label'] }}
                    </label>
                @endforeach
            </div>

            @foreach($groupedFields as $groupKey => $group)
                @if($group['fields']->isNotEmpty())
                    <div class="mb-4 pl-3" style="border-left: 2px solid var(--border);">
                        <p class="text-[11px] mb-2 italic" style="color: var(--text-muted);">{{ $group['label'] }}</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            @foreach($group['fields'] as $field)
                                <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);">
                                    <input type="checkbox" name="required_field_keys[]" value="{{ $field['key'] }}"
                                           @checked(in_array($field['key'], old('required_field_keys', $requiredFieldKeys), true))>
                                    {{ $field['label'] }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach

            <p class="text-[11px] mb-3" style="color: var(--text-muted);">
                Both signatures (Declaration and {{ \App\Models\RentalApplication::creditBureauConsentLabel($creditBureauName) }}) are compulsory by default and can be unticked
                like any other field above — but a signature that IS provided must always be a real, drawn
                signature; a blank or corrupted one is never accepted either way.
            </p>

            <button type="submit" class="corex-btn-primary text-xs">Save</button>
        </form>
    </div>

    {{--
        .ai/specs/rental-application-field-config.md — SHOWN/HIDDEN, label
        and help-text overrides, and within-section ordering. Johan,
        2026-09-19: "everything is tick / untick for optional / compulsory"
        closed the locked-fields question — nothing here is exempt either;
        every field, including the two signatures, can be hidden the same
        as any other. Grouped by FORM SECTION (matches show.blade.php's own
        <section> boundaries) because ordering is scoped within a section,
        never across one.
    --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Field Display</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            Untick anything you don't want on your application form at all — the applicant never sees a
            field you've hidden here, and it is never asked for at submission either, whatever the
            Compulsory Fields setting above says. Override a label or add a hint if your own wording fits
            your process better. Position controls the order fields appear WITHIN their section below —
            leave it blank to keep the default order; lower numbers show first.
        </p>

        @php
            $fieldByKey = collect($fieldRegistry)->keyBy('key');
        @endphp

        <form method="POST" action="{{ route('corex.settings.rental-applications.field-display') }}">
            @csrf
            <input type="hidden" name="field_display_submitted" value="1">

            @foreach($fieldSections as $sectionName => $sectionKeys)
                <div class="mb-4 pb-3" style="border-bottom: 1px solid var(--border);">
                    <p class="text-xs font-semibold mb-2" style="color: var(--text-secondary);">{{ $sectionName }}</p>
                    <div class="space-y-2">
                        @foreach($sectionKeys as $key)
                            @continue(! $fieldByKey->has($key))
                            @php $field = $fieldByKey[$key]; @endphp
                            <div class="grid grid-cols-1 sm:grid-cols-12 gap-2 items-center text-xs">
                                <label class="sm:col-span-4 flex items-center gap-2" style="color: var(--text-secondary);">
                                    <input type="checkbox" name="shown_field_keys[]" value="{{ $key }}"
                                           @checked(in_array($key, old('shown_field_keys', array_values(array_diff(collect($fieldRegistry)->pluck('key')->all(), $hiddenFieldKeys))), true))>
                                    {{ $field['label'] }}
                                </label>
                                <input type="text" name="field_labels[{{ $key }}]"
                                       value="{{ old('field_labels.' . $key, $fieldLabelOverrides[$key] ?? '') }}"
                                       placeholder="Label override"
                                       class="sm:col-span-3 corex-input text-xs">
                                <input type="text" name="field_help_text[{{ $key }}]"
                                       value="{{ old('field_help_text.' . $key, $fieldHelpTextOverrides[$key] ?? '') }}"
                                       placeholder="Help text"
                                       class="sm:col-span-4 corex-input text-xs">
                                <input type="number" name="field_order[{{ $key }}]"
                                       value="{{ old('field_order.' . $key, array_search($key, $fieldOrder, true) !== false ? array_search($key, $fieldOrder, true) : '') }}"
                                       placeholder="Position"
                                       class="sm:col-span-1 corex-input text-xs">
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <button type="submit" class="corex-btn-primary text-xs">Save</button>
        </form>
    </div>

    {{--
        .ai/specs/rental-application-field-config.md §7, piece (c)(1) —
        custom fields an agency defines itself, beyond what CoreX ships.
        Definition only here — capture/consumption land in later pieces.
        Retiring keeps an already-captured answer intact on whatever
        application has one; it just stops offering the field to new ones.
    --}}
    <div id="custom-fields" class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Custom Fields</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">Questions of your own, beyond what CoreX ships.</p>

        @php $activeCustomFieldIds = $activeCustomFields->pluck('id')->all(); @endphp

        <div class="space-y-3 mb-4">
            @forelse($activeCustomFields as $i => $customField)
                <div x-data="{ type: {{ \Illuminate\Support\Js::from($customField->field_type) }} }" class="rounded-md p-2" style="border: 1px solid var(--border);">
                    <form method="POST" action="{{ route('corex.settings.rental-applications.custom-fields.update', $customField) }}" class="flex flex-wrap items-end gap-2">
                        @csrf
                        @method('PUT')
                        <span class="flex flex-col" style="line-height: 1;">
                            <button type="submit" form="cf-reorder-up-{{ $customField->id }}" @disabled($i === 0) title="Move up" class="text-xs" style="opacity: {{ $i === 0 ? '0.3' : '1' }};">&#9650;</button>
                            <button type="submit" form="cf-reorder-down-{{ $customField->id }}" @disabled($i === count($activeCustomFieldIds) - 1) title="Move down" class="text-xs" style="opacity: {{ $i === count($activeCustomFieldIds) - 1 ? '0.3' : '1' }};">&#9660;</button>
                        </span>
                        <div>
                            <label class="block text-[11px] mb-1" style="color: var(--text-muted);">Label</label>
                            <input type="text" name="label" value="{{ old('label', $customField->label) }}" maxlength="150" required class="corex-input text-xs" style="width: 160px;">
                        </div>
                        <div>
                            <label class="block text-[11px] mb-1" style="color: var(--text-muted);">Type</label>
                            <select name="field_type" x-model="type" class="corex-input text-xs" style="width: 110px;">
                                @foreach(\App\Models\RentalApplicationCustomField::FIELD_TYPES as $type)
                                    <option value="{{ $type }}">{{ str_replace('_', ' ', ucfirst($type)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div x-show="type === 'choice_list'" x-cloak>
                            <label class="block text-[11px] mb-1" style="color: var(--text-muted);">Options (comma-separated)</label>
                            <input type="text" name="options_text" value="{{ old('options_text', $customField->options ? implode(', ', $customField->options) : '') }}" maxlength="1000" class="corex-input text-xs" style="width: 200px;">
                        </div>
                        <div>
                            <label class="block text-[11px] mb-1" style="color: var(--text-muted);">Help text</label>
                            <input type="text" name="help_text" value="{{ old('help_text', $customField->help_text) }}" maxlength="1000" class="corex-input text-xs" style="width: 200px;">
                        </div>
                        <label class="flex items-center gap-1 text-xs" style="color: var(--text-secondary);">
                            <input type="checkbox" name="required" value="1" @checked(old('required', $customField->required))>
                            Compulsory
                        </label>
                        <label class="flex items-center gap-1 text-xs" style="color: var(--text-secondary);">
                            <input type="checkbox" name="shown" value="1" @checked(old('shown', $customField->shown))>
                            Shown
                        </label>
                        <button type="submit" class="text-xs" style="color: var(--ds-blue, #2563eb);">Save</button>
                    </form>
                    @if($i > 0)
                        @php $cfSwappedUp = $activeCustomFieldIds; [$cfSwappedUp[$i - 1], $cfSwappedUp[$i]] = [$cfSwappedUp[$i], $cfSwappedUp[$i - 1]]; @endphp
                        <form id="cf-reorder-up-{{ $customField->id }}" method="POST" action="{{ route('corex.settings.rental-applications.custom-fields.reorder') }}" style="display:none;">
                            @csrf
                            @foreach($cfSwappedUp as $orderedId)
                                <input type="hidden" name="order[]" value="{{ $orderedId }}">
                            @endforeach
                        </form>
                    @endif
                    @if($i < count($activeCustomFieldIds) - 1)
                        @php $cfSwappedDown = $activeCustomFieldIds; [$cfSwappedDown[$i], $cfSwappedDown[$i + 1]] = [$cfSwappedDown[$i + 1], $cfSwappedDown[$i]]; @endphp
                        <form id="cf-reorder-down-{{ $customField->id }}" method="POST" action="{{ route('corex.settings.rental-applications.custom-fields.reorder') }}" style="display:none;">
                            @csrf
                            @foreach($cfSwappedDown as $orderedId)
                                <input type="hidden" name="order[]" value="{{ $orderedId }}">
                            @endforeach
                        </form>
                    @endif
                    <div class="text-[11px] mt-1" style="color: var(--text-muted);">
                        {{ $customField->key }} &middot; {{ $customField->creator ? 'Added by ' . $customField->creator->name : 'Creator not recorded' }}
                        <form method="POST" action="{{ route('corex.settings.rental-applications.custom-fields.archive', $customField) }}" style="display:inline;" onsubmit="return confirm('Retire this custom field? Applications that already answered it keep that answer; it just won\'t be on new ones.');">
                            @csrf
                            <button type="submit" style="color: var(--text-muted); margin-left: 6px;">Retire</button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="text-xs" style="color: var(--text-muted);">No custom fields yet.</p>
            @endforelse
        </div>

        <div class="pt-2 mb-4" style="border-top: 1px solid var(--border);" x-data="{ type: 'text' }">
            <h3 class="text-xs font-semibold mb-2" style="color: var(--text-secondary);">Add a custom field</h3>
            <form method="POST" action="{{ route('corex.settings.rental-applications.custom-fields.store') }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <div>
                    <label class="block text-[11px] mb-1" style="color: var(--text-muted);">Label</label>
                    <input type="text" name="label" value="{{ old('label') }}" maxlength="150" placeholder="e.g. Pet deposit" required class="corex-input text-xs" style="width: 160px;">
                </div>
                <div>
                    <label class="block text-[11px] mb-1" style="color: var(--text-muted);">Type</label>
                    <select name="field_type" x-model="type" class="corex-input text-xs" style="width: 110px;">
                        @foreach(\App\Models\RentalApplicationCustomField::FIELD_TYPES as $type)
                            <option value="{{ $type }}">{{ str_replace('_', ' ', ucfirst($type)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div x-show="type === 'choice_list'" x-cloak>
                    <label class="block text-[11px] mb-1" style="color: var(--text-muted);">Options (comma-separated)</label>
                    <input type="text" name="options_text" value="{{ old('options_text') }}" maxlength="1000" placeholder="Small, Medium, Large" class="corex-input text-xs" style="width: 200px;">
                </div>
                <div>
                    <label class="block text-[11px] mb-1" style="color: var(--text-muted);">Help text</label>
                    <input type="text" name="help_text" value="{{ old('help_text') }}" maxlength="1000" class="corex-input text-xs" style="width: 200px;">
                </div>
                <label class="flex items-center gap-1 text-xs" style="color: var(--text-secondary);">
                    <input type="checkbox" name="required" value="1" @checked(old('required'))>
                    Compulsory
                </label>
                <button type="submit" class="corex-btn-primary text-xs">Add</button>
            </form>
        </div>

        <div class="pt-2" style="border-top: 1px solid var(--border);">
            <h3 class="text-xs font-semibold mb-2" style="color: var(--text-secondary);">Retired</h3>
            @if($retiredCustomFields->isEmpty())
                <p class="text-xs" style="color: var(--text-muted);">Nothing retired.</p>
            @else
                <div class="space-y-1">
                    @foreach($retiredCustomFields as $customField)
                        <div class="flex items-center gap-2 text-xs" style="opacity: 0.7;">
                            <span style="color: var(--text-secondary);">{{ $customField->label }}</span>
                            <span style="color: var(--text-muted);">({{ str_replace('_', ' ', ucfirst($customField->field_type)) }})</span>
                            <span style="color: var(--text-muted);">&middot; {{ $customField->creator ? 'Added by ' . $customField->creator->name : 'Creator not recorded' }}</span>
                            <form method="POST" action="{{ route('corex.settings.rental-applications.custom-fields.restore', $customField->id) }}">
                                @csrf
                                <button type="submit" style="color: var(--ds-blue, #2563eb);">Restore</button>
                            </form>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{--
        Ruling 1, AT-392 round 5, 2026-09-13 — Johan: marital_status
        converts from free text to a real select so the spouse-fields
        condition above can actually fire. Option list is agency-
        configurable, this is the sensible default.
    --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);" x-data="{
            options: {{ Js::from(old('marital_status_options', $maritalStatusOptions)) }}
         }">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Marital Status Options</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            What the applicant can choose from for marital status. Tick "Implies spouse" for any option where
            the spouse fields above should apply — normally just "Married".
        </p>

        <form method="POST" action="{{ route('corex.settings.rental-applications.marital-status-options') }}">
            @csrf
            <template x-for="(option, index) in options" :key="index">
                <div class="flex items-center gap-2 mb-2">
                    <input type="text" :name="'marital_status_options[' + index + '][label]'" x-model="option.label"
                           class="corex-input text-sm flex-1" placeholder="e.g. Married">
                    <label class="flex items-center gap-1 text-xs whitespace-nowrap" style="color: var(--text-secondary);">
                        <input type="checkbox" :name="'marital_status_options[' + index + '][implies_spouse]'" value="1" x-model="option.implies_spouse">
                        Implies spouse
                    </label>
                    <button type="button" @click="options.splice(index, 1)" class="text-xs" style="color: var(--danger, #dc2626);">Remove</button>
                </div>
            </template>
            <button type="button" @click="options.push({ label: '', implies_spouse: false })" class="text-xs mb-3" style="color: var(--accent);">+ Add option</button>
            <div>
                <button type="submit" class="corex-btn-primary text-xs">Save</button>
            </div>
        </form>
    </div>

    {{-- Return gate, AT-392 round 4, 2026-09-13 — Johan: "initial open is
         not gated but if the applicant submits... after initial submission
         we can gate on ID." The ID number is a speed bump (it is on every
         document that person has ever handed anyone), not authentication —
         email OTP is the stronger option for agencies that want the gate
         to actually hold. --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Applicant Return Gate</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            The first time an applicant opens their link is never gated. Every visit AFTER they submit
            is — that link now holds an ID number and uploaded documents. The ID number check is a
            speed bump against a forwarded link; email verification is stronger, for agencies that want it.
        </p>

        <form method="POST" action="{{ route('corex.settings.rental-applications.return-gate') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Verification method</label>
                <select name="return_gate_method" class="corex-input text-sm">
                    <option value="id_number" @selected(old('return_gate_method', $returnGateMethod) === 'id_number')>ID number</option>
                    <option value="email_otp" @selected(old('return_gate_method', $returnGateMethod) === 'email_otp')>Email verification code</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Maximum attempts</label>
                <input type="number" name="return_gate_attempt_max" step="1" min="2" max="50"
                       value="{{ old('return_gate_attempt_max', $returnGateAttemptMax) }}"
                       class="corex-input text-sm" style="width: 100px;">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Per this many minutes</label>
                <input type="number" name="return_gate_attempt_window_minutes" step="1" min="1" max="1440"
                       value="{{ old('return_gate_attempt_window_minutes', $returnGateAttemptWindowMinutes) }}"
                       class="corex-input text-sm" style="width: 90px;">
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Save</button>
        </form>
    </div>

    {{-- Submission identity gate, 2026-09-13 — Johan walked the applicant
         link himself, signed both pads, pressed submit, and landed
         straight in FICA with no identity challenge anywhere. This fires
         ONCE, on first submission, before the application is visible to
         the agency — a DIFFERENT moment from the Return Gate above, which
         only ever gates a LATER visit. Channel (email code, or an ID
         number fallback when no email is on file) is chosen per
         applicant automatically — never a setting here. --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Applicant Identity Gate</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            Verifies who is actually submitting before the application reaches your team — a code
            emailed to the applicant, or their ID number if no email is on file. Nothing an applicant
            has typed or signed is ever lost if they can't get past this; it just waits for them.
        </p>

        {{-- Persistent, not a one-time toast — same convention as the
             qualifying-formula banner above: a configuration gap
             shouldn't be easy to miss on a later visit to this screen. --}}
        @if($identityGateUnreachableByDesign)
            <div class="rounded-md px-3 py-2 text-xs mb-3" style="background: var(--ds-amber-soft, #fffbeb); color: var(--ds-amber, #b45309); border: 1px solid var(--ds-amber, #b45309);">
                Identity verification is on, but no field it can check (email, cell, or ID number) is
                currently compulsory for applicants. Applications may arrive that can't be verified —
                they'll be flagged on your applications list for you to follow up, never blocked at
                the applicant's end.
            </div>
        @endif

        <form method="POST" action="{{ route('corex.settings.rental-applications.identity-gate') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div class="flex items-center gap-2">
                <input type="checkbox" name="identity_gate_enabled" id="identity_gate_enabled" value="1"
                       @checked(old('identity_gate_enabled', $identityGateEnabled))>
                <label for="identity_gate_enabled" class="text-xs font-medium" style="color: var(--text-secondary);">Enabled</label>
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Code length</label>
                <input type="number" name="identity_gate_otp_length" step="1" min="4" max="10"
                       value="{{ old('identity_gate_otp_length', $identityGateOtpLength ?? config('otp.length', 6)) }}"
                       class="corex-input text-sm" style="width: 80px;">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Code valid for (minutes)</label>
                <input type="number" name="identity_gate_otp_expiry_minutes" step="1" min="1" max="60"
                       value="{{ old('identity_gate_otp_expiry_minutes', $identityGateOtpExpiryMinutes ?? config('otp.expires_minutes', 10)) }}"
                       class="corex-input text-sm" style="width: 90px;">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Maximum attempts</label>
                <input type="number" name="identity_gate_attempt_max" step="1" min="2" max="50"
                       value="{{ old('identity_gate_attempt_max', $identityGateAttemptMax) }}"
                       class="corex-input text-sm" style="width: 100px;">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Per this many minutes</label>
                <input type="number" name="identity_gate_attempt_window_minutes" step="1" min="1" max="1440"
                       value="{{ old('identity_gate_attempt_window_minutes', $identityGateAttemptWindowMinutes) }}"
                       class="corex-input text-sm" style="width: 90px;">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Resend cooldown (seconds)</label>
                <input type="number" name="identity_gate_resend_cooldown_seconds" step="1" min="10" max="600"
                       value="{{ old('identity_gate_resend_cooldown_seconds', $identityGateResendCooldownSeconds ?? config('otp.resend_cooldown_secs', 60)) }}"
                       class="corex-input text-sm" style="width: 100px;">
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Save</button>
        </form>
    </div>

    {{-- Public link volume caps, AT-392 round 2, 2026-09-13 — conductor's
         sweep of the remaining public applicant-journey routes, all of
         which still carried Laravel's stock per-IP throttle (the exact
         defect the document-upload fix closed). All five re-keyed to the
         application token; grouped into one panel rather than five
         separate boxes since they're the same class of setting. --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Public Link Volume Caps</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            Safety limits on the rest of the public application link — each caps how many of that
            action an individual application can trigger in a rolling window, independent of every
            other applicant sharing a connection. Defaults are set generously above real use.
        </p>

        <form method="POST" action="{{ route('corex.settings.rental-applications.route-rate-limits') }}" class="space-y-3">
            @csrf
            <div class="grid gap-2" style="grid-template-columns: 1fr auto auto;">
                <div class="text-xs font-medium" style="color: var(--text-muted);"></div>
                <div class="text-xs font-medium text-center" style="color: var(--text-muted);">Maximum</div>
                <div class="text-xs font-medium text-center" style="color: var(--text-muted);">Per minutes</div>

                <label class="text-sm self-center" style="color: var(--text-secondary);">Page views (Show)</label>
                <input type="number" name="show_rate_limit_max" step="1" min="10" max="10000"
                       value="{{ old('show_rate_limit_max', $showRateLimitMax) }}" class="corex-input text-sm" style="width: 100px;">
                <input type="number" name="show_rate_limit_window_minutes" step="1" min="1" max="1440"
                       value="{{ old('show_rate_limit_window_minutes', $showRateLimitWindowMinutes) }}" class="corex-input text-sm" style="width: 90px;">

                <label class="text-sm self-center" style="color: var(--text-secondary);">Submissions</label>
                <input type="number" name="submit_rate_limit_max" step="1" min="3" max="10000"
                       value="{{ old('submit_rate_limit_max', $submitRateLimitMax) }}" class="corex-input text-sm" style="width: 100px;">
                <input type="number" name="submit_rate_limit_window_minutes" step="1" min="1" max="1440"
                       value="{{ old('submit_rate_limit_window_minutes', $submitRateLimitWindowMinutes) }}" class="corex-input text-sm" style="width: 90px;">

                <label class="text-sm self-center" style="color: var(--text-secondary);">PDF downloads</label>
                <input type="number" name="pdf_rate_limit_max" step="1" min="10" max="10000"
                       value="{{ old('pdf_rate_limit_max', $pdfRateLimitMax) }}" class="corex-input text-sm" style="width: 100px;">
                <input type="number" name="pdf_rate_limit_window_minutes" step="1" min="1" max="1440"
                       value="{{ old('pdf_rate_limit_window_minutes', $pdfRateLimitWindowMinutes) }}" class="corex-input text-sm" style="width: 90px;">

                <label class="text-sm self-center" style="color: var(--text-secondary);">Document views</label>
                <input type="number" name="document_view_rate_limit_max" step="1" min="10" max="10000"
                       value="{{ old('document_view_rate_limit_max', $documentViewRateLimitMax) }}" class="corex-input text-sm" style="width: 100px;">
                <input type="number" name="document_view_rate_limit_window_minutes" step="1" min="1" max="1440"
                       value="{{ old('document_view_rate_limit_window_minutes', $documentViewRateLimitWindowMinutes) }}" class="corex-input text-sm" style="width: 90px;">

                <label class="text-sm self-center" style="color: var(--text-secondary);">Background saves (Autosave)</label>
                <input type="number" name="autosave_request_rate_limit_max" step="1" min="10" max="10000"
                       value="{{ old('autosave_request_rate_limit_max', $autosaveRequestRateLimitMax) }}" class="corex-input text-sm" style="width: 100px;">
                <input type="number" name="autosave_request_rate_limit_window_minutes" step="1" min="1" max="1440"
                       value="{{ old('autosave_request_rate_limit_window_minutes', $autosaveRequestRateLimitWindowMinutes) }}" class="corex-input text-sm" style="width: 90px;">
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Save all</button>
        </form>
    </div>

    {{-- Item 2 follow-up, 2026-09-10 — Johan: "any threshold, window or
         business rule an agency-configurable setting with a sensible
         default, never hardcoded." Reproduced on a real, fully-approved
         application: the linked property could be swapped or cleared at
         any point, including while an authoriser was actively deciding
         against it, and after the outcome email had already gone out
         naming it. Default locked from submission for authorisation
         onward — see RentalApplicationQualifyingSetting::PROPERTY_LOCKED_STATUSES
         for exactly which statuses that covers. Server-enforced in
         linkProperty() itself regardless of this checkbox's state on
         screen; this only controls what that check allows. --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Property Link Lock</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            Once an application is submitted for authorisation, the linked property is what the
            authoriser's decision — and the applicant's outcome email — are actually based on.
            With this on, the linked property can no longer be changed or cleared from that point
            (submitted for authorisation, approved, or declined) — only while the agent is still
            preparing the application. Turn this off to allow changing the property at any time,
            as before.
        </p>

        <form method="POST" action="{{ route('corex.settings.rental-applications.property-lock') }}" class="flex items-center gap-3">
            @csrf
            <input type="hidden" name="lock_property_after_submission" value="0">
            <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);">
                <input type="checkbox" name="lock_property_after_submission" value="1"
                       @checked(old('lock_property_after_submission', $propertyLockEnabled))>
                Lock the linked property once submitted for authorisation
            </label>
            <button type="submit" class="corex-btn-primary text-xs">Save</button>
        </form>
    </div>

    {{-- Contact-type ruling, 2026-09-11 — Johan, verbatim: "contact type
         can be added, not changed... the seller of unit a decides to rent
         but their property has not sold yet. so that contact will be
         dealt with as a seller on their property but also as a tenant
         inside rentals." Approval ADDS Tenant to whatever the contact
         already is — it never replaces or clears an existing type. --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Tag Contact as Tenant on Approval</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            When a rental application is approved, add "Tenant" to the applicant's contact record —
            alongside any type they already have, never instead of it. A seller whose own property
            hasn't sold yet, for example, is correctly shown as both once they're approved to rent
            elsewhere. Turn this off if you don't want approval to change contact types at all.
        </p>

        <form method="POST" action="{{ route('corex.settings.rental-applications.tenant-tagging') }}" class="flex items-center gap-3">
            @csrf
            <input type="hidden" name="tag_contact_as_tenant_on_approval" value="0">
            <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);">
                <input type="checkbox" name="tag_contact_as_tenant_on_approval" value="1"
                       @checked(old('tag_contact_as_tenant_on_approval', $tenantTaggingEnabled))>
                Add "Tenant" to the contact when their application is approved
            </label>
            <button type="submit" class="corex-btn-primary text-xs">Save</button>
        </form>
    </div>

    {{-- AT-392 approval-leg, 2026-09-10 — Johan's standing rule: "any
         threshold, window or business rule must be an agency-configurable
         setting with a sensible default, never hardcoded." How many
         matched properties (wishlist-scored or fallback, always at or
         under the applicant's approved amount) the agent's approval email
         carries at most. --}}
    <div id="approval-email" class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Approval Email — Matched Properties</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            When an agent sends the approval email, this is the most properties it will list —
            always within the applicant's approved amount, never above it.
        </p>

        <form method="POST" action="{{ route('corex.settings.rental-applications.approval-email') }}" class="flex items-end gap-3">
            @csrf
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Maximum properties per approval email</label>
                <input type="number" name="max_properties_in_email" step="1" min="1" max="20"
                       value="{{ old('max_properties_in_email', $maxPropertiesInEmail) }}"
                       class="corex-input text-sm" style="width: 100px;">
                <p class="text-[11px] mt-1" style="color: var(--text-muted);">Default: 5.</p>
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Save</button>
        </form>
    </div>

    {{-- Highlighter collection expansion, 2026-09-09 — Johan: "we allow an
         agency to set up which highlighters they want... as many as they
         want, each with their own label." Replaces the fixed
         three-category/six-colour block: full CRUD over an agency-owned,
         arbitrary-length collection (RentalApplicationHighlighter). Label
         and colour are both agency-configurable now — "each with their own
         label" settles what was previously an open question (whether
         category labels, not just colours, should be admin-configurable).
         Archiving (not deleting) is the only removal — an archived
         highlighter keeps rendering every mark already drawn with it, it
         just disappears from the drawing picker; restorable below. --}}
    <div id="highlighters" class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Highlighters</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            The highlighters agents and authorisers use to mark up application documents — as
            many as you want, each with its own label, colour, and which role(s) may use it.
            Recolouring one here changes every mark already drawn with it, everywhere it's
            shown. Archiving keeps existing marks exactly as they render today; it just removes
            that highlighter from the picker for new marks.
        </p>

        @php
            $activeIds = $activeHighlighters->pluck('id')->all();
        @endphp

        {{-- 2026-09-09 (design-standard audit) — one search box drives both
             halves: it live-filters active rows client-side (x-show below,
             never touching $activeIds — reorder stays correct regardless
             of what's visually hidden) and, on submit, re-queries the
             archived list server-side. Archived sort/pagination are real
             (archived rows have no reorder dependency to protect). --}}
        <div x-data="{ highlighterFilter: @js($highlighterQuery) }" class="mb-3">
            <form method="GET" action="{{ route('corex.settings.rental-applications.edit') }}#highlighters" class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="block text-[11px] mb-1" style="color: var(--text-muted);">Search highlighters</label>
                    <input type="text" name="highlighter_q" x-model="highlighterFilter" placeholder="Label" class="corex-input text-xs" style="width: 180px;">
                </div>
                <div>
                    <label class="block text-[11px] mb-1" style="color: var(--text-muted);">Sort archived</label>
                    <select name="highlighter_archived_sort" class="corex-input text-xs" onchange="this.form.submit()">
                        <option value="label" @selected($highlighterArchivedSort === 'label')>A&ndash;Z</option>
                        <option value="recent" @selected($highlighterArchivedSort === 'recent')>Most recently archived</option>
                    </select>
                </div>
                <button type="submit" class="corex-btn-outline text-xs">Search</button>
                @if($highlighterQuery !== '')
                    <a href="{{ route('corex.settings.rental-applications.edit') }}#highlighters" class="corex-btn-outline text-xs">Clear</a>
                @endif
            </form>

            <div class="space-y-2 mb-4 mt-3">
                @forelse($activeHighlighters as $i => $highlighter)
                    <div x-show="highlighterFilter === '' || {{ \Illuminate\Support\Js::from(strtolower($highlighter->label)) }}.includes(highlighterFilter.toLowerCase())">
                <form method="POST" action="{{ route('corex.settings.rental-applications.highlighters.update', $highlighter) }}" class="flex items-center gap-2">
                    @csrf
                    @method('PUT')
                    <span class="flex flex-col" style="line-height: 1;">
                        <button type="submit" form="reorder-up-{{ $highlighter->id }}" @disabled($i === 0) title="Move up" class="text-xs" style="opacity: {{ $i === 0 ? '0.3' : '1' }};">&#9650;</button>
                        <button type="submit" form="reorder-down-{{ $highlighter->id }}" @disabled($i === count($activeIds) - 1) title="Move down" class="text-xs" style="opacity: {{ $i === count($activeIds) - 1 ? '0.3' : '1' }};">&#9660;</button>
                    </span>
                    <input type="color" name="color" value="{{ old('color', $highlighter->color) }}" class="corex-input" style="width: 40px; height: 32px; padding: 2px; flex-shrink: 0;">
                    <input type="text" name="label" value="{{ old('label', $highlighter->label) }}" maxlength="100" required class="corex-input text-xs" style="width: 140px;">
                    <select name="role_scope" class="corex-input text-xs" style="width: 130px;">
                        @foreach(['agent' => 'Agent', 'authoriser' => 'Authoriser', 'both' => 'Agent + Authoriser'] as $val => $roleLabel)
                            <option value="{{ $val }}" @selected(old('role_scope', $highlighter->role_scope) === $val)>{{ $roleLabel }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="text-xs" style="color: var(--ds-blue, #2563eb);">Save</button>
                </form>
                @if($i > 0)
                    @php $swappedUp = $activeIds; [$swappedUp[$i - 1], $swappedUp[$i]] = [$swappedUp[$i], $swappedUp[$i - 1]]; @endphp
                    <form id="reorder-up-{{ $highlighter->id }}" method="POST" action="{{ route('corex.settings.rental-applications.highlighters.reorder') }}" style="display:none;">
                        @csrf
                        @foreach($swappedUp as $orderedId)
                            <input type="hidden" name="order[]" value="{{ $orderedId }}">
                        @endforeach
                    </form>
                @endif
                @if($i < count($activeIds) - 1)
                    @php $swappedDown = $activeIds; [$swappedDown[$i], $swappedDown[$i + 1]] = [$swappedDown[$i + 1], $swappedDown[$i]]; @endphp
                    <form id="reorder-down-{{ $highlighter->id }}" method="POST" action="{{ route('corex.settings.rental-applications.highlighters.reorder') }}" style="display:none;">
                        @csrf
                        @foreach($swappedDown as $orderedId)
                            <input type="hidden" name="order[]" value="{{ $orderedId }}">
                        @endforeach
                    </form>
                @endif
                {{-- 2026-09-09 (Johan) — created_by is nullable and never
                     backfilled; the 39 pre-existing rows have no creator on
                     record and never will. Say so plainly rather than
                     leaving a gap. --}}
                <div class="text-[11px] mb-1" style="color: var(--text-muted); margin-left: 2px;" @if(!$highlighter->creator) title="This highlighter was created before this was tracked." @endif>
                    {{ $highlighter->creator ? 'Added by ' . $highlighter->creator->name : 'Creator not recorded' }}
                </div>
                <form method="POST" action="{{ route('corex.settings.rental-applications.highlighters.archive', $highlighter) }}" style="display:inline;" onsubmit="return confirm('Archive this highlighter? Existing marks made with it keep their colour — it just won\'t be choosable for new marks.');">
                    @csrf
                    <button type="submit" class="text-xs" style="color: var(--text-muted); margin-left: 0;">Archive</button>
                </form>
                    </div>
            @empty
                <p class="text-xs" style="color: var(--text-muted);">No highlighters configured yet — the six starting ones below are the defaults every agency ships with.</p>
            @endforelse
            </div>
        </div>

        <div class="pt-2 mb-4" style="border-top: 1px solid var(--border);">
            <h3 class="text-xs font-semibold mb-2" style="color: var(--text-secondary);">Add a highlighter</h3>
            <form method="POST" action="{{ route('corex.settings.rental-applications.highlighters.store') }}" class="flex items-end gap-2">
                @csrf
                <div>
                    <label class="block text-[11px] mb-1" style="color: var(--text-muted);">Colour</label>
                    <input type="color" name="color" value="{{ old('color', '#94a3b8') }}" class="corex-input" style="width: 40px; height: 32px; padding: 2px;">
                </div>
                <div>
                    <label class="block text-[11px] mb-1" style="color: var(--text-muted);">Label</label>
                    <input type="text" name="label" value="{{ old('label') }}" maxlength="100" placeholder="e.g. Deposit" required class="corex-input text-xs" style="width: 140px;">
                </div>
                <div>
                    <label class="block text-[11px] mb-1" style="color: var(--text-muted);">Who may use it</label>
                    <select name="role_scope" class="corex-input text-xs" style="width: 130px;">
                        <option value="agent">Agent</option>
                        <option value="authoriser">Authoriser</option>
                        <option value="both">Agent + Authoriser</option>
                    </select>
                </div>
                <button type="submit" class="corex-btn-primary text-xs">Add</button>
            </form>
        </div>

        {{-- 2026-09-09 (design-standard audit) — this whole block used to be
             wrapped in @if($archivedHighlighters->isNotEmpty()), so an
             agency with nothing archived rendered NOTHING here — the exact
             class of bug already fixed on the rental application audit
             trail tonight: a genuinely empty section is indistinguishable
             from a missing one. The heading now always renders; only the
             body switches between the real list and a plain-language empty
             state, same wording already used for archived rental
             applications on this module's own index screen. --}}
        <div class="pt-2" style="border-top: 1px solid var(--border);">
            <h3 class="text-xs font-semibold mb-2" style="color: var(--text-secondary);">Archived</h3>
            @if($archivedHighlighters->isEmpty())
                <p class="text-xs" style="color: var(--text-muted);">Nothing archived.</p>
            @else
                <div class="space-y-1">
                    @foreach($archivedHighlighters as $highlighter)
                        <div class="flex items-center gap-2 text-xs" style="opacity: 0.7;">
                            <span style="display:inline-block; width:16px; height:16px; border-radius:3px; background: {{ $highlighter->color }};"></span>
                            <span style="color: var(--text-secondary);">{{ $highlighter->label }}</span>
                            <span style="color: var(--text-muted);">({{ $highlighter->role_scope === 'both' ? 'Agent + Authoriser' : ucfirst($highlighter->role_scope) }})</span>
                            <span style="color: var(--text-muted);" @if(!$highlighter->creator) title="This highlighter was created before this was tracked." @endif>
                                &middot; {{ $highlighter->creator ? 'Added by ' . $highlighter->creator->name : 'Creator not recorded' }}
                            </span>
                            <form method="POST" action="{{ route('corex.settings.rental-applications.highlighters.restore', $highlighter->id) }}">
                                @csrf
                                <button type="submit" style="color: var(--ds-blue, #2563eb);">Restore</button>
                            </form>
                        </div>
                    @endforeach
                </div>
                {{ $archivedHighlighters->onEachSide(1)->fragment('highlighters')->links() }}
            @endif
        </div>
    </div>

    {{-- AT-392 authoriser flow, 2026-09-08 — Johan: "each agency will want
         their own wording on declined." A suggested default ships until the
         agency saves their own — same forAgency()-never-writes-on-read
         pattern as Qualifying Formula above. Merge fields are limited to
         what this email can always honestly populate — applicant name,
         agency name, and (optionally) the property the application was for.
         AT-410b, 2026-09-15 — "no invented guidance" above is superseded:
         the authoriser now picks a reason-plus-guidance template at the
         moment of declining (cc2's build, linked below), and its content
         merges into THIS envelope via {{decline_guidance}} — this section
         still owns only the greeting/thanks/sign-off tone, never the
         reason/guidance content itself. --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Decline Email</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            Sent to the applicant when the AGENT sends the authoriser's decline decision (not automatic —
            the agent reviews and can edit the exact text first). A suggested wording is shown below —
            edit it to your own. Available merge fields:
            <code>@{{applicant_name}}</code>, <code>@{{agency_name}}</code>,
            <code>@{{property_reference}}</code> (optional — resolves to nothing if the
            application has no property linked), <code>@{{decline_reason}}</code> (the picked
            template's short label), <code>@{{decline_guidance}}</code> (that template's full
            reason-and-guidance text — this is where the general tips the authoriser picked appear).
        </p>
        <form method="POST" action="{{ route('corex.settings.rental-applications.decline-email') }}" class="space-y-3">
            @csrf
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Subject</label>
                <input type="text" name="subject" value="{{ old('subject', $declineEmail['subject']) }}" class="corex-input text-sm w-full">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Body</label>
                <textarea name="body" rows="10" class="corex-input text-sm w-full">{{ old('body', $declineEmail['body']) }}</textarea>
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Save Decline Email</button>
        </form>
        {{-- AT-410b — cc2 owns this page/controller/CRUD entirely; this is
             the one line agreed between us so the two builds don't collide
             on this settings page. --}}
        <p class="text-xs mt-3 pt-3" style="border-top: 1px solid var(--border);">
            <a href="{{ route('corex.settings.rental-applications.decline-reason-templates.index') }}" class="font-medium" style="color: var(--ds-blue, #2563eb);">Manage decline reason templates &rarr;</a>
        </p>
    </div>

    {{-- AT-392 authoriser flow, 2026-09-08 — Johan, verbatim: "there like on
         esign needs to be the ro then co approval process? so admin or bm
         acts like the co. selected agents act as ro... ro can approve /
         decline. but then lets say the tenant speaks to admin and they
         decide they want to override ro, then can approve / decline with
         reasons given... like an admin override. Both configured as agency
         settings, multi-select from users, exactly like the existing CO and
         RO settings." Copied precisely from settings.blade.php's "Section B:
         MLROs / Reporting Officers" (FICA) — checkboxes over $agencyUsers,
         name="..._user_ids[]", one form per tier, same as MLRO's
         mlro_user_ids[] shape. Deliberately NOT fica_officer_appointments'
         dated-appointment table — no legal appointment-history requirement
         here, just "who currently holds this tier" (see
         .ai/specs/rental-applications.md for the full reasoning). --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">RO — Application Reviewers</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            These users can approve, decline, or request more information on an application
            an agent has submitted for approval.
        </p>
        <form method="POST" action="{{ route('corex.settings.rental-applications.ro') }}">
            @csrf
            <div class="space-y-1 max-h-48 overflow-y-auto mb-3 rounded-md p-2" style="border: 1px solid var(--border); background: var(--surface);">
                @forelse($agencyUsers as $u)
                    <label class="flex items-center gap-2 py-1 px-1 text-sm cursor-pointer hover:bg-[color:var(--surface-2)] rounded">
                        <input type="checkbox" name="rental_application_ro_user_ids[]" value="{{ $u->id }}"
                               {{ in_array($u->id, $roUserIds) ? 'checked' : '' }} style="accent-color: var(--brand-button, #0ea5e9);">
                        <span style="color: var(--text-primary);">{{ $u->name }}</span>
                        <span class="text-xs" style="color: var(--text-muted);">{{ $u->role }}</span>
                    </label>
                @empty
                    <p class="text-xs px-1 py-1" style="color: var(--text-muted);">No active users in this agency.</p>
                @endforelse
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Save Reviewers</button>
        </form>
    </div>

    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">CO — Overrides</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            Typically an admin or branch manager. These users can do everything a Reviewer can, and
            can also OVERRIDE a Reviewer's existing decision (change an approve to a decline, or a
            decline to an approve) — a reason is required whenever they do.
        </p>
        <form method="POST" action="{{ route('corex.settings.rental-applications.co') }}">
            @csrf
            <div class="space-y-1 max-h-48 overflow-y-auto mb-3 rounded-md p-2" style="border: 1px solid var(--border); background: var(--surface);">
                @forelse($agencyUsers as $u)
                    <label class="flex items-center gap-2 py-1 px-1 text-sm cursor-pointer hover:bg-[color:var(--surface-2)] rounded">
                        <input type="checkbox" name="rental_application_co_user_ids[]" value="{{ $u->id }}"
                               {{ in_array($u->id, $coUserIds) ? 'checked' : '' }} style="accent-color: var(--brand-button, #0ea5e9);">
                        <span style="color: var(--text-primary);">{{ $u->name }}</span>
                        <span class="text-xs" style="color: var(--text-muted);">{{ $u->role }}</span>
                    </label>
                @empty
                    <p class="text-xs px-1 py-1" style="color: var(--text-muted);">No active users in this agency.</p>
                @endforelse
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Save Overrides</button>
        </form>
    </div>
</div>
@endsection
