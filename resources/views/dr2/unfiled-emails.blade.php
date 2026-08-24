{{--
    CX-109 (Johan, 2026-08-20) — the Unfiled Emails screen, DR2's primary email-filing
    workflow. "unfiled email arrives -> agent works through the unfiled pile -> picks
    the deal it belongs to." Backed by UnfiledEmailsController
    (routes: deals-dr2.unfiled-emails.*).

    CX-113 Phase A (Johan, 2026-08-21) — scope + agent picker added: own/branch/all
    visibility (agents only see emails they were actually a party to — HFC has no
    shared mailboxes) and an admin/BM agent picker, both driven by the SAME decided
    mechanism as Deeds Capture / Market Intelligence (PermissionService::getDataScope
    + clampScope). Scope toggle is a plain server-rendered pill group + full GET
    navigation (Johan's explicit correction: not an Alpine/AJAX toggle, same idiom as
    MIC's filter rail / Buyer Pipeline) with STRICT gating — an option past the
    user's role ceiling never renders at all, not just clamped server-side.

    CX-113 Phase C (Johan, 2026-08-21) — "deal search and select ON THE ROW itself...
    no modal for the filing action." The old File-button-then-expand-row picker is
    gone: each row's action cell is now a search box that IS the filing control,
    always present. The post-filing "Filed. Related emails found." suggestion modal
    stays exactly as it was — Johan explicitly asked to keep it.

    CX-113 Phase D (Johan, 2026-08-21) — "auto-suggest the deal from filing history...
    suggest, never auto-file." Fetched from Dr2FilingSuggestionService the moment a
    row's search box opens, shown above the results until the agent types something.
    A click files it — same explicit-action requirement as every other row in this
    list, never automatic.

    CX-113 Phase E (Johan, 2026-08-21) — real problem on his screen: searching "santa"
    returned 5+ deals that all share "Santana" in the address; picking from that list
    is guessing. The right-side box was too narrow to show enough to decide, so the
    search moved to its own full-width row directly beneath each email row (still no
    modal — same explicit click-to-file). Every result now carries property address,
    status, and parties, PLUS why it's a candidate for THIS specific email — signal
    badges (email address match, learned filing history, a party surname in the
    subject, property address) ranked strongest-first server-side. Reuses
    Dr2DealPartyEmailResolver and Dr2FilingSuggestionService entirely — no new
    matcher.

    CX-113 Phase F (Johan, 2026-08-21) — styling pass after actually testing Phase E:
    "works great. looks absolute shit... looks 1980 computers." The table structure
    itself was the problem — fixed columns forced Preview off-screen at 1440px AND
    trapped the full-width deal search inside a two-column strip. Rebuilt as a card
    list (`space-y-3` of `rounded-md` / `var(--surface)` / `var(--border)` divs),
    matching resources/views/corex/deeds-capture/index.blade.php's own card treatment
    — no invented classes, every token here is real in corex.css. Subject promoted to
    primary weight (was competing equally with sender/date/preview); preview demoted
    to one muted truncated line; ALL-CAPS source subjects tamed via CSS
    text-transform:capitalize (paint-time only — the underlying text, and every test
    asserting on it, is unchanged). Signal/status badges recoloured using Deeds
    Capture's own `color-mix(in srgb, {token} 15%, transparent)` formula against real
    --ds-green/--ds-amber/--text-muted tokens, driven by the signal's OWN SCORE (not
    just its type) so a diluted match (Johan's "koos from ooba" case) visibly reads
    weaker than a unique one. Presentation only — ranking, filtering, filing, dedup,
    and the suggestion popup are all byte-for-byte the same logic as Phase E.

    CX-113 Phase I (Johan, 2026-08-22) — Comms Suspense retirement: "I like the look
    a lot more" (that screen's two-column card, content left / action panel right)
    "but the unfiled emails functionality." Card rebuilt two-column to match; the
    action panel's bottom splits in two per Johan's own words — "split the bottom in
    2... to auto matched and search. auto matched is based on our calcs if it matches
    a current deal." AUTO MATCHED (UnfiledEmailsController::autoMatchesFor(), server-
    side, computed with the page) shows OUR ranking's confident matches — reusing
    scoreDeal()/matchSignalsFor() (multi-party corroboration included) — as a plain
    evidence sentence, one click to file; genuinely says so when nothing is confident
    rather than showing a weak guess. SEARCH is the existing manual route, unchanged
    logic, now living in its own half of the split with "My Deals" on focus (Johan:
    "repurpose that to show My deals for users... make filing easy") before the agent
    types. Status chip + relative age + an attachment clip on the collapsed card and
    the 3-button stack (Confirm & file / Search all deals / Reject) in the action
    panel are the other Comms Suspense pieces salvaged, per Johan's own list.
    Behaviour frozen — ranking, filtering, filing, dedup, removal are the exact same
    logic as Phase E/G/H; this phase is presentation plus the new autoMatchesFor() read
    path only.
--}}
@extends('layouts.corex')

@php
    $scopeLabels = ['own' => 'Mine', 'branch' => 'Branch', 'all' => 'Company'];
    $scopeRank   = ['own' => 1, 'branch' => 2, 'all' => 3];
    $maxRank     = $scopeRank[$permittedScope] ?? 1;
@endphp

@section('corex-content')
<div class="w-full space-y-5"
     x-data="{
        filingId: null, moveMode: false,
        dealQ: '', dealResults: [], dealSearching: false, filing: false, err: '',
        suggestModal: false, suggestDeal: null, suggestions: [], suggestSelected: [], batchFiling: false,
        expandedId: null, expandedHtml: '', expanding: false,
        removeId: null, removing: false, removeOther: '',
        // CX-113 Phase I (Johan, 2026-08-22) — repurpose the deal dropdown to show the
        // agent's own deals for quick filing. Server-rendered once with the page
        // (window global, set outside this attribute — see the scripts push below;
        // never a literal double-quote anywhere in THIS attribute, this file has
        // shipped that exact mistake four times already). Opening the search box with
        // nothing typed shows this immediately instead of an empty list; typing 2+
        // characters replaces it with the real ranked search (searchDeals(), unchanged).
        myDeals: window.DR2_MY_DEALS || [],
        openPicker(commId, move = false){
            if(this.filingId === commId) return; // already active on this row — don't wipe what's typed
            this.filingId = commId; this.moveMode = move; this.dealQ=''; this.err='';
            this.dealResults = this.myDeals;
        },
        closePicker(){ this.filingId = null; this.moveMode = false; },
        async toggleExpand(commId){
            if(this.expandedId === commId){ this.expandedId = null; return; }
            this.expandedId = commId; this.expandedHtml = ''; this.expanding = true;
            try {
                const r = await fetch('{{ url('deals-dr2/communications') }}/' + commId + '/body', {headers:{Accept:'text/html'}});
                this.expandedHtml = r.ok ? await r.text() : '<p style=\'color:#b91c1c;font-size:.8rem;\'>Could not load this email.</p>';
            } catch(e) { this.expandedHtml = '<p style=\'color:#b91c1c;font-size:.8rem;\'>Could not load this email.</p>'; }
            this.expanding = false;
        },
        async searchDeals(){
            if(this.dealQ.trim().length < 2){ this.dealResults = this.myDeals; return; }
            this.dealSearching = true;
            try {
                const r = await fetch('{{ route('deals-dr2.unfiled-emails.deal-search') }}?q=' + encodeURIComponent(this.dealQ.trim()) + '&communication_id=' + this.filingId, {headers:{Accept:'application/json'}});
                this.dealResults = await r.json();
            } catch(e) { this.dealResults = []; }
            this.dealSearching = false;
        },
        // CX-113 Phase E/F — signal-badge colour driven by the signal's OWN SCORE, not
        // just its type: an email match on a party unique to this deal (score 100) and
        // the SAME type of match on a party who is on nine other deals (score ~5,
        // Johan's koos-from-ooba case) must NOT look equally confident. Real corex.css
        // tokens only (verified against the file, same formula Deeds Capture uses for
        // its match-strength legend): --ds-green = strong, --ds-amber = weak/partial,
        // --text-muted = barely worth noting. Purely a display mapping — the ranking
        // itself is entirely server-side and untouched. NOTE: nothing on these lines,
        // or anywhere else inside this double-quoted x-data attribute, may ever
        // contain a literal double-quote character — the browser's HTML parser
        // terminates the attribute at the first one regardless of JS/comment syntax.
        // Shipped that exact mistake three times today; every edit here gets re-swept.
        signalBadgeStyle(score){
            const token = score >= 80 ? 'var(--ds-green, #059669)' : (score >= 30 ? 'var(--ds-amber, #f59e0b)' : 'var(--text-muted, #9ca3af)');
            return 'background:color-mix(in srgb, ' + token + ' 15%, transparent);color:' + token + ';';
        },
        // Deal-status pill on each search result — same colour language as the signal
        // badges: proceeding statuses read positive, Declined reads as the negative
        // tone Deeds Capture uses for its own status indicators.
        dealStatusPillStyle(status){
            const token = status === 'Declined' ? 'var(--ds-crimson, #c41e3a)' : (status === 'Pending' ? 'var(--text-muted, #9ca3af)' : 'var(--ds-green, #059669)');
            return 'background:color-mix(in srgb, ' + token + ' 15%, transparent);color:' + token + ';';
        },
        // CX-113 Phase G (Johan, 2026-08-22) — not deal correspondence, reversible.
        // Same click-a-reason-and-its-done idiom as filing (no separate confirm step);
        // agency-wide, so the row leaves everyone's queue, not just the remover's.
        async dismissEmail(commId, reason){
            if(reason === 'other' && !this.removeOther.trim()) return;
            this.removing = true;
            try {
                const r = await fetch('{{ url('deals-dr2/unfiled-emails') }}/' + commId + '/dismiss', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':'{{ csrf_token() }}'},
                    credentials: 'same-origin',
                    body: JSON.stringify({reason: reason, reason_other: this.removeOther.trim() || null})
                });
                const j = await r.json();
                if(r.ok && j.ok){
                    this.removeId = null; this.removeOther = '';
                    document.getElementById('unfiled-row-' + commId)?.remove();
                } else {
                    this.err = 'Could not remove that email.';
                }
            } catch(e) { this.err = 'Could not remove that email.'; }
            this.removing = false;
        },
        async restoreEmail(commId){
            this.removing = true;
            try {
                await fetch('{{ url('deals-dr2/unfiled-emails') }}/' + commId + '/restore', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':'{{ csrf_token() }}'},
                    credentials: 'same-origin',
                });
                document.getElementById('unfiled-row-' + commId)?.remove();
            } catch(e) { this.err = 'Could not restore that email.'; }
            this.removing = false;
        },
        async file(commId, dealId){
            this.filing = true; this.err = '';
            try {
                const r = await fetch('{{ url('deals-dr2/unfiled-emails') }}/' + commId + '/file', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':'{{ csrf_token() }}'},
                    credentials: 'same-origin',
                    body: JSON.stringify({deal_id: dealId, move: this.moveMode})
                });
                const j = await r.json();
                if(r.ok && j.ok){
                    this.filingId = null;
                    if(this.moveMode){
                        location.reload();
                    } else {
                        document.getElementById('unfiled-row-' + commId)?.remove();
                        if(j.suggestions && j.suggestions.length){
                            this.suggestDeal = j.deal;
                            this.suggestions = j.suggestions;
                            this.suggestSelected = j.suggestions.map(s => s.id);
                            this.suggestModal = true;
                        } else {
                            location.reload();
                        }
                    }
                } else {
                    this.err = (j.message || 'Could not file that email.');
                }
            } catch(e) { this.err = 'Could not file that email.'; }
            this.filing = false;
        },
        async confirmSuggestions(){
            if(!this.suggestSelected.length){ this.suggestModal = false; location.reload(); return; }
            this.batchFiling = true;
            try {
                await fetch('{{ route('deals-dr2.unfiled-emails.file-batch') }}', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':'{{ csrf_token() }}'},
                    credentials: 'same-origin',
                    body: JSON.stringify({deal_id: this.suggestDeal.id, communication_ids: this.suggestSelected})
                });
            } catch(e) {}
            this.batchFiling = false;
            this.suggestModal = false;
            location.reload();
        }
     }">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Deal Register Unfiled Emails</h1>
                <p class="text-sm text-white/60">Emails not yet filed to a deal, that you were actually a party to. File one, and any related unfiled emails are suggested.</p>
            </div>
            <div class="flex items-center gap-3">
                {{-- Data scope — plain server-rendered pill group, full GET navigation, same
                     idiom as MIC's filter rail / Buyer Pipeline. STRICT gating: a scope past
                     the role ceiling never renders as an option at all. Active pill keeps
                     WHITE text and swaps the BACKGROUND to the brand accent instead of a dark
                     literal/var(--brand-default) on white — corex.css has a broad dark-mode
                     guard (html.dark [style*="color:var(--brand-default"], plus a list of dark
                     literal hexes incl. #0b2a4a) that neutralises ANY of those used as inline
                     TEXT color, confirmed via Chrome DevTools Protocol matched-styles (both the
                     variable and the literal rendered invisible white-on-white). Matches the
                     agent-picker button's own accent-highlight convention elsewhere on this
                     screen (border/text in --brand-icon) but inverted to background so the text
                     itself is never a color the guard can catch. --}}
                <div class="inline-flex rounded-md overflow-hidden" style="border: 1px solid rgba(255,255,255,0.25);">
                    @foreach($scopeLabels as $key => $label)
                        @if(($scopeRank[$key] ?? 9) <= $maxRank)
                            <a href="{{ route('deals-dr2.unfiled-emails.index', array_merge(request()->except(['scope', 'page']), ['scope' => $key])) }}"
                               class="px-3 py-1.5 text-sm font-medium transition-colors"
                               style="{{ $scope === $key ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'color: #fff;' }}">{{ $label }}</a>
                        @endif
                    @endforeach
                </div>
                <div class="text-sm text-white/80">{{ $emails->total() }} {{ $state === 'filed' ? 'filed' : ($state === 'all' ? 'total' : ($state === 'removed' ? 'removed' : 'unfiled')) }}</div>
            </div>
        </div>

        {{-- CX-113 Phase B — filed-state filter. Unfiled (default) / Filed / All. Search
             spans whichever state is active. Same pill idiom as scope above, no ceiling
             gating (state is not a permission concept — every scope tier gets all three). --}}
        <div class="mt-3 inline-flex rounded-md overflow-hidden" style="border: 1px solid rgba(255,255,255,0.25);">
            @foreach(['unfiled' => 'Unfiled', 'filed' => 'Filed', 'all' => 'All', 'removed' => 'Removed'] as $key => $label)
                <a href="{{ route('deals-dr2.unfiled-emails.index', array_merge(request()->except(['state', 'page']), ['state' => $key])) }}"
                   class="px-3 py-1.5 text-sm font-medium transition-colors"
                   style="{{ $state === $key ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'color: #fff;' }}">{{ $label }}</a>
            @endforeach
        </div>
    </div>

    {{-- Search + agent picker — one GET form so every control composes (search, scope,
         and the picked agent all apply together); scope travels as a hidden field so a
         Search submit never resets it back to the default. Agent-picker Alpine idiom
         copied from Deeds Capture/DealsCaptureController (same decided mechanism). --}}
    <div x-data="{
            agentPicker: false,
            agentSearch: '',
            agents: {{ \Illuminate\Support\Js::from($agentList) }},
            get filtered() {
                if (!this.agentSearch) return this.agents;
                const q = this.agentSearch.toLowerCase();
                return this.agents.filter(a => a.name.toLowerCase().includes(q) || a.email.toLowerCase().includes(q));
            },
            pickAgent(id) {
                const f = this.$refs.unfiledFilterForm;
                let h = f.querySelector('input[name=agent_id]');
                if (!h) { h = document.createElement('input'); h.type = 'hidden'; h.name = 'agent_id'; f.appendChild(h); }
                h.value = (id === null) ? '' : id;
                f.submit();
            }
         }">
        <form method="GET" action="{{ route('deals-dr2.unfiled-emails.index') }}" x-ref="unfiledFilterForm" class="flex flex-wrap items-center gap-2">
            <input type="hidden" name="scope" value="{{ $scope }}">
            <input type="hidden" name="state" value="{{ $state }}">
            <input type="text" name="q" value="{{ $search }}"
                   placeholder="Search subject, body, sender/recipient, property, seller, buyer, or attorney…"
                   class="corex-input" style="max-width:28rem;">

            @if($canPickAgent)
                {{-- Always rendered so a Search submit never drops the current agent filter. --}}
                <input type="hidden" name="agent_id" value="{{ $filterAgentId }}">

                <div class="inline-flex items-center gap-1">
                    <button type="button" @click="agentPicker = true"
                            class="list-header-filter inline-flex items-center gap-1.5 cursor-pointer"
                            style="{{ $selectedAgent ? 'border-color:var(--brand-icon,#0ea5e9);color:var(--brand-icon,#0ea5e9);' : '' }}">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <circle cx="9" cy="7" r="4"/><path stroke-linecap="round" stroke-linejoin="round" d="M3 21v-1a6 6 0 016-6h0M16 19l2 2 4-4"/>
                        </svg>
                        {{ $selectedAgent ? $selectedAgent->name : 'All Agents' }}
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    @if($filterAgentId !== '')
                        <button type="button" @click="pickAgent(null)"
                           class="inline-flex items-center justify-center w-6 h-6 rounded-md text-xs font-bold transition-all duration-300 cursor-pointer"
                           style="color:var(--text-muted);" title="Clear agent filter">&times;</button>
                    @endif
                </div>

                <div x-show="agentPicker" x-cloak
                     class="fixed inset-0 z-50 flex items-center justify-center p-4"
                     style="background:rgba(0,0,0,0.5);"
                     @click.self="agentPicker = false"
                     @keydown.escape.window="agentPicker = false"
                     x-transition.opacity>
                    <div class="w-full max-w-md rounded-md overflow-hidden flex flex-col" style="max-height:80vh;background:var(--surface,#fff);border:1px solid var(--border,#e5e7eb);box-shadow:0 20px 60px rgba(0,0,0,0.3);">
                        <div class="flex items-center justify-between px-4 py-3 flex-shrink-0" style="border-bottom:1px solid var(--border,#e5e7eb);">
                            <h3 class="text-sm font-semibold" style="color:var(--text-primary);">Select Agent</h3>
                            <button type="button" @click="agentPicker = false"
                                    class="inline-flex items-center justify-center w-7 h-7 rounded-md transition-all duration-300"
                                    style="color:var(--text-muted);">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                        <div class="p-3 flex-shrink-0" style="border-bottom:1px solid var(--border,#e5e7eb);">
                            <input type="text" x-model="agentSearch" placeholder="Search agents..."
                                   class="w-full px-3 py-1.5 text-xs rounded-md outline-none"
                                   style="border:1px solid var(--border,#e5e7eb);background:var(--surface-2,#f9fafb);color:var(--text-primary);">
                        </div>
                        <div class="flex-1" style="overflow-y:auto;">
                            <button type="button" @click="pickAgent(null)"
                               class="w-full flex items-center gap-2 px-4 py-2.5 text-xs font-semibold text-left"
                               style="color:var(--text-secondary);border-bottom:1px solid var(--border,#e5e7eb);">
                                All agents
                            </button>
                            <template x-for="agent in filtered" :key="agent.id">
                                <button type="button" @click="pickAgent(agent.id)"
                                   class="w-full flex items-center gap-2.5 px-4 py-2.5 text-xs text-left"
                                   style="border-bottom:1px solid var(--border,rgba(0,0,0,.04));">
                                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-md text-xs font-bold flex-shrink-0"
                                          style="background:var(--brand-default,#0b2a4a);color:#fff;"
                                          x-text="agent.name.charAt(0).toUpperCase()"></span>
                                    <div class="min-w-0">
                                        <div class="font-semibold truncate" style="color:var(--text-primary);" x-text="agent.name"></div>
                                        <div class="truncate" style="color:var(--text-muted);" x-text="agent.email"></div>
                                    </div>
                                </button>
                            </template>
                            <div x-show="filtered.length === 0" class="px-4 py-4 text-xs text-center" style="color:var(--text-muted);">
                                No agents found
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <button type="submit" class="corex-btn-outline text-sm">Search</button>
            @if($search || $filterAgentId !== '')
                <a href="{{ route('deals-dr2.unfiled-emails.index', ['scope' => $scope, 'state' => $state]) }}" class="text-sm" style="color: var(--text-muted,#6b7280);">Clear</a>
            @endif
        </form>
    </div>

    <p x-show="err" x-cloak x-text="err" style="color:#b91c1c;font-size:.85rem;"></p>

    {{-- CX-113 Phase G — see the dr2-email-body comment below for why. Scoped to this
         class only, so Comms Suspense/Archive's own use of the same shared partial is
         completely unaffected. --}}
    <style>
        .dr2-email-body .cx-msg { justify-content: flex-start !important; }
        .dr2-email-body .cx-bubble { max-width: 100% !important; }
    </style>

    {{-- List — CX-113 Phase F (Johan, 2026-08-21, after actually looking at the deployed
         page): "stop using a table. Make each email a CARD, matching the deeds screen's
         card treatment." A table forces fixed columns, which is exactly what caused the
         Preview column to run off-screen at 1440px and trapped the full-width deal
         search inside a two-column strip. A card has none of that — no fixed columns to
         overflow, text wraps/truncates naturally instead of forcing nowrap cells. Same
         `space-y-3` list rhythm and `rounded-md` / `var(--surface)` / `var(--border)`
         card treatment as Deeds Capture (resources/views/corex/deeds-capture/index.blade.php)
         — no invented classes, every token here is real in corex.css. --}}
    @if($emails->isEmpty())
        <div class="rounded-md p-8" style="text-align:center;color:var(--text-muted,#9ca3af);background:var(--surface,#fff);border:1px solid var(--border,#e5e7eb);">
            @if($search)
                No {{ $state === 'filed' ? 'filed' : ($state === 'all' ? '' : ($state === 'removed' ? 'removed' : 'unfiled')) }} emails match "{{ $search }}".
            @elseif($state === 'filed')
                No emails filed to a deal yet in this scope.
            @elseif($state === 'all')
                Nothing in this scope yet — filed or unfiled.
            @elseif($state === 'removed')
                Nothing removed in this scope.
            @else
                Nothing unfiled in this scope — every deal-connected email you can see is already filed.
            @endif
        </div>
    @else
        <div class="space-y-3">
            @foreach($emails as $email)
                @php($filedInfo = $filedInfoByCommId[$email->id] ?? null)
                @php($dismissedInfo = $dismissedInfoByCommId[$email->id] ?? null)
                @php($previewText = (string) ($email->body_display ?: ($email->body_text ?: $email->body_preview)))
                @php($autoMatches = $autoMatchByCommId[$email->id] ?? [])
                @php($topMatch = $autoMatches[0] ?? null)
                @php($altMatches = array_slice($autoMatches, 1))
                {{-- CX-113 Phase I — two-column card (Comms Suspense's own layout, salvaged
                     per Johan: "I like the look a lot more"): content left, action panel
                     right. flex-wrap (not a hard breakpoint) so the panel drops below the
                     content instead of ever forcing horizontal overflow at 1024px. --}}
                <div id="unfiled-row-{{ $email->id }}" class="rounded-md p-4" style="background:var(--surface,#fff);border:1px solid var(--border,#e5e7eb);">
                <div style="display:flex;gap:1.25rem;flex-wrap:wrap;align-items:flex-start;">
                <div style="flex:2 1 20rem;min-width:0;">
                    <div style="cursor:pointer;" @click="toggleExpand({{ $email->id }})">
                        {{-- Line 1 — SUBJECT, the thing an agent actually scans for. Real chevron
                             (same SVG path already used by the agent-picker dropdown elsewhere on
                             this screen — one icon language, not a raw ▶ character), rotates open
                             via Alpine, no separate icon set introduced. text-transform:capitalize
                             tames source subjects that arrive in shouting ALL CAPS — a pure paint-
                             time style, the underlying text (and every test that asserts on it) is
                             completely unchanged. truncate = single line + ellipsis; full text is
                             always the title attribute AND fully visible the moment the card
                             expands, so nothing is ever lost, only deferred. --}}
                        <div class="flex items-start gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="flex-shrink-0" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                                 style="margin-top:.2rem;color:var(--text-muted,#9ca3af);transition:transform .15s;"
                                 :style="'margin-top:.2rem;color:var(--text-muted,#9ca3af);transition:transform .15s;transform:rotate(' + (expandedId === {{ $email->id }} ? 0 : -90) + 'deg);'">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                            <div class="text-sm font-semibold truncate" style="min-width:0;flex:1;color:var(--text-primary);text-transform:capitalize;" title="{{ $email->subject ?: '(no subject)' }}">
                                {{ $email->subject ?: '(no subject)' }}
                            </div>
                            {{-- CX-113 Phase I — attachment indicator (Comms Suspense shows filenames
                                 inline; here just an existence clip — has_attachments is already a
                                 column, no join needed). Real filenames still show on expand. --}}
                            @if($email->has_attachments)
                                <span class="flex-shrink-0" title="Has attachments" style="font-size:.8rem;">📎</span>
                            @endif
                        </div>
                        {{-- CX-113 Phase I — status chip + relative age (Comms Suspense: "Needs a
                             deal" / "1 day ago"), driven by OUR confidence threshold rather than the
                             old ladder. Filed/Not-filed pill (state!=='unfiled') is unchanged. --}}
                        <div class="flex items-center gap-2 flex-wrap" style="margin:.3rem 0 0 1.35rem;">
                            @if($state !== 'unfiled' && $filedInfo)
                                <span style="font-size:.68rem;padding:.1rem .5rem;border-radius:999px;font-weight:600;white-space:nowrap;background:color-mix(in srgb, var(--ds-green,#059669) 15%, transparent);color:var(--ds-green,#059669);">Filed</span>
                            @elseif($state !== 'unfiled')
                                <span style="font-size:.68rem;padding:.1rem .5rem;border-radius:999px;font-weight:600;white-space:nowrap;background:var(--surface-2,#f3f4f6);color:var(--text-muted,#9ca3af);">Not filed</span>
                            @elseif($state === 'unfiled' && $topMatch)
                                <span style="font-size:.68rem;padding:.1rem .5rem;border-radius:999px;font-weight:600;white-space:nowrap;background:color-mix(in srgb, var(--ds-green,#059669) 15%, transparent);color:var(--ds-green,#059669);">Match found</span>
                            @elseif($state === 'unfiled')
                                <span style="font-size:.68rem;padding:.1rem .5rem;border-radius:999px;font-weight:600;white-space:nowrap;background:color-mix(in srgb, var(--ds-amber,#f59e0b) 15%, transparent);color:var(--ds-amber,#f59e0b);">Needs a deal</span>
                            @endif
                            @if($email->occurred_at)
                                <span class="text-xs" style="color:var(--text-muted,#9ca3af);">{{ $email->occurred_at->diffForHumans() }}</span>
                            @endif
                            {{-- CX-113 Phase G — "getting an email that should not be in here so how
                                 do i remove it?" Reversible: takes the row out of DR2's queue only,
                                 never touches the Communication or its contact link. Moved into the
                                 action panel's Reject button (Phase I) — the reason-picker now mounts
                                 there, next to its trigger. --}}
                        </div>
                        {{-- Line 2 — sender · date, small and muted; secondary information recedes. --}}
                        <div class="text-xs truncate" style="margin:.25rem 0 0 1.35rem;color:var(--text-muted,#9ca3af);">
                            {{ $email->from_identifier ?: '(unknown)' }} · {{ optional($email->occurred_at)->format('j M Y H:i') }}
                        </div>
                        {{-- Preview — one muted truncated line. The expand control already shows
                             the full body, so this earns its place only as a quick scan aid, never
                             competing with the subject for attention. --}}
                        @if($previewText !== '')
                            <div class="text-xs truncate" style="margin:.2rem 0 0 1.35rem;color:var(--text-muted,#9ca3af);">
                                {{ \Illuminate\Support\Str::limit($previewText, 140) }}
                            </div>
                        @endif
                        @if($state !== 'unfiled' && $filedInfo)
                            <div class="text-xs" style="margin:.35rem 0 0 1.35rem;color:var(--text-muted,#9ca3af);">
                                @if($filedInfo['deal_id'])
                                    Filed to <a href="{{ route('deals-dr2.pipeline.list', $filedInfo['deal_id']) }}" @click.stop style="color:var(--brand-icon,#0ea5e9);font-weight:600;">{{ $filedInfo['deal_label'] }}</a>
                                @else
                                    Filed to {{ $filedInfo['deal_label'] }}
                                @endif
                                {{ $filedInfo['filed_by'] ? "by {$filedInfo['filed_by']}" : '' }}{{ $filedInfo['filed_at'] ? " · {$filedInfo['filed_at']}" : '' }}
                            </div>
                        @endif
                        @if($state === 'removed' && $dismissedInfo)
                            <div class="text-xs flex items-center gap-2 flex-wrap" style="margin:.35rem 0 0 1.35rem;color:var(--text-muted,#9ca3af);">
                                <span>Removed — {{ $dismissedInfo['reason'] }}{{ $dismissedInfo['dismissed_by'] ? " by {$dismissedInfo['dismissed_by']}" : '' }}{{ $dismissedInfo['dismissed_at'] ? " · {$dismissedInfo['dismissed_at']}" : '' }}</span>
                                <button type="button" class="text-xs" :disabled="removing" @click.stop="restoreEmail({{ $email->id }})"
                                        style="padding:.15rem .5rem;border-radius:999px;border:1px solid var(--brand-icon,#0ea5e9);color:var(--brand-icon,#0ea5e9);font-weight:600;">Restore</button>
                            </div>
                        @endif
                    </div>

                    {{-- CX-112 — read the email before filing/confirming. Reuses the SAME viewer
                         partial as the filed-emails-on-a-deal screen, fetched on demand — never
                         eager-loaded for the whole list. dr2-email-body (styled once, below,
                         scoped to this class) widens the reused chat-bubble markup to the full
                         card — that partial's max-width:82% + left/right chat alignment is
                         correct for a two-party thread view, not a single card, and left a large
                         empty gap on the right (Johan, 2026-08-22, looking at a real expanded
                         card). Presentation-only override from OUTSIDE the shared partial — that
                         file is reused by Comms Suspense/Archive too and stays untouched. --}}
                    <div x-show="expandedId === {{ $email->id }}" x-cloak class="mt-3 pt-3 dr2-email-body" style="border-top:1px solid var(--border,#e5e7eb);">
                        <div x-show="expanding" x-cloak class="text-xs" style="color:var(--text-muted,#9ca3af);">Loading…</div>
                        <div x-show="!expanding" x-html="expandedHtml"></div>
                    </div>
                </div>

                {{-- CX-113 Phase I — action panel, right column. Bottom split in two per
                     Johan's own words: "split the bottom in 2... to auto matched and
                     search." Hidden for a Removed row (Phase G) — an email flagged "not
                     deal correspondence" isn't offered a deal to file to; Restore it first
                     — and for an already-Filed row, which shows Move-only via the old
                     single search box instead (unchanged). --}}
                @if($state !== 'removed' && ! $filedInfo)
                    <div style="flex:1 1 19rem;min-width:0;" @click.stop>
                        {{-- AUTO MATCHED — Johan: "auto matched is based on our calcs if it
                             matches a current deal." Server-computed (UnfiledEmailsController::
                             autoMatchesFor(), same scoreDeal()/matchSignalsFor() dealSearch()
                             uses — corroboration included), so it renders instantly with the
                             page, no fetch. Each match is directly one-click-to-file, same as a
                             search result. Genuinely says so when nothing clears the confidence
                             bar — never a weak guess dressed as an answer. --}}
                        <div class="rounded-md p-3" style="background:var(--surface-2,#f9fafb);border:1px solid var(--border,#e5e7eb);">
                            <div style="font-size:.68rem;font-weight:700;color:var(--text-muted,#9ca3af);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.5rem;">Auto matched</div>
                            @if($topMatch)
                                @php($topFileable = $topMatch['fileable'] ?? true)
                                <div class="rounded-md p-2.5" style="background:var(--surface,#fff);border:1px solid var(--brand-icon,#0ea5e9);{{ $topFileable ? 'cursor:pointer;' : '' }}"
                                     @if($topFileable) @click="file({{ $email->id }}, {{ $topMatch['id'] }})" @endif>
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-sm truncate" style="font-weight:600;color:var(--text-primary);min-width:0;">{{ $topMatch['label'] }}</span>
                                        <span class="flex-shrink-0" style="font-size:.65rem;padding:.1rem .45rem;border-radius:999px;font-weight:600;white-space:nowrap;{{ $topMatch['status'] === 'Declined' ? 'background:color-mix(in srgb, var(--ds-crimson,#c41e3a) 15%, transparent);color:var(--ds-crimson,#c41e3a);' : ($topMatch['status'] === 'Pending' ? 'background:var(--surface-2,#f3f4f6);color:var(--text-muted,#9ca3af);' : 'background:color-mix(in srgb, var(--ds-green,#059669) 15%, transparent);color:var(--ds-green,#059669);') }}">{{ $topMatch['status'] }}</span>
                                    </div>
                                    {{-- Every contributing signal, not just the first — the >=90
                                         confidence bar can be cleared by ONE strong signal or by
                                         several weaker ones adding up (e.g. property+subject+a
                                         diluted party match together); showing only signals[0] would
                                         understate why a combination match is actually confident. --}}
                                    @foreach($topMatch['signals'] as $sig)
                                        <div class="text-xs" style="color:var(--text-secondary,var(--text-primary));margin-top:.3rem;line-height:1.4;">{{ $sig['label'] }}</div>
                                    @endforeach
                                    {{-- CX-113 Phase J (Johan, 2026-08-22, urgent) — this deal is a REAL
                                         match found via subject/property text, but has no DR2 twin yet
                                         (deal_v2_id null), so there is nothing to file to. Say so honestly
                                         instead of offering a click that would refuse with a 422. --}}
                                    @unless($topFileable)
                                        <div class="text-xs" style="margin-top:.4rem;color:var(--ds-amber,#f59e0b);font-weight:600;">Found, but not yet in the Deal Register — can't file until it's added.</div>
                                    @endunless
                                </div>
                                @if($topFileable)
                                    <button type="button" class="w-full text-xs font-semibold" style="margin-top:.5rem;padding:.5rem;border-radius:.375rem;background:var(--brand-default,#0b2a4a);color:#fff;"
                                            @click="file({{ $email->id }}, {{ $topMatch['id'] }})">Confirm &amp; file {{ $topMatch['label'] }}</button>
                                @else
                                    <button type="button" class="w-full text-xs font-semibold" disabled style="margin-top:.5rem;padding:.5rem;border-radius:.375rem;background:var(--surface-2,#f3f4f6);color:var(--text-muted,#9ca3af);opacity:.7;cursor:not-allowed;">Not yet in the Deal Register</button>
                                @endif
                                @if(count($altMatches))
                                    <div style="margin-top:.6rem;padding-top:.5rem;border-top:1px solid var(--border,#e5e7eb);">
                                        <div style="font-size:.65rem;color:var(--text-muted,#9ca3af);margin-bottom:.35rem;">Or:</div>
                                        <div class="space-y-1.5">
                                            @foreach($altMatches as $alt)
                                                @php($altFileable = $alt['fileable'] ?? true)
                                                <div class="rounded-md p-2" style="background:var(--surface,#fff);border:1px solid var(--border,#e5e7eb);{{ $altFileable ? 'cursor:pointer;' : 'opacity:.7;' }}"
                                                     @if($altFileable) @click="file({{ $email->id }}, {{ $alt['id'] }})" @endif>
                                                    <div class="text-xs truncate" style="font-weight:600;color:var(--text-primary);">{{ $alt['label'] }}</div>
                                                    @unless($altFileable)
                                                        <div class="text-xs" style="color:var(--ds-amber,#f59e0b);">Not yet in the Deal Register</div>
                                                    @endunless
                                                    @foreach($alt['signals'] as $sig)
                                                        <div class="text-xs truncate" style="color:var(--text-muted,#9ca3af);">{{ $sig['label'] }}</div>
                                                    @endforeach
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            @else
                                <div class="text-xs" style="color:var(--text-muted,#9ca3af);">No confident match for this email — search below.</div>
                            @endif
                        </div>

                        {{-- SEARCH — the manual route. My Deals on focus (Johan: "repurpose that
                             to show My deals for users... make filing easy"), widens to the full
                             ranked search the moment 2+ characters are typed. Same searchDeals()/
                             file()/openPicker() state and endpoint as before — presentation only. --}}
                        <div class="mt-3" style="position:relative;" @click.outside="filingId === {{ $email->id }} && closePicker()">
                            <div style="font-size:.68rem;font-weight:700;color:var(--text-muted,#9ca3af);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.5rem;">Search</div>
                            <input type="text" autocomplete="off" id="deal-search-{{ $email->id }}"
                                   :value="filingId === {{ $email->id }} ? dealQ : ''"
                                   @focus="openPicker({{ $email->id }})"
                                   @input="dealQ = $event.target.value; searchDeals()"
                                   @keydown.escape="closePicker()"
                                   placeholder="Address, seller, buyer, attorney…"
                                   class="corex-input text-sm" style="width:100%;">
                            <div x-show="filingId === {{ $email->id }} && dealQ.trim().length === 0 && dealResults.length" x-cloak
                                 class="text-xs" style="margin-top:.4rem;color:var(--text-muted,#9ca3af);">My deals</div>
                            <div x-show="filingId === {{ $email->id }} && dealSearching" x-cloak
                                 class="text-xs" style="margin-top:.4rem;color:var(--text-muted,#9ca3af);">
                                Searching…
                            </div>
                            {{-- CX-113 Phase E — rich result cards: property address, status,
                                 parties, and WHY each deal is a candidate for THIS email (signal
                                 badges, strongest-first — the backend already sorts by score,
                                 never re-sorted client-side). A My-Deals entry (id+label only, no
                                 signals/status/parties) renders the same template with those
                                 pieces simply absent — x-show guards each optional field. --}}
                            <div x-show="filingId === {{ $email->id }} && !dealSearching && dealResults.length" x-cloak
                                 class="space-y-2" style="margin-top:.5rem;max-height:20rem;overflow:auto;">
                                <template x-for="d in dealResults" :key="d.id">
                                    <div class="rounded-md p-3" style="border:1px solid var(--border,#e5e7eb);cursor:pointer;"
                                         :style="'border-radius:.375rem;padding:.65rem .8rem;border:1px solid var(--border,#e5e7eb);cursor:pointer;' + (filing ? 'pointer-events:none;opacity:.5;' : '')"
                                         @click="file({{ $email->id }}, d.id)">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="text-sm truncate" style="font-weight:600;color:var(--text-primary);min-width:0;" x-text="d.label"></span>
                                            <span x-show="d.status" class="flex-shrink-0" :style="'font-size:.68rem;padding:.1rem .5rem;border-radius:999px;font-weight:600;white-space:nowrap;' + dealStatusPillStyle(d.status)" x-text="d.status"></span>
                                        </div>
                                        <div x-show="d.seller_name || d.buyer_name || d.attorney_name" class="text-xs truncate" style="color:var(--text-muted,#9ca3af);margin-top:.15rem;"
                                             x-text="[d.seller_name ? ('Seller: ' + d.seller_name) : null, d.buyer_name ? ('Buyer: ' + d.buyer_name) : null, d.attorney_name ? ('Attorney: ' + d.attorney_name) : null].filter(Boolean).join('  ·  ')"></div>
                                        <div x-show="d.signals && d.signals.length" class="flex flex-wrap" style="gap:.3rem;margin-top:.4rem;">
                                            <template x-for="s in (d.signals || [])" :key="s.type + s.label">
                                                <span :style="'font-size:.68rem;padding:.15rem .5rem;border-radius:999px;font-weight:600;' + signalBadgeStyle(s.score)" x-text="s.label"></span>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        {{-- CX-113 Phase I — 3-button stack (Comms Suspense's own decision
                             hierarchy, salvaged): the likely answer, the manual route, the
                             rejection. "Search all deals…" focuses the search box above (already
                             inline — no modal, Johan's earlier explicit rule) rather than opening
                             one. Reject reuses the existing reason-picker, unchanged, just
                             relocated here from the old inline text-link trigger. --}}
                        <div class="mt-3 pt-3" style="border-top:1px solid var(--border,#e5e7eb);display:flex;flex-direction:column;gap:.5rem;">
                            @php($stackFileable = $topMatch && ($topMatch['fileable'] ?? true))
                            <button type="button" class="w-full text-xs font-semibold" :disabled="! {{ $stackFileable ? 'true' : 'false' }}"
                                    style="padding:.55rem;border-radius:.375rem;background:var(--brand-default,#0b2a4a);color:#fff;{{ $stackFileable ? '' : 'opacity:.4;cursor:not-allowed;' }}"
                                    @click="{{ $stackFileable ? 'file(' . $email->id . ', ' . $topMatch['id'] . ')' : '' }}">Confirm &amp; file</button>
                            <button type="button" class="w-full text-xs" style="padding:.5rem;border-radius:.375rem;background:var(--surface-2,#f3f4f6);color:var(--text-secondary,var(--text-primary));border:1px solid var(--border,#e5e7eb);"
                                    @click="openPicker({{ $email->id }}); $nextTick(() => document.getElementById('deal-search-{{ $email->id }}')?.focus())">Search all deals…</button>
                            <button type="button" class="w-full text-xs" style="padding:.5rem;border-radius:.375rem;background:transparent;color:var(--ds-crimson,#c41e3a);border:1px solid var(--ds-crimson,#c41e3a);"
                                    @click="removeId = (removeId === {{ $email->id }} ? null : {{ $email->id }})">Reject</button>
                            <div x-show="removeId === {{ $email->id }}" x-cloak
                                 class="flex flex-wrap items-center gap-1.5">
                                @foreach($dismissalReasons as $key => $label)
                                    @if($key !== 'other')
                                        <button type="button" class="text-xs" :disabled="removing" @click="dismissEmail({{ $email->id }}, '{{ $key }}')"
                                                style="padding:.2rem .55rem;border-radius:999px;border:1px solid var(--border,#e5e7eb);background:var(--surface-2,#f9fafb);color:var(--text-primary);">{{ $label }}</button>
                                    @endif
                                @endforeach
                                <input type="text" x-model="removeOther" placeholder="Other reason…"
                                       class="corex-input text-xs" style="width:9rem;padding:.2rem .5rem;">
                                <button type="button" class="text-xs" :disabled="removing || !removeOther.trim()" @click="dismissEmail({{ $email->id }}, 'other')"
                                        style="padding:.2rem .55rem;border-radius:999px;border:1px solid var(--border,#e5e7eb);background:var(--surface-2,#f9fafb);color:var(--text-primary);">Remove</button>
                            </div>
                        </div>
                    </div>
                @elseif($state !== 'removed' && $filedInfo)
                    {{-- Already filed — the auto-matched/3-button treatment doesn't fit an
                         item that's already resolved; the existing "move to another deal"
                         search box (unchanged logic, openPicker's move=true) is enough. --}}
                    <div style="flex:1 1 19rem;min-width:0;" @click.stop>
                        <div style="position:relative;" @click.outside="filingId === {{ $email->id }} && closePicker()">
                            <input type="text" autocomplete="off" id="deal-search-{{ $email->id }}"
                                   :value="filingId === {{ $email->id }} ? dealQ : ''"
                                   @focus="openPicker({{ $email->id }}, true)"
                                   @input="dealQ = $event.target.value; searchDeals()"
                                   @keydown.escape="closePicker()"
                                   placeholder="Move to another deal…"
                                   class="corex-input text-sm" style="width:100%;">
                            <div x-show="filingId === {{ $email->id }} && dealQ.trim().length === 0 && dealResults.length" x-cloak
                                 class="text-xs" style="margin-top:.4rem;color:var(--text-muted,#9ca3af);">My deals</div>
                            <div x-show="filingId === {{ $email->id }} && dealSearching" x-cloak
                                 class="text-xs" style="margin-top:.4rem;color:var(--text-muted,#9ca3af);">Searching…</div>
                            <div x-show="filingId === {{ $email->id }} && !dealSearching && dealResults.length" x-cloak
                                 class="space-y-2" style="margin-top:.5rem;max-height:20rem;overflow:auto;">
                                <template x-for="d in dealResults" :key="d.id">
                                    <div class="rounded-md p-3"
                                         :style="'border-radius:.375rem;padding:.65rem .8rem;border:1px solid var(--border,#e5e7eb);cursor:pointer;' + (filing ? 'pointer-events:none;opacity:.5;' : '')"
                                         @click="file({{ $email->id }}, d.id)">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="text-sm truncate" style="font-weight:600;color:var(--text-primary);min-width:0;" x-text="d.label"></span>
                                            <span x-show="d.status" class="flex-shrink-0" :style="'font-size:.68rem;padding:.1rem .5rem;border-radius:999px;font-weight:600;white-space:nowrap;' + dealStatusPillStyle(d.status)" x-text="d.status"></span>
                                        </div>
                                        <div x-show="d.seller_name || d.buyer_name || d.attorney_name" class="text-xs truncate" style="color:var(--text-muted,#9ca3af);margin-top:.15rem;"
                                             x-text="[d.seller_name ? ('Seller: ' + d.seller_name) : null, d.buyer_name ? ('Buyer: ' + d.buyer_name) : null, d.attorney_name ? ('Attorney: ' + d.attorney_name) : null].filter(Boolean).join('  ·  ')"></div>
                                        <div x-show="d.signals && d.signals.length" class="flex flex-wrap" style="gap:.3rem;margin-top:.4rem;">
                                            <template x-for="s in (d.signals || [])" :key="s.type + s.label">
                                                <span :style="'font-size:.68rem;padding:.15rem .5rem;border-radius:999px;font-weight:600;' + signalBadgeStyle(s.score)" x-text="s.label"></span>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                @endif
                </div>
                </div>
            @endforeach
        </div>
    @endif

    <div>{{ $emails->links() }}</div>

    {{-- Suggestion modal — Johan: surface, never auto-file. Backdrop + centering matches the
         established modal pattern used elsewhere (e.g. partials/whatsapp-send-confirm-modal.blade.php,
         dr2/partials/_grant-conflict-modal.blade.php): fixed inset:0 backdrop, flex-centered content
         box with its OWN explicit background/border — "corex-card" below was never a real CSS class
         (confirmed by grep against resources/css/corex.css — only .corex-kpi-card and .corex-panel
         consume the --corex-card-bg variable), so the box rendered with no background at all and
         text sat directly on the semi-transparent backdrop. --}}
    <div x-show="suggestModal" x-cloak style="position:fixed;inset:0;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;z-index:9999;padding:1rem;">
        <div style="max-width:32rem;width:92%;padding:1.25rem;background:var(--surface,#fff);border:1px solid var(--border,#e5e7eb);border-radius:.5rem;box-shadow:0 20px 40px rgba(0,0,0,.25);">
            <h3 style="margin:0 0 .4rem;font-size:1rem;font-weight:700;">Filed. Related emails found.</h3>
            <p style="margin:0 0 .8rem;font-size:.85rem;color:var(--text-muted,#6b7280);">
                These unfiled emails share a sender, thread, or subject with the one just filed to
                <strong x-text="suggestDeal && suggestDeal.label"></strong> — they may belong to the same deal. Confirm the ones that do.
            </p>
            <div style="max-height:16rem;overflow:auto;display:flex;flex-direction:column;gap:.4rem;margin-bottom:1rem;">
                <template x-for="s in suggestions" :key="s.id">
                    <label style="display:flex;align-items:flex-start;gap:.5rem;font-size:.82rem;padding:.4rem;border:1px solid var(--border,rgba(0,0,0,.06));border-radius:6px;">
                        <input type="checkbox" :value="s.id" x-model="suggestSelected" style="margin-top:.2rem;">
                        <span>
                            <span style="font-weight:600;" x-text="s.subject || '(no subject)'"></span><br>
                            <span style="color:#9ca3af;" x-text="[s.from, s.when].filter(Boolean).join(' · ')"></span>
                        </span>
                    </label>
                </template>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:.5rem;">
                <button type="button" class="corex-btn-outline text-sm" :disabled="batchFiling" @click="suggestModal=false; location.reload();">Skip</button>
                <button type="button" class="corex-btn-primary text-sm" :disabled="batchFiling" @click="confirmSuggestions()">File selected too</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    // CX-113 Phase I — "My deals" for the search box's on-focus quick list. Defined
    // here, OUTSIDE the x-data attribute (a <script> block, unlike an HTML attribute,
    // has no quote-termination trap), then read via window.DR2_MY_DEALS inside it.
    window.DR2_MY_DEALS = @json($myDeals);
</script>
@endpush
@endsection
