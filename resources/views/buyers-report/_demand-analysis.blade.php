{{-- DEMAND ANALYSIS — Johan (2026-08-20): "make it sliders - show me all
     buyers we have for apartments between 500k and 1 mil". A stock-
     acquisition tool, not a report metric: tick property types (multi-
     select, OR), drag a price range, get a live buyer count + list.

     MATCHING RULE (Johan confirmed): OVERLAP, not containment. A buyer
     wanting R700k-R1.2m shows when the slider is set to R500k-R1m --
     "any buyer falling in this criteria" was his own phrasing. This is
     interactive, not partitioned, so there is no double-counting to
     caveat: a buyer either matches the current selection or doesn't.

     Source: each buyer's PRIMARY core match (contact_matches,
     listing_type=sale) -- the same auto-created record that puts them on
     the pipeline board in the first place (Johan confirmed this directly).

     Coverage is shown as a static line, always, regardless of the current
     filter -- buyers with no type/price/match never silently vanish. --}}
@php
    $facets = $demandFacets ?? ['types' => [], 'price_min' => 0, 'price_max' => 1000000];
    $cov = $demandCoverage ?? ['total_buyers' => 0, 'no_match' => 0, 'no_type' => 0, 'no_price' => 0];
    $demandBase = url('/corex/buyers-report/demand') . '?' . http_build_query([
        'scope' => $scope->level, 'branch_id' => $scope->branchId, 'user_id' => $scope->userId,
    ]);
@endphp
<div class="rounded-md p-4 mb-8" style="background: var(--surface); border: 1px solid var(--border);"
     x-data="demandAnalysis({ demandBase: @js($demandBase), priceMax: {{ (int) $facets['price_max'] }} })" x-init="fetchDemand()">
    <h3 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Demand analysis — what would we sell them?</h3>
    <p class="text-[11px] mb-3" style="color: var(--text-muted);">
        Tick property types and drag the price range to see how many current buyers would consider stock there.
        A buyer shows if their requirement OVERLAPS the selection at all — not only if it sits entirely inside it.
    </p>

    @if(empty($facets['types']))
        <p class="text-xs py-4" style="color: var(--text-muted);">No buyers in this scope have a property type recorded yet — nothing to filter on.</p>
    @else
        <div class="flex flex-wrap gap-2 mb-4">
            @foreach($facets['types'] as $t)
                <label class="inline-flex items-center gap-1.5 text-xs px-2.5 py-1.5 rounded-md cursor-pointer"
                       style="background: var(--surface-2); border: 1px solid var(--border);">
                    <input type="checkbox" value="{{ $t }}" x-model="selectedTypes" @change="fetchDemand()">
                    <span style="color: var(--text-primary);">{{ $t }}</span>
                </label>
            @endforeach
        </div>

        <div class="mb-4 max-w-md">
            <div class="flex items-center justify-between text-[11px] mb-1" style="color: var(--text-muted);">
                <span>Price range</span>
                <span x-text="'R ' + Number(priceMin).toLocaleString() + ' – R ' + Number(priceMax).toLocaleString() + (priceMax >= {{ (int) $facets['price_max'] }} ? '+' : '')"></span>
            </div>
            <div class="flex items-center gap-2">
                <input type="range" min="0" max="{{ (int) $facets['price_max'] }}" step="25000"
                       x-model.number="priceMin" @input="if (priceMin > priceMax) priceMax = priceMin" @change="fetchDemand()"
                       class="w-full">
            </div>
            <div class="flex items-center gap-2 mt-1">
                <input type="range" min="0" max="{{ (int) $facets['price_max'] }}" step="25000"
                       x-model.number="priceMax" @input="if (priceMax < priceMin) priceMin = priceMax" @change="fetchDemand()"
                       class="w-full">
            </div>
        </div>

        <div class="flex items-center justify-between mb-2">
            <div class="text-sm font-semibold" style="color: var(--text-primary);">
                <span x-show="!loading" x-text="count === null ? '…' : count"></span>
                <span x-show="loading" style="color: var(--text-muted);">…</span>
                buyers match this selection
            </div>
            <button type="button" @click="selectedTypes = []; priceMin = 0; priceMax = {{ (int) $facets['price_max'] }}; fetchDemand()"
                    class="text-[11px] underline" style="color: var(--brand-icon, #0ea5e9);">Reset</button>
        </div>

        <div x-show="!loading && rows.length === 0" class="text-xs py-4 text-center" style="color: var(--text-muted);">
            No current buyer's requirement overlaps this selection.
        </div>
        <div x-show="rows.length > 0" class="overflow-auto" style="max-height: 320px;">
            <table class="w-full text-xs">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="px-3 py-2 text-left" style="color: var(--text-muted);">Buyer</th>
                        <th class="px-3 py-2 text-left" style="color: var(--text-muted);">Agent</th>
                        <th class="px-3 py-2 text-left" style="color: var(--text-muted);">Looking for</th>
                        <th class="px-3 py-2 text-right" style="color: var(--text-muted);">Range</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(r, i) in rows" :key="i">
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-3 py-2" style="color: var(--text-primary);" x-text="r.name"></td>
                            <td class="px-3 py-2" style="color: var(--text-primary);" x-text="r.agent"></td>
                            <td class="px-3 py-2" style="color: var(--text-primary);" x-text="r.types"></td>
                            <td class="px-3 py-2 text-right" style="color: var(--text-primary);"
                                x-text="(r.price_min !== null ? 'R ' + Number(r.price_min).toLocaleString() : 'no floor') + ' – ' + (r.price_max !== null ? 'R ' + Number(r.price_max).toLocaleString() : 'no ceiling')"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <div x-show="truncated" class="text-[11px] mt-2" style="color: var(--text-muted);">
            Showing the first <span x-text="rows.length"></span> — narrow the selection to see the rest.
        </div>
    @endif

    <p class="text-[11px] mt-4 pt-3" style="color: var(--text-muted); border-top: 1px solid var(--border);">
        Coverage in this scope: {{ $cov['total_buyers'] }} current buyers ·
        {{ $cov['no_match'] }} have no core match at all (not filterable) ·
        {{ $cov['no_type'] }} have no property type recorded ·
        {{ $cov['no_price'] }} have no price range recorded.
        These buyers never silently disappear — they simply can't be matched against a criterion they don't have.
    </p>
</div>

<script>
function demandAnalysis(cfg) {
    return {
        demandBase: cfg.demandBase,
        selectedTypes: [], priceMin: 0, priceMax: cfg.priceMax,
        count: null, rows: [], truncated: false, loading: false,
        fetchDemand() {
            this.loading = true;
            const sep = this.demandBase.includes('?') ? '&' : '?';
            let url = this.demandBase + sep + 'price_min=' + this.priceMin + '&price_max=' + this.priceMax;
            this.selectedTypes.forEach(t => { url += '&types[]=' + encodeURIComponent(t); });
            fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(d => { this.count = d.count; this.rows = d.rows || []; this.truncated = !!d.truncated; })
                .finally(() => { this.loading = false; });
        },
    };
}
</script>
