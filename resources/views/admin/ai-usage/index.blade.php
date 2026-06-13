{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="max-w-7xl mx-auto w-full space-y-5">

    {{-- Page header (branded) --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">AI Usage &amp; Cost</h1>
                <p class="text-sm text-white/60">Anthropic spend, tokens, cache health and per-agency budgets — {{ $monthLabel }}. Click an agency to see where and who the spend comes from.</p>
            </div>
            <form method="GET" class="flex items-end gap-2">
                <div>
                    <label class="block text-xs font-medium mb-1 text-white/60">Month</label>
                    <input type="month" name="month" value="{{ $month }}" class="rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                </div>
                <button type="submit" class="corex-btn-primary text-sm">View</button>
            </form>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background:color-mix(in srgb, var(--ds-green,#059669) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green,#059669) 30%, transparent); color:var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif

    {{-- Hero metrics --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        @foreach([
            ['Total spend', 'R ' . number_format($totalZar, 2)],
            ['Input tokens', number_format($tokens['input'])],
            ['Output tokens', number_format($tokens['output'])],
            ['Cache hit rate (30d)', number_format($cacheHitRate30, 1) . '%'],
        ] as [$label, $value])
        <div class="rounded-md p-4" style="background:var(--surface); border:1px solid var(--border);">
            <div class="text-xs font-medium uppercase tracking-wider" style="color:var(--text-muted);">{{ $label }}</div>
            <div class="font-semibold mt-1" style="color:var(--text-primary); font-size:1.625rem;">{{ $value }}</div>
        </div>
        @endforeach
    </div>

    {{-- Cache footprint --}}
    <div class="rounded-md p-4" style="background:var(--surface); border:1px solid var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wider mb-2" style="color:var(--text-muted);">Cache footprint</div>
        <div class="grid grid-cols-3 gap-4 text-sm">
            <div>
                <div class="text-xs" style="color:var(--text-secondary);">Active rows</div>
                <div class="font-semibold" style="color:var(--text-primary);">{{ number_format($cacheStats['active_rows']) }}</div>
            </div>
            <div>
                <div class="text-xs" style="color:var(--text-secondary);">Soft-deleted (awaiting purge)</div>
                <div class="font-semibold" style="color:var(--text-primary);">{{ number_format($cacheStats['soft_deleted']) }}</div>
            </div>
            <div>
                <div class="text-xs" style="color:var(--text-secondary);">Expired (not yet swept)</div>
                <div class="font-semibold" style="color:var(--text-primary);">{{ number_format($cacheStats['expired_active']) }}</div>
            </div>
        </div>
    </div>

    {{-- Daily burn --}}
    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <div class="px-4 py-3 border-b" style="border-color:var(--border);">
            <h2 class="text-sm font-semibold" style="color:var(--text-primary);">Daily burn — {{ $monthLabel }}</h2>
        </div>
        <div class="p-4">
            @if(empty($dailyBurn))
                <p class="text-sm" style="color:var(--text-muted);">No spend recorded for this month.</p>
            @else
                @php $maxDaily = max(array_map(fn ($d) => $d['cost_zar'], $dailyBurn)) ?: 1; @endphp
                <div class="space-y-1">
                    @foreach($dailyBurn as $d)
                        <div class="flex items-center gap-3 text-xs">
                            <span class="w-24 font-mono" style="color:var(--text-secondary);">{{ $d['day'] }}</span>
                            <div class="flex-1 ds-progress-track">
                                <div class="ds-progress-bar ds-bar-navy" style="width: {{ max(2, ($d['cost_zar'] / $maxDaily) * 100) }}%;"></div>
                            </div>
                            <span class="w-24 text-right font-mono" style="color:var(--text-primary);">R {{ number_format($d['cost_zar'], 2) }}</span>
                            <span class="w-16 text-right" style="color:var(--text-secondary);">{{ number_format($d['generations']) }} calls</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- By source + Top agencies --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
            <div class="px-4 py-3 border-b" style="border-color:var(--border);">
                <h2 class="text-sm font-semibold" style="color:var(--text-primary);">Spend by source</h2>
            </div>
            <div class="p-4">
                @if(empty($bySource))
                    <p class="text-sm" style="color:var(--text-muted);">No data.</p>
                @else
                    @php $maxBySource = max($bySource) ?: 1; @endphp
                    <div class="space-y-2">
                        @foreach($bySource as $source => $cost)
                            <div>
                                <div class="flex justify-between text-xs mb-1">
                                    <span style="color:var(--text-primary);">{{ ucwords(str_replace('_', ' ', $source)) }}</span>
                                    <span class="font-mono" style="color:var(--text-secondary);">R {{ number_format($cost, 2) }}</span>
                                </div>
                                <div class="ds-progress-track">
                                    <div class="ds-progress-bar ds-bar-navy" style="width: {{ ($cost / $maxBySource) * 100 }}%;"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
            <div class="px-4 py-3 border-b" style="border-color:var(--border);">
                <h2 class="text-sm font-semibold" style="color:var(--text-primary);">Top consumers (agencies)</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background:var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Agency</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Spend</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Generations</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($topAgencies as $row)
                            <tr style="border-top:1px solid var(--border);">
                                <td class="px-4 py-3">
                                    @if($row['agency_id'])
                                        <a href="{{ route('admin.ai-usage.agency', ['agency' => $row['agency_id'], 'month' => $month]) }}"
                                           class="font-semibold" style="color:var(--brand-icon,#0ea5e9);">{{ $row['agency_name'] }}</a>
                                    @else
                                        <span style="color:var(--text-primary);">{{ $row['agency_name'] }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-mono" style="color:var(--text-primary);">R {{ number_format($row['cost_zar'], 2) }}</td>
                                <td class="px-4 py-3 text-right" style="color:var(--text-secondary);">{{ number_format($row['generations']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-12 text-center text-sm" style="color:var(--text-muted);">No data.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Per-agency budgets --}}
    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <div class="px-4 py-3 border-b flex items-center justify-between" style="border-color:var(--border);">
            <h2 class="text-sm font-semibold" style="color:var(--text-primary);">Per-agency budgets</h2>
            @unless($canEditBudgets)
                <span class="text-xs" style="color:var(--text-muted);">View-only — super_admin required to edit.</span>
            @endunless
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background:var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Agency</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Budget (R)</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Used</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">%</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Status</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Warn / Hard cap</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Overage</th>
                        @if($canEditBudgets)<th class="px-4 py-2.5"></th>@endif
                    </tr>
                </thead>
                <tbody>
                    @php
                        $statusBadge = [
                            'healthy'  => 'ds-badge-success',
                            'warning'  => 'ds-badge-warning',
                            'critical' => 'ds-badge-danger',
                            'capped'   => 'ds-badge-danger',
                        ];
                    @endphp
                    @foreach($agencies as $a)
                        @php
                            $pctColor = $a['used_pct'] >= 95 ? 'var(--ds-crimson,#c41e3a)' : ($a['used_pct'] >= 80 ? 'var(--ds-amber,#f59e0b)' : 'var(--text-primary)');
                            $agencyLink = route('admin.ai-usage.agency', ['agency' => $a['id'], 'month' => $month]);
                        @endphp
                        <tr style="border-top:1px solid var(--border);">
                            @if($canEditBudgets)
                                <form method="POST" action="{{ route('admin.ai-usage.budget.update', ['agency' => $a['id']]) }}">
                                    @csrf
                                    <td class="px-4 py-3"><a href="{{ $agencyLink }}" class="font-semibold" style="color:var(--brand-icon,#0ea5e9);">{{ $a['name'] }}</a></td>
                                    <td class="px-4 py-3 text-right">
                                        <input type="number" step="0.01" min="0" name="ai_monthly_budget_zar"
                                               value="{{ number_format($a['budget_zar'], 2, '.', '') }}"
                                               class="w-28 text-right rounded-md px-2 py-1 text-sm font-mono"
                                               style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono" style="color:var(--text-primary);">R {{ number_format($a['used_zar'], 2) }}</td>
                                    <td class="px-4 py-3 text-right font-mono" style="color: {{ $pctColor }};">{{ number_format($a['used_pct'], 1) }}%</td>
                                    <td class="px-4 py-3"><span class="ds-badge {{ $statusBadge[$a['status']] ?? 'ds-badge-default' }}">{{ ucfirst($a['status']) }}</span></td>
                                    <td class="px-4 py-3 text-right">
                                        <input type="number" min="0" max="100" name="ai_budget_warning_pct" value="{{ $a['warning_pct'] }}"
                                               class="w-12 text-right rounded-md px-1 py-1 text-xs font-mono"
                                               style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">
                                        /
                                        <input type="number" min="50" max="200" name="ai_budget_hard_cap_pct" value="{{ $a['hard_cap_pct'] }}"
                                               class="w-12 text-right rounded-md px-1 py-1 text-xs font-mono"
                                               style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">
                                    </td>
                                    <td class="px-4 py-3">
                                        <label class="inline-flex items-center gap-1 text-xs" style="color:var(--text-secondary);">
                                            <input type="checkbox" name="ai_budget_overage_allowed" value="1" {{ $a['overage_allowed'] ? 'checked' : '' }}
                                                   style="accent-color:var(--brand-button,#0ea5e9);"> allow
                                        </label>
                                    </td>
                                    <td class="px-4 py-3 text-right"><button type="submit" class="corex-btn-primary text-xs">Save</button></td>
                                </form>
                            @else
                                <td class="px-4 py-3"><a href="{{ $agencyLink }}" class="font-semibold" style="color:var(--brand-icon,#0ea5e9);">{{ $a['name'] }}</a></td>
                                <td class="px-4 py-3 text-right font-mono" style="color:var(--text-primary);">R {{ number_format($a['budget_zar'], 2) }}</td>
                                <td class="px-4 py-3 text-right font-mono" style="color:var(--text-primary);">R {{ number_format($a['used_zar'], 2) }}</td>
                                <td class="px-4 py-3 text-right font-mono" style="color: {{ $pctColor }};">{{ number_format($a['used_pct'], 1) }}%</td>
                                <td class="px-4 py-3"><span class="ds-badge {{ $statusBadge[$a['status']] ?? 'ds-badge-default' }}">{{ ucfirst($a['status']) }}</span></td>
                                <td class="px-4 py-3 text-right text-xs font-mono" style="color:var(--text-secondary);">{{ $a['warning_pct'] }}% / {{ $a['hard_cap_pct'] }}%</td>
                                <td class="px-4 py-3 text-xs" style="color:var(--text-secondary);">{{ $a['overage_allowed'] ? 'allowed' : 'blocked' }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
