{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">AI Usage &amp; Cost</h1>
                <p class="text-xs" style="color: var(--text-muted);">Anthropic spend, tokens, cache health and AI Spend Safety — {{ $monthLabel }}. Click an agency to see where and who the spend comes from.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <form method="GET" class="flex items-center gap-2">
                    <label class="text-xs font-medium" style="color: var(--text-muted);">Month</label>
                    <input type="month" name="month" value="{{ $month }}" class="rounded-md px-3 py-1.5 text-xs"
                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    <button type="submit" class="corex-btn-primary text-xs">View</button>
                </form>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background:color-mix(in srgb, var(--ds-green,#059669) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green,#059669) 30%, transparent); color:var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="color:var(--ds-green,#059669);">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif

    {{-- Hero metrics --}}
    <div class="corex-kpi-grid">
        <x-corex-kpi-card title="Total spend" :value="'R ' . number_format($totalZar, 2)" />
        <x-corex-kpi-card title="Input tokens" :value="number_format($tokens['input'])" />
        <x-corex-kpi-card title="Output tokens" :value="number_format($tokens['output'])" />
        <x-corex-kpi-card title="Cache hit rate (30d)" :value="number_format($cacheHitRate30, 1) . '%'" />
    </div>

    {{-- Cache footprint --}}
    <div class="rounded-md p-4" style="background:var(--surface); border:1px solid var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wider mb-2" style="color:var(--text-muted);">Cache footprint</div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
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
                                <div class="ds-progress-bar" style="width: {{ max(2, ($d['cost_zar'] / $maxDaily) * 100) }}%; background: var(--brand-icon);"></div>
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
                                    <div class="ds-progress-bar" style="width: {{ ($cost / $maxBySource) * 100 }}%; background: var(--brand-icon);"></div>
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

    {{-- AI Spend Safety (AT-456 §A.5) — replaces the retired per-agency budgets.
         Thresholds live in Dev Settings → AI Spend Safety; this is where an owner
         sees who is close to them and turns a paused agency back on. --}}
    @php
        $statusBadge = [
            'active'  => ['ds-badge-success', 'Active'],
            'alerted' => ['ds-badge-warning', 'Alerted'],
            'paused'  => ['ds-badge-danger',  'Paused'],
        ];
        $hasFilters = $filters['search'] !== '' || $filters['status'] !== 'all';
    @endphp
    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <div class="px-4 py-3 border-b flex flex-col md:flex-row md:items-center md:justify-between gap-2" style="border-color:var(--border);">
            <div>
                <h2 class="text-sm font-semibold" style="color:var(--text-primary);">AI Spend Safety</h2>
                <p class="text-xs" style="color:var(--text-muted);">
                    Alert email at R {{ number_format($guard['alert_zar'], 2) }} · AI switched off at R {{ number_format($guard['cutout_zar'], 2) }}
                    · counted over the last {{ $guard['window_hours'] }} hours per agency.
                </p>
            </div>
            <a href="{{ route('admin.dev-settings.index', ['s' => 'ai_spend']) }}" class="corex-btn-outline text-xs">Change thresholds</a>
        </div>

        {{-- Platform (no-agency) AI — e.g. the Article Library. Its own window and its own switch. --}}
        <div class="px-4 py-3 flex flex-wrap items-center justify-between gap-3" style="border-bottom:1px solid var(--border); background:var(--surface-2);">
            <div class="text-sm" style="color:var(--text-primary);">
                <span class="font-semibold">Platform AI</span>
                <span class="text-xs" style="color:var(--text-muted);">(AI no agency owns)</span>
                — R {{ number_format($system['spend_zar'], 2) }} in the last {{ $guard['window_hours'] }}h
                <span class="ds-badge {{ $statusBadge[$system['status']][0] }} ml-1">{{ $statusBadge[$system['status']][1] }}</span>
            </div>
            @if($system['status'] === 'paused')
                <form method="POST" action="{{ route('admin.ai-usage.system.resume') }}"
                      onsubmit="return confirm('Turn platform AI back on? Spend will be counted afresh from now.');">
                    @csrf
                    <button type="submit" class="corex-btn-primary text-xs">Turn AI back on</button>
                </form>
            @endif
        </div>

        {{-- Search + filters --}}
        <form method="GET" action="{{ route('admin.ai-usage.index') }}" class="px-4 py-3 flex flex-wrap items-center gap-3" style="border-bottom:1px solid var(--border);">
            <input type="hidden" name="month" value="{{ $month }}">
            <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
            <input type="hidden" name="direction" value="{{ $filters['direction'] }}">
            <div class="relative flex-1 min-w-[12rem] max-w-sm">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none" style="color: var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                </svg>
                <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search agency name…"
                       class="w-full pl-10 pr-3 py-2 text-sm rounded-md focus:outline-none"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <select name="status" onchange="this.form.submit()" class="rounded-md px-3 py-2 text-sm"
                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="all" @selected($filters['status'] === 'all')>All statuses</option>
                <option value="active" @selected($filters['status'] === 'active')>Active</option>
                <option value="alerted" @selected($filters['status'] === 'alerted')>Alerted</option>
                <option value="paused" @selected($filters['status'] === 'paused')>Paused</option>
            </select>
            <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>
            @if($hasFilters)
                <a href="{{ route('admin.ai-usage.index', ['month' => $month]) }}" class="text-xs font-semibold" style="color:var(--brand-icon,#0ea5e9);">Clear filters</a>
            @endif
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background:var(--surface-2);">
                        <x-sort-header field="name" label="Agency" :current-sort="$filters['sort']" :current-direction="$filters['direction']" />
                        <x-sort-header field="spend_24h" label="Spend — last {{ $guard['window_hours'] }}h" align="right" :current-sort="$filters['sort']" :current-direction="$filters['direction']" />
                        <x-sort-header field="spend_month" label="Spend — {{ $monthLabel }}" align="right" :current-sort="$filters['sort']" :current-direction="$filters['direction']" />
                        <x-sort-header field="status" label="Status" :current-sort="$filters['sort']" :current-direction="$filters['direction']" />
                        <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($agencies as $a)
                        @php
                            $spendColor = $a['spend_24h'] >= $guard['cutout_zar']
                                ? 'var(--ds-crimson,#c41e3a)'
                                : ($a['spend_24h'] >= $guard['alert_zar'] ? 'var(--ds-amber,#f59e0b)' : 'var(--text-primary)');
                        @endphp
                        <tr style="border-top:1px solid var(--border);">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.ai-usage.agency', ['agency' => $a['id'], 'month' => $month]) }}"
                                   class="font-semibold" style="color:var(--brand-icon,#0ea5e9);">{{ $a['name'] }}</a>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <span class="font-mono" style="color: {{ $spendColor }};">R {{ number_format($a['spend_24h'], 2) }}</span>
                                @if($a['since_resume'])
                                    <div class="text-[11px]" style="color:var(--text-muted);">since turned back on {{ $a['window_start']->format('d M H:i') }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-mono" style="color:var(--text-primary);">R {{ number_format($a['spend_month'], 2) }}</td>
                            <td class="px-4 py-3">
                                <span class="ds-badge {{ $statusBadge[$a['status']][0] }}">{{ $statusBadge[$a['status']][1] }}</span>
                                @if($a['status'] === 'paused' && $a['paused_at'])
                                    <div class="text-[11px] mt-1" style="color:var(--text-muted);">since {{ $a['paused_at']->format('d M H:i') }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @if($a['status'] === 'paused')
                                    <form method="POST" action="{{ route('admin.ai-usage.resume', ['agency' => $a['id']]) }}" class="inline"
                                          onsubmit="return confirm(@js('Turn AI back on for ' . $a['name'] . '? Spend will be counted afresh from now.'));">
                                        @csrf
                                        <button type="submit" class="corex-btn-primary text-xs">Turn AI back on</button>
                                    </form>
                                @endif
                                <a href="{{ route('admin.ai-usage.agency', ['agency' => $a['id'], 'month' => $month]) }}" class="corex-btn-outline text-xs">View detail</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-12 text-center text-sm" style="color:var(--text-muted);">
                                @if($hasFilters)
                                    No agencies match these filters.
                                    <a href="{{ route('admin.ai-usage.index', ['month' => $month]) }}" class="font-semibold" style="color:var(--brand-icon,#0ea5e9);">Clear filters</a>
                                @else
                                    No agencies yet.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($agencies->hasPages())
            <div class="px-4 py-3" style="border-top:1px solid var(--border);">{{ $agencies->links() }}</div>
        @endif
    </div>
</div>
@endsection
