{{-- DRILLDOWN MODAL — click to see. Fetches BuyersReportController::drilldown()
     and renders columns+rows. Mirrors the ROI report's #9 modal. Requires the
     parent element to carry x-data="buyersReport({ drilldownBase: ... })". --}}
<div x-show="drillOpen" x-cloak @keydown.escape.window="closeDrill()"
     class="fixed inset-0 z-50 flex items-start justify-center p-4 md:p-10 print:hidden"
     style="background:rgba(0,0,0,.45);" @click.self="closeDrill()">
    <div class="w-full max-w-4xl rounded shadow-lg max-h-[85vh] overflow-hidden flex flex-col"
         style="background: var(--surface-1, #fff); border:1px solid var(--border);" role="dialog" aria-modal="true" aria-label="Detail">
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
                                <td class="px-3 py-2" :class="c.align === 'right' ? 'text-right' : 'text-left'" style="color:var(--text-primary);" x-text="cell(row, c)"></td>
                            </template>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function buyersReport(cfg) {
    return {
        drilldownBase: cfg.drilldownBase,
        drillOpen: false, drillLoading: false, drillError: '', drillTitle: '',
        drillColumns: [], drillRows: [],
        drill(metric, title, agentId) {
            this.drillOpen = true; this.drillLoading = true; this.drillError = '';
            this.drillTitle = title || metric; this.drillColumns = []; this.drillRows = [];
            const sep = this.drilldownBase.includes('?') ? '&' : '?';
            let url = this.drilldownBase + sep + 'metric=' + encodeURIComponent(metric);
            if (agentId !== null && agentId !== undefined && agentId !== '') url += '&agent_id=' + encodeURIComponent(agentId);
            fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => { if (!r.ok) throw new Error('Could not load the detail (' + r.status + ').'); return r.json(); })
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
