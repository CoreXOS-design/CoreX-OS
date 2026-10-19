{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- AT-455 (Andre, 2026-10-14): split view — contact list on the left, the chosen
     contact's searches on the right. No emojis, no coloured boxes: pipeline status
     is a small dot beside the word, anything needing attention is plain amber text,
     blue is reserved for actions. Every filter, popup and button is unchanged. --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $firstContactId = (int) optional($rows->first())['contact']?->id;
    $showContactAgent = $scope !== 'own';
@endphp
{{-- On desktop the page fills the main area: header + filter bar stay put, the
     contact list and the searches pane each scroll inside themselves. --}}
<div class="w-full lg:h-full flex flex-col"
     x-data="{
        sel: (() => { const m = (location.hash || '').match(/^#c(\d+)$/); return m ? parseInt(m[1]) : {{ $firstContactId }}; })(),
        nextUrl: @js($nextPageUrl ?? null),
        loading: false,
        pick(id) { this.sel = id; try { history.replaceState(null, '', '#c' + id); } catch (e) {} },
        init() {
            // A remembered contact that is not on the first batch (after a reload) falls back to the first.
            if (this.$refs.panes && !this.$refs.panes.querySelector('[data-cm-pane=&quot;' + this.sel + '&quot;]')) { this.sel = {{ $firstContactId }}; }
        },
        async more() {
            if (!this.nextUrl || this.loading) return;
            this.loading = true;
            try {
                const r = await fetch(this.nextUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!r.ok) { this.nextUrl = null; return; }
                const doc = new DOMParser().parseFromString(await r.text(), 'text/html');
                const list = doc.querySelector('template[data-cm-list]'), panes = doc.querySelector('template[data-cm-panes]');
                if (!list || !panes) { this.nextUrl = null; return; }
                // An agent heading that continues the previous batch is not repeated.
                const lastHead = [...this.$refs.list.querySelectorAll('[data-cm-group]')].pop();
                const firstHead = list.content.firstElementChild;
                if (lastHead && firstHead && firstHead.hasAttribute('data-cm-group') && firstHead.textContent.trim() === lastHead.textContent.trim()) firstHead.remove();
                const newRows = [...document.importNode(list.content, true).children];
                const newPanes = [...document.importNode(panes.content, true).children];
                const marker = this.$refs.list.querySelector('[data-cm-loading]');
                newRows.forEach(el => { this.$refs.list.insertBefore(el, marker); Alpine.initTree(el); });
                newPanes.forEach(el => { this.$refs.panes.appendChild(el); Alpine.initTree(el); });
                this.nextUrl = doc.querySelector('[data-cm-next]')?.getAttribute('data-cm-next') || null;
            } catch (e) { this.nextUrl = null; }
            finally { this.loading = false; }
        },
     }">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner mb-5">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div data-tour="re-core-matches-intro">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Core Matches</h1>
                <p class="text-xs" style="color: var(--text-muted);">Buyer and renter search criteria saved against your contacts.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                {{-- Listing-type lens — a static label on a Rentals entry point
                     (server-side locked, see controller). Elsewhere the Type
                     dropdown in the filter bar does this job. --}}
                @if($isRentalEntry)
                <span class="corex-btn-outline text-sm" style="cursor:default;" title="This entry point always shows rental searches only">Rentals only</span>
                @endif
                @include('layouts.partials.tour-header-launcher')
                <a href="{{ route('corex.contacts.index') }}" class="corex-btn-outline text-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                    Contacts
                </a>
                @permission('access_settings')
                <a href="{{ url('/corex/settings?s=feature-matches') }}"
                   title="Core Matches Settings" aria-label="Core Matches Settings"
                   class="inline-flex items-center justify-center rounded-md transition-colors"
                   style="width:30px; height:30px; background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                </a>
                @endpermission
                {{-- Findability fix, 2026-09-29 (Johan, revised) — the "which statuses are
                     included in Core Matches" setting lives in main Settings' own Core
                     Matches section. Same permission as the setting itself. --}}
                @permission('command_center.settings')
                <a href="{{ url('/corex/settings?s=core-matches') }}"
                   title="Which statuses are shown in Core Matches" aria-label="Which statuses are shown in Core Matches"
                   class="inline-flex items-center justify-center rounded-md transition-colors"
                   style="width:30px; height:30px; background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 0 1-.659 1.591l-5.432 5.432a2.25 2.25 0 0 0-.659 1.591v2.927a2.25 2.25 0 0 1-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 0 0-.659-1.591L3.659 7.409A2.25 2.25 0 0 1 3 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0 1 12 3Z"/>
                    </svg>
                </a>
                @endpermission
            </div>
        </div>
    </div>

    {{-- Filter / sort bar. The scope switch (Mine / Branch / Agency) sits at the left
         of the bar, only rendered when the viewer actually holds more than one option
         (core_matches.all_view). The name/phone/email search box lives above the
         contact list below but posts into this same form (form="cm-filters"). --}}
    <div class="props-filter-panel rounded-md px-5 py-4 flex-shrink-0 mb-5" style="background:var(--surface); border:1px solid var(--border);">
        <form method="GET" id="cm-filters" class="flex items-end gap-3 flex-wrap">
            @if($isRentalEntry)<input type="hidden" name="listing_type" value="rental">@endif
            @if(count($availableScopes) > 1)<input type="hidden" name="scope" value="{{ $scope }}">@endif

            @if(count($availableScopes) > 1)
            <div class="flex flex-col gap-1">
                <div class="inline-flex rounded-md overflow-hidden" style="border: 1px solid var(--border);">
                    @foreach($availableScopes as $s)
                    <a href="{{ request()->fullUrlWithQuery(['scope' => $s, 'agent_id' => null]) }}"
                       class="px-3 py-2.5 text-xs font-semibold whitespace-nowrap no-underline leading-4"
                       style="{{ !$loop->first ? 'border-left: 1px solid var(--border);' : '' }} {{ $scope === $s ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">
                        {{ match($s) { 'own' => 'Mine', 'branch' => 'Branch', 'agency' => 'Agency', default => ucfirst($s) } }}
                    </a>
                    @endforeach
                </div>
            </div>
            @endif

            @if(!$isRentalEntry)
            <div class="flex flex-col gap-1">
                <select name="listing_type" onchange="this.form.submit()" class="list-header-filter {{ request('listing_type') ? 'is-active' : '' }}"
                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary); min-width:110px;">
                    @foreach(['' => 'All types', 'sale' => 'Sales', 'rental' => 'Rentals'] as $val => $label)
                    <option value="{{ $val }}" @selected(($listingType ?? '') === $val)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @endif

            <div class="flex flex-col gap-1">
                <select name="buyer_state" onchange="this.form.submit()" class="list-header-filter {{ request('buyer_state') ? 'is-active' : '' }}"
                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary); min-width:120px;">
                    <option value="" @selected($buyerState === '')>Any buyer status</option>
                    @foreach($buyerStateOptions as $state)
                    <option value="{{ $state }}" @selected($buyerState === $state)>{{ ucfirst($state) }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <select name="status" onchange="this.form.submit()" class="list-header-filter {{ request('status') ? 'is-active' : '' }}" data-tour="re-core-matches-status"
                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary); min-width:130px;">
                    <option value="" @selected($statusFilter === '')>Any search status</option>
                    @foreach(['active' => 'Active', 'paused' => 'Paused', 'fulfilled' => 'Fulfilled', 'expired' => 'Expired'] as $val => $label)
                    <option value="{{ $val }}" @selected($statusFilter === $val)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <input type="date" name="saved_from" value="{{ $savedFrom }}" onchange="this.form.submit()" aria-label="Saved from" title="Saved from"
                       class="rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
            </div>
            <div class="flex flex-col gap-1">
                <input type="date" name="saved_to" value="{{ $savedTo }}" onchange="this.form.submit()" aria-label="Saved to" title="Saved to"
                       class="rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
            </div>

            @if($scope !== 'own')
            <div class="flex flex-col gap-1">
                <select name="agent_id" onchange="this.form.submit()" class="list-header-filter {{ request('agent_id') ? 'is-active' : '' }}"
                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary); min-width:180px;">
                    <option value="all" @selected($agentId === null)>All agents</option>
                    @foreach($agents as $agent)
                    <option value="{{ $agent->id }}" @selected($agentId === (int) $agent->id)>{{ $agent->name }}</option>
                    @endforeach
                </select>
            </div>
            @endif

            <div class="flex flex-col gap-1">
                <select name="sort" onchange="this.form.submit()" class="list-header-filter"
                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary); min-width:170px;">
                    <option value="priority" @selected($sort === 'priority')>Status (default)</option>
                    <option value="saved" @selected($sort === 'saved')>Most recently saved</option>
                    <option value="contact" @selected($sort === 'contact')>Longest since contact</option>
                </select>
            </div>

            <button type="submit" class="corex-btn-primary text-sm" data-tour="re-core-matches-apply">Apply</button>
            @if($search !== '' || $statusFilter !== '' || $buyerState !== '' || (!$isRentalEntry && ($listingType ?? '') !== '') || $savedFrom !== '' || $savedTo !== '' || $agentId !== null || $sort !== 'priority')
            <a href="{{ request()->url() }}{{ $isRentalEntry ? '?listing_type=rental' : '' }}" class="corex-btn-outline text-sm">Clear</a>
            @endif

            <div class="flex-1"></div>
            <span class="text-xs font-medium whitespace-nowrap self-center" style="color:var(--text-secondary);">
                {{ number_format($totalMatches) }} {{ Str::plural('search', $totalMatches) }} · {{ number_format($contacts->total()) }} {{ Str::plural('contact', $contacts->total()) }}
            </span>
        </form>
    </div>

    @if($contacts->total() === 0 && $search === '' && $statusFilter === '' && $buyerState === '' && ($isRentalEntry || ($listingType ?? '') === '') && $savedFrom === '' && $savedTo === '' && $agentId === null)
    {{-- Genuinely nothing yet — different message from "no results for this filter". --}}
    <div class="rounded-md py-12 px-6 text-center flex-shrink-0 mb-5" style="background:var(--surface); border:1px solid var(--border);">
        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
             style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" /></svg>
        </div>
        <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">
            {{ $scope === 'own' ? 'No Core Matches saved yet' : 'No Core Matches found' . ($scope === 'branch' ? ' in your branch' : ' in your agency') }}
        </h3>
        @if($scope === 'own')
        <p class="text-sm mb-4" style="color:var(--text-muted);">Open a contact and go to the Core Matches tab to save buyer or renter criteria.</p>
        <a href="{{ route('corex.contacts.index') }}" class="corex-btn-primary text-sm">Go to Contacts</a>
        @endif
    </div>
    @else
    {{-- ── SPLIT VIEW: contact list | searches pane ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-[300px_minmax(0,1fr)] gap-4 items-start lg:items-stretch lg:flex-1 lg:min-h-0 mb-5">

        {{-- LEFT — search box, then one line per contact on this page, grouped
             under the contact's primary agent (Branch / Agency scope). The list is
             the page's pagination; the pane shows whichever contact is picked. --}}
        <div class="rounded-md overflow-hidden flex flex-col max-h-[40vh] lg:max-h-none lg:h-full" style="background:var(--surface); border:1px solid var(--border);">
            <label class="flex items-center gap-2 px-3 py-2 flex-shrink-0" style="border-bottom:1px solid var(--border);">
                <svg class="w-3.5 h-3.5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted);"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                <input type="text" name="q" form="cm-filters" value="{{ $search }}" placeholder="Search name, phone or email" class="list-header-filter {{ $search !== '' ? 'is-active' : '' }} w-full text-sm bg-transparent outline-none border-0 p-0" data-tour="re-core-matches-search"
                       style="color:var(--text-primary);">
            </label>
            {{-- The list loads its next batch of contacts by itself when scrolled
                 near its end (more() below) — no page bar. --}}
            <div class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll" x-ref="list"
                 @scroll.throttle.200ms="if ($el.scrollTop + $el.clientHeight >= $el.scrollHeight - 160) more()">
                @if($rows->isEmpty())
                {{-- Matches exist, but none match the current filter. --}}
                <div class="px-4 py-8 text-center text-sm" style="color:var(--text-muted);">No contacts match this filter.</div>
                @else
                @include('corex.core-matches._list', ['withTour' => true])
                @endif
                <div data-cm-loading x-show="loading" x-cloak class="px-4 py-3 text-center text-xs" style="color:var(--text-muted);">Loading more…</div>
            </div>
        </div>

        {{-- RIGHT — the picked contact: header with the contact's own facts and
             actions, then one card per saved search. Scrolls on its own. --}}
        <div class="min-w-0 lg:h-full lg:overflow-y-auto corex-brand-scroll" x-ref="panes">
            @if($rows->isEmpty())
            <div class="rounded-md py-12 px-6 text-center" style="background:var(--surface); border:1px solid var(--border);">
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No Core Matches match this filter</h3>
                <p class="text-sm" style="color:var(--text-muted);">Try clearing a filter or searching a different term.</p>
            </div>
            @endif
            @include('corex.core-matches._panes', ['withTour' => true])
        </div>
    </div>

    @endif

    {{-- Notes popup — Johan: "the notes should open in a popup not
         redirect to the contact", so a manager can read them without
         losing the board and its filters. Read-only (see
         BuyerNotesController::show()); one modal shell for the whole
         page, content fetched on demand for whichever contact was
         clicked — never pre-loaded for every row. --}}
    <div x-data="{ loading: false, html: '' }"
         @open-notes-quick-view.window="
             loading = true; html = '';
             $dispatch('open-modal', 'notes-quick-view');
             fetch($event.detail.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                 .then(r => r.text())
                 .then(t => { html = t; loading = false; })
                 .catch(() => { html = '<p class=&quot;text-sm&quot; style=&quot;color:var(--ds-crimson);&quot;>Could not load notes.</p>'; loading = false; })
         ">
        <x-modal name="notes-quick-view" max-width="lg">
            <div class="p-6">
                <div x-show="loading" class="text-sm" style="color:var(--text-muted);">Loading…</div>
                <div x-show="!loading" x-html="html"></div>
                <div class="mt-5 text-right">
                    <button type="button" class="corex-btn-outline text-xs" @click="$dispatch('close-modal', 'notes-quick-view')">Close</button>
                </div>
            </div>
        </x-modal>
    </div>

    {{-- Add-note popup — ONE modal for the whole board; the row's "+ Note" button
         supplies the contact's name and the contact-notes store URL. "Note only"
         saves the note; "Contacted and note" saves it AND marks the buyer contacted
         (same endpoint, same contacted rule as the contact screen). Returns to this
         board with its filters intact. --}}
    @if(count($noteableContactIds))
    <div x-data="{ action: '', name: '' }"
         @open-core-match-note.window="action = $event.detail.action; name = $event.detail.name; $dispatch('open-modal', 'core-match-note')">
        <x-modal name="core-match-note" max-width="md">
            <form method="POST" :action="action" class="p-5 space-y-3">
                @csrf
                <input type="hidden" name="redirect_to" value="back">
                <div class="text-sm font-semibold" style="color:var(--text-primary);" x-text="name"></div>
                <textarea name="body" rows="3" required maxlength="5000" placeholder="Note"
                          class="w-full rounded-md px-3 py-2 text-sm resize-none"
                          style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                <div class="flex justify-end items-center gap-2">
                    <button type="button" class="text-xs" style="color:var(--text-muted);" @click="$dispatch('close-modal', 'core-match-note')">Cancel</button>
                    <button type="submit" class="corex-btn-outline text-sm">Note only</button>
                    <button type="submit" name="mark_contacted" value="1" class="corex-btn-primary text-sm">Contacted and note</button>
                </div>
            </form>
        </x-modal>
    </div>
    @endif

    {{-- Update buyer pipeline popup — ONE for the whole board; the row's button supplies the
         buyer's name, current status and the pipeline endpoints. The list is the Buyer Pipeline
         board's own (New / Warm / Cold / Lost). New / Warm / Cold post to the board's own state
         endpoint (BuyerPipelineController::updateState) exactly as a drag on the board does, then
         the board reloads: the chip shows the new status, and a buyer whose new status this agency
         excludes from Core Matches (CoreMatchBuyerGate) drops off the list. Lost does NOT post
         here — it opens the shared Mark-Lost dialog (the same partial the buyer page uses), which
         captures the reason and posts to the board's own mark-lost endpoint. --}}
    @if(count($pipelineMovableContactIds))
    <div x-data="{
            name: '', current: '', state: '', noun: 'buyer', stateUrl: '', lostUrl: '', busy: false,
            open(d) { this.name = d.name; this.current = d.state; this.state = d.state; this.noun = d.noun; this.stateUrl = d.stateUrl; this.lostUrl = d.lostUrl; this.$dispatch('open-modal', 'core-match-pipeline'); },
            async save() {
                if (!this.state || this.state === this.current || this.busy) return;
                if (this.state === 'lost') {
                    this.$dispatch('close-modal', 'core-match-pipeline');
                    const dlg = this.$refs.coreMatchLostModal;
                    const form = dlg.querySelector('[data-mark-lost-form]');
                    form.action = this.lostUrl;
                    dlg.querySelectorAll('[data-lost-noun]').forEach(el => el.textContent = this.noun);
                    dlg.showModal();
                    return;
                }
                this.busy = true;
                try {
                    const r = await fetch(this.stateUrl, {
                        method: 'PATCH',
                        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                        body: JSON.stringify({ state: this.state }),
                    });
                    if (r.ok) { window.location.reload(); return; }
                    (window.showToast || alert)(r.status === 404
                        ? 'This ' + this.noun + ' is no longer on the pipeline — someone may have archived or moved them. Refresh to see the current list.'
                        : 'Could not update the ' + this.noun + ' pipeline status.', 'error');
                } catch (e) {
                    (window.showToast || alert)('Network error.', 'error');
                }
                this.busy = false;
            },
         }"
         @open-core-match-pipeline.window="open($event.detail)">
        <x-modal name="core-match-pipeline" max-width="md">
            <div class="p-5 space-y-3">
                <div class="text-sm font-semibold" style="color:var(--text-primary);" x-text="name"></div>
                <label class="block text-xs font-medium" style="color:var(--text-secondary);">
                    Buyer pipeline status
                    <select x-model="state" class="mt-1 w-full rounded-md px-3 py-2 text-sm"
                            style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        @foreach(['new' => 'New', 'warm' => 'Warm', 'cold' => 'Cold', 'lost' => 'Lost'] as $stateKey => $stateLabel)
                        <option value="{{ $stateKey }}">{{ $stateLabel }}</option>
                        @endforeach
                        <option value="won" disabled x-show="current === 'won'">Won</option>
                    </select>
                </label>
                <p class="text-xs" style="color:var(--text-muted);" x-show="state === 'lost' && current !== 'lost'">You will be asked for the reason next.</p>
                <div class="flex justify-end items-center gap-2">
                    <button type="button" class="text-xs" style="color:var(--text-muted);" @click="$dispatch('close-modal', 'core-match-pipeline')">Cancel</button>
                    <button type="button" class="corex-btn-primary text-sm" :disabled="!state || state === current || busy" @click="save()"
                            x-text="state === 'lost' ? 'Continue' : 'Update'">Update</button>
                </div>
            </div>
        </x-modal>

        {{-- The shared Mark-Lost dialog (one copy, also used by the buyer page). The opener above
             points its form at the chosen buyer's mark-lost endpoint; markLost() returns here. --}}
        @include('command-center.buyers._mark-lost-dialog', ['ref' => 'coreMatchLostModal', 'agencyId' => (int) auth()->user()->effectiveAgencyId(), 'noun' => 'buyer', 'action' => null])
    </div>
    @endif

    @if(count($reassignableContactIds))
        @include('corex.core-matches._move-buyer-modal', ['moveBuyerAgents' => $moveBuyerAgents])
    @endif

    {{-- "Send N new" popup — same shell/pattern as the notes popup above
         (one modal, content fetched on demand for whichever row's badge was
         clicked), not a second convention. --}}
    <div x-data="{ loading: false, html: '' }"
         @open-new-since-share.window="
             loading = true; html = '';
             $dispatch('open-modal', 'new-since-share');
             fetch($event.detail.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                 .then(r => r.text())
                 .then(t => { html = t; loading = false; })
                 .catch(() => { html = '<p class=&quot;text-sm&quot; style=&quot;color:var(--ds-crimson);&quot;>Could not load.</p>'; loading = false; })
         ">
        <x-modal name="new-since-share" max-width="lg">
            <div class="p-6">
                <div x-show="loading" class="text-sm" style="color:var(--text-muted);">Loading…</div>
                <div x-show="!loading" x-html="html"></div>
                <div class="mt-5 text-right">
                    <button type="button" class="corex-btn-outline text-xs" @click="$dispatch('close-modal', 'new-since-share')">Close</button>
                </div>
            </div>
        </x-modal>
    </div>

</div>
@endsection
