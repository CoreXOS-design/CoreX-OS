@extends('layouts.nexus')

@section('nexus-content')

{{-- ══════════════════════════════════════════════════════════════════════════
     PAGE HEADER
══════════════════════════════════════════════════════════════════════════ --}}
<div class="mb-6 flex items-start justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">Market Analysis Results</h1>
        <p class="text-sm text-gray-500 mt-1">
            Presentation #{{ $presentation->id }}
            &nbsp;·&nbsp; MA run #{{ $maRun->id }}
            &nbsp;·&nbsp; SP run #{{ $spRun->id }}
        </p>
    </div>
    <a href="{{ route('presentations.index') }}"
       class="text-xs text-indigo-600 hover:underline mt-1">← Back to Presentations</a>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     INPUTS FORM (re-run)
══════════════════════════════════════════════════════════════════════════ --}}
<div class="bg-white rounded-xl shadow p-6 mb-6">
    <h2 class="text-base font-semibold text-gray-700 mb-3">Inputs</h2>
    <form method="POST" action="{{ route('presentations.compute', $presentation) }}">
        @csrf
        <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
            <div>
                <label class="block text-xs text-gray-600 mb-1">Suburb <span class="text-red-500">*</span></label>
                <input type="text" name="suburb" value="{{ $inputs['suburb'] }}" required
                       class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
                @error('suburb')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Property Type <span class="text-red-500">*</span></label>
                <select name="type" required class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
                    @foreach(['house','unit','land','other'] as $t)
                        <option value="{{ $t }}" {{ ($inputs['type'] ?? '') === $t ? 'selected' : '' }}>{{ ucfirst($t) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Period <span class="text-red-500">*</span></label>
                <select name="period_months" required class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
                    @foreach([6,12,24] as $m)
                        <option value="{{ $m }}" {{ ($inputs['period_months'] ?? 12) == $m ? 'selected' : '' }}>{{ $m }} months</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Price (R)</label>
                <input type="number" name="price" value="{{ $inputs['price'] ?? '' }}" step="1" min="0"
                       class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Floor Area (m²)</label>
                <input type="number" name="size_m2" value="{{ $inputs['size_m2'] ?? '' }}" min="0"
                       class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Bedrooms</label>
                <input type="number" name="bedrooms" value="{{ $inputs['bedrooms'] ?? '' }}" min="0" max="20"
                       class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Branch</label>
                <select name="branch_id" class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
                    <option value="">— Any —</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" {{ ($inputs['branch_id'] ?? null) == $branch->id ? 'selected' : '' }}>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="mt-4">
            <button type="submit"
                    class="px-5 py-2 bg-indigo-600 text-white text-sm font-medium rounded hover:bg-indigo-700">
                Re-run Analysis
            </button>
        </div>
    </form>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     SELLER SUMMARY HERO — "Sale Probability at Your Price"
══════════════════════════════════════════════════════════════════════════ --}}
<div class="bg-gradient-to-br from-indigo-700 to-indigo-900 rounded-xl shadow-lg p-6 mb-6 text-white">
    <p class="text-indigo-200 text-xs font-semibold uppercase tracking-widest mb-1">Seller Summary</p>
    <h2 class="text-xl font-bold mb-5">Sale Probability at Your Price</h2>

    @if($spResult->skipReason)
        <div class="bg-white/10 border border-white/20 rounded-lg px-4 py-3 text-sm text-indigo-100">
            <strong class="text-white">Insufficient data:</strong>
            {{ $spResult->skipReason }}
        </div>
    @else
        {{-- Probability chips --}}
        <div class="grid grid-cols-3 gap-3 mb-5">
            {{-- 30 days --}}
            <div class="bg-white/10 border border-white/20 rounded-lg p-4 text-center">
                <p class="text-indigo-200 text-xs mb-1 font-medium">Sold in 30 days</p>
                <p class="text-3xl font-bold">
                    @if($spResult->p30 !== null)
                        {{ number_format($spResult->p30 * 100, 0) }}<span class="text-xl">%</span>
                    @else
                        <span class="text-base font-normal text-indigo-300 italic">—</span>
                    @endif
                </p>
            </div>
            {{-- 60 days --}}
            <div class="bg-white/15 border border-white/30 rounded-lg p-4 text-center ring-1 ring-white/30">
                <p class="text-indigo-200 text-xs mb-1 font-medium">Sold in 60 days</p>
                <p class="text-3xl font-bold">
                    @if($spResult->p60 !== null)
                        {{ number_format($spResult->p60 * 100, 0) }}<span class="text-xl">%</span>
                    @else
                        <span class="text-base font-normal text-indigo-300 italic">—</span>
                    @endif
                </p>
            </div>
            {{-- 90 days --}}
            <div class="bg-white/10 border border-white/20 rounded-lg p-4 text-center">
                <p class="text-indigo-200 text-xs mb-1 font-medium">Sold in 90 days</p>
                <p class="text-3xl font-bold">
                    @if($spResult->p90 !== null)
                        {{ number_format($spResult->p90 * 100, 0) }}<span class="text-xl">%</span>
                    @else
                        <span class="text-base font-normal text-indigo-300 italic">—</span>
                    @endif
                </p>
            </div>
        </div>

        {{-- Expected days banner --}}
        <div class="bg-white/10 border border-white/20 rounded-lg px-4 py-3 flex items-center justify-between">
            <span class="text-indigo-200 text-sm">Estimated time to sell</span>
            @if($spResult->expectedDays !== null)
                <span class="text-white font-bold text-lg">{{ $spResult->expectedDays }} days</span>
            @else
                <span class="text-indigo-300 text-sm italic">
                    {{ $spResult->skipReason ?? 'Insufficient data' }}
                </span>
            @endif
        </div>
    @endif

    {{-- Version trace --}}
    <p class="mt-4 text-indigo-300 text-xs text-right font-mono">
        MA {{ $maRun->model_version }} · SP {{ $spRun->model_version }} · run #{{ $spRun->id }}
    </p>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     WHAT'S DRIVING THIS
══════════════════════════════════════════════════════════════════════════ --}}
@php
    $signalLabels = [
        'price'      => 'Price Position',
        'absorption' => 'Months of Inventory',
        'pressure'   => 'Demand vs Supply',
        'dom'        => 'Market DOM',
        'elasticity' => 'Elasticity',
    ];

    $rawSignals = $spRun->breakdown_json['signals'] ?? [];
    $activeSignals = array_filter(
        $rawSignals,
        fn($s) => !($s['skip'] ?? true) && isset($s['contribution']) && $s['contribution'] !== null
    );
    uasort($activeSignals, fn($a, $b) => abs($b['contribution']) <=> abs($a['contribution']));
    $topSignals = array_slice($activeSignals, 0, 3, true);
@endphp

<div class="bg-white rounded-xl shadow p-6 mb-6">
    <h2 class="text-base font-semibold text-gray-800 mb-1">What's Driving This</h2>
    <p class="text-xs text-gray-400 mb-4">Top 3 signals by influence on the probability score.</p>

    @if(empty($topSignals))
        <div class="py-6 text-center">
            <p class="text-sm text-gray-400 italic">Not enough market evidence yet.</p>
        </div>
    @else
        <div class="space-y-4">
            @foreach($topSignals as $name => $signal)
                @php
                    $label = $signalLabels[$name] ?? ucfirst($name);
                    $raw   = $signal['raw'] ?? null;
                    $contribPct = round($signal['contribution'] * 100, 1);
                    $barWidth   = min(100, round($signal['contribution'] * 300));

                    // Static interpretation strings — no derived formulas
                    if ($name === 'price') {
                        if ($raw === null)        $interp = 'No price data';
                        elseif ($raw <= -10)      $interp = 'Well below market avg — buyer advantage';
                        elseif ($raw < 0)         $interp = 'Slightly below market avg';
                        elseif ($raw === 0.0)     $interp = 'At market price';
                        elseif ($raw <= 10)       $interp = 'Slightly above market avg';
                        else                      $interp = 'Above market price';
                    } elseif ($name === 'absorption') {
                        if ($raw === null)        $interp = 'No inventory data';
                        elseif ($raw <= 2)        $interp = "Seller's market — low inventory";
                        elseif ($raw <= 4)        $interp = 'Balanced market';
                        else                      $interp = "Buyer's market — high inventory";
                    } elseif ($name === 'pressure') {
                        if ($raw === null)        $interp = 'No demand data';
                        elseif ($raw > 1.2)       $interp = 'More buyers than available stock';
                        elseif ($raw < 0.8)       $interp = 'More stock than active buyers';
                        else                      $interp = 'Balanced demand and supply';
                    } elseif ($name === 'dom') {
                        if ($raw === null)        $interp = 'No DOM data';
                        elseif ($raw <= 30)       $interp = 'Fast-moving market';
                        elseif ($raw <= 60)       $interp = 'Moderate market pace';
                        else                      $interp = 'Slow-moving market';
                    } elseif ($name === 'elasticity') {
                        if ($raw === null)        $interp = 'No elasticity data';
                        elseif ($raw < -1)        $interp = 'Price reductions accelerate sales';
                        elseif ($raw > 1)         $interp = 'Market is price-inelastic';
                        else                      $interp = 'Moderate price sensitivity';
                    } else {
                        $interp = 'See breakdown for detail';
                    }
                @endphp
                <div class="flex items-start gap-4">
                    {{-- Rank circle --}}
                    <div class="shrink-0 w-7 h-7 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center text-xs font-bold">
                        {{ $loop->iteration }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-sm font-medium text-gray-800">{{ $label }}</span>
                            <span class="text-xs font-semibold text-indigo-700 ml-2 shrink-0">{{ $contribPct }}%</span>
                        </div>
                        <div class="flex items-center gap-2 mb-1.5">
                            <span class="text-xs text-gray-500">
                                @if($raw !== null)
                                    @if($name === 'price')
                                        Raw: {{ number_format($raw, 1) }}%
                                    @elseif($name === 'pressure')
                                        Raw: {{ number_format($raw, 2) }}×
                                    @elseif($name === 'dom')
                                        Raw: {{ number_format($raw, 0) }} days
                                    @elseif($name === 'absorption')
                                        Raw: {{ number_format($raw, 1) }} mo
                                    @elseif($name === 'elasticity')
                                        Raw: {{ number_format($raw, 2) }} d/%
                                    @else
                                        Raw: {{ number_format($raw, 2) }}
                                    @endif
                                @else
                                    Raw: —
                                @endif
                            </span>
                            <span class="text-xs text-indigo-600 bg-indigo-50 rounded px-1.5 py-0.5 truncate">{{ $interp }}</span>
                        </div>
                        <div class="h-1.5 rounded-full bg-gray-100 overflow-hidden">
                            <div class="h-full bg-indigo-500 rounded-full" style="width: {{ $barWidth }}%"></div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     PRICE SENSITIVITY — quick cards + full table in <details>
══════════════════════════════════════════════════════════════════════════ --}}
@if(!empty($spResult->sensitivity))
@php
    $baseRow  = null;
    $drop50k  = null;
    $drop100k = null;
    $drop150k = null;
    foreach ($spResult->sensitivity as $row) {
        if ($row['delta_rands'] === 0)       $baseRow  = $row;
        if ($row['delta_rands'] === -50000)  $drop50k  = $row;
        if ($row['delta_rands'] === -100000) $drop100k = $row;
        if ($row['delta_rands'] === -150000) $drop150k = $row;
    }
    $quickDrops = [
        ['label' => 'Drop R50,000',  'row' => $drop50k],
        ['label' => 'Drop R100,000', 'row' => $drop100k],
        ['label' => 'Drop R150,000', 'row' => $drop150k],
    ];
@endphp

<div class="bg-white rounded-xl shadow p-6 mb-6">
    <h2 class="text-base font-semibold text-gray-800 mb-1">Price Sensitivity</h2>
    <p class="text-xs text-gray-400 mb-4">Effect of a price reduction on 60-day probability and expected sale time.</p>

    {{-- Quick drop cards --}}
    <div class="grid grid-cols-3 gap-3 mb-4">
        @foreach($quickDrops as $drop)
            @php
                $row = $drop['row'];
                $hasData = $row !== null && $row['p60'] !== null && $baseRow !== null && $baseRow['p60'] !== null;
                if ($hasData) {
                    $p60Delta    = ($row['p60'] - $baseRow['p60']) * 100;
                    $daysDelta   = ($row['expected_days'] !== null && $baseRow['expected_days'] !== null)
                                    ? ($row['expected_days'] - $baseRow['expected_days'])
                                    : null;
                }
            @endphp
            <div class="border border-gray-200 rounded-lg p-4">
                <p class="text-xs text-gray-500 font-medium mb-2">{{ $drop['label'] }}</p>
                @if($hasData)
                    <p class="text-2xl font-bold text-gray-800 mb-1">
                        {{ number_format($row['p60'] * 100, 0) }}<span class="text-base">%</span>
                        <span class="text-sm text-gray-400 font-normal ml-1">p60</span>
                    </p>
                    <p class="text-xs @if($p60Delta >= 0) text-green-600 @else text-red-500 @endif font-medium">
                        @if($p60Delta >= 0)
                            +{{ number_format($p60Delta, 1) }} pp vs base
                        @else
                            {{ number_format($p60Delta, 1) }} pp vs base
                        @endif
                    </p>
                    @if($daysDelta !== null)
                        <p class="text-xs @if($daysDelta <= 0) text-green-600 @else text-red-500 @endif mt-0.5">
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

    {{-- Full 21-row table inside <details> --}}
    <details>
        <summary class="cursor-pointer text-xs text-indigo-600 hover:text-indigo-800 select-none font-medium">
            Show full price sensitivity curve (21 steps)
        </summary>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-xs text-left text-gray-700">
                <thead>
                    <tr class="border-b bg-gray-50 text-gray-500">
                        <th class="py-2 px-3">Price Delta</th>
                        <th class="py-2 px-3">Dev %</th>
                        <th class="py-2 px-3">Score</th>
                        <th class="py-2 px-3">P30</th>
                        <th class="py-2 px-3">P60</th>
                        <th class="py-2 px-3">P90</th>
                        <th class="py-2 px-3">Exp. Days</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($spResult->sensitivity as $row)
                        @php $isBase = $row['delta_rands'] === 0; @endphp
                        <tr class="border-b last:border-0 {{ $isBase ? 'bg-indigo-50 font-semibold' : '' }}">
                            <td class="py-1.5 px-3">
                                @if($row['delta_rands'] > 0)
                                    +R{{ number_format($row['delta_rands'], 0, '.', ',') }}
                                @elseif($row['delta_rands'] < 0)
                                    −R{{ number_format(abs($row['delta_rands']), 0, '.', ',') }}
                                @else
                                    Base
                                @endif
                            </td>
                            <td class="py-1.5 px-3">
                                @if($row['skip_reason'] ?? null)
                                    <span class="text-gray-400 italic">N/A</span>
                                @elseif(isset($row['adjusted_deviation_pct']))
                                    {{ number_format($row['adjusted_deviation_pct'], 1) }}%
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="py-1.5 px-3">
                                @if(($row['skip_reason'] ?? null) || $row['composite_score'] === null)
                                    <span class="text-gray-400">—</span>
                                @else
                                    {{ number_format($row['composite_score'], 3) }}
                                @endif
                            </td>
                            <td class="py-1.5 px-3">
                                @if($row['p30'] !== null)
                                    {{ number_format($row['p30'] * 100, 1) }}%
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="py-1.5 px-3">
                                @if($row['p60'] !== null)
                                    {{ number_format($row['p60'] * 100, 1) }}%
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="py-1.5 px-3">
                                @if($row['p90'] !== null)
                                    {{ number_format($row['p90'] * 100, 1) }}%
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="py-1.5 px-3">
                                @if($row['expected_days'] !== null)
                                    {{ $row['expected_days'] }}
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
</div>
@endif

{{-- ══════════════════════════════════════════════════════════════════════════
     MARKET EVIDENCE (secondary)
══════════════════════════════════════════════════════════════════════════ --}}
@php $domCurve = $maResult->domCurve; @endphp
<div class="bg-white rounded-xl shadow p-6 mb-6">
    <h2 class="text-base font-semibold text-gray-700 mb-1">Market Evidence</h2>
    <p class="text-xs text-gray-400 mb-4">
        {{ $inputs['suburb'] }} · {{ ucfirst($inputs['type']) }} · {{ $inputs['period_months'] }} month window
    </p>
    <dl class="grid grid-cols-2 gap-x-8 gap-y-3 text-sm md:grid-cols-3">
        <div>
            <dt class="text-xs text-gray-400 mb-0.5">Months of Inventory</dt>
            <dd class="font-semibold text-gray-800">
                @if($maResult->monthsOfInventory !== null)
                    {{ number_format($maResult->monthsOfInventory, 1) }} mo
                @else
                    <span class="text-gray-300">—</span>
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs text-gray-400 mb-0.5">Demand / Supply Ratio</dt>
            <dd class="font-semibold text-gray-800">
                @if($maResult->demandSupplyRatio !== null)
                    {{ number_format($maResult->demandSupplyRatio, 2) }}×
                @else
                    <span class="text-gray-300">—</span>
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs text-gray-400 mb-0.5">Price/m² vs Market</dt>
            <dd class="font-semibold text-gray-800">
                @if($maResult->pricePerSqmDeviationPct !== null)
                    {{ number_format($maResult->pricePerSqmDeviationPct, 1) }}%
                @else
                    <span class="text-gray-300">—</span>
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs text-gray-400 mb-0.5">DOM Median (p50)</dt>
            <dd class="font-semibold text-gray-800">
                @if(is_array($domCurve) && isset($domCurve['p50']))
                    {{ $domCurve['p50'] }} days
                @else
                    <span class="text-gray-300">—</span>
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs text-gray-400 mb-0.5">DOM p75</dt>
            <dd class="font-semibold text-gray-800">
                @if(is_array($domCurve) && isset($domCurve['p75']))
                    {{ $domCurve['p75'] }} days
                @else
                    <span class="text-gray-300">—</span>
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs text-gray-400 mb-0.5">Elasticity (days/%)</dt>
            <dd class="font-semibold text-gray-800">
                @if($maResult->elasticityDaysPerPct !== null)
                    {{ number_format($maResult->elasticityDaysPerPct, 2) }}
                @else
                    <span class="text-gray-300">—</span>
                @endif
            </dd>
        </div>
    </dl>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     SAVE SNAPSHOT
══════════════════════════════════════════════════════════════════════════ --}}
<div class="bg-white rounded-xl shadow p-6 mb-6">
    <h2 class="text-base font-semibold text-gray-700 mb-1">Save Snapshot</h2>
    <p class="text-xs text-gray-400 mb-4">
        Lock these results as an immutable snapshot attached to Presentation #{{ $presentation->id }}.
        MA run #{{ $maRun->id }} · SP run #{{ $spRun->id }}.
    </p>
    <form method="POST" action="{{ route('presentations.snapshots.save', $presentation) }}">
        @csrf
        <input type="hidden" name="market_run_id"       value="{{ $maRun->id }}">
        <input type="hidden" name="prob_run_id"         value="{{ $spRun->id }}">
        <input type="hidden" name="inputs_json"         value="{{ $snapshotInputsJson }}">
        <input type="hidden" name="output_summary_json" value="{{ $snapshotOutputSummaryJson }}">
        <button type="submit"
                class="px-5 py-2 bg-emerald-600 text-white text-sm font-medium rounded hover:bg-emerald-700">
            Save Snapshot
        </button>
    </form>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     AUDIT MODE
══════════════════════════════════════════════════════════════════════════ --}}
<div class="mb-2">
    <h2 class="text-xs font-semibold text-gray-400 uppercase tracking-widest">Audit Mode</h2>
</div>

<details class="mb-3">
    <summary class="cursor-pointer text-sm text-gray-500 hover:text-gray-700 select-none">
        Market Analytics breakdown (MA run #{{ $maRun->id }} · {{ $maRun->model_version }})
    </summary>
    <div class="mt-2 bg-gray-50 border border-gray-200 rounded p-4 overflow-x-auto">
        <pre class="text-xs text-gray-700 whitespace-pre-wrap">{{ json_encode($maRun->breakdown_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
</details>

<details class="mb-8">
    <summary class="cursor-pointer text-sm text-gray-500 hover:text-gray-700 select-none">
        Sale Probability breakdown (SP run #{{ $spRun->id }} · {{ $spRun->model_version }})
    </summary>
    <div class="mt-2 bg-gray-50 border border-gray-200 rounded p-4 overflow-x-auto">
        <pre class="text-xs text-gray-700 whitespace-pre-wrap">{{ json_encode($spRun->breakdown_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
</details>

@endsection
