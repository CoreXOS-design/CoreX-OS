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

    // Badge colour map (tailwind-style token → inline style)
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
    --navy:   #0b2a4a;
    --ink:    #0f172a;
    --muted:  #64748b;
    --border: #e2e8f0;
    --sel:    #eff6ff;
    --sel-border: #3b82f6;

    color: var(--ink);
    font-size: 0.875rem;
}

#spr .wrap { max-width: 1160px; margin: 0 auto; }

/* Alert */
#spr .alert { padding:10px 14px; border-radius:7px; font-size:.85rem; margin-bottom:14px; }
#spr .alert-error { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }

/* ── Toolbar ─────────────────────────────── */
#spr .toolbar {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    background: #fff; border: 1px solid var(--border);
    border-left: 4px solid #00b4d8;
    border-radius: 12px; padding: 0.75rem 1rem; margin-bottom: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}
#spr .toolbar .sel-count {
    font-size:0.78rem; font-weight:700; color: var(--navy);
    background:#dbeafe; padding:3px 9px; border-radius:20px;
    margin-right:4px; white-space:nowrap;
}
#spr .tb-label { font-size:.75rem; font-weight:600; color:var(--muted); white-space:nowrap; }
#spr .tb-sep   { width:1px; height:18px; background:var(--border); flex-shrink:0; }

#spr select.tb-select {
    font-size:.82rem; padding:5px 8px; border:1px solid var(--border);
    border-radius:5px; background:#fff; color:var(--ink); cursor:pointer;
}
#spr button.tb-btn {
    font-size:.78rem; font-weight:600; padding:5px 12px;
    border-radius:5px; border:1px solid transparent; cursor:pointer;
    transition:opacity .12s; white-space:nowrap;
}
#spr button.tb-btn:hover { opacity:.8; }
#spr .btn-apply  { background:var(--navy); color:#fff; border-color:var(--navy); }
#spr .btn-reset  { background:#fff; color:#dc2626; border-color:#fca5a5; }
#spr .btn-other  { background:#f1f5f9; color:#475569; border-color:#cbd5e1; }
#spr .btn-gen    { background:var(--navy); color:#fff; border:none; border-radius:0.5rem;
                   padding:0.625rem 1.5rem; font-size:.875rem; font-weight:600; cursor:pointer; }
#spr .btn-gen:hover { background:#143d66; }

/* ── Shortcut legend ─────────────────────── */
#spr .legend {
    display:flex; flex-wrap:wrap; gap:5px;
    background:#fff; border:1px solid var(--border);
    border-left: 4px solid var(--navy);
    border-radius:12px; padding:0.75rem 1rem; margin-bottom:12px;
    align-items:center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}
#spr .legend-title { font-size:.72rem; font-weight:700; color:var(--muted);
                     text-transform:uppercase; letter-spacing:.05em; margin-right:6px; }
#spr .key-chip {
    display:inline-flex; align-items:center; gap:4px;
    font-size:.75rem; padding:2px 7px; border-radius:4px;
    border:1px solid var(--border); background:#f8fafc; color:var(--ink);
    user-select:none; cursor:default;
}
#spr .key-chip kbd {
    font-family:monospace; font-weight:700; font-size:.78rem;
    background:#e2e8f0; border-radius:3px; padding:1px 5px;
    border:1px solid #cbd5e1;
}

/* ── Table ───────────────────────────────── */
#spr .tbl-wrap {
    background:#fff; border:1px solid var(--border); border-radius:12px;
    box-shadow:0 1px 3px rgba(0,0,0,.06); overflow:hidden; margin-bottom:16px;
}
#spr table { width:100%; border-collapse:collapse; }
#spr thead th {
    background:#f8fafc; color:var(--muted); font-size:.72rem;
    font-weight:600; letter-spacing:.05em; text-transform:uppercase;
    padding:9px 10px; text-align:left; white-space:nowrap;
    border-bottom: 1px solid var(--border);
}
#spr tbody tr {
    border-bottom:1px solid var(--border); cursor:pointer;
    transition:background .08s;
}
#spr tbody tr:last-child { border-bottom:none; }
#spr tbody tr:nth-child(even) { background:#f8fafc; }
#spr tbody tr:hover { background:#f1f5f9; }
#spr tbody tr.selected { background:var(--sel) !important; outline:2px solid var(--sel-border); outline-offset:-2px; }

#spr td { padding:6px 10px; vertical-align:middle; }

/* Thumbnail */
#spr .thumb-cell { width:360px; text-align:center; }
#spr .thumb-cell img {
    max-width: none !important;
    width:256px !important; max-width:256px !important; height:auto; border:1px solid var(--border);
    border-radius:3px; display:block; margin:0 auto;
    background:#f8fafc;
}
#spr .thumb-cell .pg-num {
    font-weight:700; color:var(--navy); font-size:.8rem;
    margin-top:2px; display:block; text-align:center;
}

/* Auto badge */
#spr .badge {
    display:inline-block; font-size:.7rem; font-weight:700;
    padding:2px 7px; border-radius:4px; white-space:nowrap;
}

/* Scores tooltip trigger */
#spr .score-tip { font-size:.7rem; color:var(--muted); cursor:help;
                  white-space:nowrap; border-bottom:1px dotted #94a3b8; }

/* Dropdown */
#spr select.lbl-select {
    font-size:.82rem; padding:5px 7px; border:1px solid var(--border);
    border-radius:5px; background:#f8fafc; color:var(--ink); cursor:pointer;
    width:100%; min-width:148px;
}
#spr select.lbl-select:focus { outline:none; border-color:var(--navy); box-shadow: 0 0 0 3px rgba(0, 180, 216, 0.1); }

/* Snippet */
#spr .snippet {
    font-size:.76rem; color:#334155; max-width:360px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
#spr .snippet.empty { color:var(--muted); font-style:italic; }

/* Bottom bar */
#spr .bottom-bar {
    display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin-top:4px;
}
#spr .btn-back {
    font-size:.85rem; color:var(--muted); text-decoration:none; padding:4px 0;
}
#spr .btn-back:hover { color:var(--navy); }
</style>

<div id="spr">
<div class="wrap">

    {{-- Navy header bar --}}
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 mb-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
            <div>
                <h2 class="text-xl font-bold text-white leading-tight">PDF Pack Splitter &mdash; Review Labels</h2>
                <div class="text-sm text-white/60">
                    <strong>{{ $base }}</strong> &middot; {{ $pCount }} pages &middot; Click rows to select &middot; Use keyboard shortcuts to label
                </div>
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

        <div class="bottom-bar">
            <button type="submit" class="btn-gen">&#x2913;&nbsp; Generate ZIP</button>
            <a href="{{ route('tools.pdf_splitter.index') }}" class="btn-back">&larr; Upload a different PDF</a>
        </div>
    </form>

</div>
</div>

<script>
(function () {
    'use strict';

    /* ── Config ─────────────────────────────── */
    const TOTAL   = {{ $pCount }};
    const KEY_MAP = @json($keyMap);   // { 'm': 'mandate', ... }

    /* ── State ──────────────────────────────── */
    let selected     = new Set();   // page numbers (int)
    let lastSelected = null;

    /* ── DOM helpers ────────────────────────── */
    const tbody    = document.getElementById('spr-tbody');
    const countEl  = document.getElementById('sel-count');

    function row(p)    { return tbody.querySelector(`tr[data-page="${p}"]`); }
    function sel(p)    { return tbody.querySelector(`select[name="labels[${p}]"]`); }

    /* ── Selection rendering ────────────────── */
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

    /* ── Row click ──────────────────────────── */
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

    /* ── Apply label to selected rows ──────── */
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

    /* ── Keyboard shortcuts ─────────────────── */
    document.addEventListener('keydown', function (e) {
        const tag = e.target.tagName;
        // Allow typing in selects/inputs/buttons normally — only intercept when body / table is focused
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

    /* ── Toolbar buttons ────────────────────── */
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

    /* ── Init ───────────────────────────────── */
    // Pre-select page 1 so keyboard shortcuts work immediately
    selectOnly(1);
})();
</script>
@endsection