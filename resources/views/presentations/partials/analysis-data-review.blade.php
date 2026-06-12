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
    // Build 7 — sectional check reads title_type (keystone single
    // source of truth). The compile()'s is_sectional flag also reads
    // title_type now, so the two sources are consistent.
    $isSectional = ($presentation->property?->title_type === 'sectional_title')
                || ($analysisData['is_sectional'] ?? false);
    $sizeLabel   = $isSectional ? 'Unit m²' : 'Erf m²';
@endphp

<div class="mb-6">
    <div class="flex items-center justify-between mb-4">
        <h2 class="ds-section-header" style="font-size:1.125rem;">Extracted Data Review</h2>
        <span class="text-xs" style="color: var(--text-muted);">
            {{ $counts['fields'] }} fields &middot;
            {{ $counts['sold_comps'] }} comps &middot;
            {{ $counts['active_listings'] }} properties
        </span>
    </div>

    @php
        // AT-27 Phase C1 — shared edit gating for the in-place sections. A
        // published version is locked (read-only); pre-confirm it is editable.
        $presLocked     = isset($version) && $version
            && $version->review_status === \App\Models\PresentationVersion::REVIEW_PUBLISHED;
        $subjectEditUrl = (!$presLocked && isset($version) && $version && isset($presentation))
            ? route('presentations.analysis.subject-field', $presentation) : null;
        $reopenUrl      = ($presLocked && isset($presentation))
            ? route('presentations.analysis.reopen', $presentation) : null;
    @endphp

    {{-- ── 1. SUBJECT PROPERTY SUMMARY ──────────────────────────────────── --}}
    <div class="ds-status-card mb-4" style="border-left-color: var(--ds-cyan);">
        <h3 class="ds-section-header">1. Subject Property</h3>
        @if($presLocked)
            {{-- AT-27 — non-silent lock (platform rule). --}}
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-md px-3 py-2"
                 style="background: color-mix(in srgb, var(--ds-amber, #d97706) 8%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber, #d97706) 30%, transparent);">
                <span class="text-xs" style="color: var(--text-secondary);">&#128274; Locked — confirmed snapshot. Re-open the presentation to edit the subject, then Confirm &amp; Generate.</span>
                @if($reopenUrl)
                <form method="POST" action="{{ $reopenUrl }}" class="inline">@csrf<button type="submit" class="corex-btn-outline text-xs">Re-open to edit</button></form>
                @endif
            </div>
        @elseif($subjectEditUrl)
            <p class="text-xs mb-3" style="color: var(--ds-amber, #d97706);">
                &#9888; These are matching inputs — saving a change re-matches the comparables and returns you to the Review screen to re-check curation.
            </p>
        @endif
        <div class="grid grid-cols-2 gap-x-8 gap-y-2 text-sm md:grid-cols-3 lg:grid-cols-4"
             id="subject-edit-grid" @if($subjectEditUrl) data-url="{{ $subjectEditUrl }}" data-csrf="{{ csrf_token() }}" @endif>
            <div>
                <span class="text-xs" style="color: var(--text-muted);">Address</span>
                @if($subjectEditUrl)
                    <input type="text" class="subj-input pres-input w-full text-sm" data-field="property_address" value="{{ $subject['address'] ?? '' }}">
                @else
                    <p class="font-medium" style="color: var(--text-primary);">{{ $subject['address'] ?? '—' }}</p>
                @endif
            </div>
            <div>
                <span class="text-xs" style="color: var(--text-muted);">Suburb</span>
                @if($subjectEditUrl)
                    <input type="text" class="subj-input pres-input w-full text-sm" data-field="suburb" value="{{ $subject['suburb'] ?? '' }}">
                @else
                    <p class="font-medium" style="color: var(--text-primary);">{{ $subject['suburb'] ?? '—' }}</p>
                @endif
            </div>
            <div>
                <span class="text-xs" style="color: var(--text-muted);">Erf Number</span>
                <p class="font-medium" style="color: var(--text-primary);">{{ $subject['erf'] ?? '—' }}</p>
            </div>
            <div>
                <span class="text-xs" style="color: var(--text-muted);">Extent (erf m&sup2;)</span>
                @if($subjectEditUrl)
                    <input type="number" min="0" step="1" class="subj-input pres-input w-full text-sm" data-field="erf_size_m2" value="{{ $subject['extent_m2'] ? (int) $subject['extent_m2'] : '' }}">
                @else
                    <p class="font-medium" style="color: var(--text-primary);">
                        @if($subject['extent_m2'])
                            {{ number_format($subject['extent_m2']) }} m&sup2;
                        @else
                            —
                        @endif
                    </p>
                @endif
            </div>
            <div>
                <span class="text-xs" style="color: var(--text-muted);">GPS</span>
                <p class="font-medium text-xs" style="color: var(--text-primary);">{{ $subject['gps'] ?? '—' }}</p>
            </div>
            <div>
                <span class="text-xs" style="color: var(--text-muted);">Property Type</span>
                @if($subjectEditUrl)
                    @php $stype = strtolower((string) ($subject['property_type'] ?? '')); @endphp
                    <select class="subj-input pres-input w-full text-sm" data-field="property_type">
                        @foreach(['house'=>'House','townhouse'=>'Townhouse','apartment'=>'Apartment','duplex'=>'Duplex','vacant_land'=>'Vacant Land','farm'=>'Farm','other'=>'Other'] as $tv => $tl)
                            <option value="{{ $tv }}" {{ $stype === $tv ? 'selected' : '' }}>{{ $tl }}</option>
                        @endforeach
                    </select>
                @else
                    <p class="font-medium" style="color: var(--text-primary);">{{ \Illuminate\Support\Str::humanType($subject['property_type'] ?? null) }}</p>
                @endif
            </div>
            <div>
                <span class="text-xs" style="color: var(--text-muted);">Bedrooms</span>
                @if($subjectEditUrl)
                    <input type="number" min="0" step="1" class="subj-input pres-input w-full text-sm" data-field="bedrooms" value="{{ $subject['bedrooms'] !== null ? (int) $subject['bedrooms'] : '' }}">
                @else
                    <p class="font-medium" style="color: var(--text-primary);">{{ $subject['bedrooms'] ?? '—' }}</p>
                @endif
            </div>
            <div>
                <span class="text-xs" style="color: var(--text-muted);">Purchase Date</span>
                <p class="font-medium" style="color: var(--text-primary);">{{ $subject['purchase_date'] ?? '—' }}</p>
            </div>
            <div>
                <span class="text-xs" style="color: var(--text-muted);">Purchase Price</span>
                <p class="font-medium" style="color: var(--text-primary);">
                    @if($subject['purchase_price'])
                        R {{ number_format($subject['purchase_price']) }}
                    @else
                        —
                    @endif
                </p>
            </div>
            <div>
                <span class="text-xs" style="color: var(--text-muted);">Indexed Value</span>
                <p class="font-medium" style="color: var(--text-primary);">
                    @if($subject['indexed_value'])
                        R {{ number_format($subject['indexed_value']) }}
                    @else
                        —
                    @endif
                </p>
            </div>
            <div>
                <span class="text-xs" style="color: var(--text-muted);">CAGR</span>
                <p class="font-medium" style="color: var(--text-primary);">
                    @if($subject['cagr'])
                        {{ number_format($subject['cagr'], 2) }}%
                    @else
                        —
                    @endif
                </p>
            </div>
            <div>
                <span class="text-xs" style="color: var(--text-muted);">Municipal Evaluation</span>
                <p class="font-medium" style="color: var(--text-primary);">
                    @if($subject['municipal_value'])
                        R {{ number_format($subject['municipal_value']) }}
                        @if($subject['municipal_year'])
                            <span class="text-xs" style="color: var(--text-muted);">({{ $subject['municipal_year'] }})</span>
                        @endif
                    @else
                        —
                    @endif
                </p>
            </div>
            <div>
                <span class="text-xs" style="color: var(--text-muted);">Asking Price</span>
                <p class="font-medium" style="color: var(--text-primary);">
                    @if($subject['asking_price'])
                        R {{ number_format($subject['asking_price']) }}
                    @else
                        <span class="text-amber-500 italic">Not set — enter in form above</span>
                    @endif
                </p>
            </div>
            <div>
                <span class="text-xs" style="color: var(--text-muted);">Monthly Holding Cost</span>
                <p class="font-medium" style="color: var(--text-primary);">
                    @if($subject['monthly_holding_total'] > 0)
                        R {{ number_format($subject['monthly_holding_total']) }}
                    @else
                        <span style="color: var(--text-muted);">R 0</span>
                    @endif
                </p>
            </div>
        </div>
    </div>
    @if($subjectEditUrl)
    {{-- AT-27 Phase C1a — subject fields are MATCHING INPUTS: saving one
         re-matches the comps and LOOPS BACK to Review to re-curate (not an
         in-place edit). The save action warns before doing so. --}}
    <script>
    (function () {
        var grid = document.getElementById('subject-edit-grid');
        if (!grid || !grid.dataset.url) return;
        var url = grid.dataset.url, csrf = grid.dataset.csrf;
        grid.querySelectorAll('.subj-input').forEach(function (el) {
            var last = el.value;
            el.addEventListener('change', function () {
                if (el.value === last) return;
                if (!confirm('Saving this change re-matches the comparables for the new subject details and returns you to the Review screen to re-check your curation. Continue?')) {
                    el.value = last;
                    return;
                }
                last = el.value;
                el.disabled = true; el.style.opacity = '0.6';
                var body = new FormData();
                body.append('_token', csrf);
                body.append('field', el.dataset.field);
                body.append('value', el.value);
                fetch(url, { method: 'POST', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: body, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d && d.ok && d.redirect_url) { window.location.href = d.redirect_url; }
                        else { el.disabled = false; el.style.opacity = ''; alert((d && d.message) || 'Could not save.'); }
                    })
                    .catch(function () { el.disabled = false; el.style.opacity = ''; });
            });
        });
    })();
    </script>
    @endif

    {{-- ── 2. SUBURB MARKET OVERVIEW ────────────────────────────────────── --}}
    @if($suburb['latest_year'])
    <div class="ds-status-card mb-4" style="border-left-color: var(--ds-cyan);">
        <h3 class="ds-section-header">2. Suburb Market Overview</h3>
        <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
            <div class="rounded-md p-3 text-center" style="background: var(--surface-2);">
                <span class="text-xs block" style="color: var(--text-muted);">Year</span>
                <p class="text-lg font-bold" style="color: var(--text-primary);">{{ $suburb['latest_year'] }}</p>
            </div>
            <div class="rounded-md p-3 text-center" style="background: var(--surface-2);">
                <span class="text-xs block" style="color: var(--text-muted);">Sales Count</span>
                <p class="text-lg font-bold" style="color: var(--text-primary);">{{ $suburb['sales_count'] ?? '—' }}</p>
            </div>
            <div class="bg-sky-50 rounded-md p-3 text-center">
                <span class="text-xs block" style="color: var(--brand-icon, #0ea5e9);">Median Price</span>
                <p class="text-lg font-bold" style="color: var(--brand-default, #0b2a4a);">
                    @if($suburb['median_price'])
                        R {{ number_format($suburb['median_price']) }}
                    @else
                        —
                    @endif
                </p>
            </div>
            <div class="rounded-md p-3 text-center" style="background: var(--surface-2);">
                <span class="text-xs block" style="color: var(--text-muted);">Low Range</span>
                <p class="text-sm font-semibold" style="color: var(--text-primary);">
                    @if($suburb['low_range'])
                        R {{ number_format($suburb['low_range']) }}
                    @else
                        —
                    @endif
                </p>
            </div>
            <div class="rounded-md p-3 text-center" style="background: var(--surface-2);">
                <span class="text-xs block" style="color: var(--text-muted);">High Range</span>
                <p class="text-sm font-semibold" style="color: var(--text-primary);">
                    @if($suburb['high_range'])
                        R {{ number_format($suburb['high_range']) }}
                    @else
                        —
                    @endif
                </p>
            </div>
            <div class="rounded-md p-3 text-center" style="background: var(--surface-2);">
                <span class="text-xs block" style="color: var(--text-muted);">Maximum</span>
                <p class="text-sm font-semibold" style="color: var(--text-primary);">
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
    <div class="{{ $absColor['bg'] }} {{ $absColor['border'] }} border rounded-md p-5 mb-4">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold {{ $absColor['text'] }} uppercase tracking-wide">Stock Absorption Rate</h3>
            <span class="text-xs px-2.5 py-1 rounded-full font-semibold {{ $absColor['badge'] }}">{{ $stock['absorption_label'] }}</span>
        </div>
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <div class="text-center">
                <span class="text-xs block" style="color: var(--text-muted);">Active Listings</span>
                <p class="text-xl font-bold {{ $absColor['text'] }}">{{ $stock['total_active_stock'] }}</p>
                @if($stock['stock_source'] === 'portal_search')
                    <span class="text-xs" style="color: var(--text-muted);">from P24 search</span>
                @endif
            </div>
            <div class="text-center">
                <span class="text-xs block" style="color: var(--text-muted);">Sales / Year</span>
                <p class="text-xl font-bold" style="color: var(--text-primary);">{{ $stock['annual_sales'] }}</p>
                <span class="text-xs" style="color: var(--text-muted);">{{ number_format($stock['monthly_sales'], 1) }} / month</span>
            </div>
            <div class="text-center">
                <span class="text-xs block" style="color: var(--text-muted);">Months of Supply</span>
                <p class="text-xl font-bold {{ $absColor['text'] }}">{{ number_format($stock['months_of_supply'], 1) }}</p>
            </div>
            <div class="text-center">
                <span class="text-xs block" style="color: var(--text-muted);">Years of Supply</span>
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

    {{-- Phase 3g V2 Part D — Spatial View. Shows the actual comps backing
         this presentation as map pins around the subject. --}}
    @php
        $_p = $presentation ?? null;
        $_property = $_p?->property;
        $_subjectLat = $_property?->latitude;
        $_subjectLng = $_property?->longitude;
    @endphp
    @if($_p && $_subjectLat && $_subjectLng)
    <div class="ds-status-card mb-4" style="border-left-color: var(--brand-button);" id="spatial-view">
        <h3 class="ds-section-header">Spatial View</h3>
        @include('corex.map.partials._embed-map', [
            'containerId'    => 'presentation-spatial-' . $_p->id,
            'centerLat'      => $_subjectLat,
            'centerLng'      => $_subjectLng,
            'radiusM'        => 1000,
            'subjectTitle'   => $_p->property_address ?: 'Subject',
            'mode'           => 'presentation',
            'presentationId' => $_p->id,
            'enabledLayers'  => ['sold_comps', 'active_listings'],
            'fullMapUrl'     => route('corex.map.index') . '?focus=' . $_subjectLat . ',' . $_subjectLng . '&zoom=17',
        ])
    </div>
    @endif

    {{-- ── NEW LISTING INFLOW & ABSORPTION ──────────────────────────────── --}}
    @php $inflow = $analysisData['inflow_absorption'] ?? []; @endphp
    @if(!empty($inflow))
    <div class="ds-status-card mb-4" style="border-left-color: var(--ds-cyan);" id="inflow-absorption">
        <h3 class="ds-section-header">New Listing Inflow &amp; Absorption</h3>

        @if(empty($inflow['has_data']))
            {{-- No data state --}}
            <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                <p class="text-sm" style="color: var(--text-secondary);">
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
                <div class="rounded-md p-3 text-center" style="background: var(--surface-2);">
                    <span class="text-xs block" style="color: var(--text-muted);">Last 7 Days</span>
                    <p class="text-xl font-bold" style="color: var(--text-primary);">{{ $inflow['count_7d'] }}</p>
                    <span class="text-xs" style="color: var(--text-muted);">new listings</span>
                </div>
                <div class="rounded-md p-3 text-center" style="background: var(--surface-2);">
                    <span class="text-xs block" style="color: var(--text-muted);">Last 30 Days</span>
                    <p class="text-xl font-bold" style="color: var(--text-primary);">{{ $inflow['count_30d'] }}</p>
                    <span class="text-xs" style="color: var(--text-muted);">new listings</span>
                </div>
                <div class="bg-sky-50 rounded-md p-3 text-center ring-1 ring-sky-200">
                    <span class="text-xs block" style="color: var(--brand-icon, #0ea5e9);">Last 90 Days</span>
                    <p class="text-xl font-bold" style="color: var(--brand-default, #0b2a4a);">{{ $inflow['count_90d'] }}</p>
                    <span class="text-xs" style="color: var(--brand-icon, #0ea5e9);">new listings</span>
                </div>
            </div>

            {{-- Row 2: Inflow rate callout --}}
            @if($inflow['new_listing_rate'] > 0)
            <div class="rounded-md p-4 mb-4" style="background: var(--surface-2);">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium" style="color: var(--text-primary);">Average inflow rate</p>
                        <p class="text-xs mt-0.5" style="color: var(--text-muted);">
                            Based on {{ $inflow['count_90d'] }} similar listings over the past 90 days
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-2xl font-bold" style="color: var(--brand-default, #0b2a4a);">{{ $inflow['new_listing_rate'] }}</p>
                        <p class="text-xs" style="color: var(--text-muted);">per month</p>
                    </div>
                </div>
                <p class="text-xs mt-2" style="color: var(--text-secondary);">
                    That's {{ number_format($inflow['new_listing_rate'] * 12, 0) }} new competing listings per year entering the market
                </p>
                @if(!empty($inflow['target_suburbs']))
                <p class="text-xs mt-1" style="color: var(--text-muted);">
                    Matching: {{ implode(', ', $inflow['target_suburbs']) }}
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
                <div class="{{ $trendColor['bg'] }} {{ $trendColor['border'] }} border rounded-md p-4">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-xs font-semibold {{ $trendColor['text'] }} uppercase tracking-wide">Adjusted Absorption</p>
                        <span class="text-xs px-2.5 py-1 rounded-full font-semibold {{ $trendColor['badge'] }}">{{ $trendLabel }}</span>
                    </div>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span style="color: var(--text-secondary);">Standard supply</span>
                            <span class="font-medium" style="color: var(--text-primary);">
                                {{ $inflow['active_listings'] }} &divide; {{ $inflow['monthly_sales'] }}/mo
                                @if(!empty($stock['months_of_supply']))
                                    = {{ number_format($stock['months_of_supply'], 1) }} months
                                @endif
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span style="color: var(--text-secondary);">Net absorption</span>
                            <span class="font-medium {{ $trendColor['text'] }}">
                                {{ $inflow['monthly_sales'] }} sold &minus; {{ $inflow['new_listing_rate'] }} new
                                = {{ $inflow['net_absorption'] > 0 ? '+' : '' }}{{ $inflow['net_absorption'] }}/mo
                            </span>
                        </div>
                        @if($inflow['adjusted_months_supply'] !== null)
                        <div class="flex justify-between pt-1 border-t {{ $trendColor['border'] }}">
                            <span class="font-medium" style="color: var(--text-secondary);">Adjusted supply</span>
                            <span class="font-bold {{ $trendColor['text'] }}">{{ $inflow['adjusted_months_supply'] }} months</span>
                        </div>
                        @endif
                        @if($inflow['pool_after_3_months'] !== null)
                        <div class="flex justify-between">
                            <span style="color: var(--text-secondary);">Pool after 3 months</span>
                            <span class="font-medium {{ $trendColor['text'] }}">~{{ $inflow['pool_after_3_months'] }} properties</span>
                        </div>
                        @endif
                    </div>
                </div>

                {{-- Right: Selling probability --}}
                <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                    <p class="text-xs font-semibold uppercase tracking-wide mb-3" style="color: var(--text-secondary);">Selling Probability</p>
                    <div class="space-y-3">
                        @if($inflow['monthly_probability'] !== null)
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span style="color: var(--text-secondary);">Monthly chance</span>
                                <span class="font-bold" style="color: var(--text-primary);">{{ $inflow['monthly_probability'] }}%</span>
                            </div>
                            <div class="w-full rounded-full h-2" style="background: var(--border);">
                                <div class="h-2 rounded-full" style="background: var(--brand-icon, #0ea5e9); width: {{ min($inflow['monthly_probability'], 100) }}%"></div>
                            </div>
                        </div>
                        @endif
                        @if($inflow['prob_3_months'] !== null)
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span style="color: var(--text-secondary);">3-month chance</span>
                                <span class="font-bold" style="color: var(--text-primary);">{{ $inflow['prob_3_months'] }}%</span>
                            </div>
                            <div class="w-full rounded-full h-2" style="background: var(--border);">
                                <div class="h-2 rounded-full" style="background: var(--brand-default, #0b2a4a); width: {{ min($inflow['prob_3_months'], 100) }}%"></div>
                            </div>
                        </div>
                        @endif
                        @if($inflow['adjusted_prob_3_months'] !== null && $inflow['adjusted_prob_3_months'] != $inflow['prob_3_months'])
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span style="color: var(--text-secondary);">Adjusted 3-month <span class="text-xs" style="color: var(--text-muted);">(with inflow)</span></span>
                                <span class="font-bold {{ $inflow['adjusted_prob_3_months'] < ($inflow['prob_3_months'] ?? 0) ? 'text-red-600' : 'text-emerald-600' }}">{{ $inflow['adjusted_prob_3_months'] }}%</span>
                            </div>
                            <div class="w-full rounded-full h-2" style="background: var(--border);">
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
            <div class="{{ $narrativeBg }} border rounded-md p-4">
                <p class="text-sm font-medium leading-relaxed">{{ $inflow['narrative'] }}</p>
            </div>
            @endif

            {{-- Data source note --}}
            <p class="text-xs mt-3" style="color: var(--text-muted);">
                Source: P24 alert email imports ({{ number_format($inflow['total_p24_listings']) }} total listings in database)
            </p>
        @endif
    </div>
    @endif

    {{-- ── PRICE POSITION & BRACKETS ─────────────────────────────────────── --}}
    @php
        // Build 8 — read canonical (competitor_stock-scored) verdict when
        // present, fall back to legacy (active_competition-derived) for
        // pre-Build-8 versions whose snapshot pre-dates the rewire.
        $compStock      = $analysisData['competitor_stock'] ?? [];
        $pricePosCanon  = $compStock['price_position_canonical'] ?? ['has_data' => false];
        $priceBrkCanon  = $compStock['price_brackets_canonical'] ?? ['has_data' => false, 'brackets' => []];
        $pricePosLegacy = $analysisData['price_position'] ?? [];
        $priceBrkLegacy = $analysisData['price_brackets'] ?? [];
        $pricePos = !empty($pricePosCanon['has_data']) ? $pricePosCanon : $pricePosLegacy;
        $priceBrk = !empty($priceBrkCanon['has_data']) ? $priceBrkCanon : $priceBrkLegacy;
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
        <div class="{{ $posColors['bg'] }} {{ $posColors['border'] }} border rounded-md p-4 mb-4">
            <div class="flex items-center justify-between mb-2">
                <p class="text-xs font-medium {{ $posColors['text'] }}">Your Price Position</p>
                <span class="text-xs px-2.5 py-1 rounded-full font-semibold {{ $posColors['badge'] }}">{{ $pricePos['position_label'] }}</span>
            </div>
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <div class="text-center">
                    <span class="text-xs block" style="color: var(--text-muted);">Rank</span>
                    <p class="text-xl font-bold {{ $posColors['text'] }}">{{ $pricePos['price_rank'] }} <span class="text-sm font-normal" style="color: var(--text-muted);">of {{ $pricePos['total_listings'] }}</span></p>
                </div>
                <div class="text-center">
                    <span class="text-xs block" style="color: var(--text-muted);">Priced Higher</span>
                    <p class="text-xl font-bold" style="color: var(--text-primary);">{{ $pricePos['listings_more_expensive'] }}</p>
                </div>
                <div class="text-center">
                    <span class="text-xs block" style="color: var(--text-muted);">Priced Lower</span>
                    <p class="text-xl font-bold" style="color: var(--text-primary);">{{ $pricePos['listings_cheaper'] }}</p>
                </div>
                <div class="text-center">
                    <span class="text-xs block" style="color: var(--text-muted);">Percentile</span>
                    <p class="text-xl font-bold {{ $posColors['text'] }}">{{ $pricePos['price_percentile'] }}%</p>
                    <span class="text-xs" style="color: var(--text-muted);">more expensive than</span>
                </div>
            </div>
        </div>
        @endif

        {{-- Price Bracket Distribution --}}
        @if(!empty($priceBrk['has_data']) && !empty($priceBrk['brackets']))
        <div>
            <p class="text-xs mb-2 font-medium" style="color: var(--text-muted);">Price Distribution (R 500K brackets) — {{ $priceBrk['total_priced'] }} listings with price data</p>
            <div class="space-y-1.5">
                @foreach($priceBrk['brackets'] as $bracket)
                <div class="flex items-center gap-3 {{ $bracket['contains_asking'] ? 'bg-sky-50 rounded-md px-2 py-1.5 -mx-2 border border-sky-200' : '' }}">
                    <span class="text-xs w-44 flex-shrink-0 text-right font-mono" style="color: var(--text-secondary);">{{ $bracket['label'] }}</span>
                    <div class="flex-1 rounded-full h-5 overflow-hidden" style="background: var(--surface-2);">
                        @if($bracket['bar_pct'] > 0)
                        <div class="h-full rounded-full {{ $bracket['contains_asking'] ? 'bg-sky-500' : '' }}"
                             style="width: {{ max($bracket['bar_pct'], 4) }}%;{{ $bracket['contains_asking'] ? '' : ' background: var(--text-muted);' }}"></div>
                        @endif
                    </div>
                    <span class="text-xs font-semibold w-8 text-right" style="color: var(--text-primary);">{{ $bracket['count'] }}</span>
                    @if($bracket['contains_asking'])
                        <span class="text-xs font-medium flex-shrink-0" style="color: var(--brand-icon, #0ea5e9);">Your price</span>
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
            <details class="mb-3 border rounded-md" style="border-color: var(--border);" {{ $firstOpen ? 'open' : '' }}>
                @php $firstOpen = false; @endphp
                <summary class="cursor-pointer px-4 py-3 text-sm font-medium transition-all duration-300 select-none flex items-center justify-between" style="color: var(--text-primary);">
                    <span>{{ $section['label'] }}</span>
                    <span class="px-2 py-0.5 rounded-full text-xs bg-sky-100 font-medium" style="color: var(--brand-default, #0b2a4a);">{{ $section['data']['count'] }}</span>
                </summary>
                <div class="px-4 pb-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs border-b" style="color: var(--text-muted); border-color: var(--border);">
                                <th class="pb-2 pr-3 font-medium">Address</th>
                                <th class="pb-2 pr-3 font-medium text-right">Dist (m)</th>
                                <th class="pb-2 pr-3 font-medium text-right">{{ $sizeLabel }}</th>
                                <th class="pb-2 pr-3 font-medium">Sale Date</th>
                                <th class="pb-2 pr-3 font-medium text-right">Sale Price</th>
                                <th class="pb-2 font-medium text-right">R/m&sup2;</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y" style="--tw-divide-opacity:1; border-color: var(--border);">
                            @foreach($section['data']['rows'] as $row)
                            <tr class="transition-all duration-300" style="--hover-bg: var(--surface-2);" onmouseenter="this.style.background='var(--surface-2)'" onmouseleave="this.style.background=''">
                                <td class="py-2 pr-3 text-xs" style="color: var(--text-primary);">{{ $row['address'] ?? '—' }}</td>
                                <td class="py-2 pr-3 text-right" style="color: var(--text-secondary);">{{ $row['distance_m'] ?? '—' }}</td>
                                <td class="py-2 pr-3 text-right" style="color: var(--text-secondary);">{{ $row['extent_m2'] ? number_format($row['extent_m2']) : '—' }}</td>
                                <td class="py-2 pr-3" style="color: var(--text-secondary);">{{ $row['sale_date'] ?? '—' }}</td>
                                <td class="py-2 pr-3 text-right font-medium" style="color: var(--text-primary);">
                                    @if($row['sale_price'])
                                        R {{ number_format($row['sale_price']) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="py-2 text-right" style="color: var(--text-secondary);">
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
                            <tr class="border-t-2 font-semibold text-xs" style="border-color: var(--border);">
                                <td class="pt-2 pr-3" colspan="4" style="color: var(--text-secondary);">
                                    Avg ({{ $section['data']['count'] }} sales)
                                </td>
                                <td class="pt-2 pr-3 text-right" style="color: var(--text-primary);">
                                    @if($section['data']['avg_price'])
                                        R {{ number_format($section['data']['avg_price']) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="pt-2 text-right" style="color: var(--text-primary);">
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

    {{-- ── 4. CMA EVALUATION ────────────────────────────────────────────── --}}
    @if($cma['cma_middle'] || $cma['vicinity_middle'])
    <div class="ds-status-card mb-4" style="border-left-color: var(--ds-cyan);">
        <h3 class="ds-section-header">4. CMA Evaluation</h3>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            {{-- CMA Range — clickable tiles --}}
            @if($cma['cma_middle'])
            <div>
                <p class="text-xs mb-2 font-medium" style="color: var(--text-muted);">CMA Report Range <span style="color: var(--brand-icon, #0ea5e9);">(click to select)</span></p>
                <div class="flex items-center gap-3">
                    @foreach(['lower' => $cma['cma_lower'], 'middle' => $cma['cma_middle'], 'upper' => $cma['cma_upper']] as $range => $val)
                    @php $isSel = ($cma['selected_range'] ?? 'middle') === $range; @endphp
                    <div class="cma-tile text-center flex-1 rounded-md p-3 cursor-pointer transition-all duration-300
                        {{ $isSel ? 'bg-sky-50 ring-1 ring-sky-200' : '' }}"
                        @if(!$isSel) style="background: var(--surface-2);" @endif
                        data-range="{{ $range }}" data-value="{{ $val }}">
                        <span class="text-xs block" style="color: {{ $isSel ? 'var(--brand-icon, #0ea5e9)' : 'var(--text-muted)' }};">{{ ucfirst($range) }}</span>
                        <p class="{{ $isSel ? 'font-bold text-lg' : 'font-semibold' }}" style="color: {{ $isSel ? 'var(--brand-default, #0b2a4a)' : 'var(--text-primary)' }};">R {{ number_format($val) }}</p>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Vicinity Range — clickable tiles --}}
            @if($cma['vicinity_middle'])
            <div>
                <p class="text-xs mb-2 font-medium" style="color: var(--text-muted);">Vicinity Sales Range <span style="color: var(--brand-icon, #0ea5e9);">(click to select)</span></p>
                <div class="flex items-center gap-3">
                    @php $vicSel = $presentation->vicinity_selected_range ?? 'middle'; @endphp
                    @foreach(['lower' => $cma['vicinity_lower'], 'middle' => $cma['vicinity_middle'], 'upper' => $cma['vicinity_upper']] as $range => $val)
                    @php $isSel = $vicSel === $range; @endphp
                    <div class="vicinity-tile text-center flex-1 rounded-md p-3 cursor-pointer transition-all duration-300
                        {{ $isSel ? 'bg-sky-50 ring-1 ring-sky-200' : '' }}"
                        @if(!$isSel) style="background: var(--surface-2);" @endif
                        data-range="{{ $range }}" data-value="{{ $val }}">
                        <span class="text-xs block" style="color: {{ $isSel ? 'var(--brand-icon, #0ea5e9)' : 'var(--text-muted)' }};">{{ ucfirst($range) }}</span>
                        <p class="{{ $isSel ? 'font-bold text-lg' : 'font-semibold' }}" style="color: {{ $isSel ? 'var(--brand-default, #0b2a4a)' : 'var(--text-primary)' }};">R {{ number_format($val) }}</p>
                    </div>
                    @endforeach
                </div>
                @if($cma['vicinity_ppm2'])
                <p class="text-xs mt-2 text-right" style="color: var(--text-muted);">Avg R/m&sup2;: <span class="font-medium" style="color: var(--text-secondary);">R {{ number_format($cma['vicinity_ppm2']) }}</span></p>
                @endif
            </div>
            @endif
        </div>

        {{-- Asking vs CMA comparison --}}
        @if($cma['asking_price'] && $cma['selected_value'])
        <div id="asking-vs-cma" class="mt-4 p-4 rounded-md border {{ $cma['is_overpriced'] ? 'bg-red-50 border-red-200' : 'bg-emerald-50 border-emerald-200' }}"
             data-asking="{{ $cma['asking_price'] }}"
             data-cma-lower="{{ $cma['cma_lower'] }}"
             data-cma-middle="{{ $cma['cma_middle'] }}"
             data-cma-upper="{{ $cma['cma_upper'] }}">
            <div class="flex items-center justify-between">
                <div>
                    <p id="asking-cma-label" class="text-xs font-medium {{ $cma['is_overpriced'] ? 'text-red-600' : 'text-emerald-600' }}">
                        Asking Price vs CMA {{ ucfirst($cma['selected_range'] ?? 'middle') }}
                    </p>
                    <p id="asking-cma-values" class="text-sm mt-1" style="color: var(--text-primary);">
                        R {{ number_format($cma['asking_price']) }} vs R {{ number_format($cma['selected_value']) }}
                    </p>
                </div>
                <div class="text-right">
                    <p id="asking-cma-pct" class="text-2xl font-bold {{ $cma['is_overpriced'] ? 'text-red-600' : 'text-emerald-600' }}">
                        @if($cma['asking_vs_cma_pct'] > 0)+@endif{{ $cma['asking_vs_cma_pct'] }}%
                    </p>
                    @if($cma['is_overpriced'])
                        <p id="asking-cma-note" class="text-xs text-red-500 font-medium">Above CMA evaluation</p>
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
    @php
        // AT-27 fix 1 — Section 5 reads the UNIFIED, type-gated, sold-excluded
        // competitor_stock set (the same set the cards, counts, pins and PDF
        // use) — NOT the legacy MIC-fed active_competition, which leaked sold +
        // wrong-type "Residence" rows (the J/K Claverhouse/Topanga listings).
        // Per-comp curation lives on the review screen; unified-set edit-in-
        // place is Phase C-C1.
        $compStock5   = $analysisData['competitor_stock'] ?? [];
        $compVisible5 = $compStock5['visible'] ?? [];
        $compPrices5  = array_values(array_filter(array_map(fn ($c) => (int) ($c['price'] ?? 0), $compVisible5), fn ($p) => $p > 0));
        $compAvg5     = count($compPrices5) ? (int) round(array_sum($compPrices5) / count($compPrices5)) : null;
    @endphp
    @if(!empty($compVisible5))
    <div class="ds-status-card mb-4" style="border-left-color: var(--ds-cyan);">
        <h3 class="ds-section-header">5. Active Market Competition</h3>
        @if($presLocked)
            {{-- AT-27 — non-silent lock (platform rule). --}}
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2 rounded-md px-3 py-2"
                 style="background: color-mix(in srgb, var(--ds-amber, #d97706) 8%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber, #d97706) 30%, transparent);">
                <span class="text-xs" style="color: var(--text-secondary);">&#128274; Locked — confirmed snapshot. Re-open the presentation to change the competition set.</span>
                @if($reopenUrl)<form method="POST" action="{{ $reopenUrl }}" class="inline">@csrf<button type="submit" class="corex-btn-outline text-xs">Re-open to edit</button></form>@endif
            </div>
        @else
            <p class="text-xs mb-2" style="color: var(--text-muted);">
                Active listings your property competes against — scored on price, suburb, type and bedrooms, type-gated and sold-excluded.
                @if($subjectEditUrl)<strong>Untick a comp to exclude it</strong> from the set.@else Curate which comps appear on the review screen.@endif
            </p>
        @endif
        <div class="overflow-x-auto">
            <table class="w-full text-sm" id="active-competition-table">
                <thead>
                    <tr class="text-left text-xs border-b" style="color: var(--text-muted); border-color: var(--border);">
                        @if($subjectEditUrl)<th class="pb-2 pr-2 font-medium text-center" style="width:36px;">Incl.</th>@endif
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
                <tbody class="divide-y" style="border-color: var(--border);">
                    @foreach($compVisible5 as $row)
                    @php
                        $cErf5 = !empty($row['erf_size_m2']) ? (int) $row['erf_size_m2'] : (!empty($row['property_size_m2']) ? (int) $row['property_size_m2'] : null);
                        $cListDate5 = $row['listed_date'] ?? (!empty($row['first_seen_at']) ? \Illuminate\Support\Carbon::parse($row['first_seen_at'])->format('Y-m-d') : null);
                    @endphp
                    <tr class="comp-row transition-all" onmouseenter="this.style.background='var(--surface-2)'" onmouseleave="this.style.background=''">
                        @if($subjectEditUrl)
                        <td class="py-2 pr-2 text-center">
                            <input type="checkbox" class="comp-incl" checked
                                   data-url="{{ route('presentations.review.toggle-competitor', [$version->id, $row['listing_id'] ?? 0]) }}"
                                   title="Untick to exclude this comp">
                        </td>
                        @endif
                        <td class="py-2 pr-3 text-xs max-w-[200px] truncate" style="color: var(--text-primary);">
                            @if(!empty($row['portal_url']))
                                <a href="{{ $row['portal_url'] }}" target="_blank" class="hover:underline" style="color: var(--brand-icon, #0ea5e9);" title="{{ $row['address'] ?? '' }}">{{ $row['address'] ?? '—' }}</a>
                            @else
                                {{ $row['address'] ?? '—' }}
                            @endif
                        </td>
                        <td class="py-2 pr-3 text-xs" style="color: var(--text-secondary);">{{ $row['property_type'] ?? '—' }}</td>
                        <td class="py-2 pr-3 text-center" style="color: var(--text-secondary);">{{ $row['bedrooms'] ?? '—' }}</td>
                        <td class="py-2 pr-3 text-center" style="color: var(--text-secondary);">{{ $row['bathrooms'] ?? '—' }}</td>
                        <td class="py-2 pr-3 text-right" style="color: var(--text-secondary);">{{ $cErf5 ? number_format($cErf5) : '—' }}</td>
                        <td class="py-2 pr-3" style="color: var(--text-secondary);">{{ $cListDate5 ?? '—' }}</td>
                        <td class="py-2 pr-3 text-right font-medium" style="color: var(--text-primary);">{{ !empty($row['price']) ? 'R ' . number_format($row['price']) : '—' }}</td>
                        <td class="py-2 text-right" style="color: var(--text-secondary);">{{ $row['days_on_market'] ?? '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 font-semibold text-xs" style="border-color: var(--border);">
                        <td class="pt-2" colspan="{{ $subjectEditUrl ? 6 : 5 }}" style="color: var(--text-secondary);">
                            {{ count($compVisible5) }} {{ count($compVisible5) === 1 ? 'property' : 'properties' }}
                        </td>
                        <td class="pt-2"></td>
                        <td class="pt-2 pr-3 text-right" style="color: var(--text-primary);">@if($compAvg5) R {{ number_format($compAvg5) }} @endif</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    @if($subjectEditUrl)
    {{-- AT-27 Phase C1b — active-competition include/exclude on the unified set:
         untick a comp → toggle it off the version's competitor whitelist →
         reload so the competition set + every dependent stat recomputes. --}}
    <script>
    (function () {
        var csrf = '{{ csrf_token() }}';
        document.querySelectorAll('#active-competition-table .comp-incl').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var url = cb.dataset.url;
                cb.disabled = true;
                var body = new FormData();
                body.append('_token', csrf);
                body.append('included', cb.checked ? '1' : '0');
                fetch(url, { method: 'POST', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: body, credentials: 'same-origin' })
                    .then(function (r) { if (r.ok) { window.location.reload(); return null; } return r.json(); })
                    .then(function (d) {
                        if (d) { cb.checked = !cb.checked; cb.disabled = false; alert((d && (d.message || d.error)) || 'Could not update the competition set.'); }
                    })
                    .catch(function () { cb.checked = !cb.checked; cb.disabled = false; });
            });
        });
    })();
    </script>
    @endif
    @endif

    {{-- ── 6. HOLDING COST IMPACT — inline-edit per component ─────────── --}}
    @php
        // Build the inline-edit URL once (rendered inside the version
        // context via the route). When $version isn't present (e.g.
        // pre-version analysis tab) the URL renders empty and the
        // edit-on-click degrades to a read-only display.
        // AT-27 fix 2 — holding costs are editable ONLY pre-confirm (mutable
        // draft). Once the version is confirmed/published the snapshot is frozen
        // and the edit degrades to read-only (confirmed means final).
        $hcConfirmed = isset($version) && $version
            && $version->review_status === \App\Models\PresentationVersion::REVIEW_PUBLISHED;
        $hcUrl = (isset($version) && $version && ! $hcConfirmed)
            ? route('presentations.review.holding-cost-component', ['version' => $version->id])
            : null;
        $hcCsrf = csrf_token();
        $hcComponents = $holding['components'] ?? [];
    @endphp
    @if(!empty($hcComponents))
    <div class="ds-status-card mb-4" style="border-left-color: var(--ds-cyan);" id="holding-cost-card"
         @if($hcUrl) data-url="{{ $hcUrl }}" data-csrf="{{ $hcCsrf }}" @endif>
        <h3 class="ds-section-header">6. Holding Cost Impact</h3>
        @if($hcUrl)
            <p class="text-xs mb-3" style="color: var(--text-muted);">
                Edit any field below — totals recompute as you go. Your numbers help CoreX predict holding costs for similar properties in future presentations.
            </p>
        @elseif($hcConfirmed)
            {{-- AT-27 — section-level NON-SILENT LOCK (platform rule): say why
                 it's read-only and offer the way forward. --}}
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-md px-3 py-2"
                 style="background: color-mix(in srgb, var(--ds-amber, #d97706) 8%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber, #d97706) 30%, transparent);">
                <span class="text-xs" style="color: var(--text-secondary);">
                    &#128274; Locked — confirmed snapshot. Re-open the presentation to edit holding costs, then Confirm &amp; Generate.
                </span>
                @isset($presentation)
                <form method="POST" action="{{ route('presentations.analysis.reopen', $presentation) }}" class="inline">
                    @csrf
                    <button type="submit" class="corex-btn-outline text-xs">Re-open to edit</button>
                </form>
                @endisset
            </div>
        @endif
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
            {{-- Monthly breakdown --}}
            <div>
                <p class="text-xs mb-2 font-medium" style="color: var(--text-muted);">Monthly Breakdown</p>
                <div class="space-y-1" id="hc-breakdown-list">
                    @foreach($hcComponents as $cmp)
                        <div class="hc-row flex justify-between text-sm items-start" data-component="{{ $cmp['component'] }}" data-column="{{ $cmp['column'] }}">
                            <span style="color: var(--text-secondary); display:flex; flex-direction:column;">
                                {{ $cmp['label'] }}
                                {{-- AT-22 item 3 — provenance so the figure isn't opaque --}}
                                @if(!empty($cmp['source_detail']))
                                    <span style="font-size:10px; color: var(--text-muted); line-height:1.2; margin-top:1px;">{{ $cmp['source_detail'] }}</span>
                                @endif
                            </span>
                            @if($hcUrl)
                                {{-- AT-27 — VISIBLE editable field (No Invisible Edits): a
                                     bordered, right-aligned input with a pencil icon so it
                                     is obviously editable at a glance, no hint needed.
                                     Saves + recomputes on change (see JS below). --}}
                                <span class="hc-value-cell" style="display:inline-flex;align-items:center;gap:6px;">
                                    <span style="color: var(--text-muted); font-size:13px;">R</span>
                                    <span style="position:relative; display:inline-flex; align-items:center;">
                                        <input type="number" min="0" step="1"
                                               class="hc-input"
                                               data-component="{{ $cmp['component'] }}"
                                               value="{{ (int) $cmp['value'] }}"
                                               aria-label="Edit {{ $cmp['label'] }} (monthly, ZAR)"
                                               title="Edit — saves on change"
                                               style="width:108px; padding:5px 26px 5px 9px; border:1px solid var(--border); border-radius:6px; font-size:13px; font-weight:600; text-align:right; background: var(--surface); color: var(--text-primary);">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="position:absolute; right:8px; color: var(--text-muted); pointer-events:none;">
                                            <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/>
                                        </svg>
                                    </span>
                                    {{-- hidden mirror so the cascade in applyHoldingCostUpdate
                                         can still address this row's value. --}}
                                    <span class="hc-value-amount" style="display:none;">{{ number_format($cmp['value']) }}</span>
                                </span>
                            @else
                                <span class="font-medium" style="color: var(--text-primary);">R {{ number_format($cmp['value']) }}</span>
                            @endif
                        </div>
                    @endforeach
                    <div class="flex justify-between text-sm pt-2 border-t font-bold" style="border-color: var(--border);">
                        <span style="color: var(--text-primary);">Monthly Total</span>
                        <span style="color: var(--text-primary);">R <span id="hc-monthly-total">{{ number_format($holding['monthly_total']) }}</span></span>
                    </div>
                </div>
            </div>

            {{-- Projections --}}
            <div>
                <p class="text-xs mb-2 font-medium" style="color: var(--text-muted);">Cumulative Projections</p>
                <div class="space-y-2">
                    <div class="flex justify-between items-center bg-amber-50 rounded-md px-4 py-3">
                        <span class="text-sm text-amber-700">3 months</span>
                        <span class="font-bold text-amber-800">R <span id="hc-proj-3m">{{ number_format($holding['projected_3m']) }}</span></span>
                    </div>
                    <div class="flex justify-between items-center bg-orange-50 rounded-md px-4 py-3">
                        <span class="text-sm text-orange-700">6 months</span>
                        <span class="font-bold text-orange-800">R <span id="hc-proj-6m">{{ number_format($holding['projected_6m']) }}</span></span>
                    </div>
                    <div class="flex justify-between items-center bg-red-50 rounded-md px-4 py-3">
                        <span class="text-sm text-red-700">12 months</span>
                        <span class="font-bold text-red-800">R <span id="hc-proj-12m">{{ number_format($holding['projected_12m']) }}</span></span>
                    </div>
                </div>
                <p class="mt-3 text-xs text-red-600 font-medium text-center">
                    Every month at current asking price costs R <span id="hc-monthly-total-2">{{ number_format($holding['monthly_total']) }}</span>
                </p>
            </div>
        </div>
    </div>

    @if($hcUrl)
    {{-- Inline edit-on-click handler. Self-contained — works wherever
         the partial is included as long as a `data-url` is present. --}}
    <script>
    (function () {
        var card = document.getElementById('holding-cost-card');
        if (!card || !card.dataset.url) return;
        var url = card.dataset.url;
        var csrf = card.dataset.csrf;

        function fmt(n) {
            if (n === null || n === undefined) return '—';
            return Number(n).toLocaleString('en-ZA', { maximumFractionDigits: 0 });
        }

        function applyHoldingCostUpdate(holding) {
            if (!holding) return;
            if (typeof holding.monthly_total !== 'undefined') {
                var v = fmt(holding.monthly_total);
                document.getElementById('hc-monthly-total').textContent = v;
                var sec = document.getElementById('hc-monthly-total-2');
                if (sec) sec.textContent = v;
            }
            if (typeof holding.projected_3m  !== 'undefined') document.getElementById('hc-proj-3m').textContent  = fmt(holding.projected_3m);
            if (typeof holding.projected_6m  !== 'undefined') document.getElementById('hc-proj-6m').textContent  = fmt(holding.projected_6m);
            if (typeof holding.projected_12m !== 'undefined') document.getElementById('hc-proj-12m').textContent = fmt(holding.projected_12m);
            if (Array.isArray(holding.components)) {
                holding.components.forEach(function (c) {
                    var row = card.querySelector('.hc-row[data-component="' + c.component + '"]');
                    if (!row) return;
                    var amt = row.querySelector('.hc-value-amount');
                    if (amt) amt.textContent = fmt(c.value);
                    // Keep cascaded input fields in sync (but never clobber the
                    // field the user is actively typing in).
                    var inp = row.querySelector('.hc-input');
                    if (inp && document.activeElement !== inp) inp.value = String(Math.round(c.value));
                });
            }
        }

        // AT-27 — visible-input edit (No Invisible Edits). Each component is an
        // always-visible field; saving + recompute fire on change (commit on
        // blur / Enter), preserving the exact recompute-on-save behaviour.
        function save(input) {
            var component = input.dataset.component;
            var next = parseInt(input.value, 10);
            if (isNaN(next) || next < 0) { next = 0; input.value = '0'; }
            input.disabled = true; input.style.opacity = '0.6';
            var body = new FormData();
            body.append('_token', csrf);
            body.append('component', component);
            body.append('monthly_value_zar', String(next));
            fetch(url, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: body, credentials: 'same-origin',
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (d && d.ok) {
                    var row = input.closest('.hc-row');
                    var amt = row && row.querySelector('.hc-value-amount');
                    if (amt) amt.textContent = fmt(d.value);
                    if (typeof d.value !== 'undefined' && document.activeElement !== input) {
                        input.value = String(Math.round(d.value));
                    }
                    applyHoldingCostUpdate(d.holding_cost);
                }
                input.disabled = false; input.style.opacity = '';
            }).catch(function () { input.disabled = false; input.style.opacity = ''; });
        }

        card.querySelectorAll('.hc-input').forEach(function (input) {
            var last = input.value;
            input.addEventListener('focus', function () { input.select(); });
            input.addEventListener('change', function () {
                if (input.value !== last) { last = input.value; save(input); }
            });
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
            });
        });
    })();
    </script>
    @endif
    @endif

    {{-- ── 7. KEY INSIGHTS ──────────────────────────────────────────────── --}}
    <div class="ds-status-card mb-4" style="border-left-color: var(--ds-cyan);" id="key-insights-container">
        <h3 class="ds-section-header">7. Key Insights</h3>

        @if(!$insights['asking_price_set'])
            <div class="bg-amber-50 border border-amber-200 rounded-md p-4">
                <p class="text-sm text-amber-700">
                    Enter an asking price in the analysis form above to see price position comparisons.
                </p>
            </div>
        @elseif(count($insights['comparisons']) === 0)
            <div class="border rounded-md p-4" style="background: var(--surface-2); border-color: var(--border);">
                <p class="text-sm" style="color: var(--text-secondary);">
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
                    <div class="insight-card flex items-center justify-between p-4 rounded-md border {{ $statusColors }}"
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
