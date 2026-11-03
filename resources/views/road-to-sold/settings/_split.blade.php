{{--
    Road to Sold - "type the total, split it by percentage" (road-to-sold.md section 21.3, Johan 27 Oct 2026).
    Used by Budgets (what the agency needs) and by the monthly Targets. One total (or four on Targets), a slider and a % box per branch,
    and the same one level down for each branch's agents. Amounts are worked out live in the browser with the same arithmetic the server
    uses (App\Services\RoadToSold\SplitAllocator: whole units, biggest remainders), so what is shown is what is saved; Save stays off until
    every level adds up to 100%, and the server refuses a split that does not.

    $split = [
      'id'        => dom id,
      'action'    => PUT route,
      'period'    => 'YYYY-MM',
      'mode'      => 'agency' (type the agency total, split to branches, then agents) | 'branch' (one branch: its total, then its agents),
      'branchId'  => id when mode = branch,
      'totalLabel'=> label of the total box(es) heading,
      'measures'  => [['key'=>'requirement','label'=>'Amount','decimals'=>2,'money'=>true], ...],
      'totals'    => [key => number],
      'totalEditable' => bool,
      'canWrite'  => bool,
      'branches'  => [['id'=>1,'name'=>'Sea View','pct'=>60.0,'agents'=>[['id'=>9,'name'=>'Retha','pct'=>50.0], ...]], ...],
    ]
--}}
@php $sid = $split['id']; @endphp
<div id="{{ $sid }}" class="rounded-md p-4 space-y-3" style="background:var(--surface); border:2px solid var(--brand-icon, #0ea5e9);"
     x-data="rtsSplit(@js($split))" data-split>
    <form method="POST" action="{{ $split['action'] }}" class="space-y-3" @submit="if (! canSave) { $event.preventDefault(); }">
        @csrf @method('PUT')
        <input type="hidden" name="period" value="{{ $split['period'] }}">
        <input type="hidden" name="level" value="{{ $split['mode'] }}">
        @if($split['mode'] === 'branch')<input type="hidden" name="branch_id" value="{{ $split['branchId'] }}">@endif

        <div class="flex flex-wrap items-end gap-3">
            <h3 class="text-sm font-bold w-full" style="color:var(--text-primary);">{{ $split['totalLabel'] }}</h3>
            @foreach($split['measures'] as $m)
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="{{ $sid }}-total-{{ $m['key'] }}">{{ $m['label'] }}</label>
                    <input id="{{ $sid }}-total-{{ $m['key'] }}" type="number" min="0" step="{{ $m['decimals'] > 0 ? '0.01' : '1' }}" name="total[{{ $m['key'] }}]"
                           x-model.number="totals['{{ $m['key'] }}']" data-total="{{ $m['key'] }}"
                           @disabled(! $split['totalEditable'] || ! $split['canWrite']) class="{{ $m['money'] ? 'w-44' : 'w-28' }} rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                </div>
            @endforeach
        </div>

        <div class="rounded-md overflow-x-auto" style="border:1px solid var(--border);">
            <table class="w-full text-sm" style="color:var(--text-primary);">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide" style="background:var(--surface-2); border-bottom:1px solid var(--border); color:var(--text-muted);">
                        <th class="px-3 py-2">{{ $split['mode'] === 'agency' ? 'Branch' : 'Agent' }}</th>
                        <th class="px-3 py-2" style="min-width:11rem;">Share</th>
                        <th class="px-3 py-2 text-right" style="width:6.5rem;">%</th>
                        @foreach($split['measures'] as $m)<th class="px-3 py-2 text-right">{{ count($split['measures']) > 1 ? $m['label'] : 'Amount' }}</th>@endforeach
                    </tr>
                </thead>
                <tbody>
                <template x-for="row in rows" :key="row.key">
                    <tr x-show="row.type === 'branch' || row.show" :style="row.type === 'agent' ? 'background:var(--surface-2);' : 'border-top:1px solid var(--border);'"
                        :data-branch-row="row.type === 'branch' ? row.ref.id : null" :data-agent-row="row.type === 'agent' ? row.ref.id : null">
                        <td class="px-3 py-2" :class="row.type === 'agent' && mode === 'agency' ? 'pl-10 text-xs' : 'font-semibold'">
                            <button type="button" class="mr-2 text-xs font-normal" style="color:var(--text-muted);" x-show="row.type === 'branch' && agentCount(row) > 0" @click="row.ref.open = ! row.ref.open" :aria-expanded="row.ref.open">
                                <span x-text="row.ref.open ? '▾' : '▸'"></span> <span x-text="agentCount(row) + (agentCount(row) === 1 ? ' agent' : ' agents')"></span>
                            </button>
                            <span x-text="row.ref.name"></span>
                        </td>
                        <td class="px-3 py-2"><input type="range" min="0" max="100" step="0.1" x-model.number="row.ref.pct" :disabled="row.single || ! canWrite" class="w-full" :aria-label="'Share of ' + row.ref.name"></td>
                        <td class="px-3 py-2 text-right"><input type="number" min="0" max="100" step="0.01" :name="row.field" x-model.number="row.ref.pct" :readonly="row.single || ! canWrite"
                                class="w-24 rounded-md px-2 py-1 text-sm text-right" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"></td>
                        <template x-for="m in measures" :key="m.key"><td class="px-3 py-2 text-right" :class="row.type === 'agent' && mode === 'agency' ? 'text-xs' : 'font-semibold'" x-text="show(m, row.amounts[m.key])"></td></template>
                    </tr>
                </template>
                </tbody>
                <tfoot>
                    <tr style="border-top:2px solid var(--border);" class="font-semibold" data-split-total>
                        <td class="px-3 py-2" colspan="2" x-text="mode === 'agency' ? 'Branches add up to' : 'Agents add up to'"></td>
                        <td class="px-3 py-2 text-right" :style="levelOk(topPcts()) ? '' : 'color:var(--ds-crimson, #dc2626)'" x-text="fmtPct(sumOf(topPcts())) + '%'"></td>
                        <template x-for="m in measures" :key="m.key"><td class="px-3 py-2 text-right" x-text="show(m, topSum(m))"></td></template>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            @if($split['canWrite'])
                <button type="submit" class="corex-btn-primary" :disabled="! canSave" :style="canSave ? '' : 'opacity:.5; cursor:not-allowed;'" data-split-save>Save split</button>
            @endif
            <span class="text-sm" :style="canSave ? 'color:var(--text-muted)' : 'color:var(--ds-crimson, #dc2626); font-weight:600;'" x-text="status" data-split-status></span>
        </div>
    </form>
</div>

<script>
{{-- The same arithmetic as App\Services\RoadToSold\SplitAllocator: whole units, leftover units to the biggest remainders (lower id first). --}}
function rtsSplit(cfg) {
    return {
        mode: cfg.mode, measures: cfg.measures, totals: Object.assign({}, cfg.totals), canWrite: cfg.canWrite,
        branches: cfg.branches.map(function (b) { return Object.assign({ open: false }, b, { agents: b.agents.map(function (a) { return Object.assign({}, a); }) }); }),
        scale: function (m) { return Math.pow(10, m.decimals); },
        sumOf: function (pcts) { var s = 0; Object.keys(pcts).forEach(function (k) { s += Number(pcts[k]) || 0; }); return Math.round(s * 10000) / 10000; },
        tallies: function (pcts) { return Math.abs(this.sumOf(pcts) - 100) <= 0.01 + 1e-9; },
        levelOk: function (pcts) { return Object.keys(pcts).length < 2 || this.tallies(pcts); },
        fmtPct: function (v) { return (Math.round(v * 100) / 100).toString(); },
        alloc: function (total, pcts, decimals) {
            var scale = Math.pow(10, decimals), units = Math.round((Number(total) || 0) * scale), ids = Object.keys(pcts), out = {}, sum = 0, given = 0, rem = [];
            ids.forEach(function (id) { sum += Math.max(0, Number(pcts[id]) || 0); });
            if (units <= 0 || sum <= 0) { ids.forEach(function (id) { out[id] = 0; }); return out; }
            ids.forEach(function (id) {
                var exact = units * Math.max(0, Number(pcts[id]) || 0) / sum, base = Math.floor(exact + 1e-9);
                out[id] = base; given += base; rem.push({ id: id, r: exact - base });
            });
            rem.sort(function (a, b) { return (b.r - a.r) || (Number(a.id) - Number(b.id)); });
            for (var i = 0; i < units - given; i++) { out[rem[i].id] += 1; }
            ids.forEach(function (id) { out[id] = out[id] / scale; });
            return out;
        },
        branchPcts: function () { var o = {}; this.branches.forEach(function (b) { o[b.id] = b.pct; }); return o; },
        agentPcts: function (b) { var o = {}; b.agents.forEach(function (a) { o[a.id] = a.pct; }); return o; },
        topPcts: function () { return this.mode === 'agency' ? this.branchPcts() : this.agentPcts(this.branches[0]); },
        branchAmounts: function (m) { return this.alloc(this.totals[m.key], this.branchPcts(), m.decimals); },
        agentAmounts: function (m, b) {
            var branchAmount = this.mode === 'agency' ? (this.branchAmounts(m)[b.id] || 0) : (Number(this.totals[m.key]) || 0);
            return this.alloc(branchAmount, this.agentPcts(b), m.decimals);
        },
        topSum: function (m) {
            var src = this.mode === 'agency' ? this.branchAmounts(m) : this.agentAmounts(m, this.branches[0]), s = 0, scale = this.scale(m);
            Object.keys(src).forEach(function (k) { s += Math.round(src[k] * scale); });
            return s / scale;
        },
        /** One flat list for the table: a branch row then its agents (agency mode), or just the agents (branch mode). */
        get rows() {
            var self = this, out = [];
            this.branches.forEach(function (b) {
                var bAmt = {};
                if (self.mode === 'agency') {
                    self.measures.forEach(function (m) { bAmt[m.key] = self.branchAmounts(m)[b.id] || 0; });
                    out.push({ key: 'b' + b.id, type: 'branch', ref: b, field: 'branches[' + b.id + ']', single: self.branches.length === 1, show: true, amounts: bAmt });
                }
                var perMeasure = {};
                self.measures.forEach(function (m) { perMeasure[m.key] = self.agentAmounts(m, b); });
                b.agents.forEach(function (a) {
                    var amounts = {};
                    self.measures.forEach(function (m) { amounts[m.key] = perMeasure[m.key][a.id] || 0; });
                    out.push({ key: 'a' + b.id + '-' + a.id, type: 'agent', ref: a, field: 'agents[' + b.id + '][' + a.id + ']', single: b.agents.length === 1, show: self.mode === 'branch' || b.open, amounts: amounts });
                });
            });
            return out;
        },
        agentCount: function (row) { return row.type === 'branch' ? row.ref.agents.length : 0; },
        show: function (m, v) {
            var parts = (Number(v) || 0).toFixed(m.decimals).split('.');
            parts[0] = parts[0].replace(/\B(?=(\d{3})+$)/g, ' ');
            return (m.money ? 'R ' : '') + parts.join('.');
        },
        get problems() {
            var out = [], self = this;
            if (this.measures.some(function (m) { return Number(self.totals[m.key]) < 0 || self.totals[m.key] === '' || self.totals[m.key] === null; })) { out.push('Enter every total (0 if none).'); }
            if (this.mode === 'agency' && ! this.levelOk(this.branchPcts())) { out.push('The branch percentages add up to ' + this.fmtPct(this.sumOf(this.branchPcts())) + '%, not 100%.'); }
            this.branches.forEach(function (b) {
                if (b.agents.length > 1 && ! self.levelOk(self.agentPcts(b))) { out.push('The ' + b.name + ' agent percentages add up to ' + self.fmtPct(self.sumOf(self.agentPcts(b))) + '%, not 100%.'); }
            });
            return out;
        },
        get canSave() { return this.canWrite && this.problems.length === 0; },
        get status() { return this.problems.length ? this.problems[0] : 'Everything adds up - ready to save.'; },
    };
}
</script>
