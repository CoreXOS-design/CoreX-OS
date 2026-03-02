@extends('layouts.corex')

@section('corex-content')

@php
    // Feature availability map — mirrors the abort_unless() gates on each endpoint
    $canSimulate  = true; // simulate endpoint has no feature gate
    $canTrajectory = (bool) config('features.trajectory_simulation_v1');
    $canPriceBand  = (bool) config('features.price_band_v1');
    $canThreats    = (bool) config('features.competitive_threat_v1');
@endphp

{{-- ═══════════════════════════════════════════════════════════════════════════
     BRAIN SIMULATION — PREMIUM DARK UI
═══════════════════════════════════════════════════════════════════════════ --}}

{{-- Navy header bar --}}
<div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 mb-6">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Brain Simulation</h2>
            <div class="text-sm text-white/60">
                {{ $presentation->title }}
                @if($presentation->property_address)
                    &middot; {{ $presentation->property_address }}
                @endif
                @if($presentation->suburb)
                    &middot; {{ $presentation->suburb }}
                @endif
            </div>
        </div>
        <a href="{{ route('presentations.show', $presentation) }}"
           class="corex-btn-outline" style="color:#fff; border-color:rgba(255,255,255,0.3); background:transparent;">
            &larr; Overview
        </a>
    </div>
</div>

<div id="brain-app" class="min-h-screen -mx-4 -mt-4 px-4 pt-4 pb-12 bg-gray-950 text-gray-100" style="border-radius:1rem;">

{{-- ── TOAST CONTAINER ──────────────────────────────────────────────────── --}}
<div id="brain-toast" class="fixed top-4 right-4 z-50 space-y-2 pointer-events-none" style="max-width:380px;"></div>

{{-- ══════════════════════════════════════════════════════════════════════════
     A) COMMAND BAR
══════════════════════════════════════════════════════════════════════════ --}}
<div class="mb-8">

    {{-- Command card --}}
    <div class="bg-gray-900 border border-gray-800 rounded-2xl p-5">

        {{-- Price input row --}}
        <div class="flex flex-wrap items-end gap-4 mb-4">
            {{-- Main price input --}}
            <div class="flex-1 min-w-[200px]">
                <label class="block text-[10px] uppercase tracking-widest text-gray-500 mb-1.5">Listing Price<!-- Price Slider --></label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm font-medium">R</span>
                    <input type="text" id="inp-price"
                           value="{{ $defaults['price'] ?? '' }}"
                           placeholder="2,500,000"
                           class="w-full bg-gray-800 border border-gray-700 rounded-lg pl-7 pr-3 py-2.5 text-lg font-semibold text-white placeholder-gray-600 focus:border-[#00b4d8] focus:ring-1 focus:ring-[#00b4d8] outline-none transition-colors"
                           inputmode="numeric">
                </div>
            </div>

            {{-- Quick adjust buttons --}}
            <div class="flex gap-1.5">
                <button data-adjust="-100000" class="adj-btn px-2.5 py-2 bg-gray-800 border border-gray-700 rounded-lg text-xs text-gray-400 hover:text-white hover:border-gray-600 transition-colors">-100k</button>
                <button data-adjust="-50000" class="adj-btn px-2.5 py-2 bg-gray-800 border border-gray-700 rounded-lg text-xs text-gray-400 hover:text-white hover:border-gray-600 transition-colors">-50k</button>
                <button data-adjust="-25000" class="adj-btn px-2.5 py-2 bg-gray-800 border border-gray-700 rounded-lg text-xs text-gray-400 hover:text-white hover:border-gray-600 transition-colors">-25k</button>
                <button data-adjust="50000" class="adj-btn px-2.5 py-2 bg-gray-800 border border-gray-700 rounded-lg text-xs text-green-500 hover:text-green-400 hover:border-gray-600 transition-colors">+50k</button>
            </div>
        </div>

        {{-- Compact inputs row --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 mb-5">
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-gray-500 mb-1">Suburb</label>
                <input type="text" id="inp-suburb" value="{{ $defaults['suburb'] }}"
                       class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-1.5 text-xs text-gray-200 focus:border-[#00b4d8] outline-none transition-colors">
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-gray-500 mb-1">Type</label>
                <select id="inp-type" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-1.5 text-xs text-gray-200 focus:border-[#00b4d8] outline-none transition-colors">
                    @foreach(['house','unit','land','other'] as $t)
                        <option value="{{ $t }}" {{ $defaults['type'] === $t ? 'selected' : '' }}>{{ ucfirst($t) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-gray-500 mb-1">Bedrooms</label>
                <input type="number" id="inp-bedrooms" min="0" max="20" value="{{ $defaults['bedrooms'] ?? '' }}"
                       class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-1.5 text-xs text-gray-200 focus:border-[#00b4d8] outline-none transition-colors">
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-gray-500 mb-1">Size m&sup2;</label>
                <input type="number" id="inp-size" min="0" value="{{ $defaults['size_m2'] ?? '' }}"
                       class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-1.5 text-xs text-gray-200 focus:border-[#00b4d8] outline-none transition-colors">
            </div>
        </div>

        {{-- Hidden period (keep default) --}}
        <input type="hidden" id="inp-period" value="{{ $defaults['period_months'] ?? 12 }}">

        {{-- Action buttons --}}
        <div class="flex flex-wrap items-center gap-3">
            {{-- Primary: Simulate --}}
            <button id="btn-simulate"
                    class="px-5 py-2.5 bg-[#0b2a4a] text-white text-sm font-medium rounded-lg hover:bg-[#00b4d8] active:bg-[#081f36] transition-colors disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-2"
                    {{ $canSimulate ? '' : 'disabled' }}>
                <span id="btn-simulate-text">Run Simulation</span>
                <svg id="btn-simulate-spin" class="hidden animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
            </button>

            <div class="w-px h-6 bg-gray-700"></div>

            {{-- Secondary: Price Band --}}
            <div class="relative group">
                <button id="btn-priceband"
                        class="px-4 py-2 bg-gray-800 border border-gray-700 text-gray-300 text-xs font-medium rounded-lg hover:border-gray-500 hover:text-white transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                        {{ $canPriceBand ? '' : 'disabled' }}>
                    Price Band
                </button>
                @unless($canPriceBand)
                    <span class="block text-[10px] text-gray-600 mt-1">Enable price_band_v1</span>
                @endunless
            </div>

            {{-- Secondary: Threats --}}
            <div class="relative group">
                <button id="btn-threats"
                        class="px-4 py-2 bg-gray-800 border border-gray-700 text-gray-300 text-xs font-medium rounded-lg hover:border-gray-500 hover:text-white transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                        {{ $canThreats ? '' : 'disabled' }}>
                    Competitive Threats
                </button>
                @unless($canThreats)
                    <span class="block text-[10px] text-gray-600 mt-1">Enable competitive_threat_v1</span>
                @endunless
            </div>

            {{-- Secondary: Trajectory --}}
            <div class="relative group">
                <button id="btn-trajectory"
                        class="px-4 py-2 bg-gray-800 border border-gray-700 text-gray-300 text-xs font-medium rounded-lg hover:border-gray-500 hover:text-white transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                        {{ $canTrajectory ? '' : 'disabled' }}>
                    Trajectory
                </button>
                @unless($canTrajectory)
                    <span class="block text-[10px] text-gray-600 mt-1">Enable trajectory_simulation_v1</span>
                @endunless
            </div>
        </div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     B) TACTICAL GRID — 4 Cards
══════════════════════════════════════════════════════════════════════════ --}}
<div id="tactical-grid" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-8">

    {{-- Card 1: Probability --}}
    <div class="bg-gray-900 border border-gray-800 rounded-2xl p-5">
        <p class="text-[10px] uppercase tracking-widest text-gray-500 mb-3">Probability</p>
        <div id="card-prob-empty" class="text-center py-4">
            <p class="text-gray-600 text-xs">Run a simulation to see results</p>
        </div>
        <div id="card-prob" class="hidden">
            <div class="flex items-baseline gap-2 mb-1">
                <span id="prob-p60" class="text-4xl font-bold text-white tabular-nums">--</span>
                <span class="text-xs text-gray-500">P60</span>
                <span id="prob-delta" class="text-sm font-medium hidden"></span>
            </div>
            <div class="flex gap-4 mt-2">
                <div>
                    <span class="text-[10px] text-gray-500">P30</span>
                    <p id="prob-p30" class="text-sm font-semibold text-gray-300 tabular-nums">--</p>
                </div>
                <div>
                    <span class="text-[10px] text-gray-500">P90</span>
                    <p id="prob-p90" class="text-sm font-semibold text-gray-300 tabular-nums">--</p>
                </div>
                <div>
                    <span class="text-[10px] text-gray-500">Exp. Days</span>
                    <p id="prob-days" class="text-sm font-semibold text-gray-300 tabular-nums">--</p>
                </div>
            </div>
            <div class="mt-3 pt-3 border-t border-gray-800">
                <p class="text-[10px] text-gray-600">Price tested</p>
                <p id="prob-price" class="text-xs text-gray-400 tabular-nums">--</p>
            </div>
        </div>
    </div>

    {{-- Card 2: Launch Position / Confidence --}}
    <div class="bg-gray-900 border border-gray-800 rounded-2xl p-5">
        <p class="text-[10px] uppercase tracking-widest text-gray-500 mb-3">Position</p>
        <div id="card-pos-empty" class="text-center py-4">
            <p class="text-gray-600 text-xs">Run a simulation to see results</p>
        </div>
        <div id="card-pos" class="hidden">
            <div id="pos-launch" class="mb-3 hidden">
                <span class="text-[10px] text-gray-500">Launch</span>
                <p id="pos-launch-label" class="text-lg font-bold text-white">--</p>
            </div>
            <div class="flex gap-4">
                <div>
                    <span class="text-[10px] text-gray-500">Confidence</span>
                    <p class="flex items-baseline gap-1">
                        <span id="pos-conf-score" class="text-2xl font-bold text-white tabular-nums">--</span>
                        <span id="pos-conf-grade" class="text-xs font-medium text-gray-400">--</span>
                    </p>
                </div>
                <div>
                    <span class="text-[10px] text-gray-500">PPI</span>
                    <p class="flex items-baseline gap-1">
                        <span id="pos-ppi-score" class="text-2xl font-bold text-white tabular-nums">--</span>
                        <span id="pos-ppi-label" class="text-xs font-medium text-gray-400">--</span>
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- Card 3: Market Pressure --}}
    <div class="bg-gray-900 border border-gray-800 rounded-2xl p-5">
        <p class="text-[10px] uppercase tracking-widest text-gray-500 mb-3">Market Pressure</p>
        <div id="card-mkt-empty" class="text-center py-4">
            <p class="text-gray-600 text-xs">Run a simulation to see results</p>
        </div>
        <div id="card-mkt" class="hidden">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <span class="text-[10px] text-gray-500">Months Stock</span>
                    <p id="mkt-months" class="text-lg font-bold text-white tabular-nums">--</p>
                </div>
                <div>
                    <span class="text-[10px] text-gray-500">Median DOM</span>
                    <p id="mkt-dom" class="text-lg font-bold text-white tabular-nums">--</p>
                </div>
                <div>
                    <span class="text-[10px] text-gray-500">Stale %</span>
                    <p id="mkt-stale" class="text-lg font-bold text-white tabular-nums">--</p>
                </div>
                <div>
                    <span class="text-[10px] text-gray-500">Data Quality</span>
                    <p id="mkt-quality" class="text-lg font-bold text-white tabular-nums">--</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Card 4: Holding Cost --}}
    <div class="bg-gray-900 border border-gray-800 rounded-2xl p-5">
        <p class="text-[10px] uppercase tracking-widest text-gray-500 mb-3">Holding Cost</p>
        <div id="card-hold-empty" class="text-center py-4">
            <p class="text-gray-600 text-xs">Run a simulation to see results</p>
        </div>
        <div id="card-hold" class="hidden">
            <div>
                <span class="text-[10px] text-gray-500">Monthly</span>
                <p id="hold-monthly" class="text-2xl font-bold text-white tabular-nums">--</p>
            </div>
            <div class="flex gap-4 mt-2">
                <div>
                    <span class="text-[10px] text-gray-500">90-day</span>
                    <p id="hold-90" class="text-sm font-semibold text-gray-300 tabular-nums">--</p>
                </div>
                <div>
                    <span class="text-[10px] text-gray-500">Per 30-day delay</span>
                    <p id="hold-delay" class="text-sm font-semibold text-gray-300 tabular-nums">--</p>
                </div>
            </div>
        </div>
        <div id="card-hold-none" class="hidden text-center py-4">
            <p class="text-gray-600 text-xs">Add holding inputs on the Overview page</p>
        </div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     C) STRATEGY ZONE
══════════════════════════════════════════════════════════════════════════ --}}
<div id="strategy-zone" class="space-y-4 mb-8">

    {{-- Key Drivers + Risks --}}
    <div id="strategy-drivers" class="hidden grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-5">
            <p class="text-[10px] uppercase tracking-widest text-gray-500 mb-3">Key Drivers</p>
            <ul id="drivers-list" class="space-y-1.5"></ul>
        </div>
        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-5">
            <p class="text-[10px] uppercase tracking-widest text-gray-500 mb-3">Risk Factors</p>
            <ul id="risks-list" class="space-y-1.5"></ul>
        </div>
    </div>

    {{-- Competitive Threats table --}}
    <div id="strategy-threats" class="hidden bg-gray-900 border border-gray-800 rounded-2xl p-5">
        <details>
            <summary class="text-[10px] uppercase tracking-widest text-gray-500 cursor-pointer hover:text-gray-400 transition-colors">
                Competitive Threats <span id="threats-count" class="text-gray-600"></span>
            </summary>
            <div id="threats-table" class="mt-3"></div>
        </details>
    </div>

    {{-- Price Band cards --}}
    <div id="strategy-priceband" class="hidden">
        <p class="text-[10px] uppercase tracking-widest text-gray-500 mb-3">Price Band</p>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3" id="priceband-cards"></div>
        <details id="priceband-scan-wrap" class="hidden mt-3 bg-gray-900 border border-gray-800 rounded-2xl p-4">
            <summary class="text-[10px] uppercase tracking-widest text-gray-500 cursor-pointer hover:text-gray-400 transition-colors">
                Scan Detail
            </summary>
            <div id="priceband-scan" class="mt-3"></div>
        </details>
    </div>

    {{-- Trajectory table --}}
    <div id="strategy-trajectory" class="hidden bg-gray-900 border border-gray-800 rounded-2xl p-5">
        <details>
            <summary class="text-[10px] uppercase tracking-widest text-gray-500 cursor-pointer hover:text-gray-400 transition-colors">
                Price Trajectory
            </summary>
            <div id="trajectory-table" class="mt-3"></div>
        </details>
    </div>

</div>

</div>{{-- /brain-app --}}

<script>
(function () {
    'use strict';

    // ── Feature map ──────────────────────────────────────────────────
    var features = {
        canSimulate:  {{ $canSimulate  ? 'true' : 'false' }},
        canTrajectory: {{ $canTrajectory ? 'true' : 'false' }},
        canPriceBand:  {{ $canPriceBand  ? 'true' : 'false' }},
        canThreats:    {{ $canThreats    ? 'true' : 'false' }},
    };

    var csrf    = '{{ csrf_token() }}';
    var baseUrl = '{{ url("presentations/" . $presentation->id) }}';

    // ── State ────────────────────────────────────────────────────────
    var lastSimResult = null;

    // ── Selectors ────────────────────────────────────────────────────
    function $(id) { return document.getElementById(id); }

    function val(id) {
        var el = $(id);
        return el ? el.value : '';
    }

    function intVal(id) {
        var v = parseInt(val(id), 10);
        return isNaN(v) ? null : v;
    }

    function floatVal(id) {
        var v = parseFloat(val(id));
        return isNaN(v) ? null : v;
    }

    // ── Formatters ───────────────────────────────────────────────────
    function fmtR(n) {
        if (n === null || n === undefined || isNaN(n)) return '--';
        return 'R' + Number(n).toLocaleString('en-ZA', { maximumFractionDigits: 0 });
    }

    function fmtPct(n) {
        if (n === null || n === undefined) return '--';
        return Math.round(n * 100) + '%';
    }

    function fmtNum(n) {
        if (n === null || n === undefined) return '--';
        return Number(n).toLocaleString('en-ZA', { maximumFractionDigits: 1 });
    }

    // ── Price input formatting ───────────────────────────────────────
    var priceInput = $('inp-price');

    function getRawPrice() {
        var raw = priceInput.value.replace(/[^0-9]/g, '');
        return raw ? parseInt(raw, 10) : null;
    }

    function formatPriceInput() {
        var raw = getRawPrice();
        if (raw !== null && raw > 0) {
            priceInput.value = Number(raw).toLocaleString('en-ZA');
        }
    }

    priceInput.addEventListener('blur', formatPriceInput);
    priceInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $('btn-simulate').click();
        }
    });
    formatPriceInput();

    // ── Quick adjust buttons ─────────────────────────────────────────
    document.querySelectorAll('.adj-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var current = getRawPrice() || 0;
            var delta   = parseInt(btn.dataset.adjust, 10);
            var next    = Math.max(0, current + delta);
            priceInput.value = next;
            formatPriceInput();
        });
    });

    // ── Toast notifications ──────────────────────────────────────────
    function toast(msg, type) {
        var container = $('brain-toast');
        var colors = {
            error: 'bg-red-900/90 border-red-700 text-red-200',
            info:  'bg-blue-900/90 border-blue-700 text-blue-200',
            warn:  'bg-amber-900/90 border-amber-700 text-amber-200',
        };
        var div = document.createElement('div');
        div.className = 'pointer-events-auto border rounded-lg px-4 py-3 text-xs shadow-lg transition-all ' + (colors[type] || colors.info);
        div.textContent = msg;
        container.appendChild(div);
        setTimeout(function () {
            div.style.opacity = '0';
            setTimeout(function () { div.remove(); }, 300);
        }, 5000);
    }

    // ── Loading state helpers ────────────────────────────────────────
    function setLoading(btnId, loading) {
        var btn  = $(btnId);
        var text = $(btnId + '-text');
        var spin = $(btnId + '-spin');
        if (text) text.style.display = loading ? 'none' : '';
        if (spin) {
            if (loading) spin.classList.remove('hidden');
            else         spin.classList.add('hidden');
        }
        btn.disabled = loading;
    }

    function setSecondaryLoading(btnId, loading) {
        var btn = $(btnId);
        if (loading) {
            btn.disabled = true;
            btn.dataset.origText = btn.textContent;
            btn.textContent = 'Computing\u2026';
        } else {
            btn.disabled = false;
            if (btn.dataset.origText) btn.textContent = btn.dataset.origText;
        }
    }

    // ── API helper ───────────────────────────────────────────────────
    function post(path, body) {
        return fetch(baseUrl + path, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
            },
            body: JSON.stringify(body),
        }).then(function (r) {
            if (r.status === 404) {
                // Treat 404 as "feature flag is off"
                throw { flagOff: true, message: 'This feature is not enabled.' };
            }
            if (!r.ok) return r.text().then(function (t) { throw new Error('HTTP ' + r.status + ': ' + t); });
            return r.json();
        });
    }

    function basePayload() {
        var payload = {
            suburb: val('inp-suburb'),
            type: val('inp-type'),
            period_months: intVal('inp-period') || 12,
        };
        var price = getRawPrice();
        if (price) payload.price = price;
        var size = intVal('inp-size');
        if (size) payload.size_m2 = size;
        var beds = intVal('inp-bedrooms');
        if (beds !== null) payload.bedrooms = beds;
        return payload;
    }

    // ── Card visibility helpers ──────────────────────────────────────
    function showCard(prefix) {
        var empty = $(prefix + '-empty');
        var card  = $(prefix);
        if (empty) empty.classList.add('hidden');
        if (card)  card.classList.remove('hidden');
    }

    // ══════════════════════════════════════════════════════════════════
    //  SIMULATE
    // ══════════════════════════════════════════════════════════════════
    $('btn-simulate').addEventListener('click', function () {
        var price = getRawPrice();
        if (!price || price <= 0) {
            toast('Enter a listing price before simulating.', 'warn');
            return;
        }

        setLoading('btn-simulate', true);

        post('/simulate', basePayload())
            .then(function (d) {
                // Compute delta from last run
                var prevP60 = lastSimResult && lastSimResult.probability ? lastSimResult.probability.p60 : null;
                lastSimResult = d;

                // Card 1: Probability
                var p60 = d.probability ? d.probability.p60 : null;
                var p30 = d.probability ? d.probability.p30 : null;
                var p90 = d.probability ? d.probability.p90 : null;

                $('prob-p60').textContent = fmtPct(p60);
                $('prob-p30').textContent = fmtPct(p30);
                $('prob-p90').textContent = fmtPct(p90);
                $('prob-days').textContent = d.expected_days || '--';
                $('prob-price').textContent = fmtR(d.price_tested);

                // P60 color
                if (p60 !== null) {
                    $('prob-p60').className = 'text-4xl font-bold tabular-nums ' +
                        (p60 >= 0.6 ? 'text-green-400' : p60 >= 0.4 ? 'text-amber-400' : 'text-red-400');
                }

                // Delta indicator
                var deltaEl = $('prob-delta');
                if (prevP60 !== null && p60 !== null) {
                    var diff = Math.round((p60 - prevP60) * 100);
                    if (diff !== 0) {
                        deltaEl.textContent = (diff > 0 ? '+' : '') + diff + 'pp';
                        deltaEl.className = 'text-sm font-medium ' + (diff > 0 ? 'text-green-400' : 'text-red-400');
                        deltaEl.classList.remove('hidden');
                    } else {
                        deltaEl.classList.add('hidden');
                    }
                } else {
                    deltaEl.classList.add('hidden');
                }

                showCard('card-prob');

                // Card 2: Position
                if (d.confidence || d.ppi || d.launch_position) {
                    if (d.confidence) {
                        $('pos-conf-score').textContent = d.confidence.confidence_score || '--';
                        $('pos-conf-grade').textContent = d.confidence.confidence_grade || '--';
                        var grade = d.confidence.confidence_grade || '';
                        var gradeColors = { A: 'text-green-400', B: 'text-blue-400', C: 'text-amber-400' };
                        $('pos-conf-score').className = 'text-2xl font-bold tabular-nums ' + (gradeColors[grade] || 'text-white');
                    }
                    if (d.ppi) {
                        $('pos-ppi-score').textContent = d.ppi.ppi_score || '--';
                        $('pos-ppi-label').textContent = d.ppi.ppi_label || '--';
                        var ppiLabel = d.ppi.ppi_label || '';
                        var ppiColors = { Strong: 'text-green-400', Balanced: 'text-amber-400' };
                        $('pos-ppi-score').className = 'text-2xl font-bold tabular-nums ' + (ppiColors[ppiLabel] || 'text-red-400');
                    }
                    if (d.launch_position) {
                        $('pos-launch').classList.remove('hidden');
                        $('pos-launch-label').textContent = d.launch_position.label || d.launch_position || '--';
                    }
                    showCard('card-pos');
                }

                // Card 3: Market Pressure
                if (d.market) {
                    $('mkt-months').textContent = d.market.months_of_stock !== undefined ? fmtNum(d.market.months_of_stock) : '--';
                    $('mkt-dom').textContent = d.market.median_dom !== undefined ? d.market.median_dom + 'd' : '--';
                    $('mkt-stale').textContent = d.market.stale_percent !== undefined ? Math.round(d.market.stale_percent * 100) + '%' : '--';
                    $('mkt-quality').textContent = d.market.data_quality_avg !== undefined ? fmtNum(d.market.data_quality_avg) : '--';
                    showCard('card-mkt');
                }

                // Card 4: Holding Cost
                if (d.holding_cost && d.holding_cost.monthly_total > 0) {
                    $('hold-monthly').textContent = fmtR(d.holding_cost.monthly_total);
                    $('hold-90').textContent = fmtR(d.holding_cost.ninety_day_total || d.holding_cost.monthly_total * 3);
                    $('hold-delay').textContent = fmtR(d.holding_cost.monthly_total);
                    $('card-hold-none').classList.add('hidden');
                    showCard('card-hold');
                } else {
                    $('card-hold-empty').classList.add('hidden');
                    $('card-hold-none').classList.remove('hidden');
                }

                // Strategy: Key Drivers + Risks
                if (d.explainability) {
                    var driversHtml = '';
                    (d.explainability.key_drivers || []).slice(0, 3).forEach(function (dr) {
                        driversHtml += '<li class="flex items-start gap-2 text-xs text-gray-300"><span class="text-green-500 mt-0.5 shrink-0">+</span>' + escHtml(dr) + '</li>';
                    });
                    $('drivers-list').innerHTML = driversHtml || '<li class="text-xs text-gray-600">None identified</li>';

                    var risksHtml = '';
                    (d.explainability.risk_factors || []).slice(0, 3).forEach(function (r) {
                        risksHtml += '<li class="flex items-start gap-2 text-xs text-gray-300"><span class="text-red-400 mt-0.5 shrink-0">!</span>' + escHtml(r) + '</li>';
                    });
                    $('risks-list').innerHTML = risksHtml || '<li class="text-xs text-gray-600">None identified</li>';

                    $('strategy-drivers').classList.remove('hidden');
                }
            })
            .catch(function (e) {
                if (e.flagOff) {
                    toast(e.message, 'warn');
                } else {
                    toast(e.message || 'Simulation failed.', 'error');
                }
            })
            .finally(function () {
                setLoading('btn-simulate', false);
            });
    });

    // ══════════════════════════════════════════════════════════════════
    //  COMPETITIVE THREATS
    // ══════════════════════════════════════════════════════════════════
    if (features.canThreats) {
        $('btn-threats').addEventListener('click', function () {
            setSecondaryLoading('btn-threats', true);
            var payload = {};
            var price = getRawPrice();
            if (price) payload.price = price;
            var size = intVal('inp-size');
            if (size) payload.size_m2 = size;
            payload.limit = 5;

            post('/competitive-threats', payload)
                .then(function (d) {
                    var threats = d.threats || [];
                    $('threats-count').textContent = '(' + threats.length + ')';
                    if (threats.length === 0) {
                        $('threats-table').innerHTML = '<p class="text-xs text-gray-600 italic">No active competitive listings found.</p>';
                    } else {
                        var html = '<table class="w-full text-xs"><thead><tr class="text-gray-500 border-b border-gray-800">';
                        html += '<th class="text-left py-2 font-medium">#</th>';
                        html += '<th class="text-left py-2 font-medium">Address</th>';
                        html += '<th class="text-right py-2 font-medium">Price</th>';
                        html += '<th class="text-right py-2 font-medium">Score</th>';
                        html += '<th class="text-right py-2 font-medium">DOM</th>';
                        html += '</tr></thead><tbody>';
                        threats.forEach(function (t, i) {
                            html += '<tr class="border-b border-gray-800/50 text-gray-300">';
                            html += '<td class="py-2 text-gray-500">' + (i + 1) + '</td>';
                            html += '<td class="py-2 truncate max-w-[180px]">' + escHtml(t.address || t.suburb || '--') + '</td>';
                            html += '<td class="text-right py-2 tabular-nums">' + fmtR(t.asking_price) + '</td>';
                            html += '<td class="text-right py-2 font-semibold tabular-nums">' + (t.threat_score || '--') + '</td>';
                            html += '<td class="text-right py-2 tabular-nums">' + (t.days_on_market !== null && t.days_on_market !== undefined ? t.days_on_market : '--') + '</td>';
                            html += '</tr>';
                        });
                        html += '</tbody></table>';
                        $('threats-table').innerHTML = html;
                    }
                    $('strategy-threats').classList.remove('hidden');
                })
                .catch(function (e) {
                    if (e.flagOff) {
                        $('btn-threats').disabled = true;
                        features.canThreats = false;
                        toast('Competitive threats feature is not enabled.', 'warn');
                    } else {
                        toast(e.message || 'Threats lookup failed.', 'error');
                    }
                })
                .finally(function () { setSecondaryLoading('btn-threats', false); });
        });
    }

    // ══════════════════════════════════════════════════════════════════
    //  PRICE BAND
    // ══════════════════════════════════════════════════════════════════
    if (features.canPriceBand) {
        $('btn-priceband').addEventListener('click', function () {
            var price = getRawPrice();
            if (!price || price <= 0) {
                toast('Set a price before scanning price bands.', 'warn');
                return;
            }
            setSecondaryLoading('btn-priceband', true);

            post('/price-band', basePayload())
                .then(function (d) {
                    var bandConfig = [
                        { key: 'aggressive', label: 'Aggressive', border: 'border-red-500/30', accent: 'text-red-400' },
                        { key: 'balanced',   label: 'Balanced',   border: 'border-amber-500/30', accent: 'text-amber-400' },
                        { key: 'defensive',  label: 'Defensive',  border: 'border-green-500/30', accent: 'text-green-400' },
                    ];
                    var cardsHtml = '';
                    bandConfig.forEach(function (cfg) {
                        var b = d[cfg.key];
                        cardsHtml += '<div class="bg-gray-900 border ' + cfg.border + ' rounded-2xl p-5 text-center">';
                        cardsHtml += '<p class="text-[10px] uppercase tracking-widest text-gray-500 mb-2">' + cfg.label + '</p>';
                        if (b) {
                            cardsHtml += '<p class="text-xl font-bold ' + cfg.accent + ' tabular-nums">' + fmtR(b.price) + '</p>';
                            cardsHtml += '<p class="text-xs text-gray-400 mt-1">P60: ' + fmtPct(b.p60) + '</p>';
                            if (b.confidence_score !== undefined) {
                                cardsHtml += '<p class="text-xs text-gray-500">Conf: ' + b.confidence_score + '</p>';
                            }
                        } else {
                            cardsHtml += '<p class="text-xl font-bold text-gray-700 tabular-nums">--</p>';
                            cardsHtml += '<p class="text-xs text-gray-600 mt-1">No match</p>';
                        }
                        cardsHtml += '</div>';
                    });
                    $('priceband-cards').innerHTML = cardsHtml;

                    // Scan detail
                    if (d.scan && d.scan.length > 0) {
                        var scanHtml = '<table class="w-full text-xs"><thead><tr class="text-gray-500 border-b border-gray-800">';
                        scanHtml += '<th class="text-right py-2 font-medium">Price</th>';
                        scanHtml += '<th class="text-right py-2 font-medium">P60</th>';
                        scanHtml += '<th class="text-right py-2 font-medium">P90</th>';
                        scanHtml += '<th class="text-right py-2 font-medium">Conf</th>';
                        scanHtml += '</tr></thead><tbody>';
                        d.scan.forEach(function (s) {
                            scanHtml += '<tr class="border-b border-gray-800/50 text-gray-300">';
                            scanHtml += '<td class="text-right py-2 tabular-nums">' + fmtR(s.price) + '</td>';
                            scanHtml += '<td class="text-right py-2 tabular-nums">' + fmtPct(s.p60) + '</td>';
                            scanHtml += '<td class="text-right py-2 tabular-nums">' + fmtPct(s.p90) + '</td>';
                            scanHtml += '<td class="text-right py-2 tabular-nums">' + (s.confidence_score || '--') + '</td>';
                            scanHtml += '</tr>';
                        });
                        scanHtml += '</tbody></table>';
                        $('priceband-scan').innerHTML = scanHtml;
                        $('priceband-scan-wrap').classList.remove('hidden');
                    } else {
                        $('priceband-scan-wrap').classList.add('hidden');
                    }

                    $('strategy-priceband').classList.remove('hidden');
                })
                .catch(function (e) {
                    if (e.flagOff) {
                        $('btn-priceband').disabled = true;
                        features.canPriceBand = false;
                        toast('Price band feature is not enabled.', 'warn');
                    } else {
                        toast(e.message || 'Price band scan failed.', 'error');
                    }
                })
                .finally(function () { setSecondaryLoading('btn-priceband', false); });
        });
    }

    // ══════════════════════════════════════════════════════════════════
    //  TRAJECTORY
    // ══════════════════════════════════════════════════════════════════
    if (features.canTrajectory) {
        $('btn-trajectory').addEventListener('click', function () {
            var price = getRawPrice();
            if (!price || price <= 0) {
                toast('Set a price before running trajectory.', 'warn');
                return;
            }
            setSecondaryLoading('btn-trajectory', true);

            var steps = [];
            for (var pct = 0; pct >= -0.15; pct -= 0.03) {
                steps.push(Math.round(price * (1 + pct)));
            }
            var payload = basePayload();
            delete payload.price;
            payload.price_steps = steps;
            payload.days_per_step = 30;

            post('/simulate-trajectory', payload)
                .then(function (d) {
                    var html = '<table class="w-full text-xs"><thead><tr class="text-gray-500 border-b border-gray-800">';
                    html += '<th class="text-left py-2 font-medium">Stage</th>';
                    html += '<th class="text-right py-2 font-medium">Price</th>';
                    html += '<th class="text-right py-2 font-medium">P60</th>';
                    html += '<th class="text-right py-2 font-medium">Cumulative</th>';
                    html += '<th class="text-right py-2 font-medium">Hold Cost</th>';
                    html += '</tr></thead><tbody>';
                    (d.stages || []).forEach(function (s, i) {
                        html += '<tr class="border-b border-gray-800/50 text-gray-300">';
                        html += '<td class="py-2 text-gray-500">' + (i + 1) + '</td>';
                        html += '<td class="text-right py-2 tabular-nums">' + fmtR(s.price) + '</td>';
                        html += '<td class="text-right py-2 tabular-nums">' + fmtPct(s.stage_probability) + '</td>';
                        html += '<td class="text-right py-2 font-semibold tabular-nums">' + fmtPct(s.cumulative_probability) + '</td>';
                        html += '<td class="text-right py-2 tabular-nums">' + fmtR(s.cumulative_holding_cost) + '</td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table>';

                    html += '<div class="mt-3 pt-3 border-t border-gray-800 flex gap-6 text-xs text-gray-400">';
                    html += '<div><span class="text-gray-500">Final Prob:</span> <span class="text-white font-semibold">' + fmtPct(d.final_cumulative_probability) + '</span></div>';
                    html += '<div><span class="text-gray-500">Total Hold:</span> <span class="text-white font-semibold">' + fmtR(d.total_holding_cost) + '</span></div>';
                    html += '<div><span class="text-gray-500">Total Days:</span> <span class="text-white font-semibold">' + (d.total_days || '--') + '</span></div>';
                    html += '</div>';

                    $('trajectory-table').innerHTML = html;
                    $('strategy-trajectory').classList.remove('hidden');
                })
                .catch(function (e) {
                    if (e.flagOff) {
                        $('btn-trajectory').disabled = true;
                        features.canTrajectory = false;
                        toast('Trajectory feature is not enabled.', 'warn');
                    } else {
                        toast(e.message || 'Trajectory simulation failed.', 'error');
                    }
                })
                .finally(function () { setSecondaryLoading('btn-trajectory', false); });
        });
    }

    // ── HTML escape helper ───────────────────────────────────────────
    function escHtml(str) {
        var div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

})();
</script>

@endsection
