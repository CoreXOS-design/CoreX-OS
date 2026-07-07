{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
@php
    $ma = $marketAverages ?? [];
    $aw = $avgWindow ?? 'period';
    $sf = $stageFilter ?? ['pending' => true, 'granted' => true, 'registered' => true];
    $am = $agentMarket ?? [];
    $windowFrom = $avgWindowFrom ?? null;
    $windowTo = $avgWindowTo ?? null;
@endphp

<div class="w-full space-y-6">

    {{-- PAGE HEADER (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Worksheet Market — Branch</h1>
                <p class="text-sm text-white/60">Set market average sale price per agent.</p>
            </div>
            <div class="flex items-center gap-2">
                <form method="GET" action="{{ route('bm.worksheet.market') }}" class="flex items-center gap-2">
                    <input type="month" name="period" value="{{ $period }}"
                           class="h-9 text-sm rounded-md border border-white/20 bg-white/10 text-white px-2 transition-all duration-300" />
                    <button type="submit"
                            class="px-3 py-1.5 text-sm font-semibold rounded-md bg-white/20 text-white hover:bg-white/30 transition-all duration-300">
                        Go
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- FLASH MESSAGE (§3.9 alert pattern) --}}
    @if (session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                 stroke="currentColor" class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green);">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif

    {{-- DEAL REGISTER MARKET AVERAGES --}}
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
            <div>
                <h2 class="ds-section-header" style="margin-bottom: 0;">Deal Register Market Averages</h2>
                <p class="text-xs mt-1" style="color: var(--text-secondary);">
                    Uses Deal Register deals for your branch. Window + stage filters apply.
                    @if(!empty($windowFrom) && !empty($windowTo))
                        <span class="ml-2"><strong>Window:</strong> {{ $windowFrom }} &rarr; {{ $windowTo }}</span>
                    @endif
                </p>
            </div>

            <form method="GET" action="{{ route('bm.worksheet.market') }}" class="flex flex-wrap gap-3 items-end">
                <input type="hidden" name="period" value="{{ $period }}" />

                <div>
                    <label for="avg_window" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Window</label>
                    <select id="avg_window" name="avg_window" class="list-header-filter">
                        <option value="period" {{ $aw === 'period' ? 'selected' : '' }}>This month</option>
                        <option value="3m" {{ $aw === '3m' ? 'selected' : '' }}>Last 3 months</option>
                        <option value="6m" {{ $aw === '6m' ? 'selected' : '' }}>Last 6 months</option>
                        <option value="all" {{ $aw === 'all' ? 'selected' : '' }}>All time</option>
                    </select>
                </div>

                <div class="flex gap-3 pb-1">
                    <label class="text-sm flex items-center gap-2" style="color: var(--text-primary);">
                        <input type="checkbox" name="st_pending" value="1" {{ !empty($sf['pending']) ? 'checked' : '' }}>
                        Pending
                    </label>
                    <label class="text-sm flex items-center gap-2" style="color: var(--text-primary);">
                        <input type="checkbox" name="st_granted" value="1" {{ !empty($sf['granted']) ? 'checked' : '' }}>
                        Granted
                    </label>
                    <label class="text-sm flex items-center gap-2" style="color: var(--text-primary);">
                        <input type="checkbox" name="st_registered" value="1" {{ !empty($sf['registered']) ? 'checked' : '' }}>
                        Registered
                    </label>
                </div>

                <button type="submit" class="corex-btn-primary">Apply</button>
            </form>
        </div>

        {{-- Market KPIs --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-5">
            <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                <div class="ds-label">Deals counted</div>
                <div class="ds-value-lg">{{ number_format((int)($ma['deals_count'] ?? 0)) }}</div>
            </div>
            <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                <div class="ds-label">Avg Sale Price (Incl VAT)</div>
                <div class="ds-value-lg">R {{ number_format((float)($ma['avg_sale_price_inc_vat'] ?? 0), 0) }}</div>
                <div class="text-xs mt-1" style="color: var(--text-muted);">
                    Ex VAT: R {{ number_format((float)($ma['avg_sale_price_ex_vat'] ?? 0), 0) }}
                </div>
            </div>
            <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                <div class="ds-label">Effective Comm % (Ex VAT)</div>
                <div class="ds-value-lg">{{ number_format((float)($ma['effective_commission_percent_ex_vat'] ?? 0), 1) }}%</div>
            </div>
        </div>
    </div>

    {{-- AGENT OVERRIDES --}}
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="ds-section-header" style="margin-bottom: 0.75rem;">Agent Overrides</h2>

        <form method="POST" action="{{ route('bm.worksheet.market.save') }}">
            @csrf
            <input type="hidden" name="period" value="{{ $period }}" />

            <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm ds-table">
                        <thead>
                            <tr style="background: var(--surface-2);">
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider"
                                    style="color: var(--text-muted);">Agent</th>
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider"
                                    style="color: var(--text-muted);">Avg Sales Override</th>
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider"
                                    style="color: var(--text-muted);">Actual Deals</th>
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider"
                                    style="color: var(--text-muted);">Actual Avg Sale (Inc)</th>
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider"
                                    style="color: var(--text-muted);">Actual Eff Comm % (Ex)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($agents as $a)
                                @php
                                    $w = $worksheets->get($a->id);
                                    $cur = $w->avg_sale_price_admin ?? null;
                                    $m = $am[(int)$a->id] ?? [
                                        'deals_count' => 0,
                                        'avg_sale_price_inc_vat' => 0,
                                        'effective_commission_percent_ex_vat' => 0,
                                    ];
                                @endphp
                                <tr class="transition-colors" style="border-top: 1px solid var(--border);">
                                    <td class="px-4 py-3 font-semibold whitespace-nowrap min-w-[220px]"
                                        style="color: var(--text-primary);">{{ $a->name }}</td>

                                    <td class="px-4 py-3">
                                        <input type="number" step="0.01"
                                               name="avg[{{ $a->id }}]"
                                               value="{{ old('avg.' . $a->id, $cur) }}"
                                               placeholder="e.g. 1200000"
                                               class="w-36 rounded-md px-3 py-2 text-sm"
                                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" />
                                        <div class="text-xs mt-1" style="color: var(--text-muted);">
                                            Current: {{ $cur === null ? '—' : ('R ' . number_format((float)$cur, 0)) }}
                                        </div>
                                    </td>

                                    <td class="px-4 py-3" style="color: var(--text-secondary);">
                                        {{ number_format((int)($m['deals_count'] ?? 0)) }}
                                    </td>
                                    <td class="px-4 py-3 ds-value">
                                        R {{ number_format((float)($m['avg_sale_price_inc_vat'] ?? 0), 0) }}
                                    </td>
                                    <td class="px-4 py-3 ds-value">
                                        {{ number_format((float)($m['effective_commission_percent_ex_vat'] ?? 0), 1) }}%
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                        No agents available for this branch.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if($agents->isNotEmpty())
                <div class="mt-4 flex justify-end">
                    <button type="submit" class="corex-btn-primary">Save Market Avg Prices</button>
                </div>
            @endif
        </form>
    </div>

</div>
@endsection
