@extends('layouts.nexus')

@section('nexus-content')

{{-- ══════════════════════════════════════════════════════════════════════════
     PAGE HEADER
══════════════════════════════════════════════════════════════════════════ --}}
<div class="mb-6 flex items-start justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">Market Analysis</h1>
        <p class="text-sm text-gray-500 mt-1">
            {{ $presentation->title }}
            @if($presentation->property_address)
                &nbsp;·&nbsp; {{ $presentation->property_address }}
            @endif
        </p>
    </div>
    <a href="{{ route('presentations.show', $presentation) }}"
       class="text-xs text-indigo-600 hover:underline mt-1">← Overview</a>
</div>

{{-- D1: Prep data summary — links + uploads at a glance --}}
@php
    $linkCount   = $presentation->links->count();
    $uploadCount = $presentation->uploads->count();
    $lastUpload  = $presentation->uploads->sortByDesc('created_at')->first();
@endphp
@if($linkCount > 0 || $uploadCount > 0)
<div class="mb-4 flex flex-wrap gap-4 text-xs text-gray-500">
    @if($linkCount > 0)
        <span>
            <span class="font-medium text-gray-700">{{ $linkCount }}</span>
            {{ $linkCount === 1 ? 'link' : 'links' }} attached
            <a href="{{ route('presentations.show', $presentation) }}#links"
               class="ml-1 text-indigo-500 hover:underline">manage</a>
        </span>
    @endif
    @if($uploadCount > 0)
        <span>
            <span class="font-medium text-gray-700">{{ $uploadCount }}</span>
            {{ $uploadCount === 1 ? 'document' : 'documents' }} uploaded
            @if($lastUpload)
                <span class="text-gray-400">· last {{ $lastUpload->created_at->format('d M') }}</span>
            @endif
        </span>
    @endif
</div>
@endif

{{-- ══════════════════════════════════════════════════════════════════════════
     INPUTS PANEL (editable — always visible)
══════════════════════════════════════════════════════════════════════════ --}}
<div class="bg-white rounded-xl shadow p-6 mb-6">
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-base font-semibold text-gray-700">Inputs</h2>
        @isset($maRun)
            <span class="text-xs text-gray-400">
                MA run #{{ $maRun->id }} · SP run #{{ $spRun->id }}
            </span>
        @endisset
    </div>
    <form method="POST" action="{{ route('presentations.analysis.run', $presentation) }}">
        @csrf
        <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
            <div>
                <label class="block text-xs text-gray-600 mb-1">Suburb <span class="text-red-500">*</span></label>
                <input type="text" name="suburb"
                       value="{{ $inputs['suburb'] ?? $lastInputs['suburb'] ?? old('suburb') }}"
                       required
                       class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                       placeholder="e.g. Ballito">
                @error('suburb')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Property Type <span class="text-red-500">*</span></label>
                <select name="type" required class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
                    @foreach(['house','unit','land','other'] as $t)
                        <option value="{{ $t }}"
                            {{ ($inputs['type'] ?? $lastInputs['type'] ?? old('type')) === $t ? 'selected' : '' }}>
                            {{ ucfirst($t) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Period <span class="text-red-500">*</span></label>
                <select name="period_months" required class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
                    @foreach([6,12,24] as $m)
                        <option value="{{ $m }}"
                            {{ ($inputs['period_months'] ?? $lastInputs['period_months'] ?? 12) == $m ? 'selected' : '' }}>
                            {{ $m }} months
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Asking Price (R) <span class="text-gray-400 font-normal">— optional</span></label>
                <input type="number" name="price"
                       value="{{ $inputs['price'] ?? $lastInputs['price'] ?? '' }}"
                       step="1" min="0"
                       class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                       placeholder="e.g. 2500000">
                <p class="mt-0.5 text-xs text-gray-400">Enter your current asking price to test positioning.</p>
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Floor Area (m²)</label>
                <input type="number" name="size_m2"
                       value="{{ $inputs['size_m2'] ?? $lastInputs['size_m2'] ?? '' }}"
                       min="0"
                       class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                       placeholder="e.g. 180">
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Bedrooms</label>
                <input type="number" name="bedrooms"
                       value="{{ $inputs['bedrooms'] ?? $lastInputs['bedrooms'] ?? '' }}"
                       min="0" max="20"
                       class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                       placeholder="e.g. 3">
            </div>
            {{-- Branch selector — admin only (Prompt A) --}}
            @if($isAdmin ?? false)
            <div>
                <label class="block text-xs text-gray-600 mb-1">Branch</label>
                <select name="branch_id" class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
                    <option value="">— Any —</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}"
                            {{ ($inputs['branch_id'] ?? $lastInputs['branch_id'] ?? null) == $branch->id ? 'selected' : '' }}>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            @endif
        </div>

        {{-- Holding cost inputs (collapsible, optional) --}}
        <details class="mt-5 border-t pt-4" @isset($holdingCost) @if($holdingCost->monthlyTotal() > 0) open @endif @endisset>
            <summary class="cursor-pointer text-xs font-medium text-gray-500 hover:text-gray-700 select-none">
                Holding Cost Inputs (optional)
            </summary>
            <div class="mt-3 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-5">
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Monthly Bond (R)</label>
                    <input type="number" name="monthly_bond"
                           value="{{ $inputs['monthly_bond'] ?? '' }}"
                           min="0" step="1"
                           class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                           placeholder="0">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Rates (R/month)</label>
                    <input type="number" name="monthly_rates"
                           value="{{ $inputs['monthly_rates'] ?? '' }}"
                           min="0" step="1"
                           class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                           placeholder="0">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Levies (R/month)</label>
                    <input type="number" name="monthly_levies"
                           value="{{ $inputs['monthly_levies'] ?? '' }}"
                           min="0" step="1"
                           class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                           placeholder="0">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Insurance (R/month)</label>
                    <input type="number" name="monthly_insurance"
                           value="{{ $inputs['monthly_insurance'] ?? '' }}"
                           min="0" step="1"
                           class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                           placeholder="0">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Maintenance Buffer</label>
                    <input type="number" name="monthly_maintenance_buffer"
                           value="{{ $inputs['monthly_maintenance_buffer'] ?? '' }}"
                           min="0" step="1"
                           class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                           placeholder="0">
                </div>
            </div>
        </details>

        <div class="mt-4">
            <button type="submit"
                    class="px-5 py-2 bg-indigo-600 text-white text-sm font-medium rounded hover:bg-indigo-700">
                @isset($maResult) Re-run Analysis @else Run Analysis @endisset
            </button>
        </div>
    </form>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     READINESS CHECKLIST (Prompt C) — always shown, gates results below
══════════════════════════════════════════════════════════════════════════ --}}
@php
    // Determine checklist state from what we know
    $ckSuburb    = !empty($inputs['suburb']   ?? $lastInputs['suburb']   ?? $presentation->suburb);
    $ckType      = !empty($inputs['type']     ?? $lastInputs['type']     ?? $presentation->property_type);
    $ckPrice     = !empty($inputs['price']    ?? null);
    $ckFloorArea = !empty($inputs['size_m2']  ?? $lastInputs['size_m2']  ?? $presentation->floor_area_m2);
    $ckSold      = $hasSoldData; // passed from controller (false on GET, computed on POST)
    $ckActive    = isset($maResult) && ($maResult->activeListingCount ?? 0) > 0;
@endphp

@if(isset($maResult) || $ckSuburb || $ckType)
<div class="bg-white rounded-xl shadow p-5 mb-6">
    <h2 class="text-sm font-semibold text-gray-700 mb-3">Analysis readiness</h2>
    <div class="grid grid-cols-2 gap-x-8 gap-y-2 text-xs sm:grid-cols-3">
        @php
        $items = [
            ['label' => 'Suburb', 'ok' => $ckSuburb, 'fix' => 'Enter suburb above'],
            ['label' => 'Property type', 'ok' => $ckType, 'fix' => 'Select property type above'],
            ['label' => 'Asking price', 'ok' => $ckPrice, 'fix' => 'Enter price to unlock recommendation'],
            ['label' => 'Floor area', 'ok' => $ckFloorArea, 'fix' => 'Add floor area to unlock price/m² signal'],
            ['label' => 'Sold comparables', 'ok' => $ckSold, 'fix' => isset($maResult) ? 'Import sold listings for this suburb' : 'Run analysis to check'],
            ['label' => 'Active listings', 'ok' => $ckActive, 'fix' => isset($maResult) ? 'Import active listings for this suburb' : 'Run analysis to check'],
        ];
        @endphp
        @foreach($items as $item)
            <div class="flex items-center gap-2">
                @if($item['ok'])
                    <span class="text-emerald-500 font-bold">✓</span>
                    <span class="text-gray-700">{{ $item['label'] }}</span>
                @else
                    <span class="text-gray-300 font-bold">○</span>
                    <span class="text-gray-400">{{ $item['label'] }}
                        <span class="text-indigo-500"> — {{ $item['fix'] }}</span>
                    </span>
                @endif
            </div>
        @endforeach
    </div>
</div>
@endif

{{-- ══════════════════════════════════════════════════════════════════════════
     RESULTS (only shown after compute POST)
══════════════════════════════════════════════════════════════════════════ --}}
@isset($maResult)

{{-- ── 1. SELLER SUMMARY HERO ─────────────────────────────────────────────────
     Narrative headline + confidence badge + probability chips
──────────────────────────────────────────────────────────────────────────── --}}
@php
    $confidenceState = $narrative['confidence_state'];
    $confidenceBadge = match($confidenceState) {
        'good'    => ['bg-emerald-400/20 text-emerald-100 border-emerald-400/40', 'Strong evidence'],
        'limited' => ['bg-amber-400/20 text-amber-100 border-amber-400/40',     'Partial evidence'],
        default   => ['bg-gray-400/20 text-gray-200 border-gray-400/40',         'Need more data'],
    };
@endphp

<div class="bg-gradient-to-br from-indigo-700 to-indigo-900 rounded-xl shadow-lg p-6 mb-6 text-white">
    <div class="flex items-start justify-between mb-1">
        <p class="text-indigo-200 text-xs font-semibold uppercase tracking-widest">Seller Summary</p>
        <span class="px-2.5 py-0.5 rounded-full text-xs font-medium border {{ $confidenceBadge[0] }}">
            {{ $confidenceBadge[1] }}
        </span>
    </div>

    <h2 class="text-xl font-bold mb-5">{{ $narrative['headline'] }}</h2>

    @if($confidenceState === 'none' || !$hasSoldData)
        {{-- No sold data or no confidence — show guidance, not probability chips (C1) --}}
        <div class="bg-white/10 border border-white/20 rounded-lg px-4 py-4 text-sm text-indigo-100">
            <p class="font-medium text-white mb-2">Not enough data to run the model yet</p>
            @foreach($narrative['what_this_means'] as $bullet)
                <p class="text-indigo-200 text-xs mb-1">{{ $bullet }}</p>
            @endforeach
        </div>
    @else
        {{-- Probability chips --}}
        <div class="grid grid-cols-3 gap-3 mb-5">
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

        <div class="bg-white/10 border border-white/20 rounded-lg px-4 py-3 flex items-center justify-between">
            <span class="text-indigo-200 text-sm">Expected time to sell</span>
            @if($spResult->expectedDays !== null)
                <span class="text-white font-bold text-lg">{{ $spResult->expectedDays }} days</span>
            @else
                <span class="text-indigo-300 text-sm italic">More data needed</span>
            @endif
        </div>

        <p class="mt-3 text-indigo-300 text-xs text-right">{{ $narrative['pricing_message'] }}</p>
    @endif

    <p class="mt-3 text-indigo-400 text-xs text-right font-mono">
        MA {{ $maRun->model_version }} · SP {{ $spRun->model_version }} · run #{{ $spRun->id }}
    </p>
</div>

{{-- ══ GATE: probability panels only render when sold comps exist (Prompt C) ══ --}}
@if(!$hasSoldData)
<div class="bg-amber-50 border border-amber-200 rounded-xl p-6 mb-6">
    <h2 class="text-base font-semibold text-amber-900 mb-2">To unlock sale probability</h2>
    <p class="text-sm text-amber-800 mb-4">
        We don't have enough comparable sold listings in this suburb to estimate sale probability or timing.
        Once we have sold data, this screen will show probability chips, a recommended price, and sensitivity analysis.
    </p>
    <ul class="space-y-2 text-sm text-amber-800">
        <li class="flex items-start gap-2">
            <span class="font-bold shrink-0">1.</span>
            <span>Import recent sold listings for <strong>{{ $inputs['suburb'] }}</strong> — use the data import tool in the sidebar.</span>
        </li>
        <li class="flex items-start gap-2">
            <span class="font-bold shrink-0">2.</span>
            <span>If you just imported, try widening the time window to <strong>24 months</strong> and re-running.</span>
        </li>
        <li class="flex items-start gap-2">
            <span class="font-bold shrink-0">3.</span>
            <span>Check that the suburb name matches exactly what was used during import.</span>
        </li>
    </ul>
</div>
@else

{{-- ── 2. RECOMMENDED STRATEGY (Prompt 9) ────────────────────────────────────
     Single authoritative recommendation from RecommendationService
──────────────────────────────────────────────────────────────────────────── --}}
@isset($recommendation)
@php
    $recReason     = $recommendation['reason'];
    $recConfidence = $recommendation['confidence'];

    $confidenceChip = match($recConfidence) {
        'high'   => 'bg-emerald-100 text-emerald-700 border-emerald-200',
        'medium' => 'bg-amber-100 text-amber-700 border-amber-200',
        default  => 'bg-gray-100 text-gray-500 border-gray-200',
    };
    $confidenceLabel = match($recConfidence) {
        'high'   => 'High confidence',
        'medium' => 'Moderate confidence',
        default  => 'Low confidence',
    };
    $borderAccent = match($recConfidence) {
        'high'   => 'border-emerald-400',
        'medium' => 'border-amber-400',
        default  => 'border-gray-300',
    };
@endphp

<div class="bg-white rounded-xl shadow p-6 mb-6 border-l-4 {{ $borderAccent }}">
    <div class="flex items-start justify-between mb-3">
        <h2 class="text-base font-semibold text-gray-800">Recommended Strategy</h2>
        <span class="px-2.5 py-0.5 rounded-full text-xs font-medium border {{ $confidenceChip }}">
            {{ $confidenceLabel }}
        </span>
    </div>

    @if($recReason === 'insufficient_sensitivity_data')
        <p class="text-sm text-gray-600">
            We do not yet have sufficient pricing sensitivity data to recommend a precise adjustment.
            Run the analysis with a price and floor area to unlock this recommendation.
        </p>
    @else
        {{-- Headline --}}
        @php
            $recPrice = $recommendation['recommended_price'];
            $recDelta = $recommendation['delta_rands'];
        @endphp

        @if($recDelta === 0)
            <p class="text-sm font-semibold text-gray-800 mb-3">
                Your current asking price of
                <span class="text-indigo-700">R{{ number_format($recPrice, 0) }}</span>
                is at or above the 65% probability threshold — no reduction required.
            </p>
        @elseif($recDelta < 0)
            <p class="text-sm font-semibold text-gray-800 mb-3">
                To achieve a 65% probability of sale within 60 days, we recommend pricing at
                <span class="text-indigo-700">R{{ number_format($recPrice, 0) }}</span>
                <span class="text-gray-400 font-normal">(−R{{ number_format(abs($recDelta), 0) }} from asking price)</span>.
            </p>
        @else
            <p class="text-sm font-semibold text-gray-800 mb-3">
                The market data suggests you could price at
                <span class="text-indigo-700">R{{ number_format($recPrice, 0) }}</span>
                <span class="text-gray-400 font-normal">(+R{{ number_format($recDelta, 0) }} above asking price)</span>
                and still achieve strong sale probability.
            </p>
        @endif

        {{-- Sub-lines --}}
        <dl class="grid grid-cols-1 gap-2 sm:grid-cols-3 text-sm">
            <div class="bg-gray-50 rounded-lg px-4 py-3">
                <dt class="text-xs text-gray-400 mb-0.5">60-day sale probability</dt>
                <dd class="font-bold text-gray-800 text-lg">
                    @if($recommendation['probability_at_recommendation'] !== null)
                        {{ number_format($recommendation['probability_at_recommendation'] * 100, 0) }}%
                    @else
                        <span class="text-gray-300">—</span>
                    @endif
                </dd>
            </div>
            <div class="bg-gray-50 rounded-lg px-4 py-3">
                <dt class="text-xs text-gray-400 mb-0.5">Expected days to sale</dt>
                <dd class="font-bold text-gray-800 text-lg">
                    @if($recommendation['expected_days_at_recommendation'] !== null)
                        {{ $recommendation['expected_days_at_recommendation'] }} days
                    @else
                        <span class="text-gray-300">—</span>
                    @endif
                </dd>
            </div>
            @if($recommendation['holding_cost_projection'] !== null)
            <div class="bg-amber-50 rounded-lg px-4 py-3 border border-amber-100">
                <dt class="text-xs text-amber-600 mb-0.5">Projected holding cost until sale</dt>
                <dd class="font-bold text-amber-700 text-lg">
                    R{{ number_format($recommendation['holding_cost_projection'], 0) }}
                </dd>
            </div>
            @endif
        </dl>

        @if($recReason === 'max_probability_available')
            <p class="mt-3 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2">
                No price point in the model reaches 65% — this is the best achievable probability given current market conditions.
                Consider importing more market data or revisiting the asking price.
            </p>
        @endif
    @endif
</div>
@endisset

{{-- ── 3. WHAT THIS MEANS ──────────────────────────────────────────────────────
     3 plain-language bullets for the seller
──────────────────────────────────────────────────────────────────────────── --}}
<div class="grid gap-6 md:grid-cols-2 mb-6">

    <div class="bg-white rounded-xl shadow p-6">
        <h2 class="text-base font-semibold text-gray-800 mb-4">What this means</h2>
        <ul class="space-y-3">
            @foreach($narrative['what_this_means'] as $bullet)
                <li class="flex items-start gap-3">
                    <span class="shrink-0 mt-0.5 w-5 h-5 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center text-xs font-bold">{{ $loop->iteration }}</span>
                    <span class="text-sm text-gray-700">{{ $bullet }}</span>
                </li>
            @endforeach
        </ul>
    </div>

    {{-- ── 3. NEXT BEST ACTIONS ────────────────────────────────────────────── --}}
    <div class="bg-indigo-50 border border-indigo-100 rounded-xl shadow p-6">
        <h2 class="text-base font-semibold text-indigo-900 mb-4">Next steps for your meeting</h2>
        <ul class="space-y-3">
            @foreach($narrative['next_best_actions'] as $action)
                <li class="flex items-start gap-3">
                    <span class="shrink-0 mt-0.5 text-indigo-500 font-bold text-base leading-5">→</span>
                    <span class="text-sm text-indigo-900">{{ $action }}</span>
                </li>
            @endforeach
        </ul>
    </div>

</div>

{{-- ── 4. HOLDING COST CLOCK ───────────────────────────────────────────────────
     Monthly / 90-day figures + R50k leverage line
──────────────────────────────────────────────────────────────────────────── --}}
@isset($holdingCost)
@if($holdingCost->monthlyTotal() > 0)
@php
    $hcMonthly   = $holdingCost->monthlyTotal();
    $hcPer90Days = $holdingCost->costForDays(90);
    $hcProjected = ($spResult->expectedDays !== null)
                    ? $holdingCost->costForDays($spResult->expectedDays)
                    : null;
@endphp
<div class="bg-white rounded-xl shadow p-6 mb-6">
    <h2 class="text-base font-semibold text-gray-700 mb-1">Your holding cost clock</h2>
    <p class="text-xs text-gray-400 mb-4">Every day on the market has a price. Here is what the timeline costs.</p>

    <div class="grid grid-cols-2 gap-4 md:grid-cols-4 mb-4">
        <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-xs text-gray-400 mb-0.5">Monthly total</p>
            <p class="text-lg font-bold text-gray-800">R{{ number_format($hcMonthly, 0) }}</p>
        </div>
        <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-xs text-gray-400 mb-0.5">Each 30 days costs</p>
            <p class="text-lg font-bold text-gray-800">R{{ number_format($holdingCost->costForDays(30), 0) }}</p>
        </div>
        <div class="bg-amber-50 rounded-lg p-3 border border-amber-100">
            <p class="text-xs text-amber-600 mb-0.5">90-day delay cost</p>
            <p class="text-lg font-bold text-amber-700">R{{ number_format($hcPer90Days, 0) }}</p>
        </div>
        @if($hcProjected !== null)
        <div class="bg-indigo-50 rounded-lg p-3 border border-indigo-100">
            <p class="text-xs text-indigo-600 mb-0.5">Projected carry ({{ $spResult->expectedDays }} days)</p>
            <p class="text-lg font-bold text-indigo-700">R{{ number_format($hcProjected, 0) }}</p>
        </div>
        @endif
    </div>

    {{-- R50k leverage line (Prompt 7) --}}
    @isset($leverage50k)
    <div class="mt-3 border-t pt-3">
        <p class="text-sm text-gray-700">
            <span class="font-medium text-gray-800">R50,000 vs waiting:</span>
            {{ $leverage50k['message'] }}
            @if($leverage50k['days_delta'] !== null && $leverage50k['days_delta'] > 0)
                <span class="ml-1 text-green-700 font-medium">
                    And may reduce your time-to-sale by {{ $leverage50k['days_delta'] }} days (based on the model).
                </span>
            @endif
        </p>
    </div>
    @endisset

    <details class="mt-3">
        <summary class="cursor-pointer text-xs text-gray-400 hover:text-gray-600 select-none">Show line breakdown</summary>
        <div class="mt-2 grid grid-cols-2 gap-2 md:grid-cols-3 text-xs text-gray-600">
            @foreach($holdingCost->breakdown() as $key => $value)
                @if($key !== 'monthly_total')
                <div class="flex justify-between py-1 border-b">
                    <span class="text-gray-400">{{ ucwords(str_replace('_', ' ', $key)) }}</span>
                    <span class="font-medium">R{{ number_format($value, 0) }}</span>
                </div>
                @endif
            @endforeach
        </div>
    </details>
</div>
@endif
@endisset

{{-- ── 7. PRICE SENSITIVITY ────────────────────────────────────────────────────
     Collapsed by default — detail for staff who want to explore
──────────────────────────────────────────────────────────────────────────── --}}
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

<details class="mb-6">
    <summary class="cursor-pointer bg-white rounded-xl shadow px-6 py-4 text-sm font-medium text-gray-600 hover:text-gray-800 select-none flex items-center justify-between">
        <span>Price Sensitivity — What if I changed the price?</span>
        <span class="text-xs text-gray-400 font-normal">click to expand</span>
    </summary>
    <div class="bg-white rounded-b-xl shadow -mt-1 px-6 pb-6 pt-4">

        <div class="grid grid-cols-3 gap-3 mb-4">
            @foreach($quickDrops as $drop)
                @php
                    $row = $drop['row'];
                    $hasData = $row !== null && $row['p60'] !== null && $baseRow !== null && $baseRow['p60'] !== null;
                    if ($hasData) {
                        $p60Delta  = ($row['p60'] - $baseRow['p60']) * 100;
                        $daysDelta = ($row['expected_days'] !== null && $baseRow['expected_days'] !== null)
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
                            @if($p60Delta >= 0)+@endif{{ number_format($p60Delta, 1) }} pp vs base
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
</details>
@endif

@endif {{-- end $hasSoldData gate (Prompt C) --}}

{{-- ── 5. EVIDENCE USED (always shown after analysis — C1) ────────────────────
     Key market facts used by the engine
──────────────────────────────────────────────────────────────────────────── --}}
<div class="bg-white rounded-xl shadow p-6 mb-6">
    <h2 class="text-base font-semibold text-gray-700 mb-1">Market evidence used</h2>
    <p class="text-xs text-gray-400 mb-4">
        {{ $inputs['suburb'] }} · {{ ucfirst($inputs['type']) }} · {{ $inputs['period_months'] }}-month window
    </p>
    <dl class="grid grid-cols-2 gap-x-8 gap-y-3 text-sm md:grid-cols-4">
        @foreach($narrative['evidence_summary'] as $label => $value)
        <div>
            <dt class="text-xs text-gray-400 mb-0.5">{{ $label }}</dt>
            <dd class="font-semibold text-gray-800">
                @if($value !== null)
                    {{ $value }}
                @else
                    <span class="text-gray-300">—</span>
                @endif
            </dd>
        </div>
        @endforeach
    </dl>
</div>

{{-- ── 6. DATA ADEQUACY TABLE (always shown after analysis — C1) ──────────────
     Shows all 5 signals: active vs skipped, and how to fix each gap
──────────────────────────────────────────────────────────────────────────── --}}
@php
    $hasSkipped = collect($narrative['signal_status'])->contains(fn($s) => !$s['active']);
    $allActive  = !$hasSkipped;
@endphp
<div class="bg-white rounded-xl shadow p-6 mb-6">
    <div class="flex items-center justify-between mb-3">
        <div>
            <h2 class="text-base font-semibold text-gray-700">Data adequacy</h2>
            <p class="text-xs text-gray-400 mt-0.5">Which pricing signals the engine could use, and why any were skipped.</p>
        </div>
        @if($allActive)
            <span class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700 border border-emerald-200">All signals active</span>
        @else
            <span class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700 border border-amber-200">Some signals skipped</span>
        @endif
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b text-xs text-gray-400 text-left">
                    <th class="pb-2 pr-4 font-medium">Signal</th>
                    <th class="pb-2 pr-4 font-medium">Status</th>
                    <th class="pb-2 pr-4 font-medium">Reason</th>
                    <th class="pb-2 font-medium">How to fix</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($narrative['signal_status'] as $name => $signal)
                <tr>
                    <td class="py-2.5 pr-4 font-medium text-gray-800">{{ $signal['label'] }}</td>
                    <td class="py-2.5 pr-4">
                        @if($signal['active'])
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700">
                                ✓ Active
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">
                                — Skipped
                            </span>
                        @endif
                    </td>
                    <td class="py-2.5 pr-4 text-xs text-gray-500">
                        {{ $signal['active'] ? '—' : $signal['skip_reason'] }}
                    </td>
                    <td class="py-2.5 text-xs text-indigo-700">
                        {{ $signal['active'] ? '—' : $signal['how_to_fix'] }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- ── 8. SAVE SNAPSHOT ────────────────────────────────────────────────────────
──────────────────────────────────────────────────────────────────────────── --}}
<div class="bg-white rounded-xl shadow p-6 mb-6">
    <h2 class="text-base font-semibold text-gray-700 mb-1">Save Snapshot</h2>
    <p class="text-xs text-gray-400 mb-4">
        Lock these results as an immutable snapshot for Presentation #{{ $presentation->id }}.
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

{{-- ── 9. AUDIT MODE ───────────────────────────────────────────────────────────
     Fully collapsed — for internal review only
──────────────────────────────────────────────────────────────────────────── --}}
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

<details class="mb-3">
    <summary class="cursor-pointer text-sm text-gray-500 hover:text-gray-700 select-none">
        Sale Probability breakdown (SP run #{{ $spRun->id }} · {{ $spRun->model_version }})
    </summary>
    <div class="mt-2 bg-gray-50 border border-gray-200 rounded p-4 overflow-x-auto">
        <pre class="text-xs text-gray-700 whitespace-pre-wrap">{{ json_encode($spRun->breakdown_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
</details>

<details class="mb-8">
    <summary class="cursor-pointer text-sm text-gray-500 hover:text-gray-700 select-none">
        Narrative payload (PresentationNarrativeService output)
    </summary>
    <div class="mt-2 bg-gray-50 border border-gray-200 rounded p-4 overflow-x-auto">
        <pre class="text-xs text-gray-700 whitespace-pre-wrap">{{ json_encode($narrative, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
</details>

@endisset {{-- end @isset($maResult) --}}

@endsection
