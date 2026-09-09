{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- AT-392 authoriser flow, 2026-09-08 — the authoriser's read + decide view.
     Same dominant-document + narrow-panel layout the agent's review screen
     already uses (resources/views/corex/rental-applications/review.blade.php)
     — matching an existing CoreX pattern rather than inventing a new one.
     Three outcomes: Approve (captures the monthly amount), Decline, Request
     more information (goes back to the agent). Each has its own free-text
     box. A CO acting on an application that already has a decision is an
     OVERRIDE — reason becomes required, and it's marked unmistakably as an
     override both here and in the audit trail below. --}}
@extends('layouts.corex')

@php
    // 2026-09-08 — the authoriser now marks up documents too (Johan: "the
    // auth should be able to write on the docs as well making notes etc").
    // Same shared viewer as the agent's review screen
    // (partials/document-highlighter-script.blade.php's
    // rentalDocumentHighlighter()) — same backend trait, same routes shape,
    // just under the authorisation namespace.
    $initialMarkedUpDocIds = $documents->filter(fn ($row) => $row['has_highlights'])->pluck('document.id')->values();
@endphp

@section('corex-content')
<div class="w-full"
     x-data="rentalAuthorisationViewer({
         initialMarkedUpDocIds: {{ Js::from($initialMarkedUpDocIds) }},
         currentUserId: {{ Js::from(auth()->id()) }},
         currentUserName: {{ Js::from(auth()->user()->name) }},
         currentUserRole: 'authoriser',
     })">

    <div class="rounded-md px-6 py-4 corex-page-banner flex items-center justify-between">
        <div>
            <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">
                Authorise — {{ $rentalApplication->contact->full_name ?? $rentalApplication->contact->first_name . ' ' . $rentalApplication->contact->last_name }}
            </h1>
            <p class="text-xs" style="color: var(--text-muted);">
                {{ $rentalApplication->property_address_override ?? optional($rentalApplication->property)->address ?? 'No property linked' }}
                &middot; Submitted for approval {{ $rentalApplication->submitted_for_approval_at?->format('d M Y H:i') }}
            </p>
        </div>
        <a href="{{ route('corex.rental-applications.authorisation.index') }}" class="corex-btn-outline text-xs">Back to list</a>
    </div>

    @if($alreadyDecided)
        <div class="rounded-md px-4 py-3 text-sm mt-4" style="background: var(--ds-amber-soft, #fffbeb); color: var(--ds-amber, #92400e); border: 1px solid var(--ds-amber, #f59e0b);">
            This application already has a decision: <strong>{{ ucfirst($rentalApplication->status) }}</strong>{{ $rentalApplication->approved_rental_amount ? ' for R' . number_format($rentalApplication->approved_rental_amount, 2) : '' }}.
            @if($canOverride)
                Acting below will OVERRIDE it — a reason is required.
            @else
                Only a CO (Override) user may change it.
            @endif
        </div>
    @endif

    {{-- Save confirmation toast — same pattern as review.blade.php's own. --}}
    <div x-show="justSaved" x-cloak x-transition
         class="fixed z-40 rounded-md px-3 py-2 text-xs font-medium flex items-center gap-1.5"
         style="top: 72px; right: 20px; background: var(--ds-emerald, #059669); color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
        &check; Marks saved — the next person to open this document sees them.
    </div>

    <style>
        .rah-auth-columns { display: flex; flex-direction: column; gap: 20px; }
        .rah-auth-main    { flex: 1 1 auto; min-width: 0; }
        .rah-auth-aside   { width: 100%; }
        @media (min-width: 1280px) {
            .rah-auth-columns { flex-direction: row; gap: 16px; align-items: stretch; }
            .rah-auth-main    { max-height: calc(100vh - 32px); overflow-y: auto; }
            .rah-auth-aside   { flex: 0 0 320px; width: 320px; align-self: stretch; position: sticky; top: 16px; max-height: calc(100vh - 32px); overflow-y: auto; }
        }
    </style>

    <div class="rah-auth-columns mt-5">
        <div class="rah-auth-main space-y-4">
            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Submitted Application</h2>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                    <dt style="color: var(--text-muted);">Employer</dt><dd>{{ $rentalApplication->employer_name ?? '—' }}</dd>
                    <dt style="color: var(--text-muted);">Position</dt><dd>{{ $rentalApplication->employer_position ?? '—' }}</dd>
                    <dt style="color: var(--text-muted);">Monthly salary (self-reported)</dt><dd>{{ $rentalApplication->monthly_salary !== null ? 'R ' . number_format($rentalApplication->monthly_salary, 2) : '—' }}</dd>
                    <dt style="color: var(--text-muted);">Current rental amount</dt><dd>{{ $rentalApplication->current_rental_amount !== null ? 'R ' . number_format($rentalApplication->current_rental_amount, 2) : '—' }}</dd>
                    <dt style="color: var(--text-muted);">Current landlord</dt><dd>{{ $rentalApplication->current_landlord_name ?? '—' }}</dd>
                    <dt style="color: var(--text-muted);">Adults / Children</dt><dd>{{ $rentalApplication->adults ?? '—' }} / {{ $rentalApplication->children ?? '—' }}</dd>
                </dl>
            </div>

            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);"
                 x-data="rentalAssessmentEditor({
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
                 })">
                <div class="flex items-center gap-1.5 mb-1">
                    <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Agent's Assessment</h2>
                    <span class="ds-badge ds-badge-muted" style="cursor: help; padding: 0 5px;"
                          title="Captured by the agent. You can add your own lines. If you disagree with a figure, strike it out — that opens a box to add the correct one right there. The struck line stays visible with what replaced it, never edited in place.">?</span>
                </div>
                {{-- 2026-09-08 — Johan, application 9: "now all the work the
                     agent did is nowhere to be found on the auth screen." Fixed
                     (see prior commits). Then, verbatim, CONFIRMED (not a
                     coordinator inference): "auth can rather strike out and
                     re-add a value than edit a value. this way we have the
                     evidence needed of who did what." There is no edit control
                     anywhere in this block — striking a row opens the add-line
                     box pre-focused right underneath it (replacingItem below),
                     so the two actions read as one flow, not two separate
                     ones, and the result — struck line + its replacement,
                     both attributed, both visible, only the live one counted
                     — is exactly what Johan's own reason (the evidence trail)
                     calls for. Dots use the SAME --ra-income/expense-* tokens
                     cc4 defined and the agent's own review panel already
                     renders — light = agent tone, dark = authoriser tone. --}}
                <div class="text-xs mb-3">
                    <p style="color: var(--text-muted);">Number of months this bank statement covers</p>
                    <p class="font-semibold" style="color: var(--text-primary);">{{ $assessment->statement_months ?? '—' }}</p>
                </div>

                <div class="text-xs mb-4">
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
                                    <span :style="{ textDecoration: item.struck_out ? 'line-through' : 'none' }" class="truncate" style="color: var(--text-primary);" x-text="item.description || '(no description)'"></span>
                                </span>
                                <span class="flex items-center justify-end gap-2 flex-shrink-0">
                                    <span :style="{ textDecoration: item.struck_out ? 'line-through' : 'none' }" style="color: var(--text-primary);" x-text="'R ' + formatAmount(item.amount)"></span>
                                    <button type="button" class="text-xs" :style="{ color: item.struck_out ? 'var(--ds-emerald, #059669)' : 'var(--ds-crimson, #dc2626)' }" @click="toggleStrike('income', item)" x-text="item.struck_out ? 'Restore' : 'Strike out'"></button>
                                </span>
                            </div>
                            {{-- Johan: "the result should read clearly as 'this
                                 figure was replaced by that one, by this
                                 person, at this time'." --}}
                            <p class="text-[11px] pl-3" style="color: var(--text-muted);" x-show="item.struck_out" x-text="struckLine(item)"></p>
                            <div class="flex items-center gap-2 pl-3 py-1.5" x-show="replacingItem === ('income-' + item.id)" x-cloak>
                                <input type="text" x-model="replaceDescription" placeholder="Description" class="corex-input text-xs" style="flex:1;" :data-replace-focus="'income-' + item.id">
                                <input type="text" inputmode="decimal" x-model="replaceAmount" placeholder="0.00" class="corex-input text-xs" style="width:80px;">
                                <button type="button" class="text-xs font-semibold flex-shrink-0" style="color: var(--ds-blue, #2563eb);" :disabled="!replaceAmount" @click="addItem('income', item.id)">Add replacement</button>
                                <button type="button" class="text-xs" style="color: var(--text-muted);" @click="replacingItem = null">Cancel</button>
                            </div>
                        </div>
                    </template>
                    <div class="flex items-center gap-2 pt-2 mt-1" style="border-top: 1px dashed var(--border);">
                        <input type="text" x-model="newIncomeDescription" placeholder="Description" class="corex-input text-xs" style="flex:1;">
                        <input type="text" inputmode="decimal" x-model="newIncomeAmount" placeholder="0.00" class="corex-input text-xs" style="width:80px;">
                        <button type="button" class="text-xs font-semibold flex-shrink-0" style="color: var(--ds-blue, #2563eb);" :disabled="!newIncomeAmount" @click="addItem('income')">+ Add</button>
                    </div>
                    <p class="mt-2" style="color: var(--text-secondary);">Total (struck-out lines excluded): <strong x-text="'R ' + formatAmount(incomeTotal())"></strong></p>
                    <p style="color: var(--text-secondary);" x-show="statementMonths">Monthly average (÷ <span x-text="statementMonths"></span> months — used in the affordability check below): <strong x-text="'R ' + formatAmount(grossIncome())"></strong></p>
                </div>

                <div class="text-xs mb-4">
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
                                    <span :style="{ textDecoration: item.struck_out ? 'line-through' : 'none' }" class="truncate" style="color: var(--text-primary);" x-text="item.description || '(no description)'"></span>
                                </span>
                                <span class="flex items-center justify-end gap-2 flex-shrink-0">
                                    <span :style="{ textDecoration: item.struck_out ? 'line-through' : 'none' }" style="color: var(--text-primary);" x-text="'R ' + formatAmount(item.amount)"></span>
                                    <button type="button" class="text-xs" :style="{ color: item.struck_out ? 'var(--ds-emerald, #059669)' : 'var(--ds-crimson, #dc2626)' }" @click="toggleStrike('expense', item)" x-text="item.struck_out ? 'Restore' : 'Strike out'"></button>
                                </span>
                            </div>
                            <p class="text-[11px] pl-3" style="color: var(--text-muted);" x-show="item.struck_out" x-text="struckLine(item)"></p>
                            <div class="flex items-center gap-2 pl-3 py-1.5" x-show="replacingItem === ('expense-' + item.id)" x-cloak>
                                <input type="text" x-model="replaceDescription" placeholder="Description" class="corex-input text-xs" style="flex:1;" :data-replace-focus="'expense-' + item.id">
                                <input type="text" inputmode="decimal" x-model="replaceAmount" placeholder="0.00" class="corex-input text-xs" style="width:80px;">
                                <button type="button" class="text-xs font-semibold flex-shrink-0" style="color: var(--ds-blue, #2563eb);" :disabled="!replaceAmount" @click="addItem('expense', item.id)">Add replacement</button>
                                <button type="button" class="text-xs" style="color: var(--text-muted);" @click="replacingItem = null">Cancel</button>
                            </div>
                        </div>
                    </template>
                    <div class="flex items-center gap-2 pt-2 mt-1" style="border-top: 1px dashed var(--border);">
                        <input type="text" x-model="newExpenseDescription" placeholder="Description" class="corex-input text-xs" style="flex:1;">
                        <input type="text" inputmode="decimal" x-model="newExpenseAmount" placeholder="0.00" class="corex-input text-xs" style="width:80px;">
                        <button type="button" class="text-xs font-semibold flex-shrink-0" style="color: var(--ds-blue, #2563eb);" :disabled="!newExpenseAmount" @click="addItem('expense')">+ Add</button>
                    </div>
                    <p class="mt-2" style="color: var(--text-secondary);">Total (struck-out lines excluded): <strong x-text="'R ' + formatAmount(expenseTotal())"></strong></p>
                </div>

                {{-- Round 16's unpaid-transactions flag, mirrored here read-only
                     — Johan: "the tick tells the auth that this is a dangerous
                     app." Same --ra-unpaid-* token the agent panel's own dot
                     uses, at authoriser tone, so the two screens read as
                     visually linked exactly as that panel's own comment says
                     they should. Not editable here — it's the agent's own
                     assertion about what they saw on the statement, the
                     authoriser's read of it is the highlighter/decision, not
                     this flag. --}}
                <div class="mb-4 flex items-center gap-1.5" x-show="hasUnpaidTransactions">
                    <span class="rounded-full flex-shrink-0" style="width: 9px; height: 9px; background: var(--ra-unpaid-authoriser); border: 1px solid var(--ra-unpaid-underline);"></span>
                    <span class="text-xs font-semibold" style="color: var(--ds-crimson, #dc2626);">Agent flagged unpaid/declined transactions on the bank statement</span>
                </div>

                <div x-show="itemError" x-cloak class="text-xs mb-3 rounded-md px-2 py-1.5" style="background: var(--ds-crimson-soft, #fef2f2); color: var(--ds-crimson, #dc2626);" x-text="itemError"></div>

                {{-- The rule, stated as the law states it: rent must not
                     exceed {max_rent_percent}% of GROSS income. Live,
                     client-side — Johan's test: "watch the total change" when
                     a line is struck. Same arithmetic as
                     RentalApplicationAssessment::qualifyingResult() (Round 16:
                     rent from the linked property, 'no_property' when none is
                     linked) — the server re-derives the authoritative figure
                     independently once a write round-trips; this is the same
                     rule shown live, not a second source of truth. --}}
                {{-- 2026-09-08, night sweep at 1522px — --ds-slate-soft was
                     never actually defined in corex.css, so its hardcoded
                     #f1f5f9 fallback always won regardless of theme, against
                     theme-aware (light-in-dark-mode) text — unreadable in
                     dark mode. --surface-2 is the real, theme-aware token. --}}
                <template x-if="statementMonths && incomeTotal() > 0">
                    <div class="rounded-md p-3" style="background: var(--surface-2, #f9fafb); border: 1px solid var(--border);">
                        <p class="text-[11px] font-semibold uppercase tracking-wide mb-1" style="color: var(--text-muted);">Suggested check — not a rule</p>
                        <p class="text-sm">
                            Gross income <span x-text="'R' + formatAmount(grossIncome())"></span> — rent must not exceed <span x-text="trimPercent(maxRentPercent)"></span>% of this (<span x-text="'R' + formatAmount(maxAffordableRent())"></span>).
                            <template x-if="!propertyLinked">
                                <span class="font-medium" style="color: var(--ds-amber, #b45309);"> Link a property to check against its rent.</span>
                            </template>
                            <template x-if="propertyLinked && rent !== null">
                                <span>Actual rent (<span x-text="'R' + formatAmount(rent)"></span>) is <span x-text="rentAsPercent()"></span>% of gross income.
                                <span class="ds-badge" :class="meetsThreshold() ? 'ds-badge-success' : 'ds-badge-warning'" x-text="meetsThreshold() ? 'Within the affordability guideline' : 'Exceeds the affordability guideline'"></span></span>
                            </template>
                        </p>
                    </div>
                </template>
                <template x-if="!(statementMonths && incomeTotal() > 0)">
                    <div class="rounded-md p-3 text-xs" style="background: var(--surface-2, #f9fafb); border: 1px solid var(--border); color: var(--text-muted);">
                        Not enough captured yet to run the affordability guideline (needs both income and the number of months).
                    </div>
                </template>
                @if($assessment->notes)
                    <p class="text-xs mt-3 whitespace-pre-wrap" style="color: var(--text-primary);">{{ $assessment->notes }}</p>
                @endif
            </div>

            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">
                    Supporting Documents
                    <span class="ds-badge ds-badge-default">{{ $documents->count() }}</span>
                </h2>
                @if($documents->isEmpty())
                    <p class="text-xs" style="color: var(--text-muted);">No supporting documents.</p>
                @else
                    <div class="space-y-2">
                        @foreach($documents as $row)
                            @php $document = $row['document']; @endphp
                            <div class="rounded-md border" style="border-color: var(--border);">
                                <div class="flex items-center justify-between px-3 py-2 text-xs">
                                    <span>{{ $document->original_name }}</span>
                                    <span class="flex items-center gap-2">
                                        <span class="ds-badge ds-badge-success" x-show="markedUpDocIds.includes({{ $document->id }})" x-cloak title="This document has saved marks — visible to anyone who opens it next.">Marked up</span>
                                        @if($row['inline_viewable'])
                                            <button type="button" style="color: var(--ds-blue, #2563eb); font-weight: 600;"
                                                    @click="openHighlighter({
                                                        documentId: {{ $document->id }},
                                                        firstPageUrl: '{{ route('corex.rental-applications.authorisation.documents.highlight-data.first', [$rentalApplication, $document]) }}',
                                                        remainingPagesUrl: '{{ route('corex.rental-applications.authorisation.documents.highlight-data.remaining', [$rentalApplication, $document]) }}',
                                                        postUrl: '{{ route('corex.rental-applications.authorisation.documents.highlight', [$rentalApplication, $document]) }}',
                                                        label: {{ Js::from($document->original_name) }},
                                                    })"
                                                    x-text="activeDocId === {{ $document->id }} ? 'Close' : 'View & Mark Up'"></button>
                                        @else
                                            <span class="ds-badge ds-badge-default" title="This file type cannot be previewed on screen — download it to view it.">No preview</span>
                                        @endif
                                    </span>
                                </div>

                                {{-- IN-PLACE viewer, 2026-09-08 — Johan: "should load the doc in
                                     the same screen that the reviewer can see the submitted info
                                     whilst working through the doc... how do I now look at the
                                     information and the document at the same time?" Same
                                     in-flow-block pattern as review.blade.php: this lives inside
                                     rah-auth-main's own independently-scrolling column, so
                                     rah-auth-aside (the Decision panel) stays visible the whole
                                     time — a plain flex sibling, never covered. The toolbar (tool
                                     picker, colours, undo/redo, save) is inline at the top of the
                                     panel rather than in a sticky header — this screen doesn't use
                                     x-sticky-action-bar and adopting it here wasn't worth the risk
                                     of touching that shared component for this one screen. --}}
                                <div x-show="activeDocId === {{ $document->id }}" x-cloak class="px-3 pb-3 border-t" style="border-color: var(--border);">
                                    <div class="flex items-center gap-3 flex-wrap py-2" x-show="!loading && !loadError">
                                        <div class="flex items-center gap-1">
                                            <button type="button" class="text-xs px-2 py-1 rounded-md" @click="activeTool = 'highlight'"
                                                    :style="{ border:'1px solid var(--border)', background: activeTool === 'highlight' ? 'var(--ds-blue-soft, #eff6ff)' : 'transparent', fontWeight: activeTool === 'highlight' ? '700' : '400' }">Highlight</button>
                                            <button type="button" class="text-xs px-2 py-1 rounded-md" @click="activeTool = 'note'"
                                                    :style="{ border:'1px solid var(--border)', background: activeTool === 'note' ? 'var(--ds-blue-soft, #eff6ff)' : 'transparent', fontWeight: activeTool === 'note' ? '700' : '400' }">Note</button>
                                        </div>
                                        {{-- Category picker, 2026-09-08 — Johan-approved six-colour
                                             scheme: the authoriser picks WHAT this mark is (Income,
                                             Expense, Unpaid), not a raw colour. The authoriser's own
                                             marks render in the DARKER shade of that category (role
                                             = treatment) — the same category the agent picked reads
                                             as a lighter shade of the same hue on their own marks. --}}
                                        <div class="flex items-center gap-1">
                                            <template x-for="c in categories" :key="c.key">
                                                <button type="button" class="text-xs px-2 py-1 rounded-md" @click="activeCategory = c.key"
                                                        :style="{ border: '1px solid var(--border)', background: activeCategory === c.key ? fillFor({category: c.key, authorRole: currentUserRole}) : 'transparent', fontWeight: activeCategory === c.key ? '700' : '400', borderBottom: activeCategory === c.key ? ('3px solid ' + markPalette[c.key].underline) : '1px solid var(--border)' }"
                                                        x-text="c.label"></button>
                                            </template>
                                        </div>
                                        <div class="flex items-center gap-1" x-show="activeTool === 'highlight'">
                                            <template x-for="s in strokeSizes" :key="s.key">
                                                <button type="button" class="text-xs px-2 py-1 rounded-md" :title="s.label" @click="setStrokeSize(s.key)"
                                                        :style="{ border:'1px solid var(--border)', background: strokeSizeKey === s.key ? 'var(--ds-blue-soft, #eff6ff)' : 'transparent', fontWeight: strokeSizeKey === s.key ? '700' : '400' }" x-text="s.label"></button>
                                            </template>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <button type="button" class="text-xs px-2 py-1 rounded-md" title="Undo (Ctrl+Z)"
                                                    @click="undo()" :disabled="!canUndo()"
                                                    :style="{ border:'1px solid var(--border)', color: canUndo() ? 'var(--text-secondary)' : 'var(--text-muted)', opacity: canUndo() ? '1' : '0.5', cursor: canUndo() ? 'pointer' : 'default' }">Undo</button>
                                            <button type="button" class="text-xs px-2 py-1 rounded-md" title="Redo (Ctrl+Shift+Z)"
                                                    @click="redo()" :disabled="!canRedo()"
                                                    :style="{ border:'1px solid var(--border)', color: canRedo() ? 'var(--text-secondary)' : 'var(--text-muted)', opacity: canRedo() ? '1' : '0.5', cursor: canRedo() ? 'pointer' : 'default' }">Redo</button>
                                        </div>
                                        <span class="text-xs font-semibold" style="color: var(--text-secondary);">
                                            <span x-text="markCount()"></span> mark<span x-show="markCount() !== 1">s</span>
                                        </span>
                                        <button type="button" class="corex-btn-primary text-xs"
                                                :disabled="applying || pagesLoading" :title="pagesLoading ? 'Still loading the rest of this document' : ''"
                                                x-text="applying ? 'Saving…' : (pagesLoading ? 'Loading…' : 'Save')" @click="applyHighlights()"></button>
                                        <button type="button" class="corex-btn-outline text-xs" @click="closeHighlighter()">Done</button>
                                    </div>

                                    @include('corex.rental-applications.partials.document-highlighter-pages')
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            @if($auditLog->isNotEmpty())
                {{-- Conductor, 2026-09-08 (night run): an unbounded render buried the
                     Decision panel below a growing scroll on a busy application. Newest
                     10 always visible; the rest behind a "Show all" toggle. The
                     controller already caps the query itself at 200 — $auditLogTotal
                     tells the truth if even that cap was hit. --}}
                <div class="rounded-md p-4" x-data="{ showAllAudit: false }" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Audit Trail ({{ $auditLogTotal }})</h2>
                        @if($auditLog->count() > 10)
                            <button type="button" class="text-xs underline" style="color: var(--text-muted);"
                                    @click="showAllAudit = !showAllAudit"
                                    x-text="showAllAudit ? 'Show fewer' : 'Show all {{ $auditLog->count() }} entries'"></button>
                        @endif
                    </div>
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
                                @if($entry->reason)
                                    <div class="mt-0.5 whitespace-pre-wrap" style="color: var(--text-secondary);">{{ $entry->reason }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if($auditLogTotal > $auditLog->count())
                        <p class="mt-2 text-xs" style="color: var(--text-muted);">Showing the {{ $auditLog->count() }} most recent of {{ $auditLogTotal }} total entries.</p>
                    @endif
                </div>
            @endif
        </div>

        <div class="rah-auth-aside rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Decision</h2>
            @if($blockedBySelfApproval)
                {{-- Johan, 2026-09-09, verbatim: "Self approve should only work
                     for the co of rentals or admin - rest agents and ro can
                     not approve their own." Server-enforced on every decision
                     endpoint (guardNotSelfApproving()) — this is the reason
                     shown, not the only thing stopping it. Never a missing
                     button with no explanation. --}}
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

                {{-- Approve --}}
                <div class="rounded-md p-3 mb-3" style="border: 1px solid var(--border);">
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Approve — monthly amount</label>
                    {{-- Johan, 2026-09-08 — "hitting the . on an amount clears the
                         values". type="number" + x-model writes the browser's own
                         parsed .value back into the field; a lone trailing "."
                         doesn't parse as a number yet, so the write-back silently
                         drops it mid-type. text + inputmode="decimal" gives the
                         same numeric keyboard on mobile with none of that native
                         parsing interference — the raw typed string passes
                         through untouched; sanitizeNumericInput() on the server
                         is the only place that ever interprets it. --}}
                    <input type="text" inputmode="decimal" x-model="approveAmount" class="corex-input text-sm w-full mb-2" placeholder="0.00">
                    <textarea x-model="approveReason" rows="2" class="corex-input text-xs w-full mb-2" placeholder="{{ $alreadyDecided ? 'Reason for override (required)' : 'Notes (optional)' }}"></textarea>
                    <form method="POST" action="{{ route('corex.rental-applications.authorisation.approve', $rentalApplication) }}" @submit="$refs.approveAmountField.value = approveAmount; $refs.approveReasonField.value = approveReason">
                        @csrf
                        <input type="hidden" name="approved_rental_amount" x-ref="approveAmountField">
                        <input type="hidden" name="reason" x-ref="approveReasonField">
                        <button type="submit" class="corex-btn-primary text-xs w-full" :disabled="!approveAmount">Approve</button>
                    </form>
                </div>

                {{-- Decline --}}
                <div class="rounded-md p-3 mb-3" style="border: 1px solid var(--border);">
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Decline</label>
                    <textarea x-model="declineReason" rows="2" class="corex-input text-xs w-full mb-2" placeholder="{{ $alreadyDecided ? 'Reason for override (required)' : 'Notes (optional)' }}"></textarea>
                    <form method="POST" action="{{ route('corex.rental-applications.authorisation.decline', $rentalApplication) }}" @submit="$refs.declineReasonField.value = declineReason">
                        @csrf
                        <input type="hidden" name="reason" x-ref="declineReasonField">
                        <button type="submit" class="corex-btn-outline text-xs w-full" style="color: var(--ds-red, #dc2626); border-color: var(--ds-red, #dc2626);">Decline</button>
                    </form>
                </div>

                {{-- Request more information (only while pending — not an override action) --}}
                @unless($alreadyDecided)
                    <div class="rounded-md p-3" style="border: 1px solid var(--border);">
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Request more information</label>
                        <p class="text-xs mb-2" style="color: var(--text-muted);">Sends this back to the agent, not the applicant.</p>
                        <textarea x-model="moreInfoReason" rows="2" class="corex-input text-xs w-full mb-2" placeholder="What's missing? (required)"></textarea>
                        <form method="POST" action="{{ route('corex.rental-applications.authorisation.request-more-info', $rentalApplication) }}" @submit="$refs.moreInfoReasonField.value = moreInfoReason">
                            @csrf
                            <input type="hidden" name="reason" x-ref="moreInfoReasonField">
                            <button type="submit" class="corex-btn-outline text-xs w-full" :disabled="!moreInfoReason.trim()">Request More Information</button>
                        </form>
                    </div>
                @endunless
            @endif
        </div>
    </div>
</div>

@include('corex.rental-applications.partials.document-highlighter-script')

<script>
function rentalAuthorisationViewer({ initialMarkedUpDocIds, currentUserId, currentUserName, currentUserRole }) {
    return {
        // Shared highlight/note viewer — see partials/document-highlighter-script.blade.php.
        ...rentalDocumentHighlighter({ initialMarkedUpDocIds, currentUserId, currentUserName, currentUserRole }),

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
        newIncomeDescription: '', newIncomeAmount: '',
        newExpenseDescription: '', newExpenseAmount: '',
        replacingItem: null, replaceDescription: '', replaceAmount: '',
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
            if (!amount) return;
            const body = { description, amount };
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
                this.replacingItem = null; this.replaceDescription = ''; this.replaceAmount = '';
            } else if (isIncome) { this.newIncomeDescription = ''; this.newIncomeAmount = ''; }
            else { this.newExpenseDescription = ''; this.newExpenseAmount = ''; }
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
