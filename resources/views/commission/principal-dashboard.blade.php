@extends('layouts.corex')

@section('corex-content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    {{-- Page header --}}
    <div style="background:var(--brand-default, #0b2a4a); border-radius:6px; padding:20px 24px;">
        <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">Commission Overview</h2>
        <div style="font-size:0.875rem; color:rgba(255,255,255,0.55);">Agency-wide commission performance, revenue share, and P&L.</div>
    </div>

    {{-- ══════════════════════════════════════
         TOP CARDS ROW
         ══════════════════════════════════════ --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

        {{-- Card 1: Agency GCI --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:16px 20px;">
            <div class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Agency GCI</div>
            <div class="text-2xl font-extrabold" style="color:var(--text-primary);">R {{ number_format($agencyGCIMonth, 2) }}</div>
            <div class="text-xs mt-1" style="color:var(--text-secondary);">YTD: R {{ number_format($agencyGCIYear, 2) }}</div>
        </div>

        {{-- Card 2: Company Dollar --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:16px 20px;">
            <div class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Company Dollar</div>
            <div class="text-2xl font-extrabold" style="color:var(--text-primary);">R {{ number_format($companyDollarMonth, 2) }}</div>
            <div class="text-xs mt-1" style="color:var(--text-secondary);">YTD: R {{ number_format($companyDollarYear, 2) }}</div>
        </div>

        {{-- Card 3: Rev Share Paid --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:16px 20px;">
            <div class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Rev Share Paid</div>
            <div class="text-2xl font-extrabold" style="color:#14b8a6;">R {{ number_format($revSharePaidMonth, 2) }}</div>
            <div class="text-xs mt-1" style="color:var(--text-secondary);">YTD: R {{ number_format($revSharePaidYear, 2) }}</div>
        </div>

        {{-- Card 4: Net Agency --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:16px 20px;">
            <div class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Net Agency</div>
            <div class="text-2xl font-extrabold" style="color:{{ $netAgencyMonth >= 0 ? '#22c55e' : '#ef4444' }};">R {{ number_format($netAgencyMonth, 2) }}</div>
            <div class="text-xs mt-1" style="color:var(--text-secondary);">YTD: R {{ number_format($netAgencyYear, 2) }}</div>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         MONTHLY AGENCY CHART
         ══════════════════════════════════════ --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;">
        <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary);">Monthly Agency Revenue — Last 12 Months</h3>
        <div style="position:relative; height:300px;">
            <canvas id="agencyChart"></canvas>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         AGENT PERFORMANCE TABLE
         ══════════════════════════════════════ --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
        <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
            <h3 class="text-sm font-bold" style="color:var(--text-primary);">Agent Performance</h3>
        </div>

        @if($agents->isEmpty())
            <div class="p-8 text-center">
                <div class="text-sm" style="color:var(--text-secondary);">No agents with commission data yet.</div>
            </div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr style="border-bottom:1px solid var(--border); background:var(--surface-2, rgba(0,0,0,0.05));">
                        <th class="text-left text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">#</th>
                        <th class="text-left text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">Agent</th>
                        <th class="text-right text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">GCI Month</th>
                        <th class="text-right text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">GCI YTD</th>
                        <th class="text-center text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">Cap Status</th>
                        <th class="text-center text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">Deals</th>
                        <th class="text-right text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">Rev Share</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($agents as $idx => $agent)
                    @php
                        $rank = $idx + 1;
                        $rankColor = match(true) {
                            $rank === 1 && $agent['gci_month'] > 0 => '#f59e0b',
                            $rank === 2 && $agent['gci_month'] > 0 => '#94a3b8',
                            $rank === 3 && $agent['gci_month'] > 0 => '#cd7f32',
                            default => 'var(--text-muted)',
                        };
                    @endphp
                    <tr style="border-bottom:1px solid var(--border);" class="hover:bg-white/5 transition-colors">
                        <td class="px-4 py-2.5 text-center">
                            <span class="text-xs font-bold" style="color:{{ $rankColor }};">{{ $rank }}</span>
                        </td>
                        <td class="px-4 py-2.5">
                            <a href="{{ route('commission.dashboard') }}?agent={{ $agent['id'] }}" class="text-sm font-medium no-underline hover:underline" style="color:var(--text-primary);">
                                {{ $agent['name'] }}
                            </a>
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap {{ $rank <= 3 && $agent['gci_month'] > 0 ? 'font-bold' : '' }}" style="color:var(--text-primary);">
                            R {{ number_format($agent['gci_month'], 2) }}
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap" style="color:var(--text-secondary);">
                            R {{ number_format($agent['gci_year'], 2) }}
                        </td>
                        <td class="px-4 py-2.5 text-center whitespace-nowrap">
                            @if($agent['is_capped'])
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold" style="background:rgba(245,158,11,0.15); color:#f59e0b;">CAPPED</span>
                            @else
                                <div class="flex items-center gap-2 justify-center">
                                    <div class="w-16 h-1.5 rounded-full overflow-hidden" style="background:var(--border);">
                                        <div class="h-full rounded-full" style="width:{{ $agent['cap_percent'] }}%; background:#0ea5e9;"></div>
                                    </div>
                                    <span class="text-xs" style="color:var(--text-secondary);">{{ $agent['cap_percent'] }}%</span>
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-center" style="color:var(--text-secondary);">
                            {{ $agent['tx_count'] }}
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap" style="color:#14b8a6;">
                            R {{ number_format($agent['rev_share_earned'], 2) }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    {{-- ══════════════════════════════════════
         REVENUE SHARE TREE
         ══════════════════════════════════════ --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;"
         x-data="{ expandedNodes: {} }">
        <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary);">Sponsorship Tree</h3>

        @if(empty($sponsorshipTree))
            <div class="text-center py-6">
                <div class="text-sm" style="color:var(--text-secondary);">No sponsorship relationships yet.</div>
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
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;">
        <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary);">Year-to-Date P&L Summary</h3>

        <div class="space-y-2 max-w-md">
            <div class="flex items-center justify-between py-1.5" style="border-bottom:1px solid var(--border);">
                <span class="text-sm" style="color:var(--text-secondary);">Total GCI</span>
                <span class="text-sm font-semibold" style="color:var(--text-primary);">R {{ number_format($pnl['total_gci'], 2) }}</span>
            </div>
            <div class="flex items-center justify-between py-1.5" style="border-bottom:1px solid var(--border);">
                <span class="text-sm" style="color:var(--text-secondary);">Less: Agent Splits</span>
                <span class="text-sm" style="color:#ef4444;">(R {{ number_format($pnl['agent_splits'], 2) }})</span>
            </div>
            <div class="flex items-center justify-between py-1.5" style="border-bottom:1px solid var(--border);">
                <span class="text-sm" style="color:var(--text-secondary);">Less: Revenue Share</span>
                <span class="text-sm" style="color:#ef4444;">(R {{ number_format($pnl['rev_share'], 2) }})</span>
            </div>
            <div class="flex items-center justify-between py-1.5" style="border-bottom:1px solid var(--border);">
                <span class="text-sm" style="color:var(--text-secondary);">Less: Platform Costs ({{ $activeAgentCount }} agents)</span>
                <span class="text-sm" style="color:#ef4444;">(R {{ number_format($pnl['platform_costs'], 2) }})</span>
            </div>
            <div class="flex items-center justify-between py-2 mt-1" style="border-top:2px solid var(--border);">
                <span class="text-sm font-bold" style="color:var(--text-primary);">Net Agency Revenue</span>
                <span class="text-lg font-extrabold" style="color:{{ $pnl['net_revenue'] >= 0 ? '#22c55e' : '#ef4444' }};">
                    R {{ number_format($pnl['net_revenue'], 2) }}
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
    const textColor = getComputedStyle(document.documentElement).getPropertyValue('--text-secondary').trim() || '#94a3b8';
    const borderColor = getComputedStyle(document.documentElement).getPropertyValue('--border').trim() || '#334155';

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: monthlyData.map(d => d.label),
            datasets: [
                {
                    label: 'Company Dollar',
                    data: monthlyData.map(d => d.companyDollar),
                    backgroundColor: 'rgba(14, 165, 233, 0.7)',
                    borderColor: '#0ea5e9',
                    borderWidth: 1,
                    borderRadius: 3,
                    order: 2,
                },
                {
                    label: 'Rev Share',
                    data: monthlyData.map(d => d.revShare),
                    backgroundColor: 'rgba(20, 184, 166, 0.7)',
                    borderColor: '#14b8a6',
                    borderWidth: 1,
                    borderRadius: 3,
                    order: 3,
                },
                {
                    label: 'Net Agency',
                    data: monthlyData.map(d => d.netAgency),
                    type: 'line',
                    borderColor: '#22c55e',
                    backgroundColor: 'rgba(34, 197, 94, 0.1)',
                    borderWidth: 2,
                    pointRadius: 3,
                    pointBackgroundColor: '#22c55e',
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
                            return context.dataset.label + ': R ' + context.parsed.y.toLocaleString('en-ZA', {minimumFractionDigits: 2});
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
