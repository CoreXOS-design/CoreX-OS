@extends('layouts.corex-app')

@section('title', 'Buyers Report — ' . $targetUser->name)

@section('corex-content')
@php
    // 2026-08-20 (Johan) — agent dedicated page (second pass). Authorized via
    // BuyersReportScopeResolver::canViewAgent() in the controller, NOT a plain
    // agency-membership check — see AT-366-D audit for why that distinction
    // matters. Reuses the shared Needs Attention/tiles/modal partials, plus a
    // richer per-buyer breakdown from BuyerActivityService::agentDetail()
    // (the same engine AT-366-E's ROI report agent page already uses).
    $m = $report['company'];
    $stateLabel = fn ($s) => match ($s) { 'warm' => 'Hot', 'new' => 'New', 'cold' => 'Cold', 'lost' => 'Lost', 'won' => 'Won', default => ucfirst((string) $s) };
    $money = fn ($v) => 'R ' . number_format((float) $v, 0);
    $drilldownBase = url('/corex/buyers-report/drilldown') . '?' . http_build_query([
        'period' => $preset, 'agent_id' => $targetUser->id, 'type' => $type,
    ]);
@endphp

<div class="max-w-6xl mx-auto px-4 py-6" x-data="buyersReport({ drilldownBase: @js($drilldownBase) })">
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <div>
            <a href="{{ route('buyers-report.index') }}" class="text-xs" style="color: var(--brand, #3b82f6);">&larr; Buyers Report</a>
            <h1 class="text-xl font-semibold mt-1" style="color: var(--text-primary);">{{ $targetUser->name }}</h1>
            <p class="text-xs mt-0.5" style="color: var(--text-muted);">{{ ucfirst(str_replace('_', ' ', $preset)) }}@if($type) · {{ $types[$type] }} only @endif</p>
        </div>
        <div class="flex items-end gap-3 flex-wrap">
            @include('buyers-report._type-selector', ['type' => $type, 'types' => $types])
            @include('performance.agency-report._period-selector', ['preset' => $preset, 'presets' => $presets, 'compareMode' => $compareMode, 'compareModes' => $compareModes])
        </div>
    </div>

    @include('buyers-report._tiles')

    @include('buyers-report._needs-attention')

    {{-- ══════ EVERY BUYER THIS AGENT HOLDS — full pipeline picture, not just cold/lost ══════ --}}
    <div class="rounded-md overflow-hidden mb-8" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
            <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Buyer book — {{ count($detail['buyers']) }} held</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="text-left px-3 py-2">Buyer</th>
                        <th class="text-left px-3 py-2">State</th>
                        <th class="text-right px-3 py-2">Days in state</th>
                        <th class="text-right px-3 py-2">Days in pipeline</th>
                        <th class="text-left px-3 py-2">Last worked</th>
                        <th class="text-right px-3 py-2">Appts (period)</th>
                        <th class="text-right px-3 py-2">Comms (period)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($detail['buyers'] as $b)
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td class="px-3 py-2 font-medium" style="color: var(--text-primary);">{{ $b['name'] }}</td>
                            <td class="px-3 py-2"><span class="ds-badge {{ in_array($b['state'], ['lost','cold']) ? 'ds-badge-warning' : 'ds-badge-default' }}">{{ $stateLabel($b['state']) }}</span></td>
                            <td class="px-3 py-2 text-right">{{ $b['days_in_state'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-right">{{ $b['days_in_pipeline'] ?? '—' }}</td>
                            <td class="px-3 py-2" style="color: var(--text-muted);">{{ $b['last_worked_at'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-right">{{ $b['appointments'] }}</td>
                            <td class="px-3 py-2 text-right">{{ $b['comms'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-6 text-center" style="color: var(--text-muted);">No buyers held.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if(!empty($detail['lost']))
    <div class="rounded-md overflow-hidden mb-8" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
            <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Lost this period</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="text-left px-3 py-2">Buyer</th>
                        <th class="text-left px-3 py-2">Reason</th>
                        <th class="text-right px-3 py-2">Value lost</th>
                        <th class="text-left px-3 py-2">Recorded</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($detail['lost'] as $l)
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td class="px-3 py-2 font-medium" style="color: var(--text-primary);">{{ $l['name'] }}</td>
                            <td class="px-3 py-2" style="color: var(--text-muted);">{{ $l['reason'] }}</td>
                            <td class="px-3 py-2 text-right">{{ $l['value'] > 0 ? $money($l['value']) : '—' }}</td>
                            <td class="px-3 py-2" style="color: var(--text-muted);">{{ $l['recorded_at'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @include('buyers-report._drilldown-modal')
</div>
@endsection
