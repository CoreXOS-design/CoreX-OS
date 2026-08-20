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
    $stateLabel = fn ($s) => match ($s) { 'warm' => 'Hot', 'new' => 'New', 'cold' => 'Cold', 'lost' => 'Lost', 'won' => 'Won', default => ucfirst((string) $s) };
    $money = fn ($v) => 'R ' . number_format((float) $v, 0);
    $drilldownBase = url('/corex/buyers-report/drilldown') . '?' . http_build_query([
        'scope' => 'agency', 'branch_id' => $scope->branchId, 'period' => $preset,
    ]);
@endphp

<div class="max-w-6xl mx-auto px-4 py-6" x-data="buyersReport({ drilldownBase: @js($drilldownBase) })">
    <div class="flex items-center justify-between mb-6">
        <div>
            <a href="{{ route('buyers-report.index') }}" class="text-xs" style="color: var(--brand, #3b82f6);">&larr; Buyers Report</a>
            <h1 class="text-xl font-semibold mt-1" style="color: var(--text-primary);">{{ $branchName }}</h1>
            <p class="text-xs mt-0.5" style="color: var(--text-muted);">{{ ucfirst(str_replace('_', ' ', $preset)) }}</p>
        </div>
        <form method="GET" class="flex items-center gap-2">
            <select name="period" onchange="this.form.submit()" class="text-xs rounded-md px-2 py-1.5" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                @foreach($presets as $p)
                    <option value="{{ $p }}" @selected($preset === $p)>{{ ucfirst(str_replace('_', ' ', $p)) }}</option>
                @endforeach
            </select>
        </form>
    </div>

    @include('buyers-report._needs-attention')

    @include('buyers-report._tiles')

    @include('buyers-report._agent-table')

    @include('buyers-report._drilldown-modal')
</div>
@endsection
