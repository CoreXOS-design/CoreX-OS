{{-- Suburb Report — shared report body. Included by BOTH the interactive
     screen and the print/PDF view so the numbers and the visuals a seller
     is shown on screen and the page handed to them can never disagree.
     Deliberately literal inline colours throughout, never a CSS custom
     property — the print/PDF render has no access to the app's token
     stylesheet, and a report meant to be put in front of a seller across a
     table should read the same everywhere: light, high-contrast, legible
     at a glance, not a dashboard.

     Requires: $data (SuburbReportDataService::build() output), $stockListings,
     $sections (per-section show/agency/market toggles — see
     MarketIntelligenceController::parseSectionToggles()). Every section
     below renders ONLY when real data backs it AND its own toggle allows
     it — a section with nothing behind it does not render at all, and
     neither does one Johan has explicitly unticked.

     Johan, 2026-08-25: "the report can show 2 parts now — the agency
     picture and the CMA or portal picture... label which is which
     unmistakably — a seller must never mistake the agency's 14 for the
     whole market." Every agency/market sub-block below is opened with an
     explicit AGENCY or MARKET (PORTAL) label chip so the two can never be
     read as one number. --}}
@php
    $layerA = $data['layer_a'];
    $layerB = $data['layer_b'];
    $layerC = $data['layer_c'];
    $market = $layerB['available'] ? ($layerB['market'] ?? ['available' => false]) : ['available' => false];
    // 2026-08-25 fix (cc4 caught it on Mtwalume) — $market['available'] means
    // "this suburb has SOME portal capture" (stock OR sold OR under_offer OR
    // price reductions), never "this specific metric has data". Using it to
    // gate an individual section rendered a full Sold & Under Offer box with
    // every figure reading zero, purely because the suburb had unrelated
    // stock data. Each section's market sub-block now checks its OWN slice
    // of $market, never the suburb-wide flag — a section's own data decides
    // whether it renders, never a neighbouring section's.
    $marketHasStock          = ($market['available'] ?? false) && (($market['stock']['total'] ?? 0) > 0);
    $marketHasSoldUnderOffer = ($market['available'] ?? false) && ((($market['sold']['total'] ?? 0) + ($market['under_offer']['total'] ?? 0)) > 0);
    $marketHasPriceReductions = ($market['available'] ?? false) && (($market['price_reductions']['counts']['total'] ?? 0) > 0);
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
    // AGENCY / MARKET label chip — the one thing that must never be
    // ambiguous on this report.
    $sideChip = fn (string $kind) => $kind === 'agency'
        ? '<span style="font-family:monospace; font-size:0.62rem; font-weight:700; letter-spacing:0.03em; padding:0.15rem 0.5rem; border-radius:99px; background:#e6ede9; color:#2f6b45;">AGENCY</span>'
        : '<span style="font-family:monospace; font-size:0.62rem; font-weight:700; letter-spacing:0.03em; padding:0.15rem 0.5rem; border-radius:99px; background:#f3ead2; color:#96660a;">MARKET — P24 &amp; PP</span>';
@endphp

{{-- ================= KPI headline ================= --}}
<div style="background:#ffffff; border:1px solid #e3ddc9; border-radius:10px; padding:1.1rem 1.3rem; margin-bottom:1rem;">
    @include('corex.market-intelligence._suburb-report-stat-row', ['stats' => [
        ['label' => 'Stock on market (agency)', 'value' => number_format($stockCount)],
        ['label' => 'Sold (agency)', 'value' => number_format(count($sold)), 'color' => '#2f6b45'],
        ['label' => 'Under offer (agency)', 'value' => number_format(count($underOffer)), 'color' => '#96660a'],
        ['label' => 'Buyers watching', 'value' => number_format($buyersWatching), 'color' => '#0d6e68'],
    ]])
</div>

{{-- ================= LAYER A — CMA imports, with visuals ================= --}}
@if($sections['cma_reports']['show'] && $layerA['available'])
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

{{-- ================= Stock on market — AGENCY / MARKET ================= --}}
@php $stockHasAny = $stockListings->isNotEmpty() || $marketHasStock; @endphp
@if($sections['stock']['show'] && $stockHasAny)
<div style="background:#ffffff; border:1px solid #e3ddc9; border-radius:10px; padding:1.1rem 1.3rem; margin-bottom:1rem; break-inside:avoid;">
    <div style="font-size:1.05rem; font-weight:700; color:#1b2a2c; margin-bottom:0.2rem;">Stock on market</div>
    <p style="font-size:0.8rem; color:#5c6c6d; margin:0 0 0.9rem;">What the agency has listed here, next to what MIC has captured across Property24 and Private Property for this suburb — competitor stock included.</p>

    @if($sections['stock']['agency'] && $stockListings->isNotEmpty())
    <div style="margin-bottom:1rem;">
        <div style="margin-bottom:0.5rem;">{!! $sideChip('agency') !!}</div>
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

    @if($sections['stock']['market'])
    <div>
        <div style="margin-bottom:0.5rem;">{!! $sideChip('market') !!}</div>
        @if($marketHasStock)
        @include('corex.market-intelligence._suburb-report-stat-row', ['stats' => [
            ['label' => 'Active on Property24', 'value' => number_format($market['stock']['p24']), 'color' => '#96660a'],
            ['label' => 'Active on Private Property', 'value' => number_format($market['stock']['pp']), 'color' => '#96660a'],
            ['label' => 'Total captured', 'value' => number_format($market['stock']['total']), 'sub' => 'agency + competitor stock'],
        ]])
        @else
        <p style="font-size:0.8rem; color:#8a9697; margin:0;">No portal stock captured for this suburb yet — MIC has not tracked any P24/PP listings here.</p>
        @endif
    </div>
    @endif
</div>
@endif

{{-- ================= Sales — AGENCY / MARKET (CMA) ================= --}}
@php
    $cmaSalesRows = [];
    if ($layerA['available']) {
        foreach ($layerA['reports'] as $r) {
            $y = null;
            foreach (array_reverse($r['years']) as $candidate) {
                if (isset($candidate['suburb_sales_count_year'])) { $y = $candidate; break; }
            }
            if ($y !== null) {
                $cmaSalesRows[] = ['report_type' => $r['report_type'], 'year' => $y['year'], 'count' => $y['suburb_sales_count_year']];
            }
        }
    }
    $salesHasAny = count($sold) > 0 || !empty($cmaSalesRows);
@endphp
@if($sections['sales']['show'] && $salesHasAny)
<div style="background:#ffffff; border:1px solid #e3ddc9; border-radius:10px; padding:1.1rem 1.3rem; margin-bottom:1rem; break-inside:avoid;">
    <div style="font-size:1.05rem; font-weight:700; color:#1b2a2c; margin-bottom:0.2rem;">Sales</div>
    <p style="font-size:0.8rem; color:#5c6c6d; margin:0 0 0.9rem;">Completed sales by the agency, next to the area's total sales as reported in each CMA on file — kept separate per report, never blended.</p>

    @if($sections['sales']['agency'])
    <div style="margin-bottom:{{ $sections['sales']['market'] ? '1rem' : '0' }};">
        <div style="margin-bottom:0.5rem;">{!! $sideChip('agency') !!}</div>
        @include('corex.market-intelligence._suburb-report-stat-row', ['stats' => [
            ['label' => 'Sold by the agency', 'value' => number_format(count($sold)), 'color' => '#2f6b45'],
        ]])
    </div>
    @endif

    @if($sections['sales']['market'])
    <div>
        <div style="margin-bottom:0.5rem;">{!! $sideChip('market') !!}</div>
        @if(!empty($cmaSalesRows))
        @foreach($cmaSalesRows as $row)
        <div style="font-size:0.85rem; color:#1b2a2c; padding:0.3rem 0;">
            <strong>{{ number_format($row['count']) }}</strong> area sales in {{ $row['year'] }} — <span style="color:#5c6c6d;">{{ $row['report_type'] }}</span>
        </div>
        @endforeach
        @else
        <p style="font-size:0.8rem; color:#8a9697; margin:0;">No CMA report on file carries an area sales count for this suburb.</p>
        @endif
    </div>
    @endif
</div>
@endif

{{-- ================= Sold & under offer, with price distribution — AGENCY / MARKET ================= --}}
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
    $soHasAny = count($sold) > 0 || count($underOffer) > 0 || $marketHasSoldUnderOffer;
@endphp
@if($sections['sold_under_offer']['show'] && $soHasAny)
<div style="background:#ffffff; border:1px solid #e3ddc9; border-radius:10px; padding:1.1rem 1.3rem; margin-bottom:1rem; break-inside:avoid;">
    <div style="font-size:1.05rem; font-weight:700; color:#1b2a2c; margin-bottom:0.2rem;">Sold &amp; under offer</div>
    <p style="font-size:0.8rem; color:#5c6c6d; margin:0 0 0.9rem;">"Sold" means granted and registered; "under offer" means an offer is in progress but not yet granted or registered.</p>

    @if($sections['sold_under_offer']['agency'] && (count($sold) > 0 || count($underOffer) > 0))
    <div style="margin-bottom:1rem;">
        <div style="margin-bottom:0.5rem;">{!! $sideChip('agency') !!}</div>

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

    @if($sections['sold_under_offer']['market'])
    <div>
        <div style="margin-bottom:0.5rem;">{!! $sideChip('market') !!}</div>
        @if($marketHasSoldUnderOffer)
        @include('corex.market-intelligence._suburb-report-stat-row', ['stats' => [
            ['label' => 'Sold (P24)', 'value' => number_format($market['sold']['p24']), 'color' => '#96660a'],
            ['label' => 'Sold (PP)', 'value' => number_format($market['sold']['pp']), 'color' => '#96660a'],
            ['label' => 'Under offer (P24)', 'value' => number_format($market['under_offer']['p24']), 'color' => '#96660a'],
            ['label' => 'Under offer (PP)', 'value' => number_format($market['under_offer']['pp']), 'color' => '#96660a'],
        ]])
        @else
        <p style="font-size:0.8rem; color:#8a9697; margin:0;">No portal sold/under-offer activity captured for this suburb yet.</p>
        @endif
    </div>
    @endif
</div>
@endif

{{-- ================= Price reduction activity — AGENCY / MARKET ================= --}}
@php
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
    $prHasAny = count($priceReductions) > 0 || $marketHasPriceReductions;
@endphp
@if($sections['price_reductions']['show'] && $prHasAny)
<div style="background:#ffffff; border:1px solid #e3ddc9; border-radius:10px; padding:1.1rem 1.3rem; margin-bottom:1rem; break-inside:avoid;">
    <div style="font-size:1.05rem; font-weight:700; color:#1b2a2c; margin-bottom:0.2rem;">Price reduction activity</div>
    <p style="font-size:0.8rem; color:#5c6c6d; margin:0 0 0.9rem;">Reductions on the agency's own listings, next to what MIC has captured across the portals for this suburb.</p>

    @if($sections['price_reductions']['agency'] && count($priceReductions) > 0)
    <div style="margin-bottom:{{ $sections['price_reductions']['market'] ? '1rem' : '0' }};">
        <div style="margin-bottom:0.5rem;">{!! $sideChip('agency') !!}</div>
        <p style="font-size:0.8rem; color:#5c6c6d; margin:0 0 0.6rem;">{{ number_format(count($priceReductions)) }} price change{{ count($priceReductions) === 1 ? '' : 's' }} on the agency's own listings{{ $avgReductionPct !== null ? ', averaging ' . number_format(abs($avgReductionPct), 1) . '% down' : '' }}.</p>
        @if(!empty($reductionCols))
        <div style="font-size:0.7rem; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; color:#8a9697; margin-bottom:0.3rem;">Reductions per month, last 12 months</div>
        @include('corex.market-intelligence._suburb-report-vbars', ['cols' => $reductionCols, 'color' => '#9c3a30'])
        @endif
    </div>
    @endif

    @if($sections['price_reductions']['market'])
    <div>
        <div style="margin-bottom:0.5rem;">{!! $sideChip('market') !!}</div>
        @if($marketHasPriceReductions)
        @include('corex.market-intelligence._suburb-report-stat-row', ['stats' => [
            ['label' => 'Reduced on P24 (last 12mo)', 'value' => number_format($market['price_reductions']['counts']['p24']), 'color' => '#9c3a30'],
            ['label' => 'Reduced on PP (last 12mo)', 'value' => number_format($market['price_reductions']['counts']['pp']), 'color' => '#9c3a30'],
        ]])
        <p style="font-size:0.72rem; color:#8a9697; margin:0.5rem 0 0;">Count only — the portals do not give MIC the old/new amount for a market-wide reduction, only that one happened.</p>
        @else
        <p style="font-size:0.8rem; color:#8a9697; margin:0;">No portal price-reduction activity captured for this suburb yet.</p>
        @endif
    </div>
    @endif
</div>
@endif

{{-- ================= Buyer demand vs available stock ================= --}}
@if($sections['buyer_demand']['show'] && $buyersWatching > 0)
<div style="background:#ffffff; border:1px solid #e3ddc9; border-radius:10px; padding:1.1rem 1.3rem; margin-bottom:1rem; break-inside:avoid;">
    <div style="font-size:1.05rem; font-weight:700; color:#1b2a2c; margin-bottom:0.2rem;">Buyer demand vs available stock</div>
    <p style="font-size:0.8rem; color:#5c6c6d; margin:0 0 0.9rem;">Active buyers currently watching this suburb, from live Core Match wishlists (buy intent only — rental searches excluded), against what's actually on the market.</p>
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
@if(!$layerA['available'] && count($sold) === 0 && count($underOffer) === 0 && $stockListings->isEmpty() && count($priceReductions) === 0 && $buyersWatching === 0 && !($market['available'] ?? false))
<div style="background:#ffffff; border:1px solid #e3ddc9; border-radius:10px; padding:2.5rem 1.5rem; text-align:center; margin-bottom:1rem;">
    <div style="font-size:1rem; font-weight:700; color:#1b2a2c; margin-bottom:0.3rem;">Nothing on file for {{ $data['suburb']['name'] ?? 'this suburb' }} yet</div>
    <p style="font-size:0.85rem; color:#5c6c6d; margin:0;">No CoreX stock, sales, or buyer activity, no portal capture, and no CMA report imported for this suburb.</p>
</div>
@endif
