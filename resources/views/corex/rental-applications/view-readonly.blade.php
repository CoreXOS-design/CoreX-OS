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
    $applicationLease = null;
    if ($rentalApplication->status === 'approved' && $rentalApplication->contact && $rentalApplication->property_id) {
        $tenantLinkedProperty = $rentalApplication->contact->properties()
            ->wherePivot('role', 'tenant')
            ->where('properties.id', $rentalApplication->property_id)
            ->first();
        // .ai/specs/leases.md sec1.3 — the lease linkTenantProperty() now
        // creates in the same action, surfaced here so the agent sees the
        // terms without leaving this screen.
        $applicationLease = \App\Models\Lease::where('rental_application_id', $rentalApplication->id)->first();
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
                    {{ $rentalApplication->displayStatusLabel() }} — read-only, as submitted and signed
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
    @if(session('warning'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 14%, transparent); color: var(--ds-amber, #b45309);">{{ session('warning') }}</div>
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
            <span class="ds-badge ds-badge-info">{{ $rentalApplication->displayStatusLabel() }}</span>
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
                {{-- Johan, QA1 walk, 2026-09-21 — address first, never the
                     listing's marketing title. Same fix as the search
                     results below (Property::toSearchResult()'s own
                     buildDisplayAddress()-first label), applied here too so
                     the ALREADY-linked property reads identically to one
                     just picked from search. --}}
                propertyLabel: {{ Js::from($rentalApplication->property?->buildDisplayAddress()) }},
                unlinkConfirming: false,
                {{-- Johan, 2026-09-22 (property 4283 / rental application
                     290) — PRECEDENCE RULING: the approved application
                     amount wins; the property is the fallback when the
                     application carries no approved amount. "the approved
                     figure is what the landlord actually agreed to for this
                     tenant, and the property listing price is often stale
                     or negotiated down." Same rule for both rent and
                     deposit. Kept as their own values (not read live off
                     $rentalApplication again) so select() below can compare
                     against them without a second data source appearing
                     mid-form. rentalAmountSource/depositAmountSource drive
                     the short "from ..." label next to each field — cleared
                     the moment the agent actually types in that field
                     (@input below), since a value they typed themselves
                     isn't "from" anywhere any more. --}}
                approvedRentalAmount: {{ Js::from($rentalApplication->approved_rental_amount) }},
                approvedDepositAmount: {{ Js::from($rentalApplication->approved_deposit_amount) }},
                rentalAmount: {{ Js::from(old('rental_amount', $rentalApplication->approved_rental_amount)) }},
                depositAmount: {{ Js::from(old('deposit_amount', $rentalApplication->approved_deposit_amount)) }},
                rentalAmountSource: {{ Js::from($rentalApplication->approved_rental_amount !== null ? 'approved' : null) }},
                depositAmountSource: {{ Js::from($rentalApplication->approved_deposit_amount !== null ? 'approved' : null) }},
                async search() {
                    if (this.query.length < 2) { this.results = []; return; }
                    const res = await fetch({{ Js::from(route('corex.rental-applications.search-properties')) }} + '?q=' + encodeURIComponent(this.query));
                    this.results = await res.json();
                },
                select(p) {
                    this.propertyId = p.id;
                    this.propertyLabel = p.label;
                    // PRECEDENCE (see the ruling above): the approved
                    // application amount always wins when it exists; the
                    // property's own rental details are only the fallback.
                    // No value anywhere (null) means an empty field, never
                    // a written zero; ?? only catches null/undefined, so a
                    // genuinely-stored 0 is preserved as 0.
                    if (this.approvedRentalAmount !== null) {
                        this.rentalAmount = this.approvedRentalAmount;
                        this.rentalAmountSource = 'approved';
                    } else {
                        this.rentalAmount = p.rental_amount ?? '';
                        this.rentalAmountSource = p.rental_amount != null ? 'property' : null;
                    }
                    if (this.approvedDepositAmount !== null) {
                        this.depositAmount = this.approvedDepositAmount;
                        this.depositAmountSource = 'approved';
                    } else {
                        this.depositAmount = p.deposit_amount ?? '';
                        this.depositAmountSource = p.deposit_amount != null ? 'property' : null;
                    }
                    this.searching = false;
                    this.query = '';
                    this.results = [];
                },
             }">
            @if($tenantLinkedProperty)
                <p class="text-xs font-medium mb-1" style="color: var(--text-secondary);">Tenant linked to property</p>
                <div class="flex items-center gap-2 flex-wrap">
                    {{-- 2026-09-13 — native confirm() removed, same reasoning as
                         Approve/Decline (see review.blade.php and
                         scripts/rental-click-through.mjs's newPage() dialog
                         handler): a native dialog blocks the whole tab's
                         renderer until a human dismisses THAT specific dialog,
                         and it is invisible to the click-through gate — a
                         reintroduced confirm() here would have been silently
                         clicked through by the gate's old auto-accept handler.
                         Two-stage in-page confirmation instead, same pattern
                         as authoriser-approve-continue/-confirm, naming the
                         tenant and property being unlinked rather than a
                         generic "are you sure". --}}
                    <template x-if="!unlinkConfirming">
                        <span class="flex items-center gap-2 flex-wrap">
                            <span class="ds-badge ds-badge-success">{{ $tenantLinkedProperty->buildDisplayAddress() }} — tenant linked</span>
                            <button type="button" class="text-xs underline" style="color: var(--ds-red, #dc2626);"
                                    data-qa="tenant-unlink-continue" @click="unlinkConfirming = true">Unlink</button>
                        </span>
                    </template>
                    <template x-if="unlinkConfirming">
                        <span class="flex items-center gap-2 flex-wrap">
                            <span class="text-xs" style="color: var(--ds-red, #dc2626);">
                                Remove {{ $rentalApplication->contact->full_name }} as tenant of {{ $tenantLinkedProperty->buildDisplayAddress() }}?
                            </span>
                            <button type="button" class="corex-btn-outline text-xs" @click="unlinkConfirming = false">Go back</button>
                            <form method="POST" action="{{ route('corex.rental-applications.unlink-tenant-property', $rentalApplication) }}" class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs underline" style="color: var(--ds-red, #dc2626);"
                                        data-qa="tenant-unlink-confirm">Yes, unlink</button>
                            </form>
                        </span>
                    </template>
                </div>
                @if($applicationLease)
                    <p class="text-[11px] mt-1" style="color: var(--text-muted);">
                        Lease: R{{ number_format((float) $applicationLease->rental_amount, 2) }}/mo,
                        from {{ $applicationLease->start_date->format('Y-m-d') }}
                        @if($applicationLease->end_date) to {{ $applicationLease->end_date->format('Y-m-d') }} @endif
                        — <span class="ds-badge {{ $applicationLease->status === 'active' ? 'ds-badge-success' : 'ds-badge-muted' }}">{{ ucfirst($applicationLease->status) }}</span>
                        @permission('leases.view')
                            <a href="{{ route('corex.leases.show', $applicationLease) }}" class="underline">View lease</a>
                        @endpermission
                    </p>
                @endif
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
                            {{-- Johan, QA1 walk, 2026-09-21 — "not the search we have
                                 implemented in other sections like pdf splitter
                                 where it displays proper details for the agent to
                                 know they are linking to the correct property."
                                 Same two-line result row as the PDF splitter's own
                                 property picker (resources/views/tools/
                                 pdf_splitter_review.blade.php): address + status
                                 badge, then ref + agent as a muted subtitle. --}}
                            <div class="absolute z-10 mt-1 w-full rounded-md max-h-72 overflow-y-auto" style="background: var(--surface); border: 1px solid var(--border);" x-show="results.length">
                                <template x-for="p in results" :key="p.id">
                                    <button type="button" @click="select(p)" class="block w-full text-left px-2 py-1.5 text-xs hover:bg-slate-50">
                                        <div class="flex items-center gap-1.5">
                                            <span x-text="p.label"></span>
                                            <span x-show="p.status" x-text="p.status" class="text-[10px] px-1 py-0.5 rounded" style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border); white-space:nowrap;"></span>
                                        </div>
                                        <div style="color: var(--text-muted);" x-text="[p.ref ? ('Ref: ' + p.ref) : '', p.agent].filter(Boolean).join(' · ')"></div>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>

                    {{-- .ai/specs/leases.md sec1.3 / conductor ruling 2026-09-15 —
                         gap 1 closed: linking a tenant now captures the lease
                         terms in the SAME action, instead of leaving a
                         property+tenant link with no rent/dates anywhere.
                         Pre-filled from the application's own
                         approved_rental_amount where the agent already set
                         one; always editable. Does NOT touch property status
                         — that ruling stays pending with Johan, untouched by
                         this change (see linkTenantProperty()'s own
                         docblock). --}}
                    <div class="w-full flex flex-wrap items-end gap-2 mt-1 pt-2" style="border-top: 1px dashed var(--border);">
                        <div>
                            <label class="text-[11px]" style="color: var(--text-muted);">Monthly rental (R)</label><br>
                            {{-- Johan, 2026-09-22 — precedence: approved
                                 application amount wins, property is the
                                 fallback (select()/ruling above); still a
                                 plain editable starting value, not locked. --}}
                            <input type="number" name="rental_amount" step="0.01" min="0" required
                                   x-model="rentalAmount" @input="rentalAmountSource = null"
                                   class="rounded-md px-2 py-1 text-xs" style="border: 1px solid var(--border); width: 8rem;"><br>
                            <span class="text-[10px]" style="color: var(--text-muted);" x-show="rentalAmountSource"
                                  x-text="rentalAmountSource === 'approved' ? 'from approved application' : 'from property'"></span>
                        </div>
                        <div>
                            <label class="text-[11px]" style="color: var(--text-muted);">Deposit (R)</label><br>
                            <input type="number" name="deposit_amount" step="0.01" min="0"
                                   x-model="depositAmount" @input="depositAmountSource = null"
                                   class="rounded-md px-2 py-1 text-xs" style="border: 1px solid var(--border); width: 8rem;"><br>
                            <span class="text-[10px]" style="color: var(--text-muted);" x-show="depositAmountSource"
                                  x-text="depositAmountSource === 'approved' ? 'from approved application' : 'from property'"></span>
                        </div>
                        <div>
                            <label class="text-[11px]" style="color: var(--text-muted);">Lease start date</label><br>
                            <input type="date" name="lease_start_date" required value="{{ old('lease_start_date', now()->toDateString()) }}"
                                   class="rounded-md px-2 py-1 text-xs" style="border: 1px solid var(--border);">
                        </div>
                        <div>
                            <label class="text-[11px]" style="color: var(--text-muted);">Lease end date (optional)</label><br>
                            <input type="date" name="lease_end_date" value="{{ old('lease_end_date') }}"
                                   class="rounded-md px-2 py-1 text-xs" style="border: 1px solid var(--border);">
                        </div>
                    </div>

                    <button type="submit" class="corex-btn-primary text-xs" :disabled="!propertyId">Link as tenant</button>
                </form>
                <p class="text-[11px] mt-1" style="color: var(--text-muted);">Sets {{ $rentalApplication->contact->full_name ?? 'this applicant' }} as the tenant on this property, and creates the lease record with the terms above.</p>
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
