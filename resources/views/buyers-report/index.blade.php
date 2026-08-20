@extends('layouts.corex-app')

@section('title', 'Buyers Report')

@section('corex-content')
@php
    // 2026-08-20 (Johan) — first pass: Needs Attention list + tiles + per-agent
    // table, real data throughout. Second pass (same day): tile-click
    // drill-down, agent/branch dedicated pages. Shared markup lives in
    // buyers-report/_needs-attention, _tiles, _drilldown-modal — reused
    // unchanged by the agent()/branch() pages so the three never drift.
    $m = $report['company'];
    $stateLabel = fn ($s) => match ($s) { 'warm' => 'Hot', 'new' => 'New', 'cold' => 'Cold', 'lost' => 'Lost', 'won' => 'Won', default => ucfirst((string) $s) };
    $money = fn ($v) => 'R ' . number_format((float) $v, 0);
    $drilldownBase = url('/corex/buyers-report/drilldown') . '?' . http_build_query([
        'scope' => $scope->level, 'branch_id' => $scope->branchId, 'user_id' => $scope->userId, 'period' => $preset, 'type' => $type,
    ]);
@endphp

<div class="max-w-6xl mx-auto px-4 py-6" x-data="buyersReport({ drilldownBase: @js($drilldownBase) })">
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <div>
            <h1 class="text-xl font-semibold" style="color: var(--text-primary);">Buyers Report</h1>
            <p class="text-xs mt-0.5" style="color: var(--text-muted);">
                {{ match($scope->level) { 'own' => 'Your buyers', 'branch' => 'Your branch', default => 'Whole agency' } }}
                · {{ ucfirst(str_replace('_', ' ', $preset)) }}
                @if($type) · {{ $types[$type] }} only @endif
            </p>
        </div>
        <div class="flex items-end gap-3 flex-wrap">
            @include('buyers-report._type-selector', ['type' => $type, 'types' => $types])
            @include('performance.agency-report._period-selector', ['preset' => $preset, 'presets' => $presets, 'compareMode' => $compareMode, 'compareModes' => $compareModes])
        </div>
    </div>

    @include('buyers-report._tiles')

    @include('buyers-report._needs-attention')

    @include('buyers-report._agent-table')

    @include('buyers-report._drilldown-modal')
</div>
@endsection
