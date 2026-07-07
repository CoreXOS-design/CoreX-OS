{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (UI_DESIGN_SYSTEM §2.4 Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div data-tour="earn-dashboard-intro">
                <h1 class="text-xl font-bold text-white leading-tight">My Earnings</h1>
                <p class="text-sm text-white/60">Commission, cap progress, and revenue share at a glance.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @include('layouts.partials.tour-header-launcher')
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         TOP CARDS ROW
         ══════════════════════════════════════ --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4" data-tour="earn-dashboard-cards">

        {{-- Card 1: This Month --}}
        <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
            <div class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">This Month</div>
            <div class="text-[1.625rem] font-semibold leading-tight" style="color:var(--text-primary);">R {{ number_format($thisMonthGCI, 2) }}</div>
            <div class="text-xs mt-1" style="color:var(--text-secondary);">Net agent earnings</div>
        </div>

        {{-- Card 2: This Year --}}
        <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
            <div class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">This Year</div>
            <div class="text-[1.625rem] font-semibold leading-tight" style="color:var(--text-primary);">R {{ number_format($thisYearGCI, 2) }}</div>
            <div class="text-xs mt-1" style="color:var(--text-secondary);">Net agent earnings</div>
        </div>

        {{-- Card 3: Cap Progress --}}
        <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
            <div class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Cap Progress</div>
            <div class="text-[1.625rem] font-semibold leading-tight" style="color:var(--text-primary);">
                R {{ number_format($capProgress, 0) }} <span class="text-sm font-normal" style="color:var(--text-secondary);">/ R {{ number_format($capTotal, 0) }}</span>
            </div>
            {{-- Mini progress bar --}}
            <div class="mt-2 h-2 rounded-full overflow-hidden" style="background:var(--border);">
                <div class="h-full rounded-full transition-all duration-500"
                     style="width:{{ $capPercent }}%; background:{{ $capPeriod->is_capped ? 'var(--ds-amber,#f59e0b)' : 'var(--brand-button,#0ea5e9)' }};"></div>
            </div>
            <div class="text-xs mt-1" style="color:{{ $capPeriod->is_capped ? 'var(--ds-amber,#f59e0b)' : 'var(--text-secondary)' }};">
                @if($capPeriod->is_capped)
                    CAPPED — 100% commission!
                @else
                    R {{ number_format($capRemaining, 0) }} to go
                @endif
            </div>
        </div>

        {{-- Card 4: Revenue Share --}}
        <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
            <div class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Revenue Share</div>
            <div class="text-[1.625rem] font-semibold leading-tight" style="color:var(--ds-teal, #14b8a6);">R {{ number_format($thisMonthRevShare, 2) }}</div>
            <div class="text-xs mt-1" style="color:var(--text-secondary);">This month</div>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         FULL-WIDTH CAP PROGRESS BAR
         ══════════════════════════════════════ --}}
    <div class="rounded-md px-6 py-5" style="background:var(--surface); border:1px solid var(--border);" data-tour="earn-dashboard-cap">
        <div class="flex items-center justify-between mb-2">
            <div class="text-sm font-semibold" style="color:var(--text-primary);">
                Annual Cap Progress
                @if($capPeriod->is_capped)
                    <span class="ds-badge ds-badge-warning ml-2">Capped</span>
                @endif
            </div>
            <div class="text-xs" style="color:var(--text-secondary);">
                Resets in {{ number_format($daysUntilReset) }} days
            </div>
        </div>

        {{-- Large progress bar --}}
        <div class="h-3 rounded-full overflow-hidden" style="background:var(--border);">
            <div class="h-full rounded-full transition-all duration-700 relative"
                 style="width:{{ $capPercent }}%; background:{{ $capPeriod->is_capped ? 'var(--ds-amber,#f59e0b)' : 'var(--brand-button,#0ea5e9)' }};">
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
    <div class="rounded-md px-6 py-5" style="background:var(--surface); border:1px solid var(--border);" data-tour="earn-dashboard-chart">
        <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary);">Monthly Earnings — Last 12 Months</h3>
        <div style="position:relative; height:280px;">
            <canvas id="earningsChart"></canvas>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         RECENT TRANSACTIONS TABLE
         ══════════════════════════════════════ --}}
    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);" data-tour="earn-dashboard-transactions">
        <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
            <h3 class="text-sm font-bold" style="color:var(--text-primary);">Recent Transactions</h3>
        </div>

        @if($recentTransactions->isEmpty())
            <div class="py-12 px-6 text-center">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-icon,#0ea5e9);">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No earnings recorded yet</h3>
                <p class="text-sm" style="color:var(--text-muted);">Commission entries will appear here once deals close.</p>
            </div>
        @else
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr>
                        <th class="text-left px-4 py-2.5">Date</th>
                        <th class="text-left px-4 py-2.5">Description</th>
                        <th class="text-left px-4 py-2.5">Type</th>
                        <th class="text-right px-4 py-2.5">Gross</th>
                        <th class="text-right px-4 py-2.5">My Split</th>
                        <th class="text-right px-4 py-2.5">Fees</th>
                        <th class="text-right px-4 py-2.5">Net</th>
                        <th class="text-center px-4 py-2.5">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentTransactions as $tx)
                    <tr>
                        <td class="px-4 py-2.5 whitespace-nowrap" style="color:var(--text-secondary);">
                            {{ $tx->deal_date ? $tx->deal_date->format('d M Y') : $tx->created_at->format('d M Y') }}
                        </td>
                        <td class="px-4 py-2.5 max-w-xs truncate" style="color:var(--text-primary);">
                            {{ \Illuminate\Support\Str::limit($tx->description, 50) }}
                        </td>
                        <td class="px-4 py-2.5 whitespace-nowrap">
                            @php
                                $typeBadge = match($tx->transaction_type) {
                                    'sale' => ['var' => 'var(--brand-icon,#0ea5e9)', 'label' => 'Sale'],
                                    'rental_letting' => ['var' => 'var(--ds-amber,#f59e0b)', 'label' => 'Letting'],
                                    'rental_management' => ['var' => 'var(--ds-amber,#f59e0b)', 'label' => 'Rental'],
                                    'referral' => ['var' => 'var(--ds-navy,#0b2a4a)', 'label' => 'Referral'],
                                    default => ['var' => null, 'label' => 'Other'],
                                };
                            @endphp
                            @if($typeBadge['var'])
                                <span class="text-xs font-semibold px-2 py-0.5 rounded-md whitespace-nowrap"
                                      style="background:color-mix(in srgb, {{ $typeBadge['var'] }} 12%, transparent); color:{{ $typeBadge['var'] }}; border:1px solid color-mix(in srgb, {{ $typeBadge['var'] }} 25%, transparent);">
                                    {{ $typeBadge['label'] }}
                                </span>
                            @else
                                <span class="text-xs font-semibold px-2 py-0.5 rounded-md whitespace-nowrap"
                                      style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);">
                                    {{ $typeBadge['label'] }}
                                </span>
                            @endif
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
                                    'pending' => ['class' => 'ds-badge-warning', 'label' => 'Pending'],
                                    'confirmed' => ['class' => 'ds-badge-info', 'label' => 'Confirmed'],
                                    'paid' => ['class' => 'ds-badge-success', 'label' => 'Paid'],
                                    'cancelled' => ['class' => 'ds-badge-danger', 'label' => 'Cancelled'],
                                    default => ['class' => 'ds-badge-default', 'label' => ucfirst($tx->status)],
                                };
                            @endphp
                            <span class="ds-badge {{ $statusBadge['class'] }}">{{ $statusBadge['label'] }}</span>
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
        <div class="rounded-md px-6 py-5" style="background:var(--surface); border:1px solid var(--border);">
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
                        <div class="text-lg font-bold" style="color:var(--ds-teal, #14b8a6);">R {{ number_format($thisMonthRevShare, 2) }}</div>
                    </div>
                    <div class="p-3 rounded-md" style="background:var(--surface-2, rgba(0,0,0,0.05)); border:1px solid var(--border);">
                        <div class="text-xs" style="color:var(--text-muted);">Rev Share This Year</div>
                        <div class="text-lg font-bold" style="color:var(--ds-teal, #14b8a6);">R {{ number_format($thisYearRevShare, 2) }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Right: Tier 1 Agents List --}}
        <div class="rounded-md px-6 py-5" style="background:var(--surface); border:1px solid var(--border);">
            <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary);">Your Tier 1 Agents</h3>

            @if($tier1Agents->isEmpty())
                <div class="py-8 px-6 text-center">
                    <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                         style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-icon,#0ea5e9);">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" /></svg>
                    </div>
                    <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No sponsored agents yet</h3>
                    <p class="text-sm" style="color:var(--text-muted);">Agents you recruit will appear here.</p>
                </div>
            @else
                <div class="space-y-2">
                    @foreach($tier1Agents as $agent)
                    <div class="flex items-center justify-between p-2.5 rounded-md transition-colors"
                         style="border:1px solid var(--border);">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold text-white"
                                 style="background:var(--brand-icon,#0ea5e9);">
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
