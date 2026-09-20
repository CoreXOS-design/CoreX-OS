{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@php
    $checklist = $rentalApplication->employment_type
        ? \App\Models\RentalApplicationDocumentRequirement::checklistFor($rentalApplication->agency_id, $rentalApplication->employment_type)
        : collect();
    $onFileTypeIds = $rentalApplication->documents->pluck('document_type_id')->filter()->all();
    // Current-living-situation ruling, 2026-09-11 — same backward-compatibility
    // default as the public form: an application from before this field
    // existed may already carry real landlord data with no
    // current_living_situation answer. Computed here (this file's one
    // top-level @php block) rather than a second @php block further down —
    // a second block at that position broke Blade compilation outright
    // (confirmed by direct bisection: reproduces with a trivial one-line
    // @php block, unrelated to its content), a real compiler quirk on this
    // file, not a mistake in the added logic itself.
    $situationDefault = $rentalApplication->current_living_situation
        ?? ($rentalApplication->current_landlord_name ? 'renting' : '');
@endphp

@section('corex-content')
<div class="w-full space-y-5" x-data="{ dirty: false }">

    {{--
        Johan, QA1 — "I would plainly have included the save button into
        the same header, and further more being a header had that frozen
        on the screen... a user can scroll through document, edit and
        update details and get to save / send eventually at the top."
        Sticky header (shared x-sticky-action-bar component, same one used
        elsewhere in CoreX) replaces the old static banner. Save moves here
        (via form="rentalApplicationForm", the big form below) — it no
        longer lives stranded at the bottom of a long scroll. Send is
        disabled — never enabled-then-error — until the record has a
        genuinely saved email AND there are no unsaved edits (`dirty`,
        set by a single delegated @input/@change on the big form below,
        so no per-field wiring was needed).
    --}}
    <x-sticky-action-bar>
        <x-slot name="left">
            <div class="min-w-0">
                <h1 class="text-sm font-bold leading-tight truncate" style="color: var(--text-primary);">
                    {{ $rentalApplication->contact->full_name ?? 'Rental Application' }}
                </h1>
                <span class="ds-badge {{ $rentalApplication->status === 'draft' ? 'ds-badge-muted' : 'ds-badge-info' }}">
                    {{ str_replace('_', ' ', $rentalApplication->status) }}
                </span>
            </div>
        </x-slot>
        <x-slot name="right">
            <a href="{{ route('corex.rental-applications.pdf', $rentalApplication) }}" class="corex-btn-outline text-xs">Download PDF</a>

            @permission('rental_applications.create')
            <button type="submit" form="rentalApplicationForm" class="text-xs"
                    :class="dirty ? 'corex-btn-primary' : 'corex-btn-outline'">
                Save
            </button>

            {{-- .ai/specs/rental-application-field-config.md §7, piece
                 (c)(3) — the bare single-parenthesis PHP-directive
                 one-liner form (removed below) has no guard against
                 Blade's raw-PHP extraction regex treating it as a
                 block-opener; adding the "Additional Questions" section
                 further down this file exposed it for real (a clean
                 compile broke the moment that content was added, with no
                 other line touched — isolated and confirmed via a direct
                 Blade-compile diff before landing this fix). Same
                 documented AT-243/AT-252 gotcha this codebase has been
                 bitten by before elsewhere; the block form below is
                 unconditionally safe. NOTE for the next person editing
                 this comment: never type the literal one-liner directive
                 syntax inside a Blade comment either — the extraction
                 regex matches it there too, comment or not, which is
                 exactly how this got found. --}}
            @php
                $canSend = (bool) $rentalApplication->recipientEmail();
            @endphp
            <form method="POST" action="{{ route('corex.rental-applications.send', $rentalApplication) }}" class="inline-flex items-center gap-2">
                @csrf
                <button type="submit" class="text-xs"
                        :class="(!dirty && {{ $canSend ? 'true' : 'false' }}) ? 'corex-btn-primary' : 'corex-btn-outline'"
                        :disabled="dirty || {{ $canSend ? 'false' : 'true' }}"
                        :title="dirty ? 'Save your changes first' : ({{ $canSend ? 'false' : 'true' }} ? 'Add an email address to send' : '')">
                    {{ $rentalApplication->status === 'draft' ? 'Send' : 'Resend' }}
                </button>
                <span class="text-xs hidden sm:inline" style="color: var(--text-muted);"
                      x-show="dirty || {{ $canSend ? 'false' : 'true' }}">
                    <span x-show="dirty">Save your changes first</span>
                    <span x-show="!dirty" x-cloak>Add an email address to send</span>
                </span>
            </form>
            @endpermission

            {{-- 2026-09-12 — Archive moved off rental_applications.create onto
                 its own rental_applications.archive, matching every other
                 module's {module}.archive convention. --}}
            @permission('rental_applications.archive')
            <form method="POST" action="{{ route('corex.rental-applications.destroy', $rentalApplication) }}"
                  onsubmit="return confirm('Archive this rental application? It can be recovered by an admin.');" class="inline">
                @csrf
                @method('DELETE')
                <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626);">Archive</button>
            </form>
            @endpermission
        </x-slot>
    </x-sticky-action-bar>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: var(--ds-emerald-soft, #ecfdf5); color: var(--ds-emerald, #059669);">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: var(--ds-red-soft, #fef2f2); color: var(--ds-red, #dc2626);">{{ session('error') }}</div>
    @endif

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: var(--ds-red-soft, #fef2f2); color: var(--ds-red, #dc2626);">
            Please check the highlighted field{{ $errors->count() > 1 ? 's' : '' }} below — nothing was saved.
        </div>
    @endif

    @if($rentalApplication->token)
    <div class="rounded-md p-4 text-xs space-y-1" style="background: var(--surface); border: 1px solid var(--border);">
        <div><strong>Online link:</strong> <a href="{{ route('rental-applications.public.show', $rentalApplication->token) }}" target="_blank" style="color: var(--brand-icon, #2563eb);">{{ route('rental-applications.public.show', $rentalApplication->token) }}</a></div>
        <div><strong>Download link:</strong> <a href="{{ route('rental-applications.public.pdf', $rentalApplication->token) }}" style="color: var(--brand-icon, #2563eb);">{{ route('rental-applications.public.pdf', $rentalApplication->token) }}</a></div>
        <div style="color: var(--text-muted);">Expires {{ optional($rentalApplication->token_expires_at)->format('d M Y') }}</div>
    </div>
    @endif

    {{--
        Johan, QA1 — "on returned applications theres statuses at the top,
        but theres no way to mark application status to what it is?" Only
        shown once the application has actually been returned — assessing
        something the applicant hasn't submitted yet makes no sense.
        draft/sent/in_progress/returned stay off this control entirely
        (system-recorded facts, see RentalApplication::AGENT_SETTABLE_STATUSES)
        — only the agent's own judgement calls are settable here.
    --}}
    @permission('rental_applications.create')
    {{-- AT-401 — this dropdown's own <option> list is AGENT_SETTABLE_STATUSES
         (+ the disabled 'returned' placeholder), not the broader
         POST_RETURN_STATUSES this gate used to check — same bug class as
         returned.blade.php: approved/declined match no <option>, so the
         browser silently defaulted to showing the first enabled one
         regardless of the real status. In practice this view is only ever
         reached for statuses NOT in AGENT_EDIT_LOCKED_STATUSES (see
         RentalApplicationController::show()), so approved/declined never
         actually landed here — but 'reopened' isn't in POST_RETURN_STATUSES
         either and was already correctly excluded, and closing this
         defensively (rather than leaving a second known-broken copy of the
         same gate) is the point of fixing the class, not the instance. --}}
    {{-- 2026-09-12 REGRESSION FIX — 'withdrawn' removed from this trigger set,
         same reasoning and same fix as index.blade.php's own copy of this
         gate: it used to render a live "Under assessment" option on a
         withdrawn row with no guard at all. Defensive here too, matching
         this comment block's own established "close the gate even where
         it's provably unreachable today" practice. --}}
    @if(in_array($rentalApplication->status, ['returned', 'under_assessment'], true))
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Application Status</h2>
        <form method="POST" action="{{ route('corex.rental-applications.update-status', $rentalApplication) }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Set status to</label>
                <select name="status" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                    <option value="returned" disabled @selected(old('status', $rentalApplication->status) === 'returned')>Returned (awaiting review)</option>
                    {{-- 2026-09-12 — 'withdrawn' removed from this dropdown's own
                         options, same reasoning as index.blade.php's identical
                         fix: recording a withdrawal is now its own explicit,
                         required-note action. See _record-withdrawn.blade.php. --}}
                    @foreach(array_diff(\App\Models\RentalApplication::AGENT_SETTABLE_STATUSES, ['withdrawn']) as $s)
                        <option value="{{ $s }}" @selected(old('status', $rentalApplication->status) === $s)>{{ str_replace('_', ' ', ucfirst($s)) }}</option>
                    @endforeach
                </select>
                @error('status')
                    <p class="text-xs mt-1" style="color: var(--ds-red, #dc2626);">{{ $message }}</p>
                @enderror
            </div>
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Note (optional)</label>
                <input type="text" name="note" value="{{ old('note') }}" maxlength="1000" placeholder="Reason for this decision..." class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                @error('note')
                    <p class="text-xs mt-1" style="color: var(--ds-red, #dc2626);">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Update Status</button>
        </form>
        @include('corex.rental-applications._record-withdrawn', ['application' => $rentalApplication])

        @if($rentalApplication->statusHistory->isNotEmpty())
        <div class="mt-4 pt-3 text-xs space-y-1" style="border-top: 1px solid var(--border); color: var(--text-muted);">
            @foreach($rentalApplication->statusHistory as $entry)
                <div>
                    {{-- 2026-09-13 — the third of three different wordings
                         for this one status found on the same screen
                         (Johan's finding): the pill above already used
                         RentalApplication::WITHDRAWN_LABEL, but this log
                         bypassed it entirely via a raw str_replace. Special-
                         cased to 'withdrawn' only — every other status here
                         keeps its exact existing display, untouched. --}}
                    {{ optional($entry->from_status) ? ($entry->from_status === 'withdrawn' ? \App\Models\RentalApplication::WITHDRAWN_LABEL : str_replace('_', ' ', $entry->from_status)) . ' → ' : '' }}{{ $entry->to_status === 'withdrawn' ? \App\Models\RentalApplication::WITHDRAWN_LABEL : str_replace('_', ' ', $entry->to_status) }}
                    — {{ $entry->changedBy?->name ?? 'System' }}, {{ $entry->created_at->format('d M Y H:i') }}
                    {{-- 2026-09-08 — Johan: a multi-line "request more info"
                         note (numbered points) must preserve line breaks
                         "when it reaches the applicant or agent." The email
                         already did (white-space:pre-wrap); this log — the
                         agent's own later view of what they asked for — did
                         not, and a numbered note collapsed into one run-on
                         line. --}}
                    @if($entry->note) — "<span style="white-space: pre-wrap;">{{ $entry->note }}</span>" @endif
                </div>
            @endforeach
        </div>
        @endif
    </div>
    @endif
    @endpermission

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
            <p id="noDocumentsYet" class="text-xs" style="color: var(--text-muted);" @if($rentalApplication->documents->isNotEmpty()) hidden @endif>None uploaded yet.</p>
            <ul id="supportingDocumentsList" class="text-xs space-y-1" style="color: var(--text-secondary);" @if($rentalApplication->documents->isEmpty()) hidden @endif>
                @foreach($rentalApplication->documents as $doc)
                    <li>✓ <a href="{{ route('corex.rental-applications.documents.download', [$rentalApplication, $doc]) }}" style="color: var(--brand-icon, #2563eb);">{{ $doc->original_name }}</a> @if($doc->documentType) ({{ $doc->documentType->label }}) @endif
                        <span style="color: var(--text-muted);">— {{ $doc->uploaded_by ? 'added by ' . ($doc->uploader->name ?? 'an agent') : 'from applicant' }}</span>
                        @if($rentalApplication->submitted_at && $doc->created_at->greaterThanOrEqualTo($rentalApplication->submitted_at))
                            <span class="ds-badge ds-badge-warning" title="This document was added after the application was submitted">Added after submission</span>
                            <span style="color: var(--text-muted);">{{ $doc->created_at->format('d M Y H:i') }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>

            {{--
                Johan — "agent should in any case be able to add docs as
                client can be in the office so agent scans docs to
                themselves, or even receive via whatsapp etc." Same
                Document model/storage/allowlist/soft-delete rule as the
                applicant's own upload — just a second authenticated entry
                point, not a second path. Async (no reload) so nothing
                elsewhere on this page — the big form's unsaved edits — is
                ever put at risk by attaching a file.
            --}}
            @permission('rental_applications.create')
            <div class="mt-3 pt-3" style="border-top: 1px solid var(--border);" x-data="agentDocumentUpload()">
                <template x-for="u in uploading" :key="u.tempId">
                    <p class="text-xs mb-1" :class="u.error ? 'text-red-600' : ''" style="color: var(--text-muted);" x-text="u.error ? (u.name + ': ' + u.error) : ('Uploading ' + u.name + '…')"></p>
                </template>
                <label class="text-xs font-medium cursor-pointer" style="color: var(--brand-icon, #2563eb);">
                    + Add document
                    <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="hidden"
                           @change="onFilesSelected($event.target.files); $event.target.value = ''">
                </label>
                <p class="text-[11px] mt-1" style="color: var(--text-muted);">Scanned in-office, received by WhatsApp, or anything else — attach it here. This never touches the form above; nothing you've typed is affected.</p>
            </div>
            @endpermission

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

    <form id="rentalApplicationForm" method="POST" action="{{ route('corex.rental-applications.update', $rentalApplication) }}"
          @input="dirty = true" @change="dirty = true"
          class="rounded-md p-6 space-y-6" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf
        @method('PUT')
        {{--
            RA-05 (cc5) — "Tab 2, opened earlier and unaware, saves a
            different field and silently blanks Tab 1's genuine save."
            Seeded from old() first so a validation-failure redisplay in
            THIS same tab doesn't falsely trip the staleness check (the
            record genuinely hasn't changed if the save itself failed).
        --}}
        <input type="hidden" name="expected_updated_at" value="{{ old('expected_updated_at', $rentalApplication->updated_at?->timestamp) }}">

        <div>
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Property</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <x-rental-application-field name="property_address_override" label="Property address (if not linked above)" :value="$rentalApplication->property_address_override" />
            </div>
        </div>

        <div>
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Personal Details</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                @if($fieldConfig['full_name']['shown'])
                <x-rental-application-field name="full_name" :label="$fieldConfig['full_name']['label']" :hint="$fieldConfig['full_name']['help_text']" :order="$fieldConfig['full_name']['order']" :value="$rentalApplication->full_name" />
                @endif
                @if($fieldConfig['id_number']['shown'])
                <x-rental-application-field name="id_number" :label="$fieldConfig['id_number']['label']" :hint="$fieldConfig['id_number']['help_text']" :order="$fieldConfig['id_number']['order']" :value="$rentalApplication->id_number" />
                @endif
                @if($fieldConfig['marital_status']['shown'])
                <x-rental-application-field name="marital_status" :label="$fieldConfig['marital_status']['label']" :hint="$fieldConfig['marital_status']['help_text']" :order="$fieldConfig['marital_status']['order']" :value="$rentalApplication->marital_status" />
                @endif
                @if($fieldConfig['citizenship']['shown'])
                <x-rental-application-field name="citizenship" :label="$fieldConfig['citizenship']['label']" :hint="$fieldConfig['citizenship']['help_text']" :order="$fieldConfig['citizenship']['order']" :value="$rentalApplication->citizenship" />
                @endif
                @if($fieldConfig['spouse_name']['shown'])
                <x-rental-application-field name="spouse_name" :label="$fieldConfig['spouse_name']['label']" :hint="$fieldConfig['spouse_name']['help_text']" :order="$fieldConfig['spouse_name']['order']" :value="$rentalApplication->spouse_name" />
                @endif
                @if($fieldConfig['spouse_id']['shown'])
                <x-rental-application-field name="spouse_id" :label="$fieldConfig['spouse_id']['label']" :hint="$fieldConfig['spouse_id']['help_text']" :order="$fieldConfig['spouse_id']['order']" :value="$rentalApplication->spouse_id" />
                @endif
                @if($fieldConfig['email']['shown'])
                <x-rental-application-field name="email" :label="$fieldConfig['email']['label']" :hint="$fieldConfig['email']['help_text']" :order="$fieldConfig['email']['order']" type="email" :value="$rentalApplication->email" />
                @endif
                @if($fieldConfig['cell']['shown'])
                <x-rental-application-field name="cell" :label="$fieldConfig['cell']['label']" :hint="$fieldConfig['cell']['help_text']" :order="$fieldConfig['cell']['order']" :value="$rentalApplication->cell" />
                @endif
                @if($fieldConfig['work_number']['shown'])
                <x-rental-application-field name="work_number" :label="$fieldConfig['work_number']['label']" :hint="$fieldConfig['work_number']['help_text']" :order="$fieldConfig['work_number']['order']" :value="$rentalApplication->work_number" />
                @endif
            </div>
            @if($fieldConfig['current_residential_address']['shown'])
            <div class="mt-3">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">{{ $fieldConfig['current_residential_address']['label'] }}</label>
                <textarea name="current_residential_address" rows="2" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">{{ old('current_residential_address', $rentalApplication->current_residential_address) }}</textarea>
                @if($fieldConfig['current_residential_address']['help_text'])
                    <p class="text-xs mt-1" style="color: var(--text-secondary);">{{ $fieldConfig['current_residential_address']['help_text'] }}</p>
                @endif
                @error('current_residential_address')
                    <p class="text-xs mt-1" style="color: var(--ds-red, #dc2626);">{{ $message }}</p>
                @enderror
            </div>
            @endif
        </div>

        <div>
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Emergency Contact (not staying with you)</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                @if($fieldConfig['emergency_contact_name']['shown'])
                <x-rental-application-field name="emergency_contact_name" :label="$fieldConfig['emergency_contact_name']['label']" :hint="$fieldConfig['emergency_contact_name']['help_text']" :order="$fieldConfig['emergency_contact_name']['order']" :value="$rentalApplication->emergency_contact_name" />
                @endif
                @if($fieldConfig['emergency_contact_cell']['shown'])
                <x-rental-application-field name="emergency_contact_cell" :label="$fieldConfig['emergency_contact_cell']['label']" :hint="$fieldConfig['emergency_contact_cell']['help_text']" :order="$fieldConfig['emergency_contact_cell']['order']" :value="$rentalApplication->emergency_contact_cell" />
                @endif
                @if($fieldConfig['emergency_contact_work']['shown'])
                <x-rental-application-field name="emergency_contact_work" :label="$fieldConfig['emergency_contact_work']['label']" :hint="$fieldConfig['emergency_contact_work']['help_text']" :order="$fieldConfig['emergency_contact_work']['order']" :value="$rentalApplication->emergency_contact_work" />
                @endif
            </div>
        </div>

        <div x-data="{ situation: {{ \Illuminate\Support\Js::from(old('current_living_situation', $situationDefault)) }} }">
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Current Living Situation</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                @if($fieldConfig['current_living_situation']['shown'])
                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">{{ $fieldConfig['current_living_situation']['label'] }}</label>
                    <select name="current_living_situation" x-model="situation"
                            class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                        <option value="">— Select —</option>
                        @foreach(\App\Models\RentalApplication::CURRENT_LIVING_SITUATION_LABELS as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @if($fieldConfig['current_living_situation']['help_text'])
                        <p class="text-xs mt-1" style="color: var(--text-secondary);">{{ $fieldConfig['current_living_situation']['help_text'] }}</p>
                    @endif
                    @error('current_living_situation')
                        <p class="text-xs mt-1" style="color: var(--ds-red, #dc2626);">{{ $message }}</p>
                    @enderror
                </div>
                @endif
            </div>

            <div x-show="situation === 'renting'" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                @if($fieldConfig['current_landlord_name']['shown'])
                <x-rental-application-field name="current_landlord_name" :label="$fieldConfig['current_landlord_name']['label']" :hint="$fieldConfig['current_landlord_name']['help_text']" :order="$fieldConfig['current_landlord_name']['order']" :value="$rentalApplication->current_landlord_name" />
                @endif
                @if($fieldConfig['current_landlord_tel']['shown'])
                <x-rental-application-field name="current_landlord_tel" :label="$fieldConfig['current_landlord_tel']['label']" :hint="$fieldConfig['current_landlord_tel']['help_text']" :order="$fieldConfig['current_landlord_tel']['order']" :value="$rentalApplication->current_landlord_tel" />
                @endif
                @if($fieldConfig['current_rental_amount']['shown'])
                <x-rental-application-field name="current_rental_amount" :label="$fieldConfig['current_rental_amount']['label']" :hint="$fieldConfig['current_rental_amount']['help_text']" :order="$fieldConfig['current_rental_amount']['order']" type="text" inputmode="decimal" :value="$rentalApplication->current_rental_amount" />
                @endif
                @if($fieldConfig['current_rental_due_day']['shown'])
                <x-rental-application-field name="current_rental_due_day" :label="$fieldConfig['current_rental_due_day']['label']" type="number" min="1" max="31" :value="$rentalApplication->current_rental_due_day" :order="$fieldConfig['current_rental_due_day']['order']"
                    :hint="$fieldConfig['current_rental_due_day']['help_text'] ?? 'e.g. 1 if their rent is due on the 1st of every month.'" />
                @endif
                @if($fieldConfig['current_rental_from']['shown'])
                <x-rental-application-field name="current_rental_from" :label="$fieldConfig['current_rental_from']['label']" :hint="$fieldConfig['current_rental_from']['help_text']" :order="$fieldConfig['current_rental_from']['order']" type="date" :value="optional($rentalApplication->current_rental_from)->format('Y-m-d')" />
                @endif
                <div x-data="{ stillLiving: {{ old('current_rental_still_living', $rentalApplication->current_rental_still_living) ? 'true' : 'false' }} }">
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">To</label>
                    <input type="date" name="current_rental_to" x-ref="currentRentalTo"
                           :disabled="stillLiving"
                           value="{{ old('current_rental_to', optional($rentalApplication->current_rental_to)->format('Y-m-d')) }}"
                           class="w-full rounded-md px-3 py-2 text-sm disabled:opacity-50" style="border: 1px solid var(--border);">
                    @error('current_rental_to')
                        <p class="text-xs mt-1" style="color: var(--ds-red, #dc2626);">{{ $message }}</p>
                    @enderror
                    <label class="flex items-center gap-2 mt-2 text-xs cursor-pointer" style="color: var(--text-secondary);">
                        <input type="hidden" name="current_rental_still_living" value="0">
                        <input type="checkbox" name="current_rental_still_living" value="1" x-model="stillLiving"
                               @change="if (stillLiving) $refs.currentRentalTo.value = ''">
                        Still living here — no end date
                    </label>
                </div>
            </div>

            @if($fieldConfig['current_living_situation_notes']['shown'])
            <div class="mt-3">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">{{ $fieldConfig['current_living_situation_notes']['help_text'] ?? "Living situation — applicant's own words (optional)" }}</label>
                <textarea name="current_living_situation_notes" rows="4"
                          class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">{{ old('current_living_situation_notes', $rentalApplication->current_living_situation_notes) }}</textarea>
                @error('current_living_situation_notes')
                    <p class="text-xs mt-1" style="color: var(--ds-red, #dc2626);">{{ $message }}</p>
                @enderror
            </div>
            @endif
        </div>

        <div>
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Employment</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                @if($fieldConfig['employment_type']['shown'])
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">{{ $fieldConfig['employment_type']['label'] }}</label>
                    <select name="employment_type" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                        <option value="">— Select —</option>
                        @foreach(\App\Models\RentalApplication::EMPLOYMENT_TYPES as $type)
                            <option value="{{ $type }}" @selected(old('employment_type', $rentalApplication->employment_type) === $type)>{{ str_replace('_', ' ', ucfirst($type)) }}</option>
                        @endforeach
                    </select>
                    @if($fieldConfig['employment_type']['help_text'])
                        <p class="text-xs mt-1" style="color: var(--text-secondary);">{{ $fieldConfig['employment_type']['help_text'] }}</p>
                    @endif
                    @error('employment_type')
                        <p class="text-xs mt-1" style="color: var(--ds-red, #dc2626);">{{ $message }}</p>
                    @enderror
                </div>
                @endif
                @if($fieldConfig['employer_name']['shown'])
                <x-rental-application-field name="employer_name" :label="$fieldConfig['employer_name']['label']" :hint="$fieldConfig['employer_name']['help_text']" :order="$fieldConfig['employer_name']['order']" :value="$rentalApplication->employer_name" />
                @endif
                @if($fieldConfig['employer_position']['shown'])
                <x-rental-application-field name="employer_position" :label="$fieldConfig['employer_position']['label']" :hint="$fieldConfig['employer_position']['help_text']" :order="$fieldConfig['employer_position']['order']" :value="$rentalApplication->employer_position" />
                @endif
                @if($fieldConfig['employer_tel']['shown'])
                <x-rental-application-field name="employer_tel" :label="$fieldConfig['employer_tel']['label']" :hint="$fieldConfig['employer_tel']['help_text']" :order="$fieldConfig['employer_tel']['order']" :value="$rentalApplication->employer_tel" />
                @endif
                @if($fieldConfig['monthly_salary']['shown'])
                <x-rental-application-field name="monthly_salary" :label="$fieldConfig['monthly_salary']['label']" type="text" inputmode="decimal" :value="$rentalApplication->monthly_salary" :order="$fieldConfig['monthly_salary']['order']"
                    :hint="$fieldConfig['monthly_salary']['help_text'] ?? 'The amount on the applicant\'s payslip BEFORE tax and other deductions — not their take-home pay.'" />
                @endif
            </div>
            @if($fieldConfig['employer_address']['shown'])
            <div class="mt-3">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">{{ $fieldConfig['employer_address']['label'] }}</label>
                <textarea name="employer_address" rows="2" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">{{ old('employer_address', $rentalApplication->employer_address) }}</textarea>
                @if($fieldConfig['employer_address']['help_text'])
                    <p class="text-xs mt-1" style="color: var(--text-secondary);">{{ $fieldConfig['employer_address']['help_text'] }}</p>
                @endif
                @error('employer_address')
                    <p class="text-xs mt-1" style="color: var(--ds-red, #dc2626);">{{ $message }}</p>
                @enderror
            </div>
            @endif
        </div>

        <div>
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Requirement of Lease</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                @if($fieldConfig['occupation_date']['shown'])
                <x-rental-application-field name="occupation_date" :label="$fieldConfig['occupation_date']['label']" :hint="$fieldConfig['occupation_date']['help_text']" :order="$fieldConfig['occupation_date']['order']" type="date" :value="optional($rentalApplication->occupation_date)->format('Y-m-d')" />
                @endif
                @if($fieldConfig['rental_term_months']['shown'])
                <div x-data="{ months: {{ old('rental_term_months', $rentalApplication->rental_term_months) ?: 'null' }} }" class="sm:col-span-2" style="order: {{ $fieldConfig['rental_term_months']['order'] }}">
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">{{ $fieldConfig['rental_term_months']['label'] }}</label>
                    <input type="hidden" name="rental_term_months" :value="months">
                    <div class="flex gap-2">
                        <template x-for="m in [6, 12, 24]" :key="m">
                            <button type="button" @click="months = m"
                                    :class="months === m ? 'bg-slate-800 text-white' : 'bg-white'"
                                    class="px-4 py-2 rounded-md text-sm" style="border: 1px solid var(--border);">
                                <span x-text="m"></span> months
                            </button>
                        </template>
                    </div>
                    @error('rental_term_months')
                        <p class="text-xs mt-1" style="color: var(--ds-red, #dc2626);">{{ $message }}</p>
                    @enderror
                    @if($rentalApplication->rental_terms && ! $rentalApplication->rental_term_months)
                        <p class="text-xs mt-1" style="color: var(--text-secondary);">Previously recorded as free text: "{{ $rentalApplication->rental_terms }}" — pick one of the options above to replace it.</p>
                    @endif
                    <p class="text-xs mt-1" style="color: var(--text-secondary);">{{ $fieldConfig['rental_term_months']['help_text'] ?? 'Maximum 24 months by law — a longer stay is arranged as a renewal later, not on this form.' }}</p>
                </div>
                @endif
                @if($fieldConfig['adults']['shown'])
                <x-rental-application-field name="adults" :label="$fieldConfig['adults']['label']" :hint="$fieldConfig['adults']['help_text']" :order="$fieldConfig['adults']['order']" type="number" :value="$rentalApplication->adults" />
                @endif
                @if($fieldConfig['children']['shown'])
                <x-rental-application-field name="children" :label="$fieldConfig['children']['label']" :hint="$fieldConfig['children']['help_text']" :order="$fieldConfig['children']['order']" type="number" :value="$rentalApplication->children" />
                @endif
            </div>
            @if($fieldConfig['special_conditions']['shown'])
            <div class="mt-3">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">{{ $fieldConfig['special_conditions']['label'] }}</label>
                <textarea name="special_conditions" rows="2" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">{{ old('special_conditions', $rentalApplication->special_conditions) }}</textarea>
                @if($fieldConfig['special_conditions']['help_text'])
                    <p class="text-xs mt-1" style="color: var(--text-secondary);">{{ $fieldConfig['special_conditions']['help_text'] }}</p>
                @endif
                @error('special_conditions')
                    <p class="text-xs mt-1" style="color: var(--ds-red, #dc2626);">{{ $message }}</p>
                @enderror
            </div>
            @endif
        </div>

        @php
            // .ai/specs/rental-application-field-config.md §7, piece
            // (c)(3) — same $fieldConfig this whole form already resolves
            // through (RentalApplication::displayFieldConfig()), never a
            // parallel field-listing mechanism. Not rendered via
            // <x-rental-application-field> — see the public form's own
            // identical comment on why a bracketed HTML name
            // (custom_field_values[key]) breaks that component's flat-name
            // old() call.
            $customFields = collect($fieldConfig)->where('is_custom', true)->sortBy('order');
        @endphp
        @if($customFields->isNotEmpty())
        <div>
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Additional Questions</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                @foreach($customFields as $cf)
                    @php
                        $cfName = 'custom_field_values[' . $cf['key'] . ']';
                        $cfOldKey = 'custom_field_values.' . $cf['key'];
                        $cfValue = old($cfOldKey, $rentalApplication->custom_field_values[$cf['key']] ?? null);
                        $cfError = $errors->has($cfOldKey);
                        $cfDoc = $cf['field_type'] === 'file' && $cfValue
                            ? \App\Models\Document::where('id', $cfValue)
                                ->where('source_type', 'rental_application')
                                ->where('source_id', $rentalApplication->id)
                                ->where('custom_field_key', $cf['key'])
                                ->first()
                            : null;
                    @endphp
                    <div class="{{ in_array($cf['field_type'], ['text', 'file']) ? 'sm:col-span-2' : '' }}">
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">{{ $cf['label'] }}</label>
                        @if($cf['field_type'] === 'file')
                            {{-- Upload only, matching this whole screen's existing
                                 document ceiling (no replace/remove for ANY
                                 document here — see uploadCustomFieldDocument()'s
                                 own docblock). Plain DOM state update on success,
                                 never a page reload — this screen's own "typed
                                 info disappeared on upload" bug (see the header
                                 comment above, `dirty` tracking) is exactly what
                                 a reload here would reintroduce. --}}
                            <div x-data="{
                                    uploading: false,
                                    error: null,
                                    doc: {{ Js::from($cfDoc ? ['id' => $cfDoc->id, 'name' => $cfDoc->original_name, 'view_url' => route('corex.rental-applications.documents.download', [$rentalApplication, $cfDoc])] : null) }},
                                }">
                                <template x-if="doc">
                                    <a :href="doc.view_url" class="text-sm" style="color: var(--brand-icon, #2563eb);" target="_blank" rel="noopener" x-text="'✓ ' + doc.name"></a>
                                </template>
                                <template x-if="!doc">
                                    <div>
                                        <label class="inline-block px-3 py-2 rounded-md text-sm cursor-pointer" style="border: 1px solid var(--border);" :class="uploading ? 'opacity-50' : ''">
                                            <span x-text="uploading ? 'Uploading…' : 'Choose file'"></span>
                                            <input type="file" class="hidden" :disabled="uploading" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                                   @change="
                                                        uploading = true; error = null;
                                                        const formData = new FormData();
                                                        formData.append('file', $event.target.files[0]);
                                                        formData.append('_token', document.querySelector('meta[name=csrf-token]').content);
                                                        fetch({{ Js::from(route('corex.rental-applications.custom-fields.upload', [$rentalApplication, $cf['key']])) }}, {
                                                            method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: formData,
                                                        }).then(res => res.json().then(data => ({ ok: res.ok, data }))).then(({ ok, data }) => {
                                                            uploading = false;
                                                            if (!ok) { error = data.message || 'Upload failed.'; return; }
                                                            doc = data.document;
                                                        }).catch(() => { uploading = false; error = 'Network error — please try again.'; });
                                                        $event.target.value = '';
                                                   ">
                                        </label>
                                        <p class="text-xs mt-1" x-show="error" x-text="error" style="color: var(--ds-red, #dc2626);"></p>
                                    </div>
                                </template>
                            </div>
                        @elseif($cf['field_type'] === 'yes_no')
                            <select name="{{ $cfName }}" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid {{ $cfError ? 'var(--ds-red, #dc2626)' : 'var(--border)' }};">
                                <option value="">— Select —</option>
                                <option value="1" @selected($cfValue == '1')>Yes</option>
                                <option value="0" @selected($cfValue !== null && $cfValue == '0')>No</option>
                            </select>
                        @elseif($cf['field_type'] === 'choice_list')
                            <select name="{{ $cfName }}" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid {{ $cfError ? 'var(--ds-red, #dc2626)' : 'var(--border)' }};">
                                <option value="">— Select —</option>
                                @foreach($cf['options'] ?? [] as $option)
                                    <option value="{{ $option }}" @selected($cfValue === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                        @elseif($cf['field_type'] === 'date')
                            <input type="date" name="{{ $cfName }}" value="{{ $cfValue }}"
                                   class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid {{ $cfError ? 'var(--ds-red, #dc2626)' : 'var(--border)' }};">
                        @elseif($cf['field_type'] === 'number')
                            <input type="text" inputmode="decimal" name="{{ $cfName }}" value="{{ $cfValue }}"
                                   class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid {{ $cfError ? 'var(--ds-red, #dc2626)' : 'var(--border)' }};">
                        @else
                            <input type="text" name="{{ $cfName }}" value="{{ $cfValue }}"
                                   class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid {{ $cfError ? 'var(--ds-red, #dc2626)' : 'var(--border)' }};">
                        @endif
                        @if($cf['help_text'])
                            <p class="text-xs mt-1" style="color: var(--text-secondary);">{{ $cf['help_text'] }}</p>
                        @endif
                        @error($cfOldKey) <p class="text-xs mt-1" style="color: var(--ds-red, #dc2626);">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
        </div>
        @endif

    </form>
</div>

<script>
function agentDocumentUpload() {
    return {
        uploading: [],

        csrfToken() {
            return document.querySelector('meta[name="csrf-token"]').content;
        },

        async uploadFile(file) {
            const tempId = 'u' + Date.now() + Math.random();
            this.uploading.push({ tempId, name: file.name, error: null });
            const formData = new FormData();
            formData.append('supporting_files[]', file);
            try {
                const res = await fetch('{{ route('corex.rental-applications.documents.upload', $rentalApplication) }}', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken() },
                    body: formData,
                });
                const data = await res.json().catch(() => ({}));
                const item = this.uploading.find(u => u.tempId === tempId);
                if (!res.ok) {
                    item.error = (data.errors && Object.values(data.errors)[0]?.[0]) || data.message || 'Upload failed.';
                    return;
                }
                (data.documents || []).forEach(doc => this.appendToList(doc));
                this.uploading = this.uploading.filter(u => u.tempId !== tempId);
            } catch (e) {
                const item = this.uploading.find(u => u.tempId === tempId);
                if (item) item.error = 'Network error — please try again.';
            }
        },

        async onFilesSelected(fileList) {
            await Promise.all(Array.from(fileList).map(file => this.uploadFile(file)));
        },

        // Plain DOM insertion, deliberately not Alpine x-for state — the
        // big form below has its own unsaved-edit tracking (`dirty`) that
        // this must never interact with or risk reloading past.
        appendToList(doc) {
            document.getElementById('noDocumentsYet').hidden = true;
            const list = document.getElementById('supportingDocumentsList');
            list.hidden = false;
            const li = document.createElement('li');
            const a = document.createElement('a');
            a.href = doc.view_url;
            a.style.color = 'var(--brand-icon, #2563eb)';
            a.textContent = doc.name;
            const span = document.createElement('span');
            span.style.color = 'var(--text-muted)';
            span.textContent = ' — added by ' + @js(auth()->user()->name ?? 'an agent');
            li.append('✓ ', a, span);
            // RA-03 (cc5) — anything added right now, through this widget,
            // on an already-submitted application is by definition "after
            // submission" (submitted_at is necessarily in the past).
            @if($rentalApplication->submitted_at)
                const badge = document.createElement('span');
                badge.className = 'ds-badge ds-badge-warning';
                badge.title = 'This document was added after the application was submitted';
                badge.textContent = 'Added after submission';
                const when = document.createElement('span');
                when.style.color = 'var(--text-muted)';
                when.textContent = new Date().toLocaleString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
                li.append(' ', badge, ' ', when);
            @endif
            list.appendChild(li);
        },
    };
}
</script>
@endsection
