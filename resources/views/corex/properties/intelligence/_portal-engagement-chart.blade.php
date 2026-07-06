{{--
    Portal Engagement chart — views + P24 lead counts over time, with a range
    filter (30D / 90D / 6M). Sits directly below Portal Leads on the Intelligence
    tab. Series comes from property_portal_metrics (PropertyIntelligenceService::
    getPortalEngagementSeries). Client-side filtering — no round trips.
    Spec: .ai/specs/portal-metrics.md
--}}
@php
    $engagement = $engagement ?? app(\App\Services\PropertyIntelligenceService::class)->getPortalEngagementSeries($property->id);
    $series   = $engagement['series'] ?? [];
    $hasData  = $engagement['has_data'] ?? false;
@endphp

<div class="rounded-md p-4"
     style="background: var(--surface); border: 1px solid var(--border);"
     x-data="portalEngagementChart(@js($series))">
    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <div>
            <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Portal Engagement Over Time</h3>
            <p class="text-[10px]" style="color: var(--text-muted);">Property24 daily views &amp; lead counts. Private Property does not expose historical stats.</p>
        </div>
        <div class="flex items-center gap-1">
            @foreach(['30' => '30D', '90' => '90D', 'all' => '6M'] as $val => $label)
                <button type="button"
                        @click="setRange('{{ $val }}')"
                        :class="range === '{{ $val }}' ? 'font-semibold' : ''"
                        :style="range === '{{ $val }}'
                            ? 'background: color-mix(in srgb, #00d4aa 15%, transparent); color: #00d4aa; border-color: #00d4aa;'
                            : 'background: var(--surface-2); color: var(--text-muted); border-color: var(--border);'"
                        class="text-[10px] px-2 py-1 rounded border transition-colors">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    @if(! $hasData)
        <div class="text-xs text-gray-400 py-8 text-center">
            No portal view data yet for this listing.
            <div class="text-[10px] mt-1" style="color: var(--text-muted);">Property24 stats are collected nightly and backfilled up to ~6 months where available.</div>
        </div>
    @else
        <div class="flex items-center gap-4 mb-2 text-xs">
            <span class="inline-flex items-center gap-1">
                <span class="inline-block w-2 h-2 rounded-full" style="background:#00d4aa;"></span>
                Views <span class="font-semibold" x-text="totalViews()"></span>
            </span>
            <span class="inline-flex items-center gap-1">
                <span class="inline-block w-2 h-2 rounded-full" style="background:#ef4444;"></span>
                P24 Leads <span class="font-semibold" x-text="totalLeads()"></span>
            </span>
            <span style="color: var(--text-muted);" class="text-[10px]" x-text="rangeLabel()"></span>
        </div>
        <div style="position: relative; height: 260px;">
            <canvas x-ref="canvas"></canvas>
        </div>
    @endif
</div>

@once
@push('scripts')
<script>
function portalEngagementChart(series) {
    return {
        series: series || [],
        range: '90',
        chart: null,
        filtered() {
            if (this.range === 'all') return this.series;
            const n = parseInt(this.range, 10);
            return this.series.slice(-n);
        },
        totalViews() { return this.filtered().reduce((a, r) => a + (r.views || 0), 0); },
        totalLeads() { return this.filtered().reduce((a, r) => a + (r.leads || 0), 0); },
        rangeLabel() {
            const f = this.filtered();
            if (!f.length) return '';
            return '· ' + f.length + ' days';
        },
        fmt(d) {
            const dt = new Date(d + 'T00:00:00');
            return dt.toLocaleDateString('en-ZA', { day: 'numeric', month: 'short' });
        },
        build() {
            if (!window.NexusCharts || !this.$refs.canvas) return;
            const f = this.filtered();
            this.chart = window.NexusCharts.portalEngagement(
                this.$refs.canvas,
                f.map(r => this.fmt(r.date)),
                f.map(r => r.views),
                f.map(r => r.leads)
            );
        },
        apply() {
            if (!this.chart) { this.build(); return; }
            const f = this.filtered();
            this.chart.data.labels = f.map(r => this.fmt(r.date));
            this.chart.data.datasets[0].data = f.map(r => r.views);
            this.chart.data.datasets[1].data = f.map(r => r.leads);
            this.chart.update();
        },
        setRange(r) { this.range = r; this.apply(); },
        init() { this.$nextTick(() => this.build()); },
    };
}
</script>
@endpush
@endonce
