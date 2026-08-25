{{-- Suburb Report — shared report body. Included by BOTH the interactive
     screen and the print/PDF view so the numbers and the visuals a seller
     is shown on screen and the page handed to them can never disagree.
     Deliberately literal inline colours throughout, never a CSS custom
     property — the print/PDF render has no access to the app's token
     stylesheet, and a report meant to be put in front of a seller across a
     table should read the same everywhere: light, high-contrast, legible
     at a glance, not a dashboard.

     Requires: $data (SuburbReportDataService::build() output), $stockListings.
     Every section below renders ONLY when real data backs it — a section
     with nothing behind it does not render at all, per the rule already
     established for this screen. --}}
@php
    $layerA = $data['layer_a'];
    $layerB = $data['layer_b'];
    $layerC = $data['layer_c'];
    $sold = $layerB['available'] ? $layerB['sales_activity']['sold'] : [];
    $underOffer = $layerB['available'] ? $layerB['sales_activity']['under_offer'] : [];
    $priceReductions = $layerB['available'] ? $layerB['price_reductions']['changes'] : [];
    $stockCount = $layerB['stock_on_market']['count'] ?? 0;
    $buyersWatching = $layerC['buyers_including_this_suburb'] ?? 0;
    $moneyFmt = fn ($v) => 'R ' . number_format((float) $v, 0);
    $moneyCompact = function ($v) {
        $v = (float) $v;
        if ($v >= 1_000_000) return 'R ' . number_format($v / 1_000_000, $v % 1_000_000 === 0.0 ? 0 : 1) . 'M';
        if ($v >= 1_000) return 'R ' . number_format($v / 1_000, 0) . 'K';
        return 'R ' . number_format($v, 0);
    };
@endphp

{{-- ================= KPI headline ================= --}}
<div style="background:#ffffff; border:1px solid #e3ddc9; border-radius:10px; padding:1.1rem 1.3rem; margin-bottom:1rem;">
    @include('corex.market-intelligence._suburb-report-stat-row', ['stats' => [
        ['label' => 'Stock on market', 'value' => number_format($stockCount)],
        ['label' => 'Sold', 'value' => number_format(count($sold)), 'color' => '#2f6b45'],
        ['label' => 'Under offer', 'value' => number_format(count($underOffer)), 'color' => '#96660a'],
        ['label' => 'Buyers watching', 'value' => number_format($buyersWatching), 'color' => '#0d6e68'],
    ]])
</div>

{{-- ================= LAYER A — CMA imports, with visuals ================= --}}
@if($layerA['available'])
<div style="background:#ffffff; border:1px solid #e3ddc9; border-radius:10px; padding:1.1rem 1.3rem; margin-bottom:1rem; break-inside:avoid;">
    <div style="font-size:1.05rem; font-weight:700; color:#1b2a2c; margin-bottom:0.2rem;">Market data on file (CMA reports)</div>
    <p style="font-size:0.8rem; color:#5c6c6d; margin:0 0 1rem;">Each report imported for this suburb kept as its own figures — never merged with another report's numbers, even where they cover the same year.</p>

    @foreach($layerA['reports'] as $report)
    @php
        // Build the year-by-year price series for the chart — median where
        // the report is a median-variant, average where it's average. Never
        // both at once for one report (they can't both be populated on a
        // real report — see CmaInfoMedianSalesAnalysisParser's variant
        // refusal), so whichever key has values IS this report's series.
        $priceKey = null;
        foreach ($report['years'] as $y) {
            if (isset($y['suburb_median_price_year'])) { $priceKey = 'suburb_median_price_year'; break; }
            if (isset($y['suburb_average_price_year'])) { $priceKey = 'suburb_average_price_year'; break; }
        }
        $priceLabel = $priceKey === 'suburb_median_price_year' ? 'Median price' : ($priceKey === 'suburb_average_price_year' ? 'Average price' : null);
        $cols = [];
        if ($priceKey !== null) {
            foreach ($report['years'] as $y) {
                if (!isset($y[$priceKey])) continue;
                $cols[] = [
                    'label'   => (string) $y['year'],
                    'value'   => (float) $y[$priceKey],
                    'display' => $moneyCompact($y[$priceKey]),
                    'partial' => $y['is_partial_year'] ?? false,
                ];
            }
        }
        $latestYear = end($report['years']) ?: null;
    @endphp
    <div style="background:#f7f3e7; border:1px solid #e3ddc9; border-radius:8px; padding:1rem; margin-bottom:0.9rem;">
        <div style="display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:0.5rem; margin-bottom:0.7rem;">
            <div style="font-size:0.9rem; font-weight:700; color:#1b2a2c;">
                {{ $report['report_type'] }}
                @if($report['property_scope'] === 'sectional_title')
                    <span style="font-family:monospace; font-size:0.62rem; font-weight:700; letter-spacing:0.02em; padding:0.15rem 0.45rem; border-radius:99px; background:#e6ede9; color:#2f6b45; margin-left:0.4rem;">SECTIONAL TITLE</span>
                @elseif($report['property_scope'] === 'full_title')
                    <span style="font-family:monospace; font-size:0.62rem; font-weight:700; letter-spacing:0.02em; padding:0.15rem 0.45rem; border-radius:99px; background:#eee3d3; color:#8a5f0a; margin-left:0.4rem;">FULL TITLE</span>
                @endif
            </div>
            @if($report['report_date'])
            <div style="font-size:0.75rem; color:#8a9697;">Report date: {{ \Illuminate\Support\Carbon::parse($report['report_date'])->format('d M Y') }}</div>
            @endif
        </div>

        @if(!empty($cols) && count($cols) >= 2)
        <div style="font-size:0.7rem; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; color:#8a9697; margin-bottom:0.3rem;">{{ $priceLabel }} by year</div>
        @include('corex.market-intelligence._suburb-report-vbars', ['cols' => $cols])
        @endif

        @if($latestYear && (isset($latestYear['suburb_low_year']) || isset($latestYear['suburb_high_year'])))
        <div style="margin-top:0.8rem; font-size:0.8rem; color:#4a5555;">
            {{ $latestYear['year'] }} range:
            <strong style="color:#1b2a2c;">{{ isset($latestYear['suburb_low_year']) ? $moneyFmt($latestYear['suburb_low_year']) : '—' }} – {{ isset($latestYear['suburb_high_year']) ? $moneyFmt($latestYear['suburb_high_year']) : '—' }}</strong>
            @if(isset($latestYear['suburb_sales_count_year']))
                &nbsp;·&nbsp;{{ number_format($latestYear['suburb_sales_count_year']) }} sales
            @endif
        </div>
        @endif
    </div>
    @endforeach

    {{-- CMA vs CoreX — the combined picture the screen exists for. Only
         where BOTH a CMA price and a real CoreX price exist for this
         suburb; the difference must be visible at a glance, never implied. --}}
    @php
        $cmaLatestPrice = null;
        $cmaLatestLabel = null;
        foreach ($layerA['reports'] as $r) {
            $y = end($r['years']);
            if (!$y) continue;
            if (isset($y['suburb_median_price_year'])) { $cmaLatestPrice = $y['suburb_median_price_year']; $cmaLatestLabel = 'CMA median (' . $y['year'] . ')'; break; }
            if (isset($y['suburb_average_price_year'])) { $cmaLatestPrice = $y['suburb_average_price_year']; $cmaLatestLabel = 'CMA average (' . $y['year'] . ')'; break; }
        }
        $coreXPrices = collect($sold)->pluck('price')->filter()->values();
        $coreXMedianSold = null;
        if ($coreXPrices->isNotEmpty()) {
            $sortedP = $coreXPrices->sort()->values();
            $n = $sortedP->count();
            $coreXMedianSold = $n % 2 === 1 ? $sortedP[intdiv($n, 2)] : (int) round(($sortedP[$n / 2 - 1] + $sortedP[$n / 2]) / 2);
        }
    @endphp
    @if($cmaLatestPrice !== null && $coreXMedianSold !== null)
    <div style="background:#eef4f2; border:1px solid #cfe0da; border-radius:8px; padding:0.9rem 1rem; margin-top:0.9rem;">
        <div style="font-size:0.7rem; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; color:#2f6b45; margin-bottom:0.5rem;">CMA vs CoreX — the combined picture</div>
        @include('corex.market-intelligence._suburb-report-stat-row', ['stats' => [
            ['label' => $cmaLatestLabel, 'value' => $moneyFmt($cmaLatestPrice), 'color' => '#8a5f0a'],
            ['label' => 'CoreX median sold price', 'value' => $moneyFmt($coreXMedianSold), 'color' => '#2f6b45', 'sub' => count($sold) . ' sold'],
        ]])
    </div>
    @endif
</div>
@endif

{{-- ================= Sold & under offer, with price distribution ================= --}}
@if(count($sold) > 0 || count($underOffer) > 0)
@php
    $allPrices = collect($sold)->concat($underOffer)->concat(collect($stockListings)->map(fn ($l) => ['price' => $l->price]))->pluck('price')->filter()->values();
    $priceBandBars = [];
    if ($allPrices->isNotEmpty()) {
        $bandSize = 500_000;
        $grouped = $allPrices->groupBy(fn ($p) => (int) floor($p / $bandSize))->sortKeys();
        foreach ($grouped as $bandIdx => $items) {
            $low = $bandIdx * $bandSize;
            $priceBandBars[] = ['label' => $moneyCompact($low) . '+', 'value' => $items->count(), 'display' => (string) $items->count()];
        }
    }
    $daysToSell = $layerB['sales_activity']['median_days_to_sell'] ?? null;
@endphp
<div style="background:#ffffff; border:1px solid #e3ddc9; border-radius:10px; padding:1.1rem 1.3rem; margin-bottom:1rem; break-inside:avoid;">
    <div style="font-size:1.05rem; font-weight:700; color:#1b2a2c; margin-bottom:0.2rem;">Sold &amp; under offer</div>
    <p style="font-size:0.8rem; color:#5c6c6d; margin:0 0 0.9rem;">Your own deals for this suburb. "Sold" means granted and registered; "under offer" means an offer is in progress but not yet granted or registered.</p>

    @if($daysToSell !== null)
    <div style="margin-bottom:0.9rem;">
        @include('corex.market-intelligence._suburb-report-stat-row', ['stats' => [
            ['label' => 'Median days to sell', 'value' => number_format($daysToSell), 'sub' => 'from listing to registration/acceptance, sold deals with a real listing date on file'],
        ]])
    </div>
    @endif

    @if(!empty($priceBandBars))
    <div style="font-size:0.7rem; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; color:#8a9697; margin-bottom:0.3rem;">Price distribution — stock, sold &amp; under offer combined</div>
    @include('corex.market-intelligence._suburb-report-hbars', ['bars' => $priceBandBars, 'color' => '#0d6e68'])
    @endif

    <div style="overflow-x:auto; margin-top:1rem;">
        <table style="width:100%; border-collapse:collapse; font-size:0.82rem;">
            <thead style="display:table-header-group;">
                <tr style="border-bottom:1px solid #e3ddc9;">
                    <th style="text-align:left; padding:0.4rem 0.5rem 0.4rem 0; color:#5c6c6d; font-weight:600;">Address</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; color:#5c6c6d; font-weight:600;">Price</th>
                    <th style="text-align:left; padding:0.4rem 0.5rem; color:#5c6c6d; font-weight:600;">Type</th>
                    <th style="text-align:left; padding:0.4rem 0 0.4rem 0.5rem; color:#5c6c6d; font-weight:600;">Stage</th>
                </tr>
            </thead>
            <tbody>
                @foreach(array_merge($sold, $underOffer) as $deal)
                <tr style="border-bottom:1px solid #f0ece0;">
                    <td style="padding:0.4rem 0.5rem 0.4rem 0; color:#1b2a2c;">{{ $deal['address'] ?? '—' }}</td>
                    <td style="padding:0.4rem 0.5rem; text-align:right; color:#1b2a2c; font-weight:600;">{{ $moneyFmt($deal['price']) }}</td>
                    <td style="padding:0.4rem 0.5rem; color:#5c6c6d;">
                        @if($deal['comparable'])
                            {{ $deal['beds'] }} bed / {{ $deal['baths'] }} bath — {{ ucfirst((string) $deal['property_type']) }}
                        @else
                            <span style="color:#8a9697;">Details not on file</span>
                        @endif
                    </td>
                    <td style="padding:0.4rem 0 0.4rem 0.5rem;">
                        @if($deal['stage'] === \App\Support\Sales\SaleStageLabel::SOLD)
                            <span style="font-family:monospace; font-size:0.65rem; font-weight:700; padding:0.15rem 0.5rem; border-radius:99px; background:#e6ede9; color:#2f6b45;">SOLD</span>
                        @else
                            <span style="font-family:monospace; font-size:0.65rem; font-weight:700; padding:0.15rem 0.5rem; border-radius:99px; background:#f3ead2; color:#96660a;">UNDER OFFER</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- ================= Stock on market ================= --}}
@if($stockListings->isNotEmpty())
<div style="background:#ffffff; border:1px solid #e3ddc9; border-radius:10px; padding:1.1rem 1.3rem; margin-bottom:1rem; break-inside:avoid;">
    <div style="font-size:1.05rem; font-weight:700; color:#1b2a2c; margin-bottom:0.2rem;">Stock on market</div>
    <p style="font-size:0.8rem; color:#5c6c6d; margin:0 0 0.9rem;">Active listings in this suburb, most recently listed first.</p>
    <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:0.82rem;">
            <thead style="display:table-header-group;">
                <tr style="border-bottom:1px solid #e3ddc9;">
                    <th style="text-align:left; padding:0.4rem 0.5rem 0.4rem 0; color:#5c6c6d; font-weight:600;">Address</th>
                    <th style="text-align:right; padding:0.4rem 0.5rem; color:#5c6c6d; font-weight:600;">Price</th>
                    <th style="text-align:right; padding:0.4rem 0 0.4rem 0.5rem; color:#5c6c6d; font-weight:600;">Listed</th>
                </tr>
            </thead>
            <tbody>
                @foreach($stockListings->take(15) as $listing)
                <tr style="border-bottom:1px solid #f0ece0;">
                    <td style="padding:0.4rem 0.5rem 0.4rem 0; color:#1b2a2c;">{{ $listing->address ?? $listing->street_name ?? '—' }}</td>
                    <td style="padding:0.4rem 0.5rem; text-align:right; color:#1b2a2c; font-weight:600;">{{ $listing->price ? $moneyFmt($listing->price) : '—' }}</td>
                    <td style="padding:0.4rem 0 0.4rem 0.5rem; text-align:right; color:#5c6c6d;">{{ $listing->listed_date ? \Illuminate\Support\Carbon::parse($listing->listed_date)->format('d M Y') : '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @if($stockListings->count() > 15)
        <p style="font-size:0.72rem; color:#8a9697; margin-top:0.4rem;">Showing 15 of {{ number_format($stockListings->count()) }} active listings.</p>
        @endif
    </div>
</div>
@endif

{{-- ================= Price reduction activity ================= --}}
@if(count($priceReductions) > 0)
@php
    // Group by month for the last 12 months of activity — a count-of-events
    // chart, not a list of every single reduction (91+ on a busy suburb).
    $byMonth = [];
    foreach ($priceReductions as $c) {
        if (empty($c['change_date'])) continue;
        $key = \Illuminate\Support\Carbon::parse($c['change_date'])->format('Y-m');
        $byMonth[$key] = ($byMonth[$key] ?? 0) + 1;
    }
    ksort($byMonth);
    $byMonth = array_slice($byMonth, -12, 12, true);
    $reductionCols = [];
    foreach ($byMonth as $ym => $count) {
        $reductionCols[] = ['label' => \Illuminate\Support\Carbon::createFromFormat('Y-m', $ym)->format('M'), 'value' => $count, 'display' => (string) $count];
    }
    $avgReductionPct = collect($priceReductions)->filter(fn ($c) => $c['old_price'] > 0)
        ->map(fn ($c) => (($c['old_price'] - $c['new_price']) / $c['old_price']) * 100)
        ->avg();
@endphp
<div style="background:#ffffff; border:1px solid #e3ddc9; border-radius:10px; padding:1.1rem 1.3rem; margin-bottom:1rem; break-inside:avoid;">
    <div style="font-size:1.05rem; font-weight:700; color:#1b2a2c; margin-bottom:0.2rem;">Price reduction activity</div>
    <p style="font-size:0.8rem; color:#5c6c6d; margin:0 0 0.9rem;">{{ number_format(count($priceReductions)) }} portal-reported price change{{ count($priceReductions) === 1 ? '' : 's' }} in this suburb{{ $avgReductionPct !== null ? ', averaging ' . number_format(abs($avgReductionPct), 1) . '% down' : '' }}.</p>
    @if(!empty($reductionCols))
    <div style="font-size:0.7rem; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; color:#8a9697; margin-bottom:0.3rem;">Reductions per month, last 12 months</div>
    @include('corex.market-intelligence._suburb-report-vbars', ['cols' => $reductionCols, 'color' => '#9c3a30'])
    @endif
</div>
@endif

{{-- ================= Buyer demand vs available stock ================= --}}
@if($buyersWatching > 0)
<div style="background:#ffffff; border:1px solid #e3ddc9; border-radius:10px; padding:1.1rem 1.3rem; margin-bottom:1rem; break-inside:avoid;">
    <div style="font-size:1.05rem; font-weight:700; color:#1b2a2c; margin-bottom:0.2rem;">Buyer demand vs available stock</div>
    <p style="font-size:0.8rem; color:#5c6c6d; margin:0 0 0.9rem;">Active buyers currently watching this suburb, from live Core Match wishlists, against what's actually on the market.</p>
    <div style="margin-bottom:0.9rem;">
        @include('corex.market-intelligence._suburb-report-stat-row', ['stats' => [
            ['label' => 'Buyers watching', 'value' => number_format($buyersWatching), 'color' => '#0d6e68', 'sub' => number_format($layerC['buyers_specifically_this_suburb']) . ' watching this suburb only'],
            ['label' => 'Stock on market', 'value' => number_format($stockCount)],
        ]])
    </div>
    @if(!empty($layerC['price_bands']))
    @php
        $bandBars = [];
        foreach ($layerC['price_bands'] as $band => $count) {
            $bandBars[] = ['label' => $band, 'value' => $count, 'display' => (string) $count];
        }
    @endphp
    <div style="font-size:0.7rem; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; color:#8a9697; margin-bottom:0.3rem;">Buyers by price band</div>
    @include('corex.market-intelligence._suburb-report-hbars', ['bars' => $bandBars, 'color' => '#0d6e68'])
    @endif
</div>
@endif

{{-- ================= Nothing at all ================= --}}
@if(!$layerA['available'] && count($sold) === 0 && count($underOffer) === 0 && $stockListings->isEmpty() && count($priceReductions) === 0 && $buyersWatching === 0)
<div style="background:#ffffff; border:1px solid #e3ddc9; border-radius:10px; padding:2.5rem 1.5rem; text-align:center; margin-bottom:1rem;">
    <div style="font-size:1rem; font-weight:700; color:#1b2a2c; margin-bottom:0.3rem;">Nothing on file for {{ $data['suburb']['name'] ?? 'this suburb' }} yet</div>
    <p style="font-size:0.85rem; color:#5c6c6d; margin:0;">No CoreX stock, sales, or buyer activity, and no CMA report imported for this suburb.</p>
</div>
@endif
