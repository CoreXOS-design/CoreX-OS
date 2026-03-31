@extends('layouts.corex')

@section('corex-content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    {{-- Page header --}}
    <div style="background:var(--brand-default, #0b2a4a); border-radius:6px; padding:20px 24px;">
        <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">My Earnings</h2>
        <div style="font-size:0.875rem; color:rgba(255,255,255,0.55);">Commission, cap progress, and revenue share at a glance.</div>
    </div>

    {{-- ══════════════════════════════════════
         TOP CARDS ROW
         ══════════════════════════════════════ --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

        {{-- Card 1: This Month --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:16px 20px;">
            <div class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">This Month</div>
            <div class="text-2xl font-extrabold" style="color:var(--text-primary);">R {{ number_format($thisMonthGCI, 2) }}</div>
            <div class="text-xs mt-1" style="color:var(--text-secondary);">Net agent earnings</div>
        </div>

        {{-- Card 2: This Year --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:16px 20px;">
            <div class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">This Year</div>
            <div class="text-2xl font-extrabold" style="color:var(--text-primary);">R {{ number_format($thisYearGCI, 2) }}</div>
            <div class="text-xs mt-1" style="color:var(--text-secondary);">Net agent earnings</div>
        </div>

        {{-- Card 3: Cap Progress --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:16px 20px;">
            <div class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Cap Progress</div>
            <div class="text-2xl font-extrabold" style="color:var(--text-primary);">
                R {{ number_format($capProgress, 0) }} <span class="text-sm font-normal" style="color:var(--text-secondary);">/ R {{ number_format($capTotal, 0) }}</span>
            </div>
            {{-- Mini progress bar --}}
            <div class="mt-2 h-2 rounded-full overflow-hidden" style="background:var(--border);">
                <div class="h-full rounded-full transition-all duration-500"
                     style="width:{{ $capPercent }}%; background:{{ $capPeriod->is_capped ? '#f59e0b' : '#0ea5e9' }};"></div>
            </div>
            <div class="text-xs mt-1" style="color:{{ $capPeriod->is_capped ? '#f59e0b' : 'var(--text-secondary)' }};">
                @if($capPeriod->is_capped)
                    CAPPED — 100% commission!
                @else
                    R {{ number_format($capRemaining, 0) }} to go
                @endif
            </div>
        </div>

        {{-- Card 4: Revenue Share --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:16px 20px;">
            <div class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Revenue Share</div>
            <div class="text-2xl font-extrabold" style="color:#14b8a6;">R {{ number_format($thisMonthRevShare, 2) }}</div>
            <div class="text-xs mt-1" style="color:var(--text-secondary);">This month</div>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         FULL-WIDTH CAP PROGRESS BAR
         ══════════════════════════════════════ --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;">
        <div class="flex items-center justify-between mb-2">
            <div class="text-sm font-semibold" style="color:var(--text-primary);">
                Annual Cap Progress
                @if($capPeriod->is_capped)
                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-bold" style="background:rgba(245,158,11,0.15); color:#f59e0b;">CAPPED</span>
                @endif
            </div>
            <div class="text-xs" style="color:var(--text-secondary);">
                Resets in {{ $daysUntilReset }} days
            </div>
        </div>

        {{-- Large progress bar --}}
        <div class="h-4 rounded-full overflow-hidden" style="background:var(--border);">
            <div class="h-full rounded-full transition-all duration-700 relative"
                 style="width:{{ $capPercent }}%; background:{{ $capPeriod->is_capped ? 'linear-gradient(90deg, #f59e0b, #eab308)' : 'linear-gradient(90deg, #0ea5e9, #06b6d4)' }};">
            </div>
        </div>

        <div class="flex items-center justify-between mt-2">
            <div class="text-xs font-medium" style="color:var(--text-secondary);">R {{ number_format($capProgress, 2) }} paid</div>
            <div class="text-xs font-medium" style="color:var(--text-secondary);">R {{ number_format($capTotal, 2) }} cap</div>
        </div>

        @if($postCapFees)
        <div class="mt-3 pt-3 grid grid-cols-3 gap-4" style="border-top:1px solid var(--border);">
            <div>
                <div class="text-xs" style="color:var(--text-muted);">Transaction Fees</div>
                <div class="text-sm font-semibold" style="color:var(--text-primary);">R {{ number_format($postCapFees['transaction_fees_paid'], 2) }}</div>
            </div>
            <div>
                <div class="text-xs" style="color:var(--text-muted);">Risk Fees</div>
                <div class="text-sm font-semibold" style="color:var(--text-primary);">R {{ number_format($postCapFees['risk_fees_paid'], 2) }}</div>
            </div>
            <div>
                <div class="text-xs" style="color:var(--text-muted);">Post-Cap Fee Cap</div>
                <div class="text-sm font-semibold" style="color:var(--text-primary);">R {{ number_format($postCapFees['post_cap_fee_cap'], 2) }}</div>
            </div>
        </div>
        @endif
    </div>

    {{-- ══════════════════════════════════════
         MONTHLY EARNINGS CHART
         ══════════════════════════════════════ --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;">
        <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary);">Monthly Earnings — Last 12 Months</h3>
        <div style="position:relative; height:280px;">
            <canvas id="earningsChart"></canvas>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         RECENT TRANSACTIONS TABLE
         ══════════════════════════════════════ --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
        <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
            <h3 class="text-sm font-bold" style="color:var(--text-primary);">Recent Transactions</h3>
        </div>

        @if($recentTransactions->isEmpty())
            <div class="p-8 text-center">
                <div class="text-sm" style="color:var(--text-secondary);">No earnings recorded yet.</div>
                <div class="text-xs mt-1" style="color:var(--text-muted);">Commission entries will appear here once deals close.</div>
            </div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr style="border-bottom:1px solid var(--border); background:var(--surface-2, rgba(0,0,0,0.05));">
                        <th class="text-left text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">Date</th>
                        <th class="text-left text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">Description</th>
                        <th class="text-left text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">Type</th>
                        <th class="text-right text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">Gross</th>
                        <th class="text-right text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">My Split</th>
                        <th class="text-right text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">Fees</th>
                        <th class="text-right text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">Net</th>
                        <th class="text-center text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentTransactions as $tx)
                    <tr style="border-bottom:1px solid var(--border);" class="hover:bg-white/5 transition-colors">
                        <td class="px-4 py-2.5 whitespace-nowrap" style="color:var(--text-secondary);">
                            {{ $tx->deal_date ? $tx->deal_date->format('d M Y') : $tx->created_at->format('d M Y') }}
                        </td>
                        <td class="px-4 py-2.5 max-w-xs truncate" style="color:var(--text-primary);">
                            {{ \Illuminate\Support\Str::limit($tx->description, 50) }}
                        </td>
                        <td class="px-4 py-2.5 whitespace-nowrap">
                            @php
                                $typeBadge = match($tx->transaction_type) {
                                    'sale' => ['bg' => 'rgba(59,130,246,0.12)', 'color' => '#3b82f6', 'label' => 'Sale'],
                                    'rental_letting' => ['bg' => 'rgba(20,184,166,0.12)', 'color' => '#14b8a6', 'label' => 'Letting'],
                                    'rental_management' => ['bg' => 'rgba(20,184,166,0.12)', 'color' => '#14b8a6', 'label' => 'Rental'],
                                    'referral' => ['bg' => 'rgba(168,85,247,0.12)', 'color' => '#a855f7', 'label' => 'Referral'],
                                    default => ['bg' => 'rgba(148,163,184,0.12)', 'color' => '#94a3b8', 'label' => 'Other'],
                                };
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold"
                                  style="background:{{ $typeBadge['bg'] }}; color:{{ $typeBadge['color'] }};">
                                {{ $typeBadge['label'] }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap" style="color:var(--text-secondary);">
                            R {{ number_format($tx->gross_commission, 2) }}
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap" style="color:var(--text-secondary);">
                            R {{ number_format($tx->agent_amount, 2) }}
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap" style="color:var(--text-secondary);">
                            @php $totalFees = ($tx->transaction_fee ?? 0) + ($tx->risk_fee ?? 0) + ($tx->mentor_fee ?? 0); @endphp
                            @if($totalFees > 0)
                                R {{ number_format($totalFees, 2) }}
                            @else
                                <span style="color:var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap font-bold" style="color:var(--text-primary);">
                            R {{ number_format($tx->net_agent_amount, 2) }}
                        </td>
                        <td class="px-4 py-2.5 text-center whitespace-nowrap">
                            @php
                                $statusBadge = match($tx->status) {
                                    'pending' => ['bg' => 'rgba(245,158,11,0.12)', 'color' => '#f59e0b', 'label' => 'Pending'],
                                    'confirmed' => ['bg' => 'rgba(59,130,246,0.12)', 'color' => '#3b82f6', 'label' => 'Confirmed'],
                                    'paid' => ['bg' => 'rgba(34,197,94,0.12)', 'color' => '#22c55e', 'label' => 'Paid'],
                                    'cancelled' => ['bg' => 'rgba(239,68,68,0.12)', 'color' => '#ef4444', 'label' => 'Cancelled'],
                                    default => ['bg' => 'rgba(148,163,184,0.12)', 'color' => '#94a3b8', 'label' => ucfirst($tx->status)],
                                };
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold"
                                  style="background:{{ $statusBadge['bg'] }}; color:{{ $statusBadge['color'] }};">
                                {{ $statusBadge['label'] }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($recentTransactions->hasPages())
        <div class="px-5 py-3" style="border-top:1px solid var(--border);">
            {{ $recentTransactions->links() }}
        </div>
        @endif
        @endif
    </div>

    {{-- ══════════════════════════════════════
         REVENUE SHARE SECTION
         ══════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

        {{-- Left: Revenue Share Summary --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;">
            <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary);">Your Network</h3>

            <div class="space-y-3">
                <div class="flex items-center justify-between p-3 rounded-md" style="background:var(--surface-2, rgba(0,0,0,0.05)); border:1px solid var(--border);">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Tier 1 Agents</div>
                        <div class="text-lg font-bold" style="color:var(--text-primary);">{{ $tier1Agents->count() }}</div>
                    </div>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8" style="color:var(--border-hover);">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                    </svg>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="p-3 rounded-md" style="background:var(--surface-2, rgba(0,0,0,0.05)); border:1px solid var(--border);">
                        <div class="text-xs" style="color:var(--text-muted);">Rev Share This Month</div>
                        <div class="text-lg font-bold" style="color:#14b8a6;">R {{ number_format($thisMonthRevShare, 2) }}</div>
                    </div>
                    <div class="p-3 rounded-md" style="background:var(--surface-2, rgba(0,0,0,0.05)); border:1px solid var(--border);">
                        <div class="text-xs" style="color:var(--text-muted);">Rev Share This Year</div>
                        <div class="text-lg font-bold" style="color:#14b8a6;">R {{ number_format($thisYearRevShare, 2) }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Right: Tier 1 Agents List --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;">
            <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary);">Your Tier 1 Agents</h3>

            @if($tier1Agents->isEmpty())
                <div class="text-center py-6">
                    <div class="text-sm" style="color:var(--text-secondary);">No sponsored agents yet.</div>
                    <div class="text-xs mt-1" style="color:var(--text-muted);">Agents you recruit will appear here.</div>
                </div>
            @else
                <div class="space-y-2">
                    @foreach($tier1Agents as $agent)
                    <div class="flex items-center justify-between p-2.5 rounded-md transition-colors hover:bg-white/5"
                         style="border:1px solid var(--border);">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold"
                                 style="background:rgba(14,165,233,0.12); color:#0ea5e9;">
                                {{ collect(explode(' ', $agent['name']))->map(fn($w) => strtoupper(substr($w, 0, 1)))->take(2)->join('') }}
                            </div>
                            <div class="text-sm font-medium" style="color:var(--text-primary);">{{ $agent['name'] }}</div>
                        </div>
                        <div class="text-sm font-semibold" style="color:var(--text-secondary);">
                            R {{ number_format($agent['month_gci'], 2) }}
                            <span class="text-xs font-normal" style="color:var(--text-muted);">/mo</span>
                        </div>
                    </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

</div>

{{-- Chart.js --}}
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('earningsChart');
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
                    label: 'Commission',
                    data: monthlyData.map(d => d.commission),
                    backgroundColor: 'rgba(14, 165, 233, 0.7)',
                    borderColor: '#0ea5e9',
                    borderWidth: 1,
                    borderRadius: 3,
                },
                {
                    label: 'Revenue Share',
                    data: monthlyData.map(d => d.revShare),
                    backgroundColor: 'rgba(20, 184, 166, 0.7)',
                    borderColor: '#14b8a6',
                    borderWidth: 1,
                    borderRadius: 3,
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
                    stacked: true,
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
