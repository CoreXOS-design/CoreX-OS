{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- AT-392 Phase 2 — agent review split-screen. Johan's own words: "application
     gets returned, agent open application - sees application and supporting docs
     on left panel of screen... then have a place on the right panel to input
     things like - income, salary / etc etc... doing the calcs to the bottom to
     see if tenant qualifies."

     UNIFIED SCREEN, 2026-09-09 — Johan, verbatim, after finding this built as
     two structurally different screens: "did I not tell you the reviewer
     screen is essentially the same screen as the agent screen? same fucking
     problem I have been describing all along. how does the reviewer work
     through the bank statement as example and look at what the agent marked
     / captured etc.?" He was right, and had been saying so since the
     authoriser screen was first built. ONE blade file now serves both
     RentalApplicationReviewController::show() ($viewerRole='agent') and
     RentalApplicationAuthorisationController::show() ($viewerRole=
     'authoriser') — same layout, same components, differing only where
     the two roles genuinely differ: the assessment panel (agent edits
     inline; authoriser strikes-and-adds, never edits) and the actions panel
     (agent submits/reopens/requests-info-from-applicant; authoriser
     approves/declines/requests-info-from-agent). Two routes stay separate —
     their guards answer genuinely different authorization questions
     (guardRentalApplication()'s ownership/branch/agency scope vs
     guardCanView()'s RO/CO tier check) — collapsing them into one guard
     would be exactly the kind of fragile conflation that's bitten this
     feature before. The authorisation queue's links are unchanged; they
     still point at the authorisation route, which now renders this screen
     instead of a second one. --}}
@extends('layouts.corex')

@php
    // Computed here, not inline inside the x-data string below — a
    // multi-line arrow-function/array literal nested inside a Blade
    // expression has already caused one real outage on this feature
    // (rental-applications/public/show.blade.php's @json() incident,
    // 2026-09-08) — never assume a closure inline in a Blade echo is safe
    // without proving the compiled output first.
    if ($viewerRole === 'agent') {
        $initialIncomeItems = $assessment->incomeItems->map(fn ($i) => [
            'id' => $i->id, 'description' => $i->description, 'amount' => $i->amount, 'entry_date' => $i->entry_date?->format('Y-m-d'),
        ])->values();
        $initialExpenseItems = $assessment->expenseItems->map(fn ($i) => [
            'id' => $i->id, 'description' => $i->description, 'amount' => $i->amount, 'entry_date' => $i->entry_date?->format('Y-m-d'),
        ])->values();
    }
    $initialMarkedUpDocIds = $documents->filter(fn ($row) => $row['has_highlights'])->pluck('document.id')->values();
    // AT-392 — Johan: "unsplit shows as visibly incomplete on the
    // application, never silently accepted." Shared by the Submit button
    // (header) and the Supporting Documents heading (badge) below — same
    // test RentalApplicationReviewController::submitForApproval() enforces
    // server-side, and the same test the "Split & File" trigger uses to
    // decide whether to show itself on each individual document.
    // Owned documents only — a referenced (pulled-from-contact) document's
    // typing/splitting was already this application's business at its
    // original filing home, not here (see guardDocumentBelongsToApplication
    // and submitForApproval()'s matching server-side count).
    $unsplitCount = $documents->filter(fn ($row) => !$row['pulled_from_contact'] && $row['document']->document_type_id === null && $row['document']->mime_type === 'application/pdf')->count();
@endphp

@section('corex-content')
<div class="w-full"
     @if($viewerRole === 'agent')
     x-data="rentalReview({
         saveUrl: '{{ route('corex.rental-applications.review.assessment', $rentalApplication) }}',
         initial: {
             notes: {{ Js::from($assessment->notes) }},
             statement_months: {{ Js::from($assessment->statement_months) }},
             statement_period_from: {{ Js::from($assessment->statement_period_from?->format('Y-m-d')) }},
             statement_period_to: {{ Js::from($assessment->statement_period_to?->format('Y-m-d')) }},
             has_unpaid_transactions: {{ Js::from((bool) $assessment->has_unpaid_transactions) }},
         },
         initialIncomeItems: {{ Js::from($initialIncomeItems) }},
         initialExpenseItems: {{ Js::from($initialExpenseItems) }},
         initialResult: {{ Js::from($result) }},
         initialSavedAt: {{ $assessment->exists ? Js::from($assessment->updated_at->toIso8601String()) : 'null' }},
         initialMarkedUpDocIds: {{ Js::from($initialMarkedUpDocIds) }},
         currentUserId: {{ Js::from(auth()->id()) }},
         currentUserName: {{ Js::from(auth()->user()->name) }},
         currentUserRole: 'agent',
         highlighters: {{ Js::from($highlighters) }},
         requestMoreInfoUrl: '{{ route('corex.rental-applications.review.request-more-info', $rentalApplication) }}',
         submitForApprovalUrl: '{{ route('corex.rental-applications.review.submit-for-approval', $rentalApplication) }}',
         reopenUrl: '{{ route('corex.rental-applications.review.reopen', $rentalApplication) }}',
         expectedGeneration: {{ Js::from($rentalApplication->current_generation) }},
     })"
     @else
     x-data="rentalAuthorisationViewer({
         initialMarkedUpDocIds: {{ Js::from($initialMarkedUpDocIds) }},
         currentUserId: {{ Js::from(auth()->id()) }},
         currentUserName: {{ Js::from(auth()->user()->name) }},
         currentUserRole: 'authoriser',
         highlighters: {{ Js::from($highlighters) }},
     })"
     @endif
>

    {{-- Sticky header, 2026-09-08 — second time today the same fault: controls
         stranded off-screen while the user scrolls a long surface. Same fix as
         the rental application form (resources/views/corex/rental-applications/
         show.blade.php) — the shared x-sticky-action-bar component, not a new
         invention. Its right slot swaps between the role's own primary action
         (normal) and the highlighter's own toolbar + Save button (while a
         document is open for marking) — Johan: "place them in a header so the
         agent can scroll and see the highlighter as well as the save buttons
         at all times." Unified screen, 2026-09-09 — the authoriser used to
         have its own non-sticky banner with the highlighter toolbar rendered
         inline per-document instead; moved onto this same shared header so
         "same layout for both roles" is literally true, not just visually
         similar. --}}
    <x-sticky-action-bar>
        <x-slot name="left">
            <div class="min-w-0">
                <h1 class="text-sm font-bold leading-tight truncate" style="color: var(--text-primary);">
                    {{ $viewerRole === 'agent' ? 'Application Review' : 'Authorise' }} — {{ $rentalApplication->contact->full_name ?? $rentalApplication->contact->first_name . ' ' . $rentalApplication->contact->last_name }}
                </h1>
                <p class="text-xs truncate" style="color: var(--text-muted);">
                    {{ $rentalApplication->property_address_override ?? optional($rentalApplication->property)->address ?? 'No property linked' }}
                    @if($viewerRole === 'authoriser')
                        &middot; Submitted for approval {{ $rentalApplication->submitted_for_approval_at?->format('d M Y H:i') }}
                    @endif
                </p>

                @if($viewerRole === 'agent')
                    {{--
                        AT-392, Johan (approved via conductor, 2026-09-08): "property
                        linked should be an option then on review... if not linked and
                        we want to test against it then we need to allow the agent to
                        link it on this screen as well." The affordability check was
                        silently testing the applicant's self-reported CURRENT rent
                        when nothing was linked — meaningless. This picker reuses the
                        exact same search-properties endpoint and Alpine pattern as
                        the New Rental Application screen's property field
                        (resources/views/corex/rental-applications/create.blade.php)
                        — not a second implementation. A NESTED, independent x-data
                        scope, deliberately not touching the shared rentalReview()
                        component (cc4's affordability panel further down the page
                        reads from that) — this widget only ever POSTs to its own
                        link-property route and reloads. Agent-only, 2026-09-09: an
                        authoriser reviewing someone else's application shouldn't be
                        changing which property it's tested against — that's the
                        agent's call, not a decision this merge hands the authoriser
                        for free.
                    --}}
                    {{--
                        AT-392 follow-up, Johan (QA1, item 4 of the post-test findings,
                        2026-09-10): "if you did not select the property before the
                        rental application opens you cannot add it on the application."
                        Investigated by actually doing it, not by reading the source —
                        the control was never broken: search-properties and
                        link-property both work exactly as built, unconditionally, at
                        every status, with no gate beyond the ordinary agent-viewer
                        check already on this whole block. The real defect was that
                        this control read as inert status text, not a button, and its
                        copy ("Link a property to check against its rent") framed
                        itself as an affordability side-calculator rather than what it
                        actually is — the only way to attach a property to THIS
                        application at all. A few lines away, the affordability panel
                        (a different feature's screen real estate, not touched here)
                        has its OWN static, non-clickable sentence that happens to
                        start with the same three words — easy to mistake for the
                        same control. Fix is copy + prominence only; the mechanism
                        underneath (search-properties/link-property, agent-only,
                        unconditional on status) is unchanged.
                    --}}
                    @if($propertyLinkLocked ?? false)
                        {{--
                            Locked, 2026-09-10 (Johan, QA1 item 2 follow-up) —
                            server-side gate lives in linkProperty() itself
                            (a POST straight at the route 403s); this is just
                            the honest UI reflection of that, not the
                            enforcement. Never rendered as a disabled version
                            of the interactive control — a genuinely
                            different, read-only state, so there's nothing
                            here that LOOKS clickable but silently no-ops.
                        --}}
                        <p class="text-xs" style="color: var(--text-muted);">
                            {{ $rentalApplication->property ? 'Property: ' . (optional($rentalApplication->property)->title ?: optional($rentalApplication->property)->buildDisplayAddress()) : 'No property linked.' }}
                            <span style="color: var(--text-muted);">&middot; locked — submitted for authorisation.</span>
                        </p>
                    @else
                    <div class="mt-1" x-data="rentalReviewPropertyLink({{ Js::from([
                        'searchUrl' => route('corex.rental-applications.search-properties'),
                        'linkUrl' => route('corex.rental-applications.review.link-property', $rentalApplication),
                        'currentLabel' => optional($rentalApplication->property)->title
                            ?: optional($rentalApplication->property)?->buildDisplayAddress(),
                    ]) }})">
                        <template x-if="!searching">
                            <p class="text-xs flex items-center flex-wrap gap-1.5">
                                <span style="color: var(--text-muted);" x-show="currentLabel" x-text="'Property: ' + currentLabel"></span>
                                <span style="color: var(--text-muted);" x-show="!currentLabel">No property linked yet.</span>
                                <button type="button"
                                        class="corex-btn-outline"
                                        style="padding: 0.15rem 0.6rem; font-size: 0.7rem; line-height: 1.2;"
                                        @click="searching = true">
                                    <span x-text="currentLabel ? 'Change property' : 'Link a property'"></span>
                                </button>
                                <button type="button" class="underline" style="color: var(--ds-red, #dc2626);" x-show="currentLabel" @click="clear()">Clear</button>
                            </p>
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
                        <form :action="linkUrl" method="POST" x-ref="linkForm" class="hidden">
                            @csrf
                            <input type="hidden" name="property_id" x-ref="propertyIdInput">
                        </form>
                    </div>
                    @endif
                @endif
            </div>
        </x-slot>
        <x-slot name="right">
            <template x-if="activeDocId === null">
                <div class="flex items-center gap-2 flex-wrap justify-end">
                    @if($viewerRole === 'agent')
                        {{-- 2026-09-08 — Johan, three times now: "same fight with placement
                             of buttons - submit to auth should be on the header at the
                             top, not off screen at the bottom... every primary action on
                             this screen belongs in the header where it is always
                             visible." Moved from the aside's own "Next step" block —
                             same actions, same guard (hidden once a decision exists),
                             just reachable regardless of scroll position now. --}}
                        @unless(in_array($rentalApplication->status, ['approved', 'declined'], true))
                            @if($unsplitCount > 0)
                                <span class="ds-badge ds-badge-warning" title="{{ $unsplitCount === 1 ? 'One supporting document' : "{$unsplitCount} supporting documents" }} still need to be split into typed, filed pieces before this application can go to the authoriser.">{{ $unsplitCount }} unsorted</span>
                            @endif
                            <button type="button" class="corex-btn-primary text-xs" :disabled="submittingForApproval"
                                    @click="submitForApproval()" x-text="submittingForApproval ? 'Submitting…' : ({{ $isPendingAuthorisation ? 'true' : 'false' }} ? 'Re-submit to authoriser' : 'Submit to authoriser')"></button>
                        @endunless
                        <a href="{{ route('corex.rental-applications.show', $rentalApplication) }}" class="corex-btn-outline text-xs">Back to application</a>
                    @else
                        <a href="{{ route('corex.rental-applications.authorisation.index') }}" class="corex-btn-outline text-xs">Back to list</a>
                    @endif
                </div>
            </template>
            <template x-if="activeDocId !== null">
                {{-- 2026-09-10 (cc5, AT-392, Johan's "desk with highlighters"
                     principle) — this used to hold the full tool set
                     (Highlight/Note toggle, a colour dropdown that changed
                     meaning depending on which was picked last, stroke
                     size, undo/redo) in one reflowing header row: Johan,
                     verbatim, "some shows, some goes away? ... not click
                     buttons change, etc." Every drawing/marking tool moved
                     to the new fixed left panel inside the document viewer
                     (document-highlighter-pages.blade.php) — one place,
                     always visible, nothing here changes shape depending on
                     what's selected. This header keeps only what genuinely
                     needs to stay reachable regardless of scroll position
                     (Johan, 2026-09-08: "place them in a header... always
                     visible") — the document label and the page-level
                     Save/Done actions, not the tools themselves. --}}
                <div class="flex items-center gap-3 flex-wrap justify-end">
                    <span class="text-xs font-medium truncate max-w-[160px]" style="color: var(--text-secondary);" x-text="label"></span>
                    <span class="text-xs font-semibold hidden sm:inline" style="color: var(--text-secondary);" x-show="!loading">
                        <span x-text="markCount()"></span> mark<span x-show="markCount() !== 1">s</span>
                    </span>
                    <button type="button" class="corex-btn-primary text-xs" x-show="!loading && !loadError"
                            :disabled="applying || pagesLoading" :title="pagesLoading ? 'Still loading the rest of this document' : ''"
                            x-text="applying ? 'Saving…' : (pagesLoading ? 'Loading…' : 'Save')" @click="applyHighlights()"></button>
                    <button type="button" class="corex-btn-outline text-xs" @click="closeHighlighter()">Done</button>
                </div>
            </template>
        </x-slot>
    </x-sticky-action-bar>

    @if($viewerRole === 'authoriser' && $alreadyDecided)
        <div class="rounded-md px-4 py-3 text-sm mt-4" style="background: var(--ds-amber-soft, #fffbeb); color: var(--ds-amber, #92400e); border: 1px solid var(--ds-amber, #f59e0b);">
            This application already has a decision: <strong>{{ ucfirst($rentalApplication->status) }}</strong>{{ $rentalApplication->approved_rental_amount ? ' for R' . number_format($rentalApplication->approved_rental_amount, 2) : '' }}.
            @if($canOverride)
                Acting below will OVERRIDE it — a reason is required.
            @else
                Only a CO (Override) user may change it.
            @endif
        </div>
    @endif

    @if($viewerRole === 'agent' && $declineInfo)
        {{-- Johan, 2026-09-09, verbatim: "yes they should see it. the auth
             needs to report back to the agent why the application has been
             rejected." Not enough for the reason to exist in the audit
             trail for the agent to go find — this is the outcome itself,
             at the top where the agent will see it within one second of
             opening the application, not buried below the documents. The
             audit trail (main column, below Supporting Documents) keeps
             its own copy as history — that's a separate thing; this is the
             report-back. --}}
        <div class="rounded-md px-4 py-3 text-sm mt-4" style="background: var(--ds-red-soft, #fef2f2); color: var(--ds-red, #dc2626); border: 1px solid var(--ds-red, #dc2626);">
            <strong>Declined.</strong>
            <div class="mt-1 whitespace-pre-wrap" style="color: var(--text-primary);">{{ $declineInfo['reason'] }}</div>
        </div>
    @endif

    {{-- Save confirmation, 2026-09-08 — Johan: "if I edit / highlight anything on
         the pdf will it automatically save?" It did not autosave, and answering
         that ambiguity is the fix: highlighting/notes are EXPLICIT-save only.
         Page-level so it survives the panel closing. --}}
    <div x-show="justSaved" x-cloak x-transition
         class="fixed z-40 rounded-md px-3 py-2 text-xs font-medium flex items-center gap-1.5"
         style="top: 72px; right: 20px; background: var(--ds-emerald, #059669); color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
        &check; Marks saved — the next person to open this document sees them.
    </div>

    {{-- Layout — one shared shape for both roles now, 2026-09-09. Was
         .rental-review-* (agent) and .rah-auth-* (authoriser) — two class
         families for the identical two-region shape, exactly the kind of
         drift Johan flagged ("we have been bitten repeatedly today by two
         versions of the same thing"). One family now; the authoriser's
         320px aside became 260px (the agent's original) — the Decision
         panel's inputs are w-full and adapt fine; if Johan wants it wider
         later, that's a one-line change, not a rebuild.

         2026-09-07/08 — Johan rejected the original 50/50 grid ("makes the
         document unreadable, defeats the purpose"). Same two-region shape
         CoreX already uses twice elsewhere (Docuperfect's signature
         review/sign screens): review-main (dominant, application +
         documents) and review-aside (fixed 260px, the role's own working
         panel). Stacks below 1280px.

         IN-PLACE ANNOTATION — Johan: "you lose the right hand panel to
         capture income etc. until you have finished the highlighting...
         think thats a problem as you have both but on separate screens."
         Fixed by removing the modal: the highlighter renders INLINE inside
         review-main's own document row, so review-aside is a plain flex
         SIBLING that's never covered.

         Independent scrolling — the original fixed "100vh - 88px" guess
         didn't account for the QA/env banner or the sticky header's real
         margins. Fixed by MEASURING the real available space at runtime
         (rentalReviewLayout() below) instead of guessing it.

         DRAGGABLE WIDTH, 2026-09-10 ("Dates on entries", Johan) — the aside
         was a fixed 260px, tight enough already per the "one-line change,
         not a rebuild" note above; adding a date column to every income/
         expense row (and a two-date "statement period" row) would have
         meant cramming three fields into that width. Johan's own call:
         "if the dates do not fit cleanly, build the draggable panel width
         as part of this rather than cramming them in." Reuses the exact
         drag pattern already shipped for DocuPerfect's web-template editor
         (resources/views/docuperfect/templates/edit-web.blade.php
         startDrag/onDrag) rather than inventing a second one — a plain
         mousedown/mousemove/mouseup drag on a 6px handle, clamped to a
         sensible min/max, width read from and written to a CSS custom
         property so the existing sticky/independent-scroll rules above
         keep working unchanged. Persisted per-browser via localStorage
         (rentalReviewAsideWidth) so a drag survives a reload.

         DEFAULT WIDTH RE-DERIVED FROM CONTENT, 2026-09-10, ROUND 2 ("Item 6",
         Johan, rejecting the first pass after checking application 76
         himself: "the description field on every row is so narrow it
         truncates to about five characters... You solved it INSIDE the
         panel's existing width — and the width is the problem. Going
         260 → 320 does not make a 5-character field readable... Work out
         the width the affordability rows genuinely need... then give the
         panel that, taking the space from the main column, which plainly
         has it to spare."
         He was right and the first pass was wrong: raising the floor by
         60px was an increment off the OLD number, not a figure derived
         from what a description/date/amount row actually needs — and
         .rental-review-main genuinely has width to spare (its own cards —
         Submitted Application, Supporting Documents, Audit Trail — are
         short, list-shaped content that was never using the wide column
         it was given; there is no reason the aside should stay narrow so
         that column can sit under-used).
         The new default is SUMMED from the row's own real requirements,
         not picked and then checked: a description column comfortable for
         a real word (~170px — "Salary deposit" fits with room),
         + the date column's already-proven 130px, + an amount column
         comfortable to R999,999.99 (~100px), + two 6px grid gaps (12px),
         + each card's own p-3 padding (24px) = 436px, rounded to 440.
         Floor (drag-to-narrowest) raised 320 → 380 — still narrower than
         the default, for a user who genuinely wants less panel, but with
         the "degrade legibly" rule below covering it rather than an
         unreadable stump. Ceiling raised 480 → 640, since a description
         column is exactly the kind of field worth giving real extra room
         to on a wide monitor.

         ROUND 3, 2026-09-10, SAME DAY — Johan accepted the description fix
         but found the AMOUNT column now clipping its last digit on
         application 76's real larger figures ("28861.3", "29340.9",
         "28863.0" instead of the full 8-character value) at the very
         default width just fixed. Root cause, found by measuring — not
         guessing again: this headless test browser renders NO native
         scrollbar (Linux/Chromium default is an overlay scrollbar that
         takes zero layout width), but `.rental-review-aside` genuinely
         overflows vertically on a real 6-row+ record and `overflow-y:auto`
         WILL show a real, space-consuming scrollbar in an ordinary desktop
         Chrome/Edge on Windows — commonly ~17px wide. That 17px was never
         accounted for in the row's width budget, so every column
         (including amount) had slightly less real room than this test
         environment showed. Fixed at the root with `scrollbar-gutter:
         stable` below — the browser reserves that space in the layout
         WHETHER OR NOT a scrollbar is currently drawn, so the content
         width this test environment measures now matches what a real
         scrollbar-showing browser actually has, instead of silently
         disagreeing by ~17px.
         Amount column's own floor also raised 78px → 100px (a hard
         minimum now, not contingent on leftover flex space) — checked
         against this application's own real data (largest captured
         figures: R29,340.99 / R28,863.00, both 8 characters) plus headroom
         to a realistic 9-character ceiling (R999,999.99). Description's
         ratio trimmed 1.6fr → 1.5fr to make room for amount's new floor
         without re-widening the whole row. Default recomputed with the
         scrollbar now included in the budget: 170 (desc) + 130 (date) +
         100 (amount, now a real floor not a lower bound) + 12 (gaps) + 24
         (card padding) + 17 (scrollbar-gutter reservation) = 453, rounded
         to 460. Floor/ceiling shifted the same +20px the default moved:
         400–660. --}}
    <style>
        .rental-review-columns { display: flex; flex-direction: column; gap: 20px; }
        .rental-review-main    { flex: 1 1 auto; min-width: 0; }
        .rental-review-aside   { width: 100%; }
        .rental-review-resizer { display: none; }
        @media (min-width: 1280px) {
            .rental-review-columns { flex-direction: row; gap: 0; align-items: stretch; }
            .rental-review-main    { height: var(--rr-panel-h, calc(100vh - 160px)); max-height: var(--rr-panel-h, calc(100vh - 160px)); overflow-y: auto; margin-right: 16px; }
            .rental-review-aside   { flex: 0 0 var(--rr-aside-w, 460px); width: var(--rr-aside-w, 460px); align-self: stretch; position: sticky; top: 72px; height: var(--rr-panel-h, calc(100vh - 160px)); max-height: var(--rr-panel-h, calc(100vh - 160px)); overflow-y: auto; overflow-x: hidden; scrollbar-gutter: stable; }
            .rental-review-resizer {
                display: block; flex: 0 0 6px; width: 6px; cursor: col-resize;
                align-self: stretch; position: sticky; top: 72px;
                height: var(--rr-panel-h, calc(100vh - 160px));
                background: var(--border); border-radius: 3px; margin: 0 5px;
            }
            .rental-review-resizer:hover, .rental-review-resizer.is-dragging { background: var(--ds-blue, #2563eb); }
        }
    </style>

    <div class="rental-review-columns mt-5" x-data="rentalReviewLayout()">

        {{-- MAIN — the submitted application, supporting documents, and audit
             trail. Dominant column, shared for both roles. --}}
        <div class="rental-review-main space-y-4">
            {{-- Collapsed by default, 2026-09-07 — Johan: "collapse on the submitted
                 application section to get extra screen to view on [for the PDF]."
                 Shared for both roles, 2026-09-09 — was a plain always-open dl on
                 the authoriser's screen; the collapsible version is strictly more
                 useful (same fields, less permanently-taken height) so it became
                 the one canonical version rather than keeping two. The "View
                 submitted application" link stays AGENT-ONLY: it hits
                 RentalApplicationController::show(), whose guard
                 (guardRentalApplication(), a permission-scope check — own/branch/
                 all) is a genuinely different question from RO/CO tier
                 membership, and an authoriser reviewing someone else's
                 application isn't guaranteed to pass it — handing them a link
                 that can 403 isn't a real feature. --}}
            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ summaryOpen: false }">
                <button type="button" class="w-full flex items-center justify-between text-left" @click="summaryOpen = !summaryOpen">
                    <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Submitted Application</h2>
                    <span class="text-xs" style="color: var(--ds-blue, #2563eb);" x-text="summaryOpen ? 'Hide' : 'Show'"></span>
                </button>
                <div x-show="summaryOpen" x-cloak class="mt-3">
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                        <dt style="color: var(--text-muted);">Employer</dt><dd>{{ $rentalApplication->employer_name ?? '—' }}</dd>
                        <dt style="color: var(--text-muted);">Position</dt><dd>{{ $rentalApplication->employer_position ?? '—' }}</dd>
                        <dt style="color: var(--text-muted);">Monthly salary (self-reported)</dt><dd>{{ $rentalApplication->monthly_salary !== null ? 'R ' . number_format($rentalApplication->monthly_salary, 2) : '—' }}</dd>
                        <dt style="color: var(--text-muted);">Current rental amount</dt><dd>{{ $rentalApplication->current_rental_amount !== null ? 'R ' . number_format($rentalApplication->current_rental_amount, 2) : '—' }}</dd>
                        {{-- "Dates on entries" (Johan, 2026-09-10) — once submitted, this
                             screen is the agent's only way to VERIFY the rent-due-day the
                             applicant answered (the edit form above locks after submission,
                             same as every other applicant-facing field on this summary). --}}
                        <dt style="color: var(--text-muted);">Current rent due day</dt><dd>{{ $rentalApplication->current_rental_due_day ?? '—' }}</dd>
                        <dt style="color: var(--text-muted);">Current landlord</dt><dd>{{ $rentalApplication->current_landlord_name ?? '—' }}</dd>
                        <dt style="color: var(--text-muted);">Adults / Children</dt><dd>{{ $rentalApplication->adults ?? '—' }} / {{ $rentalApplication->children ?? '—' }}</dd>
                    </dl>
                    @if($viewerRole === 'agent')
                        <a href="{{ route('corex.rental-applications.show', $rentalApplication) }}" class="text-xs inline-block mt-3" style="color: var(--ds-blue, #2563eb);">View submitted application &rarr;</a>
                    @endif
                </div>
            </div>

            {{-- Supporting Documents + in-place highlighter — shared markup for
                 both roles now (unified screen, 2026-09-09). Route names differ
                 by role (agent: corex.rental-applications.documents.*;
                 authoriser: corex.rental-applications.authorisation.documents.*)
                 — resolved per-document below via $viewerRole rather than
                 duplicating this whole block. --}}
            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">
                    Supporting Documents
                    <span class="ds-badge ds-badge-default">{{ $documents->count() }}</span>
                    @if($unsplitCount > 0)
                        <span class="ds-badge ds-badge-warning" title="These documents must be split into typed, filed pieces before this application can be submitted for authorisation.">{{ $unsplitCount }} not yet sorted</span>
                    @endif
                </h2>

                @if($documents->isEmpty())
                    <p class="text-xs" style="color: var(--text-muted);">No supporting documents have been uploaded yet.</p>
                @else
                    <div class="space-y-2">
                        @foreach($documents as $row)
                            @php
                                $document = $row['document'];
                                $highlightFirstUrl = $viewerRole === 'agent'
                                    ? route('corex.rental-applications.documents.highlight-data.first', [$rentalApplication, $document])
                                    : route('corex.rental-applications.authorisation.documents.highlight-data.first', [$rentalApplication, $document]);
                                $highlightRemainingUrl = $viewerRole === 'agent'
                                    ? route('corex.rental-applications.documents.highlight-data.remaining', [$rentalApplication, $document])
                                    : route('corex.rental-applications.authorisation.documents.highlight-data.remaining', [$rentalApplication, $document]);
                                $highlightPostUrl = $viewerRole === 'agent'
                                    ? route('corex.rental-applications.documents.highlight', [$rentalApplication, $document])
                                    : route('corex.rental-applications.authorisation.documents.highlight', [$rentalApplication, $document]);
                            @endphp
                            <div class="rounded-md border" style="border-color: var(--border);">
                                <div class="flex items-center justify-between px-3 py-2 text-xs">
                                    <span>{{ $document->original_name }}
                                        {{-- AT-392 — Johan: "document age shows wherever an agent
                                             picks or reviews a document." Plain age, every document,
                                             both roles — title carries the exact timestamp. --}}
                                        <span style="color: var(--text-muted); font-size: 11px;" title="{{ $document->created_at->format('d M Y H:i') }}">— {{ $document->created_at->diffForHumans() }}</span>
                                        @if($row['staleness_warning'])
                                            {{-- AT-392 — Johan: "a stale document warns naming the
                                                 purpose it fails and by how long, in plain language."
                                                 RentalApplicationDocumentValidityWindow::stalenessWarning()
                                                 already composed the exact sentence — shown verbatim. --}}
                                            <span class="ds-badge ds-badge-warning" title="{{ $row['staleness_warning'] }}">{{ $row['staleness_warning'] }}</span>
                                        @endif
                                        @if($row['pulled_from_contact'])
                                            {{-- AT-392 "pull from contact" — filed elsewhere, only
                                                 referenced here (rental_application_document pivot);
                                                 source_type/source_id untouched. --}}
                                            <span class="ds-badge ds-badge-default" title="Already on file for this contact — attached here without the applicant re-sending it.">From contact's file</span>
                                        @endif
                                        @if($viewerRole === 'agent' && !$row['pulled_from_contact'])
                                            {{-- Agent-added-documents, 2026-09-08 — cc4's backend
                                                 (RentalApplicationController::uploadDocument()). --}}
                                            <span style="color: var(--text-muted); font-size: 11px;">— {{ $document->uploaded_by ? 'added by ' . ($document->uploader->name ?? 'an agent') : 'from applicant' }}</span>
                                            @if($rentalApplication->submitted_at && $document->created_at->greaterThanOrEqualTo($rentalApplication->submitted_at))
                                                <span class="ds-badge ds-badge-warning" title="This document was added after the application was submitted">Added after submission</span>
                                                <span style="color: var(--text-muted); font-size: 11px;">{{ $document->created_at->format('d M Y H:i') }}</span>
                                            @endif
                                        @endif
                                    </span>
                                    <span class="flex items-center gap-2">
                                        <span class="ds-badge ds-badge-success" x-show="markedUpDocIds.includes({{ $document->id }})" x-cloak title="This document has saved marks — visible to anyone who opens it next.">Marked up</span>
                                        @if($row['inline_viewable'])
                                            <button type="button" style="color: var(--ds-blue, #2563eb); font-weight: 600;"
                                                    @click="openHighlighter({
                                                        documentId: {{ $document->id }},
                                                        firstPageUrl: {{ Js::from($highlightFirstUrl) }},
                                                        remainingPagesUrl: {{ Js::from($highlightRemainingUrl) }},
                                                        postUrl: {{ Js::from($highlightPostUrl) }},
                                                        label: {{ Js::from($document->original_name) }},
                                                    })"
                                                    x-text="activeDocId === {{ $document->id }} ? 'Close' : 'View & Mark Up'"></button>
                                        @else
                                            <span class="ds-badge ds-badge-default" title="This file type cannot be previewed on screen — download it to view it.">No preview</span>
                                        @endif
                                        @if($viewerRole === 'agent')
                                            {{-- AT-392 "pull from contact" — a referenced document
                                                 isn't owned by this application's own download route
                                                 (that route checks source_type/source_id ownership),
                                                 so it needs the separate referenced-download route. --}}
                                            <a href="{{ $row['pulled_from_contact'] ? route('corex.rental-applications.documents.referenced-download', [$rentalApplication, $document]) : route('corex.rental-applications.documents.download', [$rentalApplication, $document]) }}" style="color: var(--text-muted);">Download</a>
                                        @endif
                                        {{-- AT-392 — an untyped, unsplit PDF is exactly the
                                             "17-page scan with a bank statement buried in it"
                                             Johan described: worthless once it's filed. Split
                                             is offered per-document, at intake, so the agent can
                                             sort it the moment it lands rather than leaving it
                                             for submit time. Gated to PDFs only — the splitter
                                             engine rasterizes pages and has nothing to do with
                                             an already-single-purpose image/doc upload. Never
                                             offered on a referenced (pulled-from-contact) document
                                             — splitting it would archive a document this
                                             application doesn't own, filed against another context. --}}
                                        @if($viewerRole === 'agent' && !$row['pulled_from_contact'] && $document->document_type_id === null && $document->mime_type === 'application/pdf')
                                            {{-- Styled as a text button, matching "View & Mark Up" /
                                                 "Download" above — NOT ds-badge, which this row
                                                 already uses for genuine non-interactive status
                                                 ("Added after submission"). A clickable action must
                                                 look clickable; reusing badge styling here would
                                                 make a real action read as inert status text. --}}
                                            <form method="POST" action="{{ route('tools.pdf_splitter.intake_rental_application', [$rentalApplication, $document]) }}" style="display:inline;">
                                                @csrf
                                                <button type="submit" style="color: var(--ds-amber, #b45309); font-weight: 600; cursor: pointer; border: none; background: none; padding: 0;" title="This document hasn't been sorted into document types yet — split it into separate, filed documents before submitting for authorisation.">Split &amp; File</button>
                                            </form>
                                        @endif
                                    </span>
                                </div>

                                {{-- IN-PLACE viewer — a plain in-flow block inside this row,
                                     inside review-main's own independently-scrolling column.
                                     review-aside is a flex sibling that's never covered. The
                                     toolbar itself now lives in the sticky header above
                                     (x-if="activeDocId !== null") — shared for both roles. --}}
                                <div x-show="activeDocId === {{ $document->id }}" x-cloak class="px-3 pb-3 border-t" style="border-color: var(--border);">
                                    @include('corex.rental-applications.partials.document-highlighter-pages')
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if($viewerRole === 'agent')
                    {{-- Agent-added-documents, 2026-09-08 — Johan: "agent should
                         in any case be able to add docs as client can be in the
                         office so agent scans docs to themselves, or even
                         receive via whatsapp etc." Agent-only — an authoriser
                         adding new source documents to someone else's
                         application isn't part of what Johan asked for here. --}}
                    <div class="mt-3 pt-3" style="border-top: 1px solid var(--border);" x-data="agentDocumentUploadReview()">
                        <template x-for="u in uploading" :key="u.tempId">
                            <p class="text-xs mb-1" :class="u.error ? 'text-red-600' : ''" style="color: var(--text-muted);" x-text="u.error ? (u.name + ': ' + u.error) : ('Uploading ' + u.name + '…')"></p>
                        </template>
                        <label class="text-xs font-medium cursor-pointer" style="color: var(--brand-icon, #2563eb);">
                            + Add document
                            <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="hidden" @change="onFilesSelected($event.target.files); $event.target.value = ''">
                        </label>
                    </div>

                    @if($pickableContactDocuments->isNotEmpty())
                        {{-- AT-392 "pull from contact" — Johan: "the agent can
                             attach documents ALREADY ON FILE against the contact
                             to a new application, without the applicant
                             re-sending them." A plain disclosure, not a modal —
                             this list is usually short and doesn't warrant one. --}}
                        <details class="mt-2" x-data="attachExistingDocument()">
                            <summary class="text-xs font-medium cursor-pointer" style="color: var(--brand-icon, #2563eb);">
                                + Attach from contact's file ({{ $pickableContactDocuments->count() }})
                            </summary>
                            <p class="text-xs mt-2" x-show="status" x-text="status" :style="error ? 'color: var(--ds-red, #dc2626);' : 'color: var(--ds-emerald, #059669);'"></p>
                            <div class="mt-2 space-y-1">
                                @foreach($pickableContactDocuments as $existing)
                                    <div class="flex items-center justify-between text-xs py-1">
                                        <span>
                                            {{ $existing->original_name }}
                                            <span style="color: var(--text-muted);" title="{{ $existing->created_at->format('d M Y H:i') }}">— {{ $existing->created_at->diffForHumans() }}</span>
                                            @if($existing->documentType)
                                                <span class="ds-badge ds-badge-default">{{ $existing->documentType->label }}</span>
                                            @endif
                                            @if($pickableStaleness[$existing->id] ?? null)
                                                <span class="ds-badge ds-badge-warning" title="{{ $pickableStaleness[$existing->id] }}">{{ $pickableStaleness[$existing->id] }}</span>
                                            @endif
                                        </span>
                                        <button type="button" style="color: var(--brand-icon, #2563eb); font-weight: 600;"
                                                :disabled="submittingId === {{ $existing->id }}"
                                                @click="attach({{ $existing->id }})"
                                                x-text="submittingId === {{ $existing->id }} ? 'Attaching…' : 'Attach'"></button>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endif
                @endif
            </div>

            {{-- Audit Trail — conductor, 2026-09-08 (night run): moved here from
                 the authoriser-only screen, now visible to BOTH roles per
                 Johan's ruling ("VISIBLE for now... build it VISIBLE... an agent
                 seeing what happened to their own submission is a feature not a
                 leak"). Main column, below Supporting Documents — it's an
                 inherently growing list (why it's capped below), which fits the
                 main column's scrolling behaviour. Whether an authoriser's
                 decline/override REASONING specifically should stay visible to
                 the agent is still Johan's open question, separately — this
                 renders it visible for now, same as every other entry, and nothing
                 here needs to change if that answer comes back "no": the reason
                 line below is its own small, cleanly separable @if.

                 2026-09-09 — this whole card used to be wrapped in
                 @if($auditLog->isNotEmpty()), so a genuinely empty audit trail
                 (a clean application nobody has acted on yet, e.g. #12) rendered
                 NOTHING — indistinguishable from the section being missing
                 entirely. Johan: "An empty state that says so is the fix." The
                 card and its heading now always render; only the body inside
                 switches between the list and a plain-language empty state. --}}
            <div class="rounded-md p-4" x-data="{ showAllAudit: false }" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Audit Trail ({{ $auditLogTotal }})</h2>
                    @if($auditLog->count() > 10)
                        <button type="button" class="text-xs underline" style="color: var(--text-muted);"
                                @click="showAllAudit = !showAllAudit"
                                x-text="showAllAudit ? 'Show fewer' : 'Show all {{ $auditLog->count() }} entries'"></button>
                    @endif
                </div>
                @if($auditLog->isEmpty())
                    <p class="text-xs" style="color: var(--text-muted);">Nothing has happened on this application yet — no strikes, additions, links, or decisions have been recorded.</p>
                @else
                    <div class="space-y-2 text-xs">
                        @foreach($auditLog as $index => $entry)
                            <div class="pb-2" style="border-bottom: 1px solid var(--border);"
                                 @if($index >= 10) x-show="showAllAudit" x-cloak @endif>
                                <div style="color: var(--text-primary);">
                                    <strong>{{ $entry->actor_label ?? ($entry->user->name ?? 'System') }}</strong>
                                    &mdash; {{ $entry->human_summary ?? $entry->event_type }}
                                    @if($entry->is_override)
                                        <span class="ds-badge ds-badge-warning" title="This changed an existing decision.">OVERRIDE</span>
                                    @endif
                                    <span style="color: var(--text-muted);">({{ $entry->created_at->format('d M Y H:i') }})</span>
                                </div>
                                {{-- Reason line — separately callable-out per Johan's still-open
                                     question above; currently always shown. --}}
                                @if($entry->reason)
                                    <div class="mt-0.5 whitespace-pre-wrap" style="color: var(--text-secondary);">{{ $entry->reason }}</div>
                                @endif
                                {{-- 2026-09-09 (Johan) — a genuine web action must read as normal
                                     (no badge, no clutter). Anything the audit context stamped a
                                     non-null `source` on — console/tinker, a queued job, an import —
                                     is not a person clicking through CoreX, and an evidentiary trail
                                     must say so. Same collapsed-disclosure pattern as "Raw server
                                     response" on the mailbox diagnostics screen. --}}
                                @if($entry->source)
                                    <details class="mt-0.5">
                                        <summary class="text-xs cursor-pointer" style="color: var(--text-muted);" title="This entry was written by a script or command-line session, not a person clicking through CoreX.">Recorded outside the app</summary>
                                        <div class="mt-0.5 text-xs" style="color: var(--text-muted);">Raw source: {{ $entry->source }}</div>
                                    </details>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if($auditLogTotal > $auditLog->count())
                        <p class="mt-2 text-xs" style="color: var(--text-muted);">Showing the {{ $auditLog->count() }} most recent of {{ $auditLogTotal }} total entries.</p>
                    @endif
                @endif
            </div>
        </div>

        {{-- Draggable resizer, 2026-09-10 — see the "DRAGGABLE WIDTH" note on
             the layout <style> block above for why. Hidden below 1280px
             (the aside stacks full-width there, nothing to drag). --}}
        <div class="rental-review-resizer" :class="{ 'is-dragging': resizingAside }"
             @mousedown.prevent="startAsideResize($event)"
             title="Drag to resize this panel"></div>

        {{-- ASIDE — the assessment panel (role-gated: agent captures inline;
             authoriser strikes-and-adds, never edits) plus the role's own
             actions block at the bottom. Narrow working column, width
             draggable (see resizer above) — never covered by anything, see
             the in-place-annotation note above the layout <style> block.

             REDESIGNED 2026-09-10 ("Item 6", Johan, verbatim: "the right
             hand panel needs to be redesigned on rental for user and auth -
             look at the space available and then we go and put big boxes
             on there. rethink this please"). Root problem measured in a
             real browser on a real record (application 76, 6 income + 2
             expense lines): the aside was ONE undifferentiated card with
             every section separated only by a margin — no visual grouping
             at all, unlike .rental-review-main which is a plain space-y
             container of INDEPENDENTLY-boxed cards (Submitted Application /
             Supporting Documents / Audit Trail, each its own bordered
             surface). The aside now matches that exact pattern instead of
             inventing a new one — every logical group (statement period,
             income, expenses, unpaid flag, notes) is its own
             `rounded-md p-3` card, same tokens the main column's cards and
             this aside's own pre-existing "Qualifies for up to" box already
             use. This is "put big boxes on there" read literally: boxes
             sized to what they actually hold, not a wall of small text. --}}
        <div class="rental-review-aside space-y-3">
            <div class="flex items-center gap-1.5">
                <h2 class="text-sm font-semibold" style="color: var(--text-primary);">{{ $viewerRole === 'agent' ? 'Affordability Assessment' : "Agent's Assessment" }}</h2>
                @if($viewerRole === 'agent')
                    <span class="ds-badge ds-badge-muted" style="cursor: help; padding: 0 5px;"
                          title="You type these — nothing here is pre-filled from the application, and nothing here is sent to the applicant or shown anywhere else.">?</span>
                    <span class="ds-badge ds-badge-info flex-shrink-0 ml-auto" title="Every field below saves the moment you click away from it — no button needed.">Autosaves</span>
                @else
                    <span class="ds-badge ds-badge-muted" style="cursor: help; padding: 0 5px;"
                          title="Captured by the agent. You can add your own lines. If you disagree with a figure, strike it out — that opens a box to add the correct one right there. The struck line stays visible with what replaced it, never edited in place.">?</span>
                @endif
            </div>

            @if($viewerRole === 'agent')
                {{-- AGENT — inline edit + autosave. "Dates on entries" (Johan,
                     2026-09-10) — "the agent picks a from-date and a
                     to-date; the month count is calculated from them, not
                     typed." Replaces the old raw number input; the derived
                     count is shown read-only right below the two pickers so
                     the agent can see what the range works out to without
                     doing the arithmetic. --}}
                <div class="rounded-md p-3" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-center gap-1.5 mb-1">
                        <label class="text-xs font-medium" style="color: var(--text-secondary);">Statement period</label>
                        <span class="ds-badge ds-badge-muted" style="cursor: help; padding: 0 5px;"
                              title="The from/to dates this bank statement covers. The number of months is worked out from these — required to turn the totals below into the MONTHLY figure the affordability guideline actually runs against.">?</span>
                    </div>
                    <div class="grid grid-cols-2 gap-1.5">
                        <input type="date" class="corex-input text-sm w-full" x-model="statementPeriodFrom" @change="save()" aria-label="Statement period from">
                        <input type="date" class="corex-input text-sm w-full" x-model="statementPeriodTo" @change="save()" aria-label="Statement period to">
                    </div>
                    <p class="text-xs mt-1.5" style="color: var(--text-secondary);">
                        <template x-if="statementPeriodFrom && statementPeriodTo">
                            <span>Covers <strong x-text="calculatedStatementMonths()"></strong> month<span x-show="calculatedStatementMonths() !== 1">s</span></span>
                        </template>
                        <template x-if="!(statementPeriodFrom && statementPeriodTo) && statementMonths">
                            <span>Currently <strong x-text="statementMonths"></strong> month<span x-show="statementMonths !== 1">s</span> — pick a period above to set it from dates instead.</span>
                        </template>
                        <template x-if="!(statementPeriodFrom && statementPeriodTo) && !statementMonths">
                            <span>Pick both dates to work out how many months this statement covers.</span>
                        </template>
                    </p>
                </div>

                {{-- Income/expense rows — description 1.5fr, date a fixed
                     130px (enough for a full "06/24/2026" plus the native
                     picker icon, verified live — 118px still clipped the
                     last digit of the year), amount a REAL 100px floor
                     (raised from 78px, ROUND 3, 2026-09-10 below).
                     ROUND 3 correction — Johan, after round 2 fixed
                     description: "the AMOUNT column is now the clipped
                     one... a rand value silently missing its last digit is
                     the kind of thing that gets trusted and shouldn't be."
                     Round 2's own comment here previously called an
                     amount clipping its last digit an "accepted tradeoff"
                     — that was wrong, full stop, not a judgement call that
                     held up: a number an agent/authoriser reads as money
                     must never render incomplete, at any width this row
                     is asked to hold. 78px was contingent on leftover
                     flex space (not a real minimum); 100px is a hard floor,
                     checked against application 76's own largest captured
                     figures (R29,340.99 / R28,863.00, both 8 characters)
                     with headroom to 9 (R999,999.99). Description trimmed
                     1.6fr → 1.5fr to make room for amount's new floor
                     without re-widening the whole row — still comfortably
                     fits real single/double words at every width this row
                     is asked to hold. --}}
                <div class="rounded-md p-3" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-center gap-1.5 mb-1">
                        <span class="rounded-full flex-shrink-0" style="width: 9px; height: 9px; background: var(--ra-income-agent); border: 1px solid var(--ra-income-underline);"></span>
                        <label class="text-xs font-medium" style="color: var(--text-secondary);">Income (gross)</label>
                        <span class="ds-badge ds-badge-muted" style="cursor: help; padding: 0 5px;"
                              title="What's on the payslip / bank statement BEFORE tax and other deductions — not take-home pay. One line per deposit; pressing Enter in the amount field adds the next line.">?</span>
                    </div>
                    <div class="space-y-1.5" x-ref="incomeRows">
                        <template x-for="(item, index) in incomeItems" :key="index">
                            <div class="grid gap-1.5" style="grid-template-columns: minmax(0,1.5fr) 130px minmax(100px,1fr);">
                                <input type="text" class="corex-input text-sm w-full" placeholder="e.g. Salary"
                                       x-model="item.description" :title="item.description" @input="onIncomeRowInput()" @blur="save()">
                                <input type="date" class="corex-input text-sm w-full" title="Date this deposit happened"
                                       x-model="item.entry_date" @change="onIncomeRowInput(); save()">
                                <input type="text" inputmode="decimal" class="corex-input text-sm w-full" placeholder="0.00"
                                       data-role="amount" x-model="item.amount" :title="item.amount" @input="onIncomeRowInput()" @blur="save()"
                                       @keydown.enter.prevent="focusNextAmountRow('incomeRows', index)">
                            </div>
                        </template>
                    </div>
                    <p class="text-xs mt-1.5" style="color: var(--text-secondary);">
                        Total captured: <strong x-text="formatR(incomeTotal())"></strong>
                    </p>
                </div>

                <div class="rounded-md p-3" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-center gap-1.5 mb-1">
                        <span class="rounded-full flex-shrink-0" style="width: 9px; height: 9px; background: var(--ra-expense-agent); border: 1px solid var(--ra-expense-underline);"></span>
                        <label class="text-xs font-medium" style="color: var(--text-secondary);">Expenses / existing debt</label>
                        <span class="ds-badge ds-badge-muted" style="cursor: help; padding: 0 5px;"
                              title="Recurring debt/expense lines off the same bank statement — car payment, store account, and so on. For reference only; does not affect the qualifying figure below.">?</span>
                    </div>
                    <div class="space-y-1.5" x-ref="expenseRows">
                        <template x-for="(item, index) in expenseItems" :key="index">
                            <div class="grid gap-1.5" style="grid-template-columns: minmax(0,1.5fr) 130px minmax(100px,1fr);">
                                <input type="text" class="corex-input text-sm w-full" placeholder="e.g. Car payment"
                                       x-model="item.description" :title="item.description" @input="onExpenseRowInput()" @blur="save()">
                                <input type="date" class="corex-input text-sm w-full" title="Date this debit happened"
                                       x-model="item.entry_date" @change="onExpenseRowInput(); save()">
                                <input type="text" inputmode="decimal" class="corex-input text-sm w-full" placeholder="0.00"
                                       data-role="amount" x-model="item.amount" :title="item.amount" @input="onExpenseRowInput()" @blur="save()"
                                       @keydown.enter.prevent="focusNextAmountRow('expenseRows', index)">
                            </div>
                        </template>
                    </div>
                    <p class="text-xs mt-1.5" style="color: var(--text-secondary);">
                        Total captured: <strong x-text="formatR(expenseTotal())"></strong>
                    </p>
                </div>

                <div class="rounded-md p-3" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-center gap-1.5 mb-1">
                        <span class="rounded-full flex-shrink-0" style="width: 9px; height: 9px; background: var(--ra-unpaid-agent); border: 1px solid var(--ra-unpaid-underline);"></span>
                        <label class="text-xs font-medium" style="color: var(--text-secondary);">Unpaid transactions</label>
                        <span class="ds-badge ds-badge-muted" style="cursor: help; padding: 0 5px;"
                              title="Tick this if the bank statement shows any declined, unpaid, or returned transactions. An applicant with declined transactions is generally an immediate decline — this is the one-glance red flag the authoriser sees.">?</span>
                    </div>
                    <label class="flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-secondary);">
                        <input type="checkbox" x-model="hasUnpaidTransactions" @change="save()">
                        Unpaid transactions on statement
                    </label>
                </div>

                <div class="rounded-md p-3" style="background: var(--surface-2, #f9fafb); border: 1px solid var(--border);">
                    <template x-if="result.label === 'incomplete'">
                        <p class="text-xs" style="color: var(--text-muted);">Capture income above and the number of months covered to see what the applicant qualifies for.</p>
                    </template>
                    <template x-if="result.label !== 'incomplete'">
                        <div>
                            <p class="text-[11px] uppercase tracking-wide font-semibold" style="color: var(--text-muted);">Qualifies for up to</p>
                            <p class="text-2xl font-bold" style="color: var(--text-primary);" x-text="formatR(result.max_affordable_rent)"></p>
                            <p class="text-[11px] mt-0.5" style="color: var(--text-muted);">
                                Monthly gross income — from figures the agent captured off the bank statement, ÷ <span x-text="result.statement_months"></span> months —
                                × <span x-text="result.max_rent_percent"></span>%
                            </p>
                            <p class="text-xs mt-1.5" x-show="result.applicant_reported_income !== null" style="color: var(--text-muted);">
                                Applicant's stated income — as entered by them on their application: <span x-text="formatR(result.applicant_reported_income)"></span>
                            </p>
                            <template x-if="result.label === 'no_property'">
                                <p class="text-xs mt-2 font-medium" style="color: var(--ds-amber, #b45309);">Link a property to check against its rent.</p>
                            </template>
                            <template x-if="result.label === 'sufficient' || result.label === 'insufficient'">
                                <x-rental-application-affordability-verdict
                                    met-expr="result.label === 'sufficient'"
                                    detail-expr="'Property rent: <strong>' + formatR(result.rent) + '</strong>'" />
                            </template>
                        </div>
                    </template>
                </div>

                <div class="rounded-md p-3" style="background: var(--surface); border: 1px solid var(--border);">
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Notes</label>
                    <textarea rows="5" class="corex-input text-sm w-full" style="resize: none; overflow-y: auto;"
                              x-model="fields.notes" x-init="autoGrowTextarea($el)" @input="autoGrowTextarea($el)" @blur="save()"></textarea>
                </div>

                <div class="flex items-center gap-1.5 text-xs px-2 py-1 rounded-md" x-show="saveStatus"
                     :style="saveError ? 'background: var(--ds-red-soft, #fef2f2); color: var(--ds-red, #dc2626);' : 'background: var(--ds-emerald-soft, #ecfdf5); color: var(--ds-emerald, #059669);'">
                    <span x-show="!saveError && saveStatus !== 'Saving…'">&check;</span>
                    <span x-text="saveStatus"></span>
                </div>
            @else
                {{-- AUTHORISER — read-only agent values, strike-and-add only, never
                     edit. Nested x-data scope (rentalAssessmentEditor), independent
                     of the root rentalAuthorisationViewer() component, exactly as
                     before the merge — the highlighter's marks are the root
                     component's concern; the assessment items are this nested
                     scope's. Unchanged from before the merge except its position
                     on the page (now the shared aside, not a second blade's own
                     main column). Boxed 2026-09-10 ("Item 6") — same
                     rationale as the agent variant above: every section its
                     own card, matching .rental-review-main's existing
                     pattern instead of one continuous unbroken column. --}}
                <div x-data="rentalAssessmentEditor({
                         currentUserId: {{ Js::from(auth()->id()) }},
                         incomeItems: {{ Js::from($serializedIncomeItems) }},
                         expenseItems: {{ Js::from($serializedExpenseItems) }},
                         addIncomeUrl: {{ Js::from(route('corex.rental-applications.authorisation.assessment.income-items.store', $rentalApplication)) }},
                         addExpenseUrl: {{ Js::from(route('corex.rental-applications.authorisation.assessment.expense-items.store', $rentalApplication)) }},
                         incomeItemUrl: {{ Js::from(url('corex/rental-applications/authorisation/' . $rentalApplication->id . '/assessment/income-items')) }},
                         expenseItemUrl: {{ Js::from(url('corex/rental-applications/authorisation/' . $rentalApplication->id . '/assessment/expense-items')) }},
                         statementMonths: {{ Js::from($assessment->statement_months) }},
                         maxRentPercent: {{ Js::from($maxRentPercent) }},
                         rent: {{ Js::from($result['rent'] ?? null) }},
                         propertyLinked: {{ Js::from((bool) ($result['property_linked'] ?? false)) }},
                         hasUnpaidTransactions: {{ Js::from((bool) $assessment->has_unpaid_transactions) }},
                     })" class="space-y-3">
                    <div class="text-xs rounded-md p-3" style="background: var(--surface); border: 1px solid var(--border);">
                        <p style="color: var(--text-muted);">Number of months this bank statement covers</p>
                        <p class="font-semibold" style="color: var(--text-primary);">{{ $assessment->statement_months ?? '—' }}</p>
                        @if($assessment->statement_period_from && $assessment->statement_period_to)
                            <p style="color: var(--text-muted);">{{ $assessment->statement_period_from->format('d M Y') }} &ndash; {{ $assessment->statement_period_to->format('d M Y') }}</p>
                        @endif
                    </div>

                    <div class="text-xs rounded-md p-3" style="background: var(--surface); border: 1px solid var(--border);">
                        <div class="flex items-center gap-1.5 mb-1">
                            <span class="rounded-full flex-shrink-0" style="width: 9px; height: 9px; background: var(--ra-income-authoriser); border: 1px solid var(--ra-income-underline);"></span>
                            <p class="font-medium" style="color: var(--text-secondary);">Income (gross, before deductions)</p>
                        </div>
                        <template x-if="incomeItems.length === 0"><p style="color: var(--text-muted);">Nothing captured yet.</p></template>
                        <template x-for="item in incomeItems" :key="item.id">
                            <div>
                                <div class="grid grid-cols-2 gap-1.5 py-1 items-center" :style="{ opacity: item.struck_out ? '0.55' : '1' }">
                                    <span class="flex items-center gap-1.5 min-w-0">
                                        <span class="rounded-full flex-shrink-0" style="width: 7px; height: 7px;" :style="{ background: item.added_by_authoriser ? 'var(--ra-income-authoriser)' : 'var(--ra-income-agent)' }"></span>
                                        <span x-show="item.added_by_authoriser" class="ds-badge ds-badge-info flex-shrink-0" style="font-size:9px; padding:1px 4px;" title="Added by a reviewer/authoriser, not the agent">Auth</span>
                                        <span :style="{ textDecoration: item.struck_out ? 'line-through' : 'none' }" class="truncate" style="color: var(--text-primary);" :title="item.description" x-text="item.description || '(no description)'"></span>
                                        <span x-show="item.entry_date" class="flex-shrink-0" style="color: var(--text-muted); font-size:10px;" x-text="item.entry_date"></span>
                                    </span>
                                    <span class="flex items-center justify-end gap-2 flex-shrink-0">
                                        <span :style="{ textDecoration: item.struck_out ? 'line-through' : 'none' }" style="color: var(--text-primary);" x-text="'R ' + formatAmount(item.amount)"></span>
                                        <button type="button" class="text-xs" :style="{ color: item.struck_out ? 'var(--ds-emerald, #059669)' : 'var(--ds-crimson, #dc2626)' }" @click="toggleStrike('income', item)" x-text="item.struck_out ? 'Restore' : 'Strike out'"></button>
                                    </span>
                                </div>
                                <p class="text-[11px] pl-3" style="color: var(--text-muted);" x-show="item.struck_out" x-text="struckLine(item)"></p>
                                <div class="flex items-center gap-2 pl-3 py-1.5" x-show="replacingItem === ('income-' + item.id)" x-cloak>
                                    <input type="text" x-model="replaceDescription" placeholder="Description" class="corex-input text-xs" style="flex:1;" :data-replace-focus="'income-' + item.id">
                                    <input type="date" x-model="replaceDate" class="corex-input text-xs" style="width:120px;" title="Date this deposit happened">
                                    <input type="text" inputmode="decimal" x-model="replaceAmount" placeholder="0.00" class="corex-input text-xs" style="width:80px;">
                                    <button type="button" class="text-xs font-semibold flex-shrink-0" style="color: var(--ds-blue, #2563eb);" :disabled="!replaceAmount" @click="addItem('income', item.id)">Add replacement</button>
                                    <button type="button" class="text-xs" style="color: var(--text-muted);" @click="replacingItem = null">Cancel</button>
                                </div>
                            </div>
                        </template>
                        <div class="flex items-center gap-2 pt-2 mt-1" style="border-top: 1px dashed var(--border);">
                            <input type="text" x-model="newIncomeDescription" placeholder="Description" class="corex-input text-xs" style="flex:1;">
                            <input type="date" x-model="newIncomeDate" class="corex-input text-xs" style="width:120px;" title="Date this deposit happened">
                            <input type="text" inputmode="decimal" x-model="newIncomeAmount" placeholder="0.00" class="corex-input text-xs" style="width:80px;">
                            <button type="button" class="text-xs font-semibold flex-shrink-0" style="color: var(--ds-blue, #2563eb);" :disabled="!newIncomeAmount" @click="addItem('income')">+ Add</button>
                        </div>
                        <p class="mt-2" style="color: var(--text-secondary);">Total (struck-out lines excluded): <strong x-text="'R ' + formatAmount(incomeTotal())"></strong></p>
                        <p style="color: var(--text-secondary);" x-show="statementMonths">Monthly average (÷ <span x-text="statementMonths"></span> months — used in the affordability check below): <strong x-text="'R ' + formatAmount(grossIncome())"></strong></p>
                    </div>

                    <div class="text-xs rounded-md p-3" style="background: var(--surface); border: 1px solid var(--border);">
                        <div class="flex items-center gap-1.5 mb-1">
                            <span class="rounded-full flex-shrink-0" style="width: 9px; height: 9px; background: var(--ra-expense-authoriser); border: 1px solid var(--ra-expense-underline);"></span>
                            <p class="font-medium" style="color: var(--text-secondary);">Expenses / existing debt</p>
                        </div>
                        <template x-if="expenseItems.length === 0"><p style="color: var(--text-muted);">Nothing captured.</p></template>
                        <template x-for="item in expenseItems" :key="item.id">
                            <div>
                                <div class="grid grid-cols-2 gap-1.5 py-1 items-center" :style="{ opacity: item.struck_out ? '0.55' : '1' }">
                                    <span class="flex items-center gap-1.5 min-w-0">
                                        <span class="rounded-full flex-shrink-0" style="width: 7px; height: 7px;" :style="{ background: item.added_by_authoriser ? 'var(--ra-expense-authoriser)' : 'var(--ra-expense-agent)' }"></span>
                                        <span x-show="item.added_by_authoriser" class="ds-badge ds-badge-info flex-shrink-0" style="font-size:9px; padding:1px 4px;" title="Added by a reviewer/authoriser, not the agent">Auth</span>
                                        <span :style="{ textDecoration: item.struck_out ? 'line-through' : 'none' }" class="truncate" style="color: var(--text-primary);" :title="item.description" x-text="item.description || '(no description)'"></span>
                                        <span x-show="item.entry_date" class="flex-shrink-0" style="color: var(--text-muted); font-size:10px;" x-text="item.entry_date"></span>
                                    </span>
                                    <span class="flex items-center justify-end gap-2 flex-shrink-0">
                                        <span :style="{ textDecoration: item.struck_out ? 'line-through' : 'none' }" style="color: var(--text-primary);" x-text="'R ' + formatAmount(item.amount)"></span>
                                        <button type="button" class="text-xs" :style="{ color: item.struck_out ? 'var(--ds-emerald, #059669)' : 'var(--ds-crimson, #dc2626)' }" @click="toggleStrike('expense', item)" x-text="item.struck_out ? 'Restore' : 'Strike out'"></button>
                                    </span>
                                </div>
                                <p class="text-[11px] pl-3" style="color: var(--text-muted);" x-show="item.struck_out" x-text="struckLine(item)"></p>
                                <div class="flex items-center gap-2 pl-3 py-1.5" x-show="replacingItem === ('expense-' + item.id)" x-cloak>
                                    <input type="text" x-model="replaceDescription" placeholder="Description" class="corex-input text-xs" style="flex:1;" :data-replace-focus="'expense-' + item.id">
                                    <input type="date" x-model="replaceDate" class="corex-input text-xs" style="width:120px;" title="Date this debit happened">
                                    <input type="text" inputmode="decimal" x-model="replaceAmount" placeholder="0.00" class="corex-input text-xs" style="width:80px;">
                                    <button type="button" class="text-xs font-semibold flex-shrink-0" style="color: var(--ds-blue, #2563eb);" :disabled="!replaceAmount" @click="addItem('expense', item.id)">Add replacement</button>
                                    <button type="button" class="text-xs" style="color: var(--text-muted);" @click="replacingItem = null">Cancel</button>
                                </div>
                            </div>
                        </template>
                        <div class="flex items-center gap-2 pt-2 mt-1" style="border-top: 1px dashed var(--border);">
                            <input type="text" x-model="newExpenseDescription" placeholder="Description" class="corex-input text-xs" style="flex:1;">
                            <input type="date" x-model="newExpenseDate" class="corex-input text-xs" style="width:120px;" title="Date this debit happened">
                            <input type="text" inputmode="decimal" x-model="newExpenseAmount" placeholder="0.00" class="corex-input text-xs" style="width:80px;">
                            <button type="button" class="text-xs font-semibold flex-shrink-0" style="color: var(--ds-blue, #2563eb);" :disabled="!newExpenseAmount" @click="addItem('expense')">+ Add</button>
                        </div>
                        <p class="mt-2" style="color: var(--text-secondary);">Total (struck-out lines excluded): <strong x-text="'R ' + formatAmount(expenseTotal())"></strong></p>
                    </div>

                    <div class="flex items-center gap-1.5 rounded-md p-3" x-show="hasUnpaidTransactions" style="background: var(--ds-crimson-soft, #fef2f2); border: 1px solid var(--ds-crimson, #dc2626);">
                        <span class="rounded-full flex-shrink-0" style="width: 9px; height: 9px; background: var(--ra-unpaid-authoriser); border: 1px solid var(--ra-unpaid-underline);"></span>
                        <span class="text-xs font-semibold" style="color: var(--ds-crimson, #dc2626);">Agent flagged unpaid/declined transactions on the bank statement</span>
                    </div>

                    <div x-show="itemError" x-cloak class="text-xs rounded-md px-2 py-1.5" style="background: var(--ds-crimson-soft, #fef2f2); color: var(--ds-crimson, #dc2626);" x-text="itemError"></div>

                    <template x-if="statementMonths && incomeTotal() > 0">
                        <div class="rounded-md p-3" style="background: var(--surface-2, #f9fafb); border: 1px solid var(--border);">
                            <p class="text-[11px] font-semibold uppercase tracking-wide mb-1" style="color: var(--text-muted);">Suggested check — not a rule</p>
                            <p class="text-sm">
                                Gross income <span x-text="'R' + formatAmount(grossIncome())"></span> — rent must not exceed <span x-text="trimPercent(maxRentPercent)"></span>% of this (<span x-text="'R' + formatAmount(maxAffordableRent())"></span>).
                                <template x-if="!propertyLinked">
                                    <span class="font-medium" style="color: var(--ds-amber, #b45309);"> Link a property to check against its rent.</span>
                                </template>
                            </p>
                            <template x-if="propertyLinked && rent !== null">
                                <x-rental-application-affordability-verdict
                                    met-expr="meetsThreshold()"
                                    detail-expr="'Actual rent (' + 'R' + formatAmount(rent) + ') is ' + rentAsPercent() + '% of gross income.'" />
                            </template>
                        </div>
                    </template>
                    <template x-if="!(statementMonths && incomeTotal() > 0)">
                        <div class="rounded-md p-3 text-xs" style="background: var(--surface-2, #f9fafb); border: 1px solid var(--border); color: var(--text-muted);">
                            Not enough captured yet to run the affordability guideline (needs both income and the number of months).
                        </div>
                    </template>
                    @if($assessment->notes)
                        <div class="rounded-md p-3" style="background: var(--surface); border: 1px solid var(--border);">
                            <p class="text-xs font-medium mb-1" style="color: var(--text-secondary);">Agent's notes</p>
                            <p class="text-xs whitespace-pre-wrap" style="color: var(--text-primary);">{{ $assessment->notes }}</p>
                        </div>
                    @endif
                </div>
            @endif

            {{-- ACTIONS — role-gated, at the bottom of the shared aside per
                 Johan's instruction ("belongs at the bottom of the right panel").
                 Agent: submit/reopen/request-info-to-applicant, unchanged.
                 Authoriser: self-approval explanation or Approve/Decline/
                 Request-More-Info, unchanged. --}}
            <div class="rounded-md p-3" style="background: var(--surface); border: 1px solid var(--border);">
                @if($viewerRole === 'agent')
                    @if($moreInfoRequestedNote)
                        <div class="rounded-md px-3 py-2 text-xs mb-3" style="background: var(--surface-2); color: var(--ds-amber); border: 1px solid var(--ds-amber);">
                            <strong>The authoriser sent this back for more information:</strong>
                            <div class="mt-1 whitespace-pre-wrap" style="color: var(--text-primary);">{{ $moreInfoRequestedNote }}</div>
                        </div>
                    @endif
                    @if($rentalApplication->status === 'approved' && $rentalApplication->applicant_notified_at)
                        <div class="rounded-md px-3 py-2 text-xs mb-3" style="background: var(--ds-emerald-soft, #ecfdf5); color: var(--ds-emerald, #059669);">
                            &check; Approved for R{{ number_format($rentalApplication->approved_rental_amount, 2) }} a month. Sent to the applicant on {{ $rentalApplication->applicant_notified_at->format('d M Y, H:i') }}.
                        </div>
                    @elseif($rentalApplication->status === 'approved')
                        {{-- AT-392 — Johan: "agent gets back and upon them being happy
                             it gets sent out." Approved but not yet sent: the agent
                             confirms/refines the tenant's wishlist (reusing the SAME
                             Core Matches form + drawer as the Buyer Pipeline detail
                             page — command-center/buyers/detail.blade.php:580-615,
                             not a second editor), then sends. --}}
                        <div class="rounded-md px-3 py-3 text-xs mb-3" style="background: var(--ds-amber-soft, #fffbeb); color: var(--ds-amber, #b45309); border: 1px solid var(--ds-amber, #f59e0b);">
                            <p class="font-semibold mb-2">&check; Approved for R{{ number_format($rentalApplication->approved_rental_amount, 2) }} a month — not yet sent.</p>
                            <p class="mb-3">Confirm what the tenant is looking for, then send the approval. If you skip this, the email still goes out with a general list of available rentals under their approved amount.</p>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" @click="wishlistDrawerOpen = true" class="corex-btn-outline text-xs">
                                    {{ $existingWishlist ? 'Review tenant wishlist' : 'Add tenant wishlist' }}
                                </button>
                                <form method="POST" action="{{ route('corex.rental-applications.review.send', $rentalApplication) }}">
                                    @csrf
                                    <button type="submit" class="corex-btn-primary text-xs">Send approval to applicant</button>
                                </form>
                            </div>
                        </div>
                    @elseif($rentalApplication->status === 'declined')
                        <div class="rounded-md px-3 py-2 text-xs mb-3" style="background: var(--ds-red-soft, #fef2f2); color: var(--ds-red, #dc2626);">
                            Declined. The applicant has been notified.
                        </div>
                    @elseif($isPendingAuthorisation)
                        <div class="rounded-md px-3 py-2 text-xs mb-3" style="background: var(--ds-blue-soft, #eff6ff); color: var(--ds-blue, #2563eb);">
                            Submitted for approval {{ $rentalApplication->submitted_for_approval_at->format('d M Y H:i') }} — awaiting the authoriser's decision.
                        </div>
                    @endif

                    {{-- 2026-09-09 — Johan approved reopening a DECLINED
                         application too, but only for the rental-application
                         override tier (a configured CO, or admin/super_admin
                         — User::isRentalApplicationOverrideTier(), the real
                         gate enforced server-side in reopen() itself). This
                         is a courtesy hide only: an ordinary agent viewing a
                         declined application simply doesn't see the control,
                         rather than seeing it and hitting a 403 — the button
                         being absent here changes nothing about what the
                         server will accept from a crafted request. --}}
                    @php
                        $canReopenNow = in_array($rentalApplication->status, \App\Models\RentalApplication::REOPENABLE_STATUSES, true)
                            && ($rentalApplication->status !== 'declined' || auth()->user()->isRentalApplicationOverrideTier((int) $rentalApplication->agency_id));
                    @endphp
                    @if($canReopenNow)
                        <p class="text-xs font-semibold uppercase tracking-wide mb-2 mt-4" style="color: var(--text-muted);">Reopen for the applicant</p>
                        <p class="text-xs mb-2" style="color: var(--text-muted);">
                            Sends the applicant a link to fix an answer and re-sign. Their previous answers stay pre-filled — they only edit what's wrong. The signed submission on file now is kept, unchanged, as a separate record.
                        </p>
                        <textarea x-model="reopenNote" rows="3" class="corex-input text-xs w-full mb-2" style="resize: none;"
                                  placeholder="What do they need to fix? e.g. ID number was typed incorrectly"></textarea>
                        <button type="button" class="corex-btn-outline text-xs w-full" :disabled="reopenSending || !reopenNote.trim()" @click="reopenApplication()" x-text="reopenSending ? 'Sending…' : 'Reopen for applicant'"></button>
                    @endif

                    @if($rentalApplication->generations->count() > 1)
                        <p class="text-xs font-semibold uppercase tracking-wide mb-2 mt-4" style="color: var(--text-muted);">Submission history</p>
                        <div class="space-y-1 mb-2">
                            @foreach($rentalApplication->generations as $gen)
                                <a href="{{ route('corex.rental-applications.generations.show', [$rentalApplication, $gen->generation]) }}" class="block text-xs underline" style="color: var(--ds-blue, #2563eb);">
                                    Submission {{ $gen->generation }} — {{ $gen->submitted_at->format('d M Y, H:i') }}
                                    @if($gen->generation === $rentalApplication->current_generation) (current) @endif
                                </a>
                            @endforeach
                        </div>
                    @endif

                    @unless(in_array($rentalApplication->status, ['approved', 'declined'], true))
                        <p class="text-xs font-semibold uppercase tracking-wide mb-2" style="color: var(--text-muted);">Request more information</p>
                        <textarea x-model="moreInfoNote" rows="7" class="corex-input text-xs w-full mb-2" style="resize: none; overflow-y: auto;"
                                  x-init="autoGrowTextarea($el)" @input="autoGrowTextarea($el)"
                                  placeholder="What do you need from the applicant? e.g.&#10;1. Three months' bank statements&#10;2. Payslip for August&#10;3. Proof of the R12,000 deposit on 14 August"></textarea>
                        <button type="button" class="corex-btn-outline text-xs w-full" :disabled="moreInfoSending || !moreInfoNote.trim()" @click="requestMoreInfo()" x-text="moreInfoSending ? 'Sending…' : 'Send to applicant'"></button>
                        <p class="text-xs mt-2" x-show="agentActionStatus" x-text="agentActionStatus" :style="agentActionError ? 'color: var(--ds-red, #dc2626);' : 'color: var(--ds-emerald, #059669);'"></p>
                    @endunless
                @else
                    <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Decision</h2>
                    @if($blockedBySelfApproval)
                        <div class="rounded-md p-3 text-xs" style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--ds-amber, #f59e0b);">
                            <strong>You created this application, so it needs another authoriser.</strong>
                            <p class="mt-1" style="color: var(--text-muted);">Only a CO (Override) user or an administrator may approve, decline, or request more information on an application they created themselves. Ask another authoriser to act on this one.</p>
                        </div>
                    @else
                        <p class="text-xs mb-3" style="color: var(--text-muted);">
                            @if($alreadyDecided && $canOverride)
                                Acting below overrides the existing decision — a reason is required.
                            @else
                                Approve, decline, or ask the agent for more information.
                            @endif
                        </p>

                        <div class="rounded-md p-3 mb-3" style="border: 1px solid var(--border);">
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Approve — monthly amount</label>
                            <input type="text" inputmode="decimal" x-model="approveAmount" class="corex-input text-sm w-full mb-2" placeholder="0.00">
                            <textarea x-model="approveReason" rows="2" class="corex-input text-xs w-full mb-2" placeholder="{{ $alreadyDecided ? 'Reason for override (required)' : 'Notes (optional)' }}"></textarea>
                            {{-- AT-401 — Johan: "the decision buttons should save the
                                 work and the only modal here is a confirmation."
                                 One plain-language confirm naming the decision and
                                 the amount; Cancel stops the submit entirely. On
                                 confirm, __raSuppressUnloadGuard is set BEFORE the
                                 POST goes through so the highlighter's own unsaved-
                                 marks warning (document-highlighter-script.blade.php)
                                 never fires on this deliberate navigation. --}}
                            <form method="POST" action="{{ route('corex.rental-applications.authorisation.approve', $rentalApplication) }}"
                                  @submit="if (!confirm('Approve this tenant for R' + Number(approveAmount || 0).toLocaleString('en-ZA', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '?')) { $event.preventDefault(); return; } window.__raSuppressUnloadGuard = true; $refs.approveAmountField.value = approveAmount; $refs.approveReasonField.value = approveReason">
                                @csrf
                                <input type="hidden" name="approved_rental_amount" x-ref="approveAmountField">
                                <input type="hidden" name="reason" x-ref="approveReasonField">
                                <button type="submit" class="corex-btn-primary text-xs w-full" :disabled="!approveAmount">Approve</button>
                            </form>
                        </div>

                        <div class="rounded-md p-3 mb-3" style="border: 1px solid var(--border);">
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Decline</label>
                            {{-- Johan, 2026-09-09, verbatim: "the auth needs to report
                                 back to the agent why the application has been
                                 rejected." A decline with no reason tells the agent
                                 nothing — required on every decline now, not just an
                                 override, server-enforced (guardCanDecide()'s caller
                                 now validates 'reason' => 'required' unconditionally)
                                 and reflected here so the button can't even be
                                 clicked without one. --}}
                            <textarea x-model="declineReason" rows="2" class="corex-input text-xs w-full mb-2" placeholder="Reason for decline (required) — the agent will see this"></textarea>
                            {{-- AT-401 — same plain-confirm + unload-guard-suppress
                                 pattern as the Approve form above. --}}
                            <form method="POST" action="{{ route('corex.rental-applications.authorisation.decline', $rentalApplication) }}"
                                  @submit="if (!confirm('Decline this application?')) { $event.preventDefault(); return; } window.__raSuppressUnloadGuard = true; $refs.declineReasonField.value = declineReason">
                                @csrf
                                <input type="hidden" name="reason" x-ref="declineReasonField">
                                <button type="submit" class="corex-btn-outline text-xs w-full" style="color: var(--ds-red, #dc2626); border-color: var(--ds-red, #dc2626);" :disabled="!declineReason.trim()">Decline</button>
                            </form>
                        </div>

                        @unless($alreadyDecided)
                            <div class="rounded-md p-3" style="border: 1px solid var(--border);">
                                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Request more information</label>
                                <p class="text-xs mb-2" style="color: var(--text-muted);">Sends this back to the agent, not the applicant.</p>
                                <textarea x-model="moreInfoReason" rows="2" class="corex-input text-xs w-full mb-2" placeholder="What's missing? (required)"></textarea>
                                {{-- AT-401 — same pattern; this is a decision button too. --}}
                                <form method="POST" action="{{ route('corex.rental-applications.authorisation.request-more-info', $rentalApplication) }}"
                                      @submit="if (!confirm('Send this back to the agent for more information?')) { $event.preventDefault(); return; } window.__raSuppressUnloadGuard = true; $refs.moreInfoReasonField.value = moreInfoReason">
                                    @csrf
                                    <input type="hidden" name="reason" x-ref="moreInfoReasonField">
                                    <button type="submit" class="corex-btn-outline text-xs w-full" :disabled="!moreInfoReason.trim()">Request More Information</button>
                                </form>
                            </div>
                        @endunless
                    @endif
                @endif
            </div>
        </div>

        {{-- AT-392 — Tenant Wishlist drawer, relocated here (root cause of the
             cut-off/sliced-behind-the-header bug reported on QA1: this drawer
             used to live nested inside .rental-review-aside above, a
             position:sticky/overflow-y:auto panel — its own position:fixed
             overlay shared z-50 with the page's sticky action bar
             (sticky-action-bar.blade.php), an exact collision, not a value
             tweak away. As a direct sibling of .rental-review-main/.rental-review-aside,
             still inside .rental-review-columns' x-data="rentalReviewLayout()"
             scope (wishlistDrawerOpen lives there now, not on a local x-data,
             so the trigger button above and this drawer share the same state
             even though they're no longer DOM-nested), it is no longer inside
             any overflow/sticky ancestor, and z-[100] sits unambiguously above
             both the sticky header (z-50) and the app shell's own persistent
             chrome (z-40/z-50). Same pattern as
             command-center/buyers/detail.blade.php:583-616 otherwise. --}}
        @if($rentalApplication->status === 'approved' && !$rentalApplication->applicant_notified_at)
            <div x-show="wishlistDrawerOpen" x-cloak
                 class="fixed inset-0 z-[100] flex justify-end"
                 style="background: rgba(0,0,0,0.5);"
                 @keydown.escape.window="wishlistDrawerOpen = false">
                <div class="h-full overflow-y-auto p-6 w-full max-w-3xl text-left"
                     style="background: var(--surface); border-left: 1px solid var(--border); color: var(--text-primary);"
                     @click.outside="wishlistDrawerOpen = false">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold" style="color: var(--text-primary);">
                            {{ $existingWishlist ? 'Edit Tenant Wishlist' : 'New Tenant Wishlist' }}
                        </h2>
                        <button type="button" @click="wishlistDrawerOpen = false"
                                class="text-xl leading-none px-2 py-0" style="color: var(--text-muted); background: none; border: none; cursor: pointer;">&times;</button>
                    </div>
                    @include('corex.contacts._match-form', [
                        'contact' => $rentalApplication->contact,
                        'match' => $existingWishlist,
                        'defaultListingType' => 'rental',
                        'lockListingType' => true,
                        'rentalPropertyTypeNames' => $rentalPropertyTypeNames,
                        'prefill' => $wishlistPrefill,
                        'approvedRentalAmount' => $rentalApplication->approved_rental_amount,
                        'formAction' => $existingWishlist
                            ? route('corex.rental-applications.review.wishlist.update', [$rentalApplication, $existingWishlist])
                            : route('corex.rental-applications.review.wishlist.add', $rentalApplication),
                    ])
                </div>
            </div>
        @endif
    </div>
</div>

@include('corex.rental-applications.partials.document-highlighter-script')

<script>
function rentalReview({ saveUrl, initial, initialIncomeItems, initialExpenseItems, initialResult, initialSavedAt, initialMarkedUpDocIds, currentUserId, currentUserName, currentUserRole, highlighters, requestMoreInfoUrl, submitForApprovalUrl, reopenUrl, expectedGeneration }) {
    return {
        // 2026-09-08 — the highlight/note viewer state+methods (activeDocId,
        // pages, marks, openHighlighter()/applyHighlights()/etc.) now live in
        // the shared rentalDocumentHighlighter() factory (see
        // partials/document-highlighter-script.blade.php, included below)
        // — the authoriser screen spreads the same factory in rather than
        // this logic being copy-pasted a second time.
        ...rentalDocumentHighlighter({ initialMarkedUpDocIds, currentUserId, currentUserName, currentUserRole, highlighters }),

        // 2026-09-08 — Johan: "clicking back to application shows a changes
        // may be lost popup but there's no save button visible anywhere." No
        // beforeunload guard existed at all, so that warning (wherever it
        // came from) was never paired with a real, reachable way to act on
        // it. This is the real pairing: the SAME native browser warning,
        // wired to the highlighter's own `dirty` flag, with the sticky
        // header's Save button (see (g) above) visible the entire time the
        // warning could possibly fire — never one without the other.
        init() {
            this.initHighlighterPrefs();
            this.compactAndEnsureTrailing(this.incomeItems);
            this.compactAndEnsureTrailing(this.expenseItems);
        },
        // ── Income/expense line items — Round 9 (item 5). Johan: "filling
        // the last row auto-adds a fresh empty one, income and expenses
        // both, total recalculating live." A row carries an `id` once the
        // server has persisted it (used to match it on the next autosave,
        // never re-created); a row typed fresh has no `id` yet. ──────────
        incomeItems: (initialIncomeItems && initialIncomeItems.length) ? initialIncomeItems : [{ id: null, description: '', amount: '', entry_date: '' }],
        expenseItems: (initialExpenseItems && initialExpenseItems.length) ? initialExpenseItems : [{ id: null, description: '', amount: '', entry_date: '' }],
        rowIsBlank(row) {
            return (!row.description || !row.description.trim()) && (row.amount === '' || row.amount === null || row.amount === undefined);
        },
        // Removes any blank row that isn't the last one (how an agent
        // "deletes" a row — clear both its fields), then guarantees exactly
        // one blank trailing row is always available to type into. This
        // fires on every keystroke (via @input) — it must NEVER also move
        // focus, or it re-fires on every character once the new row itself
        // starts filling (see the 2026-09-08 postmortem below).
        compactAndEnsureTrailing(list) {
            for (let i = list.length - 2; i >= 0; i--) {
                if (this.rowIsBlank(list[i])) list.splice(i, 1);
            }
            if (!list.length || !this.rowIsBlank(list[list.length - 1])) {
                list.push({ id: null, description: '', amount: '', entry_date: '' });
            }
        },
        onIncomeRowInput() {
            this.compactAndEnsureTrailing(this.incomeItems);
            this.save();
        },
        onExpenseRowInput() {
            this.compactAndEnsureTrailing(this.expenseItems);
            this.save();
        },
        // 2026-09-08 — Johan, live on QA1: "added values in right hand
        // panel totals do not populate." Root cause found on the real
        // record: typed amounts had landed in the DESCRIPTION column,
        // amount left null.
        //
        // FIRST attempt at a fix moved focus to a freshly-created row's
        // amount field the instant that row was pushed (triggered from
        // @input, "the last row just became non-blank"). cc1 reproduced a
        // WORSE bug from that fix before it ever reached Johan: that
        // trigger condition is true on literally every keystroke once the
        // newly-created row itself starts filling, not just once — the
        // row just focused goes non-blank on its very next character,
        // which creates ANOTHER row and jumps focus AGAIN, forever. Typing
        // "10000" produced five single-digit rows instead of one row
        // holding "10000". Reverted off QA1 before Johan ever saw it.
        //
        // Correct fix: never infer "the agent is done with this row" from
        // an input event — a keystroke can't tell a completed value apart
        // from a value still being typed. Use an explicit signal instead:
        // Enter. Pressing Enter in an amount field moves focus to the
        // NEXT row's amount field (which @input's own compactAndEnsureTrailing
        // has already created, from the characters typed before Enter was
        // pressed) — never on plain typing, so a run of digits can never
        // trigger it more than the one time the agent actually asks for it.
        //
        // Round 16 — a SECOND, subtler bug found verifying this under fast,
        // continuous (scripted, zero-delay) typing through three rows: the
        // original version wrapped the focus move in $nextTick(), which
        // defers it to Alpine's next microtask. That's unnecessary here —
        // the target row was already created by an EARLIER, already-
        // completed @input event (events on one element are strictly
        // sequential; the row-creating keystroke fully finishes, DOM
        // patch included, before this LATER keydown.enter can even fire)
        // — and worse, it's actively harmful: a fast enough next keystroke
        // can land before that deferred callback runs, landing in the
        // OLD field instead and concatenating onto whatever was already
        // there ("15000" + "8500" typed fast enough became "150008500" in
        // one field, not two). Focusing synchronously, with no deferral,
        // removed the race entirely.
        focusNextAmountRow(ref, currentIndex) {
            const inputs = this.$refs[ref].querySelectorAll('[data-role="amount"]');
            inputs[currentIndex + 1]?.focus();
        },
        // Sums exactly what the server will sum (RentalApplicationAssessment::
        // qualifyingResult() sums the same persisted amounts) — this MUST
        // never be allowed to disagree with result.gross_income, so it uses
        // the identical rows, the identical filter, and plain addition.
        incomeTotal() {
            return this.incomeItems.filter(r => !this.rowIsBlank(r)).reduce((sum, r) => sum + (parseFloat(r.amount) || 0), 0);
        },
        expenseTotal() {
            return this.expenseItems.filter(r => !this.rowIsBlank(r)).reduce((sum, r) => sum + (parseFloat(r.amount) || 0), 0);
        },
        // Round 11 — display only (see the field's own comment above). Kept
        // as the LAST-KNOWN-GOOD figure so an existing assessment that has
        // never had a date range picked still shows/uses its old typed
        // number — "Dates on entries" (2026-09-10) only starts overwriting
        // this once both dates below are actually set.
        statementMonths: initial.statement_months ?? '',
        // "Dates on entries" (Johan, 2026-09-10) — "the agent picks a
        // from-date and a to-date; the month count is CALCULATED from them,
        // not typed." The server is the single source of truth for the
        // derivation (RentalApplicationAssessment::calculateStatementMonths(),
        // same inclusive-calendar-month rule) — this client copy exists only
        // to show the agent what it will work out to before their next
        // autosave round-trips it back.
        statementPeriodFrom: initial.statement_period_from ?? '',
        statementPeriodTo: initial.statement_period_to ?? '',
        calculatedStatementMonths() {
            if (!this.statementPeriodFrom || !this.statementPeriodTo) return null;
            const from = new Date(this.statementPeriodFrom + 'T00:00:00');
            const to = new Date(this.statementPeriodTo + 'T00:00:00');
            if (isNaN(from) || isNaN(to)) return null;
            const months = (to.getFullYear() - from.getFullYear()) * 12 + (to.getMonth() - from.getMonth()) + 1;
            return Math.max(1, months);
        },
        // Round 16 — the unpaid-transactions red flag.
        hasUnpaidTransactions: initial.has_unpaid_transactions ?? false,
        monthlyAverage(total) {
            const months = parseInt(this.statementMonths, 10);
            if (!months || months < 1) return null;

            return total / months;
        },
        // ── Affordability assessment (unchanged) ──────────────────────────
        fields: initial,
        result: initialResult ?? { label: 'incomplete' },
        // ── Authoriser flow — the agent's two actions ─────────────────────
        moreInfoNote: '',
        moreInfoSending: false,
        submittingForApproval: false,
        agentActionStatus: '',
        agentActionError: false,
        // Reopen/resubmit, 2026-09-08 — expectedGeneration is bootstrapped
        // from the generation this page actually rendered; sent back on
        // every write the applicant's own resubmit could invalidate, so a
        // stale tab is refused with a clear "reload" message rather than
        // silently acting on content it hasn't actually seen (Johan: reuse
        // the exact 409 pattern already shipped for document marks).
        expectedGeneration: expectedGeneration,
        reopenNote: '',
        reopenSending: false,
        async reopenApplication() {
            if (this.reopenSending || !this.reopenNote.trim()) return;
            if (!confirm('Send this application back to the applicant to fix and re-sign? They will be emailed a link, pre-filled with what they already entered.')) return;
            this.reopenSending = true;
            this.agentActionStatus = '';
            try {
                const res = await fetch(reopenUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ note: this.reopenNote }),
                });
                const data = await res.json().catch(() => ({}));
                if (res.ok && data.ok) {
                    this.agentActionStatus = data.mail_sent ? 'Reopened — the applicant has been emailed.' : 'Reopened, but the email could not be sent — check their email address.';
                    this.agentActionError = false;
                    setTimeout(() => window.location.reload(), 900);
                } else {
                    this.agentActionError = true;
                    this.agentActionStatus = data.error || 'Could not reopen — try again.';
                }
            } catch (e) {
                this.agentActionError = true;
                this.agentActionStatus = 'Could not reopen — check your connection.';
            }
            this.reopenSending = false;
        },
        // A generation-conflict response (409) means the applicant resubmitted
        // since this page loaded — every write action's error handling calls
        // this instead of a generic "try again" so the agent knows to reload
        // rather than retry blindly against content that no longer exists.
        handleGenerationConflict(data) {
            this.agentActionError = true;
            this.agentActionStatus = 'This application changed since you opened it — the applicant resubmitted. Reload the page to see the new version.';
        },
        async requestMoreInfo() {
            if (this.moreInfoSending || !this.moreInfoNote.trim()) return;
            this.moreInfoSending = true;
            this.agentActionStatus = '';
            try {
                const res = await fetch(requestMoreInfoUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ note: this.moreInfoNote }),
                });
                const data = await res.json().catch(() => ({}));
                if (res.ok && data.ok) {
                    this.agentActionError = false;
                    this.agentActionStatus = data.mail_sent ? 'Sent to the applicant.' : 'Logged, but the email could not be sent — check their email address.';
                    this.moreInfoNote = '';
                } else {
                    this.agentActionError = true;
                    this.agentActionStatus = data.error || 'Could not send — try again.';
                }
            } catch (e) {
                this.agentActionError = true;
                this.agentActionStatus = 'Could not send — check your connection.';
            }
            this.moreInfoSending = false;
        },
        async submitForApproval() {
            if (this.submittingForApproval) return;
            this.submittingForApproval = true;
            this.agentActionStatus = '';
            try {
                const res = await fetch(submitForApprovalUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ expected_generation: this.expectedGeneration }),
                });
                const data = await res.json().catch(() => ({}));
                if (res.ok && data.ok) {
                    this.agentActionError = false;
                    this.agentActionStatus = 'Submitted to the authoriser.';
                    setTimeout(() => window.location.reload(), 900);
                } else if (res.status === 409 && data.reason === 'generation_conflict') {
                    this.handleGenerationConflict(data);
                } else {
                    this.agentActionError = true;
                    this.agentActionStatus = data.error || 'Could not submit — try again.';
                }
            } catch (e) {
                this.agentActionError = true;
                this.agentActionStatus = 'Could not submit — check your connection.';
            }
            this.submittingForApproval = false;
        },
        saveStatus: initialSavedAt ? ('Saved at ' + formatTime(initialSavedAt)) : '',
        saveError: false,
        saveTimer: null,
        // Johan: the Notes/Request-more-info boxes were too small and their
        // own inner scrollbar sat inside a panel that was already
        // scrolling — "you scroll and the wrong thing moves." Height
        // tracks content exactly up to the cap (no scrollbar can appear
        // below it since nothing overflows), then the textarea's own
        // scroll takes over past that point. Called on init (so a saved
        // note renders at its real height immediately, not just after the
        // next keystroke) and on every input.
        autoGrowTextarea(el) {
            if (!el) return;
            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight, 320) + 'px';
        },
        save() {
            clearTimeout(this.saveTimer);
            this.saveTimer = setTimeout(() => this.performSave(), 150);
        },
        performSave() {
            if (this.saveInFlight) {
                this.saveQueued = true;
                return;
            }
            this.saveInFlight = true;
            this.saveStatus = 'Saving…';
            this.saveError = false;
            // Capture the actual row OBJECTS being sent (not a copy) so
            // the response's ids can be patched back onto them by
            // position after the round trip, without disturbing any
            // blank row the agent has started typing into since —
            // wholesale-replacing the array here would drop that.
            const sentIncomeRows = this.incomeItems.filter(r => !this.rowIsBlank(r));
            const sentExpenseRows = this.expenseItems.filter(r => !this.rowIsBlank(r));
            fetch(saveUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    income_items: sentIncomeRows,
                    expense_items: sentExpenseRows,
                    notes: this.fields.notes,
                    statement_period_from: this.statementPeriodFrom,
                    statement_period_to: this.statementPeriodTo,
                    has_unpaid_transactions: this.hasUnpaidTransactions,
                    expected_generation: this.expectedGeneration,
                }),
            }).then(r => r.json().then(data => ({ ok: r.ok, status: r.status, data }))).then(({ ok, status, data }) => {
                if (ok && data.ok) {
                    this.result = data.result;
                    // The server is the single source of truth for the
                    // derived count (see calculateStatementMonths() above) —
                    // sync it back so the read-only "Currently N months"
                    // line and the affordability math agree with what was
                    // actually persisted, not just the client's own guess.
                    if (data.result && data.result.statement_months !== undefined) {
                        this.statementMonths = data.result.statement_months ?? this.statementMonths;
                    }
                    (data.income_items || []).forEach((saved, i) => { if (sentIncomeRows[i]) { sentIncomeRows[i].id = saved.id; sentIncomeRows[i].entry_date = saved.entry_date; } });
                    (data.expense_items || []).forEach((saved, i) => { if (sentExpenseRows[i]) { sentExpenseRows[i].id = saved.id; sentExpenseRows[i].entry_date = saved.entry_date; } });
                    this.saveStatus = data.saved_at ? ('Saved at ' + formatTime(data.saved_at)) : 'Saved';
                } else if (status === 409 && data.reason === 'generation_conflict') {
                    this.saveError = true;
                    this.saveStatus = 'This application changed since you opened it — reload to see the new version.';
                } else {
                    this.saveError = true;
                    this.saveStatus = 'Could not save — try again';
                }
            }).catch(() => {
                this.saveError = true;
                this.saveStatus = 'Could not save — check your connection';
            }).finally(() => {
                this.saveInFlight = false;
                if (this.saveQueued) {
                    this.saveQueued = false;
                    this.performSave();
                }
            });
        },
        formatR(v) {
            return v === null || v === undefined ? '—' : 'R ' + Number(v).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },


        // 2026-09-08 — activeDocId, pages, marks, openHighlighter()/
        // applyHighlights()/etc. now come from the ...rentalDocumentHighlighter()
        // spread above (see partials/document-highlighter-script.blade.php).
    };
}

function formatTime(iso) {
    return new Date(iso).toLocaleTimeString('en-ZA', { hour: '2-digit', minute: '2-digit' });
}

// 2026-09-08 — Johan: "essentially everything should fit that the whole
// screen doesnt scroll, but the left and right panels scroll in the
// screen." MEASURES the real space left inside #appScroll (layouts/corex.
// blade.php's own scroll container) rather than guessing a fixed vh
// number — the guess was wrong because it never accounted for the real,
// variable height of the QA/env banner above the app, so the panels ended
// up taller than the space actually available and #appScroll itself had
// to scroll too. All values read here (header height, its margin, this
// element's own margin) are independent of #appScroll's OWN scroll
// position, so this stays correct however the page is scrolled. Shared by
// both roles now (unified screen, 2026-09-09) — the authoriser's screen
// didn't measure this at all before the merge; its columns just grew
// unbounded.
function rentalReviewLayout() {
    return {
        // AT-392 — lifted here from a local x-data on the amber "approved,
        // not yet sent" box so the drawer trigger button (still nested deep
        // in .rental-review-aside) and the drawer itself (relocated OUTSIDE
        // that panel to fix the cut-off/z-index bug — see the drawer's own
        // comment) can share one toggle despite no longer being DOM-nested.
        wishlistDrawerOpen: false,
        // DRAGGABLE WIDTH, 2026-09-10 — see the layout <style> block's own
        // comment for why. Reuses the exact drag pattern already shipped in
        // resources/views/docuperfect/templates/edit-web.blade.php
        // (startDrag/onDrag), adapted from a percentage split to a pixel
        // width since this layout's aside is a fixed-px sticky column, not
        // a flex-percentage pane.
        //
        // ROUND 2, 2026-09-10 — Johan, checking application 76 himself,
        // rejected the first pass (a 260→320 floor bump): "Going 260 → 320
        // does not make a 5-character field readable... Work out the width
        // the affordability rows genuinely need... then give the panel
        // that." These three constants ARE that derivation, summed once
        // here rather than picked as a bare number — see the layout <style>
        // block's own note for the full arithmetic and ROUND 3 (amount
        // column's own floor + scrollbar-gutter reservation, 440→460).
        RA_ASIDE_DEFAULT_PX: 460,
        RA_ASIDE_MIN_PX: 400,
        RA_ASIDE_MAX_PX: 660,
        // Persisted per-browser so a drag survives a reload; Math.max/min
        // below re-clamp a value ALREADY in localStorage from a PRIOR
        // floor/ceiling (260/320, 320/480, or 380/640 — this build's own
        // two prior passes) — a browser that dragged to one of those old
        // numbers must not stay stuck outside the current range forever.
        // Repeats the RA_ASIDE_* numbers as literals rather than referencing
        // them — a plain object literal can't read a sibling property via
        // `this` while it's still being constructed. startAsideResize()'s
        // own clamp below (evaluated later, as a real method call) uses the
        // named constants directly.
        resizingAside: false,
        asideWidth: Math.min(660, Math.max(400, parseInt(localStorage.getItem('rentalReviewAsideWidth'), 10) || 460)),
        startAsideResize(e) {
            this.resizingAside = true;
            const startX = e.clientX;
            const startWidth = this.asideWidth;
            // Plain DOM lookup, not Alpine's $el magic — found live under a
            // REAL mouse drag (not a synthetic dispatched event) that
            // `this.$el` read from inside this method resolves to
            // something that silently fails to affect the real node's
            // inline style: asideWidth updated correctly on every drag, the
            // CSS variable never did. e.target is the resizer handle itself
            // (a genuine native event, always reliable); walking up to its
            // one fixed ancestor sidesteps whatever Alpine-context quirk
            // caused that.
            const columnsEl = e.target.closest('.rental-review-columns');
            const onMove = (ev) => {
                if (!this.resizingAside) return;
                // Dragging the handle LEFT (negative delta) widens the
                // aside — the aside sits to the RIGHT of the handle.
                const next = startWidth - (ev.clientX - startX);
                this.asideWidth = Math.min(this.RA_ASIDE_MAX_PX, Math.max(this.RA_ASIDE_MIN_PX, next));
                columnsEl.style.setProperty('--rr-aside-w', this.asideWidth + 'px');
            };
            const onUp = () => {
                if (!this.resizingAside) return;
                this.resizingAside = false;
                localStorage.setItem('rentalReviewAsideWidth', String(this.asideWidth));
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
            };
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        },
        init() {
            this.$el.style.setProperty('--rr-aside-w', this.asideWidth + 'px');

            const recalc = () => {
                const scrollEl = document.getElementById('appScroll');
                const header = document.querySelector('.sticky.top-0.z-50');
                if (!scrollEl || !header) return;
                const headerSpace = header.offsetHeight + parseFloat(getComputedStyle(header).marginBottom || 0);
                const columnsMarginTop = parseFloat(getComputedStyle(this.$el).marginTop || 0);
                const available = scrollEl.clientHeight - headerSpace - columnsMarginTop - 8;
                this.$el.style.setProperty('--rr-panel-h', Math.max(300, available) + 'px');
            };
            recalc();
            window.addEventListener('resize', recalc);
            // Fonts/images can still shift real heights slightly after the
            // first paint — one more pass once layout has settled.
            setTimeout(recalc, 300);
        },
    };
}

// Agent-added-documents, 2026-09-08 — cc4's widget, handed to me for this
// file. Own component, zero shared state with rentalReview().
function agentDocumentUploadReview() {
    return {
        uploading: [],
        csrfToken() { return document.querySelector('meta[name="csrf-token"]').content; },
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
                if (!res.ok) { item.error = (data.errors && Object.values(data.errors)[0]?.[0]) || data.message || 'Upload failed.'; return; }
                this.uploading = this.uploading.filter(u => u.tempId !== tempId);
                window.location.reload();
            } catch (e) {
                const item = this.uploading.find(u => u.tempId === tempId);
                if (item) item.error = 'Network error — please try again.';
            }
        },
        async onFilesSelected(fileList) { await Promise.all(Array.from(fileList).map(file => this.uploadFile(file))); },
    };
}

// AT-392 "pull from contact" — attach a document already on file against
// this application's contact. Same reload-on-success shape as
// agentDocumentUploadReview() above.
function attachExistingDocument() {
    return {
        submittingId: null,
        status: '',
        error: false,
        async attach(documentId) {
            if (this.submittingId) return;
            this.submittingId = documentId;
            this.status = '';
            try {
                const res = await fetch('{{ route('corex.rental-applications.documents.attach-existing', $rentalApplication) }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ document_id: documentId }),
                });
                const data = await res.json().catch(() => ({}));
                if (res.ok && data.ok) {
                    window.location.reload();
                    return;
                }
                this.error = true;
                this.status = data.error || 'Could not attach — try again.';
            } catch (e) {
                this.error = true;
                this.status = 'Could not attach — check your connection.';
            }
            this.submittingId = null;
        },
    };
}

// AT-392, Johan (2026-09-08) — property link on Review, so the affordability
// check can test a real rent instead of falling back to the applicant's own
// current rent. Deliberately independent of rentalReview() above (that
// component owns the assessment/affordability panel) — this only ever POSTs
// property_id to its own route and reloads, same shape as every other
// single-field save action on this screen (e.g. the status form elsewhere
// in this module).
function rentalReviewPropertyLink({ searchUrl, linkUrl, currentLabel }) {
    return {
        linkUrl,
        currentLabel: currentLabel || null,
        searching: false,
        query: '',
        results: [],
        async search() {
            if (this.query.length < 2) { this.results = []; return; }
            const res = await fetch(searchUrl + '?q=' + encodeURIComponent(this.query));
            this.results = await res.json();
        },
        select(p) {
            this.$refs.propertyIdInput.value = p.id;
            this.$refs.linkForm.submit();
        },
        clear() {
            if (!confirm('Clear the linked property? The affordability check will show "cannot be calculated" until a property is linked again.')) return;
            this.$refs.propertyIdInput.value = '';
            this.$refs.linkForm.submit();
        },
    };
}

function rentalAuthorisationViewer({ initialMarkedUpDocIds, currentUserId, currentUserName, currentUserRole, highlighters }) {
    return {
        // Shared highlight/note viewer — see partials/document-highlighter-script.blade.php.
        ...rentalDocumentHighlighter({ initialMarkedUpDocIds, currentUserId, currentUserName, currentUserRole, highlighters }),

        // Decision panel fields — unchanged from before this screen grew a document viewer.
        approveAmount: '',
        approveReason: '',
        declineReason: '',
        moreInfoReason: '',

        init() {
            this.initHighlighterPrefs();
        },
    };
}

/**
 * AT-392 authoriser assessment markup, 2026-09-08 — strike-out and add for
 * the agent's captured income and expense lines. Johan, confirmed directly
 * (not a coordinator inference): "auth can rather strike out and re-add a
 * value than edit a value. this way we have the evidence needed of who
 * did what." There is no edit anywhere here, client or server — striking a
 * row opens a replacement box right under it (replacingItem), so the two
 * actions read as one continuous flow. Server re-enforces everything this
 * client does or doesn't offer — a crafted request still can't do more
 * than what's built here, same "never trust the client" rule the document
 * highlighter's own save already follows.
 */
function rentalAssessmentEditor({ currentUserId, incomeItems, expenseItems, addIncomeUrl, addExpenseUrl, incomeItemUrl, expenseItemUrl, statementMonths, maxRentPercent, rent, propertyLinked, hasUnpaidTransactions }) {
    return {
        currentUserId, incomeItems, expenseItems, statementMonths, maxRentPercent, rent, propertyLinked, hasUnpaidTransactions,
        newIncomeDescription: '', newIncomeAmount: '', newIncomeDate: '',
        newExpenseDescription: '', newExpenseAmount: '', newExpenseDate: '',
        replacingItem: null, replaceDescription: '', replaceAmount: '', replaceDate: '',
        itemError: '',

        // Johan: "the result should read clearly as 'this figure was
        // replaced by that one, by this person, at this time'."
        struckLine(item) {
            const who = item.struck_out_by ? ` by ${item.struck_out_by}` : '';
            const when = item.struck_out_at ? ` at ${item.struck_out_at}` : '';
            if (item.replaced_by_item_id) {
                const rWho = item.replaced_by_user ? ` by ${item.replaced_by_user}` : '';
                const rWhen = item.replaced_by_at ? ` at ${item.replaced_by_at}` : '';
                return `Struck${who}${when} — replaced by R${this.formatAmount(item.replaced_by_amount)}${rWho}${rWhen}.`;
            }
            return `Struck${who}${when} — no replacement added yet.`;
        },
        formatAmount(v) {
            return (Number(v) || 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        // AT-392 — Johan, 2026-09-09: this read "3%" instead of "30%" on a
        // real awaiting-authorisation record — the calculation itself was
        // right (30% throughout), only this label was wrong, which is worse
        // than a missing figure. Root cause: the old regex's leading dot
        // was OPTIONAL (`\.?0+$`), so it stripped trailing zeros even with
        // no decimal point at all — turning whole multiples of ten into
        // nonsense (30→3, 40→4, 100→1) while leaving non-round values
        // (28.5, 25) untouched, which is exactly why this looked like a
        // one-off rather than a systematic bug. Fixed by first formatting
        // to a fixed 2 decimals (so a "." is always present, mirroring the
        // server-side rtrim(rtrim(number_format($v,2),'0'),'.') pattern
        // used elsewhere for the same "30.00" -> "30" trim), THEN stripping
        // trailing zeros — the decimal point itself stops the trailing-zero
        // match from ever reaching into the integer part.
        trimPercent(v) {
            return Number(v).toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
        },
        liveTotal(list) {
            return list.filter(i => !i.struck_out).reduce((sum, i) => sum + Number(i.amount || 0), 0);
        },
        incomeTotal() { return this.liveTotal(this.incomeItems); },
        expenseTotal() { return this.liveTotal(this.expenseItems); },
        grossIncome() {
            if (!this.statementMonths) return 0;
            return Math.round((this.incomeTotal() / this.statementMonths) * 100) / 100;
        },
        maxAffordableRent() {
            return Math.round(this.grossIncome() * (this.maxRentPercent / 100) * 100) / 100;
        },
        rentAsPercent() {
            const g = this.grossIncome();
            if (this.rent === null || !g) return '0';
            return (Math.round((this.rent / g) * 1000) / 10).toString();
        },
        meetsThreshold() {
            if (this.rent === null) return null;
            return this.rent <= this.maxAffordableRent();
        },

        async postJson(url, method, body) {
            this.itemError = '';
            try {
                const res = await fetch(url, {
                    method,
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()), 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(body),
                });
                const data = await res.json().catch(() => null);
                if (!res.ok || !data || !data.ok) {
                    this.itemError = (data && data.message) ? data.message : 'Could not save — please try again.';
                    return null;
                }
                return data.item;
            } catch (e) {
                this.itemError = 'Could not save — please try again.';
                return null;
            }
        },

        // replacesId: present when this add follows a strike in the same
        // flow — the row it replaces. Absent for a plain "+ Add" at the
        // bottom of the list.
        async addItem(kind, replacesId = null) {
            const isIncome = kind === 'income';
            const description = replacesId ? this.replaceDescription : (isIncome ? this.newIncomeDescription : this.newExpenseDescription);
            const amount = replacesId ? this.replaceAmount : (isIncome ? this.newIncomeAmount : this.newExpenseAmount);
            const entry_date = replacesId ? this.replaceDate : (isIncome ? this.newIncomeDate : this.newExpenseDate);
            if (!amount) return;
            const body = { description, amount, entry_date: entry_date || null };
            if (replacesId) body.replaces_item_id = replacesId;
            const item = await this.postJson(isIncome ? addIncomeUrl : addExpenseUrl, 'POST', body);
            if (!item) return;
            (isIncome ? this.incomeItems : this.expenseItems).push(item);
            if (replacesId) {
                const struckRow = (isIncome ? this.incomeItems : this.expenseItems).find(i => i.id === replacesId);
                if (struckRow) {
                    struckRow.replaced_by_item_id = item.id;
                    struckRow.replaced_by_amount = item.amount;
                    struckRow.replaced_by_description = item.description;
                    struckRow.replaced_by_user = item.added_by;
                    struckRow.replaced_by_at = item.added_at;
                }
                this.replacingItem = null; this.replaceDescription = ''; this.replaceAmount = ''; this.replaceDate = '';
            } else if (isIncome) { this.newIncomeDescription = ''; this.newIncomeAmount = ''; this.newIncomeDate = ''; }
            else { this.newExpenseDescription = ''; this.newExpenseAmount = ''; this.newExpenseDate = ''; }
        },

        // Johan: strike-and-re-add, not edit. Striking a row that isn't
        // already struck opens the replacement box directly under it and
        // focuses the description field — the natural next step, not a
        // separate action the authoriser has to go find. Un-striking
        // (restoring) just clears the row back to counting normally.
        async toggleStrike(kind, item) {
            const isIncome = kind === 'income';
            const updated = await this.postJson(`${isIncome ? incomeItemUrl : expenseItemUrl}/${item.id}/strike`, 'POST', {});
            if (!updated) return;
            item.struck_out = updated.struck_out;
            item.struck_out_by = updated.struck_out_by;
            item.struck_out_at = updated.struck_out_at;
            if (item.struck_out) {
                this.replacingItem = kind + '-' + item.id;
                this.replaceDescription = item.description || '';
                this.replaceAmount = '';
                this.$nextTick(() => {
                    const el = this.$el.querySelector(`[data-replace-focus="${kind}-${item.id}"]`);
                    if (el) el.focus();
                });
            } else if (this.replacingItem === (kind + '-' + item.id)) {
                this.replacingItem = null;
            }
        },
    };
}
</script>
@endsection
