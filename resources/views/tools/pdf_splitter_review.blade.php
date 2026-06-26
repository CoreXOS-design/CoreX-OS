@extends('layouts.corex')

@section('corex-content')
@php
    $base       = $manifest['base'];
    $pCount     = (int)$manifest['pCount'];
    $labels     = $manifest['labels'];      // string-keyed
    $snippets   = $manifest['snippets'];
    $pageScores = $manifest['pageScores'];
    $docTypes   = $manifest['docTypes'];    // ['mandate' => 'Mandate', ...]

    // Keyboard shortcut map: first unique letter of each key, in order
    // Override manually to avoid collisions
    $keyMap = [
        'm' => 'mandate',
        'f' => 'fica',
        'i' => 'ids',
        'p' => 'por',
        'c' => 'condition_report',
        'l' => 'listing_form',
        'r' => 'rates_taxes',
        'b' => 'body_corporate',
        'h' => 'house_rules',
        'o' => 'offer_to_purchase',
        'd' => 'disclosure',
        'x' => 'other',
    ];

    // Badge colour map (tailwind-style token â†’ inline style)
    $badgeStyle = [
        'mandate'           => 'background:#dbeafe;color:#1e3a8a',
        'fica'              => 'background:#ede9fe;color:#4c1d95',
        'ids'               => 'background:#dcfce7;color:#14532d',
        'por'               => 'background:#fef9c3;color:#713f12',
        'condition_report'  => 'background:#fff7ed;color:#7c2d12',
        'listing_form'      => 'background:#f0fdf4;color:#065f46',
        'rates_taxes'       => 'background:#fef3c7;color:#78350f',
        'body_corporate'    => 'background:#f0f9ff;color:#0c4a6e',
        'house_rules'       => 'background:#fdf4ff;color:#701a75',
        'offer_to_purchase' => 'background:#fff1f2;color:#881337',
        'disclosure'        => 'background:#f8fafc;color:#1e293b',
        'other'             => 'background:#f1f5f9;color:#475569',
    ];
@endphp
<style>
#spr *, #spr { box-sizing: border-box; }

#spr {
    color: var(--text-primary);
    font-size: 0.875rem;
}

#spr .wrap { max-width: 1160px; margin: 0 auto; padding: 0 1.5rem; }

/* Alert */
#spr .alert { padding:10px 14px; border-radius:6px; font-size:.85rem; margin-bottom:14px; }
#spr .alert-error {
    background: color-mix(in srgb, #ef4444 12%, var(--surface));
    border:1px solid color-mix(in srgb, #ef4444 25%, var(--border));
    color:var(--ds-crimson);
}

/* â”€â”€ Toolbar â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
#spr .toolbar {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    background: var(--surface); border: 1px solid var(--border);
    border-left: 3px solid var(--brand-icon, #0ea5e9);
    border-radius: 6px; padding: 0.75rem 1rem; margin-bottom: 12px;
}
#spr .toolbar .sel-count {
    font-size:0.78rem; font-weight:700; color: var(--brand-icon, #0ea5e9);
    background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, var(--surface));
    padding:3px 9px; border-radius:6px;
    margin-right:4px; white-space:nowrap;
}
#spr .tb-label { font-size:.75rem; font-weight:600; color:var(--text-muted); white-space:nowrap; }
#spr .tb-sep   { width:1px; height:18px; background:var(--border); flex-shrink:0; }

#spr select.tb-select {
    font-size:.82rem; padding:5px 8px; border:1px solid var(--border);
    border-radius:6px; background:var(--surface); color:var(--text-primary); cursor:pointer;
}
#spr button.tb-btn {
    font-size:.78rem; font-weight:600; padding:5px 12px;
    border-radius:6px; border:1px solid transparent; cursor:pointer;
    transition: all 300ms; white-space:nowrap;
}
#spr button.tb-btn:hover { opacity:.85; }
#spr .btn-apply  { background:var(--brand-button, #0ea5e9); color:#fff; }
#spr .btn-reset  { background:var(--surface); color:var(--ds-crimson); border-color: color-mix(in srgb, #ef4444 40%, var(--border)); }
#spr .btn-other  { background:var(--surface-2, var(--surface)); color:var(--text-secondary); border-color:var(--border); }
#spr .btn-gen    { background:var(--brand-button, #0ea5e9); color:#fff; border:none; border-radius:6px;
                   padding:0.625rem 1.5rem; font-size:.875rem; font-weight:600; cursor:pointer;
                   transition: all 300ms;
                   box-shadow: 0 4px 6px -1px color-mix(in srgb, var(--brand-button, #0ea5e9) 20%, transparent); }
#spr .btn-gen:hover { filter: brightness(1.1);
                      box-shadow: 0 6px 10px -2px color-mix(in srgb, var(--brand-button, #0ea5e9) 30%, transparent); }

/* â”€â”€ Shortcut legend â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
#spr .legend {
    display:flex; flex-wrap:wrap; gap:5px;
    background:var(--surface); border:1px solid var(--border);
    border-left: 3px solid var(--brand-default, #0b2a4a);
    border-radius:6px; padding:0.75rem 1rem; margin-bottom:12px;
    align-items:center;
}
#spr .legend-title { font-size:.72rem; font-weight:700; color:var(--text-muted);
                     text-transform:uppercase; letter-spacing:.05em; margin-right:6px; }
#spr .key-chip {
    display:inline-flex; align-items:center; gap:4px;
    font-size:.75rem; padding:2px 7px; border-radius:4px;
    border:1px solid var(--border); background:var(--surface-2, var(--surface)); color:var(--text-primary);
    user-select:none; cursor:default;
}
#spr .key-chip kbd {
    font-family:'JetBrains Mono', monospace; font-weight:700; font-size:.78rem;
    background:var(--surface-2, var(--surface)); border-radius:6px; padding:1px 5px;
    border:1px solid var(--border);
}

/* â”€â”€ Table â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
#spr .tbl-wrap {
    background:var(--surface); border:1px solid var(--border); border-radius:6px;
    overflow:hidden; margin-bottom:16px;
}
#spr table { width:100%; border-collapse:collapse; }
#spr thead th {
    background:var(--surface-2, var(--surface)); color:var(--text-muted); font-size:.72rem;
    font-weight:600; letter-spacing:.05em; text-transform:uppercase;
    padding:9px 10px; text-align:left; white-space:nowrap;
    border-bottom: 1px solid var(--border);
}
#spr tbody tr {
    border-bottom:1px solid var(--border); cursor:pointer;
    transition: background 300ms;
}
#spr tbody tr:last-child { border-bottom:none; }
#spr tbody tr:hover { background:var(--surface-2, var(--surface)); }
#spr tbody tr.selected {
    background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 8%, var(--surface)) !important;
    outline:2px solid var(--brand-icon, #0ea5e9);
    outline-offset:-2px;
}

#spr td { padding:6px 10px; vertical-align:middle; }

/* Thumbnail */
#spr .thumb-cell { width:360px; text-align:center; }
#spr .thumb-cell img {
    max-width: none !important;
    width:256px !important; max-width:256px !important; height:auto; border:1px solid var(--border);
    border-radius:6px; display:block; margin:0 auto;
    background:var(--surface-2, var(--surface));
}
#spr .thumb-cell .pg-num {
    font-weight:700; color:var(--brand-icon, #0ea5e9); font-size:.8rem;
    margin-top:2px; display:block; text-align:center;
}

/* Auto badge */
#spr .badge {
    display:inline-block; font-size:.7rem; font-weight:700;
    padding:2px 7px; border-radius:6px; white-space:nowrap;
}

/* Scores tooltip trigger */
#spr .score-tip { font-size:.7rem; color:var(--text-muted); cursor:help;
                  white-space:nowrap; border-bottom:1px dotted var(--text-muted); }

/* Dropdown */
#spr select.lbl-select {
    font-size:.82rem; padding:5px 7px; border:1px solid var(--border);
    border-radius:6px; background:var(--surface); color:var(--text-primary); cursor:pointer;
    width:100%; min-width:148px; transition: border-color 300ms, box-shadow 300ms;
}
#spr select.lbl-select:focus {
    outline:none;
    border-color:var(--brand-button, #0ea5e9);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand-button, #0ea5e9) 15%, transparent);
}

/* Snippet */
#spr .snippet {
    font-size:.76rem; color:var(--text-secondary); max-width:360px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
#spr .snippet.empty { color:var(--text-muted); font-style:italic; }

/* Bottom bar */
#spr .bottom-bar {
    display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin-top:4px;
}
#spr .btn-back {
    font-size:.85rem; color:var(--text-muted); text-decoration:none; padding:4px 0;
    transition: color 300ms;
}
#spr .btn-back:hover { color:var(--brand-icon, #0ea5e9); }
</style>

<div id="spr">
<div class="wrap">

    {{-- Header bar --}}
    <div class="rounded-md px-6 py-5 mb-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">PDF Pack Splitter — Review Labels</h1>
                <p class="text-sm text-white/60">
                    <strong>{{ $base }}</strong> · {{ $pCount }} pages · Click rows to select · Use keyboard shortcuts to label
                </p>
            </div>
        </div>
    </div>

    @if($errors->any())
        <div class="alert alert-error">
            <ul style="margin:0;padding-left:18px;">
                @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- Property link (optional) --}}
    <div x-data="splitterPropertyPicker()" class="rounded-md p-4 mb-4"
         style="background: var(--surface); border: 1px solid var(--border); border-left: 3px solid var(--brand-icon, #0ea5e9);">
        <div class="flex items-center justify-between mb-2">
            <label class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">
                Link split documents to a property (optional)
            </label>
            <template x-if="selected">
                <button type="button" @click="clear()" class="text-xs underline" style="color: var(--text-secondary);">Clear</button>
            </template>
        </div>

        <template x-if="!selected">
            <div class="relative">
                <input type="text" x-model="q" @input.debounce.250="search()" @focus="search()"
                       placeholder="Search property by address, suburb, ref…"
                       class="w-full px-3 py-2 rounded-md text-sm"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <div x-show="results.length > 0" class="absolute left-0 right-0 top-full mt-1 rounded-md z-10 max-h-72 overflow-y-auto"
                     style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                    <template x-for="r in results" :key="r.id">
                        <button type="button" @click="pick(r)"
                                class="block w-full text-left px-3 py-2 text-sm hover:bg-white/5"
                                style="color: var(--text-primary);">
                            <div x-text="r.label"></div>
                            <div class="text-xs" style="color: var(--text-muted);" x-text="r.ref ? ('Ref: ' + r.ref) : ''"></div>
                        </button>
                    </template>
                </div>
                <div x-show="searching" class="absolute right-3 top-2.5 text-xs" style="color: var(--text-muted);">…</div>
            </div>
        </template>

        <template x-if="selected">
            <div class="flex items-center justify-between gap-3 px-3 py-2 rounded-md"
                 style="background: var(--surface-2); border: 1px solid var(--border);">
                <div class="text-sm" style="color: var(--text-primary);">
                    <span x-text="selected.label"></span>
                    <span class="text-xs ml-2" style="color: var(--text-muted);" x-text="selected.ref ? ('Ref: ' + selected.ref) : ''"></span>
                </div>
            </div>
        </template>

        <input type="hidden" name="property_id" :value="selected ? selected.id : ''" form="spr-form">
    </div>

    {{-- AT-105 — FICA auto-kickoff toggle (compliance users only).
         Keys off the SELLER CONTACT's own FICA state (returned by the property
         search), never the property's import-set compliance snapshot. When the
         seller is not yet FICA-verified the box defaults CHECKED so the common
         case fires on Generate; the agent can untick. Already-verified sellers
         default unchecked (tick to re-verify). --}}
    @if(!empty($canFica))
    <div x-data="splitterFicaToggle()" class="rounded-md p-4 mb-4"
         style="background: var(--surface); border: 1px solid var(--border); border-left: 3px solid #8b5cf6;">
        <label class="flex items-start gap-3" :class="canTrigger ? 'cursor-pointer' : 'cursor-not-allowed opacity-70'">
            <input type="checkbox" name="trigger_fica" value="1" form="spr-form"
                   :checked="checked" @change="override = $event.target.checked"
                   :disabled="!canTrigger"
                   class="mt-0.5 rounded w-4 h-4" style="accent-color:#8b5cf6;">
            <span>
                <span class="text-sm font-semibold" style="color: var(--text-primary);">Start a wet-ink FICA verification from this pack</span>

                {{-- No property picked yet --}}
                <span x-show="!hasProperty" class="block text-xs mt-1" style="color: var(--ds-crimson);">
                    Select a property above to enable — the FICA contact is the property's seller/owner.
                </span>

                {{-- Property picked but no resolvable seller --}}
                <span x-show="hasProperty && !hasSeller" class="block text-xs mt-1" style="color: var(--ds-crimson);">
                    This property has no clearly linked seller/owner contact — link a seller on the property, then start FICA from Compliance.
                </span>

                {{-- Seller present, FICA NOT verified → recommended --}}
                <span x-show="hasSeller && ficaIncomplete" class="block text-xs mt-1" style="color: var(--text-muted);">
                    Seller <strong x-text="sellerName"></strong> is <strong style="color: var(--ds-crimson);">not FICA-verified</strong>.
                    A wet-ink FICA will be started and the <strong>FICA Form</strong>, <strong>ID copy</strong> and
                    <strong>Proof of Residence</strong> pages from this pack auto-attached — you finish the rest.
                    <em>(The property may show as compliant from import; that does not verify the seller.)</em>
                </span>

                {{-- Seller present, FICA already verified → opt-in re-verify --}}
                <span x-show="hasSeller && !ficaIncomplete" class="block text-xs mt-1" style="color: var(--text-muted);">
                    Seller <strong x-text="sellerName"></strong>'s FICA is <strong style="color: var(--ds-green);">already verified</strong>.
                    Tick only to re-verify from this pack.
                </span>
            </span>
        </label>
    </div>
    @endif

    {{-- Keyboard legend --}}
    <div class="legend">
        <span class="legend-title">Shortcuts</span>
        @foreach($keyMap as $key => $type)
            @if(isset($docTypes[$type]))
                <span class="key-chip">
                    <kbd>{{ strtoupper($key) }}</kbd>
                    {{ $docTypes[$type] }}
                </span>
            @endif
        @endforeach
        <span class="key-chip" style="margin-left:8px;color:#94a3b8;">
            <kbd>&uarr;&darr;</kbd> navigate &nbsp; <kbd>Esc</kbd> deselect
        </span>
    </div>

    {{-- Toolbar --}}
    <div class="toolbar" id="spr-toolbar">
        <span class="sel-count" id="sel-count">0 selected</span>

        <span class="tb-sep"></span>
        <span class="tb-label">Set selected &rarr;</span>
        <select class="tb-select" id="tb-type-select">
            @foreach($docTypes as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
        <button type="button" class="tb-btn btn-apply" id="tb-apply">Apply</button>

        <span class="tb-sep"></span>
        <button type="button" class="tb-btn btn-reset" id="tb-reset">Reset selected</button>
        <button type="button" class="tb-btn btn-other" id="tb-all-other">Set ALL &rarr; Other</button>
    </div>

    <form method="POST" action="{{ route('tools.pdf_splitter.confirm') }}" id="spr-form">
        @csrf

        <div class="tbl-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:540px">Page</th>
                        <th style="width:100px">Auto</th>
                        <th style="width:165px">Label</th>
                        <th style="width:140px">Scores</th>
                        <th>OCR Snippet</th>
                    </tr>
                </thead>
                <tbody id="spr-tbody">
                @for($p = 1; $p <= $pCount; $p++)
                    @php
                        $auto  = $labels[(string)$p] ?? 'other';
                        $snip  = $snippets[(string)$p] ?? '';
                        $sc    = $pageScores[(string)$p] ?? [];
                        // Build non-zero score string for tooltip
                        $nonZ  = array_filter($sc, fn($v) => $v > 0);
                        $scStr = !empty($nonZ)
                            ? implode(' ', array_map(fn($k,$v)=>"{$k}={$v}", array_keys($nonZ), $nonZ))
                            : 'no hits';
                        $style = $badgeStyle[$auto] ?? $badgeStyle['other'];
                    @endphp
                    <tr data-page="{{ $p }}" data-auto="{{ $auto }}">
                        {{-- Page # + thumbnail --}}
                        <td class="thumb-cell">
                            <img src="{{ route('tools.pdf_splitter.thumb', $p) }}"
                                 alt="p{{ $p }}" loading="lazy">
                            <span class="pg-num">{{ $p }}</span>
                        </td>

                        {{-- Auto label badge --}}
                        <td>
                            <span class="badge" style="{{ $style }}">
                                {{ strtoupper(str_replace('_', ' ', $auto)) }}
                            </span>
                        </td>

                        {{-- Override dropdown --}}
                        <td>
                            <select name="labels[{{ $p }}]"
                                    class="lbl-select"
                                    data-auto="{{ $auto }}">
                                @foreach($docTypes as $key => $dtLabel)
                                    <option value="{{ $key }}" @selected($key === $auto)>
                                        {{ $dtLabel }}
                                    </option>
                                @endforeach
                            </select>
                        </td>

                        {{-- Scores --}}
                        <td>
                            <span class="score-tip" title="{{ $scStr }}">{{ $scStr }}</span>
                        </td>

                        {{-- Snippet --}}
                        <td>
                            @if($snip !== '')
                                <span class="snippet" title="{{ e($snip) }}">{{ $snip }}</span>
                            @else
                                <span class="snippet empty">(no OCR text)</span>
                            @endif
                        </td>
                    </tr>
                @endfor
                </tbody>
            </table>
        </div>

        <div class="bottom-bar" x-data>
            <button type="submit" class="btn-gen">
                &#x2913;&nbsp; <span x-text="$store.splitterPicker.selected ? 'ZIP &amp; Link' : 'ZIP'">ZIP</span>
            </button>
            <a href="{{ route('tools.pdf_splitter.index') }}" class="btn-back">&larr; Upload a different PDF</a>
        </div>
    </form>

</div>
</div>

<script>
(function () {
    'use strict';

    /* â”€â”€ Config â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    const TOTAL   = {{ $pCount }};
    const KEY_MAP = @json($keyMap);   // { 'm': 'mandate', ... }

    /* â”€â”€ State â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    let selected     = new Set();   // page numbers (int)
    let lastSelected = null;

    /* â”€â”€ DOM helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    const tbody    = document.getElementById('spr-tbody');
    const countEl  = document.getElementById('sel-count');

    function row(p)    { return tbody.querySelector(`tr[data-page="${p}"]`); }
    function sel(p)    { return tbody.querySelector(`select[name="labels[${p}]"]`); }

    /* â”€â”€ Selection rendering â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    function renderSelection() {
        tbody.querySelectorAll('tr[data-page]').forEach(tr => {
            const p = +tr.dataset.page;
            tr.classList.toggle('selected', selected.has(p));
        });
        const n = selected.size;
        countEl.textContent = n === 0 ? '0 selected'
                            : n === 1 ? '1 page selected'
                            : `${n} pages selected`;
    }

    function selectOnly(p) {
        selected.clear();
        selected.add(p);
        lastSelected = p;
        renderSelection();
    }

    function selectRange(from, to) {
        const lo = Math.min(from, to);
        const hi = Math.max(from, to);
        selected.clear();
        for (let p = lo; p <= hi; p++) selected.add(p);
        renderSelection();
    }

    function scrollToRow(p) {
        const r = row(p);
        if (r) r.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    /* â”€â”€ Row click â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    tbody.addEventListener('click', function (e) {
        // Ignore clicks on the select dropdown itself
        if (e.target.tagName === 'SELECT') return;

        const tr = e.target.closest('tr[data-page]');
        if (!tr) return;
        const p = +tr.dataset.page;

        if (e.shiftKey && lastSelected !== null) {
            selectRange(lastSelected, p);
        } else {
            if (selected.size === 1 && selected.has(p)) {
                selected.clear();
                lastSelected = null;
                renderSelection();
            } else {
                selectOnly(p);
            }
        }
    });

    /* â”€â”€ Apply label to selected rows â”€â”€â”€â”€â”€â”€â”€â”€ */
    function applyLabel(label, advance) {
        if (selected.size === 0) return;
        selected.forEach(p => {
            const s = sel(p);
            if (s) s.value = label;
        });

        if (advance) {
            // Move selection to the page immediately after the last selected
            const maxP = Math.max(...selected);
            const next = Math.min(maxP + 1, TOTAL);
            selectOnly(next);
            scrollToRow(next);
        }
    }

    /* â”€â”€ Keyboard shortcuts â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    document.addEventListener('keydown', function (e) {
        const tag = e.target.tagName;
        // Allow typing in selects/inputs/buttons normally â€” only intercept when body / table is focused
        if (tag === 'INPUT' || tag === 'TEXTAREA') return;
        if (tag === 'BUTTON') return;
        // Allow select dropdown navigation without stealing keys
        if (tag === 'SELECT') return;

        const key = e.key.toLowerCase();

        if (KEY_MAP[key]) {
            e.preventDefault();
            if (selected.size > 0) applyLabel(KEY_MAP[key], true);
            return;
        }

        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (selected.size === 0) {
                selectOnly(1);
                scrollToRow(1);
                return;
            }
            const ref  = e.key === 'ArrowDown' ? Math.max(...selected) : Math.min(...selected);
            const next = e.key === 'ArrowDown'
                ? Math.min(ref + 1, TOTAL)
                : Math.max(ref - 1, 1);
            selectOnly(next);
            scrollToRow(next);
            return;
        }

        if (e.key === 'Escape') {
            selected.clear();
            lastSelected = null;
            renderSelection();
        }
    });

    /* â”€â”€ Toolbar buttons â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    document.getElementById('tb-apply').addEventListener('click', function () {
        const v = document.getElementById('tb-type-select').value;
        applyLabel(v, false);
    });

    document.getElementById('tb-reset').addEventListener('click', function () {
        selected.forEach(p => {
            const s = sel(p);
            if (s) s.value = s.dataset.auto;
        });
    });

    document.getElementById('tb-all-other').addEventListener('click', function () {
        for (let p = 1; p <= TOTAL; p++) {
            const s = sel(p);
            if (s) s.value = 'other';
        }
    });

    /* â”€â”€ Init â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    // Pre-select page 1 so keyboard shortcuts work immediately
    selectOnly(1);
})();
</script>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.store('splitterPicker', { selected: null });

    Alpine.data('splitterPropertyPicker', () => ({
        q: '',
        results: [],
        searching: false,
        get selected() { return Alpine.store('splitterPicker').selected; },
        set selected(v) { Alpine.store('splitterPicker').selected = v; },
        async search() {
            const q = this.q.trim();
            if (q.length < 2) { this.results = []; return; }
            this.searching = true;
            try {
                const res = await fetch(`{{ route('tools.pdf_splitter.properties.search') }}?q=${encodeURIComponent(q)}`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                this.results = res.ok ? await res.json() : [];
            } catch (e) { this.results = []; }
            finally { this.searching = false; }
        },
        pick(r) { this.selected = r; this.q = ''; this.results = []; },
        clear() { this.selected = null; },
    }));

    // AT-105 — FICA toggle driven by the SELLER CONTACT's FICA state (carried
    // on the picked property from the search endpoint), NOT the property's
    // import-set compliance snapshot. Default-checked when the seller is not
    // verified; user tick/untick overrides. FICA-Form-page presence is enforced
    // server-side with a clear note if missing.
    Alpine.data('splitterFicaToggle', () => ({
        override: null, // null = auto (follow seller FICA state); true/false = user choice
        get prop()           { return Alpine.store('splitterPicker').selected; },
        get hasProperty()    { return !!this.prop; },
        get hasSeller()      { return !!(this.prop && this.prop.seller); },
        get sellerName()     { return this.prop ? (this.prop.seller || '') : ''; },
        get canTrigger()     { return this.hasSeller; },
        // 'incomplete' or 'expiring' (or unknown) = not genuinely verified.
        get ficaIncomplete() { return this.hasSeller && this.prop.seller_fica !== 'complete'; },
        get checked() {
            if (!this.canTrigger) return false;
            return this.override === null ? this.ficaIncomplete : this.override;
        },
    }));
});
</script>
@endsection