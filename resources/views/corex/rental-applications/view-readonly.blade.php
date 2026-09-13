{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@php
    $checklist = $rentalApplication->employment_type
        ? \App\Models\RentalApplicationDocumentRequirement::checklistFor($rentalApplication->agency_id, $rentalApplication->employment_type)
        : collect();
    $onFileTypeIds = $rentalApplication->documents->pluck('document_type_id')->filter()->all();

    // Johan — "on approval then we have a way for the agent to link the
    // application to a property... when an approved tenant is linked the
    // property changes to let out status." See
    // RentalApplicationController::linkTenantProperty()'s own docblock for
    // the full reasoning. Read here from the SAME contact_property pivot
    // ContactPropertyController already writes for owner/buyer/lessor —
    // never a second place to ask "is this contact this property's tenant".
    $tenantLinkedProperty = null;
    if ($rentalApplication->status === 'approved' && $rentalApplication->contact && $rentalApplication->property_id) {
        $tenantLinkedProperty = $rentalApplication->contact->properties()
            ->wherePivot('role', 'tenant')
            ->where('properties.id', $rentalApplication->property_id)
            ->first();
    }
@endphp

@section('corex-content')
<div class="w-full space-y-5">

    {{--
        AT-392, Johan (asked three times): "opening a rental application
        should not be able to edit... open / view should show the
        application the applicant sent in. nothing more. no edits,
        nothing." This screen replaces the editable field form with the
        SAME generated PDF the module already produces for download —
        embedded read-only below, never a second rendering of the data.
    --}}
    <x-sticky-action-bar>
        <x-slot name="left">
            <div class="min-w-0">
                <h1 class="text-sm font-bold leading-tight truncate" style="color: var(--text-primary);">
                    {{ $rentalApplication->contact->full_name ?? 'Rental Application' }}
                </h1>
                <span class="ds-badge ds-badge-info">
                    {{ \App\Models\RentalApplication::displayStatusLabel($rentalApplication->status) }} — read-only, as submitted and signed
                </span>
                {{-- cc4 walk, finding 5, 2026-09-13 — the approved amount was
                     saved but never surfaced anywhere an agent's eye actually
                     goes: this sticky header is the one thing on the page
                     visible unconditionally (the Application Status card
                     below is permission-gated), so this is where "what did
                     we approve them for" gets answered without expanding
                     anything. Same "R{amount} a month" wording review.blade.php
                     already uses for the authoriser/agent decision panel. --}}
                @if($rentalApplication->status === 'approved' && $rentalApplication->approved_rental_amount !== null)
                    <span class="ds-badge ds-badge-success">Approved for R{{ number_format((float) $rentalApplication->approved_rental_amount, 2) }} a month</span>
                @endif
            </div>
        </x-slot>
        <x-slot name="right">
            <a href="{{ route('corex.rental-applications.index') }}" class="corex-btn-outline text-xs">&larr; Back to list</a>
            <a href="{{ route('corex.rental-applications.pdf', $rentalApplication) }}" class="corex-btn-outline text-xs">Download PDF</a>
            {{-- REGRESSION FIX (2026-09-11, broadened 2026-09-12) —
                 declined/withdrawn's own explicit door back, since Review is
                 deliberately never offered for either. See
                 _reopen-terminal.blade.php. --}}
            @include('corex.rental-applications._reopen-terminal', ['application' => $rentalApplication])

            {{-- 2026-09-12 — moved off rental_applications.create onto its own
                 rental_applications.archive, matching every other module. --}}
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

    <div class="rounded-md p-3 text-xs" style="background: var(--surface-2, #f9fafb); border: 1px solid var(--border); color: var(--text-secondary);">
        This is a faithful, read-only rendering of the application the applicant submitted and signed. It cannot be
        edited from here or by any other route on this record — that is deliberate: a signed application a third
        party could alter afterwards would not stand as evidence of anything.
    </div>

    {{--
        Johan, QA1 — "on returned applications theres statuses at the top,
        but theres no way to mark application status to what it is?" This
        is the agent's own subsequent workflow action (assess / withdraw),
        not an edit of the applicant's answers — it stays exactly as it
        was on the editable screen.
    --}}
    @permission('rental_applications.create')
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Application Status</h2>
        {{-- AT-401 — this view has NO status gate at all: every status that
             reaches it (returned, under_assessment, approved, declined,
             withdrawn, reopened — see AGENT_EDIT_LOCKED_STATUSES) rendered
             this SAME editable dropdown, whose own <option> list is only
             AGENT_SETTABLE_STATUSES (under_assessment/withdrawn) plus the
             disabled 'returned' placeholder. approved, declined, and
             reopened all matched no <option>, so the browser silently
             defaulted to showing the first enabled one — the worse version
             of the same bug fixed on returned.blade.php and show.blade.php,
             since here it had no gate to narrow, only to add. Agent-settable
             statuses keep the real control; anything else gets a plain,
             correctly-labelled status line instead — the page's own sticky
             header badge above already shows the true status regardless,
             this just stops the card underneath it contradicting it.

             2026-09-12 REGRESSION FIX — 'withdrawn' removed from the trigger
             set entirely (it was included via AGENT_SETTABLE_STATUSES, same
             as returned/under_assessment). THIS was the actually-reachable
             copy of the bug: a real, live "Under assessment" option on a
             withdrawn application's own detail screen, one click, no note,
             no confirmation, reversing a documented one-way decision. A
             withdrawn row now falls through to the plain status line below;
             the Reopen button in the sticky header above (see
             _reopen-terminal.blade.php) is the one legitimate door back,
             override-tier only, required note, audited — and
             RentalApplicationController::updateStatus() refuses this
             transition server-side regardless of what this template ever
             renders. --}}
        @if(in_array($rentalApplication->status, ['returned', 'under_assessment'], true))
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
        @else
            <span class="ds-badge ds-badge-info">{{ \App\Models\RentalApplication::displayStatusLabel($rentalApplication->status) }}</span>
        @endif

        @if($rentalApplication->status === 'approved')
        {{--
            Johan — "on approval then we have a way for the agent to link
            the application to a property... when an approved tenant is
            linked the property changes to let out status." Deliberate
            press, not automatic on approval (Johan's standing rule for
            consequential things). Defaults the picker to whatever property
            this application already carries (property_id, set via the
            review screen's own link-property control) — the agent is never
            forced to search for a property the system already knows about,
            but can choose a different one. See RentalApplicationController::
            linkTenantProperty()/unlinkTenantProperty() for the reasoning on
            why this reuses contact_property + the DR2 under-offer pattern
            rather than building either again.

            The "changes to let out status" half of Johan's original ask is
            NOT built here — the conductor split it out, 2026-09-13, after
            an investigation found it risked a live Property24/Private
            Property listing silently vanishing (see .ai/specs/
            rental-applications.md, "Property status side effects — DO NOT
            flip on tenant link"). This control only ever writes the
            contact_property link.
        --}}
        <div class="mt-3 pt-3" style="border-top: 1px solid var(--border);"
             x-data="{
                searching: false,
                query: '',
                results: [],
                propertyId: {{ Js::from($rentalApplication->property_id) }},
                propertyLabel: {{ Js::from($rentalApplication->property ? ($rentalApplication->property->title ?: $rentalApplication->property->buildDisplayAddress()) : null) }},
                async search() {
                    if (this.query.length < 2) { this.results = []; return; }
                    const res = await fetch({{ Js::from(route('corex.rental-applications.search-properties')) }} + '?q=' + encodeURIComponent(this.query));
                    this.results = await res.json();
                },
                select(p) {
                    this.propertyId = p.id;
                    this.propertyLabel = p.label;
                    this.searching = false;
                    this.query = '';
                    this.results = [];
                },
             }">
            @if($tenantLinkedProperty)
                <p class="text-xs font-medium mb-1" style="color: var(--text-secondary);">Tenant linked to property</p>
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="ds-badge ds-badge-success">{{ $tenantLinkedProperty->buildDisplayAddress() }} — tenant linked</span>
                    <form method="POST" action="{{ route('corex.rental-applications.unlink-tenant-property', $rentalApplication) }}"
                          onsubmit="return confirm('Remove this tenant link from the property?');" class="inline">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-xs underline" style="color: var(--ds-red, #dc2626);">Unlink</button>
                    </form>
                </div>
            @else
                <p class="text-xs font-medium mb-1" style="color: var(--text-secondary);">Link this tenant to a property</p>
                <form method="POST" action="{{ route('corex.rental-applications.link-tenant-property', $rentalApplication) }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    <input type="hidden" name="property_id" :value="propertyId">
                    <template x-if="!searching">
                        <span class="inline-flex items-center gap-2">
                            <span class="text-xs" x-show="propertyLabel" x-text="propertyLabel"></span>
                            <span class="text-xs" style="color: var(--text-muted);" x-show="!propertyLabel">No property chosen.</span>
                            <button type="button" class="corex-btn-outline text-xs" @click="searching = true">
                                <span x-text="propertyLabel ? 'Choose a different property' : 'Choose a property'"></span>
                            </button>
                        </span>
                    </template>
                    <template x-if="searching">
                        <div class="relative" style="max-width: 22rem;">
                            <input type="text" x-model="query" @input.debounce.300ms="search()" @keydown.escape="searching = false"
                                   placeholder="Search rental properties…" autofocus
                                   class="w-full rounded-md px-2 py-1 text-xs" style="border: 1px solid var(--border);">
                            <button type="button" class="text-xs underline ml-1" style="color: var(--text-muted);" @click="searching = false">Cancel</button>
                            <div class="absolute z-10 mt-1 w-full rounded-md" style="background: var(--surface); border: 1px solid var(--border);" x-show="results.length">
                                <template x-for="p in results" :key="p.id">
                                    <button type="button" @click="select(p)" class="block w-full text-left px-2 py-1 text-xs hover:bg-slate-50" x-text="p.label"></button>
                                </template>
                            </div>
                        </div>
                    </template>
                    <button type="submit" class="corex-btn-primary text-xs" :disabled="!propertyId">Link as tenant</button>
                </form>
                <p class="text-[11px] mt-1" style="color: var(--text-muted);">Sets {{ $rentalApplication->contact->full_name ?? 'this applicant' }} as the tenant on this property.</p>
            @endif
        </div>
        @endif

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
                    @if($entry->note) — "<span style="white-space: pre-wrap;">{{ $entry->note }}</span>" @endif
                </div>
            @endforeach
        </div>
        @endif
    </div>
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
            <p class="text-xs" style="color: var(--text-muted);" @if($rentalApplication->documents->isNotEmpty()) hidden @endif>None uploaded yet.</p>
            <ul class="text-xs space-y-1" style="color: var(--text-secondary);" @if($rentalApplication->documents->isEmpty()) hidden @endif>
                @foreach($rentalApplication->documents as $doc)
                    <li>✓ <a href="{{ route('corex.rental-applications.documents.download', [$rentalApplication, $doc]) }}" style="color: var(--brand-icon, #2563eb);">{{ $doc->original_name }}</a> @if($doc->documentType) ({{ $doc->documentType->label }}) @endif
                        <span style="color: var(--text-muted);">— {{ $doc->uploaded_by ? 'added by ' . ($doc->uploader->name ?? 'an agent') : 'from applicant' }}</span>
                        @if($rentalApplication->submitted_at && $doc->created_at->greaterThanOrEqualTo($rentalApplication->submitted_at) && $doc->uploaded_by)
                            <span class="ds-badge ds-badge-warning" title="This document was added after the application was submitted">Added after submission</span>
                            <span style="color: var(--text-muted);">{{ $doc->created_at->format('d M Y H:i') }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>

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

    {{--
        Johan, driving this himself (2026-09-08): "the embedded PDF took
        about five seconds to appear, showing an empty dark viewer panel
        the whole time with no indication anything was loading. I
        initially recorded it as broken." The PDF is generated server-side
        (RentalApplicationPdfService, Puppeteer) on each request, not
        cached — a real few-second wait is expected, so the fix is telling
        the agent that, not making it faster. x-show hides on the
        iframe's own @load event; no fixed timer, so it never lies about
        whether the PDF has actually arrived.
    --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ pdfLoaded: false }">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Submitted Application</h2>
        <div class="relative" style="height: 85vh;">
            <div x-show="!pdfLoaded" x-transition.opacity
                 class="absolute inset-0 flex flex-col items-center justify-center gap-3 text-sm rounded-md"
                 style="background: var(--surface-2, #f9fafb); border: 1px solid var(--border); color: var(--text-secondary);">
                <svg class="animate-spin" style="width: 1.75rem; height: 1.75rem; color: var(--brand-icon, #2563eb);" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" stroke-opacity="0.25"></circle>
                    <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path>
                </svg>
                <span>Loading the signed application&hellip;</span>
            </div>
            <iframe src="{{ route('corex.rental-applications.pdf-inline', $rentalApplication) }}"
                    title="Rental application as submitted and signed"
                    @load="pdfLoaded = true"
                    x-show="pdfLoaded"
                    style="width: 100%; height: 100%; border: 1px solid var(--border); border-radius: 6px; background: #fff;">
                <p class="text-xs" style="color: var(--text-muted);">
                    Your browser can't display the PDF inline.
                    <a href="{{ route('corex.rental-applications.pdf', $rentalApplication) }}" style="color: var(--brand-icon, #2563eb);">Download it instead</a>.
                </p>
            </iframe>
        </div>
    </div>
</div>
@endsection
