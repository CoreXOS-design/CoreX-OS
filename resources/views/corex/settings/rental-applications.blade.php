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
         agency name, and (optionally) the property the application was
         for — no invented "how to improve" guidance (Johan was explicit
         that part is still an open idea, not settled). --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Decline Email</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            Sent to the applicant if the authoriser declines their application. A suggested wording
            is shown below — edit it to your own. Available merge fields:
            <code>@{{applicant_name}}</code>, <code>@{{agency_name}}</code>,
            <code>@{{property_reference}}</code> (optional — resolves to nothing if the
            application has no property linked).
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
