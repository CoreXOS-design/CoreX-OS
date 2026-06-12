@extends('layouts.corex')

@push('head')
{{-- Zero out <main> padding so sticky bar pins flush with no gap --}}
<style>#appScroll { padding: 0 !important; }</style>
@endpush

@section('corex-content')

{{-- Sticky action bar — <main> padding zeroed, pins flush --}}
<div class="sticky top-0 z-40 shadow-sm" style="background: var(--surface); border-bottom: 1px solid var(--border);">
    <div class="px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-14">
            <div class="flex items-center gap-3">
                <a href="{{ route('presentations.show', $presentation) }}" class="inline-flex items-center gap-1 text-sm transition-all duration-300" style="color: var(--text-secondary);">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    Overview
                </a>
            </div>
            <div class="flex-1 text-center truncate mx-4">
                <h2 class="text-sm font-semibold truncate" style="color: var(--text-primary);">Market Analysis — {{ $presentation->title }}</h2>
            </div>
            <div class="flex items-center gap-2">
                @if(isset($latestSnapshot) && $latestSnapshot && $latestSnapshot->generated_at)
                <span class="text-xs text-emerald-600 font-medium">Last analysed: {{ $latestSnapshot->generated_at->format('d M Y, H:i') }}</span>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="p-4 lg:p-6">

{{-- ══════════════════════════════════════════════════════════════════════════
     PAGE HEADER
══════════════════════════════════════════════════════════════════════════ --}}
<div style="background: var(--brand-default, #0b2a4a);" class="rounded-md px-6 py-4 mb-6">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Market Analysis</h2>
            <div class="text-sm text-white/60">
                {{ $presentation->title }}
                @if($presentation->property_address)
                    &nbsp;·&nbsp; {{ $presentation->property_address }}
                @endif
            </div>
        </div>
        <a href="{{ route('presentations.show', $presentation) }}"
           class="corex-btn-outline" style="color:#fff; border-color:rgba(255,255,255,0.3); background:transparent;">
            &larr; Overview
        </a>
    </div>
</div>

{{-- D1: Prep data summary — links + uploads at a glance --}}
@php
    $linkCount   = $presentation->links->count();
    $uploadCount = $presentation->uploads->count();
    $lastUpload  = $presentation->uploads->sortByDesc('created_at')->first();
@endphp
@if($linkCount > 0 || $uploadCount > 0)
<div class="mb-4 flex flex-wrap gap-4 text-xs" style="color: var(--text-secondary);">
    @if($linkCount > 0)
        <span>
            <span class="font-medium" style="color: var(--text-primary);">{{ $linkCount }}</span>
            {{ $linkCount === 1 ? 'link' : 'links' }} attached
            <a href="{{ route('presentations.show', $presentation) }}#links"
               class="ml-1 hover:underline" style="color: var(--brand-icon, #0ea5e9);">manage</a>
        </span>
    @endif
    @if($uploadCount > 0)
        <span>
            <span class="font-medium" style="color: var(--text-primary);">{{ $uploadCount }}</span>
            {{ $uploadCount === 1 ? 'document' : 'documents' }} uploaded
            @if($lastUpload)
                <span style="color: var(--text-muted);">· last {{ $lastUpload->created_at->format('d M') }}</span>
            @endif
        </span>
    @endif
</div>
@endif

{{-- ══════════════════════════════════════════════════════════════════════════
     RUN ANALYSIS PANEL
══════════════════════════════════════════════════════════════════════════ --}}
<div class="ds-status-card mb-6" style="border-left-color: var(--ds-cyan);">
    <div class="flex items-center justify-between mb-3">
        <h2 class="ds-section-header">Run Analysis</h2>
        @if(isset($latestSnapshot) && $latestSnapshot && $latestSnapshot->generated_at)
            <span class="text-xs text-emerald-600 font-medium">
                Snapshot saved {{ $latestSnapshot->generated_at->diffForHumans() }}
            </span>
        @endif
    </div>
    <form method="POST" action="{{ route('presentations.analysis.run', $presentation) }}">
        @csrf
        <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
            <div>
                <label class="ds-label block mb-1">Asking Price (R)</label>
                <input type="number" name="asking_price_inc"
                       value="{{ $presentation->asking_price_inc ?? '' }}"
                       step="1" min="0"
                       class="w-full pres-input px-3 py-2 text-sm"
                       placeholder="e.g. 2500000">
                <p class="mt-0.5 text-xs" style="color: var(--text-muted);">Saves to presentation and freezes analysis snapshot.</p>
                @error('asking_price_inc')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div class="flex items-end">
                <button type="submit" class="corex-btn-primary">
                    @if(isset($latestSnapshot) && $latestSnapshot) Re-run Analysis @else Run Analysis @endif
                </button>
            </div>
        </div>

        {{-- Context fields (read-only, from presentation record) --}}
        <div class="mt-4 pt-3 border-t grid grid-cols-2 gap-x-8 gap-y-1 text-xs md:grid-cols-4" style="color: var(--text-secondary);">
            <div>Suburb: <span class="font-medium" style="color: var(--text-primary);">{{ $presentation->suburb ?? '—' }}</span></div>
            <div>Type: <span class="font-medium" style="color: var(--text-primary);">{{ \Illuminate\Support\Str::humanType($presentation->property_type) }}</span></div>
            <div>Bedrooms: <span class="font-medium" style="color: var(--text-primary);">{{ $presentation->bedrooms ?? '—' }}</span></div>
            <div>Floor area: <span class="font-medium" style="color: var(--text-primary);">{{ $presentation->floor_area_m2 ? $presentation->floor_area_m2 . ' m²' : '—' }}</span></div>
        </div>
    </form>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     ACTION BUTTONS
══════════════════════════════════════════════════════════════════════════ --}}
@php
    // AT-27 Phase B — the dispatch/download actions are CONFIRMED-only. A
    // presentation is "confirmed" once its current version has been frozen +
    // its exec summary generated via Confirm & Generate (review_status =
    // published). No half-states: a not-yet-usable action is HIDDEN, not shown
    // disabled. Pre-confirm, the only forward action is Confirm & Generate.
    $isConfirmed = isset($latestVersion) && $latestVersion
        && $latestVersion->review_status === \App\Models\PresentationVersion::REVIEW_PUBLISHED;
@endphp
<div class="flex flex-wrap items-center gap-3 mb-6">
    @unless($isConfirmed)
        {{-- The single forward action. Recompiles from the confirmed edits,
             freezes the snapshot, and generates the exec summary from those
             confirmed numbers, then lands on the Overview/dispatch screen.
             Replaces the old "Compile Pack" path. --}}
        <form method="POST" action="{{ route('presentations.analysis.confirm', $presentation) }}" class="inline">
            @csrf
            <button type="submit" class="corex-btn-primary" style="background:#059669;">
                Confirm &amp; Generate
            </button>
        </form>
    @else
        {{-- Confirmed: the report is frozen and the exec summary exists, so the
             dispatch/download actions are now available. --}}
        <a href="{{ route('presentations.versions.pdf', [$presentation, $latestVersion]) }}"
           class="corex-btn-primary">
            Download PDF
        </a>
        <a href="{{ route('presentations.versions.complete-pack', [$presentation, $latestVersion]) }}"
           class="corex-btn-primary" style="background:#00b4d8;">
            Complete Pack (ZIP)
        </a>
        @if(config('features.pricing_simulator_v1'))
        <a href="{{ route('presentations.pricing-simulator', $presentation) }}"
           class="corex-btn-primary">
            Pricing Simulator
        </a>
        @endif
        {{-- Re-confirm after further edits — re-freezes + regenerates the summary. --}}
        <form method="POST" action="{{ route('presentations.analysis.confirm', $presentation) }}" class="inline">
            @csrf
            <button type="submit" class="corex-btn-outline">
                Re-confirm &amp; Regenerate
            </button>
        </form>
    @endunless

    {{-- Back to Overview --}}
    <a href="{{ route('presentations.show', $presentation) }}"
       class="corex-btn-outline">
        &larr; Back to Overview
    </a>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     AT-27 Phase B.3 — WHAT'S IN YOUR PRESENTATION (section toggles)
     Moved here from the review screen: inclusion decisions belong where the
     numbers are visible. Persists per version via presentations.review.sections.
══════════════════════════════════════════════════════════════════════════ --}}
@if($latestVersion)
<div class="ds-status-card mb-6">
    <h2 class="ds-section-header mb-1">What's in your presentation</h2>
    <p class="text-xs mb-3" style="color: var(--text-muted);">
        Choose which sections appear in the generated report. Always-shown sections are locked.
    </p>
    <div id="analysis-section-toggles" class="grid gap-2"
         style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));"
         data-section-url="{{ route('presentations.review.sections', $latestVersion->id) }}">
        @foreach($sectionsCatalogue as $sKey => $sLabel)
            @php
                $isFloor = in_array($sKey, $sectionFloor, true);
                $isOn    = (bool) ($sectionSnapshot[$sKey] ?? true);
                $deps    = $sectionDeps[$sKey] ?? [];
            @endphp
            <label class="flex items-start gap-2 p-2 rounded-md border"
                   style="background: var(--surface-2); border-color: var(--border); {{ $isFloor ? 'opacity:0.8;' : 'cursor:pointer;' }}"
                   data-section-key="{{ $sKey }}">
                <input type="checkbox" class="analysis-section-toggle mt-0.5"
                       style="accent-color:#00d4aa; width:16px; height:16px;"
                       data-section-key="{{ $sKey }}"
                       {{ $isOn ? 'checked' : '' }} {{ $isFloor ? 'disabled' : '' }}>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium" style="color: var(--text-primary);">
                        {{ $sLabel }}
                        @if($isFloor)
                            <span class="ml-1 text-[10px] px-1.5 py-0.5 rounded" style="background: var(--surface-3); color: var(--text-muted);">Always shown</span>
                        @endif
                        @foreach($deps as $depKey)
                            <span class="ml-1 text-[10px] px-1.5 py-0.5 rounded" style="background: var(--surface-3); color: var(--text-muted);">needs {{ $sectionsCatalogue[$depKey] ?? $depKey }}</span>
                        @endforeach
                    </div>
                </div>
            </label>
        @endforeach
    </div>
    <p id="analysis-section-save-state" class="mt-2 text-xs" style="color: var(--text-muted); min-height:1em;"></p>
</div>
<script>
(function () {
    const wrap = document.getElementById('analysis-section-toggles');
    if (!wrap) return;
    const url   = wrap.dataset.sectionUrl;
    const csrf  = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';
    const state = document.getElementById('analysis-section-save-state');
    wrap.querySelectorAll('.analysis-section-toggle').forEach((cb) => {
        cb.addEventListener('change', async () => {
            if (state) state.textContent = 'Saving…';
            try {
                const body = new FormData();
                body.append('_token', csrf);
                body.append('section_key', cb.dataset.sectionKey);
                body.append('enabled', cb.checked ? '1' : '0');
                const r = await fetch(url, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body, credentials: 'same-origin',
                });
                const d = await r.json();
                if (d && d.ok) { if (state) state.textContent = 'Saved.'; }
                else { cb.checked = !cb.checked; if (state) state.textContent = 'Could not save — reverted.'; }
            } catch (e) {
                cb.checked = !cb.checked;
                if (state) state.textContent = 'Network error — reverted.';
            }
        });
    });
})();
</script>
@endif

{{-- ══════════════════════════════════════════════════════════════════════════
     READINESS CHECKLIST — reads from presentation record
══════════════════════════════════════════════════════════════════════════ --}}
@php
    $ckSuburb    = !empty($presentation->suburb);
    $ckType      = !empty($presentation->property_type);
    $ckPrice     = !empty($presentation->asking_price_inc);
    $ckFloorArea = !empty($presentation->floor_area_m2);
    $ckSold      = $presentation->soldComps()->count() > 0
                   || $presentation->uploads()->whereIn('type', ['vicinity_sales', 'cma'])->where('extraction_status', 'ok')->exists();
    $ckActive    = $presentation->activeListings()->count() > 0
                   || \App\Models\PortalCapture::where('presentation_id', $presentation->id)->where('parse_status', 'parsed')->exists();
@endphp

<div class="ds-status-card mb-6" style="border-left-color: var(--ds-cyan);" id="readiness">
    <h2 class="ds-section-header">Analysis readiness</h2>
    <div class="grid grid-cols-2 gap-x-8 gap-y-2 text-xs sm:grid-cols-3">
        @php
        $items = [
            ['label' => 'Suburb', 'ok' => $ckSuburb, 'fix' => 'Set suburb on overview page'],
            ['label' => 'Property type', 'ok' => $ckType, 'fix' => 'Set property type on overview page'],
            ['label' => 'Asking price', 'ok' => $ckPrice, 'fix' => 'Enter asking price above'],
            ['label' => 'Floor area', 'ok' => $ckFloorArea, 'fix' => 'Add floor area on overview page'],
            ['label' => 'Sold comparables', 'ok' => $ckSold, 'fix' => 'Upload CMA/vicinity sales PDF'],
            ['label' => 'Active listings', 'ok' => $ckActive, 'fix' => 'Import active listings via extension'],
        ];
        @endphp
        @foreach($items as $item)
            <div class="flex items-center gap-2">
                @if($item['ok'])
                    <span class="text-emerald-500 font-bold">✓</span>
                    <span style="color: var(--text-primary);">{{ $item['label'] }}</span>
                @else
                    <span class="font-bold" style="color: var(--text-muted);">○</span>
                    <span style="color: var(--text-muted);">{{ $item['label'] }}
                        <span style="color: var(--brand-icon, #0ea5e9);"> — {{ $item['fix'] }}</span>
                    </span>
                @endif
            </div>
        @endforeach
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     EXTRACTED DATA REVIEW — 7 sections from AnalysisDataService
══════════════════════════════════════════════════════════════════════════ --}}
<div id="analysis-results">
@include('presentations.partials.analysis-data-review')
</div>

{{-- Scroll preservation + analysis selection JS --}}
<script>
(function () {
    'use strict';

    var STORAGE_KEY = 'pres_analysis_scroll_{{ $presentation->id }}';
    var SAVE_URL    = '{{ route("presentations.analysis-selections.update", $presentation) }}';
    var CSRF_TOKEN  = document.querySelector('meta[name="csrf-token"]')?.content || '';

    // ── Scroll/focus preservation ────────────────────────────────────────
    try {
        if (window.location.hash) {
            // Browser auto-scrolls
        } else {
            var saved = sessionStorage.getItem(STORAGE_KEY);
            if (saved) {
                sessionStorage.removeItem(STORAGE_KEY);
                var state = JSON.parse(saved);
                if (state.scrollY) window.scrollTo(0, state.scrollY);
                if (state.focusId) {
                    var el = document.getElementById(state.focusId);
                    if (el) el.focus();
                } else if (state.focusName) {
                    var el2 = document.querySelector('[name="' + state.focusName + '"]');
                    if (el2) el2.focus();
                }
            }
        }
    } catch (e) { /* ignore */ }

    document.addEventListener('submit', function (e) {
        if (!e.target || e.target.tagName !== 'FORM' || e.defaultPrevented) return;
        try {
            var focused = document.activeElement;
            var st = { scrollY: window.scrollY };
            if (focused && focused.id) st.focusId = focused.id;
            else if (focused && focused.name) st.focusName = focused.name;
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(st));
        } catch (ex) { /* ignore */ }
    });

    document.addEventListener('click', function (e) {
        var link = e.target.closest('a[href]');
        if (!link) return;
        var href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:') || link.target === '_blank') return;
        try {
            var u = new URL(href, window.location.origin);
            if (u.pathname === window.location.pathname)
                sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ scrollY: window.scrollY }));
        } catch (ex) { /* ignore */ }
    });

    // ── AJAX save helper ─────────────────────────────────────────────────
    function saveSelection(payload) {
        fetch(SAVE_URL, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF_TOKEN,
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        }).catch(function () { /* silent — selections persist on next reload */ });
    }

    // ── Number formatting helper ─────────────────────────────────────────
    function fmtZar(n) {
        if (!n) return '—';
        return 'R ' + Number(n).toLocaleString('en-ZA');
    }

    // ── CMA tile click ───────────────────────────────────────────────────
    document.querySelectorAll('.cma-tile').forEach(function (tile) {
        tile.addEventListener('click', function () {
            var range = tile.dataset.range;
            var value = parseInt(tile.dataset.value, 10);

            // Visual update — all CMA tiles
            document.querySelectorAll('.cma-tile').forEach(function (t) {
                var isSel = t.dataset.range === range;
                t.className = t.className
                    .replace(/bg-sky-50|bg-gray-50|hover:bg-gray-100|ring-1|ring-sky-200/g, '')
                    .trim();
                if (isSel) {
                    t.classList.add('bg-sky-50', 'ring-1', 'ring-sky-200');
                } else {
                    t.classList.add('bg-gray-50', 'hover:bg-gray-100');
                }
                var label = t.querySelector('span');
                var valP  = t.querySelector('p');
                if (label) {
                    label.className = label.className.replace(/text-\[#38bfe0\]|text-gray-400/g, '').trim();
                    label.classList.add(isSel ? 'text-[#38bfe0]' : 'text-gray-400');
                }
                if (valP) {
                    valP.className = valP.className
                        .replace(/font-bold|font-semibold|text-\[#0b2a4a\]|text-gray-700|text-lg/g, '')
                        .trim();
                    if (isSel) valP.classList.add('font-bold', 'text-[#0b2a4a]', 'text-lg');
                    else valP.classList.add('font-semibold', 'text-gray-700');
                }
            });

            // Update asking-vs-CMA comparison box
            updateAskingVsCma(range, value);

            // Update key insights CMA card
            updateInsightCard('cma', range, value);

            // Save
            saveSelection({ cma_selected_range: range });
        });
    });

    // ── Vicinity tile click ──────────────────────────────────────────────
    document.querySelectorAll('.vicinity-tile').forEach(function (tile) {
        tile.addEventListener('click', function () {
            var range = tile.dataset.range;
            var value = parseInt(tile.dataset.value, 10);

            document.querySelectorAll('.vicinity-tile').forEach(function (t) {
                var isSel = t.dataset.range === range;
                t.className = t.className
                    .replace(/bg-sky-50|bg-gray-50|hover:bg-gray-100|ring-1|ring-sky-200/g, '')
                    .trim();
                if (isSel) t.classList.add('bg-sky-50', 'ring-1', 'ring-sky-200');
                else t.classList.add('bg-gray-50', 'hover:bg-gray-100');

                var label = t.querySelector('span');
                var valP  = t.querySelector('p');
                if (label) {
                    label.className = label.className.replace(/text-\[#38bfe0\]|text-gray-400/g, '').trim();
                    label.classList.add(isSel ? 'text-[#38bfe0]' : 'text-gray-400');
                }
                if (valP) {
                    valP.className = valP.className
                        .replace(/font-bold|font-semibold|text-\[#0b2a4a\]|text-gray-700|text-lg/g, '')
                        .trim();
                    if (isSel) valP.classList.add('font-bold', 'text-[#0b2a4a]', 'text-lg');
                    else valP.classList.add('font-semibold', 'text-gray-700');
                }
            });

            updateInsightCard('vicinity', range, value);
            saveSelection({ vicinity_selected_range: range });
        });
    });

    // ── Asking vs CMA recalculation ──────────────────────────────────────
    function updateAskingVsCma(range, cmaValue) {
        var box = document.getElementById('asking-vs-cma');
        if (!box) return;
        var asking = parseInt(box.dataset.asking, 10);
        if (!asking || !cmaValue) return;

        var pct = ((asking - cmaValue) / cmaValue * 100).toFixed(1);
        var overpriced = pct > 10;

        var label  = document.getElementById('asking-cma-label');
        var values = document.getElementById('asking-cma-values');
        var pctEl  = document.getElementById('asking-cma-pct');
        var note   = document.getElementById('asking-cma-note');

        if (label) label.textContent = 'Asking Price vs CMA ' + range.charAt(0).toUpperCase() + range.slice(1);
        if (values) values.textContent = fmtZar(asking) + ' vs ' + fmtZar(cmaValue);
        if (pctEl) pctEl.textContent = (pct > 0 ? '+' : '') + pct + '%';

        // Update colors
        box.className = box.className
            .replace(/bg-red-50|bg-emerald-50|border-red-200|border-emerald-200/g, '').trim();
        box.classList.add(overpriced ? 'bg-red-50' : 'bg-emerald-50', overpriced ? 'border-red-200' : 'border-emerald-200');

        if (label) {
            label.className = label.className.replace(/text-red-600|text-emerald-600/g, '').trim();
            label.classList.add(overpriced ? 'text-red-600' : 'text-emerald-600');
        }
        if (pctEl) {
            pctEl.className = pctEl.className.replace(/text-red-600|text-emerald-600/g, '').trim();
            pctEl.classList.add(overpriced ? 'text-red-600' : 'text-emerald-600');
        }
        if (note) {
            if (overpriced) {
                note.textContent = 'Above CMA evaluation';
                note.classList.remove('hidden', 'text-emerald-500');
                note.classList.add('text-red-500');
            } else {
                note.classList.add('hidden');
            }
        }
    }

    // ── Key Insights card update ─────────────────────────────────────────
    function updateInsightCard(type, range, newBenchmark) {
        var cards = document.querySelectorAll('.insight-card');
        cards.forEach(function (card) {
            var label = card.dataset.label || '';
            var match = (type === 'cma' && label.indexOf('CMA') !== -1)
                     || (type === 'vicinity' && label.indexOf('Vicinity') !== -1);
            if (!match) return;

            var asking = parseInt(card.dataset.asking, 10);
            if (!asking || !newBenchmark) return;

            var pct = ((asking - newBenchmark) / newBenchmark * 100).toFixed(1);
            var thresholds = type === 'cma'
                ? { warning: 5, danger: 15 }
                : { warning: 10, danger: 30 };
            var status = pct > thresholds.danger ? 'danger' : (pct > thresholds.warning ? 'warning' : 'ok');

            // Update data attrs
            card.dataset.benchmark = newBenchmark;
            card.dataset.pct = pct;

            // Update label
            var rangeName = range.charAt(0).toUpperCase() + range.slice(1);
            var labelEl = card.querySelector('.insight-label');
            if (labelEl) {
                if (type === 'cma') labelEl.textContent = 'vs CMA Evaluation (' + range + ')';
                else labelEl.textContent = 'vs Vicinity Range (' + range + ')';
            }

            // Update values
            var valEl = card.querySelector('.insight-values');
            if (valEl) valEl.textContent = fmtZar(asking) + ' vs ' + fmtZar(newBenchmark);

            // Update pct
            var pctEl = card.querySelector('.insight-pct');
            if (pctEl) pctEl.textContent = (pct > 0 ? '+' : '') + pct + '%';

            // Update colors
            var statusMap = {
                danger:  { bg: 'bg-red-50', border: 'border-red-200', text: 'text-red-700', pct: 'text-red-600' },
                warning: { bg: 'bg-amber-50', border: 'border-amber-200', text: 'text-amber-700', pct: 'text-amber-600' },
                ok:      { bg: 'bg-emerald-50', border: 'border-emerald-200', text: 'text-emerald-700', pct: 'text-emerald-600' }
            };
            var colors = statusMap[status];
            card.className = card.className
                .replace(/bg-(red|amber|emerald)-50/g, '')
                .replace(/border-(red|amber|emerald)-200/g, '')
                .replace(/text-(red|amber|emerald)-700/g, '')
                .trim();
            card.classList.add(colors.bg, colors.border, colors.text);
            if (pctEl) {
                pctEl.className = pctEl.className
                    .replace(/text-(red|amber|emerald)-600/g, '').trim();
                pctEl.classList.add(colors.pct);
            }
        });
    }

    // ── Active listing checkboxes ────────────────────────────────────────
    var checkAll = document.getElementById('active-check-all');
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            var checked = checkAll.checked;
            document.querySelectorAll('.active-listing-check').forEach(function (cb) {
                cb.checked = checked;
                updateRowStyle(cb);
            });
            recalcActiveStats();
            saveExcludedIndices();
        });
    }

    document.querySelectorAll('.active-listing-check').forEach(function (cb) {
        cb.addEventListener('change', function () {
            updateRowStyle(cb);
            recalcActiveStats();
            saveExcludedIndices();
        });
    });

    function updateRowStyle(cb) {
        var row = cb.closest('tr');
        if (!row) return;
        if (cb.checked) {
            row.classList.remove('opacity-50');
            row.querySelectorAll('td').forEach(function (td) { td.classList.remove('line-through'); });
        } else {
            row.classList.add('opacity-50');
            // Only strikethrough the address cell
            var addressTd = row.querySelector('td:nth-child(2)');
            if (addressTd) addressTd.classList.add('line-through');
        }
    }

    function recalcActiveStats() {
        var included = 0;
        var total = 0;
        var priceSum = 0;
        var priceCount = 0;

        document.querySelectorAll('.active-listing-row').forEach(function (row) {
            total++;
            var cb = row.querySelector('.active-listing-check');
            if (cb && cb.checked) {
                included++;
                var price = parseInt(row.dataset.price, 10);
                if (price > 0) { priceSum += price; priceCount++; }
            }
        });

        var countEl = document.getElementById('active-count');
        var avgEl   = document.getElementById('active-avg-price');
        if (countEl) countEl.textContent = included;
        if (avgEl) avgEl.textContent = priceCount > 0 ? fmtZar(Math.round(priceSum / priceCount)) : '';
    }

    function saveExcludedIndices() {
        var excluded = [];
        document.querySelectorAll('.active-listing-check').forEach(function (cb) {
            if (!cb.checked) excluded.push(parseInt(cb.dataset.rowIndex, 10));
        });
        saveSelection({ excluded_active_listing_indices: excluded });
    }
})();
</script>

</div>{{-- /padded content --}}

@endsection
