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
        filingSuggestion: null, filingSuggestionLoading: false,
        openPicker(commId, move = false){
            if(this.filingId === commId) return; // already active on this row — don't wipe what's typed
            this.filingId = commId; this.moveMode = move; this.dealQ=''; this.dealResults=[]; this.err='';
            this.fetchFilingSuggestion(commId);
        },
        closePicker(){ this.filingId = null; this.moveMode = false; this.filingSuggestion = null; },
        // CX-113 Phase D (Johan, 2026-08-21) — auto-suggest the deal from filing
        // history; suggest, never auto-file. Fetched once, when the row's search
        // opens, before the agent has typed anything. Shown ABOVE the normal search
        // results; typing a query doesn't clear it, but the real search results (once
        // 2+ chars are typed) take visual priority — the suggestion is a starting
        // point, never forced. NOTE: this comment sits inside the double-quoted x-data
        // attribute — no literal double-quote character (the one this note is itself
        // avoiding) may EVER appear on these lines, even in a comment; the browser's
        // HTML parser terminates the attribute at the first one, regardless of JS
        // syntax (confirmed live — this exact mistake shipped once already).
        async fetchFilingSuggestion(commId){
            this.filingSuggestion = null; this.filingSuggestionLoading = true;
            try {
                const r = await fetch('{{ url('deals-dr2/unfiled-emails') }}/' + commId + '/suggest', {headers:{Accept:'application/json'}});
                const j = await r.json();
                this.filingSuggestion = (j && j.deal_id) ? j : null;
            } catch(e) { this.filingSuggestion = null; }
            this.filingSuggestionLoading = false;
        },
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
            if(this.dealQ.trim().length < 2){ this.dealResults = []; return; }
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
                <h1 class="text-xl font-bold text-white leading-tight">Unfiled Emails</h1>
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
                <div class="text-sm text-white/80">{{ $emails->total() }} {{ $state === 'filed' ? 'filed' : ($state === 'all' ? 'total' : 'unfiled') }}</div>
            </div>
        </div>

        {{-- CX-113 Phase B — filed-state filter. Unfiled (default) / Filed / All. Search
             spans whichever state is active. Same pill idiom as scope above, no ceiling
             gating (state is not a permission concept — every scope tier gets all three). --}}
        <div class="mt-3 inline-flex rounded-md overflow-hidden" style="border: 1px solid rgba(255,255,255,0.25);">
            @foreach(['unfiled' => 'Unfiled', 'filed' => 'Filed', 'all' => 'All'] as $key => $label)
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
                No {{ $state === 'filed' ? 'filed' : ($state === 'all' ? '' : 'unfiled') }} emails match "{{ $search }}".
            @elseif($state === 'filed')
                No emails filed to a deal yet in this scope.
            @elseif($state === 'all')
                Nothing in this scope yet — filed or unfiled.
            @else
                Nothing unfiled in this scope — every deal-connected email you can see is already filed.
            @endif
        </div>
    @else
        <div class="space-y-3">
            @foreach($emails as $email)
                @php($filedInfo = $filedInfoByCommId[$email->id] ?? null)
                @php($previewText = (string) ($email->body_display ?: ($email->body_text ?: $email->body_preview)))
                <div id="unfiled-row-{{ $email->id }}" class="rounded-md p-4" style="background:var(--surface,#fff);border:1px solid var(--border,#e5e7eb);">
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
                            @if($state !== 'unfiled')
                                <span class="flex-shrink-0" style="font-size:.68rem;padding:.1rem .5rem;border-radius:999px;font-weight:600;white-space:nowrap;{{ $filedInfo ? 'background:color-mix(in srgb, var(--ds-green,#059669) 15%, transparent);color:var(--ds-green,#059669);' : 'background:var(--surface-2,#f3f4f6);color:var(--text-muted,#9ca3af);' }}">{{ $filedInfo ? 'Filed' : 'Not filed' }}</span>
                            @endif
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
                    </div>

                    {{-- CX-112 — read the email before filing/confirming. Reuses the SAME viewer
                         partial as the filed-emails-on-a-deal screen, fetched on demand — never
                         eager-loaded for the whole list. --}}
                    <div x-show="expandedId === {{ $email->id }}" x-cloak class="mt-3 pt-3" style="border-top:1px solid var(--border,#e5e7eb);">
                        <div x-show="expanding" x-cloak class="text-xs" style="color:var(--text-muted,#9ca3af);">Loading…</div>
                        <div x-show="!expanding" x-html="expandedHtml"></div>
                    </div>

                    {{-- CX-113 Phase E/F — deal search, full width, at the bottom of the card as
                         its own visually distinct action zone (Johan: "bottom left... give it the
                         full row width so results can carry real detail" — a card gives that
                         naturally, no fixed columns to trap it inside). Same searchDeals()/file()/
                         openPicker() state as before — presentation-only change. --}}
                    <div class="mt-3 pt-3" style="border-top:1px solid var(--border,#e5e7eb);" @click.stop>
                        <div style="position:relative;" @click.outside="filingId === {{ $email->id }} && closePicker()">
                            <input type="text" autocomplete="off"
                                   :value="filingId === {{ $email->id }} ? dealQ : ''"
                                   @focus="openPicker({{ $email->id }}, {{ $filedInfo ? 'true' : 'false' }})"
                                   @input="dealQ = $event.target.value; searchDeals()"
                                   @keydown.escape="closePicker()"
                                   placeholder="{{ $filedInfo ? 'Move to another deal…' : 'Search deal — address, seller, buyer, attorney…' }}"
                                   class="corex-input text-sm" style="width:100%;max-width:38rem;">
                            {{-- CX-113 Phase D — filing-history suggestion, shown BEFORE the agent
                                 types anything; a real search takes over the moment they type. --}}
                            <div x-show="filingId === {{ $email->id }} && dealQ.trim().length === 0 && filingSuggestion" x-cloak
                                 style="position:relative;max-width:38rem;margin-top:.4rem;background:var(--surface-2,#f9fafb);border:1px solid var(--brand-icon,#0ea5e9);border-radius:.5rem;padding:.6rem .75rem;">
                                <div style="font-size:.68rem;font-weight:600;color:var(--brand-icon,#0ea5e9);text-transform:uppercase;letter-spacing:.03em;margin-bottom:.2rem;">Suggested</div>
                                <div class="text-sm" style="font-weight:600;cursor:pointer;color:var(--text-primary);"
                                     :class="filing ? 'pointer-events-none opacity-50' : ''"
                                     x-text="filingSuggestion && filingSuggestion.label"
                                     @click="file({{ $email->id }}, filingSuggestion.deal_id)"></div>
                                <div class="text-xs" style="color:var(--text-muted,#9ca3af);" x-text="filingSuggestion && filingSuggestion.reason"></div>
                            </div>
                            <div x-show="filingId === {{ $email->id }} && dealQ.trim().length === 0 && filingSuggestionLoading" x-cloak
                                 class="text-xs" style="max-width:38rem;margin-top:.4rem;color:var(--text-muted,#9ca3af);">
                                Checking filing history…
                            </div>
                            <div x-show="filingId === {{ $email->id }} && dealSearching" x-cloak
                                 class="text-xs" style="max-width:38rem;margin-top:.4rem;color:var(--text-muted,#9ca3af);">
                                Searching…
                            </div>
                            {{-- CX-113 Phase E — rich result cards: property address, status,
                                 parties, and WHY each deal is a candidate for THIS email (signal
                                 badges, strongest-first — the backend already sorts by score,
                                 never re-sorted client-side). An unbadged result is a plain text
                                 match — it naturally sorts last. Same card/border/spacing language
                                 as the outer list, not a different visual system. --}}
                            <div x-show="filingId === {{ $email->id }} && !dealSearching && dealResults.length" x-cloak
                                 class="space-y-2" style="max-width:38rem;margin-top:.5rem;max-height:24rem;overflow:auto;">
                                <template x-for="d in dealResults" :key="d.id">
                                    <div class="rounded-md p-3" style="border:1px solid var(--border,#e5e7eb);cursor:pointer;"
                                         :style="'border-radius:.375rem;padding:.65rem .8rem;border:1px solid var(--border,#e5e7eb);cursor:pointer;' + (filing ? 'pointer-events:none;opacity:.5;' : '')"
                                         @click="file({{ $email->id }}, d.id)">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="text-sm truncate" style="font-weight:600;color:var(--text-primary);min-width:0;" x-text="d.label"></span>
                                            <span class="flex-shrink-0" :style="'font-size:.68rem;padding:.1rem .5rem;border-radius:999px;font-weight:600;white-space:nowrap;' + dealStatusPillStyle(d.status)" x-text="d.status"></span>
                                        </div>
                                        <div class="text-xs truncate" style="color:var(--text-muted,#9ca3af);margin-top:.15rem;"
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
@endsection
