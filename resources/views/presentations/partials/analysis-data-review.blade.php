{{-- ══════════════════════════════════════════════════════════════════════════
     EXTRACTED DATA REVIEW — 7 Sections
     All computations performed in AnalysisDataService (not here).
══════════════════════════════════════════════════════════════════════════ --}}
@if(isset($analysisData) && ($analysisData['data_counts']['fields'] > 0 || $analysisData['data_counts']['sold_comps'] > 0))

@php
    $subject  = $analysisData['subject_property'];
    $suburb   = $analysisData['suburb_overview'];
    $comps    = $analysisData['comparable_sales'];
    $cma      = $analysisData['cma_valuation'];
    $active   = $analysisData['active_competition'];
    $stock    = $analysisData['stock_absorption'] ?? [];
    $holding  = $analysisData['holding_cost'];
    $insights = $analysisData['key_insights'];
    $counts   = $analysisData['data_counts'];
    $isSectional = ($analysisData['is_sectional'] ?? false)
                || stripos($presentation->property_type ?? '', 'sectional') !== false;
    $sizeLabel   = $isSectional ? 'Unit m²' : 'Erf m²';
@endphp

<div class="mb-6">
    <div class="flex items-center justify-between mb-4">
        <h2 class="ds-section-header" style="font-size:1.125rem;">Extracted Data Review</h2>
        <span class="text-xs text-gray-400">
            {{ $counts['fields'] }} fields &middot;
            {{ $counts['sold_comps'] }} comps &middot;
            {{ $counts['active_listings'] }} properties
        </span>
    </div>

    {{-- ── 1. SUBJECT PROPERTY SUMMARY ──────────────────────────────────── --}}
    <div class="ds-status-card mb-4" style="border-left-color: var(--ds-cyan);">
        <h3 class="ds-section-header">1. Subject Property</h3>
        <div class="grid grid-cols-2 gap-x-8 gap-y-2 text-sm md:grid-cols-3 lg:grid-cols-4">
            <div>
                <span class="text-xs text-gray-400">Address</span>
                <p class="font-medium text-gray-800">{{ $subject['address'] ?? '—' }}</p>
            </div>
            <div>
                <span class="text-xs text-gray-400">Suburb</span>
                <p class="font-medium text-gray-800">{{ $subject['suburb'] ?? '—' }}</p>
            </div>
            <div>
                <span class="text-xs text-gray-400">Erf Number</span>
                <p class="font-medium text-gray-800">{{ $subject['erf'] ?? '—' }}</p>
            </div>
            <div>
                <span class="text-xs text-gray-400">Extent</span>
                <p class="font-medium text-gray-800">
                    @if($subject['extent_m2'])
                        {{ number_format($subject['extent_m2']) }} m&sup2;
                    @else
                        —
                    @endif
                </p>
            </div>
            <div>
                <span class="text-xs text-gray-400">GPS</span>
                <p class="font-medium text-gray-800 text-xs">{{ $subject['gps'] ?? '—' }}</p>
            </div>
            <div>
                <span class="text-xs text-gray-400">Property Type</span>
                <p class="font-medium text-gray-800">{{ ucfirst($subject['property_type'] ?? '—') }}</p>
            </div>
            <div>
                <span class="text-xs text-gray-400">Bedrooms</span>
                <p class="font-medium text-gray-800">{{ $subject['bedrooms'] ?? '—' }}</p>
            </div>
            <div>
                <span class="text-xs text-gray-400">Purchase Date</span>
                <p class="font-medium text-gray-800">{{ $subject['purchase_date'] ?? '—' }}</p>
            </div>
            <div>
                <span class="text-xs text-gray-400">Purchase Price</span>
                <p class="font-medium text-gray-800">
                    @if($subject['purchase_price'])
                        R {{ number_format($subject['purchase_price']) }}
                    @else
                        —
                    @endif
                </p>
            </div>
            <div>
                <span class="text-xs text-gray-400">Indexed Value</span>
                <p class="font-medium text-gray-800">
                    @if($subject['indexed_value'])
                        R {{ number_format($subject['indexed_value']) }}
                    @else
                        —
                    @endif
                </p>
            </div>
            <div>
                <span class="text-xs text-gray-400">CAGR</span>
                <p class="font-medium text-gray-800">
                    @if($subject['cagr'])
                        {{ number_format($subject['cagr'], 2) }}%
                    @else
                        —
                    @endif
                </p>
            </div>
            <div>
                <span class="text-xs text-gray-400">Municipal Valuation</span>
                <p class="font-medium text-gray-800">
                    @if($subject['municipal_value'])
                        R {{ number_format($subject['municipal_value']) }}
                        @if($subject['municipal_year'])
                            <span class="text-gray-400 text-xs">({{ $subject['municipal_year'] }})</span>
                        @endif
                    @else
                        —
                    @endif
                </p>
            </div>
            <div>
                <span class="text-xs text-gray-400">Asking Price</span>
                <p class="font-medium text-gray-800">
                    @if($subject['asking_price'])
                        R {{ number_format($subject['asking_price']) }}
                    @else
                        <span class="text-amber-500 italic">Not set — enter in form above</span>
                    @endif
                </p>
            </div>
            <div>
                <span class="text-xs text-gray-400">Monthly Holding Cost</span>
                <p class="font-medium text-gray-800">
                    @if($subject['monthly_holding_total'] > 0)
                        R {{ number_format($subject['monthly_holding_total']) }}
                    @else
                        <span class="text-gray-400">R 0</span>
                    @endif
                </p>
            </div>
        </div>
    </div>

    {{-- ── 2. SUBURB MARKET OVERVIEW ────────────────────────────────────── --}}
    @if($suburb['latest_year'])
    <div class="ds-status-card mb-4" style="border-left-color: var(--ds-cyan);">
        <h3 class="ds-section-header">2. Suburb Market Overview</h3>
        <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
            <div class="bg-gray-50 rounded-lg p-3 text-center">
                <span class="text-xs text-gray-400 block">Year</span>
                <p class="text-lg font-bold text-gray-800">{{ $suburb['latest_year'] }}</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3 text-center">
                <span class="text-xs text-gray-400 block">Sales Count</span>
                <p class="text-lg font-bold text-gray-800">{{ $suburb['sales_count'] ?? '—' }}</p>
            </div>
            <div class="bg-sky-50 rounded-lg p-3 text-center">
                <span class="text-xs text-[#38bfe0] block">Median Price</span>
                <p class="text-lg font-bold text-[#0b2a4a]">
                    @if($suburb['median_price'])
                        R {{ number_format($suburb['median_price']) }}
                    @else
                        —
                    @endif
                </p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3 text-center">
                <span class="text-xs text-gray-400 block">Low Range</span>
                <p class="text-sm font-semibold text-gray-700">
                    @if($suburb['low_range'])
                        R {{ number_format($suburb['low_range']) }}
                    @else
                        —
                    @endif
                </p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3 text-center">
                <span class="text-xs text-gray-400 block">High Range</span>
                <p class="text-sm font-semibold text-gray-700">
                    @if($suburb['high_range'])
                        R {{ number_format($suburb['high_range']) }}
                    @else
                        —
                    @endif
                </p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3 text-center">
                <span class="text-xs text-gray-400 block">Maximum</span>
                <p class="text-sm font-semibold text-gray-700">
                    @if($suburb['max_price'])
                        R {{ number_format($suburb['max_price']) }}
                    @else
                        —
                    @endif
                </p>
            </div>
        </div>
    </div>
    @endif

    {{-- ── STOCK ABSORPTION ──────────────────────────────────────────────── --}}
    @if(!empty($stock['total_active_stock']) && !empty($stock['months_of_supply']))
    @php
        $absColor = match($stock['absorption_color'] ?? '') {
            'green'  => ['bg' => 'bg-emerald-50', 'border' => 'border-emerald-200', 'text' => 'text-emerald-700', 'badge' => 'bg-emerald-100 text-emerald-800'],
            'amber'  => ['bg' => 'bg-amber-50',   'border' => 'border-amber-200',   'text' => 'text-amber-700',   'badge' => 'bg-amber-100 text-amber-800'],
            'orange' => ['bg' => 'bg-orange-50',   'border' => 'border-orange-200',  'text' => 'text-orange-700',  'badge' => 'bg-orange-100 text-orange-800'],
            'red'    => ['bg' => 'bg-red-50',      'border' => 'border-red-200',     'text' => 'text-red-700',     'badge' => 'bg-red-100 text-red-800'],
            default  => ['bg' => 'bg-gray-50',     'border' => 'border-gray-200',    'text' => 'text-gray-700',    'badge' => 'bg-gray-100 text-gray-800'],
        };
    @endphp
    <div class="{{ $absColor['bg'] }} {{ $absColor['border'] }} border rounded-xl p-5 mb-4">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold {{ $absColor['text'] }} uppercase tracking-wide">Stock Absorption Rate</h3>
            <span class="text-xs px-2.5 py-1 rounded-full font-semibold {{ $absColor['badge'] }}">{{ $stock['absorption_label'] }}</span>
        </div>
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <div class="text-center">
                <span class="text-xs text-gray-400 block">Active Listings</span>
                <p class="text-xl font-bold {{ $absColor['text'] }}">{{ $stock['total_active_stock'] }}</p>
                @if($stock['stock_source'] === 'portal_search')
                    <span class="text-xs text-gray-400">from P24 search</span>
                @endif
            </div>
            <div class="text-center">
                <span class="text-xs text-gray-400 block">Sales / Year</span>
                <p class="text-xl font-bold text-gray-800">{{ $stock['annual_sales'] }}</p>
                <span class="text-xs text-gray-400">{{ number_format($stock['monthly_sales'], 1) }} / month</span>
            </div>
            <div class="text-center">
                <span class="text-xs text-gray-400 block">Months of Supply</span>
                <p class="text-xl font-bold {{ $absColor['text'] }}">{{ number_format($stock['months_of_supply'], 1) }}</p>
            </div>
            <div class="text-center">
                <span class="text-xs text-gray-400 block">Years of Supply</span>
                <p class="text-xl font-bold {{ $absColor['text'] }}">{{ number_format($stock['years_of_supply'], 1) }}</p>
            </div>
        </div>
        @if($stock['search_total_count'] && $stock['listings_with_price'] < $stock['search_total_count'])
        <p class="text-xs {{ $absColor['text'] }} mt-3 opacity-75">
            Price data available for {{ $stock['listings_with_price'] }} of {{ $stock['search_total_count'] }} listings &mdash; actual competition may be higher.
        </p>
        @endif
    </div>
    @endif

    {{-- ── NEW LISTING INFLOW & ABSORPTION ──────────────────────────────── --}}
    @php $inflow = $analysisData['inflow_absorption'] ?? []; @endphp
    @if(!empty($inflow))
    <div class="ds-status-card mb-4" style="border-left-color: var(--ds-cyan);" id="inflow-absorption">
        <h3 class="ds-section-header">New Listing Inflow &amp; Absorption</h3>

        @if(empty($inflow['has_data']))
            {{-- No data state --}}
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                <p class="text-sm text-gray-500">
                    No P24 alert data available yet.
                    @if(!empty($inflow['reason']))
                        {{ $inflow['reason'] }}
                    @endif
                    @if(($inflow['total_p24_listings'] ?? 0) === 0)
                        Import P24 alert emails to see new listing inflow data.
                    @endif
                </p>
            </div>
        @else
            {{-- Row 1: Period cards --}}
            <div class="grid grid-cols-3 gap-3 mb-4">
                <div class="bg-gray-50 rounded-lg p-3 text-center">
                    <span class="text-xs text-gray-400 block">Last 7 Days</span>
                    <p class="text-xl font-bold text-gray-800">{{ $inflow['count_7d'] }}</p>
                    <span class="text-xs text-gray-400">new listings</span>
                </div>
                <div class="bg-gray-50 rounded-lg p-3 text-center">
                    <span class="text-xs text-gray-400 block">Last 30 Days</span>
                    <p class="text-xl font-bold text-gray-800">{{ $inflow['count_30d'] }}</p>
                    <span class="text-xs text-gray-400">new listings</span>
                </div>
                <div class="bg-sky-50 rounded-lg p-3 text-center ring-1 ring-sky-200">
                    <span class="text-xs text-[#38bfe0] block">Last 90 Days</span>
                    <p class="text-xl font-bold text-[#0b2a4a]">{{ $inflow['count_90d'] }}</p>
                    <span class="text-xs text-[#38bfe0]">new listings</span>
                </div>
            </div>

            {{-- Row 2: Inflow rate callout --}}
            @if($inflow['new_listing_rate'] > 0)
            <div class="bg-gray-50 rounded-lg p-4 mb-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-700">Average inflow rate</p>
                        <p class="text-xs text-gray-400 mt-0.5">
                            Based on {{ $inflow['count_90d'] }} similar listings over the past 90 days
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-2xl font-bold text-[#0b2a4a]">{{ $inflow['new_listing_rate'] }}</p>
                        <p class="text-xs text-gray-400">per month</p>
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-2">
                    That's {{ number_format($inflow['new_listing_rate'] * 12, 0) }} new competing listings per year entering the market
                </p>
                @if(!empty($inflow['target_suburbs']))
                <p class="text-xs text-gray-400 mt-1">
                    Matching: {{ $inflow['town_label'] ?? implode(', ', $inflow['target_suburbs']) }}
                    @if(!empty($inflow['target_types']))
                        &middot; {{ implode('/', $inflow['target_types']) }}
                    @endif
                    @if(!empty($inflow['price_range']))
                        &middot; R {{ number_format($inflow['price_range']['low']) }} &ndash; R {{ number_format($inflow['price_range']['high']) }}
                    @endif
                </p>
                @endif
            </div>
            @endif

            {{-- Row 3: Absorption impact --}}
            @if($inflow['net_absorption'] !== null)
            @php
                $trendColor = match($inflow['stock_trend'] ?? '') {
                    'growing'   => ['bg' => 'bg-red-50', 'border' => 'border-red-200', 'text' => 'text-red-700', 'badge' => 'bg-red-100 text-red-800'],
                    'depleting' => ['bg' => 'bg-emerald-50', 'border' => 'border-emerald-200', 'text' => 'text-emerald-700', 'badge' => 'bg-emerald-100 text-emerald-800'],
                    default     => ['bg' => 'bg-amber-50', 'border' => 'border-amber-200', 'text' => 'text-amber-700', 'badge' => 'bg-amber-100 text-amber-800'],
                };
                $trendLabel = match($inflow['stock_trend'] ?? '') {
                    'growing'   => 'Stock Growing',
                    'depleting' => 'Stock Depleting',
                    default     => 'Stock Stable',
                };
            @endphp
            <div class="grid grid-cols-1 gap-4 mb-4 md:grid-cols-2">
                {{-- Left: Standard vs Adjusted --}}
                <div class="{{ $trendColor['bg'] }} {{ $trendColor['border'] }} border rounded-lg p-4">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-xs font-semibold {{ $trendColor['text'] }} uppercase tracking-wide">Adjusted Absorption</p>
                        <span class="text-xs px-2.5 py-1 rounded-full font-semibold {{ $trendColor['badge'] }}">{{ $trendLabel }}</span>
                    </div>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Standard supply</span>
                            <span class="font-medium text-gray-800">
                                {{ $inflow['active_listings'] }} &divide; {{ $inflow['monthly_sales'] }}/mo
                                @if(!empty($stock['months_of_supply']))
                                    = {{ number_format($stock['months_of_supply'], 1) }} months
                                @endif
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Net absorption</span>
                            <span class="font-medium {{ $trendColor['text'] }}">
                                {{ $inflow['monthly_sales'] }} sold &minus; {{ $inflow['new_listing_rate'] }} new
                                = {{ $inflow['net_absorption'] > 0 ? '+' : '' }}{{ $inflow['net_absorption'] }}/mo
                            </span>
                        </div>
                        @if($inflow['adjusted_months_supply'] !== null)
                        <div class="flex justify-between pt-1 border-t {{ $trendColor['border'] }}">
                            <span class="text-gray-600 font-medium">Adjusted supply</span>
                            <span class="font-bold {{ $trendColor['text'] }}">{{ $inflow['adjusted_months_supply'] }} months</span>
                        </div>
                        @endif
                        @if($inflow['pool_after_3_months'] !== null)
                        <div class="flex justify-between">
                            <span class="text-gray-600">Pool after 3 months</span>
                            <span class="font-medium {{ $trendColor['text'] }}">~{{ $inflow['pool_after_3_months'] }} properties</span>
                        </div>
                        @endif
                    </div>
                </div>

                {{-- Right: Selling probability --}}
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Selling Probability</p>
                    <div class="space-y-3">
                        @if($inflow['monthly_probability'] !== null)
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="text-gray-600">Monthly chance</span>
                                <span class="font-bold text-gray-800">{{ $inflow['monthly_probability'] }}%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-[#00b4d8] h-2 rounded-full" style="width: {{ min($inflow['monthly_probability'], 100) }}%"></div>
                            </div>
                        </div>
                        @endif
                        @if($inflow['prob_3_months'] !== null)
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="text-gray-600">3-month chance</span>
                                <span class="font-bold text-gray-800">{{ $inflow['prob_3_months'] }}%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-[#0b2a4a] h-2 rounded-full" style="width: {{ min($inflow['prob_3_months'], 100) }}%"></div>
                            </div>
                        </div>
                        @endif
                        @if($inflow['adjusted_prob_3_months'] !== null && $inflow['adjusted_prob_3_months'] != $inflow['prob_3_months'])
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="text-gray-600">Adjusted 3-month <span class="text-xs text-gray-400">(with inflow)</span></span>
                                <span class="font-bold {{ $inflow['adjusted_prob_3_months'] < ($inflow['prob_3_months'] ?? 0) ? 'text-red-600' : 'text-emerald-600' }}">{{ $inflow['adjusted_prob_3_months'] }}%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="{{ $inflow['adjusted_prob_3_months'] < ($inflow['prob_3_months'] ?? 0) ? 'bg-red-400' : 'bg-emerald-400' }} h-2 rounded-full"
                                     style="width: {{ min($inflow['adjusted_prob_3_months'], 100) }}%"></div>
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
            @endif

            {{-- Row 4: Key insight narrative --}}
            @if(!empty($inflow['narrative']))
            @php
                $narrativeBg = match($inflow['stock_trend'] ?? '') {
                    'growing'   => 'bg-red-50 border-red-200 text-red-800',
                    'depleting' => 'bg-emerald-50 border-emerald-200 text-emerald-800',
                    default     => 'bg-amber-50 border-amber-200 text-amber-800',
                };
            @endphp
            <div class="{{ $narrativeBg }} border rounded-lg p-4">
                <p class="text-sm font-medium leading-relaxed">{{ $inflow['narrative'] }}</p>
            </div>
            @endif

            {{-- Data source note --}}
            <p class="text-xs text-gray-400 mt-3">
                Source: P24 alert email imports ({{ number_format($inflow['total_p24_listings']) }} total listings in database)
            </p>
        @endif
    </div>
    @endif

    {{-- ── PROPCON LISTING PERFORMANCE ────────────────────────────────────── --}}
    @php $propcon = $analysisData['propcon_insights'] ?? []; @endphp
    @if(!empty($propcon))
    <div class="ds-status-card mb-4" style="border-left-color: var(--ds-cyan);" id="propcon-insights">
        <h3 class="ds-section-header">Listing Performance &mdash; Similar Properties</h3>

        @if(empty($propcon['has_data']))
            {{-- Empty state --}}
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                <p class="text-sm text-gray-500">
                    {{ $propcon['reason'] ?? 'No similar PropCon listings found matching your criteria.' }}
                </p>
            </div>
        @else
            {{-- Criteria label --}}
            @if(!empty($propcon['criteria']))
            <p class="text-xs text-gray-400 mb-3">
                Matching: <span class="font-medium text-gray-500">{{ $propcon['criteria'] }}</span>
                &middot; {{ $propcon['similar_count'] }} similar {{ $propcon['similar_count'] === 1 ? 'listing' : 'listings' }}
            </p>
            @endif

            {{-- Row 1: Benchmark cards --}}
            <div class="grid grid-cols-2 gap-3 mb-4 md:grid-cols-4">
                <div class="bg-gray-50 rounded-lg p-3 text-center">
                    <span class="text-xs text-gray-400 block">Avg Views</span>
                    <p class="text-xl font-bold text-gray-800">{{ $propcon['avg_views'] !== null ? number_format($propcon['avg_views']) : '—' }}</p>
                    @if($propcon['min_views'] !== null && $propcon['max_views'] !== null)
                    <span class="text-xs text-gray-400">{{ number_format($propcon['min_views']) }} – {{ number_format($propcon['max_views']) }}</span>
                    @endif
                </div>
                <div class="bg-gray-50 rounded-lg p-3 text-center">
                    <span class="text-xs text-gray-400 block">Avg Buyer Matches</span>
                    <p class="text-xl font-bold text-gray-800">{{ $propcon['avg_matches'] !== null ? number_format($propcon['avg_matches']) : '—' }}</p>
                    @if($propcon['min_matches'] !== null && $propcon['max_matches'] !== null)
                    <span class="text-xs text-gray-400">{{ $propcon['min_matches'] }} – {{ $propcon['max_matches'] }}</span>
                    @endif
                </div>
                <div class="bg-gray-50 rounded-lg p-3 text-center">
                    <span class="text-xs text-gray-400 block">Avg Days on Market</span>
                    <p class="text-xl font-bold text-gray-800">{{ $propcon['avg_days_on_market'] !== null ? $propcon['avg_days_on_market'] . ' days' : '—' }}</p>
                    @if($propcon['min_days'] !== null && $propcon['max_days'] !== null)
                    <span class="text-xs text-gray-400">{{ $propcon['min_days'] }} – {{ $propcon['max_days'] }} days</span>
                    @endif
                </div>
                <div class="bg-sky-50 rounded-lg p-3 text-center ring-1 ring-sky-200">
                    <span class="text-xs text-[#38bfe0] block">Avg Views/Day</span>
                    <p class="text-xl font-bold text-[#0b2a4a]">{{ $propcon['avg_views_per_day'] !== null ? $propcon['avg_views_per_day'] . '/day' : '—' }}</p>
                </div>
            </div>

            {{-- Row 2: Similar listings table --}}
            @if(!empty($propcon['listings']))
            <div class="overflow-x-auto mb-4">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-400 border-b">
                            <th class="pb-2 pr-3 font-medium">Address</th>
                            <th class="pb-2 pr-3 font-medium">Type</th>
                            <th class="pb-2 pr-3 font-medium text-right">Price</th>
                            <th class="pb-2 pr-3 font-medium text-center">Beds</th>
                            <th class="pb-2 pr-3 font-medium text-right">Views</th>
                            <th class="pb-2 pr-3 font-medium text-right">Matches</th>
                            <th class="pb-2 pr-3 font-medium text-right">Days</th>
                            <th class="pb-2 font-medium text-right">Views/Day</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($propcon['listings'] as $row)
                        <tr class="{{ $row['is_subject'] ? 'bg-sky-50 font-semibold' : 'hover:bg-gray-50' }}">
                            <td class="py-2 pr-3 text-gray-800 text-xs max-w-[220px] truncate">
                                {{ $row['address'] ?? '—' }}
                                @if($row['is_subject'])
                                    <span class="ml-1 text-[10px] px-1.5 py-0.5 rounded bg-sky-100 text-sky-700 font-medium">YOUR LISTING</span>
                                @endif
                            </td>
                            <td class="py-2 pr-3 text-gray-600 text-xs">{{ $row['type'] ?? '—' }}</td>
                            <td class="py-2 pr-3 text-right font-medium text-gray-800">
                                @if($row['price'])
                                    R {{ number_format($row['price']) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="py-2 pr-3 text-center text-gray-600">{{ $row['beds'] ?? '—' }}</td>
                            <td class="py-2 pr-3 text-right text-gray-600">{{ $row['views'] !== null ? number_format($row['views']) : '—' }}</td>
                            <td class="py-2 pr-3 text-right text-gray-600">{{ $row['matches'] ?? '—' }}</td>
                            <td class="py-2 pr-3 text-right text-gray-600">{{ $row['days_on_market'] ?? '—' }}</td>
                            <td class="py-2 text-right text-gray-600">{{ $row['views_per_day'] ?? '—' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif

            {{-- Subject property stats (if found) --}}
            @if($propcon['subject_found'] && $propcon['subject_stats'])
            @php $ss = $propcon['subject_stats']; @endphp
            <div class="bg-sky-50 border border-sky-200 rounded-lg p-4 mb-4">
                <p class="text-xs font-semibold text-sky-700 uppercase tracking-wide mb-2">Your Listing Performance</p>
                <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                    <div class="text-center">
                        <span class="text-xs text-gray-400 block">Views</span>
                        <p class="text-lg font-bold text-[#0b2a4a]">{{ $ss['views'] !== null ? number_format($ss['views']) : '—' }}</p>
                        @if($ss['rank_views'])
                        <span class="text-xs text-sky-600">{{ $ss['rank_views'] }}</span>
                        @endif
                    </div>
                    <div class="text-center">
                        <span class="text-xs text-gray-400 block">Matches</span>
                        <p class="text-lg font-bold text-[#0b2a4a]">{{ $ss['matches'] ?? '—' }}</p>
                        @if($ss['rank_matches'])
                        <span class="text-xs text-sky-600">{{ $ss['rank_matches'] }}</span>
                        @endif
                    </div>
                    <div class="text-center">
                        <span class="text-xs text-gray-400 block">Days on Market</span>
                        <p class="text-lg font-bold text-[#0b2a4a]">{{ $ss['days_on_market'] ?? '—' }}</p>
                    </div>
                    <div class="text-center">
                        <span class="text-xs text-gray-400 block">Views/Day</span>
                        <p class="text-lg font-bold text-[#0b2a4a]">{{ $ss['views_per_day'] ?? '—' }}</p>
                    </div>
                </div>
            </div>
            @endif

            {{-- Row 3: Market signal insight bar --}}
            @if(!empty($propcon['market_signal_text']))
            @php
                $signalStyle = match($propcon['market_signal'] ?? '') {
                    'price_issue'       => 'bg-red-50 border-red-200 text-red-800',
                    'visibility_issue'  => 'bg-amber-50 border-amber-200 text-amber-800',
                    'healthy'           => 'bg-emerald-50 border-emerald-200 text-emerald-800',
                    'new_listing'       => 'bg-blue-50 border-blue-200 text-blue-800',
                    default             => 'bg-gray-50 border-gray-200 text-gray-800',
                };
            @endphp
            <div class="{{ $signalStyle }} border rounded-lg p-4 mb-3">
                <p class="text-sm font-medium leading-relaxed">{{ $propcon['market_signal_text'] }}</p>
            </div>
            @endif

            {{-- Source note --}}
            <p class="text-xs text-gray-400 mt-2">
                Source: PropCon agency data &middot; {{ number_format($propcon['total_propcon_listings']) }} active listings in database &middot; Updated weekly
            </p>
        @endif
    </div>
    @endif

    {{-- ── PRICE POSITION & BRACKETS ─────────────────────────────────────── --}}
    @php
        $pricePos = $analysisData['price_position'] ?? [];
        $priceBrk = $analysisData['price_brackets'] ?? [];
    @endphp
    @if(!empty($pricePos['has_data']) || !empty($priceBrk['has_data']))
    <div class="ds-status-card mb-4" style="border-left-color: var(--ds-cyan);">
        <h3 class="ds-section-header">Market Position & Price Distribution</h3>

        {{-- Price Position Ranking --}}
        @if(!empty($pricePos['has_data']))
        @php
            $posColors = match($pricePos['position_color'] ?? '') {
                'green'  => ['bg' => 'bg-emerald-50', 'border' => 'border-emerald-200', 'text' => 'text-emerald-700', 'badge' => 'bg-emerald-100 text-emerald-800'],
                'amber'  => ['bg' => 'bg-amber-50',   'border' => 'border-amber-200',   'text' => 'text-amber-700',   'badge' => 'bg-amber-100 text-amber-800'],
                'orange' => ['bg' => 'bg-orange-50',  'border' => 'border-orange-200',  'text' => 'text-orange-700',  'badge' => 'bg-orange-100 text-orange-800'],
                'red'    => ['bg' => 'bg-red-50',     'border' => 'border-red-200',     'text' => 'text-red-700',     'badge' => 'bg-red-100 text-red-800'],
                default  => ['bg' => 'bg-gray-50',    'border' => 'border-gray-200',    'text' => 'text-gray-700',    'badge' => 'bg-gray-100 text-gray-800'],
            };
        @endphp
        <div class="{{ $posColors['bg'] }} {{ $posColors['border'] }} border rounded-lg p-4 mb-4">
            <div class="flex items-center justify-between mb-2">
                <p class="text-xs font-medium {{ $posColors['text'] }}">Your Price Position</p>
                <span class="text-xs px-2.5 py-1 rounded-full font-semibold {{ $posColors['badge'] }}">{{ $pricePos['position_label'] }}</span>
            </div>
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <div class="text-center">
                    <span class="text-xs text-gray-400 block">Rank</span>
                    <p class="text-xl font-bold {{ $posColors['text'] }}">{{ $pricePos['price_rank'] }} <span class="text-sm font-normal text-gray-400">of {{ $pricePos['total_listings'] }}</span></p>
                </div>
                <div class="text-center">
                    <span class="text-xs text-gray-400 block">Priced Higher</span>
                    <p class="text-xl font-bold text-gray-800">{{ $pricePos['listings_more_expensive'] }}</p>
                </div>
                <div class="text-center">
                    <span class="text-xs text-gray-400 block">Priced Lower</span>
                    <p class="text-xl font-bold text-gray-800">{{ $pricePos['listings_cheaper'] }}</p>
                </div>
                <div class="text-center">
                    <span class="text-xs text-gray-400 block">Percentile</span>
                    <p class="text-xl font-bold {{ $posColors['text'] }}">{{ $pricePos['price_percentile'] }}%</p>
                    <span class="text-xs text-gray-400">more expensive than</span>
                </div>
            </div>
        </div>
        @endif

        {{-- Price Bracket Distribution --}}
        @if(!empty($priceBrk['has_data']) && !empty($priceBrk['brackets']))
        <div>
            <p class="text-xs text-gray-400 mb-2 font-medium">Price Distribution (R 500K brackets) — {{ $priceBrk['total_priced'] }} listings with price data</p>
            <div class="space-y-1.5">
                @foreach($priceBrk['brackets'] as $bracket)
                <div class="flex items-center gap-3 {{ $bracket['contains_asking'] ? 'bg-sky-50 rounded-lg px-2 py-1.5 -mx-2 border border-sky-200' : '' }}">
                    <span class="text-xs text-gray-500 w-44 flex-shrink-0 text-right font-mono">{{ $bracket['label'] }}</span>
                    <div class="flex-1 bg-gray-100 rounded-full h-5 overflow-hidden">
                        @if($bracket['bar_pct'] > 0)
                        <div class="h-full rounded-full {{ $bracket['contains_asking'] ? 'bg-sky-500' : 'bg-gray-400' }}"
                             style="width: {{ max($bracket['bar_pct'], 4) }}%"></div>
                        @endif
                    </div>
                    <span class="text-xs font-semibold text-gray-700 w-8 text-right">{{ $bracket['count'] }}</span>
                    @if($bracket['contains_asking'])
                        <span class="text-xs text-[#00b4d8] font-medium flex-shrink-0">Your price</span>
                    @endif
                </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>
    @endif

    {{-- ── 3. COMPARABLE SALES ──────────────────────────────────────────── --}}
    @if($comps['vicinity']['count'] > 0 || $comps['cma_comps']['count'] > 0 || $comps['street_sales']['count'] > 0)
    <div class="ds-status-card mb-4" style="border-left-color: var(--ds-cyan);">
        <h3 class="ds-section-header">3. Comparable Sales</h3>

        @php
            $compSections = [
                ['label' => 'Vicinity Sales',  'data' => $comps['vicinity']],
                ['label' => 'CMA Comps',       'data' => $comps['cma_comps']],
                ['label' => 'Street Sales',    'data' => $comps['street_sales']],
            ];
            $firstOpen = true;
        @endphp

        @foreach($compSections as $section)
            @if($section['data']['count'] > 0)
            <details class="mb-3 border border-gray-200 rounded-lg" {{ $firstOpen ? 'open' : '' }}>
                @php $firstOpen = false; @endphp
                <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50 select-none flex items-center justify-between">
                    <span>{{ $section['label'] }}</span>
                    <span class="px-2 py-0.5 rounded-full text-xs bg-sky-100 text-[#0b2a4a] font-medium">{{ $section['data']['count'] }}</span>
                </summary>
                <div class="px-4 pb-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-gray-400 border-b">
                                <th class="pb-2 pr-3 font-medium">Address</th>
                                <th class="pb-2 pr-3 font-medium text-right">Dist (m)</th>
                                <th class="pb-2 pr-3 font-medium text-right">{{ $sizeLabel }}</th>
                                <th class="pb-2 pr-3 font-medium">Sale Date</th>
                                <th class="pb-2 pr-3 font-medium text-right">Sale Price</th>
                                <th class="pb-2 font-medium text-right">R/m&sup2;</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($section['data']['rows'] as $row)
                            <tr class="hover:bg-gray-50">
                                <td class="py-2 pr-3 text-gray-800 text-xs">{{ $row['address'] ?? '—' }}</td>
                                <td class="py-2 pr-3 text-right text-gray-600">{{ $row['distance_m'] ?? '—' }}</td>
                                <td class="py-2 pr-3 text-right text-gray-600">{{ $row['extent_m2'] ? number_format($row['extent_m2']) : '—' }}</td>
                                <td class="py-2 pr-3 text-gray-600">{{ $row['sale_date'] ?? '—' }}</td>
                                <td class="py-2 pr-3 text-right font-medium text-gray-800">
                                    @if($row['sale_price'])
                                        R {{ number_format($row['sale_price']) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="py-2 text-right text-gray-600">
                                    @if($row['price_per_m2'])
                                        R {{ number_format($row['price_per_m2']) }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-200 font-semibold text-xs">
                                <td class="pt-2 pr-3 text-gray-500" colspan="4">
                                    Avg ({{ $section['data']['count'] }} sales)
                                </td>
                                <td class="pt-2 pr-3 text-right text-gray-800">
                                    @if($section['data']['avg_price'])
                                        R {{ number_format($section['data']['avg_price']) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="pt-2 text-right text-gray-800">
                                    @if($section['data']['avg_price_per_m2'])
                                        R {{ number_format($section['data']['avg_price_per_m2']) }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </details>
            @endif
        @endforeach
    </div>
    @endif

    {{-- ── 4. CMA VALUATION ─────────────────────────────────────────────── --}}
    @if($cma['cma_middle'] || $cma['vicinity_middle'])
    <div class="ds-status-card mb-4" style="border-left-color: var(--ds-cyan);">
        <h3 class="ds-section-header">4. CMA Valuation</h3>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            {{-- CMA Range — clickable tiles --}}
            @if($cma['cma_middle'])
            <div>
                <p class="text-xs text-gray-400 mb-2 font-medium">CMA Report Range <span class="text-[#38bfe0]">(click to select)</span></p>
                <div class="flex items-center gap-3">
                    @foreach(['lower' => $cma['cma_lower'], 'middle' => $cma['cma_middle'], 'upper' => $cma['cma_upper']] as $range => $val)
                    @php $isSel = ($cma['selected_range'] ?? 'middle') === $range; @endphp
                    <div class="cma-tile text-center flex-1 rounded-lg p-3 cursor-pointer transition-all
                        {{ $isSel ? 'bg-sky-50 ring-1 ring-sky-200' : 'bg-gray-50 hover:bg-gray-100' }}"
                        data-range="{{ $range }}" data-value="{{ $val }}">
                        <span class="text-xs block {{ $isSel ? 'text-[#38bfe0]' : 'text-gray-400' }}">{{ ucfirst($range) }}</span>
                        <p class="{{ $isSel ? 'font-bold text-[#0b2a4a] text-lg' : 'font-semibold text-gray-700' }}">R {{ number_format($val) }}</p>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Vicinity Range — clickable tiles --}}
            @if($cma['vicinity_middle'])
            <div>
                <p class="text-xs text-gray-400 mb-2 font-medium">Vicinity Sales Range <span class="text-[#38bfe0]">(click to select)</span></p>
                <div class="flex items-center gap-3">
                    @php $vicSel = $presentation->vicinity_selected_range ?? 'middle'; @endphp
                    @foreach(['lower' => $cma['vicinity_lower'], 'middle' => $cma['vicinity_middle'], 'upper' => $cma['vicinity_upper']] as $range => $val)
                    @php $isSel = $vicSel === $range; @endphp
                    <div class="vicinity-tile text-center flex-1 rounded-lg p-3 cursor-pointer transition-all
                        {{ $isSel ? 'bg-sky-50 ring-1 ring-sky-200' : 'bg-gray-50 hover:bg-gray-100' }}"
                        data-range="{{ $range }}" data-value="{{ $val }}">
                        <span class="text-xs block {{ $isSel ? 'text-[#38bfe0]' : 'text-gray-400' }}">{{ ucfirst($range) }}</span>
                        <p class="{{ $isSel ? 'font-bold text-[#0b2a4a] text-lg' : 'font-semibold text-gray-700' }}">R {{ number_format($val) }}</p>
                    </div>
                    @endforeach
                </div>
                @if($cma['vicinity_ppm2'])
                <p class="text-xs text-gray-400 mt-2 text-right">Avg R/m&sup2;: <span class="font-medium text-gray-600">R {{ number_format($cma['vicinity_ppm2']) }}</span></p>
                @endif
            </div>
            @endif
        </div>

        {{-- Asking vs CMA comparison --}}
        @if($cma['asking_price'] && $cma['selected_value'])
        <div id="asking-vs-cma" class="mt-4 p-4 rounded-lg border {{ $cma['is_overpriced'] ? 'bg-red-50 border-red-200' : 'bg-emerald-50 border-emerald-200' }}"
             data-asking="{{ $cma['asking_price'] }}"
             data-cma-lower="{{ $cma['cma_lower'] }}"
             data-cma-middle="{{ $cma['cma_middle'] }}"
             data-cma-upper="{{ $cma['cma_upper'] }}">
            <div class="flex items-center justify-between">
                <div>
                    <p id="asking-cma-label" class="text-xs font-medium {{ $cma['is_overpriced'] ? 'text-red-600' : 'text-emerald-600' }}">
                        Asking Price vs CMA {{ ucfirst($cma['selected_range'] ?? 'middle') }}
                    </p>
                    <p id="asking-cma-values" class="text-sm text-gray-700 mt-1">
                        R {{ number_format($cma['asking_price']) }} vs R {{ number_format($cma['selected_value']) }}
                    </p>
                </div>
                <div class="text-right">
                    <p id="asking-cma-pct" class="text-2xl font-bold {{ $cma['is_overpriced'] ? 'text-red-600' : 'text-emerald-600' }}">
                        @if($cma['asking_vs_cma_pct'] > 0)+@endif{{ $cma['asking_vs_cma_pct'] }}%
                    </p>
                    @if($cma['is_overpriced'])
                        <p id="asking-cma-note" class="text-xs text-red-500 font-medium">Above CMA valuation</p>
                    @else
                        <p id="asking-cma-note" class="text-xs text-emerald-500 font-medium hidden"></p>
                    @endif
                </div>
            </div>
        </div>
        @endif
    </div>
    @endif

    {{-- ── 5. ACTIVE MARKET COMPETITION ─────────────────────────────────── --}}
    @if(($active['total_count'] ?? $active['count']) > 0)
    <div class="ds-status-card mb-4" style="border-left-color: var(--ds-cyan);">
        <h3 class="ds-section-header">5. Active Market Competition</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm" id="active-listings-table">
                <thead>
                    <tr class="text-left text-xs text-gray-400 border-b">
                        <th class="pb-2 pr-2 font-medium text-center" style="width:32px">
                            <input type="checkbox" id="active-check-all" checked title="Include/exclude all">
                        </th>
                        <th class="pb-2 pr-3 font-medium">Address</th>
                        <th class="pb-2 pr-3 font-medium">Type</th>
                        <th class="pb-2 pr-3 font-medium text-center">Beds</th>
                        <th class="pb-2 pr-3 font-medium text-center">Baths</th>
                        <th class="pb-2 pr-3 font-medium text-right">{{ $sizeLabel }}</th>
                        <th class="pb-2 pr-3 font-medium">List Date</th>
                        <th class="pb-2 pr-3 font-medium text-right">List Price</th>
                        <th class="pb-2 font-medium text-right">DOM</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($active['rows'] as $row)
                    <tr class="hover:bg-gray-50 active-listing-row {{ !empty($row['is_excluded']) ? 'opacity-50' : '' }}"
                        data-row-index="{{ $row['row_index'] ?? $loop->index }}"
                        data-price="{{ $row['list_price'] ?? 0 }}">
                        <td class="py-2 pr-2 text-center">
                            <input type="checkbox" class="active-listing-check"
                                   data-row-index="{{ $row['row_index'] ?? $loop->index }}"
                                   {{ empty($row['is_excluded']) ? 'checked' : '' }}>
                        </td>
                        <td class="py-2 pr-3 text-gray-800 text-xs max-w-[200px] truncate {{ !empty($row['is_excluded']) ? 'line-through' : '' }}">
                            @if(!empty($row['url']))
                                <a href="{{ $row['url'] }}" target="_blank" class="text-[#00b4d8] hover:underline" title="{{ $row['address'] ?? '' }}">{{ $row['address'] ?? '—' }}</a>
                            @else
                                {{ $row['address'] ?? '—' }}
                            @endif
                            @if(!empty($row['is_multi_agency']))
                                <span class="ml-1 text-[10px] px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 font-medium" title="{{ $row['listing_ids_in_group'] }} agencies list this property">{{ $row['listing_ids_in_group'] }}x</span>
                            @endif
                        </td>
                        <td class="py-2 pr-3 text-gray-600 text-xs">{{ $row['property_type'] ?? '—' }}</td>
                        <td class="py-2 pr-3 text-center text-gray-600">{{ $row['beds'] ?? '—' }}</td>
                        <td class="py-2 pr-3 text-center text-gray-600">{{ $row['baths'] ?? '—' }}</td>
                        <td class="py-2 pr-3 text-right text-gray-600">{{ $row['extent_m2'] ? number_format($row['extent_m2']) : '—' }}</td>
                        <td class="py-2 pr-3 text-gray-600">{{ $row['list_date'] ?? '—' }}</td>
                        <td class="py-2 pr-3 text-right font-medium text-gray-800">
                            @if($row['list_price'])
                                R {{ number_format($row['list_price']) }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="py-2 text-right text-gray-600">{{ $row['days_on_market'] ?? '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-gray-200 font-semibold text-xs" id="active-summary">
                        <td class="pt-2" colspan="2"></td>
                        <td class="pt-2 text-gray-500" colspan="5">
                            <span id="active-count">{{ $active['count'] }}</span> unique
                            {{ $active['count'] === 1 ? 'property' : 'properties' }}
                            @if(($active['raw_listing_count'] ?? 0) > ($active['total_count'] ?? $active['count']))
                                <span class="text-gray-400">({{ ($active['raw_listing_count'] ?? 0) - ($active['total_count'] ?? $active['count']) }} multi-agency dupes removed)</span>
                            @endif
                            @if(($active['total_count'] ?? $active['count']) > $active['count'])
                                <span class="text-gray-400">&middot; {{ ($active['total_count'] ?? $active['count']) - $active['count'] }} excluded</span>
                            @endif
                        </td>
                        <td class="pt-2 pr-3 text-right text-gray-800">
                            <span id="active-avg-price">
                            @if($active['avg_asking_price'])
                                R {{ number_format($active['avg_asking_price']) }}
                            @endif
                            </span>
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    @endif

    {{-- ── 6. HOLDING COST IMPACT ───────────────────────────────────────── --}}
    @if($holding['monthly_total'] > 0)
    <div class="ds-status-card mb-4" style="border-left-color: var(--ds-cyan);">
        <h3 class="ds-section-header">6. Holding Cost Impact</h3>
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
            {{-- Monthly breakdown --}}
            <div>
                <p class="text-xs text-gray-400 mb-2 font-medium">Monthly Breakdown</p>
                <div class="space-y-1">
                    @foreach($holding['breakdown'] as $label => $amount)
                        @if($amount > 0)
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600">{{ $label }}</span>
                            <span class="font-medium text-gray-800">R {{ number_format($amount) }}</span>
                        </div>
                        @endif
                    @endforeach
                    <div class="flex justify-between text-sm pt-2 border-t border-gray-200 font-bold">
                        <span class="text-gray-700">Monthly Total</span>
                        <span class="text-gray-900">R {{ number_format($holding['monthly_total']) }}</span>
                    </div>
                </div>
            </div>

            {{-- Projections --}}
            <div>
                <p class="text-xs text-gray-400 mb-2 font-medium">Cumulative Projections</p>
                <div class="space-y-2">
                    <div class="flex justify-between items-center bg-amber-50 rounded-lg px-4 py-3">
                        <span class="text-sm text-amber-700">3 months</span>
                        <span class="font-bold text-amber-800">R {{ number_format($holding['projected_3m']) }}</span>
                    </div>
                    <div class="flex justify-between items-center bg-orange-50 rounded-lg px-4 py-3">
                        <span class="text-sm text-orange-700">6 months</span>
                        <span class="font-bold text-orange-800">R {{ number_format($holding['projected_6m']) }}</span>
                    </div>
                    <div class="flex justify-between items-center bg-red-50 rounded-lg px-4 py-3">
                        <span class="text-sm text-red-700">12 months</span>
                        <span class="font-bold text-red-800">R {{ number_format($holding['projected_12m']) }}</span>
                    </div>
                </div>
                <p class="mt-3 text-xs text-red-600 font-medium text-center">
                    Every month at current asking price costs R {{ number_format($holding['monthly_total']) }}
                </p>
            </div>
        </div>
    </div>
    @endif

    {{-- ── 7. KEY INSIGHTS ──────────────────────────────────────────────── --}}
    <div class="ds-status-card mb-4" style="border-left-color: var(--ds-cyan);" id="key-insights-container">
        <h3 class="ds-section-header">7. Key Insights</h3>

        @if(!$insights['asking_price_set'])
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-4">
                <p class="text-sm text-amber-700">
                    Enter an asking price in the analysis form above to see price position comparisons.
                </p>
            </div>
        @elseif(count($insights['comparisons']) === 0)
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                <p class="text-sm text-gray-500">
                    No benchmark data available for comparison yet. Upload CMA or suburb reports.
                </p>
            </div>
        @else
            <div class="space-y-3" id="key-insights-list">
                @foreach($insights['comparisons'] as $comp)
                    @php
                        $statusColors = match($comp['status']) {
                            'danger'  => 'bg-red-50 border-red-200 text-red-700',
                            'warning' => 'bg-amber-50 border-amber-200 text-amber-700',
                            default   => 'bg-emerald-50 border-emerald-200 text-emerald-700',
                        };
                        $pctColors = match($comp['status']) {
                            'danger'  => 'text-red-600',
                            'warning' => 'text-amber-600',
                            default   => 'text-emerald-600',
                        };
                    @endphp
                    <div class="insight-card flex items-center justify-between p-4 rounded-lg border {{ $statusColors }}"
                         data-label="{{ $comp['label'] }}"
                         data-benchmark="{{ $comp['benchmark'] }}"
                         data-asking="{{ $comp['asking'] }}"
                         data-pct="{{ $comp['pct_difference'] }}">
                        <div>
                            <p class="insight-label text-xs font-medium opacity-75">{{ $comp['label'] }}</p>
                            <p class="insight-values text-sm mt-1">
                                R {{ number_format($comp['asking']) }} vs R {{ number_format($comp['benchmark']) }}
                            </p>
                        </div>
                        <p class="insight-pct text-xl font-bold {{ $pctColors }}">
                            @if($comp['pct_difference'] > 0)+@endif{{ $comp['pct_difference'] }}%
                        </p>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

</div>

@endif
