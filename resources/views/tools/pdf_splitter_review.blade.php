{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $base       = $manifest['base'];
    $pCount     = (int)$manifest['pCount'];
    $labels     = $manifest['labels'];      // string-keyed
    $snippets   = $manifest['snippets'];
    $pageScores = $manifest['pageScores'];
    $docTypes   = $manifest['docTypes'];    // ['mandate' => 'Mandate', ...]

    // AT-105 small fix — the doc-type picker on THIS review screen lists
    // alphabetically by label (case-insensitive); the list outgrew sort_order
    // scanning. Scoped to the splitter review only — the admin settings screen
    // keeps its deliberate sort_order arrangement (separate query/view).
    uasort($docTypes, fn ($a, $b) => strcasecmp((string) $a, (string) $b));

    // Pre-build per-page seed data for the Alpine component (contacts resolve
    // client-side once a property is picked).
    $pageSeed = [];
    for ($p = 1; $p <= $pCount; $p++) {
        $sc   = $pageScores[(string)$p] ?? [];
        $nonZ = array_filter($sc, fn($v) => $v > 0);
        $pageSeed[] = [
            'page'    => $p,
            'label'   => $labels[(string)$p] ?? 'other',
            'snippet' => $snippets[(string)$p] ?? '',
            'scores'  => !empty($nonZ)
                ? implode(' ', array_map(fn($k,$v)=>"{$k}={$v}", array_keys($nonZ), $nonZ))
                : 'no hits',
            'contactIds' => [],
            'manual'     => false,
        ];
    }
@endphp
<style>
#spr *, #spr { box-sizing: border-box; }
#spr { color: var(--text-primary); font-size: 0.875rem; }
#spr .wrap { max-width: 1240px; margin: 0 auto; padding: 0 1.5rem; }
#spr .alert { padding:10px 14px; border-radius:6px; font-size:.85rem; margin-bottom:14px; }
#spr .alert-error {
    background: color-mix(in srgb, var(--ds-crimson, #ef4444) 12%, var(--surface));
    border:1px solid color-mix(in srgb, var(--ds-crimson, #ef4444) 25%, var(--border));
    color:var(--ds-crimson);
}
#spr .card { background: var(--surface); border: 1px solid var(--border); border-radius:6px; }
#spr .toolbar {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    background: var(--surface); border: 1px solid var(--border);
    border-left: 3px solid var(--brand-icon, #0ea5e9);
    border-radius: 6px; padding: 0.75rem 1rem; margin-bottom: 12px;
}
#spr .tb-label { font-size:.75rem; font-weight:600; color:var(--text-muted); white-space:nowrap; }
#spr select.tb-select {
    font-size:.82rem; padding:5px 8px; border:1px solid var(--border);
    border-radius:6px; background:var(--surface); color:var(--text-primary); cursor:pointer;
}
#spr button.tb-btn {
    font-size:.78rem; font-weight:600; padding:5px 12px;
    border-radius:6px; border:1px solid var(--border); cursor:pointer;
    background:var(--surface-2, var(--surface)); color:var(--text-secondary);
    transition: all 300ms; white-space:nowrap;
}
#spr button.tb-btn:hover { opacity:.85; }
#spr .tbl-wrap { background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden; margin-bottom:16px; }
#spr table { width:100%; border-collapse:collapse; }
#spr thead th {
    background:var(--surface-2, var(--surface)); color:var(--text-muted); font-size:.72rem;
    font-weight:600; letter-spacing:.05em; text-transform:uppercase;
    padding:9px 10px; text-align:left; white-space:nowrap; border-bottom: 1px solid var(--border);
}
#spr tbody tr { border-bottom:1px solid var(--border); }
#spr tbody tr:last-child { border-bottom:none; }
#spr td { padding:8px 10px; vertical-align:top; }
#spr .thumb-cell { width:230px; text-align:center; }
#spr .thumb-cell img {
    width:200px !important; max-width:200px !important; height:auto; border:1px solid var(--border);
    border-radius:6px; display:block; margin:0 auto; background:var(--surface-2, var(--surface));
}
#spr .thumb-cell .pg-num { font-weight:700; color:var(--brand-icon, #0ea5e9); font-size:.8rem; margin-top:2px; display:block; }
#spr select.lbl-select {
    font-size:.82rem; padding:5px 7px; border:1px solid var(--border);
    border-radius:6px; background:var(--surface); color:var(--text-primary); cursor:pointer;
    width:100%; min-width:150px;
}
#spr .snippet { font-size:.74rem; color:var(--text-secondary); max-width:280px;
    overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
#spr .snippet.empty { color:var(--text-muted); font-style:italic; }
#spr .score-tip { font-size:.68rem; color:var(--text-muted); }
#spr .role-group { margin-bottom:6px; }
#spr .role-title { font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--text-muted); margin-bottom:2px; }
#spr .chip {
    display:inline-flex; align-items:center; gap:5px; font-size:.76rem;
    padding:3px 8px; margin:2px 4px 2px 0; border-radius:6px;
    border:1px solid var(--border); background:var(--surface-2, var(--surface));
    color:var(--text-primary); cursor:pointer; user-select:none;
}
#spr .chip input { accent-color: var(--ds-green, #16a34a); width:14px; height:14px; cursor:pointer; }
#spr .chip.checked { border-color: var(--ds-green, #16a34a); background: color-mix(in srgb, var(--ds-green, #16a34a) 10%, var(--surface)); }
#spr .add-link { font-size:.72rem; color:var(--brand-icon, #0ea5e9); cursor:pointer; text-decoration:underline; }
#spr .mini { background:var(--surface-2, var(--surface)); border:1px solid var(--border); border-radius:6px; padding:8px; margin-top:4px; }
#spr .mini input { width:100%; padding:5px 7px; font-size:.78rem; border:1px solid var(--border); border-radius:5px; background:var(--surface); color:var(--text-primary); margin-bottom:4px; }
#spr .mini .res { padding:4px 6px; font-size:.78rem; cursor:pointer; border-radius:4px; }
#spr .mini .res:hover { background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 10%, var(--surface)); }
#spr .btn-gen { background:var(--brand-button, #0ea5e9); color:#fff; border:none; border-radius:6px;
    padding:0.625rem 1.5rem; font-size:.875rem; font-weight:600; cursor:pointer; transition: all 300ms; }
#spr .btn-gen:hover { filter: brightness(1.1); }
#spr .btn-gen.secondary { background:var(--surface); color:var(--text-secondary); border:1px solid var(--border); }
#spr .btn-gen:disabled { opacity:.5; cursor:not-allowed; }
#spr .btn-back { font-size:.85rem; color:var(--text-muted); text-decoration:none; padding:4px 0; }
#spr [x-cloak] { display:none !important; }
</style>

<div id="spr" x-data="splitterReview()" x-cloak>
<div class="wrap">

    {{-- Header --}}
    <div class="rounded-md px-6 py-5 mb-5" style="background: var(--brand-default, #0b2a4a);">
        <h1 class="text-xl font-bold text-white leading-tight">PDF Pack Splitter — Review &amp; Assign</h1>
        <p class="text-sm text-white/60">
            <strong>{{ $base }}</strong> · {{ $pCount }} pages · Set each page's document type, then tick the contact(s) it belongs to.
        </p>
    </div>

    @if($errors->any())
        <div class="alert alert-error">
            <ul style="margin:0;padding-left:18px;">
                @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- Property picker --}}
    <div class="card p-4 mb-4" data-tour="spr-property" style="border-left: 3px solid var(--brand-icon, #0ea5e9);">
        <div class="flex items-center justify-between mb-2">
            <label class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">
                Link split documents to a property
            </label>
            <button type="button" x-show="property" @click="clearProperty()" class="text-xs underline" style="color: var(--text-secondary);">Clear</button>
        </div>

        <div x-show="!property" class="relative">
            <input type="text" x-model="q" @input.debounce.250="searchProps()" @focus="searchProps()"
                   placeholder="Search property by address, suburb, ref…"
                   class="w-full px-3 py-2 rounded-md text-sm"
                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            <div x-show="propResults.length > 0" class="absolute left-0 right-0 top-full mt-1 rounded-md z-20 max-h-72 overflow-y-auto"
                 style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                <template x-for="r in propResults" :key="r.id">
                    <button type="button" @click="pickProp(r)" class="block w-full text-left px-3 py-2 text-sm" style="color: var(--text-primary);">
                        <div x-text="r.label"></div>
                        <div class="text-xs" style="color: var(--text-muted);" x-text="r.ref ? ('Ref: ' + r.ref) : ''"></div>
                    </button>
                </template>
            </div>
        </div>

        <div x-show="property" class="flex items-center justify-between gap-3 px-3 py-2 rounded-md"
             style="background: var(--surface-2); border: 1px solid var(--border);">
            <div class="text-sm" style="color: var(--text-primary);">
                <span x-text="property?.label"></span>
                <span class="text-xs ml-2" style="color: var(--text-muted);" x-text="property && property.ref ? ('Ref: ' + property.ref) : ''"></span>
            </div>
            <span class="text-xs" style="color: var(--text-muted);">
                <span x-show="loadingContacts">Loading contacts…</span>
                <span x-show="!loadingContacts" x-text="contacts.length + ' contact' + (contacts.length===1?'':'s') + ' linked'"></span>
            </span>
        </div>
        <p x-show="!property" class="text-xs mt-2" style="color: var(--text-muted);">
            Pick a property to enable per-page contact assignment and the “Link to CoreX” action. You can still “Download ZIP” without one.
        </p>
    </div>

    {{-- FICA toggle (compliance users only) --}}
    @if(!empty($canFica))
    <div class="card p-4 mb-4" data-tour="spr-fica" style="border-left: 3px solid #8b5cf6;" x-show="property">
        <label class="flex items-start gap-3" :class="ficaHasTargets ? 'cursor-pointer' : 'cursor-not-allowed opacity-70'">
            <input type="checkbox" :checked="ficaChecked" @change="ficaOverride = $event.target.checked" :disabled="!ficaHasTargets"
                   class="mt-0.5 rounded w-4 h-4" style="accent-color:#8b5cf6;">
            <span>
                <span class="text-sm font-semibold" style="color: var(--text-primary);">Start wet-ink FICA verification(s) from this pack</span>
                <span x-show="ficaHasTargets" class="block text-xs mt-1" style="color: var(--text-muted);">
                    One verification per distinct contact who has a FICA / ID / Proof-of-Residence page assigned
                    (<strong x-text="ficaTargetCount"></strong> will be started or reused). Each party FICAs individually.
                    The assigned pages auto-attach to the right slots; you finish the rest.
                </span>
                <span x-show="!ficaHasTargets" class="block text-xs mt-1" style="color: var(--text-muted);">
                    Assign a FICA / ID / Proof-of-Residence page to a contact to enable this.
                </span>
            </span>
        </label>
    </div>
    @endif

    {{-- Bulk doc-type tools --}}
    <div class="toolbar" data-tour="spr-doctype">
        <span class="tb-label">Bulk:</span>
        <select class="tb-select" x-model="bulkType">
            @foreach($docTypes as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
        <button type="button" class="tb-btn" @click="setAll(bulkType)">Set ALL pages →</button>
        <button type="button" class="tb-btn" @click="resetAuto()">Reset to auto-detected</button>
        <span class="tb-label" style="margin-left:auto;" x-show="property">
            Tip: the first page of each type pre-ticks its role contacts; later pages inherit your last choice.
        </span>
    </div>

    {{-- The form. Two distinct submit actions (formaction). --}}
    <form method="POST" action="{{ route('tools.pdf_splitter.confirm') }}" id="spr-form">
        @csrf
        <input type="hidden" name="property_id" :value="property ? property.id : ''">
        @if(!empty($canFica))
            <input type="hidden" name="trigger_fica" :value="ficaChecked ? '1' : '0'">
        @endif

        {{-- Deterministic submission: hidden inputs mirror Alpine state, so the
             posted labels/contacts never depend on a checkbox :checked quirk. --}}
        <template x-for="pg in pages" :key="pg.page">
            <div>
                <input type="hidden" :name="`labels[${pg.page}]`" :value="pg.label">
                <template x-for="cid in pg.contactIds" :key="cid">
                    <input type="hidden" :name="`contacts[${pg.page}][]`" :value="cid">
                </template>
            </div>
        </template>

        <div class="tbl-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:230px">Page</th>
                        <th style="width:170px">Document type</th>
                        <th data-tour="spr-assign">Assign to contact(s)</th>
                        <th style="width:230px">OCR snippet</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="pg in pages" :key="pg.page">
                        <tr>
                            {{-- Thumbnail --}}
                            <td class="thumb-cell">
                                <img :src="thumbTpl.replace('__PAGE__', pg.page)" :alt="`p${pg.page}`" loading="lazy">
                                <span class="pg-num" x-text="`Page ${pg.page}`"></span>
                            </td>

                            {{-- Doc type --}}
                            <td>
                                <select class="lbl-select" x-model="pg.label" @change="onLabelChange(pg)">
                                    @foreach($docTypes as $key => $dtLabel)
                                        <option value="{{ $key }}">{{ $dtLabel }}</option>
                                    @endforeach
                                </select>
                            </td>

                            {{-- Contact assignment (many-to-many across roles) --}}
                            <td>
                                <template x-if="!property">
                                    <span class="text-xs" style="color: var(--text-muted);">Pick a property above to assign contacts.</span>
                                </template>
                                <template x-if="property">
                                    <div>
                                        <template x-if="docRoles(pg.label).length === 0">
                                            <span class="text-xs" style="color: var(--text-muted);">This type isn’t routed to a contact (files to the property).</span>
                                        </template>

                                        <template x-for="role in docRoles(pg.label)" :key="role">
                                            <div class="role-group">
                                                <div class="role-title" x-text="roleLabels[role] || role"></div>
                                                <template x-for="c in roleCandidates(role)" :key="c.id">
                                                    <label class="chip" :class="isChecked(pg, c.id) ? 'checked' : ''">
                                                        <input type="checkbox" :checked="isChecked(pg, c.id)" @change="toggleContact(pg, c.id)">
                                                        <span x-text="c.name"></span>
                                                        <span x-show="c.fica_status !== 'complete'" title="Not FICA-verified" style="color: var(--ds-crimson, #ef4444);">•</span>
                                                    </label>
                                                </template>
                                                <span x-show="roleCandidates(role).length === 0" class="text-xs" style="color: var(--text-muted);">No <span x-text="(roleLabels[role]||role).toLowerCase()"></span> linked. </span>
                                                <span class="add-link" @click="openAdd(pg.page, role)">+ Add <span x-text="roleLabels[role] || role"></span></span>

                                                {{-- Inline add: search existing OR create new --}}
                                                <div class="mini" x-show="addKey === (pg.page + ':' + role)" @click.outside="closeAdd()">
                                                    <input type="text" x-model="addQ" @input.debounce.250="searchContacts()" placeholder="Search existing contact…">
                                                    <template x-for="r in addResults" :key="r.id">
                                                        <div class="res" @click="linkExisting(r, role)" x-text="r.name + (r.phone ? (' · ' + r.phone) : '')"></div>
                                                    </template>
                                                    <div class="text-xs mt-1 mb-1" style="color: var(--text-muted);">— or create new —</div>
                                                    <input type="text" x-model="newC.first_name" placeholder="First name">
                                                    <input type="text" x-model="newC.last_name" placeholder="Last name">
                                                    <input type="text" x-model="newC.phone" placeholder="Phone">
                                                    <input type="text" x-model="newC.email" placeholder="Email (optional)">
                                                    <button type="button" class="tb-btn" @click="createNew(role)" :disabled="addBusy">Create &amp; link</button>
                                                    <span x-show="addError" class="text-xs" style="color: var(--ds-crimson);" x-text="addError"></span>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </td>

                            {{-- Snippet + scores --}}
                            <td>
                                <div class="snippet" :class="pg.snippet ? '' : 'empty'" x-text="pg.snippet || '(no OCR text)'"></div>
                                <div class="score-tip" x-text="pg.scores"></div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="flex items-center gap-3 flex-wrap" style="margin-top:4px;">
            <button type="submit" class="btn-gen" formaction="{{ route('tools.pdf_splitter.link') }}" data-tour="spr-link"
                    :disabled="!property"
                    :title="property ? 'File each page to its destination(s) and assigned contact(s)' : 'Pick a property first'">
                &#x1F517;&nbsp; Link to CoreX
            </button>
            <button type="submit" class="btn-gen secondary" formaction="{{ route('tools.pdf_splitter.confirm') }}" data-tour="spr-zip"
                    title="Produce the split ZIP only — no filing, no FICA">
                &#x2913;&nbsp; Download ZIP
            </button>
            <a href="{{ route('tools.pdf_splitter.index') }}" class="btn-back">&larr; Upload a different PDF</a>
        </div>
    </form>
</div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('splitterReview', () => ({
        // ── config from the server ────────────────────────────────────────
        docTypes:   @json($docTypes),
        routing:    @json($routing),     // {slug:{label,contact_roles[],fica_slot}}
        roleSets:   @json($roleSets),    // {role:[pivotRole,...]}
        roleLabels: @json($roleLabels),
        searchUrl:       '{{ route('tools.pdf_splitter.properties.search') }}',
        contactsTpl:     '{{ route('tools.pdf_splitter.properties.contacts', ['property' => '__ID__']) }}',
        thumbTpl:        '{{ route('tools.pdf_splitter.thumb', ['page' => '__PAGE__']) }}',
        contactSearchTpl:'{{ route('corex.properties.contacts.search', ['property' => '__PID__']) }}',
        contactLinkTpl:  '{{ route('corex.properties.contacts.link', ['property' => '__PID__']) }}',
        contactCreateTpl:'{{ route('corex.properties.contacts.createAndLink', ['property' => '__PID__']) }}',
        csrf:            '{{ csrf_token() }}',

        // ── state ─────────────────────────────────────────────────────────
        pages:      @json($pageSeed),
        bulkType:   'other',
        q: '', propResults: [],
        property: null,
        contacts: [], contactsById: {}, loadingContacts: false,
        ficaOverride: null,
        // inline add-contact panel
        addKey: null, addRole: null, addPage: null, addQ: '', addResults: [], addBusy: false, addError: '',
        newC: { first_name:'', last_name:'', phone:'', email:'' },

        // ── property search ───────────────────────────────────────────────
        async searchProps() {
            const q = this.q.trim();
            if (q.length < 2) { this.propResults = []; return; }
            try {
                const res = await fetch(`${this.searchUrl}?q=${encodeURIComponent(q)}`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin',
                });
                this.propResults = res.ok ? await res.json() : [];
            } catch (e) { this.propResults = []; }
        },
        pickProp(r) { this.property = r; this.q = ''; this.propResults = []; this.loadContacts(r.id); },
        clearProperty() {
            this.property = null; this.contacts = []; this.contactsById = {};
            this.pages.forEach(p => { p.contactIds = []; p.manual = false; });
        },
        async loadContacts(id) {
            this.loadingContacts = true;
            try {
                const res = await fetch(this.contactsTpl.replace('__ID__', id), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin',
                });
                const j = res.ok ? await res.json() : { contacts: [] };
                this.contacts = j.contacts || [];
            } catch (e) { this.contacts = []; }
            this.loadingContacts = false;
            this.indexContacts();
            this.resolveAssignments();
        },
        indexContacts() { this.contactsById = {}; this.contacts.forEach(c => this.contactsById[c.id] = c); },

        // ── role → candidate resolution ───────────────────────────────────
        docRoles(slug) { return (this.routing[slug] && this.routing[slug].contact_roles) || []; },
        roleCandidates(role) {
            const set = (this.roleSets[role] || []).map(r => r.toLowerCase());
            return this.contacts.filter(c => set.includes((c.role || '').toLowerCase()));
        },
        allCandidateIds(slug) {
            const ids = [];
            this.docRoles(slug).forEach(role => this.roleCandidates(role).forEach(c => { if (!ids.includes(c.id)) ids.push(c.id); }));
            return ids;
        },

        // ── sticky auto-resolve (per doc-type, carries the whole SET) ──────
        resolveAssignments() {
            // Carry forward the PREVIOUS page's contact set (filtered to each
            // page's own valid candidates); a manual change updates the carry
            // going forward. Keyed by PAGE ORDER, not by doc-type.
            //
            // Why order, not doc-type: a real pack is laid out per PARTY —
            // seller FICA / seller ID / seller POR, then buyer FICA / buyer ID /
            // buyer POR. A per-doc-type sticky bled the seller's ID/POR choice
            // onto the buyer's same-type pages, silently reverting the buyer's
            // ID + POR to the seller (buyer ends up with only its FICA page;
            // the seller collects both parties' pages). Carrying the previous
            // page keeps each party's contiguous run on that party — the agent
            // only switches at the party boundary.
            let running = null;
            for (const pg of this.pages) {
                const cand = this.allCandidateIds(pg.label);
                if (pg.manual) { running = pg.contactIds.slice(); continue; }
                pg.contactIds = (running !== null)
                    ? running.filter(id => cand.includes(id))
                    : cand.slice();   // first page → full role-resolved set
                running = pg.contactIds.slice();
            }
        },
        isChecked(pg, cid) { return pg.contactIds.includes(cid); },
        toggleContact(pg, cid) {
            const i = pg.contactIds.indexOf(cid);
            if (i >= 0) pg.contactIds.splice(i, 1); else pg.contactIds.push(cid);
            pg.manual = true;
            this.resolveAssignments();
        },
        onLabelChange(pg) { pg.manual = false; this.resolveAssignments(); },
        setAll(slug) { this.pages.forEach(p => { p.label = slug; p.manual = false; }); this.resolveAssignments(); },
        resetAuto() { this.pages.forEach((p, i) => { p.label = (@json(array_values($pageSeed)))[i].label; p.manual = false; }); this.resolveAssignments(); },

        // ── FICA toggle (reactive) ────────────────────────────────────────
        ficaTargetIds() {
            const ids = new Set();
            for (const pg of this.pages) {
                const slot = this.routing[pg.label] && this.routing[pg.label].fica_slot;
                if (slot && slot !== 'none') pg.contactIds.forEach(id => ids.add(id));
            }
            return [...ids];
        },
        get ficaHasTargets() { return this.property && this.ficaTargetIds().length > 0; },
        get ficaTargetCount() { return this.ficaTargetIds().length; },
        get ficaNeedsVerify() {
            return this.ficaTargetIds().some(id => { const c = this.contactsById[id]; return c && c.fica_status !== 'complete'; });
        },
        get ficaChecked() {
            if (!this.ficaHasTargets) return false;
            return this.ficaOverride === null ? this.ficaNeedsVerify : this.ficaOverride;
        },

        // ── inline add contact (reuses the property-contact endpoints) ────
        pivotRoleFor(role) { return (this.roleSets[role] || ['seller'])[0]; },
        openAdd(page, role) {
            this.addKey = page + ':' + role; this.addPage = page; this.addRole = role;
            this.addQ = ''; this.addResults = []; this.addError = '';
            this.newC = { first_name:'', last_name:'', phone:'', email:'' };
        },
        closeAdd() { this.addKey = null; },
        async searchContacts() {
            const q = this.addQ.trim();
            if (q.length < 2 || !this.property) { this.addResults = []; return; }
            try {
                const url = this.contactSearchTpl.replace('__PID__', this.property.id) + `?q=${encodeURIComponent(q)}`;
                const res = await fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                const j = res.ok ? await res.json() : [];
                this.addResults = (Array.isArray(j) ? j : (j.contacts || j.results || [])).map(r => ({
                    id: r.id, name: r.name || r.full_name || ((r.first_name||'')+' '+(r.last_name||'')).trim() || '(no name)', phone: r.phone || '',
                }));
            } catch (e) { this.addResults = []; }
        },
        async linkExisting(r, role) {
            this.addBusy = true; this.addError = '';
            try {
                const res = await fetch(this.contactLinkTpl.replace('__PID__', this.property.id), {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type':'application/json', 'Accept':'application/json', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify({ contact_id: r.id, role: this.pivotRoleFor(role) }),
                });
                if (!res.ok) { const j = await res.json().catch(()=>({})); this.addError = j.message || 'Could not link contact.'; this.addBusy=false; return; }
                this.closeAdd();
                await this.loadContacts(this.property.id);
                this.tickNewContact(r.id, role);
            } catch (e) { this.addError = 'Network error.'; }
            this.addBusy = false;
        },
        async createNew(role) {
            if (!this.newC.first_name || !this.newC.last_name || !this.newC.phone) { this.addError = 'First name, last name and phone are required.'; return; }
            this.addBusy = true; this.addError = '';
            try {
                const res = await fetch(this.contactCreateTpl.replace('__PID__', this.property.id), {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type':'application/json', 'Accept':'application/json', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify({ ...this.newC, role: this.pivotRoleFor(role), bypass_duplicate_check: true }),
                });
                if (!res.ok) {
                    const j = await res.json().catch(()=>({}));
                    this.addError = j.message || 'Could not create contact — try “search existing” instead.';
                    this.addBusy = false; return;
                }
                const j = await res.json();
                const newId = j.contact && j.contact.id ? j.contact.id : null;
                this.closeAdd();
                await this.loadContacts(this.property.id);
                if (newId) this.tickNewContact(newId, role);
            } catch (e) { this.addError = 'Network error.'; }
            this.addBusy = false;
        },
        // Tick the just-added contact on the page that requested it (and make sticky).
        tickNewContact(cid, role) {
            const pg = this.pages.find(p => p.page === this.addPage);
            if (pg && this.contactsById[cid] && !pg.contactIds.includes(cid)) {
                pg.contactIds.push(cid); pg.manual = true; this.resolveAssignments();
            }
        },
    }));
});
</script>
@endsection
