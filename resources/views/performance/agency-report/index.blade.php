@extends('layouts.corex-app')

@section('title', 'Agency Performance & ROI')

@section('corex-content')
@php
    // Shape cc6's rollup for the client-side sort/filter/drilldown component (AT-366 frontend, cc1).
    $branchRows = collect($report['branches'])
        ->map(fn ($b, $k) => ['key' => (string) $k, 'label' => $b['label'], 'metrics' => $b['metrics'], 'deal_status' => $b['deal_status'] ?? null])
        ->values()->all();
    $agentRows  = $report['agents'];
    $metricMeta = $report['metrics'];
    $branchUrlBase = route('performance.agency-report.branch', ['branch' => '__KEY__', 'period' => $preset]);
    $agentUrlBase  = route('performance.agency-report.agent', ['user' => '__UID__', 'period' => $preset]);
    // Custom-range params flow through to the drilldown endpoint (contract §B). Built as a
    // plain path (not route()) so the view never depends on cc6's endpoint being registered
    // yet — the modal degrades to "coming soon" on 404 until cc6 lands the route.
    $drillQuery = array_filter(['period' => $preset, 'start' => request('start'), 'end' => request('end')]);
    $drilldownBase = url('/corex/performance/agency-report/drilldown') . '?' . http_build_query($drillQuery);
    $hasCustomDates = request()->filled('start') || request()->filled('end');
@endphp
<div class="p-4 lg:p-6 space-y-5 max-w-full"
     x-data="agencyReport({
        branches: {{ Illuminate\Support\Js::from($branchRows) }},
        agents: {{ Illuminate\Support\Js::from($agentRows) }},
        metrics: {{ Illuminate\Support\Js::from($metricMeta) }},
        company: {{ Illuminate\Support\Js::from($report['company']) }},
        companyStatus: {{ Illuminate\Support\Js::from($report['company']['deal_status'] ?? null) }},
        branchUrlBase: @js($branchUrlBase),
        agentUrlBase: @js($agentUrlBase),
        drilldownBase: @js($drilldownBase),
        currentPreset: @js($preset),
        hasCustomDates: {{ $hasCustomDates ? 'true' : 'false' }},
        defaultUrl: @js(route('performance.agency-report'))
     })">

    {{-- STICKY TOP BLOCK — period selector + deal-status toggles stay pinned while the long
         agent list scrolls beneath (fix #2). Everything from here down to (not incl.) Company. --}}
    <div x-ref="topBlock" class="sticky top-0 z-30 -mx-4 lg:-mx-6 px-4 lg:px-6 pt-1 pb-3 space-y-3"
         style="background:var(--bg,#f4f6fb); border-bottom:1px solid var(--border);">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div class="min-w-0">
                <h1 class="text-lg lg:text-xl font-bold truncate" style="color:var(--text-primary);">Agency Performance &amp; ROI</h1>
                <p class="text-xs" style="color:var(--text-muted);">
                    {{ $report['period']['label'] }} · {{ ucfirst($report['scope']['level']) }} view
                </p>
            </div>

            <div class="flex items-center gap-2 flex-wrap print:hidden">
                {{-- #3 Reset view — back to default period (this month) + clear sort/filter/toggles --}}
                <button type="button" @click="resetView()"
                        class="text-[11px] px-3 py-2 rounded"
                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                        title="Reset period, sort, filters and toggles to default">↺ Reset view</button>
                <a href="{{ route('performance.agency-report.print', ['period' => $preset]) }}" target="_blank"
                   class="text-[11px] px-3 py-2 rounded no-underline"
                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                   title="Print the whole-company report">🖨 Print report</a>
                {{-- Period selector (cc5-owned partial — DO NOT edit here, only include it) --}}
                @include('performance.agency-report._period-selector', ['preset' => $preset, 'presets' => $presets])
            </div>
        </div>

        @if(session('period_error'))
            <div class="text-xs px-3 py-2 rounded" style="background:#fee; color:#900;">{{ session('period_error') }}</div>
        @endif

        {{-- #6 DEAL-STATUS TOGGLES — live QTY + VALUE recompute from cc6's per-status data.
             Hidden gracefully until cc6 ships `deal_status` on the rollup (contract §A). --}}
        <div x-show="hasDealStatus" x-cloak class="rounded p-3" style="background:var(--surface-2); border:1px solid var(--border);">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div class="flex items-center gap-3 flex-wrap" role="group" aria-label="Deal status filter">
                    <span class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Deals</span>
                    <label class="text-[11px] flex items-center gap-1" style="color:var(--text-primary);">
                        <input type="checkbox" @change="toggleAll($event.target.checked)" :checked="allStatusesOn()"> All
                    </label>
                    <template x-for="st in statusKeys" :key="st">
                        <label class="text-[11px] flex items-center gap-1 capitalize" style="color:var(--text-primary);">
                            <input type="checkbox" x-model="statusOn[st]"> <span x-text="st"></span>
                        </label>
                    </template>
                </div>
                <div class="flex items-center gap-6 flex-wrap">
                    <div class="text-right">
                        <div class="text-xl font-bold" style="color:var(--text-primary);" x-text="statusQty().toLocaleString()"></div>
                        <div class="text-[10px]" style="color:var(--text-muted);">deals (selected)</div>
                    </div>
                    <div class="text-right">
                        <div class="text-xl font-bold" style="color:var(--text-primary);" x-text="'R ' + statusValue().toLocaleString()"></div>
                        <div class="text-[10px]" style="color:var(--text-muted);">value (selected)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Company totals — each card drills into its detail (contract §B) --}}
    <div>
        <h2 class="text-xs font-bold uppercase tracking-widest mb-2" style="color:var(--text-muted);">Company</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            @foreach($report['metrics'] as $m)
                <button type="button" @click="drill('{{ $m['key'] }}', 'company', null, @js($m['label']))"
                        class="rounded p-3 text-left" style="background:var(--surface-2); border:1px solid var(--border); cursor:pointer;"
                        title="Click to see the detail">
                    <div class="text-xl font-bold" style="color:var(--text-primary);">{{ $report['company'][$m['key']] ?? 0 }}</div>
                    <div class="text-[11px]" style="color:var(--text-muted);">{{ $m['label'] }}</div>
                </button>
            @endforeach
        </div>
    </div>

    {{-- AT-366-E — company buyer-activity summary --}}
    @includeWhen(isset($buyer), 'performance.agency-report._buyer-summary')

    {{-- Branch rollup (sortable, drillable) — contained horizontal scroll (fix #1) --}}
    <div>
        <h2 class="text-xs font-bold uppercase tracking-widest mb-2" style="color:var(--text-muted);">By branch</h2>
        <div class="overflow-x-auto rounded max-w-full" style="border:1px solid var(--border);">
            <table class="w-full text-[11px] report-metric-table" style="border-collapse:collapse;">
                <thead>
                    <tr style="background:var(--surface-2);">
                        <th class="text-left px-2 py-1.5 cursor-pointer select-none text-[10px] uppercase tracking-wide" @click="sortBranch('label')" :aria-sort="ariaBranch('label')" style="color:var(--text-muted);">
                            Branch <span x-text="branchArrow('label')"></span>
                        </th>
                        <template x-for="m in metrics" :key="m.key">
                            <th :class="isMoney(m.key) ? 'metric-th metric-money' : 'metric-th metric-qty'" @click="sortBranch(m.key)" :aria-sort="ariaBranch(m.key)">
                                <div :class="isMoney(m.key) ? '' : 'th-rot'"><span x-text="m.label"></span> <span x-text="branchArrow(m.key)"></span></div>
                            </th>
                        </template>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="b in branchDisplay()" :key="b.key">
                        <tr style="border-top:1px solid var(--border);">
                            <td class="px-2 py-1.5 whitespace-nowrap">
                                <a :href="branchUrl(b.key)" class="no-underline" style="color:var(--brand, #3b82f6);" x-text="b.label"></a>
                            </td>
                            <template x-for="m in metrics" :key="m.key">
                                <td :class="isMoney(m.key) ? 'num-money' : 'num-qty'"
                                    @click="drill(m.key, 'branch', b.key, m.label + ' — ' + b.label)"
                                    x-text="isMoney(m.key) ? fmtMoney(b.metrics[m.key]) : fmt(b.metrics[m.key])"></td>
                            </template>
                        </tr>
                    </template>
                    <tr x-show="branchDisplay().length === 0">
                        <td class="px-2 py-3 text-center" style="color:var(--text-muted);" :colspan="metrics.length + 1">No branches in scope.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Per-agent table with filter toolbar (sortable, drillable) --}}
    <div>
        <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
            <h2 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">By agent</h2>
            <div class="flex items-center gap-2 flex-wrap print:hidden" role="group" aria-label="Agent filters">
                <input type="search" x-model="search" placeholder="Filter agent…" aria-label="Filter by agent name"
                       class="text-[11px] px-2 py-1 rounded" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <select x-model="branchFilter" aria-label="Filter by branch"
                        class="text-[11px] px-2 py-1 rounded" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    <option value="">All branches</option>
                    <template x-for="opt in branchOptions()" :key="opt.id">
                        <option :value="opt.id" x-text="opt.label"></option>
                    </template>
                </select>
                <label class="text-[11px] flex items-center gap-1" style="color:var(--text-muted);">
                    Min activity
                    <input type="number" min="0" x-model.number="minActivity" aria-label="Minimum total activity"
                           class="text-[11px] px-2 py-1 rounded w-16" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                </label>
                <button type="button" @click="resetFilters()" x-show="search || branchFilter || minActivity > 0"
                        class="text-[11px] px-2 py-1 rounded" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-muted);">Clear</button>
                <span class="text-[11px]" style="color:var(--text-muted);" x-text="agentDisplay().length + ' / ' + agents.length"></span>
            </div>
        </div>
        {{-- Sticky column headers: the by-agent grid is a bounded box that pins flush BELOW the
             sticky top block (top = the measured --report-top-h CSS var, no magic number) and
             scrolls internally, so the column-header row (sticky top:0 inside the box, theme-aware
             --surface-2 bg) stays visible while the agent rows scroll. Contained horizontal scroll too. --}}
        <style>
            /* Sticky by-agent column headers (pin under the top block) */
            .report-agent-scroll thead th { position: sticky; top: 0; z-index: 10; background: var(--surface-2); }
            /* Column-fit (both tables): narrow, vertically-rotated QTY headers so ~5-digit counts
               fit on a standard desktop width. Identity + money columns stay horizontal + wide. */
            .report-metric-table .metric-th { cursor: pointer; user-select: none; vertical-align: bottom; color: var(--text-muted); font-size: 10px; letter-spacing: .02em; text-transform: uppercase; }
            .report-metric-table .metric-qty { width: 2.5rem; min-width: 2.5rem; padding: .375rem .25rem; text-align: center; }
            .report-metric-table .metric-qty .th-rot { writing-mode: vertical-rl; transform: rotate(180deg); white-space: nowrap; display: inline-block; text-align: left; line-height: 1.1; }
            .report-metric-table .metric-money { min-width: 6rem; padding: .375rem .5rem; text-align: right; white-space: normal; }
            .report-metric-table td.num-qty { text-align: right; white-space: nowrap; padding: .375rem .25rem; color: var(--text-primary); cursor: pointer; }
            .report-metric-table td.num-money { text-align: right; white-space: nowrap; padding: .375rem .5rem; color: var(--text-primary); cursor: pointer; font-variant-numeric: tabular-nums; }
        </style>
        <div class="report-agent-scroll rounded max-w-full"
             style="border:1px solid var(--border); background:var(--bg,#f4f6fb);
                    position:sticky; top:var(--report-top-h, 0px); z-index:20;
                    max-height:calc(100vh - var(--report-top-h, 0px) - 1.5rem); overflow:auto;">
            <table class="w-full text-[11px] report-metric-table" style="border-collapse:collapse;">
                <thead>
                    <tr style="background:var(--surface-2);">
                        <th class="text-left px-2 py-1.5 cursor-pointer select-none text-[10px] uppercase tracking-wide" @click="sortAgent('name')" :aria-sort="ariaAgent('name')" style="color:var(--text-muted);">
                            Agent <span x-text="agentArrow('name')"></span>
                        </th>
                        <th class="text-left px-2 py-1.5 cursor-pointer select-none text-[10px] uppercase tracking-wide" @click="sortAgent('branch')" :aria-sort="ariaAgent('branch')" style="color:var(--text-muted);">
                            Branch <span x-text="agentArrow('branch')"></span>
                        </th>
                        <template x-for="m in metrics" :key="m.key">
                            <th :class="isMoney(m.key) ? 'metric-th metric-money' : 'metric-th metric-qty'" @click="sortAgent(m.key)" :aria-sort="ariaAgent(m.key)">
                                <div :class="isMoney(m.key) ? '' : 'th-rot'"><span x-text="m.label"></span> <span x-text="agentArrow(m.key)"></span></div>
                            </th>
                        </template>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="a in agentDisplay()" :key="a.user_id">
                        <tr style="border-top:1px solid var(--border);">
                            <td class="px-2 py-1.5 whitespace-nowrap">
                                <a :href="agentUrl(a.user_id)" class="no-underline" style="color:var(--brand, #3b82f6);" x-text="a.name"></a>
                            </td>
                            <td class="px-2 py-1.5 whitespace-nowrap" style="color:var(--text-muted);" x-text="a.branch_label"></td>
                            <template x-for="m in metrics" :key="m.key">
                                <td :class="isMoney(m.key) ? 'num-money' : 'num-qty'"
                                    @click="drill(m.key, 'agent', a.user_id, m.label + ' — ' + a.name)"
                                    x-text="isMoney(m.key) ? fmtMoney(a.metrics[m.key]) : fmt(a.metrics[m.key])"></td>
                            </template>
                        </tr>
                    </template>
                    <tr x-show="agentDisplay().length === 0">
                        <td class="px-2 py-3 text-center" style="color:var(--text-muted);" :colspan="metrics.length + 2">No agents match the current filters.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-[10px]" style="color:var(--text-muted);">
        Agency Performance &amp; ROI — {{ count($report['metrics']) }} metrics rolled up agent → branch → company for the selected period, with point-in-time branch attribution.
    </p>

    {{-- #9 DRILLDOWN MODAL — "click to see". Fetches cc6's endpoint (contract §B) and renders columns+rows. --}}
    <div x-show="drillOpen" x-cloak @keydown.escape.window="closeDrill()"
         class="fixed inset-0 z-50 flex items-start justify-center p-4 md:p-10 print:hidden"
         style="background:rgba(0,0,0,.45);" @click.self="closeDrill()">
        <div class="w-full max-w-4xl rounded shadow-lg max-h-[85vh] overflow-hidden flex flex-col"
             style="background:var(--surface-1, #fff); border:1px solid var(--border);" role="dialog" aria-modal="true" aria-label="Detail">
            <div class="flex items-center justify-between px-4 py-3" style="border-bottom:1px solid var(--border);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);" x-text="drillTitle || 'Detail'"></h3>
                <button type="button" @click="closeDrill()" class="text-lg leading-none px-2" style="color:var(--text-muted);" aria-label="Close">&times;</button>
            </div>
            <div class="overflow-auto p-4">
                <div x-show="drillLoading" class="text-xs py-8 text-center" style="color:var(--text-muted);">Loading…</div>
                <div x-show="!drillLoading && drillError" class="text-xs py-8 text-center" style="color:var(--text-muted);">
                    <span x-text="drillError"></span>
                </div>
                <div x-show="!drillLoading && !drillError && drillRows.length === 0" class="text-xs py-8 text-center" style="color:var(--text-muted);">
                    Nothing to show for this figure in the selected period.
                </div>
                <table x-show="!drillLoading && !drillError && drillRows.length > 0" class="w-full text-xs">
                    <thead>
                        <tr style="background:var(--surface-2);">
                            <template x-for="c in drillColumns" :key="c.key">
                                <th class="px-3 py-2" :class="c.align === 'right' ? 'text-right' : 'text-left'" style="color:var(--text-muted);" x-text="c.label"></th>
                            </template>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, i) in drillRows" :key="i">
                            <tr style="border-top:1px solid var(--border);">
                                <template x-for="c in drillColumns" :key="c.key">
                                    <td class="px-3 py-2" :class="c.align === 'right' ? 'text-right' : 'text-left'" style="color:var(--text-primary);">
                                        <template x-if="c.key === drillColumns[0].key && row.href">
                                            <a :href="row.href" class="no-underline" style="color:var(--brand, #3b82f6);" x-text="cell(row, c)"></a>
                                        </template>
                                        <template x-if="!(c.key === drillColumns[0].key && row.href)">
                                            <span x-text="cell(row, c)"></span>
                                        </template>
                                    </td>
                                </template>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
/* AT-366 report frontend (cc1) — sort/filter (#7), status toggles (#6), drilldown (#9),
   sticky header + reset view (layout fixes). Pure presentation over cc6's already-agency-scoped
   rollup. Endpoints per .ai/specs/at366-report-frontend-contract.md; degrades gracefully. */
function agencyReport(cfg) {
    return {
        branches: cfg.branches || [],
        agents: cfg.agents || [],
        metrics: cfg.metrics || [],
        company: cfg.company || {},
        companyStatus: cfg.companyStatus || null,
        branchUrlBase: cfg.branchUrlBase,
        agentUrlBase: cfg.agentUrlBase,
        drilldownBase: cfg.drilldownBase,
        currentPreset: cfg.currentPreset || 'this_month',
        hasCustomDates: !!cfg.hasCustomDates,
        defaultUrl: cfg.defaultUrl,

        // Measure the sticky top block's height into a CSS var (--report-top-h) so the by-agent
        // column headers pin flush directly beneath it at every scroll position / viewport /
        // light-or-dark mode — driven by the real measured height, never a brittle magic number.
        init() {
            const root = this.$el;
            const measure = () => {
                const tb = this.$refs.topBlock;
                if (tb && root) root.style.setProperty('--report-top-h', tb.offsetHeight + 'px');
            };
            this.$nextTick(measure);
            if (window.ResizeObserver) {
                this._ro = new ResizeObserver(measure);
                this.$nextTick(() => { if (this.$refs.topBlock) this._ro.observe(this.$refs.topBlock); });
            }
            window.addEventListener('resize', measure);
        },

        // ---- #7 sort / filter ----
        bSort: 'label', bDir: 'asc', aSort: 'name', aDir: 'asc',
        search: '', branchFilter: '', minActivity: 0,

        fmt(v) { return Number(v ?? 0).toLocaleString(); },
        // Money columns (commission / any deal value) stay wide + horizontal; everything else is a
        // narrow rotated qty column. Key-based so it needs no extra flag from cc6's metric metadata.
        isMoney(key) { return /commission|value|gross|amount|revenue|rand|_zar/i.test(key || ''); },
        fmtMoney(v) { return 'R ' + Number(v ?? 0).toLocaleString(); },
        branchUrl(key) { return this.branchUrlBase.replace('__KEY__', encodeURIComponent(key)); },
        agentUrl(id)  { return this.agentUrlBase.replace('__UID__', encodeURIComponent(id)); },
        _val(row, key, labelKey) {
            if (key === labelKey) return (row[labelKey] || '').toString().toLowerCase();
            if (key === 'branch') return (row.branch_label || '').toString().toLowerCase();
            return Number(row.metrics ? (row.metrics[key] ?? 0) : 0);
        },
        _cmp(a, b, key, dir, labelKey) {
            const va = this._val(a, key, labelKey), vb = this._val(b, key, labelKey);
            let r = (typeof va === 'string' || typeof vb === 'string') ? String(va).localeCompare(String(vb)) : va - vb;
            return dir === 'asc' ? r : -r;
        },
        _toggle(cur, key, curDir, textKeys) {
            if (cur === key) return [key, curDir === 'asc' ? 'desc' : 'asc'];
            return [key, textKeys.includes(key) ? 'asc' : 'desc'];
        },
        sortBranch(key) { [this.bSort, this.bDir] = this._toggle(this.bSort, key, this.bDir, ['label']); },
        branchArrow(key) { return this.bSort === key ? (this.bDir === 'asc' ? '▲' : '▼') : ''; },
        ariaBranch(key) { return this.bSort === key ? (this.bDir === 'asc' ? 'ascending' : 'descending') : 'none'; },
        branchDisplay() { return [...this.branches].sort((a, b) => this._cmp(a, b, this.bSort, this.bDir, 'label')); },
        sortAgent(key) { [this.aSort, this.aDir] = this._toggle(this.aSort, key, this.aDir, ['name', 'branch']); },
        agentArrow(key) { return this.aSort === key ? (this.aDir === 'asc' ? '▲' : '▼') : ''; },
        ariaAgent(key) { return this.aSort === key ? (this.aDir === 'asc' ? 'ascending' : 'descending') : 'none'; },
        _agentTotal(a) { return this.metrics.reduce((s, m) => s + Number(a.metrics ? (a.metrics[m.key] ?? 0) : 0), 0); },
        agentFiltered() {
            const q = this.search.trim().toLowerCase(), bf = String(this.branchFilter), min = Number(this.minActivity) || 0;
            return this.agents.filter(a => {
                if (q && !(a.name || '').toLowerCase().includes(q)) return false;
                if (bf !== '' && String(a.branch_id ?? '') !== bf) return false;
                if (min > 0 && this._agentTotal(a) < min) return false;
                return true;
            });
        },
        agentDisplay() { return this.agentFiltered().sort((a, b) => this._cmp(a, b, this.aSort, this.aDir, 'name')); },
        branchOptions() {
            const seen = new Map();
            this.agents.forEach(a => { if (a.branch_id != null && !seen.has(String(a.branch_id))) seen.set(String(a.branch_id), a.branch_label || '—'); });
            return [...seen.entries()].map(([id, label]) => ({ id, label })).sort((x, y) => x.label.localeCompare(y.label));
        },
        resetFilters() { this.search = ''; this.branchFilter = ''; this.minActivity = 0; },

        // ---- #3 reset view: default period (this month) + clear all sort/filter/toggle state ----
        resetView() {
            this.bSort = 'label'; this.bDir = 'asc'; this.aSort = 'name'; this.aDir = 'asc';
            this.resetFilters();
            this.statusKeys.forEach(k => this.statusOn[k] = true);
            this.closeDrill();
            // Default period is "this month". If we're on any other period (or a custom range),
            // reload the report at its default URL so the server-rendered period resets too.
            if (this.currentPreset !== 'this_month' || this.hasCustomDates) {
                window.location.href = this.defaultUrl;
            }
        },

        // ---- #6 deal-status toggles ----
        statusKeys: ['pending', 'granted', 'registered', 'declined'],
        statusOn: { pending: true, granted: true, registered: true, declined: true },
        get hasDealStatus() { return !!this.companyStatus; },
        allStatusesOn() { return this.statusKeys.every(k => this.statusOn[k]); },
        toggleAll(on) { this.statusKeys.forEach(k => this.statusOn[k] = on); },
        statusQty() {
            if (!this.companyStatus) return 0;
            return this.statusKeys.reduce((s, k) => s + (this.statusOn[k] ? Number(this.companyStatus[k]?.qty ?? 0) : 0), 0);
        },
        statusValue() {
            if (!this.companyStatus) return 0;
            return this.statusKeys.reduce((s, k) => s + (this.statusOn[k] ? Number(this.companyStatus[k]?.value ?? 0) : 0), 0);
        },

        // ---- #9 drilldown modal ----
        drillOpen: false, drillLoading: false, drillError: '', drillTitle: '',
        drillColumns: [], drillRows: [],
        drill(metric, level, id, title) {
            this.drillOpen = true; this.drillLoading = true; this.drillError = '';
            this.drillTitle = title || metric; this.drillColumns = []; this.drillRows = [];
            const sep = this.drilldownBase.includes('?') ? '&' : '?';
            let url = this.drilldownBase + sep + 'metric=' + encodeURIComponent(metric) + '&level=' + encodeURIComponent(level);
            if (id !== null && id !== undefined && id !== '') url += '&id=' + encodeURIComponent(id);
            fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => { if (!r.ok) throw new Error(r.status === 404 ? 'Detail view is coming soon.' : 'Could not load the detail (' + r.status + ').'); return r.json(); })
                .then(d => { this.drillTitle = d.title || this.drillTitle; this.drillColumns = d.columns || []; this.drillRows = d.rows || []; })
                .catch(e => { this.drillError = e.message || 'Could not load the detail.'; })
                .finally(() => { this.drillLoading = false; });
        },
        closeDrill() { this.drillOpen = false; },
        cell(row, c) {
            const v = row[c.key];
            if (v === null || v === undefined || v === '') return '—';
            if (c.format === 'currency') return 'R ' + Number(v).toLocaleString();
            if (c.format === 'number') return Number(v).toLocaleString();
            return v;
        },
    };
}
</script>
@endsection
