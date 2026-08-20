@extends('layouts.corex-app')

@section('title', 'Buyers Report — ' . $branchName)

@section('corex-content')
@php
    // 2026-08-20 (Johan) — branch dedicated page (second pass). Authorized via
    // BuyersReportScopeResolver::canViewBranch() in the controller, NOT a plain
    // agency-membership check — see AT-366-D audit for why that distinction
    // matters. Reuses the exact same partials as index.blade.php so the two
    // never drift.
    $m = $report['company'];
    $stateLabel = fn ($s) => match ($s) { 'warm' => 'Warm', 'new' => 'New', 'cold' => 'Cold', 'lost' => 'Lost', 'won' => 'Won', default => ucfirst((string) $s) };
    $money = fn ($v) => 'R ' . number_format((float) $v, 0);
    $drilldownBase = url('/corex/buyers-report/drilldown') . '?' . http_build_query([
        'scope' => 'agency', 'branch_id' => $scope->branchId, 'period' => $preset, 'type' => $type,
    ]);
@endphp

<div class="max-w-6xl mx-auto px-4 py-6" x-data="buyersReport({ drilldownBase: @js($drilldownBase) })">
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <div>
            <a href="{{ route('buyers-report.index') }}" class="text-xs" style="color: var(--brand, #3b82f6);">&larr; Buyers Report</a>
            <h1 class="text-xl font-semibold mt-1" style="color: var(--text-primary);">{{ $branchName }}</h1>
            <p class="text-xs mt-0.5" style="color: var(--text-muted);">{{ ucfirst(str_replace('_', ' ', $preset)) }}@if($type) · {{ $types[$type] }} only @endif</p>
        </div>
        <div class="flex items-end gap-3 flex-wrap">
            @include('buyers-report._type-selector', ['type' => $type, 'types' => $types])
            @include('performance.agency-report._period-selector', ['preset' => $preset, 'presets' => $presets, 'compareMode' => $compareMode, 'compareModes' => $compareModes])
        </div>
    </div>

    <h2 class="text-base font-semibold mb-3" style="color: var(--text-primary);">What happened to buyers</h2>
    @include('buyers-report._tiles')

    @include('buyers-report._needs-attention')

    @include('buyers-report._agent-table')

    @include('buyers-report._pipeline-states')

    @include('buyers-report._demand-analysis')

    @include('buyers-report._drilldown-modal')
</div>
@endsection
