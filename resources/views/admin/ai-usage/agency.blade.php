{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
@php
    $maxSource = collect($bySource)->max('cost') ?: 1;
    $maxUser   = collect($byUser)->max('cost') ?: 1;
@endphp
<div class="w-full space-y-5">

    {{-- Page header (branded) --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <a href="{{ route('admin.ai-usage.index', ['month' => $month]) }}" class="text-xs text-white/60 hover:text-white transition-colors">&larr; AI Usage &amp; Cost</a>
                <h1 class="text-xl font-bold text-white leading-tight mt-1">{{ $agency->name }}</h1>
                <p class="text-sm text-white/60">Where the AI spend came from and who drove it — {{ $monthLabel }}.</p>
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

    {{-- KPI tiles --}}
    <div class="corex-kpi-grid">
        <x-corex-kpi-card title="Total spend" :value="'R ' . number_format($totals['cost'], 2)" />
        <x-corex-kpi-card title="Generations" :value="number_format($totals['gens'])" />
        <x-corex-kpi-card title="Input tokens" :value="number_format($totals['inp'])" />
        <x-corex-kpi-card title="Output tokens" :value="number_format($totals['outp'])" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {{-- WHERE — by source --}}
        <div class="rounded-md" style="background:var(--surface); border:1px solid var(--border);">
            <div class="px-4 py-3 border-b" style="border-color:var(--border);">
                <h2 class="text-sm font-semibold" style="color:var(--text-primary);">Where it comes from <span class="font-normal" style="color:var(--text-muted);">— by source</span></h2>
            </div>
            <div class="p-4">
                @forelse($bySource as $s)
                <div class="mb-3 last:mb-0">
                    <div class="flex justify-between text-xs mb-1">
                        <span style="color:var(--text-primary);">{{ ucwords(str_replace('_', ' ', $s['source'])) }}</span>
                        <span class="font-mono" style="color:var(--text-secondary);">R {{ number_format($s['cost'], 2) }} · {{ number_format($s['gens']) }}</span>
                    </div>
                    <div class="ds-progress-track">
                        <div class="ds-progress-bar ds-bar-navy" style="width: {{ max(2, ($s['cost'] / $maxSource) * 100) }}%;"></div>
                    </div>
                </div>
                @empty
                <p class="text-sm" style="color:var(--text-muted);">No AI usage recorded this month.</p>
                @endforelse
            </div>
        </div>

        {{-- WHO — by user --}}
        <div class="rounded-md" style="background:var(--surface); border:1px solid var(--border);">
            <div class="px-4 py-3 border-b" style="border-color:var(--border);">
                <h2 class="text-sm font-semibold" style="color:var(--text-primary);">Who it comes from <span class="font-normal" style="color:var(--text-muted);">— by user</span></h2>
            </div>
            <div class="p-4">
                @forelse($byUser as $u)
                <div class="mb-3 last:mb-0">
                    <div class="flex justify-between text-xs mb-1">
                        <span style="color:var(--text-primary);">{{ $u['name'] }}</span>
                        <span class="font-mono" style="color:var(--text-secondary);">R {{ number_format($u['cost'], 2) }} · {{ number_format($u['gens']) }}</span>
                    </div>
                    <div class="ds-progress-track">
                        <div class="ds-progress-bar ds-bar-amber" style="width: {{ max(2, ($u['cost'] / $maxUser) * 100) }}%;"></div>
                    </div>
                </div>
                @empty
                <p class="text-sm" style="color:var(--text-muted);">No AI usage recorded this month.</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Recent calls --}}
    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <div class="px-4 py-3 border-b" style="border-color:var(--border);">
            <h2 class="text-sm font-semibold" style="color:var(--text-primary);">Recent calls <span class="font-normal" style="color:var(--text-muted);">— latest 100</span></h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background:var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Time</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">User</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Source</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Surface</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Model</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Tokens (in/out)</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Cost</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recent as $r)
                    <tr style="border-top:1px solid var(--border);">
                        <td class="px-4 py-3 whitespace-nowrap" style="color:var(--text-secondary);">{{ \Illuminate\Support\Carbon::parse($r['occurred_at'])->format('d M H:i') }}</td>
                        <td class="px-4 py-3" style="color:var(--text-primary);">{{ $r['user'] }}</td>
                        <td class="px-4 py-3">
                            <span class="ds-badge ds-badge-info">{{ ucwords(str_replace('_', ' ', $r['source'])) }}</span>
                            @if($r['fallback'])<span class="ds-badge ds-badge-warning">Fallback</span>@elseif($r['cache_hit'])<span class="ds-badge ds-badge-default">Cache</span>@endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs" style="color:var(--text-muted);">{{ $r['surface'] ?: '—' }}</td>
                        <td class="px-4 py-3 font-mono text-xs" style="color:var(--text-secondary);">{{ $r['model'] }}</td>
                        <td class="px-4 py-3 text-right font-mono" style="color:var(--text-secondary);">{{ number_format($r['input']) }} / {{ number_format($r['output']) }}</td>
                        <td class="px-4 py-3 text-right font-mono" style="color:var(--text-primary);">R {{ number_format($r['cost'], 2) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="px-4 py-12 text-center text-sm" style="color:var(--text-muted);">No AI calls recorded for {{ $agency->name }} in {{ $monthLabel }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
