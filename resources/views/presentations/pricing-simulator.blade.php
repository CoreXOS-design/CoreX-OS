@extends('layouts.corex')

@section('corex-content')

{{-- ══════════════════════════════════════════════════════════════════════════
     PAGE HEADER
══════════════════════════════════════════════════════════════════════════ --}}
<div style="background: var(--brand-default, #0b2a4a);" class="rounded-md px-6 py-4 mb-6">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Pricing Simulator</h2>
            <div class="text-sm text-white/60">
                {{ $presentation->title }}
                @if($presentation->property_address)
                    &nbsp;&middot;&nbsp; {{ $presentation->property_address }}
                @endif
            </div>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('presentations.analysis', $presentation) }}"
               class="corex-btn-outline" style="color:#fff; border-color:rgba(255,255,255,0.3); background:transparent;">
                &larr; Analysis
            </a>
            <a href="{{ route('presentations.show', $presentation) }}"
               class="corex-btn-outline" style="color:#fff; border-color:rgba(255,255,255,0.3); background:transparent;">
                &larr; Overview
            </a>
        </div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     CONFIGURATION PANEL
══════════════════════════════════════════════════════════════════════════ --}}
<div class="ds-status-card mb-6" style="border-left-color: var(--ds-cyan);">
    <h2 class="ds-section-header">Configuration</h2>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
            <label class="ds-label block mb-1">Commission %</label>
            <input type="number" id="cfg-commission" value="{{ $config['commission_pct'] ?? 5.0 }}"
                   step="0.5" min="0" max="15"
                   class="pres-input w-full px-3 py-2 text-sm">
        </div>
        <div>
            <label class="ds-label block mb-1">Transfer Cost %</label>
            <input type="number" id="cfg-transfer" value="{{ $config['transfer_cost_pct'] ?? 4.0 }}"
                   step="0.5" min="0" max="10"
                   class="pres-input w-full px-3 py-2 text-sm">
        </div>
        <div>
            <label class="ds-label block mb-1">Monthly Holding Cost</label>
            <div class="flex items-center rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                R {{ number_format($config['monthly_holding_cost'] ?? 0, 0, '.', ' ') }}
            </div>
            <input type="hidden" id="cfg-holding" value="{{ (int)($config['monthly_holding_cost'] ?? 0) }}">
        </div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     STOCK CONTEXT BANNER
══════════════════════════════════════════════════════════════════════════ --}}
@php
    $stock = $analysisData['stock_absorption'] ?? [];
    // Build 8 — verdict line (#X of Y / Zth percentile / position
    // label) reads the canonical competitor_stock-derived position
    // so the simulator agrees with the PDF + agent review screen.
    // The stock-context banner ABOVE this stays on the broad-pool
    // total_active_stock (legitimate market-pool denominator for
    // months-of-supply math).
    $compStock = $analysisData['competitor_stock'] ?? [];
    $pricePosCanon  = $compStock['price_position_canonical'] ?? ['has_data' => false];
    $pricePosLegacy = $analysisData['price_position'] ?? [];
    $pricePos = !empty($pricePosCanon['has_data']) ? $pricePosCanon : $pricePosLegacy;
@endphp
@if(!empty($stock['total_active_stock']) && !empty($stock['monthly_sales']))
@php
    $stockColorClass = match($stock['absorption_color'] ?? '') {
        'green'  => 'bg-emerald-50 border-emerald-200 text-emerald-800',
        'amber'  => 'bg-amber-50 border-amber-200 text-amber-800',
        'orange' => 'bg-orange-50 border-orange-200 text-orange-800',
        'red'    => 'bg-red-50 border-red-200 text-red-800',
        default  => 'border text-inherit',
    };
@endphp
<div class="rounded-md border p-4 mb-6 {{ $stockColorClass }}">
    <div class="flex items-center flex-wrap gap-3 text-sm">
        <span class="font-bold text-lg">{{ $stock['total_active_stock'] }}</span>
        <span>competing listings</span>
        <span class="text-gray-400">|</span>
        <span><strong>{{ $stock['annual_sales'] }}</strong> sales/year ({{ number_format($stock['monthly_sales'], 1) }}/month)</span>
        <span class="text-gray-400">|</span>
        <span><strong>{{ number_format($stock['months_of_supply'], 1) }}</strong> months of supply</span>
        <span class="text-gray-400">|</span>
        <span class="font-semibold">{{ $stock['absorption_label'] }}</span>
    </div>
    @if(!empty($pricePos['has_data']))
    <div class="flex items-center gap-3 text-sm mt-2 pt-2 border-t border-current border-opacity-20">
        <span>Price rank: <strong>#{{ $pricePos['price_rank'] }}</strong> of {{ $pricePos['total_listings'] }}</span>
        <span class="text-gray-400">|</span>
        <span>{{ $pricePos['listings_more_expensive'] }} priced higher, {{ $pricePos['listings_cheaper'] }} lower</span>
        <span class="text-gray-400">|</span>
        <span class="font-semibold">{{ $pricePos['position_label'] }}</span>
    </div>
    @endif
    @if($stock['search_total_count'] && $stock['listings_with_price'] < $stock['search_total_count'])
    <p class="text-xs mt-1 opacity-75">
        {{ $stock['listings_with_price'] }} of {{ $stock['search_total_count'] }} listings have price data &mdash; "Competing" column counts listings with known prices at or below each scenario.
    </p>
    @endif
</div>
@endif

{{-- ══════════════════════════════════════════════════════════════════════════
     SCENARIOS TABLE
══════════════════════════════════════════════════════════════════════════ --}}
<div class="ds-status-card mb-6" style="border-left-color: var(--ds-cyan);">
    <div class="flex items-center justify-between mb-3">
        <h2 class="ds-section-header">Pricing Scenarios</h2>
        <div class="flex gap-2">
            <button id="btn-add-scenario" class="corex-btn-outline text-xs px-3 py-1">+ Add Scenario</button>
            <button id="btn-reset" class="corex-btn-outline text-xs px-3 py-1">Reset to Defaults</button>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="ds-table w-full text-sm" id="scenarios-table">
            <thead>
                <tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-200">
                    <th class="text-left py-2 px-2">Scenario</th>
                    <th class="text-right py-2 px-2">Price</th>
                    <th class="text-right py-2 px-2">Competing</th>
                    <th class="text-right py-2 px-2">Est. Months</th>
                    <th class="text-right py-2 px-2">Holding Cost</th>
                    <th class="text-right py-2 px-2">Commission</th>
                    <th class="text-right py-2 px-2">Transfer</th>
                    <th class="text-right py-2 px-2 font-bold">Net Proceeds</th>
                    <th class="text-right py-2 px-2">vs Asking</th>
                    <th class="text-center py-2 px-2">Probability</th>
                    <th class="py-2 px-1 w-8"></th>
                </tr>
            </thead>
            <tbody id="scenarios-body">
                @foreach($scenarios as $i => $s)
                <tr class="scenario-row border-b border-gray-100 hover:bg-gray-50" data-index="{{ $i }}">
                    <td class="py-2 px-2">
                        <input type="text" class="sc-label border-0 bg-transparent text-sm font-medium text-gray-800 w-full focus:outline-none focus:bg-sky-50 rounded px-1"
                               value="{{ $s['label'] }}">
                    </td>
                    <td class="py-2 px-2 text-right">
                        <input type="number" class="sc-price border border-gray-200 rounded px-2 py-1 text-sm text-right w-28 focus:ring-[#00b4d8] focus:border-[#00b4d8]"
                               value="{{ $s['price'] }}" min="1" step="10000">
                    </td>
                    <td class="py-2 px-2 text-right sc-competing text-gray-700">{{ $s['competing_count'] ?? '—' }}</td>
                    <td class="py-2 px-2 text-right sc-months text-gray-700">{{ $s['est_months'] ?? '—' }}</td>
                    <td class="py-2 px-2 text-right sc-holding text-gray-700">{{ isset($s['holding_cost_total']) ? 'R ' . number_format($s['holding_cost_total'], 0, '.', ' ') : '—' }}</td>
                    <td class="py-2 px-2 text-right sc-commission text-gray-700">{{ isset($s['commission']) ? 'R ' . number_format($s['commission'], 0, '.', ' ') : '—' }}</td>
                    <td class="py-2 px-2 text-right sc-transfer text-gray-700">{{ isset($s['transfer_cost']) ? 'R ' . number_format($s['transfer_cost'], 0, '.', ' ') : '—' }}</td>
                    <td class="py-2 px-2 text-right sc-net font-bold {{ ($s['net_proceeds'] ?? 0) >= 0 ? 'text-emerald-700' : 'text-red-600' }}">
                        {{ isset($s['net_proceeds']) ? 'R ' . number_format($s['net_proceeds'], 0, '.', ' ') : '—' }}
                    </td>
                    <td class="py-2 px-2 text-right sc-vs-asking {{ ($s['vs_asking_net'] ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-500' }}">
                        @if(isset($s['vs_asking_net']))
                            {{ $s['vs_asking_net'] >= 0 ? '+' : '' }}R {{ number_format($s['vs_asking_net'], 0, '.', ' ') }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="py-2 px-2 text-center">
                        @php
                            $probClass = match($s['probability'] ?? '') {
                                'Very Likely' => 'bg-emerald-100 text-emerald-700',
                                'Likely'      => 'bg-green-100 text-green-700',
                                'Possible'    => 'bg-amber-100 text-amber-700',
                                'Unlikely'    => 'bg-orange-100 text-orange-700',
                                default       => 'bg-red-100 text-red-700',
                            };
                        @endphp
                        <span class="sc-prob inline-block text-xs px-2 py-0.5 rounded-full font-medium {{ $probClass }}">
                            {{ $s['probability'] ?? '—' }}
                        </span>
                    </td>
                    <td class="py-2 px-1">
                        <button class="btn-remove-row text-gray-400 hover:text-red-500 text-xs" title="Remove">&times;</button>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     BAR CHART — Net Proceeds Comparison
══════════════════════════════════════════════════════════════════════════ --}}
<div class="ds-status-card mb-6" style="border-left-color: var(--ds-cyan);">
    <h2 class="ds-section-header">Net Proceeds Comparison</h2>
    <div id="bar-chart" class="space-y-2">
        {{-- Rendered by JS --}}
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     NARRATIVE CALLOUT
══════════════════════════════════════════════════════════════════════════ --}}
<div class="rounded-md p-4 mb-6" id="narrative-box" style="background: color-mix(in srgb, var(--brand-icon) 8%, var(--surface)); border: 1px solid color-mix(in srgb, var(--brand-icon) 20%, transparent);">
    <h3 class="text-xs font-semibold uppercase tracking-wide mb-2" style="color: var(--brand-default, #0b2a4a);">Key Insight</h3>
    <p class="text-sm leading-relaxed" id="narrative-text" style="color: var(--text-primary);">{{ $narrative }}</p>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     ACTION BUTTONS
══════════════════════════════════════════════════════════════════════════ --}}
<div class="flex flex-wrap items-center gap-3 mb-8">
    <button id="btn-compute" class="corex-btn-primary">
        Compute Scenarios
    </button>

    <button id="btn-save" class="corex-btn-primary" style="background:#059669;">
        Save Configuration
    </button>

    <label class="flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-primary);">
        <input type="checkbox" id="chk-include-pdf" {{ $includeInPdf ? 'checked' : '' }}
               class="rounded" style="border-color: var(--border); color: var(--brand-button, #0ea5e9);">
        Include in PDF
    </label>

    <a href="{{ route('presentations.pricing-simulator.present', $presentation) }}" target="_blank"
       class="corex-btn-primary">
        Present to Seller &rarr;
    </a>

    <a href="{{ route('presentations.analysis', $presentation) }}"
       class="corex-btn-outline text-sm">
        &larr; Back to Analysis
    </a>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════════════════════════════════════════ --}}
<script>
document.addEventListener('DOMContentLoaded', function() {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const presentationId = @json($presentation->id);
    const baseUrl = '/presentations/' + presentationId;

    // Default scenarios for reset
    const defaultScenarios = @json($defaultScenarios);

    // ── Helpers ─────────────────────────────────────────────────
    function zar(v) {
        if (v == null) return '—';
        const neg = v < 0;
        const formatted = 'R ' + Math.abs(v).toLocaleString('en-ZA', {maximumFractionDigits: 0}).replace(/,/g, ' ');
        return neg ? '-' + formatted : formatted;
    }

    function probClass(prob) {
        switch(prob) {
            case 'Very Likely': return 'bg-emerald-100 text-emerald-700';
            case 'Likely':      return 'bg-green-100 text-green-700';
            case 'Possible':    return 'bg-amber-100 text-amber-700';
            case 'Unlikely':    return 'bg-orange-100 text-orange-700';
            default:            return 'bg-red-100 text-red-700';
        }
    }

    function probBarColor(prob) {
        switch(prob) {
            case 'Very Likely': return '#059669';
            case 'Likely':      return '#16a34a';
            case 'Possible':    return '#d97706';
            case 'Unlikely':    return '#ea580c';
            default:            return '#dc2626';
        }
    }

    function collectConfig() {
        return {
            commission_pct: parseFloat(document.getElementById('cfg-commission').value) || 7.5,
            transfer_cost_pct: parseFloat(document.getElementById('cfg-transfer').value) || 4.0,
            monthly_holding_cost: parseInt(document.getElementById('cfg-holding').value) || 0,
        };
    }

    function collectScenarios() {
        const rows = document.querySelectorAll('#scenarios-body .scenario-row');
        const scenarios = [];
        rows.forEach(row => {
            const label = row.querySelector('.sc-label').value.trim();
            const price = parseInt(row.querySelector('.sc-price').value) || 0;
            if (label && price > 0) {
                scenarios.push({ label, price });
            }
        });
        return scenarios;
    }

    // ── Render bar chart ────────────────────────────────────────
    function renderChart(scenarios) {
        const chart = document.getElementById('bar-chart');
        if (!scenarios || !scenarios.length) {
            chart.innerHTML = '<p class="text-sm text-gray-400">No scenarios computed yet.</p>';
            return;
        }

        const maxNet = Math.max(...scenarios.map(s => Math.max(s.net_proceeds || 0, 0)));
        if (maxNet <= 0) {
            chart.innerHTML = '<p class="text-sm text-gray-400">All scenarios show negative net proceeds.</p>';
            return;
        }

        let html = '';
        scenarios.forEach(s => {
            const widthPct = maxNet > 0 ? Math.max(Math.round((Math.max(s.net_proceeds, 0) / maxNet) * 100), 2) : 2;
            const color = probBarColor(s.probability);
            html += `<div class="flex items-center gap-2">
                <div class="w-28 text-xs text-gray-600 text-right truncate flex-shrink-0">${s.label}</div>
                <div class="flex-1 bg-gray-100 rounded-full h-7 relative overflow-hidden">
                    <div class="h-full rounded-full flex items-center px-2 transition-all duration-300"
                         style="width:${widthPct}%;background:${color};">
                        <span class="text-xs text-white font-medium whitespace-nowrap">${zar(s.net_proceeds)}</span>
                    </div>
                </div>
            </div>`;
        });
        chart.innerHTML = html;
    }

    // ── Update table from computed results ──────────────────────
    function updateTable(scenarios) {
        const tbody = document.getElementById('scenarios-body');
        tbody.innerHTML = '';

        scenarios.forEach((s, i) => {
            const netClass = (s.net_proceeds || 0) >= 0 ? 'text-emerald-700' : 'text-red-600';
            const vsClass = (s.vs_asking_net || 0) >= 0 ? 'text-emerald-600' : 'text-red-500';
            const vsPrefix = s.vs_asking_net != null ? (s.vs_asking_net >= 0 ? '+' : '') : '';
            const pClass = probClass(s.probability);

            const tr = document.createElement('tr');
            tr.className = 'scenario-row border-b border-gray-100 hover:bg-gray-50';
            tr.dataset.index = i;
            tr.innerHTML = `
                <td class="py-2 px-2">
                    <input type="text" class="sc-label border-0 bg-transparent text-sm font-medium text-gray-800 w-full focus:outline-none focus:bg-sky-50 rounded px-1" value="${s.label}">
                </td>
                <td class="py-2 px-2 text-right">
                    <input type="number" class="sc-price border border-gray-200 rounded px-2 py-1 text-sm text-right w-28 focus:ring-[#00b4d8] focus:border-[#00b4d8]" value="${s.price}" min="1" step="10000">
                </td>
                <td class="py-2 px-2 text-right sc-competing text-gray-700">${s.competing_count ?? '—'}</td>
                <td class="py-2 px-2 text-right sc-months text-gray-700">${s.est_months ?? '—'}</td>
                <td class="py-2 px-2 text-right sc-holding text-gray-700">${s.holding_cost_total != null ? zar(s.holding_cost_total) : '—'}</td>
                <td class="py-2 px-2 text-right sc-commission text-gray-700">${s.commission != null ? zar(s.commission) : '—'}</td>
                <td class="py-2 px-2 text-right sc-transfer text-gray-700">${s.transfer_cost != null ? zar(s.transfer_cost) : '—'}</td>
                <td class="py-2 px-2 text-right sc-net font-bold ${netClass}">${s.net_proceeds != null ? zar(s.net_proceeds) : '—'}</td>
                <td class="py-2 px-2 text-right sc-vs-asking ${vsClass}">${s.vs_asking_net != null ? vsPrefix + zar(s.vs_asking_net) : '—'}</td>
                <td class="py-2 px-2 text-center"><span class="sc-prob inline-block text-xs px-2 py-0.5 rounded-full font-medium ${pClass}">${s.probability || '—'}</span></td>
                <td class="py-2 px-1"><button class="btn-remove-row text-gray-400 hover:text-red-500 text-xs" title="Remove">&times;</button></td>
            `;
            tbody.appendChild(tr);
        });

        attachRemoveHandlers();
    }

    // ── Compute ─────────────────────────────────────────────────
    document.getElementById('btn-compute').addEventListener('click', async function() {
        const btn = this;
        btn.disabled = true;
        btn.textContent = 'Computing...';

        try {
            const config = collectConfig();
            const scenarios = collectScenarios();

            const res = await fetch(baseUrl + '/pricing-simulator/compute', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ ...config, scenarios })
            });

            if (!res.ok) throw new Error('Compute failed: ' + res.status);
            const data = await res.json();

            updateTable(data.scenarios);
            renderChart(data.scenarios);
            document.getElementById('narrative-text').textContent = data.narrative;
        } catch(e) {
            alert('Error computing scenarios: ' + e.message);
        } finally {
            btn.disabled = false;
            btn.textContent = 'Compute Scenarios';
        }
    });

    // ── Save ────────────────────────────────────────────────────
    document.getElementById('btn-save').addEventListener('click', async function() {
        const btn = this;
        btn.disabled = true;
        btn.textContent = 'Saving...';

        try {
            const config = collectConfig();
            const scenarios = collectScenarios();

            // Collect full scenario data from the table
            const rows = document.querySelectorAll('#scenarios-body .scenario-row');
            const fullScenarios = [];
            rows.forEach(row => {
                fullScenarios.push({
                    label:             row.querySelector('.sc-label').value.trim(),
                    price:             parseInt(row.querySelector('.sc-price').value) || 0,
                    competing_count:   parseInt(row.querySelector('.sc-competing')?.textContent) || 0,
                    est_months:        parseFloat(row.querySelector('.sc-months')?.textContent) || 0,
                    holding_cost_total: parseZar(row.querySelector('.sc-holding')?.textContent),
                    commission:        parseZar(row.querySelector('.sc-commission')?.textContent),
                    transfer_cost:     parseZar(row.querySelector('.sc-transfer')?.textContent),
                    net_proceeds:      parseZar(row.querySelector('.sc-net')?.textContent),
                    vs_asking_net:     parseZar(row.querySelector('.sc-vs-asking')?.textContent),
                    probability:       row.querySelector('.sc-prob')?.textContent.trim() || '',
                });
            });

            const narrative = document.getElementById('narrative-text').textContent;
            const includeInPdf = document.getElementById('chk-include-pdf').checked;

            const res = await fetch(baseUrl + '/pricing-simulator/save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ config, scenarios: fullScenarios, narrative, include_in_pdf: includeInPdf })
            });

            if (!res.ok) throw new Error('Save failed: ' + res.status);
            btn.textContent = 'Saved!';
            setTimeout(() => { btn.textContent = 'Save Configuration'; }, 2000);
        } catch(e) {
            alert('Error saving: ' + e.message);
        } finally {
            btn.disabled = false;
        }
    });

    function parseZar(text) {
        if (!text || text === '—') return 0;
        return parseInt(text.replace(/[^\d-]/g, '')) || 0;
    }

    // ── Add scenario ────────────────────────────────────────────
    document.getElementById('btn-add-scenario').addEventListener('click', function() {
        const rows = document.querySelectorAll('#scenarios-body .scenario-row');
        if (rows.length >= 8) { alert('Maximum 8 scenarios.'); return; }

        const idx = rows.length;
        const tr = document.createElement('tr');
        tr.className = 'scenario-row border-b border-gray-100 hover:bg-gray-50';
        tr.dataset.index = idx;
        tr.innerHTML = `
            <td class="py-2 px-2">
                <input type="text" class="sc-label border-0 bg-transparent text-sm font-medium text-gray-800 w-full focus:outline-none focus:bg-sky-50 rounded px-1" value="Custom ${idx + 1}" placeholder="Label">
            </td>
            <td class="py-2 px-2 text-right">
                <input type="number" class="sc-price border border-gray-200 rounded px-2 py-1 text-sm text-right w-28 focus:ring-[#00b4d8] focus:border-[#00b4d8]" value="" min="1" step="10000" placeholder="Price">
            </td>
            <td class="py-2 px-2 text-right sc-competing text-gray-700">—</td>
            <td class="py-2 px-2 text-right sc-months text-gray-700">—</td>
            <td class="py-2 px-2 text-right sc-holding text-gray-700">—</td>
            <td class="py-2 px-2 text-right sc-commission text-gray-700">—</td>
            <td class="py-2 px-2 text-right sc-transfer text-gray-700">—</td>
            <td class="py-2 px-2 text-right sc-net font-bold text-gray-700">—</td>
            <td class="py-2 px-2 text-right sc-vs-asking text-gray-700">—</td>
            <td class="py-2 px-2 text-center"><span class="sc-prob inline-block text-xs px-2 py-0.5 rounded-full font-medium bg-gray-100 text-gray-500">—</span></td>
            <td class="py-2 px-1"><button class="btn-remove-row text-gray-400 hover:text-red-500 text-xs" title="Remove">&times;</button></td>
        `;
        document.getElementById('scenarios-body').appendChild(tr);
        attachRemoveHandlers();
    });

    // ── Reset to defaults ───────────────────────────────────────
    document.getElementById('btn-reset').addEventListener('click', function() {
        if (!confirm('Reset scenarios to defaults? Unsaved changes will be lost.')) return;

        // Build default rows from defaultScenarios (only label + price)
        const tbody = document.getElementById('scenarios-body');
        tbody.innerHTML = '';

        defaultScenarios.forEach((s, i) => {
            const tr = document.createElement('tr');
            tr.className = 'scenario-row border-b border-gray-100 hover:bg-gray-50';
            tr.dataset.index = i;
            tr.innerHTML = `
                <td class="py-2 px-2">
                    <input type="text" class="sc-label border-0 bg-transparent text-sm font-medium text-gray-800 w-full focus:outline-none focus:bg-sky-50 rounded px-1" value="${s.label}">
                </td>
                <td class="py-2 px-2 text-right">
                    <input type="number" class="sc-price border border-gray-200 rounded px-2 py-1 text-sm text-right w-28 focus:ring-[#00b4d8] focus:border-[#00b4d8]" value="${s.price}" min="1" step="10000">
                </td>
                <td class="py-2 px-2 text-right sc-competing text-gray-700">—</td>
                <td class="py-2 px-2 text-right sc-months text-gray-700">—</td>
                <td class="py-2 px-2 text-right sc-holding text-gray-700">—</td>
                <td class="py-2 px-2 text-right sc-commission text-gray-700">—</td>
                <td class="py-2 px-2 text-right sc-transfer text-gray-700">—</td>
                <td class="py-2 px-2 text-right sc-net font-bold text-gray-700">—</td>
                <td class="py-2 px-2 text-right sc-vs-asking text-gray-700">—</td>
                <td class="py-2 px-2 text-center"><span class="sc-prob inline-block text-xs px-2 py-0.5 rounded-full font-medium bg-gray-100 text-gray-500">—</span></td>
                <td class="py-2 px-1"><button class="btn-remove-row text-gray-400 hover:text-red-500 text-xs" title="Remove">&times;</button></td>
            `;
            tbody.appendChild(tr);
        });

        attachRemoveHandlers();
        document.getElementById('bar-chart').innerHTML = '<p class="text-sm text-gray-400">Click "Compute Scenarios" to generate results.</p>';
        document.getElementById('narrative-text').textContent = 'Click "Compute Scenarios" to generate insights.';
    });

    // ── Remove row handlers ─────────────────────────────────────
    function attachRemoveHandlers() {
        document.querySelectorAll('.btn-remove-row').forEach(btn => {
            btn.onclick = function() {
                const rows = document.querySelectorAll('#scenarios-body .scenario-row');
                if (rows.length <= 1) { alert('At least one scenario is required.'); return; }
                this.closest('.scenario-row').remove();
            };
        });
    }
    attachRemoveHandlers();

    // ── Initial chart render ────────────────────────────────────
    renderChart(@json($scenarios));
});
</script>

@endsection
