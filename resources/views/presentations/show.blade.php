@extends('layouts.corex')

@push('head')
<meta name="hfc-presentation-id" content="{{ $presentation->id }}">
<meta name="hfc-presentation-title" content="{{ $presentation->title ?? '' }}">
{{-- Zero out <main> padding so sticky bar pins flush with no gap --}}
<style>#appScroll { padding: 0 !important; }</style>
@endpush

@section('corex-content')

@php
    $statusClasses = match($presentation->status) {
        'presented' => 'bg-emerald-50 text-[#00d4aa]',
        'locked'    => 'pres-badge-success',
        default     => 'bg-slate-100 text-slate-500',
    };
    $lastSummary = $latestSnapshot ? $latestSnapshot->getOutputSummaryArray() : null;
@endphp

{{-- Sticky action bar — no wrapper, no negative margins, <main> padding zeroed --}}
<div class="sticky top-0 z-40 bg-white border-b border-gray-200 shadow-sm">
    <div class="px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-14">
            <div class="flex items-center gap-3">
                <a href="{{ route('presentations.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    All Presentations
                </a>
            </div>
            <div class="flex-1 text-center truncate mx-4">
                <h2 class="text-sm font-semibold text-gray-700 truncate">{{ $presentation->title }}</h2>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('presentations.edit', $presentation) }}" class="px-3 py-1.5 text-sm font-medium bg-slate-100 text-slate-700 rounded-lg hover:bg-slate-200">Edit</a>
                @if($readiness['can_compile'])
                <a href="{{ route('presentations.analysis', [$presentation, 'refresh' => 1]) }}" class="px-3 py-1.5 text-sm font-medium text-white rounded-lg" style="background:#00d4aa;color:#0f172a;font-weight:600;">
                    {{ $latestSnapshot ? 'Re-run Analysis' : 'Run Analysis' }}
                </a>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="pres-page p-4 lg:p-6">

{{-- Navy header bar --}}
<div style="background:#0f172a;" class="rounded-2xl px-6 py-4 mb-8">
    <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
        <div>
            <div class="flex items-center gap-3 mb-1.5">
                <h2 class="text-xl font-bold text-white leading-tight">{{ $presentation->title }}</h2>
                <span class="pres-badge {{ $statusClasses }}" style="border:1px solid rgba(255,255,255,0.2);">
                    {{ ucfirst($presentation->status) }}
                </span>
            </div>
            <p class="text-sm text-white/70 font-medium">{{ $presentation->property_address ?? 'No address set' }}</p>

            {{-- Property details row --}}
            @php
                $propTypeLabels = [
                    'house' => 'House', 'townhouse' => 'Townhouse', 'apartment' => 'Apartment/Flat',
                    'duplex' => 'Duplex', 'vacant_land' => 'Vacant Land', 'farm' => 'Farm',
                    'unit' => 'Unit/Apartment', 'land' => 'Vacant Land', 'other' => 'Other',
                ];
                $propDetails = array_filter([
                    $presentation->suburb,
                    $presentation->property_type ? ($propTypeLabels[$presentation->property_type] ?? ucfirst($presentation->property_type)) : null,
                    $presentation->bedrooms ? $presentation->bedrooms . ' bed' : null,
                    $presentation->bathrooms ? $presentation->bathrooms . ' bath' : null,
                    $presentation->garages_parking ? $presentation->garages_parking . ' garage' : null,
                    $presentation->erf_size_m2 ? number_format($presentation->erf_size_m2) . ' m² erf' : null,
                    $presentation->floor_area_m2 ? $presentation->floor_area_m2 . ' m² floor' : null,
                    $presentation->asking_price_inc ? 'R ' . number_format($presentation->asking_price_inc, 0, '.', ' ') : null,
                ]);
            @endphp
            @if(!empty($propDetails))
                <p class="text-xs text-white/40 mt-1">{{ implode(' · ', $propDetails) }}</p>
            @endif

            @if($presentation->seller_name)
                <p class="text-xs text-white/40 mt-0.5">Seller: {{ $presentation->seller_name }}</p>
            @endif
            <p class="text-xs text-white/40 mt-0.5">Created {{ $presentation->created_at->format('Y-m-d') }}</p>
        </div>
        <a href="{{ route('presentations.index') }}"
           class="corex-btn-outline" style="color:#fff; border-color:rgba(255,255,255,0.3); background:transparent;">
            &larr; All Presentations
        </a>
    </div>
</div>

{{-- Flash messages handled by global toast system --}}

{{-- ACTION BUTTONS --}}
<div class="ds-status-card mb-8">
    <div class="flex flex-wrap items-center gap-3 px-5 py-3.5">
        @if($latestSnapshot)
            <a href="{{ route('presentations.analysis', $presentation) }}"
               class="corex-btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
                View Analysis
            </a>
        @endif
        @if($readiness['can_compile'])
            <a href="{{ route('presentations.analysis', [$presentation, 'refresh' => 1]) }}"
               class="{{ $latestSnapshot ? 'corex-btn-outline' : 'corex-btn-primary' }}">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 0-6.23.693L5 14.5m14.8.8 1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0 1 12 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" /></svg>
                {{ $latestSnapshot ? 'Re-run Analysis' : 'Run Analysis' }}
            </a>
        @elseif(!$latestSnapshot)
            <span class="corex-btn-primary" style="opacity:0.5;cursor:not-allowed;"
                  title="Complete the required evidence items below before running analysis">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 0-6.23.693L5 14.5m14.8.8 1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0 1 12 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" /></svg>
                Run Analysis
            </span>
        @endif
        @if(config('features.pricing_simulator_v1'))
            <a href="{{ route('presentations.pricing-simulator', $presentation) }}"
               class="corex-btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 0 0-2.455 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" /></svg>
                Pricing Simulator
            </a>
            <a href="{{ route('presentations.seller-live', $presentation) }}"
               class="corex-btn-primary" style="background:#1a1a1a;border:1px solid #444;color:#f0f0f0;">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3m0 0 .5 1.5m-.5-1.5h-9.5m0 0-.5 1.5" /></svg>
                Seller Live Test
            </a>
        @endif
        @if(config('features.presentation_blueprint'))
            <form method="POST" action="{{ route('presentations.compile', $presentation) }}" class="inline">
                @csrf
                <button type="submit"
                        class="{{ $readiness['can_compile'] ? 'corex-btn-primary' : 'corex-btn-primary' }}" style="{{ $readiness['can_compile'] ? '' : 'opacity:0.5;cursor:not-allowed;' }}"
                        {{ $readiness['can_compile'] ? '' : 'disabled title="Missing required evidence — see checklist below"' }}>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m3.75 9v6m3-3H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                    Compile Pack
                </button>
            </form>
        @endif
        @if(config('features.presentation_pdf_v1') && isset($latestVersion) && $latestVersion)
            <a href="{{ route('presentations.versions.pdf', [$presentation, $latestVersion]) }}"
               class="corex-btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                Download PDF (v{{ $latestVersion->id }})
            </a>
            <a href="{{ route('presentations.versions.complete-pack', [$presentation, $latestVersion]) }}"
               class="corex-btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0-3-3m3 3 3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" /></svg>
                Complete Pack (ZIP)
            </a>
        @endif
        <a href="{{ route('presentations.edit', $presentation) }}"
           class="corex-btn-outline text-sm" title="Edit property details">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
            Edit Details
        </a>
    </div>
</div>

{{-- Error flash handled by global toast system --}}

{{-- ── READINESS CHECKLIST (P16) ──────────────────────────────────────────── --}}
<div class="ds-status-card mb-8">
    <div class="flex items-center justify-between mb-3">
        <h2 class="ds-section-header" style="margin-bottom:0">Pack Readiness</h2>
        <span class="ds-badge {{ $readiness['completed_percent'] >= 100 ? 'ds-badge-success' : 'ds-badge-info' }}">
            {{ $readiness['completed_percent'] }}% complete
        </span>
    </div>
    <div>
        {{-- Progress bar --}}
        <div class="ds-progress-track mb-5">
            <div class="ds-progress-bar"
                 style="width: {{ $readiness['completed_percent'] }}%; background: var(--pres-brand)"></div>
        </div>

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
            {{-- Required --}}
            <div>
                <p class="text-[11px] font-semibold text-slate-400 mb-2.5 uppercase tracking-widest">Required</p>
                <ul class="space-y-2">
                    @foreach($readiness['required_items'] as $item)
                        <li class="flex items-start gap-2.5 text-xs">
                            <span class="mt-0.5 shrink-0 w-4 h-4 rounded-full flex items-center justify-center text-[10px] {{ $item['satisfied'] ? 'bg-emerald-100 text-[#00d4aa]' : 'bg-slate-100 text-slate-400' }}">
                                {{ $item['satisfied'] ? '✓' : '✗' }}
                            </span>
                            <span class="{{ $item['satisfied'] ? 'text-slate-500' : 'text-slate-700 font-medium' }}">
                                {{ $item['label'] }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Optional --}}
            <div>
                <p class="text-[11px] font-semibold text-slate-400 mb-2.5 uppercase tracking-widest">Optional</p>
                <ul class="space-y-2">
                    @foreach($readiness['optional_items'] as $item)
                        <li class="flex items-start gap-2.5 text-xs">
                            <span class="mt-0.5 shrink-0 w-4 h-4 rounded-full flex items-center justify-center text-[10px] {{ $item['satisfied'] ? 'bg-emerald-100 text-[#00d4aa]' : 'bg-slate-100 text-slate-300' }}">
                                {{ $item['satisfied'] ? '✓' : '○' }}
                            </span>
                            <span class="text-slate-500">{{ $item['label'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        @if($readiness['can_compile'])
            <div class="mt-4 px-3 py-2 rounded-lg" style="background: var(--pres-success-bg)">
                <p class="text-xs font-semibold" style="color: var(--pres-success)">All required items present — ready to compile.</p>
            </div>
        @else
            <div class="mt-4 px-3 py-2 bg-slate-50 rounded-lg">
                <p class="text-xs text-slate-600 font-medium">
                    Missing: {{ implode(', ', array_column($readiness['missing_required'], 'label')) }}
                </p>
            </div>
        @endif
    </div>
</div>

{{-- ── POWER PANEL (UI1) ──────────────────────────────────────────────── --}}
@if($powerPanel)
<div class="ds-status-card mb-8">
    <div class="flex items-center justify-between mb-3">
        <h2 class="ds-section-header" style="margin-bottom:0">Power Panel</h2>
        <span class="text-xs text-slate-400 font-medium">Snapshot {{ $powerPanel['snapshot_at']->format('Y-m-d H:i') }}</span>
    </div>
    <div>

    {{-- Row 1: Probability + Confidence + PPI --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6 mb-5">
        {{-- P30 --}}
        <div class="text-center bg-slate-50 rounded-lg py-3 px-2">
            <p class="ds-label mb-1">P30</p>
            <p class="ds-value-lg {{ ($powerPanel['p30'] ?? 0) >= 0.5 ? 'text-[#00d4aa]' : 'text-slate-800' }}">
                @if($powerPanel['p30'] !== null)
                    {{ number_format($powerPanel['p30'] * 100, 0) }}%
                @else
                    <span class="text-slate-300">--</span>
                @endif
            </p>
        </div>
        {{-- P60 --}}
        <div class="text-center bg-slate-50 rounded-lg py-3 px-2">
            <p class="ds-label mb-1">P60</p>
            <p class="ds-value-lg {{ ($powerPanel['p60'] ?? 0) >= 0.5 ? 'text-[#00d4aa]' : 'text-slate-800' }}">
                @if($powerPanel['p60'] !== null)
                    {{ number_format($powerPanel['p60'] * 100, 0) }}%
                @else
                    <span class="text-slate-300">--</span>
                @endif
            </p>
        </div>
        {{-- P90 --}}
        <div class="text-center bg-slate-50 rounded-lg py-3 px-2">
            <p class="ds-label mb-1">P90</p>
            <p class="ds-value-lg {{ ($powerPanel['p90'] ?? 0) >= 0.65 ? 'text-[#00d4aa]' : 'text-slate-800' }}">
                @if($powerPanel['p90'] !== null)
                    {{ number_format($powerPanel['p90'] * 100, 0) }}%
                @else
                    <span class="text-slate-300">--</span>
                @endif
            </p>
        </div>
        {{-- Expected Days --}}
        <div class="text-center bg-slate-50 rounded-lg py-3 px-2">
            <p class="ds-label mb-1">Exp. Days</p>
            <p class="ds-value-lg text-slate-800">
                @if($powerPanel['expected_days'] !== null)
                    {{ $powerPanel['expected_days'] }}
                @else
                    <span class="text-slate-300">--</span>
                @endif
            </p>
        </div>
        {{-- Confidence --}}
        <div class="text-center bg-slate-50 rounded-lg py-3 px-2">
            <p class="ds-label mb-1">Confidence</p>
            @if($powerPanel['confidence'])
                @php
                    $confScore = $powerPanel['confidence']['confidence_score'] ?? 0;
                    $confGrade = $powerPanel['confidence']['confidence_grade'] ?? '-';
                    $confColor = match($confGrade) {
                        'A' => 'text-[#00d4aa]',
                        'B' => 'text-[#00d4aa]',
                        'C' => 'text-slate-500',
                        default => 'text-slate-400',
                    };
                @endphp
                <p class="ds-value-lg {{ $confColor }}">{{ $confScore }} <span class="text-xs font-medium">({{ $confGrade }})</span></p>
            @else
                <p class="ds-value-lg text-slate-300">--</p>
            @endif
        </div>
        {{-- PPI --}}
        <div class="text-center bg-slate-50 rounded-lg py-3 px-2">
            <p class="ds-label mb-1">PPI</p>
            @if($powerPanel['ppi'])
                @php
                    $ppiScore = $powerPanel['ppi']['ppi_score'] ?? 0;
                    $ppiLabel = $powerPanel['ppi']['ppi_label'] ?? '-';
                    $ppiColor = match($ppiLabel) {
                        'Strong' => 'text-[#00d4aa]',
                        'Balanced' => 'text-slate-600',
                        default => 'text-slate-400',
                    };
                @endphp
                <p class="ds-value-lg {{ $ppiColor }}">{{ $ppiScore }} <span class="text-xs font-medium">({{ $ppiLabel }})</span></p>
            @else
                <p class="ds-value-lg text-slate-300">--</p>
            @endif
        </div>
    </div>

    {{-- Row 2: Competitive Stock + Holding Cost --}}
    @php
        $compStock = $powerPanel['competitive_stock'] ?? null;
        $holdingCost = $powerPanel['holding_cost'] ?? null;
    @endphp
    @if($compStock || $holdingCost)
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 mb-5 pt-4 border-t border-slate-100">
        @if($compStock)
            <div class="bg-slate-50 rounded-lg px-3 py-2">
                <p class="ds-label">Active Stock</p>
                <p class="text-sm font-bold text-slate-700 mt-0.5">{{ $compStock['total_active_stock'] ?? '--' }}</p>
            </div>
            <div class="bg-slate-50 rounded-lg px-3 py-2">
                <p class="ds-label">Below Subject</p>
                <p class="text-sm font-bold text-slate-700 mt-0.5">{{ $compStock['below_subject_count'] ?? '--' }}</p>
            </div>
            <div class="bg-slate-50 rounded-lg px-3 py-2">
                <p class="ds-label">Above Subject</p>
                <p class="text-sm font-bold text-slate-700 mt-0.5">{{ $compStock['above_subject_count'] ?? '--' }}</p>
            </div>
        @endif
        @if($holdingCost)
            <div class="bg-slate-50 rounded-lg px-3 py-2">
                <p class="ds-label">Monthly Hold Cost</p>
                <p class="text-sm font-bold text-slate-700 mt-0.5">R{{ number_format($holdingCost['monthly_total'] ?? 0, 0) }}</p>
            </div>
        @endif
    </div>
    @endif

    {{-- Row 3: Explainability --}}
    @if($powerPanel['explainability'])
        @php $explain = $powerPanel['explainability']; @endphp
        <div class="pt-4 border-t border-slate-100">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                {{-- Key Drivers --}}
                @if(!empty($explain['key_drivers']))
                    <div class="bg-emerald-50 rounded-lg p-3">
                        <p class="text-[11px] font-semibold text-[#0f172a] mb-2 uppercase tracking-widest">Key Drivers</p>
                        <ul class="space-y-1.5">
                            @foreach($explain['key_drivers'] as $driver)
                                <li class="text-xs text-slate-600 flex items-start gap-2">
                                    <span class="text-[#00d4aa] mt-0.5 shrink-0 font-bold">+</span>
                                    {{ $driver }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                {{-- Risk Factors --}}
                @if(!empty($explain['risk_factors']))
                    <div class="bg-amber-50 rounded-lg p-3">
                        <p class="text-[11px] font-semibold text-amber-700 mb-2 uppercase tracking-widest">Risk Factors</p>
                        <ul class="space-y-1.5">
                            @foreach($explain['risk_factors'] as $risk)
                                <li class="text-xs text-slate-600 flex items-start gap-2">
                                    <span class="text-amber-500 mt-0.5 shrink-0 font-bold">!</span>
                                    {{ $risk }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
            {{-- Position summary --}}
            @if(!empty($explain['position_summary']))
                <p class="mt-3 text-xs text-slate-500 italic bg-slate-50 rounded-lg px-3 py-2">{{ $explain['position_summary'] }}</p>
            @endif
        </div>
    @endif
    </div>
</div>
@endif

{{-- ═══════ BUYER DEMAND INTELLIGENCE (Module 13) ═══════ --}}
@if(!empty($buyerDemand) && ($buyerDemand['above_threshold'] ?? false))
<div class="ds-status-card mb-8">
    <h2 class="ds-section-header mb-4">
        <span class="flex items-center gap-2">
            <svg class="w-5 h-5" style="color:#10b981;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
            Active Buyer Demand for Your Property
        </span>
    </h2>
    <p class="text-sm mb-4" style="color:var(--text-muted, #94a3b8);">Buyers in our system actively looking for properties similar to yours.</p>

    {{-- Stat row --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
        <div class="rounded-lg p-4 text-center" style="background: rgba(16,185,129,0.08); border: 1px solid rgba(16,185,129,0.2);">
            <div class="text-2xl font-bold" style="color: #10b981;">{{ $buyerDemand['total_matches'] }}</div>
            <div class="text-xs mt-1" style="color: var(--text-muted, #94a3b8);">buyers actively searching</div>
        </div>
        @if(($buyerDemand['preapproved_count'] ?? 0) > 0)
        <div class="rounded-lg p-4 text-center" style="background: rgba(14,165,233,0.08); border: 1px solid rgba(14,165,233,0.2);">
            <div class="text-2xl font-bold" style="color: #0ea5e9;">{{ $buyerDemand['preapproved_count'] }}</div>
            <div class="text-xs mt-1" style="color: var(--text-muted, #94a3b8);">pre-approved at or above asking</div>
        </div>
        @endif
        @if(($buyerDemand['area_buyers'] ?? 0) > 0)
        <div class="rounded-lg p-4 text-center" style="background: rgba(245,158,11,0.08); border: 1px solid rgba(245,158,11,0.2);">
            <div class="text-2xl font-bold" style="color: #f59e0b;">{{ $buyerDemand['area_buyers'] }}</div>
            <div class="text-xs mt-1" style="color: var(--text-muted, #94a3b8);">searching in this area specifically</div>
        </div>
        @endif
    </div>

    {{-- Anonymised buyer list --}}
    @if(!empty($buyerDemand['anonymised_buyers']))
    <div class="space-y-2">
        <h3 class="text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted, #94a3b8);">Active Buyer Profiles</h3>
        @foreach($buyerDemand['anonymised_buyers'] as $buyer)
        <div class="flex items-center justify-between py-2 px-3 rounded-lg" style="background: var(--surface-2, #f1f5f9); border: 1px solid var(--border, #e2e8f0);">
            <div class="flex items-center gap-2">
                <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold" style="background: rgba(16,185,129,0.15); color: #10b981;">{{ $buyer['label'][strlen($buyer['label'])-1] }}</div>
                <span class="text-sm font-medium" style="color: var(--text-primary, #1e293b);">{{ $buyer['label'] }}</span>
            </div>
            <span class="text-xs px-2 py-0.5 rounded-full font-semibold"
                  style="{{ $buyer['tier'] === 'perfect' ? 'background:rgba(16,185,129,0.15);color:#10b981;' : ($buyer['tier'] === 'strong' ? 'background:rgba(14,165,233,0.15);color:#0ea5e9;' : 'background:rgba(245,158,11,0.15);color:#f59e0b;') }}">
                {{ ucfirst($buyer['tier']) }} match
            </span>
        </div>
        @endforeach
    </div>
    @endif

    <p class="text-[10px] mt-4" style="color: var(--text-muted, #94a3b8);">Data as of {{ now()->format('d M Y') }}. Buyer identities protected per POPIA requirements.</p>
</div>
@endif

<div class="grid grid-cols-1 gap-6 md:grid-cols-2 mb-8">

    {{-- LAST ANALYSIS SUMMARY --}}
    <div class="ds-status-card">
        <h2 class="ds-section-header mb-3">Last Analysis</h2>
        <div>
        @if($lastSummary)
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between items-center py-1.5 border-b border-slate-50">
                    <dt class="text-slate-400 text-xs font-medium">60-day sale probability</dt>
                    <dd class="font-bold text-slate-800">
                        @if(isset($lastSummary['p60']) && $lastSummary['p60'] !== null)
                            {{ number_format($lastSummary['p60'] * 100, 0) }}%
                        @else
                            <span class="text-slate-300">—</span>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between items-center py-1.5 border-b border-slate-50">
                    <dt class="text-slate-400 text-xs font-medium">Expected Days to Sell</dt>
                    <dd class="font-bold text-slate-800">
                        @if(isset($lastSummary['expected_days']) && $lastSummary['expected_days'] !== null)
                            {{ $lastSummary['expected_days'] }} days
                        @else
                            <span class="text-slate-300">—</span>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between items-center py-1.5">
                    <dt class="text-slate-400 text-xs font-medium">Months of Inventory</dt>
                    <dd class="font-bold text-slate-800">
                        @if(isset($lastSummary['months_of_inventory']) && $lastSummary['months_of_inventory'] !== null)
                            {{ number_format($lastSummary['months_of_inventory'], 1) }} mo
                        @else
                            <span class="text-slate-300">—</span>
                        @endif
                    </dd>
                </div>
            </dl>
            <p class="mt-4 text-xs text-slate-400 font-medium">
                Snapshot saved {{ $latestSnapshot->created_at->format('Y-m-d H:i') }}
            </p>
        @else
            <p class="text-sm text-slate-400 italic">No analysis run yet.</p>
            @if($readiness['can_compile'])
                <a href="{{ route('presentations.analysis', $presentation) }}"
                   class="mt-3 inline-block text-xs text-[#00d4aa] hover:underline font-medium">
                    Run first analysis →
                </a>
            @else
                <p class="mt-2 text-xs text-slate-400">Complete the required evidence items above to unlock analysis.</p>
            @endif
        @endif
        </div>
    </div>

    {{-- SNAPSHOTS --}}
    <div class="ds-status-card">
        <h2 class="ds-section-header mb-3">Snapshots</h2>
        <div class="flex flex-col items-start">
            <p class="ds-value-lg text-slate-800 mb-1">{{ $snapshotCount }}</p>
            <p class="text-xs text-slate-400 font-medium">
                {{ $snapshotCount === 1 ? 'snapshot saved' : 'snapshots saved' }}
            </p>
            @if($latestSnapshot)
                <a href="{{ route('presentations.snapshots.show', [$presentation, $latestSnapshot]) }}"
                   class="mt-4 inline-block text-xs text-[#00d4aa] hover:underline font-medium">
                    View latest →
                </a>
            @endif
        </div>
    </div>

</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     LINKS & DOCUMENTS
══════════════════════════════════════════════════════════════════════════ --}}

    {{-- LINKS (full width) --}}
    <div class="ds-status-card mb-8" id="property-links">
        <div class="flex items-center justify-between mb-3">
            <h2 class="ds-section-header" style="margin-bottom:0">Property Links</h2>
            @if(config('features.portal_extension_capture_v1'))
                <div class="flex gap-2">
                    <a href="https://www.property24.com" target="_blank" rel="noopener noreferrer"
                       class="corex-btn-primary text-xs py-1.5 px-3">
                        Property24
                    </a>
                    <a href="https://www.privateproperty.co.za" target="_blank" rel="noopener noreferrer"
                       class="corex-btn-primary text-xs py-1.5 px-3">
                        PrivateProperty
                    </a>
                </div>
            @endif
        </div>
        <div>

        {{-- P24 SEARCH BUTTONS --}}
        @if($presentation->asking_price_inc)
            @php
                $p24Suburb = $presentation->suburb ?? '';
                $p24Beds   = $presentation->bedrooms ?? '';
                $p24Ask    = (int) $presentation->asking_price_inc;
                $p24Min    = (int) (floor(($p24Ask * 0.7) / 100000) * 100000);
                $p24Max    = (int) (ceil(($p24Ask * 1.3) / 100000) * 100000);

                // Property type → P24 URL path segment
                $p24PathMap = [
                    'house'       => 'houses-for-sale',
                    'townhouse'   => 'townhouses-for-sale',
                    'apartment'   => 'apartments-for-sale',
                    'duplex'      => 'houses-for-sale',
                    'vacant_land' => 'vacant-land-for-sale',
                    'farm'        => 'farms-for-sale',
                    'unit'        => 'apartments-for-sale',
                    'land'        => 'vacant-land-for-sale',
                ];
                $p24TypeLabel = [
                    'house'       => 'House',
                    'townhouse'   => 'Townhouse',
                    'apartment'   => 'Apartment/Flat',
                    'duplex'      => 'Duplex',
                    'vacant_land' => 'Vacant Land',
                    'farm'        => 'Farm',
                    'unit'        => 'Apartment/Flat',
                    'land'        => 'Vacant Land',
                    'other'       => 'All Types',
                ];
                $p24TypeSlug = $p24PathMap[$presentation->property_type] ?? 'for-sale';
                $p24TypeDisplay = $p24TypeLabel[$presentation->property_type] ?? ucfirst($presentation->property_type ?? '');

                // Look up suburb in DB first (p24_suburbs table), fall back to config
                $p24SuburbKey  = strtolower(trim($p24Suburb));
                $p24SuburbInfo = null;
                if (class_exists(\App\Models\P24Suburb::class) && \Schema::hasTable('p24_suburbs')) {
                    $dbSuburb = \App\Models\P24Suburb::where('slug', str_replace(' ', '-', $p24SuburbKey))
                        ->orWhereRaw('LOWER(name) = ?', [$p24SuburbKey])
                        ->first();
                    if ($dbSuburb) {
                        $p24SuburbInfo = [
                            'id'          => $dbSuburb->p24_id,
                            'slug'        => $dbSuburb->slug,
                            'surrounding' => $dbSuburb->surrounding_ids ?? [],
                            'confirmed'   => $dbSuburb->confirmed,
                        ];
                    }
                }
                if (!$p24SuburbInfo) {
                    $p24Suburbs    = config('p24_suburbs', []);
                    $p24SuburbInfo = $p24Suburbs[$p24SuburbKey] ?? null;
                }
                // If suburb found but ID is unconfirmed, fall back to Term= search
                if ($p24SuburbInfo && empty($p24SuburbInfo['confirmed'])) {
                    $p24SuburbInfo = null; // force Term= fallback
                }

                // Build advanced-search URL: sp=s%3d{ids}%26pf%3d{min}%26pt%3d{max}%26bd%3d{beds}
                $p24BaseUrl    = 'https://www.property24.com/' . $p24TypeSlug . '/advanced-search/results';
                $p24Url        = null;  // Suburb-only URL
                $p24WideUrl    = null;  // Suburb + surrounding URL
                $p24FallbackUrl = null; // Term-based fallback

                if ($p24SuburbInfo) {
                    // Suburb-only: s=6357
                    $p24SpParams = 's%3d' . $p24SuburbInfo['id']
                        . '%26pf%3d' . $p24Min . '%26pt%3d' . $p24Max;
                    if ($p24Beds) {
                        $p24SpParams .= '%26bd%3d' . $p24Beds;
                    }
                    $p24Url = $p24BaseUrl . '?sp=' . $p24SpParams;

                    // Wider area: s=6357,6358,33106,6336 (suburb + surrounding)
                    $surrounding = $p24SuburbInfo['surrounding'] ?? [];
                    if (!empty($surrounding)) {
                        $allIds = array_merge([$p24SuburbInfo['id']], $surrounding);
                        $p24WideSpParams = 's%3d' . implode('%2c', $allIds)
                            . '%26pf%3d' . $p24Min . '%26pt%3d' . $p24Max;
                        if ($p24Beds) {
                            $p24WideSpParams .= '%26bd%3d' . $p24Beds;
                        }
                        $p24WideUrl = $p24BaseUrl . '?sp=' . $p24WideSpParams;
                    }
                } else {
                    // Fallback: Term-based search
                    $p24SpFallback = 'pf%3d' . $p24Min . '%26pt%3d' . $p24Max;
                    if ($p24Beds) {
                        $p24SpFallback .= '%26bd%3d' . $p24Beds;
                    }
                    $p24FallbackUrl = $p24BaseUrl . '?sp=' . $p24SpFallback;
                    if ($p24Suburb) {
                        $p24FallbackUrl .= '&Term=' . urlencode($p24Suburb);
                    }
                }
            @endphp
            <div class="mb-5 p-4 rounded-xl border-2 border-emerald-200 bg-emerald-50/50">
                <div class="flex items-center justify-between gap-4 flex-wrap">
                    <div>
                        <p class="text-xs font-semibold text-slate-600 mb-1">Quick Search — find competing listings</p>
                        <p class="text-xs text-slate-500">
                            {{ $p24TypeDisplay }},
                            @if($p24Beds) {{ $p24Beds }}+ beds, @endif
                            <strong>R {{ number_format($p24Min, 0, '.', ',') }}</strong> &ndash;
                            <strong>R {{ number_format($p24Max, 0, '.', ',') }}</strong>
                        </p>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        @if($p24Url)
                            <a href="{{ $p24Url }}"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-white text-xs font-bold shadow-sm hover:shadow transition-all"
                               style="background: linear-gradient(135deg, #4f46e5, #6366f1);">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                                Search {{ $p24Suburb }}
                            </a>
                            @if($p24WideUrl)
                                <a href="{{ $p24WideUrl }}"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-[#0f172a] text-xs font-bold border border-emerald-300 bg-white hover:bg-emerald-50 shadow-sm hover:shadow transition-all">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                                    {{ $p24Suburb }} + Surrounding
                                </a>
                            @endif
                        @elseif($p24FallbackUrl)
                            <a href="{{ $p24FallbackUrl }}"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-white text-xs font-bold shadow-sm hover:shadow transition-all"
                               style="background: linear-gradient(135deg, #4f46e5, #6366f1);">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                                Search Property24
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        @else
            <div class="mb-5 p-3 rounded-lg bg-slate-50 border border-slate-200">
                <p class="text-xs text-slate-400 italic">Enter an asking price to enable the Property24 search button.</p>
            </div>
        @endif

        @if($links->isEmpty())
            <p class="text-xs text-slate-400 italic mb-3">No links added yet.</p>
        @else
            @php
                $linkTypeLabels = [
                    'property24'         => 'Property24',
                    'lightstone'         => 'Lightstone',
                    'active_listing'     => 'Active Listing',
                    'competitor_listing'  => 'Competitor',
                    'market_article'     => 'Article',
                    'other'              => 'Other',
                ];
            @endphp
            <ul class="space-y-3 mb-4" id="links-list">
                @foreach($links as $link)
                    <li class="pres-link-row text-xs" data-link-id="{{ $link->id }}">
                        {{-- Row 1: Link header --}}
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex items-center gap-1 flex-wrap">
                                @php
                                    $linkColor = in_array($link->type, ['active_listing', 'competitor_listing'])
                                        ? 'bg-emerald-50 text-[#00d4aa]' : 'bg-slate-100 text-slate-500';
                                @endphp
                                <span class="pres-badge {{ $linkColor }}">
                                    {{ $linkTypeLabels[$link->type] ?? ucfirst($link->type) }}
                                </span>

                                {{-- Extraction status badge --}}
                                @php
                                    $lHasCapture = !empty($link->portal_capture_id);
                                    $lExtStatus = $link->extraction_status ?? 'pending';
                                    if ($lHasCapture) {
                                        $lExtBadge = 'bg-emerald-50 text-[#00d4aa]';
                                        $lExtLabel = 'Captured';
                                    } else {
                                        $lExtBadge = match($lExtStatus) {
                                            'ok'     => 'bg-emerald-50 text-[#00d4aa]',
                                            'failed' => 'bg-slate-100 text-slate-500',
                                            default  => 'bg-slate-50 text-slate-400',
                                        };
                                        $lExtLabel = match($lExtStatus) {
                                            'ok'     => 'Extracted',
                                            'failed' => 'Failed',
                                            default  => 'Pending',
                                        };
                                    }
                                @endphp
                                <span class="pres-badge {{ $lExtBadge }}" data-link-badge="{{ $link->id }}">
                                    {{ $lExtLabel }}
                                </span>

                                @unless(config('features.portal_extension_capture_v1') && $link->type === 'property24')
                                    <form method="POST"
                                          action="{{ route('presentations.links.re-extract', [$presentation, $link]) }}"
                                          class="inline">
                                        @csrf
                                        <button type="submit"
                                                class="inline-block px-1 py-0.5 text-xs text-[#00d4aa] hover:text-[#0f172a]"
                                                title="Re-run extraction">&#x27F3;</button>
                                    </form>
                                @endunless

                                @if($link->isOverridden())
                                    <span class="pres-badge pres-badge-warn">
                                        Override
                                    </span>
                                @endif

                                <a href="{{ $link->url }}" target="_blank" rel="noopener noreferrer"
                                   class="text-[#00d4aa] hover:underline break-all">
                                    {{ \Illuminate\Support\Str::limit($link->url, 50) }}
                                </a>
                                @if($link->notes)
                                    <span class="text-gray-400"> — {{ $link->notes }}</span>
                                @endif
                            </div>
                            <div class="flex items-center gap-1.5 shrink-0">
                                <form method="POST"
                                      action="{{ route('presentations.links.update-type', [$presentation, $link]) }}"
                                      class="flex items-center gap-1.5">
                                    @csrf
                                    @method('PATCH')
                                    <select name="type" class="pres-select text-xs">
                                        @foreach($linkTypeLabels as $val => $label)
                                            <option value="{{ $val }}" {{ $link->type === $val ? 'selected' : '' }}>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit"
                                            class="text-xs text-[#00d4aa] hover:text-[#0f172a] font-semibold">Save</button>
                                </form>
                                <form method="POST"
                                      action="{{ route('presentations.links.destroy', [$presentation, $link]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="text-red-400 hover:text-red-600 text-xs"
                                            onclick="return confirm('Remove this link?')">✕</button>
                                </form>
                            </div>
                        </div>

                        {{-- Row 2: Extraction summary --}}
                        @php
                            $lVerified = $link->getVerifiedData();
                            $lPageType = $lVerified['_page_type'] ?? null;
                            // Also check capture page_type for classification
                            if (!$lPageType && $lHasCapture && $link->portalCapture) {
                                $lPageType = $link->portalCapture->page_type === 'search' ? 'search' : ($link->portalCapture->page_type === 'property' ? 'listing' : null);
                            }
                            // Legacy fallback
                            if (!$lPageType && $lVerified && ($lVerified['link_subtype'] ?? '') === 'search_results') {
                                $lPageType = 'search';
                            }
                        @endphp
                        @if($lVerified && $lPageType === 'search')
                            {{-- Search results summary --}}
                            @php
                                $lParts = [];
                                $lListingsFound = $lVerified['listing_urls_count'] ?? $lVerified['search']['items_on_page'] ?? $lVerified['results_count'] ?? null;
                                if ($lListingsFound) $lParts[] = 'Listings: ' . $lListingsFound;
                                if (!empty($lVerified['price_min']) && !empty($lVerified['price_max'])) {
                                    $lParts[] = 'Range: R' . number_format($lVerified['price_min'], 0) . ' – R' . number_format($lVerified['price_max'], 0);
                                }
                                if (!empty($lVerified['price_median'])) $lParts[] = 'Median: R' . number_format($lVerified['price_median'], 0);
                            @endphp
                            @if(!empty($lParts))
                                <div class="mt-1.5 text-xs text-slate-600 bg-emerald-50 rounded px-2 py-1">
                                    Search capture | {{ implode(' | ', $lParts) }}
                                </div>
                            @else
                                <div class="mt-1.5 text-xs text-slate-600 bg-emerald-50 rounded px-2 py-1">
                                    Search capture
                                </div>
                            @endif
                        @elseif($lVerified && ($lPageType === 'listing' || !empty($lVerified['asking_price']) || !empty($lVerified['price'])))
                            {{-- Listing summary --}}
                            @php
                                $lParts = [];
                                $lPrice = $lVerified['asking_price'] ?? $lVerified['price'] ?? null;
                                if ($lPrice) $lParts[] = 'R' . number_format($lPrice, 0);
                                $lBeds = $lVerified['beds'] ?? $lVerified['bedrooms'] ?? null;
                                $lBaths = $lVerified['baths'] ?? $lVerified['bathrooms'] ?? null;
                                if ($lBeds) $lParts[] = $lBeds . ' bed';
                                if ($lBaths) $lParts[] = $lBaths . ' bath';
                                $lFloor = $lVerified['floor_area_m2'] ?? $lVerified['floor_m2'] ?? null;
                                if ($lFloor) $lParts[] = $lFloor . 'm²';
                                if (!empty($lVerified['suburb'])) $lParts[] = $lVerified['suburb'];
                            @endphp
                            <div class="mt-1.5 text-xs text-slate-600 bg-slate-50 rounded px-2 py-1">
                                {{ implode(' | ', $lParts) }}
                            </div>
                        @elseif($lVerified)
                            {{-- Generic key-value fallback --}}
                            @php
                                $lSkipKeys = ['extractor_version', 'link_type', 'url', 'source_domain', 'source_site', 'link_subtype', 'snapshot_id', 'extraction_method', 'snapshot_error', 'top_listings', 'blocked_reason', 'timed_out', 'http_status', 'content_bytes', '_page_type', '_extractor', '_extraction', '_capture_source', '_capture_id', 'search', 'listing_urls_count'];
                            @endphp
                            <div class="mt-1.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-gray-500">
                                @foreach($lVerified as $lKey => $lVal)
                                    @if(!in_array($lKey, $lSkipKeys) && $lVal !== null && $lVal !== '' && !is_array($lVal))
                                        <span>
                                            <span class="text-gray-400">{{ str_replace('_', ' ', $lKey) }}:</span>
                                            @if(is_numeric($lVal) && $lVal >= 10000)
                                                R{{ number_format($lVal, 0) }}
                                            @else
                                                {{ $lVal }}
                                            @endif
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                        @if($lHasCapture)
                            @php $lCapture = $link->portalCapture; @endphp
                            @if($lCapture)
                                <div class="mt-1.5 bg-emerald-50 border border-emerald-200 rounded px-2 py-1.5 text-xs text-[#0f172a] flex items-center justify-between gap-2">
                                    <div class="flex-1">
                                        <span class="font-semibold">Captured via extension</span>
                                        — {{ number_format($lCapture->html_bytes) }} bytes
                                        @if($lCapture->screenshot_path)
                                            | screenshot saved
                                        @endif
                                        | {{ $lCapture->captured_at->format('Y-m-d H:i') }}
                                    </div>
                                </div>
                                @php $lPriceChanges = $lCapture->priceChangeCount(); @endphp
                                <div class="mt-1 rounded px-2 py-1 text-xs font-medium {{ $lPriceChanges > 0 ? '' : 'hidden' }}" data-price-change="{{ $link->id }}" style="background:var(--pres-warn-bg);color:#92400e;border:1px solid #fcd34d">
                                    Price Change Detected — <span data-price-change-count="{{ $link->id }}">{{ $lPriceChanges }}</span> listing{{ $lPriceChanges > 1 ? 's' : '' }} changed
                                </div>
                            @endif
                        @endif
                        @if($lExtStatus === 'failed' && !$lHasCapture)
                            @if(config('features.portal_extension_capture_v1') && $link->type === 'property24')
                                {{-- Portal extension capture mode: no headless retry --}}
                                <div class="mt-1.5 bg-emerald-50 border border-emerald-200 rounded px-2 py-1.5 text-xs text-[#0f172a] flex items-center justify-between gap-2">
                                    <div class="flex-1">
                                        <span class="font-semibold">Capture via Browser Extension</span> — open the portal and use the capture extension
                                    </div>
                                    <a href="{{ $link->url }}" target="_blank" rel="noopener noreferrer"
                                       class="px-2 py-0.5 text-white text-xs rounded font-medium shrink-0" style="background:var(--pres-brand)">
                                        Open Portal
                                    </a>
                                </div>
                            @else
                                @php
                                    $lBlockedReason = $lVerified['blocked_reason'] ?? null;
                                    $lHttpStatus    = $lVerified['http_status'] ?? null;
                                    $lTimedOut      = $lVerified['timed_out'] ?? false;
                                    $lErrorMsg      = $link->extraction_error ?? 'check link type';

                                    // Determine error category for styling
                                    $lIsBlocked = $lBlockedReason || ($lHttpStatus && $lHttpStatus >= 400);
                                    $lIsTimeout = $lTimedOut;
                                @endphp
                                <div class="mt-1.5 {{ $lIsBlocked ? 'bg-red-50 border-red-200' : ($lIsTimeout ? 'bg-amber-50 border-amber-200' : 'bg-red-50 border-red-200') }} border rounded px-2 py-1.5 text-xs {{ $lIsBlocked ? 'text-red-700' : ($lIsTimeout ? 'text-amber-700' : 'text-red-700') }} flex items-center justify-between gap-2">
                                    <div class="flex-1">
                                        @if(str_starts_with($lBlockedReason ?? '', 'headless_service_'))
                                            <span class="font-semibold">Portal fetch engine offline</span> — start the headless service and retry
                                        @elseif($lIsBlocked)
                                            <span class="font-semibold">Blocked</span> — {{ $lBlockedReason ?? $lErrorMsg }}
                                            @if($lHttpStatus)
                                                <span class="text-red-500">(HTTP {{ $lHttpStatus }})</span>
                                            @endif
                                        @elseif($lIsTimeout)
                                            <span class="font-semibold">Timed out</span> — connection to site failed
                                        @else
                                            No data extracted — {{ $lErrorMsg }}
                                        @endif
                                    </div>
                                    <form method="POST"
                                          action="{{ route('presentations.links.re-extract', [$presentation, $link]) }}"
                                          class="shrink-0">
                                        @csrf
                                        <button type="submit"
                                                class="px-2 py-0.5 bg-red-600 text-white text-xs rounded hover:bg-red-700 font-medium">
                                            Retry
                                        </button>
                                    </form>
                                </div>
                            @endif
                        @endif

                        {{-- Override audit info --}}
                        @if($link->isOverridden())
                            <p class="mt-1 text-xs text-slate-500">
                                Overridden {{ $link->override_at ? $link->override_at->format('Y-m-d H:i') : '' }}
                                @if($link->override_by_user_id)
                                    by user #{{ $link->override_by_user_id }}
                                @endif
                            </p>
                        @endif

                        {{-- Expand: details + override form (V1 curated) --}}
                        @if(config('features.presentation_link_details_v1') && isset($linkViews[$link->id]))
                            @php $lView = $linkViews[$link->id]; @endphp
                            <details class="mt-1.5">
                                <summary class="text-xs text-[#00d4aa] cursor-pointer hover:underline">
                                    @if(($lView['capture_page_type'] ?? null) === 'search')
                                        View search summary
                                    @else
                                        {{ $link->isOverridden() ? 'Edit override' : 'View details / Override' }}
                                    @endif
                                </summary>
                                <div class="mt-2 space-y-3">

                                    @if(($lView['capture_page_type'] ?? null) === 'search')
                                        {{-- ═══ SEARCH CAPTURE SUMMARY ═══ --}}
                                        <div class="bg-emerald-50 border border-emerald-200 rounded p-3">
                                            <p class="text-xs font-semibold text-[#0f172a] mb-2 uppercase tracking-wide">Search Capture Summary</p>
                                            @if(!empty($lView['search_summary']))
                                                <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                                                    @if(!empty($lView['search_summary']['listings_found']))
                                                        <dt class="text-[#38bfe0] whitespace-nowrap">Listings found</dt>
                                                        <dd class="text-[#0f172a] font-medium">{{ $lView['search_summary']['listings_found'] }}</dd>
                                                    @endif
                                                    @if(!empty($lView['search_summary']['total_results']))
                                                        <dt class="text-[#38bfe0] whitespace-nowrap">Total results</dt>
                                                        <dd class="text-[#0f172a] font-medium">{{ $lView['search_summary']['total_results'] }}</dd>
                                                    @endif
                                                    @if(!empty($lView['search_summary']['price_change_count']))
                                                        <dt class="text-[#38bfe0] whitespace-nowrap">Price changes</dt>
                                                        <dd class="text-amber-700 font-semibold">{{ $lView['search_summary']['price_change_count'] }}</dd>
                                                    @endif
                                                    @if(!empty($lView['search_summary']['capture_time']))
                                                        <dt class="text-[#38bfe0] whitespace-nowrap">Captured</dt>
                                                        <dd class="text-[#0f172a]">{{ $lView['search_summary']['capture_time'] }}</dd>
                                                    @endif
                                                    @if(!empty($lView['search_summary']['html_bytes']))
                                                        <dt class="text-[#38bfe0] whitespace-nowrap">Page size</dt>
                                                        <dd class="text-[#0f172a]">{{ number_format($lView['search_summary']['html_bytes']) }} bytes</dd>
                                                    @endif
                                                    @if(!empty($lView['search_summary']['parse_status']))
                                                        <dt class="text-[#38bfe0] whitespace-nowrap">Status</dt>
                                                        <dd class="text-[#0f172a]">{{ $lView['search_summary']['parse_status'] }}</dd>
                                                    @endif
                                                </dl>
                                            @endif
                                            <p class="mt-2 text-xs text-[#00d4aa] italic">
                                                Search captures monitor competitor changes. To see listing details, open the listing page and capture it.
                                            </p>
                                        </div>
                                        {{-- NO override table for search captures --}}
                                    @else
                                        {{-- ═══ LISTING DETAILS + OVERRIDE ═══ --}}

                                        {{-- Imported fields (curated, human-readable) --}}
                                        @if(!empty($lView['imported']))
                                            <div class="bg-gray-50 rounded p-2">
                                                <p class="text-xs font-medium text-gray-500 mb-1.5 uppercase tracking-wide">Imported data</p>
                                                <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                                                    @foreach($lView['imported'] as $fieldLabel => $fieldVal)
                                                        <dt class="text-gray-400 whitespace-nowrap">{{ $fieldLabel }}</dt>
                                                        <dd class="text-gray-700 font-medium">{{ $fieldVal }}</dd>
                                                    @endforeach
                                                </dl>
                                            </div>
                                        @else
                                            <p class="text-xs text-gray-400 italic">No imported data available.</p>
                                        @endif

                                        {{-- Meta row --}}
                                        @if(!empty($lView['meta']))
                                            <div class="flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-gray-400">
                                                @foreach($lView['meta'] as $mLabel => $mVal)
                                                    <span>{{ $mLabel }}: <span class="text-gray-600">{{ $mVal }}</span></span>
                                                @endforeach
                                            </div>
                                        @endif

                                        {{-- Override section --}}
                                        @if(!empty($lView['override_fields']))
                                            <form method="POST"
                                                  action="{{ route('presentations.links.override', [$presentation, $link]) }}"
                                                  class="border border-slate-200 rounded p-2 bg-slate-50">
                                                @csrf
                                                @method('PATCH')
                                                <p class="text-xs font-medium text-slate-600 mb-1.5">Override values</p>
                                                <table class="w-full text-xs border-collapse">
                                                    <thead>
                                                        <tr class="text-left text-gray-400 border-b">
                                                            <th class="py-1 pr-2 font-medium">Field</th>
                                                            <th class="py-1 pr-2 font-medium">Current</th>
                                                            <th class="py-1 pr-2 font-medium">Imported</th>
                                                            <th class="py-1 font-medium">Override</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach($lView['override_fields'] as $oField)
                                                            <tr class="border-b border-gray-100">
                                                                <td class="py-1.5 pr-2 text-gray-500 whitespace-nowrap">{{ $oField['label'] }}</td>
                                                                <td class="py-1.5 pr-2 text-gray-700">{{ $oField['current'] ?? '—' }}</td>
                                                                <td class="py-1.5 pr-2 {{ $oField['imported'] ? 'text-[#00d4aa]' : 'text-gray-300' }}">
                                                                    {{ $oField['imported'] ?? ($oField['imported_missing_label'] ?? 'No imported value yet') }}
                                                                </td>
                                                                <td class="py-1.5">
                                                                    <input type="text" name="override_data[{{ $oField['key'] }}]"
                                                                           placeholder="{{ $oField['label'] }}"
                                                                           value="{{ $oField['current_raw'] ?? '' }}"
                                                                           class="w-full border border-gray-200 rounded px-1.5 py-0.5 text-xs">
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                                @if(!empty($lView['meta']['Captured']))
                                                    <p class="text-xs text-gray-400 mt-1">Last captured: {{ $lView['meta']['Captured'] }}
                                                        @if(!empty($lView['meta']['Source']))
                                                            ({{ $lView['meta']['Source'] }})
                                                        @endif
                                                    </p>
                                                @endif
                                                <div class="flex gap-2 mt-1.5">
                                                    <button type="submit"
                                                            class="px-2 py-1 text-white text-xs rounded" style="background:var(--pres-brand)">
                                                        Save Override
                                                    </button>
                                                </div>
                                            </form>
                                        @else
                                            {{-- Fallback for non-listing types (article, other) --}}
                                            @php $lOverride = $link->override_json ?? $link->extracted_json ?? []; @endphp
                                            <form method="POST"
                                                  action="{{ route('presentations.links.override', [$presentation, $link]) }}"
                                                  class="border border-slate-200 rounded p-2 bg-slate-50">
                                                @csrf
                                                @method('PATCH')
                                                <p class="text-xs font-medium text-slate-600 mb-1.5">Override values</p>
                                                <div class="grid grid-cols-2 gap-1.5">
                                                    @if($link->type === 'market_article')
                                                        <input type="text" name="override_data[headline]" placeholder="Headline"
                                                               value="{{ $lOverride['headline'] ?? '' }}"
                                                               class="col-span-2 border border-gray-200 rounded px-2 py-1 text-xs">
                                                    @else
                                                        <input type="text" name="override_data[notes]" placeholder="Notes"
                                                               value="{{ $lOverride['notes'] ?? '' }}"
                                                               class="col-span-2 border border-gray-200 rounded px-2 py-1 text-xs">
                                                    @endif
                                                </div>
                                                <div class="flex gap-2 mt-1.5">
                                                    <button type="submit"
                                                            class="px-2 py-1 text-white text-xs rounded" style="background:var(--pres-brand)">
                                                        Save Override
                                                    </button>
                                                </div>
                                            </form>
                                        @endif

                                        @if($link->isOverridden())
                                            <form method="POST"
                                                  action="{{ route('presentations.links.override.clear', [$presentation, $link]) }}"
                                                  class="mt-1">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="px-2 py-1 text-xs text-gray-500 hover:text-red-600"
                                                        onclick="return confirm('Clear this override?')">
                                                    Clear Override
                                                </button>
                                            </form>
                                        @endif
                                    @endif

                                    {{-- Diagnostics (raw) — admin only --}}
                                    @if($isAdmin && $link->extracted_json)
                                        <details class="mt-1">
                                            <summary class="text-xs text-gray-400 cursor-pointer hover:text-gray-600">Diagnostics (raw)</summary>
                                            <div class="mt-1 bg-gray-50 rounded p-2 text-xs font-mono text-gray-600 overflow-x-auto max-h-40 overflow-y-auto">
                                                <pre>{{ json_encode($link->extracted_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                            </div>
                                            @if($link->portal_capture_id && $link->portalCapture && $link->portalCapture->extracted_fields_json)
                                                <p class="text-xs text-gray-400 mt-1">Portal capture fields:</p>
                                                <div class="mt-0.5 bg-gray-50 rounded p-2 text-xs font-mono text-gray-600 overflow-x-auto max-h-40 overflow-y-auto">
                                                    <pre>{{ json_encode($link->portalCapture->extracted_fields_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                                </div>
                                            @endif
                                        </details>
                                    @endif
                                </div>
                            </details>
                        @else
                            {{-- Legacy fallback (feature flag off) --}}
                            <details class="mt-1.5">
                                <summary class="text-xs text-[#00d4aa] cursor-pointer hover:underline">
                                    {{ $link->isOverridden() ? 'Edit override' : 'View details / Override' }}
                                </summary>
                                <div class="mt-2 space-y-2">
                                    @if($link->extracted_json)
                                        <div class="bg-gray-50 rounded p-2 text-xs font-mono text-gray-600 overflow-x-auto max-h-40 overflow-y-auto">
                                            <pre>{{ json_encode($link->extracted_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </div>
                                    @endif
                                    @php $lOverride = $link->override_json ?? $link->extracted_json ?? []; @endphp
                                    <form method="POST"
                                          action="{{ route('presentations.links.override', [$presentation, $link]) }}"
                                          class="border border-slate-200 rounded p-2 bg-slate-50">
                                        @csrf
                                        @method('PATCH')
                                        <p class="text-xs font-medium text-slate-600 mb-1.5">Override values</p>
                                        <div class="grid grid-cols-2 gap-1.5">
                                            @if(in_array($link->type, ['property24', 'active_listing', 'competitor_listing']))
                                                <input type="number" name="override_data[asking_price]" placeholder="Asking price (R)"
                                                       value="{{ $lOverride['asking_price'] ?? '' }}"
                                                       class="border border-gray-200 rounded px-2 py-1 text-xs">
                                                <input type="text" name="override_data[suburb]" placeholder="Suburb"
                                                       value="{{ $lOverride['suburb'] ?? '' }}"
                                                       class="border border-gray-200 rounded px-2 py-1 text-xs">
                                                <input type="number" name="override_data[beds]" placeholder="Beds"
                                                       value="{{ $lOverride['beds'] ?? '' }}"
                                                       class="border border-gray-200 rounded px-2 py-1 text-xs">
                                                <input type="number" name="override_data[baths]" placeholder="Baths"
                                                       value="{{ $lOverride['baths'] ?? '' }}"
                                                       class="border border-gray-200 rounded px-2 py-1 text-xs">
                                                <input type="number" name="override_data[floor_area_m2]" placeholder="Floor m²"
                                                       value="{{ $lOverride['floor_area_m2'] ?? '' }}"
                                                       class="border border-gray-200 rounded px-2 py-1 text-xs">
                                                <input type="number" name="override_data[erf_m2]" placeholder="Erf m²"
                                                       value="{{ $lOverride['erf_m2'] ?? '' }}"
                                                       class="border border-gray-200 rounded px-2 py-1 text-xs">
                                            @elseif($link->type === 'market_article')
                                                <input type="text" name="override_data[headline]" placeholder="Headline"
                                                       value="{{ $lOverride['headline'] ?? '' }}"
                                                       class="col-span-2 border border-gray-200 rounded px-2 py-1 text-xs">
                                            @else
                                                <input type="text" name="override_data[notes]" placeholder="Notes"
                                                       value="{{ $lOverride['notes'] ?? '' }}"
                                                       class="col-span-2 border border-gray-200 rounded px-2 py-1 text-xs">
                                            @endif
                                        </div>
                                        <div class="flex gap-2 mt-1.5">
                                            <button type="submit"
                                                    class="px-2 py-1 text-white text-xs rounded" style="background:var(--pres-brand)">
                                                Save Override
                                            </button>
                                        </div>
                                    </form>
                                    @if($link->isOverridden())
                                        <form method="POST"
                                              action="{{ route('presentations.links.override.clear', [$presentation, $link]) }}"
                                              class="mt-1">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="px-2 py-1 text-xs text-gray-500 hover:text-red-600"
                                                    onclick="return confirm('Clear this override?')">
                                                Clear Override
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="mt-4 pt-4 border-t border-slate-100">
        <form method="POST" action="{{ route('presentations.links.store', $presentation) }}" id="add-link-form" class="space-y-2.5">
            @csrf
            <div class="flex gap-2">
                <select name="type" id="link-type" class="pres-select text-xs">
                    <option value="property24">Property24</option>
                    <option value="lightstone">Lightstone</option>
                    <option value="active_listing">Active Listing</option>
                    <option value="competitor_listing">Competitor Listing</option>
                    <option value="market_article">Market Article</option>
                    <option value="other">Other</option>
                </select>
                <input type="url" name="url" id="link-url" placeholder="https://..." required
                       class="pres-input flex-1 min-w-0">
                <button type="button" id="open-link-btn"
                   class="corex-btn-outline text-xs py-1.5 px-2 shrink-0"
                   title="Open link in new tab"
                   onclick="var u=document.getElementById('link-url').value; if(u) window.open(u,'_blank','noopener,noreferrer');">↗</button>
            </div>
            <div class="flex gap-2">
                <input type="text" name="notes" placeholder="Notes (optional)"
                       class="pres-input flex-1">
                <button type="submit" id="add-link-btn"
                        class="corex-btn-primary text-xs shrink-0">
                    Add Link
                </button>
            </div>
            <p id="add-link-error" class="text-xs text-red-600 hidden"></p>
            <p id="add-link-success" class="text-xs text-[#00d4aa] hidden"></p>

            @error('url')
                <p class="text-xs text-red-600">{{ $message }}</p>
            @enderror
        </form>
        <script>
        (function () {
            var typeEl = document.getElementById('link-type');
            var urlEl  = document.getElementById('link-url');
            var openBtn = document.getElementById('open-link-btn');

            urlEl.addEventListener('input', function () {
                openBtn.href = urlEl.value || '#';
            });

            // ── AJAX Add Link ──────────────────────────────────────────────
            var form      = document.getElementById('add-link-form');
            var btn       = document.getElementById('add-link-btn');
            var errEl     = document.getElementById('add-link-error');
            var successEl = document.getElementById('add-link-success');
            var linksList = document.getElementById('links-list');
            var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            var linkTypeLabels = {
                'property24': 'Property24', 'lightstone': 'Lightstone',
                'active_listing': 'Active Listing', 'competitor_listing': 'Competitor',
                'market_article': 'Article', 'other': 'Other'
            };

            function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                errEl.classList.add('hidden');
                successEl.classList.add('hidden');

                btn.disabled = true;
                btn.textContent = 'Adding...';

                var formData = new FormData(form);
                var body = {};
                formData.forEach(function (v, k) { if (k !== '_token' && v !== '') body[k] = v; });

                fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(body)
                })
                .then(function (r) {
                    if (r.status === 422) {
                        return r.json().then(function (d) {
                            var msgs = [];
                            if (d.errors) {
                                Object.keys(d.errors).forEach(function (k) {
                                    msgs = msgs.concat(d.errors[k]);
                                });
                            }
                            errEl.textContent = msgs.join('; ') || 'Validation error';
                            errEl.classList.remove('hidden');
                            throw new Error('validation');
                        });
                    }
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function (data) {
                    if (!data.success) {
                        errEl.textContent = 'Server error adding link';
                        errEl.classList.remove('hidden');
                        return;
                    }

                    // Build new link row and insert into DOM
                    var link = data.link;
                    var typeColor = ['active_listing', 'competitor_listing'].indexOf(link.type) >= 0
                        ? 'bg-emerald-50 text-[#00d4aa]' : 'bg-slate-100 text-slate-500';
                    var extBadge = link.portal_capture_id
                        ? 'bg-emerald-50 text-[#00d4aa]'
                        : (link.extraction_status === 'ok' ? 'bg-emerald-50 text-[#00d4aa]' : (link.extraction_status === 'failed' ? 'bg-slate-100 text-slate-500' : 'bg-slate-50 text-slate-400'));
                    var extLabel = link.portal_capture_id
                        ? 'Captured'
                        : (link.extraction_status === 'ok' ? 'Extracted' : (link.extraction_status === 'failed' ? 'Failed' : 'Pending'));
                    var shortUrl = link.url.length > 50 ? link.url.substring(0, 50) + '...' : link.url;

                    var li = document.createElement('li');
                    li.className = 'pres-link-row text-xs';
                    li.setAttribute('data-link-id', link.id);
                    li.style.backgroundColor = '#eef2ff';
                    li.innerHTML = '<div class="flex items-start justify-between gap-2">'
                        + '<div class="min-w-0 flex items-center gap-1 flex-wrap">'
                        + '<span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium ' + typeColor + '">' + esc(linkTypeLabels[link.type] || link.type) + '</span>'
                        + '<span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium ' + extBadge + '" data-link-badge="' + link.id + '">' + extLabel + '</span>'
                        + '<a href="' + esc(link.url) + '" target="_blank" rel="noopener noreferrer" class="text-[#00d4aa] hover:underline break-all">' + esc(shortUrl) + '</a>'
                        + (link.notes ? '<span class="text-gray-400"> — ' + esc(link.notes) + '</span>' : '')
                        + '</div></div>';

                    if (linksList) {
                        linksList.appendChild(li);
                    } else {
                        // First link — create the list
                        var noLinks = form.parentElement.querySelector('p.italic');
                        if (noLinks) noLinks.remove();
                        var ul = document.createElement('ul');
                        ul.className = 'space-y-3 mb-4';
                        ul.id = 'links-list';
                        form.parentElement.insertBefore(ul, form);
                        ul.appendChild(li);
                        linksList = ul;
                    }

                    // Fade highlight
                    setTimeout(function () {
                        li.style.transition = 'background-color 2s';
                        li.style.backgroundColor = '';
                    }, 50);

                    // Clear form inputs, keep focus on URL input
                    urlEl.value = '';
                    openBtn.href = '#';
                    form.querySelector('[name="notes"]').value = '';

                    successEl.textContent = 'Link added.';
                    successEl.classList.remove('hidden');
                    setTimeout(function () { successEl.classList.add('hidden'); }, 3000);

                    urlEl.focus();
                })
                .catch(function (err) {
                    if (err.message !== 'validation') {
                        errEl.textContent = 'Failed to add link: ' + err.message;
                        errEl.classList.remove('hidden');
                    }
                })
                .finally(function () {
                    btn.disabled = false;
                    btn.textContent = 'Add Link';
                });
            });
        })();
        </script>
        </div>
    </div>
    </div>

    {{-- PORTAL CAPTURES — user-friendly display --}}
    @if(config('features.portal_extension_capture_v1'))
    <div class="ds-status-card mb-8" id="portal-captures">
        <div class="flex items-center justify-between mb-3">
            <h2 class="ds-section-header" style="margin-bottom:0">Portal Captures</h2>
            <div class="flex gap-2">
                <button type="button" id="reclassify-captures-btn"
                        class="corex-btn-outline text-xs"
                        title="Re-classify page types using server-side URL patterns">
                    Reclassify
                </button>
                <button type="button" id="refresh-captures-btn"
                        class="corex-btn-outline text-xs">
                    Refresh
                </button>
            </div>
        </div>
        <div>

        {{-- Summary line --}}
        <div id="captures-summary" class="mb-4 hidden">
            <div class="flex items-center gap-4 text-xs">
                <span id="captures-summary-listings" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-50 text-[#0f172a] font-semibold"></span>
                <span id="captures-summary-searches" class="text-slate-400 font-medium"></span>
            </div>
        </div>

        {{-- Captured Searches --}}
        <div id="captures-searches" class="hidden mb-5">
            <p class="text-[11px] font-semibold text-slate-400 mb-2.5 uppercase tracking-widest">Captured Searches</p>
            <div id="captures-searches-list" class="space-y-2"></div>
        </div>

        {{-- Captured Properties --}}
        <div id="captures-properties" class="hidden mb-5">
            <p class="text-[11px] font-semibold text-slate-400 mb-2.5 uppercase tracking-widest">Captured Properties</p>
            <div id="captures-properties-list" class="grid grid-cols-1 sm:grid-cols-2 gap-3"></div>
        </div>

        {{-- Unattached captures --}}
        <div id="captures-unattached" class="hidden mb-4">
            <p class="text-[11px] font-semibold text-slate-400 mb-2.5 uppercase tracking-widest">Unattached (your recent captures)</p>
            <div id="captures-unattached-list" class="space-y-2"></div>
        </div>

        {{-- Empty state --}}
        <div id="captures-empty">
            <p class="text-xs text-slate-400 italic">Loading captures...</p>
        </div>

        {{-- Technical details (admin, collapsed) --}}
        @if($isAdmin)
        <details class="mt-4 border-t border-slate-100 pt-3" id="captures-tech-details">
            <summary class="text-[11px] font-semibold text-slate-400 cursor-pointer hover:text-slate-600 select-none uppercase tracking-widest">
                Technical Details
            </summary>
            <div id="captures-tech-container" class="mt-2">
                <p class="text-xs text-gray-400 italic">Loading...</p>
            </div>
        </details>
        @endif

        @php
            $p24SuburbMap = collect(config('p24_suburbs'))
                ->pluck('slug', 'id')
                ->map(fn ($slug) => ucwords(str_replace('-', ' ', $slug)));
        @endphp
        <script>
        (function () {
            var presentationId = {{ $presentation->id }};
            var listUrl = '{{ route("presentations.portal-captures.index", $presentation) }}';
            var refreshBtn = document.getElementById('refresh-captures-btn');

            var summaryEl = document.getElementById('captures-summary');
            var summaryListingsEl = document.getElementById('captures-summary-listings');
            var summarySearchesEl = document.getElementById('captures-summary-searches');
            var searchesSection = document.getElementById('captures-searches');
            var searchesList = document.getElementById('captures-searches-list');
            var propertiesSection = document.getElementById('captures-properties');
            var propertiesList = document.getElementById('captures-properties-list');
            var unattachedSection = document.getElementById('captures-unattached');
            var unattachedList = document.getElementById('captures-unattached-list');
            var emptyEl = document.getElementById('captures-empty');
            var techContainer = document.getElementById('captures-tech-container');

            function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

            function formatPrice(p) {
                if (!p) return '';
                var n = parseInt(String(p).replace(/[^\d]/g, ''), 10);
                if (isNaN(n) || n === 0) return String(p);
                return 'R ' + n.toLocaleString('en-ZA');
            }

            function shortDate(iso) {
                if (!iso) return '';
                return iso.substring(0, 16).replace('T', ' ');
            }

            function formatShortPrice(v) {
                if (!v || v <= 0) return '';
                if (v >= 1000000) {
                    var m = (v / 1000000);
                    return 'R' + (m % 1 === 0 ? m.toFixed(0) : m.toFixed(1)) + 'M';
                }
                if (v >= 1000) return 'R' + Math.round(v / 1000) + 'K';
                return 'R' + v;
            }

            // P24 suburb ID → name lookup (from config/p24_suburbs.php)
            var p24SuburbMap = @json($p24SuburbMap);

            function extractSearchDescription(c) {
                var url = c.source_url || '';

                // Parse P24 advanced search URL for a friendly description
                if (url.indexOf('property24.com') !== -1 && url.indexOf('sp=') !== -1) {
                    try {
                        var urlObj = new URL(url);
                        var sp = urlObj.searchParams.get('sp');
                        if (sp) {
                            var spParams = new URLSearchParams(sp);
                            var parts = [];

                            // Suburb name from ID
                            var subId = spParams.get('s');
                            if (subId && p24SuburbMap[subId]) {
                                parts.push(p24SuburbMap[subId]);
                            }

                            // Property type from URL path
                            if (url.indexOf('/houses-for-sale') !== -1) parts.push('Houses');
                            else if (url.indexOf('/apartments-for-sale') !== -1) parts.push('Apartments');
                            else if (url.indexOf('/townhouses-for-sale') !== -1) parts.push('Townhouses');
                            else parts.push('Properties');

                            // Price range
                            var pf = spParams.get('pf');
                            var pt = spParams.get('pt');
                            if (pf || pt) {
                                var rangeStr = '';
                                rangeStr += pf ? formatShortPrice(parseInt(pf)) : 'Any';
                                rangeStr += ' \u2013 ';
                                rangeStr += pt ? formatShortPrice(parseInt(pt)) : 'Any';
                                parts.push(rangeStr);
                            }

                            // Beds
                            var bd = spParams.get('bd');
                            if (bd) parts.push(bd + '+ beds');

                            if (parts.length > 0) {
                                return parts.join(' | ');
                            }
                        }
                    } catch (e) { /* fall through to default */ }
                }

                // Fallback: use page title
                var title = c.page_title || '';
                var desc = title.replace(/\s*[-|–].*(Property24|PrivateProperty).*$/i, '').trim();
                if (desc.length > 80) desc = desc.substring(0, 77) + '...';
                return desc || c.source_site || 'Search';
            }

            function extractListingCount(c) {
                var ef = c.extracted_fields_json;
                if (ef && ef.search && ef.search.items_on_page) return ef.search.items_on_page;
                if (ef && ef.listing_urls_count) return ef.listing_urls_count;
                return null;
            }

            function extractPropertyFields(c) {
                var ef = c.extracted_fields_json || {};
                // Find a real property image (skip icons, logos, placeholders)
                var img = ef.image || null;
                if (!img && c.found_image_urls_json) {
                    for (var fi = 0; fi < c.found_image_urls_json.length; fi++) {
                        var u = c.found_image_urls_json[fi];
                        if (u && /\.(jpg|jpeg|webp|png)/i.test(u) &&
                            !/icon|logo|blank|sprite|NoImage/i.test(u) &&
                            u.length > 40) {
                            img = u;
                            break;
                        }
                    }
                }
                return {
                    name: ef.name || ef.title || ef.suburb || c.page_title || '',
                    price: ef.price || ef.asking_price || null,
                    address: ef.address || ef.suburb || '',
                    bedrooms: ef.bedrooms || ef.beds || null,
                    bathrooms: ef.bathrooms || ef.baths || null,
                    garages: ef.garages || ef.parking || null,
                    lotSize: ef.lot_size || ef.erf_m2 || ef.erf_size || null,
                    floorSize: ef.floor_size || ef.floor_m2 || null,
                    image: img,
                    listingId: ef.listing_id ? ('P24-' + ef.listing_id) : extractP24Id(c.source_url),
                    agentName: ef.agent_name || null,
                };
            }

            function extractP24Id(url) {
                if (!url) return null;
                var m = url.match(/\/(\d{6,})\/?(?:\?.*)?$/);
                return m ? 'P24-' + m[1] : null;
            }

            function buildSearchCard(c) {
                var desc = extractSearchDescription(c);
                var count = extractListingCount(c);
                var statusClass = c.parse_status === 'parsed' ? 'bg-emerald-50 text-[#00d4aa]' : 'bg-slate-100 text-slate-400';
                var statusLabel = c.parse_status === 'parsed' ? 'Parsed' : (c.parse_status || 'Pending');

                var html = '<div class="flex items-center gap-3 px-3 py-2.5 rounded-lg bg-slate-50 hover:bg-slate-100 transition-colors">';
                html += '<div class="shrink-0 w-8 h-8 rounded-lg bg-emerald-100 flex items-center justify-center">';
                html += '<svg class="w-4 h-4 text-[#00d4aa]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>';
                html += '</div>';
                html += '<div class="flex-1 min-w-0">';
                html += '<p class="text-xs font-semibold text-slate-700 truncate">' + esc(desc) + '</p>';
                html += '<div class="flex items-center gap-2 mt-0.5">';
                if (count !== null) {
                    html += '<span class="text-[11px] text-[#00d4aa] font-medium">' + count + ' properties found</span>';
                    html += '<span class="text-slate-300">·</span>';
                }
                html += '<span class="text-[11px] text-slate-400">' + shortDate(c.captured_at) + '</span>';
                html += '</div>';
                html += '</div>';
                html += '<div class="flex items-center gap-2 shrink-0">';
                html += '<span class="px-1.5 py-0.5 rounded text-[10px] font-medium ' + statusClass + '">' + esc(statusLabel) + '</span>';
                html += '<a href="' + esc(c.source_url) + '" target="_blank" class="text-[#00d4aa] hover:text-[#0f172a]" title="Open on portal">';
                html += '<svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>';
                html += '</a>';
                html += '<button type="button" onclick="deleteCapture(' + c.id + ')" class="text-slate-300 hover:text-red-500 transition-colors" title="Delete capture">';
                html += '<svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>';
                html += '</button>';
                html += '</div>';
                html += '</div>';
                return html;
            }

            function buildPropertyCard(c) {
                var f = extractPropertyFields(c);
                var statusClass = c.parse_status === 'parsed' ? 'bg-emerald-50 text-[#00d4aa]' : 'bg-slate-100 text-slate-400';
                var statusLabel = c.parse_status === 'parsed' ? 'Parsed' : (c.parse_status || 'Pending');
                var priceStr = formatPrice(f.price);
                var title = (f.name || '').replace(/\s*[-|–].*(Property24|PrivateProperty).*$/i, '').trim();
                if (title.length > 60) title = title.substring(0, 57) + '...';

                var html = '<div class="rounded-lg border border-slate-100 overflow-hidden hover:border-slate-200 transition-colors">';

                // Image + overlay (always show — placeholder if no image)
                html += '<div class="relative h-28 bg-slate-100 overflow-hidden">';
                if (f.image) {
                    html += '<img src="' + esc(f.image) + '" alt="" class="w-full h-full object-cover" loading="lazy" onerror="this.closest(\'.relative\').querySelector(\'.placeholder-icon\').classList.remove(\'hidden\');this.style.display=\'none\'">';
                    html += '<div class="placeholder-icon hidden absolute inset-0 flex items-center justify-center">';
                } else {
                    html += '<div class="placeholder-icon absolute inset-0 flex items-center justify-center">';
                }
                html += '<svg class="w-10 h-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>';
                html += '</div>';
                if (priceStr) {
                    html += '<div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/60 to-transparent px-2.5 py-1.5">';
                    html += '<span class="text-sm font-bold text-white">' + esc(priceStr) + '</span>';
                    html += '</div>';
                }
                html += '</div>';

                html += '<div class="px-3 py-2.5">';

                // Title / address
                html += '<p class="text-xs font-semibold text-slate-700 truncate" title="' + esc(title) + '">' + esc(title || 'Property') + '</p>';
                if (f.address && f.address !== title) {
                    html += '<p class="text-[11px] text-slate-400 truncate mt-0.5">' + esc(f.address) + '</p>';
                }

                // Stats row: beds · baths · garages · erf · floor
                var stats = [];
                if (f.bedrooms) stats.push(f.bedrooms + ' bed');
                if (f.bathrooms) stats.push(f.bathrooms + ' bath');
                if (f.garages) stats.push(f.garages + ' garage');
                if (f.lotSize) stats.push(f.lotSize + ' m\u00B2 erf');
                if (f.floorSize) stats.push(f.floorSize + ' m\u00B2 floor');
                if (stats.length > 0) {
                    html += '<p class="text-[11px] text-slate-500 mt-1">' + stats.join(' \u00B7 ') + '</p>';
                }
                if (f.agentName) {
                    html += '<p class="text-[10px] text-slate-400 mt-0.5">' + esc(f.agentName) + '</p>';
                }

                // Footer: listing ID, date, status, link
                html += '<div class="flex items-center justify-between mt-2 pt-1.5 border-t border-slate-50">';
                html += '<div class="flex items-center gap-2">';
                if (f.listingId) {
                    html += '<span class="text-[10px] text-slate-400 font-mono">' + esc(f.listingId) + '</span>';
                }
                html += '<span class="px-1.5 py-0.5 rounded text-[10px] font-medium ' + statusClass + '">' + esc(statusLabel) + '</span>';
                html += '</div>';
                html += '<div class="flex items-center gap-2">';
                html += '<a href="' + esc(c.source_url) + '" target="_blank" class="text-[#00d4aa] hover:text-[#0f172a]" title="View on portal">';
                html += '<svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>';
                html += '</a>';
                html += '<button type="button" onclick="deleteCapture(' + c.id + ')" class="text-slate-300 hover:text-red-500 transition-colors" title="Delete capture">';
                html += '<svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>';
                html += '</button>';
                html += '</div>';
                html += '</div>';
                html += '</div>'; // end px-3 py-2.5
                html += '</div>'; // end card
                return html;
            }

            function buildUnattachedRow(c) {
                var isSearch = c.page_type === 'search';
                var label = isSearch ? extractSearchDescription(c) : (c.extracted_fields_json && c.extracted_fields_json.name ? c.extracted_fields_json.name : c.page_title || c.source_url);
                if (label && label.length > 60) label = label.substring(0, 57) + '...';
                var typeBadge = isSearch
                    ? '<span class="px-1.5 py-0.5 rounded text-[10px] font-medium bg-emerald-50 text-[#00d4aa]">search</span>'
                    : '<span class="px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-50 text-amber-600">property</span>';

                var html = '<div class="flex items-center gap-3 px-3 py-2 rounded-lg bg-slate-50">';
                html += '<div class="flex-1 min-w-0">';
                html += '<p class="text-xs text-slate-600 truncate">' + esc(label) + '</p>';
                html += '<span class="text-[11px] text-slate-400">' + shortDate(c.captured_at) + '</span>';
                html += '</div>';
                html += '<div class="flex items-center gap-2 shrink-0">';
                html += typeBadge;
                html += '<button class="px-2.5 py-1 text-white rounded text-[11px] font-medium" style="background:var(--pres-brand)" onclick="attachCapture(' + c.id + ')">Attach</button>';
                html += '</div>';
                html += '</div>';
                return html;
            }

            function buildTechTable(items, showAttach) {
                var t = '<table class="w-full text-xs border-collapse">';
                t += '<thead><tr class="text-left text-gray-400 border-b">';
                t += '<th class="py-1 pr-2">Site</th><th class="py-1 pr-2">Type</th><th class="py-1 pr-2">URL</th><th class="py-1 pr-2">Status</th><th class="py-1 pr-2">Captured</th>';
                t += '<th class="py-1">Bytes</th>';
                t += '</tr></thead><tbody>';

                items.forEach(function (c) {
                    var shortUrl = (c.source_url || '').length > 45 ? c.source_url.substring(0, 45) + '...' : c.source_url;
                    var capturedAt = shortDate(c.captured_at);
                    var statusBadge = c.parse_status === 'parsed'
                        ? '<span class="px-1 py-0.5 rounded bg-emerald-50 text-[#00d4aa]" data-capture-status>parsed</span>'
                        : '<span class="px-1 py-0.5 rounded bg-slate-50 text-slate-400" data-capture-status>' + esc(c.parse_status || 'unknown') + '</span>';
                    t += '<tr class="border-b border-gray-50" data-capture-id="' + c.id + '">';
                    t += '<td class="py-1.5 pr-2 text-gray-600">' + esc(c.source_site || '') + '</td>';
                    t += '<td class="py-1.5 pr-2"><span class="px-1 py-0.5 rounded bg-emerald-50 text-[#00d4aa]">' + esc(c.page_type) + '</span></td>';
                    t += '<td class="py-1.5 pr-2"><a href="' + esc(c.source_url) + '" target="_blank" class="text-[#00d4aa] hover:underline">' + esc(shortUrl) + '</a></td>';
                    t += '<td class="py-1.5 pr-2">' + statusBadge + '</td>';
                    t += '<td class="py-1.5 pr-2 text-gray-500">' + capturedAt + '</td>';
                    t += '<td class="py-1.5 text-gray-500">' + (c.html_bytes ? Number(c.html_bytes).toLocaleString() + 'b' : '-') + '</td>';
                    t += '</tr>';
                });

                t += '</tbody></table>';
                return t;
            }

            function loadCaptures() {
                emptyEl.innerHTML = '<p class="text-xs text-gray-400 italic">Loading...</p>';
                emptyEl.classList.remove('hidden');

                fetch(listUrl, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var attached = data.attached || [];
                    var unattached = data.unattached || [];
                    var hasContent = false;

                    // Separate attached by page_type
                    var searches = [];
                    var properties = [];
                    attached.forEach(function (c) {
                        if (c.page_type === 'search') searches.push(c);
                        else properties.push(c);
                    });

                    // Summary line — count UNIQUE listings across all search captures
                    var totalListingsFound = 0;
                    var seenListingIds = {};
                    var bestTotalCount = null;
                    searches.forEach(function (c) {
                        var ef = c.extracted_fields_json;
                        // Collect unique listing IDs from extracted items
                        if (ef && ef.search && ef.search.items) {
                            ef.search.items.forEach(function (item) {
                                // Normalize: strip leading non-numeric chars (P prefix on sponsored copies)
                                var lid = String(item.portal_listing_id || '').replace(/^[^0-9]+/, '');
                                if (lid && !seenListingIds[lid]) {
                                    seenListingIds[lid] = true;
                                    totalListingsFound++;
                                }
                            });
                        }
                        // P24's reported total_count is the authoritative headline number
                        if (ef && ef.search && ef.search.total_count && ef.search.total_count > 0) {
                            if (bestTotalCount === null || ef.search.total_count > bestTotalCount) {
                                bestTotalCount = ef.search.total_count;
                            }
                        }
                    });
                    // Always prefer P24's total_count as the headline (it's the truth)
                    if (bestTotalCount !== null) {
                        totalListingsFound = bestTotalCount;
                    }

                    if (attached.length > 0) {
                        var listingLabel = bestTotalCount !== null
                            ? totalListingsFound + ' active listings (from P24 search)'
                            : totalListingsFound + ' unique listings from ' + searches.length + ' search capture' + (searches.length !== 1 ? 's' : '');
                        summaryListingsEl.textContent = listingLabel;
                        summarySearchesEl.textContent = properties.length + ' individual propert' + (properties.length !== 1 ? 'ies' : 'y') + ' captured';
                        summaryEl.classList.remove('hidden');
                    } else {
                        summaryEl.classList.add('hidden');
                    }

                    // Render search cards
                    if (searches.length > 0) {
                        searchesList.innerHTML = searches.map(buildSearchCard).join('');
                        searchesSection.classList.remove('hidden');
                        hasContent = true;
                    } else {
                        searchesSection.classList.add('hidden');
                    }

                    // Render property cards
                    if (properties.length > 0) {
                        propertiesList.innerHTML = properties.map(buildPropertyCard).join('');
                        propertiesSection.classList.remove('hidden');
                        hasContent = true;
                    } else {
                        propertiesSection.classList.add('hidden');
                    }

                    // Render unattached
                    if (unattached.length > 0) {
                        unattachedList.innerHTML = unattached.map(buildUnattachedRow).join('');
                        unattachedSection.classList.remove('hidden');
                        hasContent = true;
                    } else {
                        unattachedSection.classList.add('hidden');
                    }

                    // Empty state
                    if (hasContent) {
                        emptyEl.classList.add('hidden');
                    } else {
                        emptyEl.innerHTML = '<p class="text-xs text-slate-400 italic">No captures yet. Open a portal site and use the capture extension.</p>';
                        emptyEl.classList.remove('hidden');
                    }

                    // Technical details (admin only)
                    if (techContainer) {
                        var techHtml = '';
                        if (attached.length > 0) {
                            techHtml += '<p class="text-xs font-semibold text-gray-500 mb-1">Attached (' + attached.length + ')</p>';
                            techHtml += buildTechTable(attached, false);
                        }
                        if (unattached.length > 0) {
                            techHtml += '<p class="text-xs font-semibold text-gray-500 mt-3 mb-1">Unattached (' + unattached.length + ')</p>';
                            techHtml += buildTechTable(unattached, false);
                        }
                        techContainer.innerHTML = techHtml || '<p class="text-xs text-gray-400 italic">No raw capture data.</p>';
                    }
                })
                .catch(function () {
                    emptyEl.innerHTML = '<p class="text-xs text-red-500">Failed to load captures.</p>';
                    emptyEl.classList.remove('hidden');
                });
            }

            window.attachCapture = function (captureId) {
                var attachUrl = '/presentations/' + presentationId + '/portal-captures/' + captureId + '/attach';
                fetch(attachUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) loadCaptures();
                    else alert('Failed to attach capture');
                })
                .catch(function () { alert('Error attaching capture'); });
            };

            window.deleteCapture = function (captureId) {
                if (!confirm('Delete this capture? This cannot be undone.')) return;
                var deleteUrl = '/presentations/' + presentationId + '/portal-captures/' + captureId;
                fetch(deleteUrl, {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) loadCaptures();
                    else alert('Failed to delete capture');
                })
                .catch(function () { alert('Error deleting capture'); });
            };

            refreshBtn.addEventListener('click', loadCaptures);

            var reclassifyBtn = document.getElementById('reclassify-captures-btn');
            reclassifyBtn.addEventListener('click', function () {
                reclassifyBtn.disabled = true;
                reclassifyBtn.textContent = 'Reclassifying...';
                var reclassifyUrl = '/presentations/' + presentationId + '/portal-captures/reclassify';
                fetch(reclassifyUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    reclassifyBtn.disabled = false;
                    reclassifyBtn.textContent = 'Reclassify';
                    if (data.success) {
                        var msg = data.changed + ' capture(s) reclassified';
                        if (data.re_extracted > 0) msg += ', ' + data.re_extracted + ' re-extracted';
                        alert(msg);
                        loadCaptures();
                    } else {
                        alert('Reclassify failed');
                    }
                })
                .catch(function () {
                    reclassifyBtn.disabled = false;
                    reclassifyBtn.textContent = 'Reclassify';
                    alert('Error reclassifying captures');
                });
            });

            loadCaptures();
        })();
        </script>
        </div>
    </div>
    @endif

    {{-- DOCUMENT UPLOAD --}}
    <div class="ds-status-card mb-8" id="documents">
        <h2 class="ds-section-header mb-3">Documents</h2>
        <div>

        @php
            $docTypeLabels = [
                'suburb_stats'   => 'Suburb Report',
                'vicinity_sales' => 'Vicinity Sales Report',
                'cma'            => 'CMA Valuation Report',
                'market_article' => 'Market Article',
                'other'          => 'Other',
            ];
            $docTypeIcons = [
                'suburb_stats'   => '📊',
                'vicinity_sales' => '📍',
                'cma'            => '📋',
                'market_article' => '📰',
                'other'          => '📄',
                'unknown'        => '❓',
                'application/pdf' => '📄',
            ];

            // Upload status summary
            $uploadsByType = $presentation->uploads->groupBy('type');
            $requiredTypes = ['suburb_stats', 'vicinity_sales', 'cma'];
            $presentTypes = $uploadsByType->keys()->intersect($requiredTypes)->toArray();
            $missingTypes = array_diff($requiredTypes, $presentTypes);
            $totalUploads = $presentation->uploads->count();
        @endphp

        {{-- Upload status summary --}}
        @if($totalUploads > 0)
            <div class="mb-4 px-3 py-2 rounded-lg {{ empty($missingTypes) ? 'bg-emerald-50' : 'bg-slate-50' }}">
                <div class="flex items-center gap-2 text-xs">
                    @if(empty($missingTypes))
                        <span class="text-[#00d4aa] font-semibold">Documents: {{ count($presentTypes) }}/3 uploaded ✓</span>
                    @else
                        <span class="text-slate-600 font-semibold">Documents: {{ count($presentTypes) }}/3</span>
                        <span class="text-slate-400">— missing:
                            {{ implode(', ', array_map(fn($t) => $docTypeLabels[$t] ?? $t, $missingTypes)) }}
                        </span>
                    @endif
                </div>
            </div>
        @endif

        @if($presentation->uploads->isEmpty())
            <p class="text-xs text-slate-400 italic mb-3">No documents uploaded yet.</p>
        @else
            <ul class="space-y-3 mb-4 text-xs text-slate-600">
                @foreach($presentation->uploads as $upload)
                    <li class="pres-doc-row">
                        {{-- Row 1: File header --}}
                        @php
                            $uIcon = $docTypeIcons[$upload->type] ?? '📄';
                            $uTypeLabel = $docTypeLabels[$upload->type] ?? $upload->type;
                            $uIsKnownType = in_array($upload->type, ['suburb_stats', 'vicinity_sales', 'cma', 'market_article', 'other']);
                            $uExtStatus = $upload->extraction_status ?? 'pending';
                            $uExtBadge = match($uExtStatus) {
                                'ok'     => 'bg-emerald-50 text-[#00d4aa]',
                                'failed' => 'bg-red-50 text-red-600',
                                default  => 'bg-amber-50 text-amber-600',
                            };
                            $uExtLabel = match($uExtStatus) {
                                'ok'     => '✅ Extracted',
                                'failed' => '❌ Failed',
                                default  => '⏳ Processing',
                            };
                        @endphp
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-center gap-2 min-w-0 flex-wrap">
                                <span class="text-lg shrink-0 leading-none">{{ $uIcon }}</span>
                                <div class="min-w-0">
                                    <span class="font-semibold text-slate-700">{{ $uTypeLabel }}</span>
                                    <span class="text-slate-400 ml-1 truncate">{{ $upload->original_filename ?? basename($upload->file_path) }}</span>
                                </div>

                                <span class="pres-badge {{ $uExtBadge }}">
                                    {{ $uExtLabel }}
                                </span>

                                <form method="POST"
                                      action="{{ route('presentations.uploads.re-extract', [$presentation, $upload]) }}"
                                      class="inline">
                                    @csrf
                                    <button type="submit"
                                            class="inline-block px-1 py-0.5 text-xs text-[#00d4aa] hover:text-[#0f172a]"
                                            title="Re-run extraction">&#x27F3;</button>
                                </form>

                                <form method="POST"
                                      action="{{ route('presentations.uploads.destroy', [$presentation, $upload]) }}"
                                      class="inline"
                                      onsubmit="return confirm('Delete this document? Extracted data will be removed.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="inline-block px-1 py-0.5 text-xs text-red-400 hover:text-red-600"
                                            title="Delete document">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                    </button>
                                </form>

                                @if($upload->isOverridden())
                                    <span class="pres-badge pres-badge-warn">
                                        Override
                                    </span>
                                @endif
                            </div>
                            @if(!$uIsKnownType || $upload->type === 'other')
                                {{-- Unknown/other type: show prominent type selector --}}
                                <form method="POST"
                                      action="{{ route('presentations.uploads.update-type', [$presentation, $upload]) }}"
                                      class="flex items-center gap-1.5 shrink-0">
                                    @csrf
                                    @method('PATCH')
                                    <select name="type" class="pres-select text-xs border-amber-300">
                                        <option value="" disabled>Select type...</option>
                                        @foreach($docTypeLabels as $val => $label)
                                            <option value="{{ $val }}" {{ $upload->type === $val ? 'selected' : '' }}>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit"
                                            class="text-xs text-[#00d4aa] hover:text-[#0f172a] font-semibold">Save</button>
                                </form>
                            @else
                                {{-- Known type: small "Change type" toggle --}}
                                <details class="shrink-0">
                                    <summary class="text-[11px] text-slate-400 cursor-pointer hover:text-[#00d4aa]">Change type</summary>
                                    <form method="POST"
                                          action="{{ route('presentations.uploads.update-type', [$presentation, $upload]) }}"
                                          class="flex items-center gap-1.5 mt-1">
                                        @csrf
                                        @method('PATCH')
                                        <select name="type" class="pres-select text-xs">
                                            @foreach($docTypeLabels as $val => $label)
                                                <option value="{{ $val }}" {{ $upload->type === $val ? 'selected' : '' }}>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit"
                                                class="text-xs text-[#00d4aa] hover:text-[#0f172a] font-semibold">Save</button>
                                    </form>
                                </details>
                            @endif
                        </div>

                        {{-- Row 2: Extraction summary (doc_extract_v1 enhanced) --}}
                        @php
                            $uVerified  = $upload->getVerifiedData();
                            $uAgg       = $uVerified['aggregates'] ?? [];
                            $uCounts    = $uVerified['parsed_counts'] ?? [];
                            $uFields    = $uVerified['fields'] ?? [];
                            $hasDocExtract = !empty($uFields) && ($uVerified['extracted_version'] ?? '') === 'doc_extract_v1';
                        @endphp

                        @if($hasDocExtract && $upload->type === 'cma')
                            {{-- ── CMA Valuation Summary Card ── --}}
                            <div class="mt-2 bg-emerald-50 rounded-lg px-3 py-2 text-xs text-gray-700 space-y-1">
                                <div class="font-semibold text-[#0f172a]">CMA Valuation Summary</div>
                                @if(isset($uFields['cma.lower_range']) || isset($uFields['cma.middle_range']) || isset($uFields['cma.upper_range']))
                                    <div>
                                        <span class="text-gray-500">Price Range:</span>
                                        @if(isset($uFields['cma.lower_range'])) R{{ number_format((int)$uFields['cma.lower_range']) }} @endif
                                        @if(isset($uFields['cma.middle_range'])) &ndash; <span class="font-medium">R{{ number_format((int)$uFields['cma.middle_range']) }}</span> @endif
                                        @if(isset($uFields['cma.upper_range'])) &ndash; R{{ number_format((int)$uFields['cma.upper_range']) }} @endif
                                    </div>
                                    <div class="text-[10px] text-gray-400 -mt-0.5">Lower &ndash; Middle &ndash; Upper</div>
                                @endif
                                @if(isset($uFields['municipal.total_value']))
                                    <div>
                                        <span class="text-gray-500">Municipal:</span>
                                        R{{ number_format((int)$uFields['municipal.total_value']) }}
                                        @if(isset($uFields['municipal.valuation_year']))
                                            <span class="text-gray-400">({{ $uFields['municipal.valuation_year'] }})</span>
                                        @endif
                                    </div>
                                @endif
                                @if(isset($uFields['subject.address']))
                                    <div>{{ $uFields['subject.address'] }}@if(isset($uFields['subject.suburb'])), {{ $uFields['subject.suburb'] }}@endif</div>
                                @endif
                                @php
                                    $subjectParts = [];
                                    if (isset($uFields['subject.erf'])) $subjectParts[] = 'Erf ' . $uFields['subject.erf'];
                                    if (isset($uFields['subject.extent_m2'])) $subjectParts[] = number_format((int)$uFields['subject.extent_m2']) . ' m²';
                                @endphp
                                @if(!empty($subjectParts))
                                    <div class="text-gray-500">{{ implode(' | ', $subjectParts) }}</div>
                                @endif
                                @if(isset($uFields['subject.purchase_price']))
                                    <div class="text-gray-500">
                                        Purchased{{ isset($uFields['subject.purchase_date']) ? ': ' . $uFields['subject.purchase_date'] : '' }}
                                        for R{{ number_format((int)$uFields['subject.purchase_price']) }}
                                        @if(isset($uFields['subject.indexed_value']))
                                            | Indexed: R{{ number_format((int)$uFields['subject.indexed_value']) }}
                                        @endif
                                        @if(isset($uFields['subject.cagr']))
                                            | CAGR: {{ $uFields['subject.cagr'] }}%
                                        @endif
                                    </div>
                                @endif
                            </div>

                        @elseif($hasDocExtract && $upload->type === 'suburb_stats')
                            {{-- ── Suburb Sales Summary Card ── --}}
                            <div class="mt-2 bg-emerald-50 rounded-lg px-3 py-2 text-xs text-gray-700 space-y-1">
                                <div class="font-semibold text-[#0f172a]">
                                    Suburb Sales Summary
                                    @if(isset($uFields['suburb.latest_year']))
                                        <span class="font-normal text-gray-400">({{ $uFields['suburb.latest_year'] }})</span>
                                    @endif
                                </div>
                                @if(isset($uFields['suburb.latest_median_price']))
                                    <div>
                                        <span class="text-gray-500">Median:</span>
                                        <span class="font-medium">R{{ number_format((int)$uFields['suburb.latest_median_price']) }}</span>
                                        @if(isset($uFields['suburb.latest_sales_count']))
                                            | <span class="text-gray-500">Sales:</span> {{ $uFields['suburb.latest_sales_count'] }}
                                        @endif
                                    </div>
                                @endif
                                @if(isset($uFields['suburb.latest_low']) && isset($uFields['suburb.latest_high']))
                                    <div>
                                        <span class="text-gray-500">Range:</span>
                                        R{{ number_format((int)$uFields['suburb.latest_low']) }}
                                        &ndash; R{{ number_format((int)$uFields['suburb.latest_high']) }}
                                    </div>
                                @endif
                            </div>

                        @elseif($hasDocExtract && $upload->type === 'vicinity_sales')
                            {{-- ── Vicinity Sales Summary Card ── --}}
                            <div class="mt-2 bg-emerald-50 rounded-lg px-3 py-2 text-xs text-gray-700 space-y-1">
                                <div class="font-semibold text-[#0f172a]">Vicinity Sales Summary</div>
                                @if(isset($uFields['vicinity.lower_range']) || isset($uFields['vicinity.middle_range']) || isset($uFields['vicinity.upper_range']))
                                    <div>
                                        <span class="text-gray-500">Price Range:</span>
                                        @if(isset($uFields['vicinity.lower_range'])) R{{ number_format((int)$uFields['vicinity.lower_range']) }} @endif
                                        @if(isset($uFields['vicinity.middle_range'])) &ndash; <span class="font-medium">R{{ number_format((int)$uFields['vicinity.middle_range']) }}</span> @endif
                                        @if(isset($uFields['vicinity.upper_range'])) &ndash; R{{ number_format((int)$uFields['vicinity.upper_range']) }} @endif
                                    </div>
                                    <div class="text-[10px] text-gray-400 -mt-0.5">Lower &ndash; Middle &ndash; Upper</div>
                                @endif
                                @php
                                    $vicParts = [];
                                    if (isset($uFields['vicinity.average_price'])) $vicParts[] = 'Avg: R' . number_format((int)$uFields['vicinity.average_price']);
                                    if (isset($uFields['vicinity.avg_price_per_m2'])) $vicParts[] = 'Avg R/m²: R' . number_format((int)$uFields['vicinity.avg_price_per_m2']);
                                    if (isset($uFields['vicinity.comps_count'])) $vicParts[] = 'Comps: ' . $uFields['vicinity.comps_count'];
                                @endphp
                                @if(!empty($vicParts))
                                    <div>{{ implode(' | ', $vicParts) }}</div>
                                @endif
                            </div>

                        @elseif($uVerified && ($upload->type === 'suburb_stats') && !empty($uAgg))
                            {{-- Suburb Stats compact summary (legacy) --}}
                            @php
                                $uParts = [];
                                if (!empty($uAgg['active_listings_count'])) $uParts[] = 'Active: ' . $uAgg['active_listings_count'];
                                if (!empty($uAgg['median_price'])) $uParts[] = 'Median: R' . number_format($uAgg['median_price'], 0);
                                if (!empty($uAgg['average_price'])) $uParts[] = 'Avg: R' . number_format($uAgg['average_price'], 0);
                                if (!empty($uAgg['dom_p50'])) $uParts[] = 'DOM: ' . $uAgg['dom_p50'];
                                if (!empty($uAgg['months_of_inventory'])) $uParts[] = 'MOI: ' . $uAgg['months_of_inventory'];
                                if (!empty($uCounts['active_listings'])) $uParts[] = 'Rows: ' . $uCounts['active_listings'];
                            @endphp
                            <div class="mt-1.5 text-xs text-slate-600 bg-slate-50 rounded px-2 py-1">
                                {{ implode(' | ', $uParts) }}
                            </div>
                        @elseif($uVerified && ($upload->type === 'vicinity_sales') && !empty($uAgg))
                            {{-- Vicinity Sales compact summary (legacy) --}}
                            @php
                                $uParts = [];
                                if (!empty($uAgg['sold_count'])) $uParts[] = 'Sold: ' . $uAgg['sold_count'];
                                if (!empty($uAgg['median_price'])) $uParts[] = 'Median: R' . number_format($uAgg['median_price'], 0);
                                if (!empty($uAgg['average_price'])) $uParts[] = 'Avg: R' . number_format($uAgg['average_price'], 0);
                                if (!empty($uAgg['dom_p50'])) $uParts[] = 'DOM: ' . $uAgg['dom_p50'];
                                if (!empty($uAgg['price_range_low']) && !empty($uAgg['price_range_high'])) {
                                    $uParts[] = 'Range: R' . number_format($uAgg['price_range_low'], 0) . '–R' . number_format($uAgg['price_range_high'], 0);
                                }
                                if (!empty($uCounts['sold_comps'])) $uParts[] = 'Rows: ' . $uCounts['sold_comps'];
                            @endphp
                            <div class="mt-1.5 text-xs text-slate-600 bg-slate-50 rounded px-2 py-1">
                                {{ implode(' | ', $uParts) }}
                            </div>
                        @elseif($uVerified && ($upload->type === 'cma') && !empty($uVerified['suggested_band']))
                            {{-- CMA compact summary (legacy) --}}
                            @php
                                $band = $uVerified['suggested_band'];
                            @endphp
                            <div class="mt-1.5 text-xs text-slate-600 bg-slate-50 rounded px-2 py-1">
                                Band: R{{ number_format($band['low'], 0) }} – R{{ number_format($band['high'], 0) }}
                                @if(!empty($uVerified['notes']))
                                    @foreach($uVerified['notes'] as $note)
                                        | {{ str_replace('suggested_value:', 'Suggested: R', $note) }}
                                    @endforeach
                                @endif
                            </div>
                        @elseif($uVerified && !empty($uCounts))
                            {{-- Fallback: show parsed counts --}}
                            <div class="mt-1.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-gray-500">
                                @foreach($uCounts as $pcKey => $pcVal)
                                    <span>
                                        <span class="text-gray-400">{{ str_replace('_', ' ', $pcKey) }}:</span>
                                        {{ $pcVal }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                        @if($uExtStatus === 'failed')
                            <div class="mt-1.5 bg-red-50 border border-red-200 rounded px-2 py-1.5 text-xs text-red-700">
                                No data extracted — {{ $upload->extraction_error ?? 'check PDF format' }}
                            </div>
                        @endif

                        {{-- Override audit info --}}
                        @if($upload->isOverridden())
                            <p class="mt-1 text-xs text-slate-500">
                                Overridden {{ $upload->override_at ? $upload->override_at->format('Y-m-d H:i') : '' }}
                                @if($upload->override_by_user_id)
                                    by user #{{ $upload->override_by_user_id }}
                                @endif
                            </p>
                        @endif

                        {{-- Expand: details + diagnostics + override form --}}
                            <details class="mt-1.5">
                                <summary class="text-xs text-[#00d4aa] cursor-pointer hover:underline">
                                    {{ $upload->isOverridden() ? 'Edit override' : 'Details' }}
                                </summary>
                                <div class="mt-2 space-y-2">

                                    {{-- Extracted fields table (agent-friendly, no JSON) --}}
                                    @if($hasDocExtract)
                                        <div class="bg-white border border-gray-100 rounded p-2">
                                            <p class="text-xs font-medium text-gray-500 mb-1">Extracted Fields <span class="text-gray-300">({{ $uVerified['extracted_version'] ?? '' }})</span></p>
                                            <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-xs">
                                                @foreach($uFields as $fk => $fv)
                                                    <span class="text-gray-400">{{ $fk }}</span>
                                                    <span class="text-gray-700">
                                                        @if(is_numeric($fv) && (int)$fv >= 10000)
                                                            R{{ number_format((int)$fv) }}
                                                        @else
                                                            {{ $fv }}
                                                        @endif
                                                    </span>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                    {{-- Diagnostics (admin, collapsed) --}}
                                    <details class="text-xs">
                                        <summary class="text-gray-400 cursor-pointer hover:underline">Diagnostics</summary>
                                        <div class="mt-1 space-y-1">
                                            @if($upload->extraction_json)
                                                <div class="bg-gray-50 rounded p-2 font-mono text-gray-600 overflow-x-auto max-h-40 overflow-y-auto">
                                                    <pre>{{ json_encode($upload->extraction_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                                </div>
                                            @endif
                                            @if($upload->text_extracted)
                                                <div class="bg-gray-50 rounded p-2 font-mono text-gray-500 overflow-x-auto max-h-24 overflow-y-auto">
                                                    <pre>{{ Illuminate\Support\Str::limit($upload->text_extracted, 500) }}</pre>
                                                </div>
                                            @endif
                                        </div>
                                    </details>

                                    {{-- Override form --}}
                                    <form method="POST"
                                          action="{{ route('presentations.uploads.override', [$presentation, $upload]) }}"
                                          class="border border-slate-200 rounded p-2 bg-slate-50">
                                        @csrf
                                        @method('PATCH')
                                        <p class="text-xs font-medium text-slate-600 mb-1.5">Override values</p>
                                        @php
                                            $uOverrideSource = $upload->override_json ?? [];
                                            $uAggPrefill = $uVerified['aggregates'] ?? [];
                                            $uOverride = !empty($uOverrideSource) ? $uOverrideSource : $uAggPrefill;
                                            $uFieldDefs = match($upload->type) {
                                                'suburb_stats' => [
                                                    'active_listings_count' => 'Active listings',
                                                    'median_price' => 'Median price',
                                                    'average_price' => 'Average price',
                                                    'dom_p50' => 'DOM p50',
                                                    'months_of_inventory' => 'Months of inventory',
                                                ],
                                                'vicinity_sales' => [
                                                    'sold_count' => 'Sold count',
                                                    'median_price' => 'Median price',
                                                    'average_price' => 'Average price',
                                                    'dom_p50' => 'DOM p50',
                                                ],
                                                'cma' => [
                                                    'suggested_price_low' => 'Price low',
                                                    'suggested_price_high' => 'Price high',
                                                    'comps_count' => 'Comps count',
                                                ],
                                                default => [
                                                    'notes' => 'Notes',
                                                ],
                                            };
                                        @endphp
                                        <div class="grid grid-cols-2 gap-1.5">
                                            @foreach($uFieldDefs as $fKey => $fLabel)
                                                <div>
                                                    <label class="block text-xs text-gray-400">{{ $fLabel }}</label>
                                                    <input type="text" name="override_data[{{ $fKey }}]"
                                                           placeholder="{{ $fLabel }}"
                                                           value="{{ $uOverride[$fKey] ?? '' }}"
                                                           class="w-full border border-gray-200 rounded px-2 py-1 text-xs">
                                                </div>
                                            @endforeach
                                        </div>
                                        <div class="flex gap-2 mt-1.5">
                                            <button type="submit"
                                                    class="px-2 py-1 text-white text-xs rounded" style="background:var(--pres-brand)">
                                                Save Override
                                            </button>
                                        </div>
                                    </form>
                                    @if($upload->isOverridden())
                                        <form method="POST"
                                              action="{{ route('presentations.uploads.override.clear', [$presentation, $upload]) }}"
                                              class="mt-1">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="px-2 py-1 text-xs text-gray-500 hover:text-red-600"
                                                    onclick="return confirm('Clear this override?')">
                                                Clear Override
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </details>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="mt-4 pt-4 border-t border-slate-100">
        <form method="POST" action="{{ route('presentations.upload', $presentation) }}"
              enctype="multipart/form-data" class="space-y-2.5">
            @csrf
            <div class="flex gap-2 items-center">
                <select name="doc_type" class="pres-select text-xs" required>
                    <option value="auto" selected>Auto-detect (Recommended)</option>
                    @foreach($docTypeLabels as $val => $label)
                        <option value="{{ $val }}">{{ $label }}</option>
                    @endforeach
                </select>
                <input type="file" name="documents[]" multiple accept=".pdf"
                       class="pres-input flex-1 text-xs" required>
                <button type="submit"
                        class="corex-btn-outline text-xs shrink-0">
                    Upload
                </button>
            </div>
            <p class="text-[11px] text-slate-400">CMA Info PDFs are auto-detected by filename. Drop all 3 files at once — type is detected automatically.</p>
            @error('doc_type')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
            @error('documents')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
            @error('documents.*')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </form>

        </div>

        {{-- Document Library button (feature-flagged) --}}
        @if(config('features.document_library_v1'))
            <div class="mt-4 pt-4 border-t border-slate-100">
                <a href="{{ route('documents.library.index', ['presentation_id' => $presentation->id, 'return' => url()->current() . '#documents']) }}"
                   class="corex-btn-primary text-xs">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                    </svg>
                    Document Library
                </a>
            </div>

            {{-- Attached from Library --}}
            @php
                $libraryDocs = $presentation->documentLibraryItems()->with('uploader')->get();
            @endphp
            @if($libraryDocs->isNotEmpty())
                <div class="mt-4 pt-4 border-t border-slate-100">
                    <h3 class="text-[11px] font-semibold text-slate-400 uppercase tracking-widest mb-2.5">Attached from Library</h3>
                    <ul class="space-y-2 text-xs text-slate-600">
                        @foreach($libraryDocs as $libDoc)
                            <li class="pres-doc-row flex items-center justify-between">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="text-slate-400 shrink-0">&#128206;</span>
                                    <span class="truncate font-medium">{{ $libDoc->title ?? $libDoc->original_name }}</span>
                                    <span class="pres-badge bg-emerald-50 text-[#00d4aa]">
                                        {{ $libDoc->doc_type }}
                                    </span>
                                    <span class="text-slate-400">{{ $libDoc->uploader->name ?? '' }}</span>
                                    <span class="text-slate-400">{{ $libDoc->pivot->created_at ? \Carbon\Carbon::parse($libDoc->pivot->created_at)->format('d M Y') : '' }}</span>
                                </div>
                                <a href="{{ route('documents.library.download', $libDoc) }}"
                                   class="text-[#00d4aa] hover:text-[#0f172a] font-semibold shrink-0 ml-2">
                                    Download
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endif
    </div>
    </div>

{{-- ── MARKET NEWS & ARTICLES ─────────────────────────────────────────── --}}
@if(config('features.article_suggestions_v1'))
<div class="mb-8" id="articles">
    <div class="ds-status-card">
        <h2 class="ds-section-header mb-3">Market News &amp; Articles</h2>

        {{-- Part A — Added Articles --}}
        @if($addedArticles->isNotEmpty())
            <div class="mb-4">
                <h3 class="text-[11px] font-semibold text-slate-400 uppercase tracking-widest mb-2.5">Added to Presentation</h3>
                <ul class="space-y-3">
                    @foreach($addedArticles as $article)
                        <li class="bg-slate-50 rounded-lg p-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0 flex-1">
                                    <a href="{{ $article->url }}" target="_blank"
                                       class="text-sm font-semibold text-[#0f172a] hover:text-[#00d4aa] leading-tight">
                                        {{ $article->tags_json['title'] ?? Str::limit($article->url, 60) }}
                                    </a>
                                    <div class="text-[11px] text-slate-400 mt-0.5">
                                        {{ $article->tags_json['source'] ?? 'Unknown source' }}
                                        @if(!empty($article->tags_json['published_at']))
                                            &middot; {{ \Carbon\Carbon::parse($article->tags_json['published_at'])->format('d M Y') }}
                                        @endif
                                    </div>
                                    @if($article->ai_summary_text)
                                        <p class="text-xs text-slate-600 mt-1.5 leading-relaxed">
                                            {{ Str::limit($article->ai_summary_text, 250) }}
                                        </p>
                                    @endif
                                </div>
                                <form method="POST"
                                      action="{{ route('presentations.articles.remove', [$presentation, $article]) }}"
                                      onsubmit="return confirm('Remove this article?');"
                                      class="shrink-0">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="text-xs text-red-400 hover:text-red-600 font-medium">
                                        Remove
                                    </button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Part B — Suggested Articles --}}
        @if($suggestedArticles->isNotEmpty())
            <div class="@if($addedArticles->isNotEmpty()) pt-3 border-t border-slate-100 @endif">
                <h3 class="text-[11px] font-semibold text-slate-400 uppercase tracking-widest mb-2.5">
                    Suggested Articles
                    @if($presentation->suburb)
                        <span class="font-normal">&mdash; based on {{ $presentation->suburb }}{{ $presentation->property_type ? ', ' . $presentation->property_type : '' }}</span>
                    @endif
                </h3>
                <ul class="space-y-2">
                    @foreach($suggestedArticles as $poolArticle)
                        <li class="flex items-start justify-between gap-2 py-2 {{ !$loop->last ? 'border-b border-slate-50' : '' }}">
                            <div class="min-w-0 flex-1">
                                <a href="{{ $poolArticle->url }}" target="_blank"
                                   class="text-sm font-medium text-[#0f172a] hover:text-[#00d4aa] leading-tight">
                                    {{ $poolArticle->title }}
                                </a>
                                <div class="text-[11px] text-slate-400 mt-0.5">
                                    {{ $poolArticle->source }}
                                    @if($poolArticle->published_at)
                                        &middot; {{ $poolArticle->published_at->format('d M Y') }}
                                    @endif
                                </div>
                                @if($poolArticle->snippet)
                                    <p class="text-xs text-slate-500 mt-1 leading-relaxed">
                                        {{ Str::limit($poolArticle->snippet, 150) }}
                                    </p>
                                @endif
                            </div>
                            <form method="POST"
                                  action="{{ route('presentations.articles.add', $presentation) }}"
                                  class="shrink-0">
                                @csrf
                                <input type="hidden" name="article_pool_id" value="{{ $poolArticle->id }}">
                                <button type="submit"
                                        class="corex-btn-outline text-xs whitespace-nowrap">
                                    + Add
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </div>
        @elseif($addedArticles->isEmpty())
            <p class="text-xs text-slate-400">
                No matching articles found. Articles are updated daily from SA property news sources.
                Run <code class="bg-slate-100 px-1 rounded">php artisan articles:scrape</code> to populate.
            </p>
        @endif
    </div>
</div>
@endif

{{-- ── ASKING PRICE ─────────────────────────────────────────────────────── --}}
<div class="mb-8">
    <div class="ds-status-card">
        <h2 class="ds-section-header mb-3">Asking Price (ZAR)</h2>
        <div>
        <form method="POST" action="{{ route('presentations.holding-cost.update', $presentation) }}" class="space-y-4">
            @csrf
            @method('PATCH')
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                <div>
                    <label class="block text-xs text-slate-500 mb-1.5 font-medium">Asking Price (R)</label>
                    <input type="number" name="asking_price_inc" min="0" step="1"
                           value="{{ $presentation->asking_price_inc ?? '' }}"
                           placeholder="e.g. 2500000"
                           class="pres-input w-full">
                    <p class="mt-1 text-xs text-slate-400">Whole rands, no cents. Used by analysis and pack compilation.</p>
                </div>
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                        class="corex-btn-primary text-xs">
                    Save Asking Price
                </button>
                @if($presentation->asking_price_inc)
                    <span class="text-xs text-slate-500 font-medium bg-slate-50 px-2.5 py-1 rounded-lg">
                        Current: R {{ number_format($presentation->asking_price_inc) }}
                    </span>
                @endif
            </div>
        </form>
        </div>
    </div>
</div>

{{-- ── HOLDING COST INPUTS (P15) ─────────────────────────────────────────── --}}
<div class="mb-8" id="holding-costs">
    <div class="ds-status-card">
        <h2 class="ds-section-header mb-3">Holding Cost Inputs (monthly, ZAR)</h2>
        <div>
        <form method="POST" action="{{ route('presentations.holding-cost.update', $presentation) }}" class="space-y-4">
            @csrf
            @method('PATCH')

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                <div>
                    <label class="block text-xs text-slate-500 mb-1.5 font-medium">Bond payment</label>
                    <input type="number" name="monthly_bond" min="0" step="0.01"
                           value="{{ $presentation->monthly_bond ?? '' }}"
                           placeholder="0"
                           class="pres-input w-full">
                </div>
                <div>
                    <label class="block text-xs text-slate-500 mb-1.5 font-medium">Rates</label>
                    <input type="number" name="monthly_rates" min="0" step="0.01"
                           value="{{ $presentation->monthly_rates ?? '' }}"
                           placeholder="0"
                           class="pres-input w-full">
                </div>
                <div>
                    <label class="block text-xs text-slate-500 mb-1.5 font-medium">Levies</label>
                    <input type="number" name="monthly_levies" min="0" step="0.01"
                           value="{{ $presentation->monthly_levies ?? '' }}"
                           placeholder="0"
                           class="pres-input w-full">
                </div>
                <div>
                    <label class="block text-xs text-slate-500 mb-1.5 font-medium">Insurance</label>
                    <input type="number" name="monthly_insurance" min="0" step="0.01"
                           value="{{ $presentation->monthly_insurance ?? '' }}"
                           placeholder="0"
                           class="pres-input w-full">
                </div>
                <div>
                    <label class="block text-xs text-slate-500 mb-1.5 font-medium">Utilities</label>
                    <input type="number" name="monthly_utilities" min="0" step="0.01"
                           value="{{ $presentation->monthly_utilities ?? '' }}"
                           placeholder="0"
                           class="pres-input w-full">
                </div>
                <div>
                    <label class="block text-xs text-slate-500 mb-1.5 font-medium">Opportunity cost</label>
                    <input type="number" name="monthly_opportunity_cost" min="0" step="0.01"
                           value="{{ $presentation->monthly_opportunity_cost ?? '' }}"
                           placeholder="0"
                           class="pres-input w-full">
                </div>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                        class="corex-btn-primary text-xs">
                    Save Holding Cost
                </button>
                @php
                    $hcTotal = collect([
                        $presentation->monthly_bond,
                        $presentation->monthly_rates,
                        $presentation->monthly_levies,
                        $presentation->monthly_insurance,
                        $presentation->monthly_utilities,
                        $presentation->monthly_opportunity_cost,
                    ])->sum();
                @endphp
                @if($hcTotal > 0)
                    <span class="text-xs text-slate-500 font-medium bg-slate-50 px-2.5 py-1 rounded-lg">
                        Monthly total: R{{ number_format($hcTotal, 0) }}
                    </span>
                @endif
            </div>
        </form>
        </div>
    </div>
</div>

{{-- ── LIVE UPDATES POLLING (B1) ────────────────────────────────────────── --}}
@if(config('features.presentation_live_updates_v1') && config('features.portal_extension_capture_v1'))
{{-- New captures banner (fixed at top of captures section) --}}
<div id="live-new-captures-banner" class="hidden fixed bottom-4 right-4 z-50 px-4 py-2 bg-[#0f172a] text-white text-sm font-medium rounded-lg shadow-lg cursor-pointer hover:bg-[#1e293b] transition-colors"
     onclick="window.__liveUpdates && window.__liveUpdates.scrollToCaptures()">
    <span id="live-banner-text">0 new captures</span>
</div>

{{-- Live debug indicator (visible when window.PRESENTATIONS_LIVE_DEBUG = true) --}}
<div id="live-debug-indicator" class="hidden fixed top-2 right-2 z-50 bg-gray-900 text-green-400 text-xs font-mono rounded-lg shadow-lg px-3 py-2 max-w-xs opacity-90">
    <div>Live: <span id="ldi-status">OFF</span></div>
    <div>Last poll: <span id="ldi-poll-time">-</span></div>
    <div>HTTP: <span id="ldi-http-status">-</span></div>
    <div>New: <span id="ldi-new-captures">0</span> | Upd: <span id="ldi-updated-captures">0</span> | Links: <span id="ldi-updated-links">0</span></div>
    <div id="ldi-error" class="text-red-400 hidden"></div>
</div>

<script>
(function () {
    'use strict';

    // ── Config ──────────────────────────────────────────────────────────
    var POLL_ACTIVE_MS   = 2000;   // 2s when tab visible
    var POLL_HIDDEN_MS   = 10000;  // 10s when tab hidden
    var POLL_URL         = '{{ route("presentations.live-snapshot", $presentation) }}';
    var CSRF_TOKEN       = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    // ── State ───────────────────────────────────────────────────────────
    var lastCaptureId        = {{ $maxCaptureId }};
    var lastLinkUpdatedAt    = null;  // null → first polls omit cursor for wide catch-up
    var lastCaptureUpdatedAt = null;
    var pollCycleCount       = 0;     // tracks poll cycles; first 2 are "wide catch-up"
    var pollTimer            = null;
    var pendingNewCaptures   = 0;
    var isCapturesSectionVisible = false;

    // ── DOM refs ────────────────────────────────────────────────────────
    var capturesContainer = document.getElementById('captures-container');
    var banner            = document.getElementById('live-new-captures-banner');
    var bannerText        = document.getElementById('live-banner-text');

    // ── Helpers ─────────────────────────────────────────────────────────
    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function isCapturesInView() {
        if (!capturesContainer) return false;
        var rect = capturesContainer.getBoundingClientRect();
        return rect.top < window.innerHeight && rect.bottom > 0;
    }

    function showBanner(count) {
        pendingNewCaptures = count;
        if (count > 0 && !isCapturesInView()) {
            bannerText.textContent = count + ' new capture' + (count > 1 ? 's' : '');
            banner.classList.remove('hidden');
        } else {
            banner.classList.add('hidden');
            pendingNewCaptures = 0;
        }
    }

    function scrollToCaptures() {
        if (capturesContainer) {
            capturesContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        banner.classList.add('hidden');
        pendingNewCaptures = 0;
    }

    // ── In-place link badge update ──────────────────────────────────────
    function updateLinkBadge(linkData) {
        var badgeEl = document.querySelector('[data-link-badge="' + linkData.id + '"]');
        if (!badgeEl) return;

        if (linkData.portal_capture_id) {
            badgeEl.className = 'inline-block px-1.5 py-0.5 rounded text-xs font-medium bg-emerald-50 text-[#00d4aa]';
            badgeEl.textContent = 'Captured';
        } else {
            var statusMap = {
                'ok':      { cls: 'bg-emerald-50 text-[#00d4aa]', label: 'Extracted' },
                'failed':  { cls: 'bg-slate-100 text-slate-500',  label: 'Failed' },
                'pending': { cls: 'bg-slate-50 text-slate-400',   label: 'Pending' },
            };
            var st = statusMap[linkData.extraction_status] || statusMap['pending'];
            badgeEl.className = 'inline-block px-1.5 py-0.5 rounded text-xs font-medium ' + st.cls;
            badgeEl.textContent = st.label;
        }

        // Price change indicator
        if (linkData.price_change_indicator) {
            var priceEl = document.querySelector('[data-price-change="' + linkData.id + '"]');
            if (priceEl) {
                priceEl.classList.remove('hidden');
            }
        }
    }

    // ── In-place capture status update ─────────────────────────────────
    function updateCaptureRow(c) {
        var row = capturesContainer ? capturesContainer.querySelector('[data-capture-id="' + c.id + '"]') : null;
        if (!row) return;

        var statusEl = row.querySelector('[data-capture-status]');
        if (statusEl) {
            if (c.parse_status === 'parsed') {
                statusEl.className = 'px-1 py-0.5 rounded bg-emerald-50 text-[#00d4aa]';
                statusEl.textContent = 'parsed';
            } else {
                statusEl.className = 'px-1 py-0.5 rounded bg-slate-50 text-slate-400';
                statusEl.textContent = c.parse_status || 'unknown';
            }
        }

        // Flash highlight
        row.style.backgroundColor = '#fef9c3';
        setTimeout(function () {
            row.style.transition = 'background-color 2s';
            row.style.backgroundColor = '';
        }, 50);
    }

    // ── Capture card builder ────────────────────────────────────────────
    function buildCaptureRow(c) {
        var shortUrl = (c.source_url || '').length > 45
            ? c.source_url.substring(0, 45) + '...'
            : c.source_url;
        var capturedAt = c.captured_at ? c.captured_at.substring(0, 16).replace('T', ' ') : '';
        var statusBadge = c.parse_status === 'parsed'
            ? '<span class="px-1 py-0.5 rounded bg-emerald-50 text-[#00d4aa]" data-capture-status>parsed</span>'
            : '<span class="px-1 py-0.5 rounded bg-slate-50 text-slate-400" data-capture-status>' + esc(c.parse_status || 'unknown') + '</span>';

        var row = '<tr class="border-b border-gray-50 live-capture-new" data-capture-id="' + c.id + '">';
        row += '<td class="py-1.5 pr-2 text-gray-600">' + esc(c.source_site || '') + '</td>';
        row += '<td class="py-1.5 pr-2"><span class="px-1 py-0.5 rounded bg-emerald-50 text-[#00d4aa]">' + esc(c.page_type) + '</span></td>';
        row += '<td class="py-1.5 pr-2"><a href="' + esc(c.source_url) + '" target="_blank" class="text-[#00d4aa] hover:underline">' + esc(shortUrl) + '</a></td>';
        row += '<td class="py-1.5 pr-2">' + statusBadge + '</td>';
        row += '<td class="py-1.5 pr-2 text-gray-500">' + capturedAt + '</td>';
        row += '<td class="py-1.5 text-gray-500">' + (c.html_bytes ? Number(c.html_bytes).toLocaleString() + 'b' : '-') + '</td>';

        // Price change indicator
        if (c.price_change_count > 0) {
            row += '</tr><tr class="border-b border-gray-50"><td colspan="6"><div class="bg-amber-50 border border-amber-300 rounded px-2 py-1 text-xs text-amber-800 font-medium">';
            row += 'Price Change Detected — ' + c.price_change_count + ' listing' + (c.price_change_count > 1 ? 's' : '') + ' changed';
            row += '</div></td></tr>';
        } else {
            row += '</tr>';
        }

        return row;
    }

    // ── Inject new captures into existing table ─────────────────────────
    function injectCaptures(captures) {
        if (!captures || captures.length === 0) return;

        // Find the "Attached" table body
        var tbody = capturesContainer.querySelector('table tbody');
        if (!tbody) {
            // Captures section might not have loaded yet or is empty — trigger a full reload
            if (typeof window.loadCaptures === 'function') window.loadCaptures();
            return;
        }

        // Prepend rows (newest first, so reverse the array which came oldest-first)
        var reversed = captures.slice().reverse();
        for (var i = 0; i < reversed.length; i++) {
            var c = reversed[i];
            // Skip if already in DOM
            if (tbody.querySelector('[data-capture-id="' + c.id + '"]')) continue;

            var temp = document.createElement('template');
            temp.innerHTML = buildCaptureRow(c);
            var newRow = temp.content.firstChild;

            // Flash animation
            newRow.style.backgroundColor = '#eef2ff';
            tbody.insertBefore(newRow, tbody.firstChild);

            // Also insert price-change row if present
            if (temp.content.firstChild) {
                tbody.insertBefore(temp.content.firstChild, newRow.nextSibling);
            }

            // Fade out highlight
            setTimeout(function (el) {
                el.style.transition = 'background-color 2s';
                el.style.backgroundColor = '';
            }.bind(null, newRow), 50);
        }
    }

    // ── Debug indicator refs ────────────────────────────────────────────
    var debugPanel     = document.getElementById('live-debug-indicator');
    var ldiStatus      = document.getElementById('ldi-status');
    var ldiPollTime    = document.getElementById('ldi-poll-time');
    var ldiHttpStatus  = document.getElementById('ldi-http-status');
    var ldiNewCap      = document.getElementById('ldi-new-captures');
    var ldiUpdCap      = document.getElementById('ldi-updated-captures');
    var ldiUpdLinks    = document.getElementById('ldi-updated-links');
    var ldiError       = document.getElementById('ldi-error');
    var isFirstPoll    = true;

    function updateDebugPanel(httpStatus, data, error) {
        if (!window.PRESENTATIONS_LIVE_DEBUG) {
            if (debugPanel) debugPanel.classList.add('hidden');
            return;
        }
        if (debugPanel) debugPanel.classList.remove('hidden');
        ldiStatus.textContent = 'ON';
        ldiPollTime.textContent = new Date().toLocaleTimeString();
        ldiHttpStatus.textContent = httpStatus || '-';
        if (data) {
            ldiNewCap.textContent = (data.counts || {}).new_captures || 0;
            ldiUpdCap.textContent = (data.counts || {}).updated_captures || 0;
            ldiUpdLinks.textContent = (data.counts || {}).updated_links || 0;
        }
        if (error) {
            ldiError.textContent = error;
            ldiError.classList.remove('hidden');
        } else {
            ldiError.classList.add('hidden');
        }
    }

    // ── Poll ────────────────────────────────────────────────────────────
    function poll() {
        pollCycleCount++;

        // Build poll URL — omit cursor params during first 2 cycles (wide catch-up)
        var url = POLL_URL + '?after_capture_id=' + lastCaptureId;
        if (pollCycleCount > 2 && lastLinkUpdatedAt) {
            url += '&after_link_updated_at=' + encodeURIComponent(lastLinkUpdatedAt);
        }
        if (pollCycleCount > 2 && lastCaptureUpdatedAt) {
            url += '&after_capture_updated_at=' + encodeURIComponent(lastCaptureUpdatedAt);
        }

        // Include debug=1 on first poll if debug mode is on
        if (window.PRESENTATIONS_LIVE_DEBUG && isFirstPoll) {
            url += '&debug=1';
        }
        isFirstPoll = false;

        if (window.PRESENTATIONS_LIVE_DEBUG) {
            console.log('[LiveUpdates] poll #' + pollCycleCount, {
                url: url,
                cursors: {
                    lastCaptureId: lastCaptureId,
                    lastLinkUpdatedAt: lastLinkUpdatedAt,
                    lastCaptureUpdatedAt: lastCaptureUpdatedAt,
                },
                wideCatchUp: pollCycleCount <= 2,
            });
        }

        fetch(url, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        })
        .then(function (r) {
            var status = r.status;
            if (!r.ok) {
                console.error('[LiveUpdates] HTTP error', status);
                updateDebugPanel(status, null, 'HTTP ' + status);
                throw new Error('HTTP ' + status);
            }
            return r.json().then(function (d) { return { status: status, data: d }; });
        })
        .then(function (result) {
            var data = result.data;
            if (data.enabled === false) return;

            updateDebugPanel(result.status, data, null);

            // Update cursors from server response only
            if (data.latest_capture_id)          lastCaptureId        = data.latest_capture_id;
            if (data.latest_link_updated_at)     lastLinkUpdatedAt    = data.latest_link_updated_at;
            if (data.latest_capture_updated_at)  lastCaptureUpdatedAt = data.latest_capture_updated_at;

            // Debug logging
            if (window.PRESENTATIONS_LIVE_DEBUG) {
                console.log('[LiveUpdates] response', {
                    new_captures: (data.new_captures || []).length,
                    updated_captures: (data.updated_captures || []).length,
                    updated_links: (data.updated_links || []).length,
                    upd_link_ids: (data.updated_links || []).map(function(l) { return l.id; }),
                    latest_link_updated_at: data.latest_link_updated_at,
                    latest_capture_updated_at: data.latest_capture_updated_at,
                    debug: data.debug || null,
                });
            }

            // Inject new captures
            if (data.new_captures && data.new_captures.length > 0) {
                injectCaptures(data.new_captures);
                showBanner(pendingNewCaptures + data.new_captures.length);
            }

            // Update existing capture rows in-place
            if (data.updated_captures && data.updated_captures.length > 0) {
                data.updated_captures.forEach(updateCaptureRow);
            }

            // Update link badges in-place
            if (data.updated_links && data.updated_links.length > 0) {
                data.updated_links.forEach(updateLinkBadge);
            }

            schedulePoll();
        })
        .catch(function (err) {
            console.error('[LiveUpdates] Poll failed:', err.message);
            updateDebugPanel(null, null, err.message);
            // On error, back off and retry
            schedulePoll();
        });
    }

    function schedulePoll() {
        clearTimeout(pollTimer);
        var interval = document.hidden ? POLL_HIDDEN_MS : POLL_ACTIVE_MS;
        pollTimer = setTimeout(poll, interval);
    }

    // ── Visibility change ───────────────────────────────────────────────
    document.addEventListener('visibilitychange', function () {
        clearTimeout(pollTimer);
        if (!document.hidden) {
            // Returning to tab — poll immediately to catch up
            poll();
        } else {
            schedulePoll();
        }
    });

    // Scroll listener to auto-dismiss banner when captures section is visible
    window.addEventListener('scroll', function () {
        if (pendingNewCaptures > 0 && isCapturesInView()) {
            showBanner(0);
        }
    }, { passive: true });

    // ── Start ───────────────────────────────────────────────────────────
    schedulePoll();

    // Public API for banner click
    window.__liveUpdates = { scrollToCaptures: scrollToCaptures };

})();
</script>

@endif

{{-- Scroll & focus preservation for form submits --}}
<script>
(function () {
    'use strict';
    var STORAGE_KEY = 'pres_show_scroll_{{ $presentation->id }}';

    // On page load: restore scroll position (also respects URL hash fragments)
    try {
        if (window.location.hash) {
            // Browser will auto-scroll to the hash target — let it handle it
        } else {
            var saved = sessionStorage.getItem(STORAGE_KEY);
            if (saved) {
                sessionStorage.removeItem(STORAGE_KEY);
                var state = JSON.parse(saved);
                if (state.scrollY) {
                    window.scrollTo(0, state.scrollY);
                }
                if (state.focusId) {
                    var el = document.getElementById(state.focusId);
                    if (el) el.focus();
                } else if (state.focusName) {
                    var el2 = document.querySelector('[name="' + state.focusName + '"]');
                    if (el2) el2.focus();
                }
            }
        }
    } catch (e) { /* ignore */ }

    // Before form submit: save scroll + focus
    document.addEventListener('submit', function (e) {
        if (!e.target || e.target.tagName !== 'FORM') return;
        // Skip AJAX forms (those with fetch-based handlers)
        if (e.defaultPrevented) return;

        try {
            var focused = document.activeElement;
            var state = { scrollY: window.scrollY };
            if (focused && focused.id) {
                state.focusId = focused.id;
            } else if (focused && focused.name) {
                state.focusName = focused.name;
            }
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (ex) { /* ignore */ }
    });

    // Before link clicks that navigate to the same page: save scroll
    document.addEventListener('click', function (e) {
        var link = e.target.closest('a[href]');
        if (!link) return;
        var href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:') || link.target === '_blank') return;
        // Only save for same-page navigation (links back to this presentation)
        try {
            var linkUrl = new URL(href, window.location.origin);
            if (linkUrl.pathname === window.location.pathname) {
                sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ scrollY: window.scrollY }));
            }
        } catch (ex) { /* ignore */ }
    });
})();
</script>

</div>{{-- /.pres-page --}}

@endsection
