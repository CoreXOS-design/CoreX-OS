{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- AT-392 Phase 2 — agent review split-screen. Johan's own words: "application
     gets returned, agent open application - sees application and supporting docs
     on left panel of screen... then have a place on the right panel to input
     things like - income, salary / etc etc... doing the calcs to the bottom to
     see if tenant qualifies." --}}
@extends('layouts.corex')

@php
    // Computed here, not inline inside the x-data string below — a
    // multi-line arrow-function/array literal nested inside a Blade
    // expression has already caused one real outage on this feature
    // (rental-applications/public/show.blade.php's @json() incident,
    // 2026-09-08) — never assume a closure inline in a Blade echo is safe
    // without proving the compiled output first.
    $initialIncomeItems = $assessment->incomeItems->map(fn ($i) => [
        'id' => $i->id, 'description' => $i->description, 'amount' => $i->amount,
    ])->values();
    $initialExpenseItems = $assessment->expenseItems->map(fn ($i) => [
        'id' => $i->id, 'description' => $i->description, 'amount' => $i->amount,
    ])->values();
@endphp

@section('corex-content')
<div class="w-full"
     x-data="rentalReview({
         saveUrl: '{{ route('corex.rental-applications.review.assessment', $rentalApplication) }}',
         initial: {
             notes: {{ Js::from($assessment->notes) }},
             statement_months: {{ Js::from($assessment->statement_months) }},
             has_unpaid_transactions: {{ Js::from((bool) $assessment->has_unpaid_transactions) }},
         },
         initialIncomeItems: {{ Js::from($initialIncomeItems) }},
         initialExpenseItems: {{ Js::from($initialExpenseItems) }},
         initialResult: {{ Js::from($result) }},
         initialSavedAt: {{ $assessment->exists ? Js::from($assessment->updated_at->toIso8601String()) : 'null' }},
         initialMarkedUpDocIds: {{ Js::from($documents->filter(fn ($row) => $row['has_highlights'])->pluck('document.id')->values()) }},
         currentUserId: {{ Js::from(auth()->id()) }},
         currentUserName: {{ Js::from(auth()->user()->name) }},
         currentUserRole: 'agent',
         requestMoreInfoUrl: '{{ route('corex.rental-applications.review.request-more-info', $rentalApplication) }}',
         submitForApprovalUrl: '{{ route('corex.rental-applications.review.submit-for-approval', $rentalApplication) }}',
     })">

    {{-- Sticky header, 2026-09-08 — second time today the same fault: controls
         stranded off-screen while the user scrolls a long surface. Same fix as
         the rental application form (resources/views/corex/rental-applications/
         show.blade.php) — the shared x-sticky-action-bar component, not a new
         invention. Its right slot swaps between "Back to application" (normal)
         and the highlighter's own toolbar + Save button (while a document is
         open for marking) — Johan: "place them in a header so the agent can
         scroll and see the highlighter as well as the save buttons at all
         times." --}}
    <x-sticky-action-bar>
        <x-slot name="left">
            <div class="min-w-0">
                <h1 class="text-sm font-bold leading-tight truncate" style="color: var(--text-primary);">
                    Application Review — {{ $rentalApplication->contact->full_name ?? $rentalApplication->contact->first_name . ' ' . $rentalApplication->contact->last_name }}
                </h1>
                <p class="text-xs truncate" style="color: var(--text-muted);">
                    {{ $rentalApplication->property_address_override ?? optional($rentalApplication->property)->address ?? 'No property linked' }}
                </p>

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
                    link-property route and reloads.
                --}}
                <div class="mt-1" x-data="rentalReviewPropertyLink({{ Js::from([
                    'searchUrl' => route('corex.rental-applications.search-properties'),
                    'linkUrl' => route('corex.rental-applications.review.link-property', $rentalApplication),
                    'currentLabel' => optional($rentalApplication->property)->title
                        ?: optional($rentalApplication->property)?->buildDisplayAddress(),
                ]) }})">
                    <template x-if="!searching">
                        <p class="text-xs">
                            <span style="color: var(--text-muted);" x-show="currentLabel" x-text="'Linked for affordability: ' + currentLabel"></span>
                            <span style="color: var(--ds-amber, #d97706);" x-show="!currentLabel">Link a property to check against its rent</span>
                            <button type="button" class="underline ml-1" style="color: var(--brand-icon, #2563eb);" @click="searching = true">
                                <span x-text="currentLabel ? 'Change' : 'Link a property'"></span>
                            </button>
                            <button type="button" class="underline ml-1" style="color: var(--ds-red, #dc2626);" x-show="currentLabel" @click="clear()">Clear</button>
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
            </div>
        </x-slot>
        <x-slot name="right">
            {{-- 2026-09-08 — Johan, three times now: "same fight with placement
                 of buttons - submit to auth should be on the header at the
                 top, not off screen at the bottom... every primary action on
                 this screen belongs in the header where it is always
                 visible." Moved from the aside's own "Next step" block —
                 same actions, same guard (hidden once a decision exists),
                 just reachable regardless of scroll position now. The note
                 textarea became a plain prompt() so the whole flow fits a
                 single header row without inventing a dropdown/popover for
                 one short line of text. --}}
            <template x-if="activeDocId === null">
                <div class="flex items-center gap-2 flex-wrap justify-end">
                    {{-- 2026-09-08 — Johan: "request more info only appears if
                         a document is not open... I have to write down
                         somewhere else because its only available once the
                         doc is closed." The header prompt() was still a
                         version of that same mistake — reachable only when
                         no document was open, and even once open it was a
                         one-line interruption, not somewhere he could
                         actually compose a real request while reading. Moved
                         to the aside (see below) — always visible, a real
                         textarea, never covered by a document. "Submit to
                         authoriser" stays here: a single decisive click once
                         he's ready, not something composed while reading. --}}
                    @unless(in_array($rentalApplication->status, ['approved', 'declined'], true))
                        <button type="button" class="corex-btn-primary text-xs" :disabled="submittingForApproval"
                                @click="submitForApproval()" x-text="submittingForApproval ? 'Submitting…' : ({{ $isPendingAuthorisation ? 'true' : 'false' }} ? 'Re-submit to authoriser' : 'Submit to authoriser')"></button>
                    @endunless
                    <a href="{{ route('corex.rental-applications.show', $rentalApplication) }}" class="corex-btn-outline text-xs">Back to application</a>
                </div>
            </template>
            <template x-if="activeDocId !== null">
                <div class="flex items-center gap-3 flex-wrap justify-end">
                    <span class="text-xs font-medium truncate max-w-[160px]" style="color: var(--text-secondary);" x-text="label"></span>
                    {{-- Tool picker — Highlight (default) or Note. --}}
                    <div class="flex items-center gap-1">
                        <button type="button" class="text-xs px-2 py-1 rounded-md" @click="activeTool = 'highlight'"
                                :style="{ border:'1px solid var(--border)', background: activeTool === 'highlight' ? 'var(--ds-blue-soft, #eff6ff)' : 'transparent', fontWeight: activeTool === 'highlight' ? '700' : '400' }">Highlight</button>
                        <button type="button" class="text-xs px-2 py-1 rounded-md" @click="activeTool = 'note'"
                                :style="{ border:'1px solid var(--border)', background: activeTool === 'note' ? 'var(--ds-blue-soft, #eff6ff)' : 'transparent', fontWeight: activeTool === 'note' ? '700' : '400' }">Note</button>
                    </div>
                    {{-- Category picker, 2026-09-08 — Johan-approved six-colour
                         scheme: the agent picks WHAT this mark is (Income,
                         Expense, Unpaid), not a raw colour. The colour and the
                         underline follow from that category plus who's
                         drawing (agent = lighter fill here). Applies to both
                         tools. --}}
                    <div class="flex items-center gap-1" x-show="!loading && !loadError">
                        <template x-for="c in categories" :key="c.key">
                            <button type="button" class="text-xs px-2 py-1 rounded-md" @click="activeCategory = c.key"
                                    :style="{ border: '1px solid var(--border)', background: activeCategory === c.key ? fillFor({category: c.key, authorRole: currentUserRole}) : 'transparent', fontWeight: activeCategory === c.key ? '700' : '400', borderBottom: activeCategory === c.key ? ('3px solid ' + markPalette[c.key].underline) : '1px solid var(--border)' }"
                                    x-text="c.label"></button>
                        </template>
                    </div>
                    {{-- Highlighter size, 2026-09-08 — Johan: "we need a way
                         to adjust the highlighter smaller or larger. current
                         on highlights too much lines on bank statement -
                         lines are small there." Three presets, not a slider
                         — "medium" is today's unchanged default. Remembered
                         in localStorage (set in init()) so it survives both
                         switching documents in one sitting and coming back
                         tomorrow, not just this page view. Only meaningful
                         for the highlight tool — a note's marker size never
                         varies. --}}
                    <div class="flex items-center gap-1" x-show="!loading && !loadError && activeTool === 'highlight'">
                        <template x-for="s in strokeSizes" :key="s.key">
                            <button type="button" class="text-xs px-2 py-1 rounded-md" :title="s.label" @click="setStrokeSize(s.key)"
                                    :style="{ border:'1px solid var(--border)', background: strokeSizeKey === s.key ? 'var(--ds-blue-soft, #eff6ff)' : 'transparent', fontWeight: strokeSizeKey === s.key ? '700' : '400' }" x-text="s.label"></button>
                        </template>
                    </div>
                    <div class="flex items-center gap-1" x-show="!loading && !loadError">
                        <button type="button" class="text-xs px-2 py-1 rounded-md" title="Undo (Ctrl+Z)"
                                @click="undo()" :disabled="!canUndo()"
                                :style="{ border:'1px solid var(--border)', color: canUndo() ? 'var(--text-secondary)' : 'var(--text-muted)', opacity: canUndo() ? '1' : '0.5', cursor: canUndo() ? 'pointer' : 'default' }">Undo</button>
                        <button type="button" class="text-xs px-2 py-1 rounded-md" title="Redo (Ctrl+Shift+Z)"
                                @click="redo()" :disabled="!canRedo()"
                                :style="{ border:'1px solid var(--border)', color: canRedo() ? 'var(--text-secondary)' : 'var(--text-muted)', opacity: canRedo() ? '1' : '0.5', cursor: canRedo() ? 'pointer' : 'default' }">Redo</button>
                    </div>
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

    {{-- Save confirmation, 2026-09-08 — Johan: "if I edit / highlight anything on
         the pdf will it automatically save?" It did not autosave, and answering
         that ambiguity is the fix: highlighting/notes are EXPLICIT-save only,
         same as the viewing-pack redaction tool (command-center/viewing-packs/
         show.blade.php's redactionTool() has no autosave either — "Apply
         redaction" is the only way a box is ever persisted). This toast is
         OUTSIDE the "document open" template on purpose — the old badge lived
         inside x-if="activeDocId !== null" and got hidden the same instant
         Done closed the panel, so a save-then-close never actually showed
         anything. Page-level, so it survives the panel closing. --}}
    <div x-show="justSaved" x-cloak x-transition
         class="fixed z-40 rounded-md px-3 py-2 text-xs font-medium flex items-center gap-1.5"
         style="top: 72px; right: 20px; background: var(--ds-emerald, #059669); color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
        &check; Marks saved — the next person to open this document sees them.
    </div>

    {{-- Layout, 2026-09-07 — Johan rejected the original 50/50 grid ("makes the
         document unreadable, defeats the purpose"). CoreX already solves "dominant
         document + narrow working panel" twice — copied the proven pattern exactly
         rather than inventing new proportions:
           - resources/views/docuperfect/signatures/external/sign.blade.php
             (.recipient-doc-main flex:1 1 auto / .recipient-amend-col flex:0 0 260px)
           - resources/views/docuperfect/signatures/review.blade.php
             (.review-main flex:1 1 0% / .review-aside width:260px flex:0 0 260px,
             which itself notes "260px column matching cc6's recipient panel")
         Same two-region shape here: review-main (dominant, application + documents)
         and review-aside (fixed 260px, the agent's own working inputs). Stacks
         below 1280px, same breakpoint family as the signature review screen.

         IN-PLACE ANNOTATION, 2026-09-08 — Johan: "you lose the right hand panel
         to capture income etc. until you have finished the highlighting... think
         thats a problem as you have both but on separate screens." He was right
         — a full-screen modal covered review-aside entirely, defeating the whole
         point of a split screen. Fixed by removing the modal: the highlighter now
         renders INLINE inside review-main's own document row (still its own
         independently-scrolling column — see below), so review-aside is a plain
         flex SIBLING that was never covered and stays visible and usable the
         entire time an agent is highlighting a payslip. This was a pure
         presentational change — the mark-persistence backend never knew or cared
         whether its UI was a modal or inline; only the wrapping markup moved. --}}
    {{-- Independent scrolling, 2026-09-07/08 — Johan: "the right and left
         panels should scroll independently... essentially everything should
         fit that the whole screen doesnt scroll." The original fixed
         "100vh - 88px" guess didn't account for the QA/env banner
         (partials._demo-watermark / _env-banner, real height on QA1 — visible
         in a screenshot as the "QA · 127.0.0.1" bar) or the sticky header's
         real margins, so the panels were taller than the space actually left
         inside #appScroll (layouts/corex.blade.php's own scroll container) —
         #appScroll then had to scroll too, exactly the double-scroll Johan
         flagged. A bigger hardcoded guess would only be right for one
         viewport/banner combination and wrong for the next. Fixed by
         MEASURING the real available space at runtime (rentalReviewLayout()
         below) instead of guessing it — stable across banner/viewport
         changes because it reads the actual rendered header height and
         #appScroll's actual box, not an assumption about them. --}}
    <style>
        .rental-review-columns { display: flex; flex-direction: column; gap: 20px; }
        .rental-review-main    { flex: 1 1 auto; min-width: 0; }
        .rental-review-aside   { width: 100%; }
        @media (min-width: 1280px) {
            .rental-review-columns { flex-direction: row; gap: 16px; align-items: stretch; }
            .rental-review-main    { height: var(--rr-panel-h, calc(100vh - 160px)); max-height: var(--rr-panel-h, calc(100vh - 160px)); overflow-y: auto; }
            .rental-review-aside   { flex: 0 0 260px; width: 260px; align-self: stretch; position: sticky; top: 72px; height: var(--rr-panel-h, calc(100vh - 160px)); max-height: var(--rr-panel-h, calc(100vh - 160px)); overflow-y: auto; overflow-x: hidden; }
        }
    </style>

    <div class="rental-review-columns mt-5" x-data="rentalReviewLayout()">

        {{-- MAIN — the submitted application + supporting documents, viewable on screen. Dominant column. --}}
        <div class="rental-review-main space-y-4">
            {{-- Collapsed by default, 2026-09-07 — Johan: "collapse on the submitted
                 application section to get extra screen to view on [for the PDF]."
                 Defaults CLOSED (not just collapsible) because every field here also
                 lives on the main application record one click away, whereas the
                 documents below are the one thing this screen adds — so the extra
                 height goes to the actual point of this screen by default. One click
                 re-opens it; nothing here is destroyed or hidden permanently. --}}
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
                        <dt style="color: var(--text-muted);">Current landlord</dt><dd>{{ $rentalApplication->current_landlord_name ?? '—' }}</dd>
                        <dt style="color: var(--text-muted);">Adults / Children</dt><dd>{{ $rentalApplication->adults ?? '—' }} / {{ $rentalApplication->children ?? '—' }}</dd>
                    </dl>
                    <a href="{{ route('corex.rental-applications.show', $rentalApplication) }}" class="text-xs inline-block mt-3" style="color: var(--ds-blue, #2563eb);">View / edit full application &rarr;</a>
                </div>
            </div>

            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">
                    Supporting Documents
                    <span class="ds-badge ds-badge-default">{{ $documents->count() }}</span>
                </h2>

                @if($documents->isEmpty())
                    <p class="text-xs" style="color: var(--text-muted);">No supporting documents have been uploaded yet.</p>
                @else
                    <div class="space-y-2">
                        @foreach($documents as $row)
                            @php $document = $row['document']; @endphp
                            <div class="rounded-md border" style="border-color: var(--border);">
                                <div class="flex items-center justify-between px-3 py-2 text-xs">
                                    <span>{{ $document->original_name }}
                                        {{-- Agent-added-documents, 2026-09-08 — cc4's backend
                                             (RentalApplicationController::uploadDocument()), markup
                                             handed to me since I own this file. --}}
                                        <span style="color: var(--text-muted); font-size: 11px;">— {{ $document->uploaded_by ? 'added by ' . ($document->uploader->name ?? 'an agent') : 'from applicant' }}</span>
                                        {{-- RA-03 (cc5 re-test, Round 8) — this badge existed on
                                             show.blade.php but was never handed over here, the
                                             screen an agent actually reviews a returned
                                             application on. Same condition, same wording. --}}
                                        @if($rentalApplication->submitted_at && $document->created_at->greaterThanOrEqualTo($rentalApplication->submitted_at))
                                            <span class="ds-badge ds-badge-warning" title="This document was added after the application was submitted">Added after submission</span>
                                            <span style="color: var(--text-muted); font-size: 11px;">{{ $document->created_at->format('d M Y H:i') }}</span>
                                        @endif
                                    </span>
                                    <span class="flex items-center gap-2">
                                        <span class="ds-badge ds-badge-success" x-show="markedUpDocIds.includes({{ $document->id }})" x-cloak title="This document has saved marks — visible to anyone who opens it next.">Marked up</span>
                                        @if($row['inline_viewable'])
                                            <button type="button" style="color: var(--ds-blue, #2563eb); font-weight: 600;"
                                                    @click="openHighlighter({
                                                        documentId: {{ $document->id }},
                                                        firstPageUrl: '{{ route('corex.rental-applications.documents.highlight-data.first', [$rentalApplication, $document]) }}',
                                                        remainingPagesUrl: '{{ route('corex.rental-applications.documents.highlight-data.remaining', [$rentalApplication, $document]) }}',
                                                        postUrl: '{{ route('corex.rental-applications.documents.highlight', [$rentalApplication, $document]) }}',
                                                        label: {{ Js::from($document->original_name) }},
                                                    })"
                                                    x-text="activeDocId === {{ $document->id }} ? 'Close' : 'View & Mark Up'"></button>
                                        @else
                                            <span class="ds-badge ds-badge-default" title="This file type cannot be previewed on screen — download it to view it.">No preview</span>
                                        @endif
                                        <a href="{{ route('corex.rental-applications.documents.download', [$rentalApplication, $document]) }}" style="color: var(--text-muted);">Download</a>
                                    </span>
                                </div>

                                {{-- IN-PLACE viewer — was a fixed full-screen modal that covered
                                     review-aside entirely; now a plain in-flow block inside this
                                     row, inside review-main's own independently-scrolling column.
                                     review-aside (the assessment panel) is a flex sibling that was
                                     never covered by either shape — this change is presentational
                                     only. --}}
                                <div x-show="activeDocId === {{ $document->id }}" x-cloak class="px-3 pb-3 border-t" style="border-color: var(--border);">
                                    @include('corex.rental-applications.partials.document-highlighter-pages')
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Agent-added-documents, 2026-09-08 — Johan: "agent should
                     in any case be able to add docs as client can be in the
                     office so agent scans docs to themselves, or even
                     receive via whatsapp etc." Backend built by cc4
                     (RentalApplicationController::uploadDocument(), same
                     allowlist/scoping the applicant path already uses).
                     Widget itself handed to me since I own this file —
                     own nested x-data, zero shared state with the root
                     rentalReview() component. Reloads on success (server-
                     rendered $documents list, not x-for) — safe here since
                     the assessment panel autosaves on blur, so nothing is
                     ever sitting unsaved when an agent clicks away to pick
                     a file. --}}
                <div class="mt-3 pt-3" style="border-top: 1px solid var(--border);" x-data="agentDocumentUploadReview()">
                    <template x-for="u in uploading" :key="u.tempId">
                        <p class="text-xs mb-1" :class="u.error ? 'text-red-600' : ''" style="color: var(--text-muted);" x-text="u.error ? (u.name + ': ' + u.error) : ('Uploading ' + u.name + '…')"></p>
                    </template>
                    <label class="text-xs font-medium cursor-pointer" style="color: var(--brand-icon, #2563eb);">
                        + Add document
                        <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="hidden" @change="onFilesSelected($event.target.files); $event.target.value = ''">
                    </label>
                </div>
            </div>
        </div>

        {{-- ASIDE — the agent's own affordability capture + suggestive calculation.
             Narrow, fixed 260px working column. Never covered by anything —
             see the in-place-annotation note above the layout <style> block. --}}
        <div class="rental-review-aside rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            {{-- Round 16 — Johan: "you are putting a lot of text on the
                 panel which makes the panel extremenly long. why not use
                 the helper? and put the tooltip in there rather than
                 having a hell of a lot of info." Every explanatory
                 paragraph that used to live in this panel is now a "?"
                 helper badge (native title="" tooltip — the same pattern
                 already used on the Autosaves badge below) next to its
                 heading, never body text. --}}
            <div class="flex items-center gap-1.5 mb-3">
                <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Affordability Assessment</h2>
                <span class="ds-badge ds-badge-muted" style="cursor: help; padding: 0 5px;"
                      title="You type these — nothing here is pre-filled from the application, and nothing here is sent to the applicant or shown anywhere else.">?</span>
                <span class="ds-badge ds-badge-info flex-shrink-0 ml-auto" title="Every field below saves the moment you click away from it — no button needed.">Autosaves</span>
            </div>

            <div class="mb-4">
                <div class="flex items-center gap-1.5 mb-1">
                    <label class="text-xs font-medium" style="color: var(--text-secondary);">Months covered</label>
                    <span class="ds-badge ds-badge-muted" style="cursor: help; padding: 0 5px;"
                          title="How many months this bank statement covers. Required to turn the totals below into the MONTHLY figure the affordability guideline actually runs against — the raw total alone means nothing against a monthly legal threshold.">?</span>
                </div>
                <input type="number" step="1" min="1" max="36" class="corex-input text-sm w-20"
                       x-model="statementMonths" @blur="save()" placeholder="e.g. 3">
            </div>

            {{-- Round 16 — Johan: "why can we not split that block - desc
                 half and amount half? so it fits next to each other." A
                 real 50/50 grid column split (grid-cols-2), not a flex-1 +
                 fixed-width row (the shape that caused the amount field to
                 render almost entirely off the panel's right edge earlier
                 today) — a grid column is always exactly half the row's
                 width regardless of either input's own content, so this
                 cannot recur at any panel width. --}}
            <div class="mb-4">
                <div class="flex items-center gap-1.5 mb-1">
                    <span class="rounded-full flex-shrink-0" style="width: 9px; height: 9px; background: var(--ra-income-agent); border: 1px solid var(--ra-income-underline);"></span>
                    <label class="text-xs font-medium" style="color: var(--text-secondary);">Income (gross)</label>
                    <span class="ds-badge ds-badge-muted" style="cursor: help; padding: 0 5px;"
                          title="What's on the payslip / bank statement BEFORE tax and other deductions — not take-home pay. One line per deposit; pressing Enter in the amount field adds the next line.">?</span>
                </div>
                <div class="space-y-1.5" x-ref="incomeRows">
                    <template x-for="(item, index) in incomeItems" :key="index">
                        <div class="grid grid-cols-2 gap-1.5">
                            <input type="text" class="corex-input text-sm w-full" placeholder="e.g. Salary"
                                   x-model="item.description" @input="onIncomeRowInput()" @blur="save()">
                            <input type="text" inputmode="decimal" class="corex-input text-sm w-full" placeholder="0.00"
                                   data-role="amount" x-model="item.amount" @input="onIncomeRowInput()" @blur="save()"
                                   @keydown.enter.prevent="focusNextAmountRow('incomeRows', index)">
                        </div>
                    </template>
                </div>
                <p class="text-xs mt-1.5" style="color: var(--text-secondary);">
                    Total captured: <strong x-text="formatR(incomeTotal())"></strong>
                </p>
            </div>

            <div class="mb-4">
                <div class="flex items-center gap-1.5 mb-1">
                    <span class="rounded-full flex-shrink-0" style="width: 9px; height: 9px; background: var(--ra-expense-agent); border: 1px solid var(--ra-expense-underline);"></span>
                    <label class="text-xs font-medium" style="color: var(--text-secondary);">Expenses / existing debt</label>
                    <span class="ds-badge ds-badge-muted" style="cursor: help; padding: 0 5px;"
                          title="Recurring debt/expense lines off the same bank statement — car payment, store account, and so on. For reference only; does not affect the qualifying figure below.">?</span>
                </div>
                <div class="space-y-1.5" x-ref="expenseRows">
                    <template x-for="(item, index) in expenseItems" :key="index">
                        <div class="grid grid-cols-2 gap-1.5">
                            <input type="text" class="corex-input text-sm w-full" placeholder="e.g. Car payment"
                                   x-model="item.description" @input="onExpenseRowInput()" @blur="save()">
                            <input type="text" inputmode="decimal" class="corex-input text-sm w-full" placeholder="0.00"
                                   data-role="amount" x-model="item.amount" @input="onExpenseRowInput()" @blur="save()"
                                   @keydown.enter.prevent="focusNextAmountRow('expenseRows', index)">
                        </div>
                    </template>
                </div>
                <p class="text-xs mt-1.5" style="color: var(--text-secondary);">
                    Total captured: <strong x-text="formatR(expenseTotal())"></strong>
                </p>
            </div>

            {{-- Round 16 — Johan: "we need the heading, the highlighter and
                 a tick - unpaid transactions on bank statement... an
                 applicant with declined transactions are generally
                 immediately declined... the tick tells the auth that this
                 is a dangerous app." A single flag, not a list of amounts
                 — individual declined lines are marked directly on the
                 document (the highlighter, cc6's own territory, untouched
                 here). This flag is what the authoriser's screen (cc5)
                 reads to show the red flag; the colour dot below is the
                 SAME --ra-unpaid-* token cc5 renders it with, so the two
                 screens read as visually linked. --}}
            <div class="mb-4">
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

            {{-- Round 16 — Johan: "we just show an info box - income * 30%
                 = Rx - based on income captured the applicant qualifies
                 for... so we dont need a massive hooha." ONE box, the
                 qualifying ceiling as a large figure with its own
                 arithmetic underneath in small type — nothing else. Wording
                 fix, Johan caught this himself: the OLD copy said "bank
                 statement total" as though CoreX opened and totalled the
                 statement itself; it did not — the agent typed these
                 figures in. Every number now says who asserted it. --}}
            <div class="rounded-md p-3" style="background: var(--ds-slate-soft, #f1f5f9); border: 1px solid var(--border);">
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
                        {{-- Round 16 — Johan: "the affordability check is
                             currently testing the applicant's SELF-REPORTED
                             CURRENT RENT, not the rent of the property
                             being applied for... If no property is linked,
                             the check must say it cannot run." cc3 is
                             building the property-link control elsewhere on
                             this screen; this line just reflects whatever
                             qualifyingResult() reports once it's set. --}}
                        <template x-if="result.label === 'no_property'">
                            <p class="text-xs mt-2 font-medium" style="color: var(--ds-amber, #b45309);">Link a property to check against its rent.</p>
                        </template>
                        {{-- Round 16 — a single-line badge with the rent
                             figure crammed into it overflowed the panel at
                             1522px, the exact same class of bug as the
                             clipped amount fields earlier today. Split into
                             a short badge (never wraps, always fits) plus
                             the rent figure as plain text below it, which
                             wraps normally like any other text in the box. --}}
                        <template x-if="result.label === 'sufficient' || result.label === 'insufficient'">
                            <div class="mt-2">
                                <span class="ds-badge" :class="result.label === 'sufficient' ? 'ds-badge-success' : 'ds-badge-warning'"
                                      x-text="result.label === 'sufficient' ? 'Within guideline' : 'Exceeds guideline'"></span>
                                <p class="text-xs mt-1" style="color: var(--text-muted);">
                                    Property rent: <strong x-text="formatR(result.rent)"></strong>
                                </p>
                            </div>
                        </template>
                    </div>
                </template>
            </div>

            <div class="mt-4">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Notes</label>
                <textarea rows="7" class="corex-input text-sm w-full" style="resize: none; overflow-y: auto;"
                          x-model="fields.notes" x-init="autoGrowTextarea($el)" @input="autoGrowTextarea($el)" @blur="save()"></textarea>
            </div>

            <div class="flex items-center gap-1.5 text-xs mt-2 px-2 py-1 rounded-md" x-show="saveStatus"
                 :style="saveError ? 'background: var(--ds-red-soft, #fef2f2); color: var(--ds-red, #dc2626);' : 'background: var(--ds-emerald-soft, #ecfdf5); color: var(--ds-emerald, #059669);'">
                <span x-show="!saveError && saveStatus !== 'Saving…'">&check;</span>
                <span x-text="saveStatus"></span>
            </div>

            {{-- Authoriser flow, 2026-09-08 — the agent's own two actions.
                 Johan: "agent only gets submit? get application, maybe a way
                 to request more info from applicant? then once everything
                 received checked etc they submit to auth." Reuses the
                 applicant's EXISTING token link (no new token/route) — the
                 applicant can already add documents at any status. --}}
            <div class="rounded-md p-3 mt-4" style="border: 1px solid var(--border);">
                {{-- Authoriser decision, 2026-09-08 — "update the agent's
                     screen to show the applicant approved for that amount."
                     An override (a CO changing an earlier decision) simply
                     shows the CURRENT decision here — the full history,
                     including the override, is on the authoriser's own
                     screen and the audit trail. --}}
                @if($rentalApplication->status === 'approved')
                    <div class="rounded-md px-3 py-2 text-xs mb-3" style="background: var(--ds-emerald-soft, #ecfdf5); color: var(--ds-emerald, #059669);">
                        &check; Approved for R{{ number_format($rentalApplication->approved_rental_amount, 2) }} a month. The applicant has been notified — you can now start matching them to a property.
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

                {{-- Request more information, 2026-09-08 — Johan, verbatim:
                     "request more info only appears if a document is not
                     open... I have to write down somewhere else because its
                     only available once the doc is closed?" and "the simple
                     modal that loads on request extra info will not
                     suffice. Allow a free text box - I can go and fill in
                     numbered points." Both are the same underlying design
                     mistake — the request lived somewhere that wasn't
                     reachable, or wasn't sized for real writing, WHILE he
                     was looking at the evidence he was writing about. Fixed
                     by putting it here instead: the aside is never covered
                     by an open document (same reason the highlighter went
                     inline instead of a modal originally), so this is
                     reachable and fillable the entire time a document is
                     open. The draft surviving a document being opened or
                     closed needs no extra code — `moreInfoNote` (bound
                     below) was never part of openHighlighter()/
                     closeHighlighter()'s reset list, so it was already
                     independent of the document lifecycle; the fix here is
                     purely that this UI now actually stays on screen to be
                     typed into. rows="6" + a monospace-ish plain textarea
                     preserves line breaks/numbering as typed — no
                     reformatting on the way in, and the mail template
                     already renders with white-space:pre-wrap so numbered
                     points survive to the applicant unchanged (verified —
                     see spec). --}}
                @unless(in_array($rentalApplication->status, ['approved', 'declined'], true))
                    <p class="text-xs font-semibold uppercase tracking-wide mb-2" style="color: var(--text-muted);">Request more information</p>
                    <textarea x-model="moreInfoNote" rows="7" class="corex-input text-xs w-full mb-2" style="resize: none; overflow-y: auto;"
                              x-init="autoGrowTextarea($el)" @input="autoGrowTextarea($el)"
                              placeholder="What do you need from the applicant? e.g.&#10;1. Three months' bank statements&#10;2. Payslip for August&#10;3. Proof of the R12,000 deposit on 14 August"></textarea>
                    <button type="button" class="corex-btn-outline text-xs w-full" :disabled="moreInfoSending || !moreInfoNote.trim()" @click="requestMoreInfo()" x-text="moreInfoSending ? 'Sending…' : 'Send to applicant'"></button>
                    <p class="text-xs mt-2" x-show="agentActionStatus" x-text="agentActionStatus" :style="agentActionError ? 'color: var(--ds-red, #dc2626);' : 'color: var(--ds-emerald, #059669);'"></p>
                @endunless
            </div>
        </div>
    </div>
</div>

@include('corex.rental-applications.partials.document-highlighter-script')

<script>
function rentalReview({ saveUrl, initial, initialIncomeItems, initialExpenseItems, initialResult, initialSavedAt, initialMarkedUpDocIds, currentUserId, currentUserName, currentUserRole, requestMoreInfoUrl, submitForApprovalUrl }) {
    return {
        // 2026-09-08 — the highlight/note viewer state+methods (activeDocId,
        // pages, marks, openHighlighter()/applyHighlights()/etc.) now live in
        // the shared rentalDocumentHighlighter() factory (see
        // partials/document-highlighter-script.blade.php, included below)
        // — the authoriser screen spreads the same factory in rather than
        // this logic being copy-pasted a second time.
        ...rentalDocumentHighlighter({ initialMarkedUpDocIds, currentUserId, currentUserName, currentUserRole }),

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
        incomeItems: (initialIncomeItems && initialIncomeItems.length) ? initialIncomeItems : [{ id: null, description: '', amount: '' }],
        expenseItems: (initialExpenseItems && initialExpenseItems.length) ? initialExpenseItems : [{ id: null, description: '', amount: '' }],
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
                list.push({ id: null, description: '', amount: '' });
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
        // Round 11 — display only (see the field's own comment above).
        statementMonths: initial.statement_months ?? '',
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
                });
                const data = await res.json().catch(() => ({}));
                if (res.ok && data.ok) {
                    this.agentActionError = false;
                    this.agentActionStatus = 'Submitted to the authoriser.';
                    setTimeout(() => window.location.reload(), 900);
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
                    statement_months: this.statementMonths,
                    has_unpaid_transactions: this.hasUnpaidTransactions,
                }),
            }).then(r => r.json()).then(data => {
                if (data.ok) {
                    this.result = data.result;
                    (data.income_items || []).forEach((saved, i) => { if (sentIncomeRows[i]) sentIncomeRows[i].id = saved.id; });
                    (data.expense_items || []).forEach((saved, i) => { if (sentExpenseRows[i]) sentExpenseRows[i].id = saved.id; });
                    this.saveStatus = data.saved_at ? ('Saved at ' + formatTime(data.saved_at)) : 'Saved';
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
// position, so this stays correct however the page is scrolled.
function rentalReviewLayout() {
    return {
        init() {
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
</script>
@endsection
