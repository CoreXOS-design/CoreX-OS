{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Commission Overview</h1>
                <p class="text-sm text-white/60">Agency-wide commission performance, revenue share, and P&amp;L.</p>
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         TOP CARDS ROW
         ══════════════════════════════════════ --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

        {{-- Card 1: Agency GCI --}}
        <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xs font-semibold uppercase tracking-wider mb-1" style="color: var(--text-muted);">Agency GCI</div>
            <div class="text-[1.625rem] font-semibold leading-tight" style="color: var(--text-primary);">R {{ number_format($agencyGCIMonth, 0) }}</div>
            <div class="text-xs mt-1" style="color: var(--text-secondary);">YTD: R {{ number_format($agencyGCIYear, 0) }}</div>
        </div>

        {{-- Card 2: Company Dollar --}}
        <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xs font-semibold uppercase tracking-wider mb-1" style="color: var(--text-muted);">Company Dollar</div>
            <div class="text-[1.625rem] font-semibold leading-tight" style="color: var(--text-primary);">R {{ number_format($companyDollarMonth, 0) }}</div>
            <div class="text-xs mt-1" style="color: var(--text-secondary);">YTD: R {{ number_format($companyDollarYear, 0) }}</div>
        </div>

        {{-- Card 3: Rev Share Paid --}}
        <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xs font-semibold uppercase tracking-wider mb-1" style="color: var(--text-muted);">Rev Share Paid</div>
            <div class="text-[1.625rem] font-semibold leading-tight" style="color: var(--ds-green);">R {{ number_format($revSharePaidMonth, 0) }}</div>
            <div class="text-xs mt-1" style="color: var(--text-secondary);">YTD: R {{ number_format($revSharePaidYear, 0) }}</div>
        </div>

        {{-- Card 4: Net Agency (loss = danger state per spec §1.5) --}}
        <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xs font-semibold uppercase tracking-wider mb-1" style="color: var(--text-muted);">Net Agency</div>
            <div class="text-[1.625rem] font-semibold leading-tight" style="color: {{ $netAgencyMonth >= 0 ? 'var(--ds-green)' : 'var(--ds-crimson)' }};">R {{ number_format($netAgencyMonth, 0) }}</div>
            <div class="text-xs mt-1" style="color: var(--text-secondary);">YTD: R {{ number_format($netAgencyYear, 0) }}</div>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         MONTHLY AGENCY CHART
         ══════════════════════════════════════ --}}
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h3 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Monthly Agency Revenue — Last 12 Months</h3>
        <div style="position: relative; height: 300px;">
            <canvas id="agencyChart"></canvas>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         AGENT PERFORMANCE TABLE
         ══════════════════════════════════════ --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);">
            <h3 class="text-lg font-semibold" style="color: var(--text-primary);">Agent Performance</h3>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">#</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">GCI Month</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">GCI YTD</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Cap Status</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Deals</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Rev Share</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($agents as $idx => $agent)
                        @php
                            $rank = $idx + 1;
                            // Rank medal colours: gold (token), silver (token), bronze (no token equivalent — documented exception)
                            $rankColor = match(true) {
                                $rank === 1 && $agent['gci_month'] > 0 => 'var(--ds-amber, #f59e0b)',
                                $rank === 2 && $agent['gci_month'] > 0 => 'var(--text-muted, #94a3b8)',
                                $rank === 3 && $agent['gci_month'] > 0 => '#cd7f32',
                                default => 'var(--text-muted)',
                            };
                        @endphp
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-3 text-center">
                                <span class="text-xs font-bold" style="color: {{ $rankColor }};">{{ $rank }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <a href="{{ route('commission.dashboard') }}?agent={{ $agent['id'] }}"
                                   class="text-sm font-medium hover:underline"
                                   style="color: var(--text-primary);">
                                    {{ $agent['name'] }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap {{ $rank <= 3 && $agent['gci_month'] > 0 ? 'font-semibold' : '' }}" style="color: var(--text-primary);">
                                R {{ number_format($agent['gci_month'], 0) }}
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap" style="color: var(--text-secondary);">
                                R {{ number_format($agent['gci_year'], 0) }}
                            </td>
                            <td class="px-4 py-3 text-center whitespace-nowrap">
                                @if($agent['is_capped'])
                                    <span class="ds-badge ds-badge-warning">Capped</span>
                                @else
                                    <div class="flex items-center gap-2 justify-center">
                                        <div class="ds-progress-track" style="width: 4rem;">
                                            <div class="ds-progress-bar ds-bar-navy" style="width: {{ $agent['cap_percent'] }}%;"></div>
                                        </div>
                                        <span class="text-xs" style="color: var(--text-secondary);">{{ number_format($agent['cap_percent'], 0) }}%</span>
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center" style="color: var(--text-secondary);">
                                {{ number_format($agent['tx_count']) }}
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap" style="color: var(--ds-green);">
                                R {{ number_format($agent['rev_share_earned'], 0) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                No agents with commission data yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         REVENUE SHARE TREE
         ══════════════════════════════════════ --}}
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);"
         x-data="{ expandedNodes: {} }">
        <h3 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Sponsorship Tree</h3>

        @if(empty($sponsorshipTree))
            <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                    </svg>
                </div>
                <h4 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No sponsorship relationships yet</h4>
                <p class="text-sm" style="color: var(--text-muted);">When agents sponsor others, the tree will appear here.</p>
            </div>
        @else
            <div class="space-y-1">
                @foreach($sponsorshipTree as $node)
                    @include('commission._tree-node', ['node' => $node, 'depth' => 0])
                @endforeach
            </div>
        @endif
    </div>

    {{-- ══════════════════════════════════════
         MONTHLY P&L SUMMARY
         ══════════════════════════════════════ --}}
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h3 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Year-to-Date P&amp;L Summary</h3>

        <div class="space-y-2 max-w-md">
            <div class="flex items-center justify-between py-1.5" style="border-bottom: 1px solid var(--border);">
                <span class="text-sm" style="color: var(--text-secondary);">Total GCI</span>
                <span class="text-sm font-semibold" style="color: var(--text-primary);">R {{ number_format($pnl['total_gci'], 0) }}</span>
            </div>
            <div class="flex items-center justify-between py-1.5" style="border-bottom: 1px solid var(--border);">
                <span class="text-sm" style="color: var(--text-secondary);">Less: Agent Splits</span>
                <span class="text-sm" style="color: var(--text-secondary);">(R {{ number_format($pnl['agent_splits'], 0) }})</span>
            </div>
            <div class="flex items-center justify-between py-1.5" style="border-bottom: 1px solid var(--border);">
                <span class="text-sm" style="color: var(--text-secondary);">Less: Revenue Share</span>
                <span class="text-sm" style="color: var(--text-secondary);">(R {{ number_format($pnl['rev_share'], 0) }})</span>
            </div>
            <div class="flex items-center justify-between py-1.5" style="border-bottom: 1px solid var(--border);">
                <span class="text-sm" style="color: var(--text-secondary);">Less: Platform Costs ({{ number_format($activeAgentCount) }} agents)</span>
                <span class="text-sm" style="color: var(--text-secondary);">(R {{ number_format($pnl['platform_costs'], 0) }})</span>
            </div>
            <div class="flex items-center justify-between py-2 mt-1" style="border-top: 2px solid var(--border);">
                <span class="text-sm font-bold" style="color: var(--text-primary);">Net Agency Revenue</span>
                <span class="text-lg font-bold" style="color: {{ $pnl['net_revenue'] >= 0 ? 'var(--ds-green)' : 'var(--ds-crimson)' }};">
                    R {{ number_format($pnl['net_revenue'], 0) }}
                </span>
            </div>
        </div>
    </div>

</div>

{{-- Chart.js --}}
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('agencyChart');
    if (!ctx) return;

    const monthlyData = @json($monthlyData);
    const rootStyle = getComputedStyle(document.documentElement);
    const textColor = rootStyle.getPropertyValue('--text-secondary').trim() || '#94a3b8';
    const borderColor = rootStyle.getPropertyValue('--border').trim() || '#334155';
    const brandIcon = rootStyle.getPropertyValue('--brand-icon').trim() || '#0ea5e9';
    const dsGreen = rootStyle.getPropertyValue('--ds-green').trim() || '#059669';

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: monthlyData.map(d => d.label),
            datasets: [
                {
                    label: 'Company Dollar',
                    data: monthlyData.map(d => d.companyDollar),
                    backgroundColor: brandIcon + 'B3',
                    borderColor: brandIcon,
                    borderWidth: 1,
                    borderRadius: 4,
                    order: 2,
                },
                {
                    label: 'Rev Share',
                    data: monthlyData.map(d => d.revShare),
                    backgroundColor: dsGreen + 'B3',
                    borderColor: dsGreen,
                    borderWidth: 1,
                    borderRadius: 4,
                    order: 3,
                },
                {
                    label: 'Net Agency',
                    data: monthlyData.map(d => d.netAgency),
                    type: 'line',
                    borderColor: dsGreen,
                    backgroundColor: dsGreen + '1A',
                    borderWidth: 2,
                    pointRadius: 3,
                    pointBackgroundColor: dsGreen,
                    tension: 0.3,
                    fill: false,
                    order: 1,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    align: 'end',
                    labels: {
                        color: textColor,
                        font: { size: 11 },
                        boxWidth: 12,
                        boxHeight: 12,
                        padding: 16,
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': R ' + Math.round(context.parsed.y).toLocaleString('en-ZA');
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    grid: { display: false },
                    ticks: { color: textColor, font: { size: 11 } },
                },
                y: {
                    stacked: false,
                    grid: { color: borderColor + '40' },
                    ticks: {
                        color: textColor,
                        font: { size: 11 },
                        callback: function(value) {
                            if (value >= 1000) return 'R ' + (value / 1000).toFixed(0) + 'k';
                            return 'R ' + value;
                        }
                    },
                    beginAtZero: true,
                }
            },
            interaction: {
                intersect: false,
                mode: 'index',
            }
        }
    });
});
</script>
@endsection
