@extends('layouts.corex-app')

@section('title', 'Agency Performance & ROI')

@section('corex-content')
@php
    // Shape cc6's rollup for the client-side sort/filter component (AT-366 frontend, cc1).
    // Branch rollup is keyed assoc → flatten to a list carrying its route key.
    $branchRows = collect($report['branches'])
        ->map(fn ($b, $k) => ['key' => (string) $k, 'label' => $b['label'], 'metrics' => $b['metrics']])
        ->values()->all();
    $agentRows = $report['agents'];
    $metricMeta = $report['metrics'];
    // Route templates with a placeholder the component swaps per-row (keeps deep-link + period).
    $branchUrlBase = route('performance.agency-report.branch', ['branch' => '__KEY__', 'period' => $preset]);
    $agentUrlBase  = route('performance.agency-report.agent', ['user' => '__UID__', 'period' => $preset]);
@endphp
<div class="p-6 space-y-6" x-data>
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-xl font-bold" style="color:var(--text-primary);">Agency Performance &amp; ROI</h1>
            <p class="text-xs" style="color:var(--text-muted);">
                {{ $report['period']['label'] }} · {{ ucfirst($report['scope']['level']) }} view
            </p>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
            {{-- #8 whole-company print --}}
            <a href="{{ route('performance.agency-report.print', ['period' => $preset]) }}" target="_blank"
               class="text-[11px] px-3 py-2 rounded no-underline print:hidden"
               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
               title="Print the whole-company report">🖨 Print report</a>

            {{-- Period selector (cc5-owned partial — DO NOT edit here) --}}
            @include('performance.agency-report._period-selector', ['preset' => $preset, 'presets' => $presets])
        </div>
    </div>

    @if(session('period_error'))
        <div class="text-xs px-3 py-2 rounded" style="background:#fee; color:#900;">{{ session('period_error') }}</div>
    @endif

    {{-- Company totals --}}
    <div>
        <h2 class="text-xs font-bold uppercase tracking-widest mb-2" style="color:var(--text-muted);">Company</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            @foreach($report['metrics'] as $m)
                <div class="rounded p-4" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div class="text-2xl font-bold" style="color:var(--text-primary);">{{ $report['company'][$m['key']] ?? 0 }}</div>
                    <div class="text-[11px]" style="color:var(--text-muted);">{{ $m['label'] }}</div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- AT-366-E — company buyer-activity summary --}}
    @includeWhen(isset($buyer), 'performance.agency-report._buyer-summary')

    {{-- Branch rollup + agent table: one Alpine component drives sort + filter (cc1 AT-366 frontend, #7). --}}
    <div x-data="agencyReportTable({
            branches: {{ Illuminate\Support\Js::from($branchRows) }},
            agents: {{ Illuminate\Support\Js::from($agentRows) }},
            metrics: {{ Illuminate\Support\Js::from($metricMeta) }},
            branchUrlBase: @js($branchUrlBase),
            agentUrlBase: @js($agentUrlBase)
         })">

        {{-- Branch rollup --}}
        <div>
            <h2 class="text-xs font-bold uppercase tracking-widest mb-2" style="color:var(--text-muted);">By branch</h2>
            <div class="overflow-x-auto rounded" style="border:1px solid var(--border);">
                <table class="w-full text-xs">
                    <thead>
                        <tr style="background:var(--surface-2);">
                            <th class="text-left px-3 py-2 cursor-pointer select-none" @click="sortBranch('label')" :aria-sort="ariaBranch('label')" style="color:var(--text-muted);">
                                Branch <span x-text="branchArrow('label')"></span>
                            </th>
                            <template x-for="m in metrics" :key="m.key">
                                <th class="text-right px-3 py-2 cursor-pointer select-none" @click="sortBranch(m.key)" :aria-sort="ariaBranch(m.key)" style="color:var(--text-muted);">
                                    <span x-text="m.label"></span> <span x-text="branchArrow(m.key)"></span>
                                </th>
                            </template>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="b in branchDisplay()" :key="b.key">
                            <tr style="border-top:1px solid var(--border);">
                                <td class="px-3 py-2">
                                    <a :href="branchUrl(b.key)" class="no-underline" style="color:var(--brand, #3b82f6);" x-text="b.label"></a>
                                </td>
                                <template x-for="m in metrics" :key="m.key">
                                    <td class="text-right px-3 py-2" style="color:var(--text-primary);" x-text="fmt(b.metrics[m.key])"></td>
                                </template>
                            </tr>
                        </template>
                        <tr x-show="branchDisplay().length === 0">
                            <td class="px-3 py-4 text-center" style="color:var(--text-muted);" :colspan="metrics.length + 1">No branches in scope.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Per-agent table with filter toolbar --}}
        <div class="mt-6">
            <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
                <h2 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">By agent</h2>
                {{-- #7 filter controls --}}
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
            <div class="overflow-x-auto rounded" style="border:1px solid var(--border);">
                <table class="w-full text-xs">
                    <thead>
                        <tr style="background:var(--surface-2);">
                            <th class="text-left px-3 py-2 cursor-pointer select-none" @click="sortAgent('name')" :aria-sort="ariaAgent('name')" style="color:var(--text-muted);">
                                Agent <span x-text="agentArrow('name')"></span>
                            </th>
                            <th class="text-left px-3 py-2 cursor-pointer select-none" @click="sortAgent('branch')" :aria-sort="ariaAgent('branch')" style="color:var(--text-muted);">
                                Branch <span x-text="agentArrow('branch')"></span>
                            </th>
                            <template x-for="m in metrics" :key="m.key">
                                <th class="text-right px-3 py-2 cursor-pointer select-none" @click="sortAgent(m.key)" :aria-sort="ariaAgent(m.key)" style="color:var(--text-muted);">
                                    <span x-text="m.label"></span> <span x-text="agentArrow(m.key)"></span>
                                </th>
                            </template>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="a in agentDisplay()" :key="a.user_id">
                            <tr style="border-top:1px solid var(--border);">
                                <td class="px-3 py-2">
                                    <a :href="agentUrl(a.user_id)" class="no-underline" style="color:var(--brand, #3b82f6);" x-text="a.name"></a>
                                </td>
                                <td class="px-3 py-2" style="color:var(--text-muted);" x-text="a.branch_label"></td>
                                <template x-for="m in metrics" :key="m.key">
                                    <td class="text-right px-3 py-2" style="color:var(--text-primary);" x-text="fmt(a.metrics[m.key])"></td>
                                </template>
                            </tr>
                        </template>
                        <tr x-show="agentDisplay().length === 0">
                            <td class="px-3 py-4 text-center" style="color:var(--text-muted);" :colspan="metrics.length + 2">No agents match the current filters.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <p class="text-[10px]" style="color:var(--text-muted);">
        Agency Performance &amp; ROI — {{ count($report['metrics']) }} metrics rolled up agent → branch → company for the selected period, with point-in-time branch attribution.
    </p>
</div>

<script>
/* AT-366 report frontend (cc1) — client-side sort + filter over cc6's rollup data.
   Pure presentation: operates only on the already-scoped rows the server rendered
   (agency scoping is enforced server-side by cc6's build()). */
function agencyReportTable(cfg) {
    return {
        branches: cfg.branches || [],
        agents: cfg.agents || [],
        metrics: cfg.metrics || [],
        branchUrlBase: cfg.branchUrlBase,
        agentUrlBase: cfg.agentUrlBase,

        // sort state, one per table
        bSort: 'label', bDir: 'asc',
        aSort: 'name', aDir: 'asc',

        // agent filters
        search: '',
        branchFilter: '',
        minActivity: 0,

        fmt(v) { return Number(v ?? 0).toLocaleString(); },
        branchUrl(key) { return this.branchUrlBase.replace('__KEY__', encodeURIComponent(key)); },
        agentUrl(id)  { return this.agentUrlBase.replace('__UID__', encodeURIComponent(id)); },

        _val(row, key, labelKey) {
            if (key === labelKey) return (row[labelKey] || '').toString().toLowerCase();
            if (key === 'branch') return (row.branch_label || '').toString().toLowerCase();
            return Number(row.metrics ? (row.metrics[key] ?? 0) : 0);
        },
        _cmp(a, b, key, dir, labelKey) {
            const va = this._val(a, key, labelKey), vb = this._val(b, key, labelKey);
            let r;
            if (typeof va === 'string' || typeof vb === 'string') r = String(va).localeCompare(String(vb));
            else r = va - vb;
            return dir === 'asc' ? r : -r;
        },
        _toggle(cur, key, curDir, textKeys) {
            // returns [newKey, newDir]
            if (cur === key) return [key, curDir === 'asc' ? 'desc' : 'asc'];
            // text columns default asc, numeric columns default desc (most-first)
            return [key, textKeys.includes(key) ? 'asc' : 'desc'];
        },

        // ---- branch table ----
        sortBranch(key) { [this.bSort, this.bDir] = this._toggle(this.bSort, key, this.bDir, ['label']); },
        branchArrow(key) { return this.bSort === key ? (this.bDir === 'asc' ? '▲' : '▼') : ''; },
        ariaBranch(key) { return this.bSort === key ? (this.bDir === 'asc' ? 'ascending' : 'descending') : 'none'; },
        branchDisplay() { return [...this.branches].sort((a, b) => this._cmp(a, b, this.bSort, this.bDir, 'label')); },

        // ---- agent table ----
        sortAgent(key) { [this.aSort, this.aDir] = this._toggle(this.aSort, key, this.aDir, ['name', 'branch']); },
        agentArrow(key) { return this.aSort === key ? (this.aDir === 'asc' ? '▲' : '▼') : ''; },
        ariaAgent(key) { return this.aSort === key ? (this.aDir === 'asc' ? 'ascending' : 'descending') : 'none'; },
        _agentTotal(a) { return this.metrics.reduce((s, m) => s + Number(a.metrics ? (a.metrics[m.key] ?? 0) : 0), 0); },
        agentFiltered() {
            const q = this.search.trim().toLowerCase();
            const bf = String(this.branchFilter);
            const min = Number(this.minActivity) || 0;
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
    };
}
</script>
@endsection
