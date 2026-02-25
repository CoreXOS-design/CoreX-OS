@extends('layouts.nexus')

@section('nexus-content')

{{-- ══════════════════════════════════════════════════════════════════════════
     PAGE HEADER
══════════════════════════════════════════════════════════════════════════ --}}
<div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 mb-6">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Market Analysis</h2>
            <div class="text-sm text-white/60">
                {{ $presentation->title }}
                @if($presentation->property_address)
                    &nbsp;·&nbsp; {{ $presentation->property_address }}
                @endif
            </div>
            @if(isset($latestSnapshot) && $latestSnapshot && $latestSnapshot->generated_at)
                <div class="text-xs text-emerald-300 mt-1 font-medium">
                    Last analysed: {{ $latestSnapshot->generated_at->format('d M Y, H:i') }}
                </div>
            @endif
        </div>
        <a href="{{ route('presentations.show', $presentation) }}"
           class="nexus-btn-outline" style="color:#fff; border-color:rgba(255,255,255,0.3); background:transparent;">
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
<div class="mb-4 flex flex-wrap gap-4 text-xs text-gray-500">
    @if($linkCount > 0)
        <span>
            <span class="font-medium text-gray-700">{{ $linkCount }}</span>
            {{ $linkCount === 1 ? 'link' : 'links' }} attached
            <a href="{{ route('presentations.show', $presentation) }}#links"
               class="ml-1 text-[#00b4d8] hover:underline">manage</a>
        </span>
    @endif
    @if($uploadCount > 0)
        <span>
            <span class="font-medium text-gray-700">{{ $uploadCount }}</span>
            {{ $uploadCount === 1 ? 'document' : 'documents' }} uploaded
            @if($lastUpload)
                <span class="text-gray-400">· last {{ $lastUpload->created_at->format('d M') }}</span>
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
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none"
                       placeholder="e.g. 2500000">
                <p class="mt-0.5 text-xs text-gray-400">Saves to presentation and freezes analysis snapshot.</p>
                @error('asking_price_inc')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div class="flex items-end">
                <button type="submit" class="nexus-btn-primary">
                    @if(isset($latestSnapshot) && $latestSnapshot) Re-run Analysis @else Run Analysis @endif
                </button>
            </div>
        </div>

        {{-- Context fields (read-only, from presentation record) --}}
        <div class="mt-4 pt-3 border-t grid grid-cols-2 gap-x-8 gap-y-1 text-xs text-gray-500 md:grid-cols-4">
            <div>Suburb: <span class="font-medium text-gray-700">{{ $presentation->suburb ?? '—' }}</span></div>
            <div>Type: <span class="font-medium text-gray-700">{{ ucfirst($presentation->property_type ?? '—') }}</span></div>
            <div>Bedrooms: <span class="font-medium text-gray-700">{{ $presentation->bedrooms ?? '—' }}</span></div>
            <div>Floor area: <span class="font-medium text-gray-700">{{ $presentation->floor_area_m2 ? $presentation->floor_area_m2 . ' m²' : '—' }}</span></div>
        </div>
    </form>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     ACTION BUTTONS
══════════════════════════════════════════════════════════════════════════ --}}
<div class="flex flex-wrap items-center gap-3 mb-6">
    {{-- Compile Pack --}}
    @if(isset($readiness) && $readiness['can_compile'])
    <form method="POST" action="{{ route('presentations.compile', $presentation) }}" class="inline">
        @csrf
        <button type="submit" class="nexus-btn-primary" style="background:#059669;">
            Compile Pack
        </button>
    </form>
    @else
    <button disabled class="px-4 py-2 bg-gray-300 text-gray-500 text-sm font-medium rounded cursor-not-allowed" title="Complete readiness checklist first">
        Compile Pack
    </button>
    @endif

    {{-- Download PDF --}}
    @if(isset($latestVersion) && $latestVersion)
    <a href="{{ route('presentations.versions.pdf', [$presentation, $latestVersion]) }}"
       class="nexus-btn-primary">
        Download PDF
    </a>
    <a href="{{ route('presentations.versions.complete-pack', [$presentation, $latestVersion]) }}"
       class="nexus-btn-primary" style="background:#00b4d8;">
        Complete Pack (ZIP)
    </a>
    @endif

    {{-- Pricing Simulator --}}
    @if(config('features.pricing_simulator_v1'))
    <a href="{{ route('presentations.pricing-simulator', $presentation) }}"
       class="nexus-btn-primary">
        Pricing Simulator
    </a>
    @endif

    {{-- Back to Overview --}}
    <a href="{{ route('presentations.show', $presentation) }}"
       class="px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded hover:bg-gray-200">
        &larr; Back to Overview
    </a>
</div>

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
                    <span class="text-gray-700">{{ $item['label'] }}</span>
                @else
                    <span class="text-gray-300 font-bold">○</span>
                    <span class="text-gray-400">{{ $item['label'] }}
                        <span class="text-[#00b4d8]"> — {{ $item['fix'] }}</span>
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
                note.textContent = 'Above CMA valuation';
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
                if (type === 'cma') labelEl.textContent = 'vs CMA Valuation (' + range + ')';
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

@endsection
