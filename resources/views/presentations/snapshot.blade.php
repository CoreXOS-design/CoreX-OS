@extends('layouts.corex')

@section('corex-content')

@php
    $summary = $snapshot->getOutputSummaryArray();
    $inputs  = $snapshot->getInputsArray();
@endphp

{{-- ══════════════════════════════════════════════════════════════════════════
     PAGE HEADER
══════════════════════════════════════════════════════════════════════════ --}}
<div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 mb-6">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Snapshot #{{ $snapshot->id }}</h2>
            <div class="text-sm text-white/60">
                Presentation #{{ $presentation->id }}
                &nbsp;·&nbsp; Saved {{ $snapshot->created_at->format('Y-m-d H:i') }}
                @if($snapshot->market_analytics_run_id)
                    &nbsp;·&nbsp; MA run #{{ $snapshot->market_analytics_run_id }}
                @endif
                @if($snapshot->sale_probability_run_id)
                    &nbsp;·&nbsp; SP run #{{ $snapshot->sale_probability_run_id }}
                @endif
            </div>
        </div>
        <a href="{{ route('presentations.index') }}"
           class="corex-btn-outline" style="color:#fff; border-color:rgba(255,255,255,0.3); background:transparent;">
            &larr; Back to Presentations
        </a>
    </div>
</div>

{{-- Flash messages handled by global toast system --}}

{{-- ══════════════════════════════════════════════════════════════════════════
     INPUTS USED
══════════════════════════════════════════════════════════════════════════ --}}
@if(!empty($inputs))
<div class="ds-status-card mb-6" style="border-left-color: var(--ds-cyan);">
    <h2 class="ds-section-header">Inputs (locked)</h2>
    <dl class="grid grid-cols-2 gap-x-8 gap-y-2 text-sm md:grid-cols-3 lg:grid-cols-4">
        @foreach([
            'suburb'        => 'Suburb',
            'type'          => 'Property Type',
            'period_months' => 'Period',
            'price'         => 'Asking Price',
            'size_m2'       => 'Floor Area (m²)',
            'bedrooms'      => 'Bedrooms',
            'branch_id'     => 'Branch ID',
        ] as $key => $label)
            @if(isset($inputs[$key]) && $inputs[$key] !== null && $inputs[$key] !== '')
            <div>
                <dt class="text-xs text-gray-400 mb-0.5">{{ $label }}</dt>
                <dd class="font-semibold text-gray-800">
                    @if($key === 'type')
                        {{ ucfirst($inputs[$key]) }}
                    @elseif($key === 'period_months')
                        {{ $inputs[$key] }} months
                    @elseif($key === 'price')
                        R{{ number_format($inputs[$key], 0) }}
                    @elseif($key === 'size_m2')
                        {{ $inputs[$key] }} m²
                    @else
                        {{ $inputs[$key] }}
                    @endif
                </dd>
            </div>
            @endif
        @endforeach
    </dl>
</div>
@endif

{{-- ══════════════════════════════════════════════════════════════════════════
     SALE PROBABILITY SUMMARY
══════════════════════════════════════════════════════════════════════════ --}}
<div class="bg-gradient-to-br from-[#0b2a4a] to-[#061a30] rounded-xl shadow-lg p-6 mb-6 text-white">
    <p class="text-sky-200 text-xs font-semibold uppercase tracking-widest mb-1">Snapshot — Seller Summary</p>
    <h2 class="text-xl font-bold mb-5">Sale Probability at Your Price</h2>

    @if(!empty($summary['skip_reason']))
        <div class="bg-white/10 border border-white/20 rounded-lg px-4 py-3 text-sm text-sky-100">
            <strong class="text-white">Insufficient data:</strong>
            {{ $summary['skip_reason'] }}
        </div>
    @else
        <div class="grid grid-cols-3 gap-3 mb-5">
            @foreach([
                ['label' => 'Sold in 30 days', 'key' => 'p30'],
                ['label' => 'Sold in 60 days', 'key' => 'p60'],
                ['label' => 'Sold in 90 days', 'key' => 'p90'],
            ] as $chip)
            <div class="bg-white/10 border border-white/20 rounded-lg p-4 text-center">
                <p class="text-sky-200 text-xs mb-1 font-medium">{{ $chip['label'] }}</p>
                <p class="text-3xl font-bold">
                    @if(isset($summary[$chip['key']]) && $summary[$chip['key']] !== null)
                        {{ number_format($summary[$chip['key']] * 100, 0) }}<span class="text-xl">%</span>
                    @else
                        <span class="text-base font-normal text-sky-300 italic">—</span>
                    @endif
                </p>
            </div>
            @endforeach
        </div>

        <div class="bg-white/10 border border-white/20 rounded-lg px-4 py-3 flex items-center justify-between">
            <span class="text-sky-200 text-sm">Estimated time to sell</span>
            @if(isset($summary['expected_days']) && $summary['expected_days'] !== null)
                <span class="text-white font-bold text-lg">{{ $summary['expected_days'] }} days</span>
            @else
                <span class="text-sky-300 text-sm italic">Insufficient data</span>
            @endif
        </div>
    @endif

    @if($maRun && $spRun)
    <p class="mt-4 text-sky-300 text-xs text-right font-mono">
        MA {{ $maRun->model_version }} · SP {{ $spRun->model_version }} · run #{{ $spRun->id }}
    </p>
    @endif
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     KEY MARKET METRICS
══════════════════════════════════════════════════════════════════════════ --}}
<div class="ds-status-card mb-6" style="border-left-color: var(--ds-cyan);">
    <h2 class="ds-section-header" style="margin-bottom:0.25rem;">Market Evidence (locked)</h2>
    <p class="text-xs text-gray-400 mb-4">Values frozen at snapshot time.</p>
    <dl class="grid grid-cols-2 gap-x-8 gap-y-3 text-sm md:grid-cols-3">
        <div>
            <dt class="text-xs text-gray-400 mb-0.5">Months of Inventory</dt>
            <dd class="font-semibold text-gray-800">
                @if(isset($summary['months_of_inventory']) && $summary['months_of_inventory'] !== null)
                    {{ number_format($summary['months_of_inventory'], 1) }} mo
                @else
                    <span class="text-gray-300">—</span>
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs text-gray-400 mb-0.5">Demand / Supply Ratio</dt>
            <dd class="font-semibold text-gray-800">
                @if(isset($summary['demand_supply_ratio']) && $summary['demand_supply_ratio'] !== null)
                    {{ number_format($summary['demand_supply_ratio'], 2) }}×
                @else
                    <span class="text-gray-300">—</span>
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs text-gray-400 mb-0.5">Price/m² vs Market</dt>
            <dd class="font-semibold text-gray-800">
                @if(isset($summary['price_per_sqm_deviation_pct']) && $summary['price_per_sqm_deviation_pct'] !== null)
                    {{ number_format($summary['price_per_sqm_deviation_pct'], 1) }}%
                @else
                    <span class="text-gray-300">—</span>
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs text-gray-400 mb-0.5">DOM Median (p50)</dt>
            <dd class="font-semibold text-gray-800">
                @if(isset($summary['dom_p50']) && $summary['dom_p50'] !== null)
                    {{ $summary['dom_p50'] }} days
                @else
                    <span class="text-gray-300">—</span>
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs text-gray-400 mb-0.5">DOM p75</dt>
            <dd class="font-semibold text-gray-800">
                @if(isset($summary['dom_p75']) && $summary['dom_p75'] !== null)
                    {{ $summary['dom_p75'] }} days
                @else
                    <span class="text-gray-300">—</span>
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs text-gray-400 mb-0.5">Elasticity (days/%)</dt>
            <dd class="font-semibold text-gray-800">
                @if(isset($summary['elasticity_days_per_pct']) && $summary['elasticity_days_per_pct'] !== null)
                    {{ number_format($summary['elasticity_days_per_pct'], 2) }}
                @else
                    <span class="text-gray-300">—</span>
                @endif
            </dd>
        </div>
    </dl>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     SENSITIVITY QUICK CARDS (locked)
══════════════════════════════════════════════════════════════════════════ --}}
@php
    $sensitivityDrops = [
        ['label' => 'Drop R50,000',  'key' => 'sensitivity_drop_50k'],
        ['label' => 'Drop R100,000', 'key' => 'sensitivity_drop_100k'],
        ['label' => 'Drop R150,000', 'key' => 'sensitivity_drop_150k'],
    ];
    $hasAnySensitivity = collect($sensitivityDrops)->contains(fn($d) => !empty($summary[$d['key']]));
@endphp
@if($hasAnySensitivity)
<div class="ds-status-card mb-6" style="border-left-color: var(--ds-cyan);">
    <h2 class="ds-section-header" style="margin-bottom:0.25rem;">Price Sensitivity (locked)</h2>
    <p class="text-xs text-gray-400 mb-4">Quick scenario cards frozen at snapshot time.</p>
    <div class="grid grid-cols-3 gap-3">
        @foreach($sensitivityDrops as $drop)
            @php
                $row    = $summary[$drop['key']] ?? null;
                $baseP60 = $summary['p60'] ?? null;
            @endphp
            <div class="border border-gray-200 rounded-lg p-4">
                <p class="text-xs text-gray-500 font-medium mb-2">{{ $drop['label'] }}</p>
                @if($row && $row['p60'] !== null)
                    <p class="text-2xl font-bold text-gray-800 mb-1">
                        {{ number_format($row['p60'] * 100, 0) }}<span class="text-base">%</span>
                        <span class="text-sm text-gray-400 font-normal ml-1">p60</span>
                    </p>
                    @if($baseP60 !== null)
                        @php $delta = ($row['p60'] - $baseP60) * 100; @endphp
                        <p class="text-xs {{ $delta >= 0 ? 'text-green-600' : 'text-red-500' }} font-medium">
                            @if($delta >= 0)+@endif{{ number_format($delta, 1) }} pp vs base
                        </p>
                    @endif
                    @if(isset($row['expected_days']) && $row['expected_days'] !== null && isset($summary['expected_days']) && $summary['expected_days'] !== null)
                        @php $daysDelta = $row['expected_days'] - $summary['expected_days']; @endphp
                        <p class="text-xs {{ $daysDelta <= 0 ? 'text-green-600' : 'text-red-500' }} mt-0.5">
                            @if($daysDelta < 0)
                                {{ $daysDelta }} days faster
                            @elseif($daysDelta > 0)
                                +{{ $daysDelta }} days slower
                            @else
                                No change in days
                            @endif
                        </p>
                    @endif
                @else
                    <p class="text-sm text-gray-400 italic">—</p>
                @endif
            </div>
        @endforeach
    </div>
</div>
@endif

{{-- ══════════════════════════════════════════════════════════════════════════
     AUDIT MODE
══════════════════════════════════════════════════════════════════════════ --}}
<div class="mb-2">
    <h2 class="text-xs font-semibold text-gray-400 uppercase tracking-widest">Audit Mode</h2>
</div>

@if($maRun)
<details class="mb-3">
    <summary class="cursor-pointer text-sm text-gray-500 hover:text-gray-700 select-none">
        Market Analytics breakdown (MA run #{{ $maRun->id }} · {{ $maRun->model_version }})
    </summary>
    <div class="mt-2 bg-gray-50 border border-gray-200 rounded p-4 overflow-x-auto">
        <pre class="text-xs text-gray-700 whitespace-pre-wrap">{{ json_encode($maRun->breakdown_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
</details>
@endif

@if($spRun)
<details class="mb-3">
    <summary class="cursor-pointer text-sm text-gray-500 hover:text-gray-700 select-none">
        Sale Probability breakdown (SP run #{{ $spRun->id }} · {{ $spRun->model_version }})
    </summary>
    <div class="mt-2 bg-gray-50 border border-gray-200 rounded p-4 overflow-x-auto">
        <pre class="text-xs text-gray-700 whitespace-pre-wrap">{{ json_encode($spRun->breakdown_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
</details>
@endif

<details class="mb-8">
    <summary class="cursor-pointer text-sm text-gray-500 hover:text-gray-700 select-none">
        Snapshot output_summary_json (raw)
    </summary>
    <div class="mt-2 bg-gray-50 border border-gray-200 rounded p-4 overflow-x-auto">
        <pre class="text-xs text-gray-700 whitespace-pre-wrap">{{ json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
</details>

@endsection
