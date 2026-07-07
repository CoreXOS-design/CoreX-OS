{{--
    Portal Engagement chart — views + P24 lead counts over time, with a range
    filter (30D / 90D / 6M). Sits directly below Portal Leads on the Intelligence
    tab. Series comes from property_portal_metrics (PropertyIntelligenceService::
    getPortalEngagementSeries). Client-side filtering — no round trips.

    The range filter is held in a shared Alpine store ($store.portalViews) so the
    "P24 Views" stat card at the top of the tab reacts to the SAME filter — change
    to 90D and both the graph and the card's number + label update together.
    Spec: .ai/specs/portal-metrics.md
--}}
@php
    $engagement = $engagement ?? app(\App\Services\PropertyIntelligenceService::class)->getPortalEngagementSeries($property->id);
    $series    = $engagement['series'] ?? [];
    $hasData   = $engagement['has_data'] ?? false;
    $ppHasData = $engagement['pp_has_data'] ?? false;
    $ppViews   = array_sum(array_column($series, 'pp_views'));
    $ppLeads   = array_sum(array_column($series, 'pp_leads'));
@endphp

<div class="rounded-md p-4"
     style="background: var(--surface); border: 1px solid var(--border);"
     x-data="portalEngagementChart()">
    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <div>
            <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Portal Engagement Over Time</h3>
            <p class="text-[10px]" style="color: var(--text-muted);">Property24 daily views &amp; lead counts (chart), backfilled to ~6 months. Private Property views &amp; enquiries are collected nightly from switch-on (no historical backfill).</p>
        </div>
        <div class="flex items-center gap-1">
            @foreach(['30' => '30D', '90' => '90D', 'all' => '6M'] as $val => $label)
                <button type="button"
                        @click="$store.portalViews.setRange('{{ $val }}')"
                        :class="$store.portalViews.range === '{{ $val }}' ? 'font-semibold' : ''"
                        :style="$store.portalViews.range === '{{ $val }}'
                            ? 'background: color-mix(in srgb, #00d4aa 15%, transparent); color: #00d4aa; border-color: #00d4aa;'
                            : 'background: var(--surface-2); color: var(--text-muted); border-color: var(--border);'"
                        class="text-[10px] px-2 py-1 rounded border transition-colors">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    @if(! $hasData && ! $ppHasData)
        <div class="text-xs text-gray-400 py-8 text-center">
            No portal view data yet for this listing.
            <div class="text-[10px] mt-1" style="color: var(--text-muted);">Property24 stats are collected nightly and backfilled up to ~6 months where available. Private Property stats begin accumulating the day the snapshot is enabled.</div>
        </div>
    @else
        <div class="flex items-center gap-4 mb-2 text-xs">
            <span class="inline-flex items-center gap-1">
                <span class="inline-block w-2 h-2 rounded-full" style="background:#00d4aa;"></span>
                Views <span class="font-semibold" x-text="$store.portalViews.totalViews()"></span>
            </span>
            <span class="inline-flex items-center gap-1">
                <span class="inline-block w-2 h-2 rounded-full" style="background:#ef4444;"></span>
                P24 Leads <span class="font-semibold" x-text="$store.portalViews.totalLeads()"></span>
            </span>
            @if($ppHasData)
                <span class="inline-flex items-center gap-1" title="Private Property engagement since the nightly snapshot was enabled (no historical backfill).">
                    <span class="inline-block w-2 h-2 rounded-full" style="background:#8b5cf6;"></span>
                    PP Views <span class="font-semibold">{{ number_format($ppViews) }}</span>
                    <span style="color: var(--text-muted);">· PP Enquiries {{ number_format($ppLeads) }}</span>
                </span>
            @endif
            <span style="color: var(--text-muted);" class="text-[10px]" x-text="$store.portalViews.dayLabel()"></span>
        </div>
        <div style="position: relative; height: 260px;">
            <canvas x-ref="canvas"></canvas>
        </div>
    @endif
</div>

@once
@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    if (window.Alpine && Alpine.store('portalViews')) return;
    Alpine.store('portalViews', {
        series: @js($series),
        range: '30',
        filtered() {
            if (this.range === 'all') return this.series;
            const n = parseInt(this.range, 10);
            return this.series.slice(-n);
        },
        totalViews() { return this.filtered().reduce((a, r) => a + (r.views || 0), 0); },
        totalLeads() { return this.filtered().reduce((a, r) => a + (r.leads || 0), 0); },
        rangeLabel() { return this.range === 'all' ? '6mo' : this.range + 'd'; },
        dayLabel() {
            const f = this.filtered();
            return f.length ? '· ' + f.length + ' days' : '';
        },
        setRange(r) { this.range = r; },
    });
});

function portalEngagementChart() {
    return {
        chart: null,
        store() { return this.$store.portalViews; },
        fmt(d) {
            const dt = new Date(d + 'T00:00:00');
            return dt.toLocaleDateString('en-ZA', { day: 'numeric', month: 'short' });
        },
        build() {
            if (!window.NexusCharts || !this.$refs.canvas) return;
            const f = this.store().filtered();
            this.chart = window.NexusCharts.portalEngagement(
                this.$refs.canvas,
                f.map(r => this.fmt(r.date)),
                f.map(r => r.views),
                f.map(r => r.leads)
            );
        },
        apply() {
            if (!this.chart) { this.build(); return; }
            const f = this.store().filtered();
            this.chart.data.labels = f.map(r => this.fmt(r.date));
            this.chart.data.datasets[0].data = f.map(r => r.views);
            this.chart.data.datasets[1].data = f.map(r => r.leads);
            this.chart.update();
        },
        init() {
            this.$nextTick(() => this.build());
            this.$watch(() => this.$store.portalViews.range, () => this.apply());
        },
    };
}
</script>
@endpush
@endonce
