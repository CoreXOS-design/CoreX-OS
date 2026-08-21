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
        // CX-113 Phase D (Johan, 2026-08-21) — "auto-suggest the deal from filing
        // history... suggest, never auto-file." Fetched once, when the row's search
        // opens, before the agent has typed anything. Shown ABOVE the normal search
        // results; typing a query doesn't clear it, but the real search results (once
        // 2+ chars are typed) take visual priority — the suggestion is a starting
        // point, never forced.
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
                const r = await fetch('{{ route('deals-dr2.unfiled-emails.deal-search') }}?q=' + encodeURIComponent(this.dealQ.trim()), {headers:{Accept:'application/json'}});
                this.dealResults = await r.json();
            } catch(e) { this.dealResults = []; }
            this.dealSearching = false;
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

    {{-- List — overflow-x:auto (CX-113 Phase B, not overflow:hidden as before): the new
         Status column on Filed/All makes the table wide enough to need horizontal scroll
         on narrow viewports; hidden would clip it off-screen with no way to reach it. --}}
    <div style="padding:0;overflow-x:auto;background:var(--surface,#fff);border:1px solid var(--border,#e5e7eb);border-radius:.5rem;">
        @if($emails->isEmpty())
            <div style="padding:2rem;text-align:center;color:var(--text-muted,#9ca3af);">
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
            <table class="w-full text-sm" style="border-collapse:collapse;">
                <thead>
                    <tr style="background: var(--surface-muted,#f9fafb); text-align:left;">
                        <th style="padding:.6rem .9rem;">Sender</th>
                        <th style="padding:.6rem .9rem;">Subject</th>
                        <th style="padding:.6rem .9rem;">Date</th>
                        <th style="padding:.6rem .9rem;">Preview</th>
                        @if($state !== 'unfiled')
                            <th style="padding:.6rem .9rem;">Status</th>
                        @endif
                        <th style="padding:.6rem .9rem;"></th>
                    </tr>
                </thead>
                <tbody>
                    @php($colCount = $state !== 'unfiled' ? 6 : 5)
                    @foreach($emails as $email)
                        @php($filedInfo = $filedInfoByCommId[$email->id] ?? null)
                        <tr id="unfiled-row-{{ $email->id }}" style="border-top:1px solid var(--border,rgba(0,0,0,.06));cursor:pointer;" @click="toggleExpand({{ $email->id }})">
                            <td style="padding:.6rem .9rem;white-space:nowrap;">{{ $email->from_identifier ?: '(unknown)' }}</td>
                            <td style="padding:.6rem .9rem;font-weight:600;">
                                <span style="display:inline-block;width:.9rem;color:var(--text-muted,#9ca3af);" x-text="expandedId === {{ $email->id }} ? '▾' : '▸'"></span>
                                {{ $email->subject ?: '(no subject)' }}
                            </td>
                            <td style="padding:.6rem .9rem;white-space:nowrap;color:var(--text-muted,#6b7280);">{{ optional($email->occurred_at)->format('j M Y H:i') }}</td>
                            <td style="padding:.6rem .9rem;color:var(--text-muted,#6b7280);max-width:22rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                {{ \Illuminate\Support\Str::limit((string) ($email->body_display ?: ($email->body_text ?: $email->body_preview)), 90) }}
                            </td>
                            @if($state !== 'unfiled')
                                <td style="padding:.6rem .9rem;white-space:nowrap;font-size:.78rem;color:var(--text-muted,#6b7280);">
                                    @if($filedInfo)
                                        @if($filedInfo['deal_id'])
                                            <a href="{{ route('deals-dr2.pipeline.list', $filedInfo['deal_id']) }}" @click.stop style="color:var(--brand-icon,#0ea5e9);text-decoration:underline;">{{ $filedInfo['deal_label'] }}</a>
                                        @else
                                            {{ $filedInfo['deal_label'] }}
                                        @endif
                                        <br>{{ $filedInfo['filed_by'] ? "by {$filedInfo['filed_by']}" : '' }}{{ $filedInfo['filed_at'] ? " · {$filedInfo['filed_at']}" : '' }}
                                    @else
                                        <span style="color:#9ca3af;">Not filed</span>
                                    @endif
                                </td>
                            @endif
                            {{-- CX-113 Phase C (Johan, 2026-08-21) — "deal search and select ON
                                 THE ROW itself... no modal for the filing action." Replaces the
                                 File/Move button + separate expand-row picker: the search box
                                 IS the row's filing control, always present, no extra click to
                                 reveal it. Same searchDeals()/file() as before, same shared
                                 dealQ/dealResults/filingId state (only one row is ever "active"
                                 at a time) — just anchored directly under THIS input instead of
                                 a whole-width row below. click.outside closes it without a
                                 separate Cancel button. --}}
                            <td style="padding:.6rem .9rem;text-align:right;white-space:nowrap;position:relative;" @click.stop>
                                <div style="position:relative;display:inline-block;width:15rem;text-align:left;"
                                     @click.outside="filingId === {{ $email->id }} && closePicker()">
                                    <input type="text" autocomplete="off"
                                           :value="filingId === {{ $email->id }} ? dealQ : ''"
                                           @focus="openPicker({{ $email->id }}, {{ $filedInfo ? 'true' : 'false' }})"
                                           @input="dealQ = $event.target.value; searchDeals()"
                                           @keydown.escape="closePicker()"
                                           placeholder="{{ $filedInfo ? 'Move to another deal…' : 'Search deal — address, seller, buyer, attorney…' }}"
                                           class="corex-input text-sm" style="width:100%;">
                                    {{-- CX-113 Phase D — filing-history suggestion. Shown BEFORE the
                                         agent types anything (dealQ empty); a real search takes
                                         priority the moment they start typing. Click = files it
                                         immediately, exactly like a normal search result — this is
                                         still an explicit agent action, never automatic. --}}
                                    <div x-show="filingId === {{ $email->id }} && dealQ.trim().length === 0 && filingSuggestion" x-cloak
                                         style="position:absolute;z-index:41;left:0;right:0;top:100%;background:var(--surface,#fff);border:1px solid var(--brand-icon,#0ea5e9);border-radius:.5rem;box-shadow:0 8px 24px rgba(0,0,0,.08);padding:.5rem .7rem;">
                                        <div style="font-size:.7rem;font-weight:600;color:var(--brand-icon,#0ea5e9);text-transform:uppercase;letter-spacing:.03em;margin-bottom:.2rem;">Suggested</div>
                                        <div style="font-size:.8rem;font-weight:600;cursor:pointer;"
                                             :class="filing ? 'pointer-events-none opacity-50' : ''"
                                             x-text="filingSuggestion && filingSuggestion.label"
                                             @click="file({{ $email->id }}, filingSuggestion.deal_id)"></div>
                                        <div style="font-size:.72rem;color:var(--text-muted,#9ca3af);" x-text="filingSuggestion && filingSuggestion.reason"></div>
                                    </div>
                                    <div x-show="filingId === {{ $email->id }} && dealQ.trim().length === 0 && filingSuggestionLoading" x-cloak
                                         style="position:absolute;z-index:40;left:0;right:0;top:100%;background:var(--surface,#fff);border:1px solid #e5e7eb;border-radius:.5rem;box-shadow:0 8px 24px rgba(0,0,0,.08);padding:.5rem .7rem;font-size:.75rem;color:var(--text-muted,#9ca3af);">
                                        Checking filing history…
                                    </div>
                                    <div x-show="filingId === {{ $email->id }} && dealSearching" x-cloak
                                         style="position:absolute;z-index:40;left:0;right:0;top:100%;background:var(--surface,#fff);border:1px solid #e5e7eb;border-radius:.5rem;box-shadow:0 8px 24px rgba(0,0,0,.08);padding:.5rem .7rem;font-size:.8rem;color:var(--text-muted,#9ca3af);">
                                        Searching…
                                    </div>
                                    <div x-show="filingId === {{ $email->id }} && !dealSearching && dealResults.length" x-cloak
                                         style="position:absolute;z-index:40;left:0;right:0;top:100%;background:var(--surface,#fff);border:1px solid #e5e7eb;border-radius:.5rem;box-shadow:0 8px 24px rgba(0,0,0,.08);max-height:14rem;overflow:auto;">
                                        <template x-for="d in dealResults" :key="d.id">
                                            <div style="padding:.5rem .7rem;border-bottom:1px solid #f3f4f6;font-size:.8rem;cursor:pointer;"
                                                 onmouseover="this.style.background='var(--surface-2,#f9fafb)'" onmouseout="this.style.background=''"
                                                 :class="filing ? 'pointer-events-none opacity-50' : ''"
                                                 x-text="d.label" @click="file({{ $email->id }}, d.id)"></div>
                                        </template>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        {{-- CX-112 — read the email before filing/confirming. Reuses the SAME
                             viewer partial as the filed-emails-on-a-deal screen (below/sibling
                             view), fetched on demand — never eager-loaded for the whole list. --}}
                        <tr x-show="expandedId === {{ $email->id }}" x-cloak>
                            <td colspan="{{ $colCount }}" style="padding:.75rem .9rem;background:var(--surface-muted,#f9fafb);">
                                <div x-show="expanding" x-cloak style="font-size:.8rem;color:var(--text-muted,#9ca3af);">Loading…</div>
                                <div x-show="!expanding" x-html="expandedHtml"></div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

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
