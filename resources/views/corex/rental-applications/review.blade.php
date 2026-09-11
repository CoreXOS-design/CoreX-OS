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
    //
    // Capture-ledger rework, 2026-09-11 — $initialIncomeItems/
    // $initialExpenseItems REMOVED (were computed here from
    // $assessment->incomeItems/expenseItems). $captureEntries, computed in
    // the controller from rental_application_document_marks, replaces them
    // as this screen's source of truth for the ledger.
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

    // ROUND 4, 2026-09-11 — Johan: "'No property linked' appears TWICE...
    // work out which one is the intended one, and remove the duplication."
    // Two independent computations existed: the plain header subtitle
    // (property_address_override ?? property->address ?? fallback — never
    // considered the property's own title) and the locked-state property
    // widget (property->title ?: buildDisplayAddress() — never considered
    // property_address_override). Neither was complete on its own. ONE
    // computation now, folding in everything either version knew about,
    // used everywhere on this header instead of two separately-maintained
    // English strings that happened to agree by luck.
    $propertyLabel = $rentalApplication->property
        ? (optional($rentalApplication->property)->title ?: optional($rentalApplication->property)->buildDisplayAddress())
        : $rentalApplication->property_address_override;
    $headerContactName = $rentalApplication->contact->full_name
        ?? trim(($rentalApplication->contact->first_name ?? '') . ' ' . ($rentalApplication->contact->last_name ?? ''));
    $headerTitleText = ($viewerRole === 'agent' ? 'Application Review' : 'Authorise') . ' — ' . $headerContactName;
    $headerPropertyFact = $propertyLabel ? ('Property: ' . $propertyLabel) : 'No property linked';
    $headerExtraFact = null;
    if ($viewerRole === 'agent' && ($propertyLinkLocked ?? false)) {
        $headerExtraFact = 'locked — submitted for authorisation.';
    } elseif ($viewerRole === 'authoriser') {
        $headerExtraFact = 'Submitted for approval ' . $rentalApplication->submitted_for_approval_at?->format('d M Y H:i');
    }
    $headerFullText = $headerTitleText . ' · ' . $headerPropertyFact . ($headerExtraFact ? ' · ' . $headerExtraFact : '');
@endphp

@section('corex-content')
@php
    // ROUND 6, 2026-09-11 — computed once, up here, so both the x-data init
    // below (which action the merged "Send back to applicant" button takes)
    // and Zone 4's own button visibility (further down the page) read the
    // exact same gate — see RentalApplicationReviewController::reopen()/
    // requestMoreInfoFromApplicant() for what each path actually does.
    $canReopenNow = $viewerRole === 'agent'
        && in_array($rentalApplication->status, \App\Models\RentalApplication::REOPENABLE_STATUSES, true)
        && ($rentalApplication->status !== 'declined' || auth()->user()->isRentalApplicationOverrideTier((int) $rentalApplication->agency_id));
    $canSendBackToApplicant = $viewerRole === 'agent'
        && ($canReopenNow || !in_array($rentalApplication->status, ['approved', 'declined'], true));
@endphp
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
         initialCaptureEntries: {{ Js::from($captureEntries) }},
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
         canReopenNow: {{ Js::from($canReopenNow) }},
         documentChecklist: {{ Js::from($documentChecklist) }},
     })"
     @else
     x-data="rentalAuthorisationViewer({
         initialMarkedUpDocIds: {{ Js::from($initialMarkedUpDocIds) }},
         currentUserId: {{ Js::from(auth()->id()) }},
         currentUserName: {{ Js::from(auth()->user()->name) }},
         currentUserRole: 'authoriser',
         highlighters: {{ Js::from($highlighters) }},
         initialCaptureEntries: {{ Js::from($captureEntries) }},
         initialStatementMonths: {{ Js::from($assessment->statement_months) }},
     })"
     @endif
     {{-- Capture-ledger rework, 2026-09-11 — the chip lives inside
          rentalDocumentHighlighter(), which is a genuinely SEPARATE
          x-data component per document inside the continuous view (see
          that block's own comment on why the root-level spread's
          marks/activeDocId are dead there) — it has no direct reference
          to this root's captureEntries/addCaptureEntry(). .window listeners
          here bridge the two regardless of DOM nesting depth, the same way
          $dispatch()'d events always do. --}}
     @capture-entry-created.window="addCaptureEntry($event.detail)"
     @capture-entry-updated.window="updateCaptureEntry($event.detail)"
     @capture-entry-deleted.window="removeCaptureEntry($event.detail)"
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
                {{--
                    ROUND 4, 2026-09-11 — Johan: "this at the top... cut it
                    down to 1 row. lots of wasted screen there as well."
                    Was three stacked elements (h1 title, a plain subtitle
                    <p>, and — agent-only — the property-link widget's own
                    <p>/<div>) with "No property linked" independently
                    computed and rendered by TWO of them. Now one flex row:
                    bold title truncates on its own (a genuinely unbounded
                    contact name is the only unbounded content here), the
                    property/lock/submitted facts are flex-shrink-0 so they
                    never get clipped, and the whole row carries a `title`
                    with the full untruncated text — the same degrade-
                    legibly fallback already used on the affordability rows,
                    chosen deliberately over letting this wrap to a second
                    row (which would just recreate the "lots of wasted
                    screen" complaint one row lower).
                --}}
                <h1 class="flex items-center gap-1.5 text-sm leading-tight" style="color: var(--text-primary);" title="{{ $headerFullText }}">
                    <span class="truncate font-bold" style="min-width:0;">{{ $headerTitleText }}</span>
                    <span class="text-xs font-normal flex-shrink-0 whitespace-nowrap" style="color: var(--text-muted);">
                        &middot; {{ $headerPropertyFact }}@if($headerExtraFact) &middot; {{ $headerExtraFact }}@endif
                    </span>
                </h1>

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
                    @if(!($propertyLinkLocked ?? false))
                    {{--
                        ROUND 4, 2026-09-11 — the locked branch's own copy of
                        this fact was removed entirely (the h1 above already
                        carries it — see this file's top PHP setup block). This
                        unlocked branch's own "Property: X"/"No property
                        linked yet." text is ALSO removed here for the same
                        reason (the h1's fact covers it too, always, not just
                        when locked) — only the interactive buttons remain,
                        appended inline onto the same header row rather than
                        a separate stacked line.
                    --}}
                    <div class="inline-flex items-center gap-1.5 flex-shrink-0" x-data="rentalReviewPropertyLink({{ Js::from([
                        'searchUrl' => route('corex.rental-applications.search-properties'),
                        'linkUrl' => route('corex.rental-applications.review.link-property', $rentalApplication),
                        'currentLabel' => $propertyLabel,
                    ]) }})">
                        <template x-if="!searching">
                            <span class="inline-flex items-center gap-1.5">
                                <button type="button"
                                        class="corex-btn-outline"
                                        style="padding: 0.15rem 0.6rem; font-size: 0.7rem; line-height: 1.2;"
                                        @click="searching = true">
                                    <span x-text="currentLabel ? 'Change property' : 'Link a property'"></span>
                                </button>
                                <button type="button" class="underline text-xs" style="color: var(--ds-red, #dc2626);" x-show="currentLabel" @click="clear()">Clear</button>
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

    {{-- ROUND 8, 2026-09-11 — capture-ledger rework. Johan, on the bottom
         strip Rounds 6/7 built: "it stays split and disconnected." The
         fix is structural, not another resize: the highlighter mark IS
         the ledger line now (see document-highlighter-script.blade.php
         and app/Http/Controllers/Concerns/HandlesRentalApplicationDocumentMarks.php's
         captureEntry* methods). Because the panel no longer holds any
         input fields — it is a READ-ONLY tally now, capture happens on
         the document itself via a capture pen + an anchored chip — it no
         longer needs the width six earlier rounds fought over. It
         RETURNS to a fixed 196px side column, side-by-side with the
         document at ≥1280px, exactly the two-region shape this screen
         used before the bottom-strip detour, minus the drag entirely:
         a fixed-width read-only tally has nothing to negotiate for.
         Full round-by-round history (widths measured and re-derived
         seven times over, the bottom-strip build, the reasons it was
         rejected) lives in .ai/specs/rental-applications.md — kept there
         now rather than repeated inline, since none of it describes the
         layout actually shipping any more.

         One still-true finding carried forward: native `<input
         type="date">` display format is the browser/OS locale's own
         rendering, not something this page's CSS/JS can override —
         relevant again below, since the statement-period fields are
         still native date inputs, just relocated.
         main on top (full width, flexible remaining height), aside below
         (full width, fixed HEIGHT instead of fixed WIDTH). The resizer now
         drags VERTICALLY (`cursor: row-resize`, a horizontal bar) — Johan's
         own new requirement: "make the bar movable the agent can move it
         up and down to see more or less. their choice." Persisted exactly
         like the old width was (same RA_ASIDE_*-pattern localStorage
         mechanism, renamed RA_STRIP_* in the script below — "follow the
         pattern you already know rather than inventing a second one").
         Floor (260px) still shows the strip's own header row plus the
         ledger's input row — verified live, not assumed; ceiling (560px)
         still leaves a genuinely readable document on a normal viewport.
         Default (320px) matches Johan's own approved mockup: all four
         zones' content with three ledger rows visible before it scrolls. --}}
    <style>
        .rental-review-columns { display: flex; flex-direction: column; gap: 20px; }
        .rental-review-main    { flex: 1 1 auto; min-width: 0; }
        .rental-review-aside   { width: 100%; }
        @media (min-width: 1280px) {
            .rental-review-columns { flex-direction: row; gap: 16px; align-items: stretch; }
            .rental-review-main    { height: var(--rr-panel-h, calc(100vh - 160px)); max-height: var(--rr-panel-h, calc(100vh - 160px)); overflow-y: auto; }
            /* ROUND 8, 2026-09-11 — fixed 196px, no drag. A read-only tally
               has nothing to negotiate for; every prior round's width fight
               was about fitting INPUT fields, and there are none left in
               this panel — capture happens on the document now. */
            .rental-review-aside  {
                flex: 0 0 196px; width: 196px; align-self: stretch;
                position: sticky; top: 72px;
                height: var(--rr-panel-h, calc(100vh - 160px)); max-height: var(--rr-panel-h, calc(100vh - 160px));
                overflow-y: auto; overflow-x: hidden; scrollbar-gutter: stable;
                padding: 10px 12px;
            }
        }
        /* @tailwindcss/forms' own global default (12px/8px) tightened to
           fit a 196px column — same scoped-override pattern already used
           by .dr2-distribute/.dr2-pipeline elsewhere in this codebase. */
        .rental-review-aside .corex-input { padding: 4px 6px; }

        /* The capture-ledger tally — numbered rows, INCOME then EXPENSES,
           each a single grid line: badge | date | amount | jump glyph. */
        .rr-ledger-group-label {
            font-size: 10px; text-transform: uppercase; letter-spacing: 0.03em;
            color: var(--text-muted); margin: 10px 0 4px 0;
        }
        .rr-ledger-row {
            display: grid; grid-template-columns: 18px 56px 1fr 14px; gap: 6px; align-items: center;
            padding: 2px 0;
        }
        .rr-ledger-badge {
            width: 15px; height: 15px; border-radius: 9999px; color: #fff;
            font-size: 9px; font-weight: 700; line-height: 15px; text-align: center;
            flex-shrink: 0;
        }
        .rr-ledger-amount { text-align: right; font-variant-numeric: tabular-nums; }
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
                        {{-- "Current living situation" (2026-09-11) — the old form
                             assumed a landlord always exists; not every applicant is
                             currently renting. Kept alongside "Current landlord" above
                             rather than folded together — different facts (who they
                             rent from vs. whether they're renting at all). Fallback
                             covers pre-existing applications that have landlord data
                             but never answered this newer field. --}}
                        <dt style="color: var(--text-muted);">Current living situation</dt><dd>{{ \App\Models\RentalApplication::currentLivingSituationLabel($rentalApplication->current_living_situation) ?? ($rentalApplication->current_landlord_name ? 'Currently renting' : '—') }}</dd>
                        @if($rentalApplication->current_living_situation_notes)
                            <dt style="color: var(--text-muted);">In their own words</dt><dd>{{ $rentalApplication->current_living_situation_notes }}</dd>
                        @endif
                        <dt style="color: var(--text-muted);">Adults / Children</dt><dd>{{ $rentalApplication->adults ?? '—' }} / {{ $rentalApplication->children ?? '—' }}</dd>
                    </dl>
                    @if($viewerRole === 'agent')
                        <a href="{{ route('corex.rental-applications.show', $rentalApplication) }}" class="text-xs inline-block mt-3" style="color: var(--ds-blue, #2563eb);">View submitted application &rarr;</a>
                    @endif
                </div>
            </div>

            {{-- Supporting Documents — shared markup for both roles now
                 (unified screen, 2026-09-09). Route names differ by role
                 (agent: corex.rental-applications.documents.*; authoriser:
                 corex.rental-applications.authorisation.documents.*) —
                 resolved per-document below via $viewerRole rather than
                 duplicating this whole block.

                 ROUND 7, 2026-09-11 — Johan, measuring his own screen at
                 1522px: "from supporting documents 5 we have like 7 lines
                 of which only 2 is the actual document... you keep building
                 screens where the real estate is lost to nice parts instead
                 of functional parts." His own worked example (application
                 107's 5 split documents) named exactly what was wrong: the
                 uploader name, the exact timestamp, and the "Added after
                 submission" badge were IDENTICAL on every row (all five
                 came from the same split operation) — repeated five times
                 instead of stated once. And the relative age + the exact
                 date were the same fact printed twice on every row
                 regardless.

                 $allSameOrigin below checks whether EVERY document in this
                 list genuinely shares the same uploader + same "added after
                 submission" outcome — computed fresh each render, never
                 assumed, because it will legitimately be false for a mixed
                 batch (some from the applicant, some added later by the
                 agent). Johan's own second pass, after re-measuring and
                 finding the first attempt barely moved the needle, made the
                 real call explicit: don't hoist the uniform fact to a
                 still-separate summary line — DROP it, full stop, since the
                 Audit Trail section below already carries this exact
                 history ("the audit trail already holds the full history,
                 so this list does not need to be a second one"). $allSameOrigin
                 is used for exactly one purpose now: a row's own uploader/
                 date/badge only render when the set is genuinely MIXED
                 (the fact is then actually distinguishing that one row),
                 never when it's uniform. --}}
            @php
                $ownedRows = $documents->filter(fn ($r) => !$r['pulled_from_contact']);
                $firstOwned = $ownedRows->first();
                $allSameOrigin = $ownedRows->count() > 1 && $firstOwned && $ownedRows->every(function ($r) use ($firstOwned, $rentalApplication) {
                    // "Same batch" — within 5 minutes of each other, not
                    // literally the same second: a real split/upload
                    // processes documents one at a time, a few seconds
                    // apart. Carbon's diffInMinutes() also returns a float
                    // in this version — found live, not assumed, comparing
                    // === 0 against 0.0 silently failed for every document
                    // (0.0 === 0 is false in PHP's strict comparison).
                    return $r['document']->uploaded_by === $firstOwned['document']->uploaded_by
                        && $r['document']->created_at->diffInMinutes($firstOwned['document']->created_at) <= 5
                        && ($rentalApplication->submitted_at && $r['document']->created_at->greaterThanOrEqualTo($rentalApplication->submitted_at))
                            === ($rentalApplication->submitted_at && $firstOwned['document']->created_at->greaterThanOrEqualTo($rentalApplication->submitted_at));
                });
            @endphp
            {{-- ROUND 7, 2026-09-11, second pass — Johan re-measured after the
                 first pass and found it barely moved: "0 entries captured
                 increases the space by like 3 lines." Trimming the FACTS
                 inside an already-single-line row saves nothing when the
                 row's own height was never governed by how much text sat
                 in it — a readable row has a floor height regardless. The
                 real levers are the ones that change the LINE COUNT: drop
                 the added-by/date facts outright rather than hoisting them
                 to a still-separate summary line (Johan's own reasoning —
                 "the audit trail already holds the full history, so this
                 list does not need to be a second one" — applies just as
                 much to one shared line as it did to five repeated ones);
                 put the one primary action (View & Mark Up all) INLINE in
                 the always-visible heading instead of its own line below
                 it; and default the whole block COLLAPSED — "the agent
                 needs it while marking up and rarely afterwards" is
                 satisfied better by starting closed than by starting open
                 and hoping the agent closes it. --}}
            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ docsOpen: false }">
                <div class="flex items-center justify-between">
                    <button type="button" class="flex items-center gap-2 text-left" @click="docsOpen = !docsOpen">
                        <h2 class="text-sm font-semibold" style="color: var(--text-primary);">
                            Supporting Documents
                            <span class="ds-badge ds-badge-default">{{ $documents->count() }}</span>
                            @if($unsplitCount > 0)
                                <span class="ds-badge ds-badge-warning" title="These documents must be split into typed, filed pieces before this application can be submitted for authorisation.">{{ $unsplitCount }} not yet sorted</span>
                            @endif
                        </h2>
                        <span class="text-xs" style="color: var(--ds-blue, #2563eb);" x-text="docsOpen ? 'Hide' : 'Show'"></span>
                    </button>
                    @if($documents->filter(fn ($r) => $r['inline_viewable'])->isNotEmpty())
                        <button type="button" class="text-xs font-semibold" style="color: var(--ds-blue, #2563eb);" @click="openContinuousView()">View &amp; Mark Up all &rarr;</button>
                    @endif
                </div>

                <div x-show="docsOpen" x-cloak class="mt-3">
                @if($documents->isEmpty())
                    <p class="text-xs" style="color: var(--text-muted);">No supporting documents have been uploaded yet.</p>
                @else
                    <div class="space-y-1">
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
                            <div class="flex items-center justify-between px-3 py-1.5 text-xs rounded-md" style="border: 1px solid var(--border);">
                                <span class="truncate min-w-0" style="color: var(--text-primary);">
                                    {{ $document->original_name }}
                                    <span style="color: var(--text-muted);">&middot; {{ $document->documentType->label ?? 'Untyped' }}</span>
                                    {{-- Per-row facts stay ONLY where they genuinely
                                         distinguish this document from the others in
                                         the set — a staleness warning or a "from
                                         contact's file" tag is never uniform across a
                                         batch by its own nature, so it never qualifies
                                         for the single hoisted line above. --}}
                                    @if($row['staleness_warning'])
                                        <span class="ds-badge ds-badge-warning" title="{{ $row['staleness_warning'] }}">{{ $row['staleness_warning'] }}</span>
                                    @endif
                                    @if($row['pulled_from_contact'])
                                        <span class="ds-badge ds-badge-default" title="Already on file for this contact — attached here without the applicant re-sending it.">From contact's file</span>
                                    @endif
                                    @if(!$allSameOrigin && $viewerRole === 'agent' && !$row['pulled_from_contact'])
                                        <span style="color: var(--text-muted); font-size: 11px;" title="{{ $document->created_at->format('d M Y H:i') }}">— {{ $document->uploaded_by ? 'added by ' . ($document->uploader->name ?? 'an agent') : 'from applicant' }}, {{ $document->created_at->diffForHumans() }}</span>
                                        @if($rentalApplication->submitted_at && $document->created_at->greaterThanOrEqualTo($rentalApplication->submitted_at))
                                            <span class="ds-badge ds-badge-warning" title="This document was added after the application was submitted">Added after submission</span>
                                        @endif
                                    @endif
                                    <span class="ds-badge ds-badge-success" x-show="markedUpDocIds.includes({{ $document->id }})" x-cloak title="This document has saved marks — visible to anyone who opens it next.">Marked up</span>
                                </span>
                                <span class="flex items-center gap-2 flex-shrink-0 ml-2">
                                    @if($row['inline_viewable'])
                                        <button type="button" style="color: var(--ds-blue, #2563eb); font-weight: 600;"
                                                @click="openContinuousView({{ $document->id }})">View &amp; Mark Up</button>
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
                                         Johan described: worthless once it's filed. A
                                         genuinely exceptional third action, present only
                                         on the rare not-yet-sorted row — never in
                                         conflict with "the two actions" for every
                                         ordinary, already-typed document. --}}
                                    @if($viewerRole === 'agent' && !$row['pulled_from_contact'] && $document->document_type_id === null && $document->mime_type === 'application/pdf')
                                        <form method="POST" action="{{ route('tools.pdf_splitter.intake_rental_application', [$rentalApplication, $document]) }}" style="display:inline;">
                                            @csrf
                                            <button type="submit" style="color: var(--ds-amber, #b45309); font-weight: 600; cursor: pointer; border: none; background: none; padding: 0;" title="This document hasn't been sorted into document types yet — split it into separate, filed documents before submitting for authorisation.">Split &amp; File</button>
                                        </form>
                                    @endif
                                </span>
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

        {{-- ASIDE — read-only capture-ledger tally, fixed 196px (ROUND 8 —
             see the layout <style> block's own comment). No drag, no
             resizer element: nothing to negotiate for in a fixed-width
             read-only panel. Never covered by anything, see the
             in-place-annotation note above the layout <style> block.

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
        <div class="rental-review-aside">
            <div class="flex items-center gap-1.5 mb-2">
                <h2 class="text-sm font-semibold" style="color: var(--text-primary);">{{ $viewerRole === 'agent' ? 'Affordability Assessment' : "Agent's Assessment" }}</h2>
                @if($viewerRole === 'agent')
                    <span class="ds-badge ds-badge-muted" style="cursor: help; padding: 0 5px;"
                          title="You type these — nothing here is pre-filled from the application, and nothing here is sent to the applicant or shown anywhere else.">?</span>
                @else
                    <span class="ds-badge ds-badge-muted" style="cursor: help; padding: 0 5px;"
                          title="Captured by the agent. You can add your own lines. If you disagree with a figure, strike it out — that opens a box to add the correct one right there. The struck line stays visible with what replaced it, never edited in place.">?</span>
                @endif
            </div>

            {{-- ROUND 6, 2026-09-11 — status banners that used to live inside
                 the ACTIONS card (bottom of the old right-hand column) moved
                 up here, next to the declined-reason banner that already
                 lived at the top of the page (see above, "$declineInfo").
                 Zone 4 below is action BUTTONS only now — 128px has no room
                 for prose, and these are outcomes the agent needs to see
                 the moment the strip is visible, not buried behind a click. --}}
            @if($viewerRole === 'agent')
                @if($moreInfoRequestedNote)
                    <div class="rounded-md px-3 py-1.5 text-xs mb-2" style="background: var(--surface-2); color: var(--ds-amber); border: 1px solid var(--ds-amber);">
                        <strong>The authoriser sent this back for more information:</strong>
                        <span class="whitespace-pre-wrap" style="color: var(--text-primary);"> {{ $moreInfoRequestedNote }}</span>
                    </div>
                @endif
                @if($rentalApplication->status === 'approved' && $rentalApplication->applicant_notified_at)
                    <div class="rounded-md px-3 py-1.5 text-xs mb-2" style="background: var(--ds-emerald-soft, #ecfdf5); color: var(--ds-emerald, #059669);">
                        &check; Approved for R{{ number_format($rentalApplication->approved_rental_amount, 2) }} a month. Sent to the applicant on {{ $rentalApplication->applicant_notified_at->format('d M Y, H:i') }}.
                    </div>
                @elseif($rentalApplication->status === 'approved')
                    {{-- AT-392 — Johan: "agent gets back and upon them being happy
                         it gets sent out." Approved but not yet sent: the agent
                         confirms/refines the tenant's wishlist (reusing the SAME
                         Core Matches form + drawer as the Buyer Pipeline detail
                         page — command-center/buyers/detail.blade.php:580-615,
                         not a second editor), then sends. --}}
                    <div class="rounded-md px-3 py-2 text-xs mb-2" style="background: var(--ds-amber-soft, #fffbeb); color: var(--ds-amber, #b45309); border: 1px solid var(--ds-amber, #f59e0b);">
                        <p class="font-semibold mb-1.5">&check; Approved for R{{ number_format($rentalApplication->approved_rental_amount, 2) }} a month — not yet sent.</p>
                        <p class="mb-2">Confirm what the tenant is looking for, then send the approval. If you skip this, the email still goes out with a general list of available rentals under their approved amount.</p>
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
                    <div class="rounded-md px-3 py-1.5 text-xs mb-2" style="background: var(--ds-red-soft, #fef2f2); color: var(--ds-red, #dc2626);">
                        Declined. The applicant has been notified.
                    </div>
                @elseif($isPendingAuthorisation)
                    <div class="rounded-md px-3 py-1.5 text-xs mb-2" style="background: var(--ds-blue-soft, #eff6ff); color: var(--ds-blue, #2563eb);">
                        Submitted for approval {{ $rentalApplication->submitted_for_approval_at->format('d M Y H:i') }} — awaiting the authoriser's decision.
                    </div>
                @endif
                <div class="flex items-center gap-1.5 text-xs px-2 py-1 rounded-md mb-2" x-show="saveStatus"
                     :style="saveError ? 'background: var(--ds-red-soft, #fef2f2); color: var(--ds-red, #dc2626);' : 'background: var(--ds-emerald-soft, #ecfdf5); color: var(--ds-emerald, #059669);'">
                    <span x-show="!saveError && saveStatus !== 'Saving…'">&check;</span>
                    <span x-text="saveStatus"></span>
                </div>
                <p class="text-xs mb-2" x-show="agentActionStatus" x-text="agentActionStatus" :style="agentActionError ? 'color: var(--ds-red, #dc2626);' : 'color: var(--ds-emerald, #059669);'"></p>
            @endif

            {{-- ROUND 8, 2026-09-11 — capture-ledger rework. Johan: "the
                 highlighter mark IS the ledger line." Read-only tally now
                 (income/expense entries come from drawing on the document
                 with a capture pen — document-highlighter-pages.blade.php
                 — never typed here), which is what lets this stay a fixed
                 196px column: see the layout <style> block's own comment.
                 statementPeriodFrom/To stay editable here (agent only,
                 authoriser reads the saved value) — they are per-DOCUMENT-
                 SET (the whole bank statement's own date range), not
                 per-line, so they were never part of what moved to the
                 document. --}}
            <div>
                @if($viewerRole === 'agent')
                    <div class="flex items-center justify-between gap-1 mb-1">
                        <label class="text-[11px] font-medium" style="color: var(--text-secondary);">Statement period</label>
                        <span class="ds-badge ds-badge-info flex-shrink-0" style="font-size: 9px; padding: 1px 4px;" title="Every field here saves the moment you click away from it — no button needed.">Autosaves</span>
                    </div>
                    <div class="grid grid-cols-2 gap-1">
                        <input type="date" class="corex-input text-xs w-full" x-model="statementPeriodFrom" @change="save()" aria-label="Statement period from">
                        <input type="date" class="corex-input text-xs w-full" x-model="statementPeriodTo" @change="save()" aria-label="Statement period to">
                    </div>
                    <p class="text-[11px] mt-1" style="color: var(--text-muted);" x-show="calculatedStatementMonths() || statementMonths">
                        <template x-if="calculatedStatementMonths()"><span>Covers <strong x-text="calculatedStatementMonths()"></strong> mo</span></template>
                        <template x-if="!calculatedStatementMonths() && statementMonths"><span>Currently <strong x-text="statementMonths"></strong> mo</span></template>
                    </p>
                @else
                    <label class="text-[11px] font-medium block mb-1" style="color: var(--text-secondary);">Statement period</label>
                    <p class="text-[11px]" style="color: var(--text-muted);">{{ $assessment->statement_period_from?->format('y/m/d') ?? '—' }} &ndash; {{ $assessment->statement_period_to?->format('y/m/d') ?? '—' }}</p>
                    @if($assessment->statement_months)
                        <p class="text-[11px]" style="color: var(--text-muted);">Covers {{ $assessment->statement_months }} mo</p>
                    @endif
                @endif
            </div>

            {{-- INCOME / EXPENSES — numbered, purple/amber badges (Johan's
                 own spec — a fixed panel-badge scheme, independent of
                 whichever agency-configurable highlighter colour was used
                 to actually draw the mark on the document). Number is
                 POSITIONAL (index+1 within the group), never stored — see
                 rentalCaptureLedger()'s own docblock.

                 Stage 2, 2026-09-11 — the whole row (not just the jump
                 glyph — Johan's own spec) opens the document the line came
                 from, on the right page, scrolls the mark into view, and
                 flashes it (jumpToMark(), on rentalReviewLayout() and this
                 shared script respectively — see their own comments). Only
                 an ANCHORED entry (a real drawn mark, row.document_id set)
                 is clickable; a migrated/manually-entered row with nothing
                 to jump to renders the same shape with an inert glyph
                 rather than a dead click target, per the panel's own
                 stated design ("shows without a mark number and with no
                 jump target"). --}}
            <div>
                <p class="rr-ledger-group-label">Income</p>
                <template x-if="incomeEntries().length === 0"><p class="text-[11px]" style="color: var(--text-muted);">None captured.</p></template>
                <template x-for="(row, idx) in incomeEntries()" :key="row.id">
                    <div class="rr-ledger-row" :style="{ cursor: row.document_id ? 'pointer' : 'default' }" :title="row.document_id ? 'Jump to this mark on the document' : ''" @click="jumpToMark(row)">
                        <span class="rr-ledger-badge" style="background: var(--ds-purple, #7c3aed);" x-text="idx + 1"></span>
                        <span class="text-[11px]" style="color: var(--text-secondary);" x-text="shortDate(row.entry_date)"></span>
                        <span class="rr-ledger-amount text-xs" style="color: var(--text-primary);" :title="row.entry_description" x-text="formatR(row.entry_amount)"></span>
                        <span class="text-[11px] text-right" :style="{ color: row.document_id ? 'var(--ds-blue, #2563eb)' : 'var(--text-muted)' }">&rarr;</span>
                    </div>
                </template>
            </div>
            <div>
                <p class="rr-ledger-group-label">Expenses</p>
                <template x-if="expenseEntries().length === 0"><p class="text-[11px]" style="color: var(--text-muted);">None captured.</p></template>
                <template x-for="(row, idx) in expenseEntries()" :key="row.id">
                    <div class="rr-ledger-row" :style="{ cursor: row.document_id ? 'pointer' : 'default' }" :title="row.document_id ? 'Jump to this mark on the document' : ''" @click="jumpToMark(row)">
                        <span class="rr-ledger-badge" style="background: var(--ds-amber, #f59e0b);" x-text="idx + 1"></span>
                        <span class="text-[11px]" style="color: var(--text-secondary);" x-text="shortDate(row.entry_date)"></span>
                        <span class="rr-ledger-amount text-xs" style="color: var(--text-primary);" :title="row.entry_description" x-text="formatR(row.entry_amount)"></span>
                        <span class="text-[11px] text-right" :style="{ color: row.document_id ? 'var(--ds-blue, #2563eb)' : 'var(--text-muted)' }">&rarr;</span>
                    </div>
                </template>
            </div>

            {{-- Totals — Income, Expenses, Months, Monthly income, Net
                 monthly (emphasised). No property-rent/30%-threshold
                 verdict here — that box lived in the deleted strip's own
                 Zone 3 and is not part of this panel's stated contents;
                 dropped, not relocated. Flagged in the build report. --}}
            <div class="pt-2" style="border-top: 1px solid var(--border);">
                <p class="text-xs flex items-center justify-between" style="color: var(--text-secondary);"><span>Income</span><span class="rr-ledger-amount" style="color: var(--text-primary);" x-text="formatR(incomeTotal())"></span></p>
                <p class="text-xs flex items-center justify-between" style="color: var(--text-secondary);"><span>Expenses</span><span class="rr-ledger-amount" style="color: var(--text-primary);" x-text="formatR(expenseTotal())"></span></p>
                <p class="text-xs flex items-center justify-between" style="color: var(--text-secondary);"><span>Months</span><span style="color: var(--text-primary);" x-text="statementMonths || '—'"></span></p>
                <p class="text-xs flex items-center justify-between" style="color: var(--text-secondary);"><span>Monthly income</span><span class="rr-ledger-amount" style="color: var(--text-primary);" x-text="formatR(monthlyIncome())"></span></p>
                <p class="text-sm font-bold flex items-center justify-between mt-1" style="color: var(--text-primary);"><span>Net monthly</span><span class="rr-ledger-amount" x-text="formatR(netMonthly())"></span></p>
            </div>

            {{-- Actions. "Add line manually" (Stage 3) renders per the
                 panel's own stated contents but is inert this stage — a
                 disabled button explaining why (title) rather than a
                 silently-missing one or a fake working one. --}}
            <div class="pt-2" style="border-top: 1px solid var(--border);">
                @if($viewerRole === 'agent')
                    <button type="button" class="corex-btn-outline text-xs w-full mb-1.5" style="opacity: 0.5; cursor: not-allowed;" disabled title="Manual entry — lands in a follow-up update, for figures with nothing to highlight.">Add line manually</button>
                    @if($canSendBackToApplicant)
                        <button type="button" class="corex-btn-outline text-xs w-full mb-1.5" @click="sendBackModalOpen = true">Send back to applicant</button>
                    @endif
                    @unless(in_array($rentalApplication->status, ['submitted_for_approval', 'approved', 'declined'], true) || $isPendingAuthorisation)
                        <button type="button" class="corex-btn-primary text-xs w-full mb-1.5" :disabled="submittingForApproval" @click="submitForApproval()" x-text="submittingForApproval ? 'Submitting…' : 'Submit for approval'"></button>
                    @endunless
                    @if($rentalApplication->generations->count() > 1)
                        <button type="button" class="text-[11px] underline w-full text-left" style="color: var(--ds-blue, #2563eb);" @click="submissionHistoryOpen = true">Submission history</button>
                    @endif
                @else
                    @if($blockedBySelfApproval)
                        <p class="text-[11px]" style="color: var(--text-muted);">You created this application — only another authoriser may act on it.</p>
                    @else
                        <button type="button" class="corex-btn-outline text-xs w-full mb-1.5" @click="sendBackToAgentModalOpen = true">Send back</button>
                        <button type="button" class="corex-btn-primary text-xs w-full mb-1.5" @click="approveModalOpen = true">Approve &amp; continue</button>
                        <button type="button" class="text-[11px] underline w-full text-left" style="color: var(--ds-crimson, #dc2626);" @click="declineModalOpen = true">Decline</button>
                    @endif
                @endif
            </div>
        </div>

        {{-- ROUND 6, 2026-09-11 — decision/send-back modals. Zone 4 in the
             strip above is buttons only (128px) — every field that used to
             sit permanently visible in the old right-hand column now opens
             here instead. Page-level siblings of the aside, same reason the
             Tenant Wishlist drawer already is one (see its own comment just
             below): never covered, never clipped by the strip's own
             overflow/scroll. --}}
        @if($viewerRole === 'agent')
            {{-- Send back to applicant — Johan: "I also dont see the need
                 for 2 section - send back for more info and reopen and send
                 back. does the same function - send it back and ask for
                 what you need." Merged into one button/modal; which real
                 server action fires is decided by canReopenNow (see
                 sendBackToApplicant() in the script below) — nothing the
                 old two-button screen could do is lost. Checklist is
                 DERIVED from RentalApplicationDocumentRequirement::
                 checklistFor() — the same agency-configurable, per-
                 employment-type mechanism the original application form's
                 own printed checklist already runs on — never Johan's own
                 mockup list verbatim, so it stays true as an agency
                 customises its own requirements. --}}
            <div x-show="sendBackModalOpen" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center p-4" style="background: rgba(0,0,0,0.5);" @keydown.escape.window="sendBackModalOpen = false">
                <div class="w-full max-w-md rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);" @click.outside="sendBackModalOpen = false">
                    <h3 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Send back to applicant</h3>
                    <p class="text-xs mb-3" style="color: var(--text-muted);">
                        {{-- Alpine's x-if clone mechanism needs a single root
                             ELEMENT inside the template, not bare text — a
                             text-only template's content has no
                             firstElementChild, which crashes Alpine's
                             internal clone/scope-attach step the instant a
                             second x-if template sits next to it. Wrap in a
                             <span>, same as every other x-if on this page
                             already does. --}}
                        <template x-if="canReopenNow"><span>They'll be emailed a link to fix what's wrong and re-sign — their previous answers stay pre-filled.</span></template>
                        <template x-if="!canReopenNow"><span>They'll be emailed what you type below. Everything they've already sent stays on file.</span></template>
                    </p>
                    <template x-if="documentChecklist.length">
                        <div class="mb-3">
                            <p class="text-xs font-medium mb-1" style="color: var(--text-secondary);">Ask for any of these documents</p>
                            <div class="space-y-1">
                                <template x-for="doc in documentChecklist" :key="doc.slug">
                                    <label class="flex items-center gap-2 text-xs cursor-pointer" style="color: var(--text-secondary);">
                                        <input type="checkbox" x-model="doc.checked">
                                        <span x-text="doc.label"></span>
                                    </label>
                                </template>
                            </div>
                        </div>
                    </template>
                    <textarea x-model="sendBackNote" rows="4" class="corex-input text-xs w-full mb-3" style="resize: none;"
                              placeholder="What do they need to fix or provide? e.g. ID number was typed incorrectly"></textarea>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="corex-btn-outline text-xs" @click="sendBackModalOpen = false">Cancel</button>
                        <button type="button" class="corex-btn-primary text-xs" :disabled="sendBackSending || !sendBackNote.trim()" @click="sendBackToApplicant()" x-text="sendBackSending ? 'Sending…' : 'Confirm and send'"></button>
                    </div>
                </div>
            </div>

            @if($rentalApplication->generations->count() > 1)
                <div x-show="submissionHistoryOpen" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center p-4" style="background: rgba(0,0,0,0.5);" @keydown.escape.window="submissionHistoryOpen = false">
                    <div class="w-full max-w-md rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);" @click.outside="submissionHistoryOpen = false">
                        <h3 class="text-sm font-semibold mb-2" style="color: var(--text-primary);">Submission history</h3>
                        <div class="space-y-1 mb-3">
                            @foreach($rentalApplication->generations as $gen)
                                <a href="{{ route('corex.rental-applications.generations.show', [$rentalApplication, $gen->generation]) }}" class="block text-xs underline" style="color: var(--ds-blue, #2563eb);">
                                    Submission {{ $gen->generation }} — {{ $gen->submitted_at->format('d M Y, H:i') }}
                                    @if($gen->generation === $rentalApplication->current_generation) (current) @endif
                                </a>
                            @endforeach
                        </div>
                        <div class="flex justify-end">
                            <button type="button" class="corex-btn-outline text-xs" @click="submissionHistoryOpen = false">Close</button>
                        </div>
                    </div>
                </div>
            @endif
        @else
            @unless($blockedBySelfApproval)
                {{-- Approve — same fields, same form/action/CSRF, same AT-401
                     confirm()+unload-guard-suppress @submit pattern as
                     before this round; only the surrounding chrome (now a
                     modal instead of an always-visible card) changed. --}}
                <div x-show="approveModalOpen" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center p-4" style="background: rgba(0,0,0,0.5);" @keydown.escape.window="approveModalOpen = false">
                    <div class="w-full max-w-md rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);" @click.outside="approveModalOpen = false">
                        <h3 class="text-sm font-semibold mb-2" style="color: var(--text-primary);">Approve</h3>
                        @if($alreadyDecided && $canOverride)
                            <p class="text-xs mb-2" style="color: var(--ds-amber, #b45309);">This overrides the existing decision — a reason is required.</p>
                        @endif
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Monthly amount</label>
                        <input type="text" inputmode="decimal" x-model="approveAmount" class="corex-input text-sm w-full mb-2" placeholder="0.00">
                        <textarea x-model="approveReason" rows="2" class="corex-input text-xs w-full mb-3" placeholder="{{ $alreadyDecided ? 'Reason for override (required)' : 'Notes (optional)' }}"></textarea>
                        <form method="POST" action="{{ route('corex.rental-applications.authorisation.approve', $rentalApplication) }}"
                              @submit="if (!confirm('Approve this tenant for R' + Number(approveAmount || 0).toLocaleString('en-ZA', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '?')) { $event.preventDefault(); return; } window.__raSuppressUnloadGuard = true; $refs.approveAmountField.value = approveAmount; $refs.approveReasonField.value = approveReason">
                            @csrf
                            <input type="hidden" name="approved_rental_amount" x-ref="approveAmountField">
                            <input type="hidden" name="reason" x-ref="approveReasonField">
                            <div class="flex justify-end gap-2">
                                <button type="button" class="corex-btn-outline text-xs" @click="approveModalOpen = false">Cancel</button>
                                <button type="submit" class="corex-btn-primary text-xs" :disabled="!approveAmount">Approve</button>
                            </div>
                        </form>
                    </div>
                </div>

                {{-- Decline — same fields/form/pattern as before. --}}
                <div x-show="declineModalOpen" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center p-4" style="background: rgba(0,0,0,0.5);" @keydown.escape.window="declineModalOpen = false">
                    <div class="w-full max-w-md rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);" @click.outside="declineModalOpen = false">
                        <h3 class="text-sm font-semibold mb-2" style="color: var(--text-primary);">Decline</h3>
                        <textarea x-model="declineReason" rows="3" class="corex-input text-xs w-full mb-3" placeholder="Reason for decline (required) — the agent will see this"></textarea>
                        <form method="POST" action="{{ route('corex.rental-applications.authorisation.decline', $rentalApplication) }}"
                              @submit="if (!confirm('Decline this application?')) { $event.preventDefault(); return; } window.__raSuppressUnloadGuard = true; $refs.declineReasonField.value = declineReason">
                            @csrf
                            <input type="hidden" name="reason" x-ref="declineReasonField">
                            <div class="flex justify-end gap-2">
                                <button type="button" class="corex-btn-outline text-xs" @click="declineModalOpen = false">Cancel</button>
                                <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626); border-color: var(--ds-red, #dc2626);" :disabled="!declineReason.trim()">Decline</button>
                            </div>
                        </form>
                    </div>
                </div>

                {{-- Send back — this is the AUTHORISER's own "Request more
                     information," unchanged: sends this back to the AGENT,
                     never the applicant. Not part of the two-actions-to-
                     the-applicant merge above — a genuinely separate flow,
                     kept separate. --}}
                @unless($alreadyDecided)
                    <div x-show="sendBackToAgentModalOpen" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center p-4" style="background: rgba(0,0,0,0.5);" @keydown.escape.window="sendBackToAgentModalOpen = false">
                        <div class="w-full max-w-md rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);" @click.outside="sendBackToAgentModalOpen = false">
                            <h3 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Send back</h3>
                            <p class="text-xs mb-2" style="color: var(--text-muted);">Sends this back to the agent, not the applicant.</p>
                            <textarea x-model="moreInfoReason" rows="3" class="corex-input text-xs w-full mb-3" placeholder="What's missing? (required)"></textarea>
                            <form method="POST" action="{{ route('corex.rental-applications.authorisation.request-more-info', $rentalApplication) }}"
                                  @submit="if (!confirm('Send this back to the agent for more information?')) { $event.preventDefault(); return; } window.__raSuppressUnloadGuard = true; $refs.moreInfoReasonField.value = moreInfoReason">
                                @csrf
                                <input type="hidden" name="reason" x-ref="moreInfoReasonField">
                                <div class="flex justify-end gap-2">
                                    <button type="button" class="corex-btn-outline text-xs" @click="sendBackToAgentModalOpen = false">Cancel</button>
                                    <button type="submit" class="corex-btn-outline text-xs" :disabled="!moreInfoReason.trim()">Send</button>
                                </div>
                            </form>
                        </div>
                    </div>
                @endunless
            @endunless
        @endif

        {{-- ROUND 7, 2026-09-11 — continuous multi-document mark-up view.
             Johan, verbatim, on application 107 after the splitter turned
             one 17-page upload into five separate documents: "split the
             document, now it gives me all the pages to view and mark up.
             Can we still load this as 1 view and mark." Marking up a
             bundle is ONE continuous job (bank statement, then the
             payslip, then the ID) — splitting is for FILING, not for how
             the agent reads. Investigated FICA first, per his own
             instruction ("Fica I think has the same scenario"):
             compliance/fica/show.blade.php renders EVERY uploaded
             document inline, stacked, in one page — never one-at-a-time
             behind a click. That is the structural lesson copied here
             (all documents together, not a click-through) — NOT FICA's
             rendering mechanism itself (a plain `<iframe>` per document,
             delegating to the browser's own native PDF viewer), which
             has no way to draw a highlight/note overlay on top and — more
             importantly for Johan's own explicit loading requirement —
             no progressive per-page loading at all; it would fetch each
             whole PDF up front, exactly what he separately warned against
             ("a real bundle will be worse").

             Architecture: EVERY inline-viewable document gets its OWN,
             fully independent `rentalDocumentHighlighter()` instance
             (the exact same factory a single click-to-view used before —
             its internals are untouched, this reuses it rather than
             forking a second copy of logic the file's own docblock calls
             "hard-won, easy-to-reintroduce"). Because each instance owns
             its own pages/marks/renderedPageSize, a mark in one document
             cannot leak into another's coordinate space — the 0–1
             fraction system is completely unchanged, per document, per
             page, exactly as it already was.

             Loading stays progressive at the DOCUMENT level too, not just
             the page level: each section only starts fetching once it's
             scrolled within ~1000px of view (IntersectionObserver against
             the scroll container, not the browser viewport, since this
             runs inside its own overlay), then loads page 1 immediately
             and the rest behind it exactly like the single-document
             viewer already did — the same "page 1 now, N more loading"
             banner from document-highlighter-pages.blade.php applies
             per-section, unchanged. A 5-document bundle mostly loads at
             once because 5 sections all sit within that margin on open;
             a much larger bundle would only load the first few until the
             agent scrolls further — this is the part Johan named
             specifically as needing to survive scale.

             The old single-document "View & Mark Up" toggle (one shared
             activeDocId on the root component, the sticky header's
             toolbar swapping to a Save/Done pair) is retired, not kept
             alongside this — Johan's own words framed the click-through
             experience itself as the defect ("opens a viewer, marks,
             closes it, opens the next, five times over"), so a second
             surviving way to do the same job one document at a time would
             just reintroduce the thing being fixed. The shared factory
             function itself is unchanged; only ITS ROOT-LEVEL SPREAD's
             now-dead activeDocId/pages/marks are unused at the root
             (markedUpDocIds, also from that same spread, stays — the
             per-row "Marked up" badge still reads it). --}}
        <div x-show="continuousViewOpen" x-cloak class="fixed inset-0 z-[110]" style="background: var(--surface);">
            <div class="flex h-full">
                <div class="flex-shrink-0 overflow-y-auto" style="width: 220px; border-right: 1px solid var(--border); padding: 12px; background: var(--surface-2, #f9fafb);">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Documents</h3>
                        <button type="button" class="text-xs" style="color: var(--text-muted);" @click="continuousViewOpen = false">Close</button>
                    </div>
                    <nav class="space-y-1">
                        @foreach($documents as $row)
                            <a href="#cv-doc-{{ $row['document']->id }}" class="block text-xs truncate px-1 py-1 rounded" style="color: var(--ds-blue, #2563eb);" title="{{ $row['document']->original_name }}">{{ $row['document']->original_name }}</a>
                        @endforeach
                    </nav>
                </div>
                <div class="flex-1 overflow-y-auto" id="continuousViewScroll" style="scroll-behavior: smooth;">
                    <div class="max-w-4xl mx-auto p-4 space-y-6">
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
                                // Capture-ledger rework, 2026-09-11 — create is per-document
                                // (a brand new mark needs one to attach to); update/delete act
                                // on the rental application directly (an entry outlives
                                // whichever document it was drawn on), so those two are a URL
                                // TEMPLATE with a literal placeholder, filled client-side by
                                // captureUrlFor().
                                $captureCreateUrl = $viewerRole === 'agent'
                                    ? route('corex.rental-applications.documents.capture-entries.store', [$rentalApplication, $document])
                                    : route('corex.rental-applications.authorisation.documents.capture-entries.store', [$rentalApplication, $document]);
                                $captureUpdateUrlTemplate = $viewerRole === 'agent'
                                    ? route('corex.rental-applications.capture-entries.update', [$rentalApplication, '__MARK_UID__'])
                                    : route('corex.rental-applications.authorisation.capture-entries.update', [$rentalApplication, '__MARK_UID__']);
                                $captureDeleteUrlTemplate = $viewerRole === 'agent'
                                    ? route('corex.rental-applications.capture-entries.destroy', [$rentalApplication, '__MARK_UID__'])
                                    : route('corex.rental-applications.authorisation.capture-entries.destroy', [$rentalApplication, '__MARK_UID__']);
                            @endphp
                            <section id="cv-doc-{{ $document->id }}">
                                <h2 class="text-sm font-semibold pb-1 mb-2" style="color: var(--text-primary); border-bottom: 1px solid var(--border);">
                                    {{ $document->original_name }}
                                    <span class="text-xs font-normal" style="color: var(--text-muted);">&middot; {{ $document->documentType->label ?? 'Untyped' }}</span>
                                </h2>
                                @if($row['inline_viewable'])
                                    <div x-data="rentalDocumentHighlighter({
                                            initialMarkedUpDocIds: {{ Js::from($initialMarkedUpDocIds) }},
                                            currentUserId: {{ Js::from(auth()->id()) }},
                                            currentUserName: {{ Js::from(auth()->user()->name) }},
                                            currentUserRole: {{ Js::from($viewerRole) }},
                                            highlighters: {{ Js::from($highlighters) }},
                                         })"
                                         x-init="
                                            initHighlighterPrefs();
                                            activeDocId = {{ $document->id }};
                                            firstPageUrl = {{ Js::from($highlightFirstUrl) }};
                                            remainingPagesUrl = {{ Js::from($highlightRemainingUrl) }};
                                            postUrl = {{ Js::from($highlightPostUrl) }};
                                            captureCreateUrl = {{ Js::from($captureCreateUrl) }};
                                            captureUpdateUrlTemplate = {{ Js::from($captureUpdateUrlTemplate) }};
                                            captureDeleteUrlTemplate = {{ Js::from($captureDeleteUrlTemplate) }};
                                            label = {{ Js::from($document->original_name) }};
                                            const cvScroll = $el.closest('#continuousViewScroll');
                                            const cvIo = new IntersectionObserver((entries) => {
                                                if (entries[0].isIntersecting) { loadDocument(); cvIo.disconnect(); }
                                            }, { root: cvScroll, rootMargin: '1000px 0px' });
                                            cvIo.observe($el);
                                         ">
                                        <div class="flex items-center gap-3 mb-2">
                                            <span class="text-xs font-semibold" style="color: var(--text-secondary);" x-show="!loading">
                                                <span x-text="markCount()"></span> mark<span x-show="markCount() !== 1">s</span>
                                            </span>
                                            <button type="button" class="corex-btn-primary text-xs" x-show="!loading && !loadError"
                                                    :disabled="applying || pagesLoading" :title="pagesLoading ? 'Still loading the rest of this document' : ''"
                                                    x-text="applying ? 'Saving…' : (pagesLoading ? 'Loading…' : 'Save')" @click="applyHighlights()"></button>
                                            <span class="text-xs" x-show="justSaved" x-cloak style="color: var(--ds-emerald, #059669);">&check; Saved</span>
                                        </div>
                                        @include('corex.rental-applications.partials.document-highlighter-pages')
                                    </div>
                                @else
                                    <p class="text-xs" style="color: var(--text-muted);">This file type cannot be previewed on screen — use Download on the Supporting Documents list to view it.</p>
                                @endif
                            </section>
                        @endforeach
                    </div>
                </div>
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
             command-center/buyers/detail.blade.php:583-616 otherwise.

             BLOCKER FIX, 2026-09-11 — this block referenced $existingWishlist/
             $wishlistPrefill (only computed by RentalApplicationReviewController
             ::show(), never by RentalApplicationAuthorisationController::show())
             with no $viewerRole guard of its own, so an authoriser opening ANY
             approved-not-yet-notified application 500'd on an undefined
             variable — application 76 is exactly that state and is what Johan
             is testing on next. Not gating this as a null-safe fallback: the
             wishlist-add/update routes it posts to
             (corex.rental-applications.review.wishlist.*) only exist under the
             AGENT controller — there is no authoriser equivalent — and the only
             way to OPEN this drawer at all is the trigger button a few hundred
             lines up, which already sits behind its own
             `@if($viewerRole === 'agent')` (see that block's own comment,
             "AT-392 — Johan: agent gets back and upon them being happy it gets
             sent out"). This is genuinely an agent-only step in the approval
             workflow, not a shared one the authoriser has any action to take
             in — the authoriser's job ends at the decision; confirming the
             tenant's wishlist and sending the approval email is the agent's
             follow-up, from their own screen. Gating the whole block (not just
             patching the undefined variable) matches that reality instead of
             rendering dead markup an authoriser can never reach anyway. --}}
        @if($viewerRole === 'agent' && $rentalApplication->status === 'approved' && !$rentalApplication->applicant_notified_at)
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
/**
 * Capture-ledger rework, 2026-09-11 — Johan: "it stays split and
 * disconnected" (the old separate ledger strip). "The highlighter mark IS
 * the ledger line" — shared by rentalReview() (agent) and
 * rentalAuthorisationViewer() (authoriser), spread into both root
 * components exactly like rentalDocumentHighlighter() already is, for the
 * same reason: one copy of the tally logic, not two independently-
 * maintained ones.
 *
 * `captureEntries` is a FLAT array of every active income/expense mark for
 * this application (server-hydrated once via toMarkArray() — see either
 * controller's show()), kept live client-side as marks are captured/
 * edited/removed through the document (addCaptureEntry()/
 * updateCaptureEntry()/removeCaptureEntry(), called from
 * document-highlighter-script.blade.php's own capture-chip save/update/
 * delete handlers) — never re-fetched, matching how the highlighter's own
 * `marks` array is kept live rather than reloaded after every draw.
 *
 * Numbering (the panel's "numbered badge") is POSITIONAL — index+1 within
 * each group, recomputed every render from whatever order captureEntries
 * is currently in (oldest-captured first, matching the server's own
 * `orderBy('created_at')->orderBy('id')` hydration and how a newly
 * captured entry is appended) — never a stored number, so it can never
 * drift from what's actually on screen.
 */
function rentalCaptureLedger({ initialCaptureEntries } = {}) {
    return {
        captureEntries: initialCaptureEntries || [],
        incomeEntries() {
            return this.captureEntries.filter(e => e.entry_type === 'income');
        },
        expenseEntries() {
            return this.captureEntries.filter(e => e.entry_type === 'expense');
        },
        // Sums exactly what the server will sum — same rows, same filter,
        // plain addition (no server-side qualifyingResult() dependency any
        // more; that computation still exists, unused by this screen now,
        // since it read the old income/expense-item tables this rework
        // replaces as this screen's source of truth).
        incomeTotal() {
            return this.incomeEntries().reduce((sum, e) => sum + (parseFloat(e.entry_amount) || 0), 0);
        },
        expenseTotal() {
            return this.expenseEntries().reduce((sum, e) => sum + (parseFloat(e.entry_amount) || 0), 0);
        },
        monthlyIncome() {
            const months = parseInt(this.statementMonths, 10);
            if (!months || months < 1) return null;
            return this.incomeTotal() / months;
        },
        netMonthly() {
            const months = parseInt(this.statementMonths, 10);
            if (!months || months < 1) return null;
            return (this.incomeTotal() - this.expenseTotal()) / months;
        },
        formatR(v) {
            return v === null || v === undefined ? '—' : 'R ' + Number(v).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        // yy/mm/dd — Johan's own format for this panel. A plain string
        // split, not a Date parse/reformat: entry_date always arrives as a
        // stable 'YYYY-MM-DD' from the server (see either model's
        // toMarkArray()), so there is no timezone-shift risk a Date object
        // parse of a bare date string can introduce.
        shortDate(d) {
            if (!d) return '—';
            const parts = String(d).split('-');
            if (parts.length !== 3) return d;
            return parts[0].slice(2) + '/' + parts[1] + '/' + parts[2];
        },
        addCaptureEntry(entry) {
            this.captureEntries.push(entry);
        },
        updateCaptureEntry(entry) {
            const idx = this.captureEntries.findIndex(e => e.id === entry.id);
            if (idx !== -1) this.captureEntries.splice(idx, 1, entry);
        },
        removeCaptureEntry(markUid) {
            const idx = this.captureEntries.findIndex(e => e.id === markUid);
            if (idx !== -1) this.captureEntries.splice(idx, 1);
        },
    };
}

function rentalReview({ saveUrl, initial, initialCaptureEntries, initialSavedAt, initialMarkedUpDocIds, currentUserId, currentUserName, currentUserRole, highlighters, requestMoreInfoUrl, submitForApprovalUrl, reopenUrl, expectedGeneration, canReopenNow, documentChecklist }) {
    return {
        // 2026-09-08 — the highlight/note viewer state+methods (activeDocId,
        // pages, marks, openHighlighter()/applyHighlights()/etc.) now live in
        // the shared rentalDocumentHighlighter() factory (see
        // partials/document-highlighter-script.blade.php, included below)
        // — the authoriser screen spreads the same factory in rather than
        // this logic being copy-pasted a second time.
        ...rentalDocumentHighlighter({ initialMarkedUpDocIds, currentUserId, currentUserName, currentUserRole, highlighters }),
        // Capture-ledger rework, 2026-09-11 — shared with rentalAuthorisationViewer()
        // for the same reason rentalDocumentHighlighter() is: identical
        // tally logic, one copy. See its own docblock for the full
        // reasoning (rentalCaptureLedger(), defined further below).
        ...rentalCaptureLedger({ initialCaptureEntries }),

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
        },
        // ROUND 6, 2026-09-11 — Johan: "I also dont see the need for 2
        // section - send back for more info and reopen and send back. does
        // the same function - send it back and ask for what you need."
        // Investigation found the two ORIGINAL actions genuinely differ —
        // reopen() (below, via reopenUrl) changes status, unlocks the
        // applicant's own typed fields for editing, and requires
        // re-signature; requestMoreInfo() (further below, via
        // requestMoreInfoUrl) is notify-only, no unlock. Nothing is dropped
        // by merging the BUTTON: canReopenNow (computed server-side, same
        // gate as the original Reopen button) decides which real action
        // fires, always preferring the fuller one (reopen) when it's legally
        // available for this application's current status, falling back to
        // the notify-only path otherwise — the applicant never gets LESS
        // than the old two-button screen could do, and the agent never has
        // to choose between two buttons that looked like they did the same
        // thing but didn't.
        sendBackModalOpen: false,
        sendBackNote: '',
        sendBackSending: false,
        documentChecklist: (documentChecklist || []).map(d => ({ ...d, checked: false })),
        canReopenNow: !!canReopenNow,
        async sendBackToApplicant() {
            if (this.sendBackSending || !this.sendBackNote.trim()) return;
            const requested = this.documentChecklist.filter(d => d.checked).map(d => d.label);
            const note = requested.length ? (this.sendBackNote.trim() + '\n\nPlease also send: ' + requested.join(', ')) : this.sendBackNote.trim();
            this.sendBackSending = true;
            this.agentActionStatus = '';
            try {
                const url = this.canReopenNow ? reopenUrl : requestMoreInfoUrl;
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ note }),
                });
                const data = await res.json().catch(() => ({}));
                if (res.ok && data.ok) {
                    this.agentActionError = false;
                    this.agentActionStatus = data.mail_sent
                        ? (this.canReopenNow ? 'Reopened — the applicant has been emailed.' : 'Sent to the applicant.')
                        : 'Logged, but the email could not be sent — check their email address.';
                    this.sendBackModalOpen = false;
                    this.sendBackNote = '';
                    this.documentChecklist.forEach(d => d.checked = false);
                    if (this.canReopenNow) setTimeout(() => window.location.reload(), 900);
                } else {
                    this.agentActionError = true;
                    this.agentActionStatus = data.error || 'Could not send — try again.';
                }
            } catch (e) {
                this.agentActionError = true;
                this.agentActionStatus = 'Could not send — check your connection.';
            }
            this.sendBackSending = false;
        },
        submissionHistoryOpen: false,
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
        // ── Authoriser flow — the agent's action ──────────────────────────
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
        // reopenApplication()/requestMoreInfo() (the two separate actions
        // this used to be) are merged into sendBackToApplicant() above,
        // 2026-09-11 — see that method's own comment for why neither one
        // was simply deleted.
        // A generation-conflict response (409) means the applicant resubmitted
        // since this page loaded — every write action's error handling calls
        // this instead of a generic "try again" so the agent knows to reload
        // rather than retry blindly against content that no longer exists.
        handleGenerationConflict(data) {
            this.agentActionError = true;
            this.agentActionStatus = 'This application changed since you opened it — the applicant resubmitted. Reload the page to see the new version.';
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
            // Capture-ledger rework, 2026-09-11 — income_items/expense_items
            // REMOVED from this payload. The highlighter mark is the ledger
            // line now (captureEntryCreate()/Update()/Delete(), a separate
            // immediate save per entry) — this endpoint's own job shrank to
            // notes/statement period/unpaid flag. Sending an empty/absent
            // array here would have told the server "the agent's whole list
            // is now empty" and soft-deleted every existing row — see
            // RentalApplicationReviewController::saveAssessment()'s own
            // comment for the full reasoning.
            fetch(saveUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    notes: this.fields.notes,
                    statement_period_from: this.statementPeriodFrom,
                    statement_period_to: this.statementPeriodTo,
                    has_unpaid_transactions: this.hasUnpaidTransactions,
                    expected_generation: this.expectedGeneration,
                }),
            }).then(r => r.json().then(data => ({ ok: r.ok, status: r.status, data }))).then(({ ok, status, data }) => {
                if (ok && data.ok) {
                    // The server is the single source of truth for the
                    // derived count (see calculateStatementMonths() above) —
                    // sync it back so the read-only "Currently N months"
                    // line and the monthly-figures math agree with what was
                    // actually persisted, not just the client's own guess.
                    if (data.statement_months !== undefined) {
                        this.statementMonths = data.statement_months ?? this.statementMonths;
                    }
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
        // ROUND 7, 2026-09-11 — the continuous multi-document mark-up view.
        // Lives here (not on rentalReview()/rentalAuthorisationViewer()
        // separately) for the same reason wishlistDrawerOpen does: the
        // trigger buttons (Supporting Documents rows) and the overlay
        // itself are both descendants of .rental-review-columns regardless
        // of role, so one shared toggle here works for both without
        // duplicating it per role.
        continuousViewOpen: false,
        openContinuousView(scrollToDocId) {
            this.continuousViewOpen = true;
            if (scrollToDocId) {
                this.$nextTick(() => {
                    const el = document.getElementById('cv-doc-' + scrollToDocId);
                    if (el) el.scrollIntoView({ block: 'start' });
                });
            }
        },
        // Stage 2, 2026-09-11 — the capture panel's row-click-to-jump.
        // Lives here (not on rentalCaptureLedger(), which owns the row/
        // captureEntries data this reads) for the same reason
        // openContinuousView() does: it needs to open/scroll the
        // continuous view, which only exists on THIS scope. A row's
        // @click="jumpToMark(row)" resolves here via Alpine's normal
        // ancestor-scope walk (.rental-review-aside is a descendant of
        // this x-data, same as the drawer trigger button already was) —
        // no cross-scope plumbing needed for the CALL itself. Reaching the
        // right per-document rentalDocumentHighlighter() instance to
        // actually scroll/flash the mark DOES need one, since those are
        // genuinely separate x-data instances one level further down (one
        // per document, inside the continuous view) — a window event
        // bridges that, the same pattern the capture chip already uses in
        // the other direction (see rentalCaptureLedger()'s own comment).
        jumpToMark(row) {
            if (!row.document_id) return; // migrated/manual entry — nothing to jump to, the row's own inert glyph already says so
            this.openContinuousView(row.document_id);
            this.$dispatch('rental-jump-to-mark', { documentId: row.document_id, markId: row.id });
        },
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
        //
        // ROUND 6, 2026-09-11 — Johan, after cc1 verified Round 4's tighter
        // layout: "not going to work. too little width left to properly
        // see the pdf." The panel is no longer a side column at all — it's
        // a bottom strip, and the drag is now VERTICAL (height), not
        // horizontal (width). Same mechanism, same constant-naming
        // convention, renamed RA_STRIP_*: floor (260px) still shows the
        // strip's own header row plus the ledger's input row — verified
        // live; ceiling (560px) still leaves a genuinely readable document
        // on a normal viewport; default (320px) matches Johan's own
        // approved mockup — four zones, three ledger rows visible.
        RA_STRIP_DEFAULT_PX: 320,
        RA_STRIP_MIN_PX: 260,
        RA_STRIP_MAX_PX: 560,
        // Persisted per-browser so a drag survives a reload. A browser that
        // dragged the OLD width-based control (rentalReviewAsideWidth) has
        // no bearing on this new height control — separate localStorage
        // key, separate axis, starts fresh at the default. Repeats the
        // RA_STRIP_* numbers as literals rather than referencing them — a
        // plain object literal can't read a sibling property via `this`
        // while it's still being constructed. startStripResize()'s own
        // clamp below (evaluated later, as a real method call) uses the
        // named constants directly.
        resizingStrip: false,
        stripHeight: Math.min(560, Math.max(260, parseInt(localStorage.getItem('rentalReviewStripHeight'), 10) || 320)),
        startStripResize(e) {
            this.resizingStrip = true;
            const startY = e.clientY;
            const startHeight = this.stripHeight;
            // Plain DOM lookup, not Alpine's $el magic — the width-drag
            // above already found (under a REAL mouse drag, not a
            // synthetic dispatched event) that `this.$el` read from inside
            // this method resolves to something that silently fails to
            // affect the real node's inline style. e.target is the
            // resizer handle itself (a genuine native event, always
            // reliable); walking up to its one fixed ancestor sidesteps
            // whatever Alpine-context quirk caused that.
            const columnsEl = e.target.closest('.rental-review-columns');
            const onMove = (ev) => {
                if (!this.resizingStrip) return;
                // Dragging the handle UP (negative delta) grows the strip —
                // the strip sits BELOW the handle.
                const next = startHeight - (ev.clientY - startY);
                this.stripHeight = Math.min(this.RA_STRIP_MAX_PX, Math.max(this.RA_STRIP_MIN_PX, next));
                columnsEl.style.setProperty('--rr-strip-h', this.stripHeight + 'px');
                // Mark re-projection (RoleBlockNormalizer's toDisplayX/Y
                // helpers, fed by the ResizeObserver on each page <img>)
                // must hold THROUGHOUT the drag, not just once it settles —
                // the PDF's rendered box genuinely changes size on every
                // single mousemove now that the strip height eats directly
                // into .rental-review-main's available height. That
                // ResizeObserver already fires on every layout change with
                // no extra wiring needed here; this comment documents WHY
                // that existing mechanism is now load-bearing mid-drag, not
                // just at rest.
            };
            const onUp = () => {
                if (!this.resizingStrip) return;
                this.resizingStrip = false;
                localStorage.setItem('rentalReviewStripHeight', String(this.stripHeight));
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
            };
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        },
        init() {
            this.$el.style.setProperty('--rr-strip-h', this.stripHeight + 'px');

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

function rentalAuthorisationViewer({ initialMarkedUpDocIds, currentUserId, currentUserName, currentUserRole, highlighters, initialCaptureEntries, initialStatementMonths }) {
    return {
        // Shared highlight/note viewer — see partials/document-highlighter-script.blade.php.
        ...rentalDocumentHighlighter({ initialMarkedUpDocIds, currentUserId, currentUserName, currentUserRole, highlighters }),
        // Capture-ledger rework, 2026-09-11 — see rentalCaptureLedger()'s
        // own docblock above rentalReview() for the full reasoning; same
        // factory, same tally, read-only either way (no edit UI in the new
        // panel for anyone — capture happens on the document itself).
        ...rentalCaptureLedger({ initialCaptureEntries }),
        // statementMonths has no editable UI on the authoriser's side (the
        // dates are the agent's own field, read-only here) — just the
        // number needed for monthlyIncome()/netMonthly() to compute.
        statementMonths: initialStatementMonths ?? '',

        // Decision panel fields — unchanged from before this screen grew a document viewer.
        approveAmount: '',
        approveReason: '',
        declineReason: '',
        moreInfoReason: '',
        // ROUND 6, 2026-09-11 — these fields used to sit permanently visible
        // in the old right-hand column; Zone 4 is buttons only now (128px),
        // so each opens the same fields in a modal instead. The fields, the
        // forms, the @submit confirm()+unload-guard-suppress pattern (AT-401)
        // and the server endpoints are all unchanged — only where the agent
        // types is different.
        approveModalOpen: false,
        declineModalOpen: false,
        sendBackToAgentModalOpen: false,

        init() {
            this.initHighlighterPrefs();
        },
    };
}

// Capture-ledger rework, 2026-09-11 — rentalAssessmentEditor() REMOVED
// (was here: strike-and-add for the agent's income/expense lines, AT-392).
// Fully superseded — the authoriser's read-only tally now comes from
// rentalCaptureLedger() above, same marks-based source the agent's own
// panel uses. Its old incomeItems/expenseItems/toggleStrike()/addItem()
// mechanics operated on RentalApplicationIncomeItem/ExpenseItem, models
// this rework does not write to any more (see saveAssessment()'s own
// comment) — nothing in the new template references this function.
</script>
@endsection
