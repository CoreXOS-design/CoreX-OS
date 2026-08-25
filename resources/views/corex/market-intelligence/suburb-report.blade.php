{{-- Suburb Report — combined CMA-vs-CoreX picture (Johan, 2026-08-25).
     UI_DESIGN_SYSTEM.md Pattern A header + card/KPI/badge/empty-state
     patterns throughout. Every section below is real-data-gated: a section
     with nothing behind it does not render at all — no placeholder box, no
     "0 results" panel with a border. Numbers that ARE rendered (KPI tiles)
     show 0 honestly where that is the true count; the ABSENT rule is about
     whole sections, not about zero being a valid value. --}}
@extends('layouts.corex-app')

@php
    $layerA = $data['layer_a'];
    $layerB = $data['layer_b'];
    $layerC = $data['layer_c'];
    $sold = $layerB['available'] ? $layerB['sales_activity']['sold'] : [];
    $underOffer = $layerB['available'] ? $layerB['sales_activity']['under_offer'] : [];
    $priceReductions = $layerB['available'] ? $layerB['price_reductions']['changes'] : [];
@endphp

@section('corex-content')
<div class="max-w-7xl mx-auto space-y-6">

    <x-mic-page-header
        title="{{ $data['suburb']['name'] ?? ('#' . $suburb->id) }}"
        subtitle="{{ $data['suburb']['municipality_confirmed'] ? $data['suburb']['municipality'] . ' — ' : '' }}Suburb Report — as at {{ \Illuminate\Support\Carbon::parse($layerB['as_at'] ?? now())->format('d M Y, H:i') }}" />

    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        @include('corex.market-intelligence._suburb-report-picker', ['currentSuburbName' => $data['suburb']['name'] ?? null])
    </div>

    {{-- KPI strip — always renders; 0 is an honest value, not an empty section. --}}
    <div class="corex-kpi-grid">
        <x-corex-kpi-card title="Stock on market" value="{{ number_format($layerB['stock_on_market']['count'] ?? 0) }}" />
        <x-corex-kpi-card title="Sold" value="{{ number_format(count($sold)) }}" />
        <x-corex-kpi-card title="Under offer" value="{{ number_format(count($underOffer)) }}" />
        <x-corex-kpi-card title="Buyers watching this suburb" value="{{ number_format($layerC['buyers_including_this_suburb'] ?? 0) }}" />
    </div>

    {{-- ================= LAYER A — CMA imports (market data) ================= --}}
    @if($layerA['available'])
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex items-center justify-between mb-1">
            <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Market data (CMA reports on file)</h2>
            <span class="ds-badge ds-badge-info">Market</span>
        </div>
        <p class="text-xs mb-4" style="color: var(--text-muted);">
            What the imported CMA reports say for this suburb. Each report below kept as its own figures — never merged with another report's numbers, even where they cover the same year.
        </p>

        <div class="space-y-4">
            @foreach($layerA['reports'] as $report)
            <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <div class="text-sm font-semibold" style="color: var(--text-primary);">{{ $report['report_type'] }}</div>
                    @if($report['report_date'])
                    <div class="text-xs" style="color: var(--text-muted);">Report date: {{ \Illuminate\Support\Carbon::parse($report['report_date'])->format('d M Y') }}</div>
                    @endif
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs" style="border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--border);">
                                <th class="text-left py-1.5 pr-3" style="color: var(--text-secondary);">Year</th>
                                <th class="text-right py-1.5 pr-3" style="color: var(--text-secondary);">Median</th>
                                <th class="text-right py-1.5 pr-3" style="color: var(--text-secondary);">Average</th>
                                <th class="text-right py-1.5 pr-3" style="color: var(--text-secondary);">Sales count</th>
                                <th class="text-right py-1.5 pr-3" style="color: var(--text-secondary);">Change %</th>
                                <th class="text-right py-1.5" style="color: var(--text-secondary);">Range (low–high)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($report['years'] as $year)
                            <tr style="border-bottom: 1px solid var(--border);">
                                <td class="py-1.5 pr-3 font-medium" style="color: var(--text-primary);">
                                    {{ $year['year'] }}@if($year['is_partial_year']) <span class="ds-badge ds-badge-default" style="font-size: 0.5625rem;">Partial</span>@endif
                                </td>
                                <td class="py-1.5 pr-3 text-right" style="color: var(--text-primary);">{{ isset($year['suburb_median_price_year']) ? 'R ' . number_format($year['suburb_median_price_year']) : '—' }}</td>
                                <td class="py-1.5 pr-3 text-right" style="color: var(--text-primary);">{{ isset($year['suburb_average_price_year']) ? 'R ' . number_format($year['suburb_average_price_year']) : '—' }}</td>
                                <td class="py-1.5 pr-3 text-right" style="color: var(--text-primary);">{{ isset($year['suburb_sales_count_year']) ? number_format($year['suburb_sales_count_year']) : '—' }}</td>
                                <td class="py-1.5 pr-3 text-right" style="color: var(--text-primary);">{{ isset($year['suburb_annual_change_pct']) ? number_format($year['suburb_annual_change_pct'], 1) . '%' : '—' }}</td>
                                <td class="py-1.5 text-right" style="color: var(--text-primary);">
                                    @if(isset($year['suburb_low_year']) || isset($year['suburb_high_year']))
                                        {{ isset($year['suburb_low_year']) ? 'R ' . number_format($year['suburb_low_year']) : '—' }} – {{ isset($year['suburb_high_year']) ? 'R ' . number_format($year['suburb_high_year']) : '—' }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ================= LAYER B — Sold & Under Offer (CoreX own book) ================= --}}
    @if(count($sold) > 0 || count($underOffer) > 0)
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex items-center justify-between mb-1">
            <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Sold &amp; under offer</h2>
            <span class="ds-badge ds-badge-default">CoreX</span>
        </div>
        <p class="text-xs mb-4" style="color: var(--text-muted);">Your own deals for this suburb. "Sold" means granted and registered; "under offer" means an offer is in progress but not yet granted or registered.</p>

        <div class="overflow-x-auto">
            <table class="w-full text-sm" style="border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border);">
                        <th class="text-left py-2 pr-3" style="color: var(--text-secondary);">Address</th>
                        <th class="text-right py-2 pr-3" style="color: var(--text-secondary);">Price</th>
                        <th class="text-left py-2 pr-3" style="color: var(--text-secondary);">Type</th>
                        <th class="text-left py-2" style="color: var(--text-secondary);">Stage</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach(array_merge($sold, $underOffer) as $deal)
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td class="py-2 pr-3" style="color: var(--text-primary);">{{ $deal['address'] ?? '—' }}</td>
                        <td class="py-2 pr-3 text-right" style="color: var(--text-primary);">R {{ number_format($deal['price']) }}</td>
                        <td class="py-2 pr-3" style="color: var(--text-secondary);">
                            @if($deal['comparable'])
                                {{ $deal['beds'] }} bed / {{ $deal['baths'] }} bath — {{ ucfirst((string) $deal['property_type']) }}
                            @else
                                <span style="color: var(--text-muted);">Not comparable — property details not on file</span>
                            @endif
                        </td>
                        <td class="py-2">
                            @if($deal['stage'] === \App\Support\Sales\SaleStageLabel::SOLD)
                                <span class="ds-badge ds-badge-success">Sold</span>
                            @else
                                <span class="ds-badge ds-badge-warning">Under offer</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- ================= LAYER B — Stock on market ================= --}}
    @if($stockListings->isNotEmpty())
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex items-center justify-between mb-1">
            <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Stock on market</h2>
            <span class="ds-badge ds-badge-default">CoreX</span>
        </div>
        <p class="text-xs mb-4" style="color: var(--text-muted);">Active listings in this suburb, most recently listed first.</p>

        <div class="overflow-x-auto">
            <table class="w-full text-sm" style="border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border);">
                        <th class="text-left py-2 pr-3" style="color: var(--text-secondary);">Address</th>
                        <th class="text-right py-2 pr-3" style="color: var(--text-secondary);">Price</th>
                        <th class="text-right py-2" style="color: var(--text-secondary);">Listed</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($stockListings as $listing)
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td class="py-2 pr-3" style="color: var(--text-primary);">{{ $listing->address ?? $listing->street_name ?? '—' }}</td>
                        <td class="py-2 pr-3 text-right" style="color: var(--text-primary);">{{ $listing->price ? 'R ' . number_format($listing->price) : '—' }}</td>
                        <td class="py-2 text-right" style="color: var(--text-secondary);">{{ $listing->listed_date ? \Illuminate\Support\Carbon::parse($listing->listed_date)->format('d M Y') : '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- ================= LAYER B — Price reductions ================= --}}
    @if(count($priceReductions) > 0)
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex items-center justify-between mb-1">
            <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Price reductions</h2>
            <span class="ds-badge ds-badge-default">CoreX</span>
        </div>
        <p class="text-xs mb-4" style="color: var(--text-muted);">{{ number_format(count($priceReductions)) }} portal-reported price change{{ count($priceReductions) === 1 ? '' : 's' }} in this suburb.</p>

        <div class="overflow-x-auto">
            <table class="w-full text-sm" style="border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border);">
                        <th class="text-right py-2 pr-3" style="color: var(--text-secondary);">Old price</th>
                        <th class="text-right py-2 pr-3" style="color: var(--text-secondary);">New price</th>
                        <th class="text-right py-2" style="color: var(--text-secondary);">Date</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach(array_slice($priceReductions, 0, 25) as $change)
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td class="py-2 pr-3 text-right" style="color: var(--text-secondary); text-decoration: line-through;">R {{ number_format($change['old_price']) }}</td>
                        <td class="py-2 pr-3 text-right font-medium" style="color: var(--text-primary);">R {{ number_format($change['new_price']) }}</td>
                        <td class="py-2 text-right" style="color: var(--text-secondary);">{{ $change['change_date'] ? \Illuminate\Support\Carbon::parse($change['change_date'])->format('d M Y') : '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @if(count($priceReductions) > 25)
            <p class="text-xs mt-2" style="color: var(--text-muted);">Showing the 25 most recent of {{ number_format(count($priceReductions)) }}.</p>
            @endif
        </div>
    </div>
    @endif

    {{-- ================= LAYER C — Buyer demand ================= --}}
    @if(($layerC['buyers_including_this_suburb'] ?? 0) > 0)
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex items-center justify-between mb-1">
            <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Buyer demand</h2>
            <span class="ds-badge ds-badge-info">Core Match</span>
        </div>
        <p class="text-xs mb-4" style="color: var(--text-muted);">Active buyers currently watching this suburb, from live Core Match wishlists.</p>

        <div class="flex flex-wrap gap-x-8 gap-y-2 mb-4 text-sm">
            <div>
                <span class="font-semibold" style="color: var(--text-primary);">{{ number_format($layerC['buyers_specifically_this_suburb']) }}</span>
                <span style="color: var(--text-muted);"> watching this suburb only</span>
            </div>
            <div>
                <span class="font-semibold" style="color: var(--text-primary);">{{ number_format($layerC['buyers_including_this_suburb']) }}</span>
                <span style="color: var(--text-muted);"> watching this suburb among others</span>
            </div>
        </div>

        @if(!empty($layerC['price_bands']))
        <div class="overflow-x-auto">
            <table class="w-full text-sm" style="border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border);">
                        <th class="text-left py-2 pr-3" style="color: var(--text-secondary);">Price band</th>
                        <th class="text-right py-2" style="color: var(--text-secondary);">Buyers</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($layerC['price_bands'] as $band => $count)
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td class="py-2 pr-3" style="color: var(--text-primary);">{{ $band }}</td>
                        <td class="py-2 text-right" style="color: var(--text-primary);">{{ number_format($count) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
    @endif

    {{-- ================= Nothing at all ================= --}}
    @if(!$layerA['available'] && count($sold) === 0 && count($underOffer) === 0 && $stockListings->isEmpty() && count($priceReductions) === 0 && ($layerC['buyers_including_this_suburb'] ?? 0) === 0)
    <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
             style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2Z" />
            </svg>
        </div>
        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">Nothing on file for {{ $data['suburb']['name'] ?? 'this suburb' }} yet</h3>
        <p class="text-sm" style="color: var(--text-muted);">No CoreX stock, sales, or buyer activity, and no CMA report imported for this suburb.</p>
    </div>
    @endif

</div>
@endsection
