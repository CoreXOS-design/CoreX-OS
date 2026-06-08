@extends('layouts.corex-app')

@section('corex-content')
<div class="max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-2xl font-semibold" style="color:var(--text-primary)">AI Usage &amp; Cost</h1>
            <p class="text-sm mt-1" style="color:var(--text-secondary)">
                Anthropic spend, token throughput, cache health, and per-agency budgets for <strong>{{ $monthLabel }}</strong>.
                <span class="ml-1">Forward-looking ZAR — historical cache rows are snapshots.</span>
            </p>
        </div>
        <form method="GET" class="flex items-center gap-2">
            <label class="text-xs uppercase tracking-wider" style="color:var(--text-secondary)">Month</label>
            <input type="month" name="month" value="{{ $month }}" class="rounded px-2 py-1 text-sm"
                   style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border)">
            <button type="submit" class="text-xs px-3 py-1 rounded"
                    style="background:var(--brand-icon); color:#fff">View</button>
        </form>
    </div>

    @if(session('status'))
        <div class="hfc-card mb-4 px-4 py-2 text-sm"
             style="background:var(--surface-2); color:var(--text-primary); border-left: 3px solid var(--brand-icon)">
            {{ session('status') }}
        </div>
    @endif

    {{-- Hero metrics --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-6">
        <div class="hfc-card px-4 py-3">
            <div class="text-xs uppercase tracking-wider" style="color:var(--text-secondary)">Total spend</div>
            <div class="text-2xl font-semibold mt-1" style="color:var(--text-primary)">
                R {{ number_format($totalZar, 2) }}
            </div>
        </div>
        <div class="hfc-card px-4 py-3">
            <div class="text-xs uppercase tracking-wider" style="color:var(--text-secondary)">Input tokens</div>
            <div class="text-2xl font-semibold mt-1" style="color:var(--text-primary)">
                {{ number_format($tokens['input']) }}
            </div>
        </div>
        <div class="hfc-card px-4 py-3">
            <div class="text-xs uppercase tracking-wider" style="color:var(--text-secondary)">Output tokens</div>
            <div class="text-2xl font-semibold mt-1" style="color:var(--text-primary)">
                {{ number_format($tokens['output']) }}
            </div>
        </div>
        <div class="hfc-card px-4 py-3">
            <div class="text-xs uppercase tracking-wider" style="color:var(--text-secondary)">Cache hit rate (30d)</div>
            <div class="text-2xl font-semibold mt-1" style="color:var(--text-primary)">
                {{ number_format($cacheHitRate30, 1) }}%
            </div>
        </div>
    </div>

    {{-- Cache footprint --}}
    <div class="hfc-card mb-6 px-4 py-3">
        <div class="text-xs uppercase tracking-wider mb-2" style="color:var(--brand-icon)">Cache footprint</div>
        <div class="grid grid-cols-3 gap-4 text-sm">
            <div>
                <div class="text-xs" style="color:var(--text-secondary)">Active rows</div>
                <div class="font-semibold" style="color:var(--text-primary)">{{ number_format($cacheStats['active_rows']) }}</div>
            </div>
            <div>
                <div class="text-xs" style="color:var(--text-secondary)">Soft-deleted (awaiting purge)</div>
                <div class="font-semibold" style="color:var(--text-primary)">{{ number_format($cacheStats['soft_deleted']) }}</div>
            </div>
            <div>
                <div class="text-xs" style="color:var(--text-secondary)">Expired (not yet swept)</div>
                <div class="font-semibold" style="color:var(--text-primary)">{{ number_format($cacheStats['expired_active']) }}</div>
            </div>
        </div>
    </div>

    {{-- Daily burn --}}
    <div class="hfc-card mb-6">
        <div class="px-4 py-3 border-b" style="border-color:var(--border)">
            <h2 class="text-sm font-semibold uppercase tracking-wider" style="color:var(--brand-icon)">
                Daily burn — {{ $monthLabel }}
            </h2>
        </div>
        <div class="p-4">
            @if(empty($dailyBurn))
                <p class="text-sm" style="color:var(--text-secondary)">No spend recorded for this month.</p>
            @else
                @php
                    $maxDaily = max(array_map(fn ($d) => $d['cost_zar'], $dailyBurn)) ?: 1;
                @endphp
                <div class="space-y-1">
                    @foreach($dailyBurn as $d)
                        <div class="flex items-center gap-3 text-xs">
                            <span class="w-24 font-mono" style="color:var(--text-secondary)">{{ $d['day'] }}</span>
                            <div class="flex-1 h-4 rounded relative" style="background:var(--surface-2)">
                                <div class="h-full rounded"
                                     style="width: {{ max(2, ($d['cost_zar'] / $maxDaily) * 100) }}%; background: var(--brand-icon)"></div>
                            </div>
                            <span class="w-24 text-right font-mono" style="color:var(--text-primary)">R {{ number_format($d['cost_zar'], 2) }}</span>
                            <span class="w-16 text-right" style="color:var(--text-secondary)">{{ $d['generations'] }} calls</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- By narrative type + Top agencies — side by side --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <div class="hfc-card">
            <div class="px-4 py-3 border-b" style="border-color:var(--border)">
                <h2 class="text-sm font-semibold uppercase tracking-wider" style="color:var(--brand-icon)">
                    Spend by source
                </h2>
            </div>
            <div class="p-4">
                @if(empty($bySource))
                    <p class="text-sm" style="color:var(--text-secondary)">No data.</p>
                @else
                    @php $maxBySource = max($bySource) ?: 1; @endphp
                    <div class="space-y-2">
                        @foreach($bySource as $source => $cost)
                            <div>
                                <div class="flex justify-between text-xs mb-1">
                                    <span style="color:var(--text-primary)">{{ ucwords(str_replace('_', ' ', $source)) }}</span>
                                    <span class="font-mono" style="color:var(--text-secondary)">R {{ number_format($cost, 2) }}</span>
                                </div>
                                <div class="h-2 rounded" style="background:var(--surface-2)">
                                    <div class="h-full rounded"
                                         style="width: {{ ($cost / $maxBySource) * 100 }}%; background: var(--brand-icon)"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="hfc-card">
            <div class="px-4 py-3 border-b" style="border-color:var(--border)">
                <h2 class="text-sm font-semibold uppercase tracking-wider" style="color:var(--brand-icon)">
                    Top consumers (agencies)
                </h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wider"
                            style="color:var(--text-secondary); background:var(--surface-2)">
                            <th class="px-4 py-2">Agency</th>
                            <th class="px-4 py-2 text-right">Spend</th>
                            <th class="px-4 py-2 text-right">Generations</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($topAgencies as $row)
                            <tr class="border-t" style="border-color:var(--border)">
                                <td class="px-4 py-2" style="color:var(--text-primary)">{{ $row['agency_name'] }}</td>
                                <td class="px-4 py-2 text-right font-mono" style="color:var(--text-primary)">
                                    R {{ number_format($row['cost_zar'], 2) }}
                                </td>
                                <td class="px-4 py-2 text-right" style="color:var(--text-secondary)">
                                    {{ number_format($row['generations']) }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-4 text-sm" style="color:var(--text-secondary)">No data.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Per-agency budgets --}}
    <div class="hfc-card mb-6">
        <div class="px-4 py-3 border-b flex items-center justify-between" style="border-color:var(--border)">
            <h2 class="text-sm font-semibold uppercase tracking-wider" style="color:var(--brand-icon)">
                Per-agency budgets
            </h2>
            @unless($canEditBudgets)
                <span class="text-xs" style="color:var(--text-secondary)">View-only — super_admin required to edit.</span>
            @endunless
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider"
                        style="color:var(--text-secondary); background:var(--surface-2)">
                        <th class="px-4 py-2">Agency</th>
                        <th class="px-4 py-2 text-right">Budget (R)</th>
                        <th class="px-4 py-2 text-right">Used</th>
                        <th class="px-4 py-2 text-right">%</th>
                        <th class="px-4 py-2">Status</th>
                        <th class="px-4 py-2 text-right">Warn / Hard cap</th>
                        <th class="px-4 py-2">Overage</th>
                        @if($canEditBudgets)
                            <th class="px-4 py-2"></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($agencies as $a)
                        <tr class="border-t" style="border-color:var(--border)">
                            @if($canEditBudgets)
                                <form method="POST" action="{{ route('admin.ai-usage.budget.update', ['agency' => $a['id']]) }}">
                                    @csrf
                                    <td class="px-4 py-2" style="color:var(--text-primary)">{{ $a['name'] }}</td>
                                    <td class="px-4 py-2 text-right">
                                        <input type="number" step="0.01" min="0" name="ai_monthly_budget_zar"
                                               value="{{ number_format($a['budget_zar'], 2, '.', '') }}"
                                               class="w-28 text-right rounded px-2 py-1 text-sm font-mono"
                                               style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border)">
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono" style="color:var(--text-primary)">
                                        R {{ number_format($a['used_zar'], 2) }}
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono"
                                        style="color: {{ $a['used_pct'] >= 95 ? '#dc2626' : ($a['used_pct'] >= 80 ? '#d97706' : 'var(--text-primary)') }}">
                                        {{ number_format($a['used_pct'], 1) }}%
                                    </td>
                                    <td class="px-4 py-2">
                                        @php
                                            $statusColors = [
                                                'healthy'  => '#16a34a',
                                                'warning'  => '#d97706',
                                                'critical' => '#dc2626',
                                                'capped'   => '#7f1d1d',
                                            ];
                                            $statusColor = $statusColors[$a['status']] ?? 'var(--text-secondary)';
                                        @endphp
                                        <span class="inline-block px-2 py-0.5 text-xs rounded font-semibold uppercase tracking-wider"
                                              style="background: {{ $statusColor }}; color:#fff">
                                            {{ $a['status'] }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        <input type="number" min="0" max="100" name="ai_budget_warning_pct"
                                               value="{{ $a['warning_pct'] }}"
                                               class="w-12 text-right rounded px-1 py-1 text-xs font-mono"
                                               style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border)">
                                        /
                                        <input type="number" min="50" max="200" name="ai_budget_hard_cap_pct"
                                               value="{{ $a['hard_cap_pct'] }}"
                                               class="w-12 text-right rounded px-1 py-1 text-xs font-mono"
                                               style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border)">
                                    </td>
                                    <td class="px-4 py-2">
                                        <label class="inline-flex items-center gap-1 text-xs">
                                            <input type="checkbox" name="ai_budget_overage_allowed" value="1"
                                                   {{ $a['overage_allowed'] ? 'checked' : '' }}>
                                            allow
                                        </label>
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        <button type="submit" class="text-xs px-2 py-1 rounded"
                                                style="background:var(--brand-icon); color:#fff">Save</button>
                                    </td>
                                </form>
                            @else
                                <td class="px-4 py-2" style="color:var(--text-primary)">{{ $a['name'] }}</td>
                                <td class="px-4 py-2 text-right font-mono" style="color:var(--text-primary)">
                                    R {{ number_format($a['budget_zar'], 2) }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono" style="color:var(--text-primary)">
                                    R {{ number_format($a['used_zar'], 2) }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono"
                                    style="color: {{ $a['used_pct'] >= 95 ? '#dc2626' : ($a['used_pct'] >= 80 ? '#d97706' : 'var(--text-primary)') }}">
                                    {{ number_format($a['used_pct'], 1) }}%
                                </td>
                                <td class="px-4 py-2">
                                    <span class="inline-block px-2 py-0.5 text-xs rounded font-semibold uppercase tracking-wider"
                                          style="background: var(--surface-2); color:var(--text-primary)">
                                        {{ $a['status'] }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-right text-xs font-mono" style="color:var(--text-secondary)">
                                    {{ $a['warning_pct'] }}% / {{ $a['hard_cap_pct'] }}%
                                </td>
                                <td class="px-4 py-2 text-xs" style="color:var(--text-secondary)">
                                    {{ $a['overage_allowed'] ? 'allowed' : 'blocked' }}
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
