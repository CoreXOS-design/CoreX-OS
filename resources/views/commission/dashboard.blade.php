{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Layout: "Two income streams" (2026-09-14, .ai/specs/commission_engine_spec.md §6.1).
     Frozen header; below it two self-contained columns that scroll inside themselves at lg+:
       LEFT  — everything about the agent's OWN commission (month / year / cap strip, 12-month
               chart, transactions table scrolling inside its card, pagination pinned under it).
       RIGHT — everything about REVENUE SHARE (month / year, tier 1 agents with a GCI bar,
               rev share by month).
     Below lg the columns stack and the page scrolls normally. View-only: every figure, route,
     permission and tour anchor is unchanged from the stacked layout it replaces. --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $capColor   = $capPeriod->is_capped ? 'var(--ds-amber,#f59e0b)' : 'var(--brand-button)';
    $tierMaxGci = (float) ($tier1Agents->max('month_gci') ?? 0);
    $revMax     = (float) max(1, collect($monthlyData)->max('revShare') ?? 0);
    $revHasAny  = collect($monthlyData)->sum('revShare') > 0;
@endphp
<div class="w-full lg:h-full flex flex-col gap-4">

    {{-- ── FROZEN HEADER ── --}}
    <div class="rounded-md px-6 py-5 corex-page-banner flex-shrink-0">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div data-tour="earn-dashboard-intro">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">My Earnings</h1>
                <p class="text-xs" style="color: var(--text-muted);">What you earned, and what your network earned for you.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
            </div>
        </div>
    </div>

    {{-- ── TWO STREAMS: my commission | my revenue share ── --}}
    <div class="lg:flex-1 lg:min-h-0 grid grid-cols-1 lg:grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)] gap-4">

        {{-- ════════════════ LEFT — MY COMMISSION ════════════════ --}}
        <div class="lg:min-h-0 flex flex-col rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">

            <div class="flex items-center justify-between gap-3 px-5 py-3 flex-shrink-0" style="border-bottom:1px solid var(--border);">
                <div class="flex items-center gap-2.5 min-w-0">
                    <span class="w-2 h-2 rounded-sm flex-shrink-0" style="background:var(--brand-icon);"></span>
                    <h3 class="text-sm font-bold truncate" style="color:var(--text-primary);">My commission</h3>
                </div>
                <span class="text-xs whitespace-nowrap" style="color:var(--text-muted);">Sales, lettings, referrals</span>
            </div>

            {{-- Headline numbers + cap --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 flex-shrink-0" style="border-bottom:1px solid var(--border);" data-tour="earn-dashboard-cards">
                <div class="px-5 py-3 earn-cell">
                    <div class="text-[11px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">This month</div>
                    <div class="text-[1.375rem] font-bold leading-tight tabular-nums" style="color:var(--text-primary);">R {{ number_format($thisMonthGCI, 2) }}</div>
                    <div class="text-xs" style="color:var(--text-secondary);">Net agent earnings</div>
                </div>
                <div class="px-5 py-3 earn-cell">
                    <div class="text-[11px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">This year</div>
                    <div class="text-[1.375rem] font-bold leading-tight tabular-nums" style="color:var(--text-primary);">R {{ number_format($thisYearGCI, 2) }}</div>
                    <div class="text-xs" style="color:var(--text-secondary);">Net agent earnings</div>
                </div>
                <div class="px-5 py-3 flex flex-col justify-center gap-1.5" data-tour="earn-dashboard-cap">
                    <div class="flex items-center justify-between gap-2">
                        <div class="text-[11px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">
                            Cap · {{ $capPercent }}%
                            @if($capPeriod->is_capped)
                                <span class="ds-badge ds-badge-warning ml-1">Capped</span>
                            @endif
                        </div>
                        <span class="text-xs whitespace-nowrap" style="color:var(--text-secondary);">Resets in {{ number_format($daysUntilReset) }} days</span>
                    </div>
                    <div class="h-2 rounded-full overflow-hidden" style="background:var(--border);">
                        <div class="h-full rounded-full transition-all duration-500" style="width:{{ $capPercent }}%; background:{{ $capColor }};"></div>
                    </div>
                    <div class="flex items-center justify-between gap-2 text-xs tabular-nums">
                        <span style="color:var(--text-secondary);"><span class="font-semibold" style="color:var(--text-primary);">R {{ number_format($capProgress, 0) }}</span> / R {{ number_format($capTotal, 0) }}</span>
                        <span style="color:{{ $capPeriod->is_capped ? 'var(--ds-amber,#f59e0b)' : 'var(--text-secondary)' }};">
                            @if($capPeriod->is_capped)
                                100% commission
                            @else
                                R {{ number_format($capRemaining, 0) }} to go
                            @endif
                        </span>
                    </div>
                </div>
            </div>

            @if($postCapFees)
            <div class="grid grid-cols-3 gap-4 px-5 py-2.5 flex-shrink-0" style="border-bottom:1px solid var(--border); background:var(--surface-2);">
                <div>
                    <div class="text-[11px]" style="color:var(--text-muted);">Transaction fees</div>
                    <div class="text-sm font-semibold tabular-nums" style="color:var(--text-primary);">R {{ number_format($postCapFees['transaction_fees_paid'], 2) }}</div>
                </div>
                <div>
                    <div class="text-[11px]" style="color:var(--text-muted);">Risk fees</div>
                    <div class="text-sm font-semibold tabular-nums" style="color:var(--text-primary);">R {{ number_format($postCapFees['risk_fees_paid'], 2) }}</div>
                </div>
                <div>
                    <div class="text-[11px]" style="color:var(--text-muted);">Post-cap fee cap</div>
                    <div class="text-sm font-semibold tabular-nums" style="color:var(--text-primary);">R {{ number_format($postCapFees['post_cap_fee_cap'], 2) }}</div>
                </div>
            </div>
            @endif

            {{-- 12-month chart --}}
            <div class="px-5 pt-3 pb-1 flex-shrink-0" data-tour="earn-dashboard-chart">
                <div style="position:relative; height:170px;">
                    <canvas id="earningsChart"></canvas>
                </div>
            </div>

            {{-- Transactions — scroll inside the card at lg+ --}}
            <div class="max-h-[60vh] lg:max-h-none lg:flex-1 lg:min-h-0 overflow-auto corex-brand-scroll" style="border-top:1px solid var(--border);" data-tour="earn-dashboard-transactions">
                @if($recentTransactions->isEmpty())
                    <div class="py-10 px-6 text-center">
                        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                             style="background:color-mix(in srgb, var(--brand-icon) 12%, transparent); color:var(--brand-icon);">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>
                        </div>
                        <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No earnings recorded yet</h3>
                        <p class="text-sm" style="color:var(--text-muted);">Commission entries will appear here once deals close.</p>
                    </div>
                @else
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr>
                            <th class="text-left px-4 py-2.5 sticky top-0 z-10">Date</th>
                            <th class="text-left px-4 py-2.5 sticky top-0 z-10">Description</th>
                            <th class="text-left px-4 py-2.5 sticky top-0 z-10">Type</th>
                            <th class="text-right px-4 py-2.5 sticky top-0 z-10">Net</th>
                            <th class="text-center px-4 py-2.5 sticky top-0 z-10">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentTransactions as $tx)
                        @php
                            $typeBadge = match($tx->transaction_type) {
                                'sale' => ['var' => 'var(--brand-icon)', 'label' => 'Sale'],
                                'rental_letting' => ['var' => 'var(--ds-amber,#f59e0b)', 'label' => 'Letting'],
                                'rental_management' => ['var' => 'var(--ds-amber,#f59e0b)', 'label' => 'Rental'],
                                'referral' => ['var' => 'var(--ds-navy,#0b2a4a)', 'label' => 'Referral'],
                                default => ['var' => null, 'label' => 'Other'],
                            };
                            $statusBadge = match($tx->status) {
                                'pending' => ['class' => 'ds-badge-warning', 'label' => 'Pending'],
                                'confirmed' => ['class' => 'ds-badge-info', 'label' => 'Confirmed'],
                                'paid' => ['class' => 'ds-badge-success', 'label' => 'Paid'],
                                'cancelled' => ['class' => 'ds-badge-danger', 'label' => 'Cancelled'],
                                default => ['class' => 'ds-badge-default', 'label' => ucfirst($tx->status)],
                            };
                            $totalFees = ($tx->transaction_fee ?? 0) + ($tx->risk_fee ?? 0) + ($tx->mentor_fee ?? 0);
                        @endphp
                        <tr>
                            <td class="px-4 py-2.5 whitespace-nowrap align-top" style="color:var(--text-secondary);">
                                {{ $tx->deal_date ? $tx->deal_date->format('d M Y') : $tx->created_at->format('d M Y') }}
                            </td>
                            <td class="px-4 py-2.5 align-top" style="max-width:20rem;">
                                <div class="truncate" style="color:var(--text-primary);" title="{{ $tx->description }}">{{ \Illuminate\Support\Str::limit($tx->description, 60) }}</div>
                                <div class="text-[11px] whitespace-nowrap tabular-nums mt-0.5" style="color:var(--text-muted);">
                                    Gross R {{ number_format($tx->gross_commission, 2) }}
                                    <span class="mx-1">·</span> Split R {{ number_format($tx->agent_amount, 2) }}
                                    <span class="mx-1">·</span> Fees {{ $totalFees > 0 ? 'R ' . number_format($totalFees, 2) : '—' }}
                                </div>
                            </td>
                            <td class="px-4 py-2.5 whitespace-nowrap align-top">
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
                            <td class="px-4 py-2.5 text-right whitespace-nowrap font-bold tabular-nums align-top" style="color:var(--text-primary);">
                                R {{ number_format($tx->net_agent_amount, 2) }}
                            </td>
                            <td class="px-4 py-2.5 text-center whitespace-nowrap align-top">
                                <span class="ds-badge {{ $statusBadge['class'] }}">{{ $statusBadge['label'] }}</span>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @endif
            </div>

            @if($recentTransactions->hasPages())
            <div class="px-5 py-2.5 flex-shrink-0" style="border-top:1px solid var(--border);">
                {{ $recentTransactions->links() }}
            </div>
            @endif
        </div>

        {{-- ════════════════ RIGHT — MY REVENUE SHARE ════════════════ --}}
        <div class="lg:min-h-0 flex flex-col rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">

            <div class="flex items-center justify-between gap-3 px-5 py-3 flex-shrink-0" style="border-bottom:1px solid var(--border);">
                <div class="flex items-center gap-2.5 min-w-0">
                    <span class="w-2 h-2 rounded-sm flex-shrink-0" style="background:var(--ds-teal,#14b8a6);"></span>
                    <h3 class="text-sm font-bold truncate" style="color:var(--text-primary);">My revenue share</h3>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 flex-shrink-0" style="color:var(--text-muted);">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                </svg>
            </div>

            <div class="grid grid-cols-2 flex-shrink-0" style="border-bottom:1px solid var(--border);">
                <div class="px-5 py-3" style="border-right:1px solid var(--border);">
                    <div class="text-[11px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">This month</div>
                    <div class="text-[1.375rem] font-bold leading-tight tabular-nums" style="color:var(--ds-teal,#14b8a6);">R {{ number_format($thisMonthRevShare, 2) }}</div>
                    <div class="text-xs" style="color:var(--text-secondary);">From {{ $tier1Agents->count() }} tier 1 {{ \Illuminate\Support\Str::plural('agent', $tier1Agents->count()) }}</div>
                </div>
                <div class="px-5 py-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">This year</div>
                    <div class="text-[1.375rem] font-bold leading-tight tabular-nums" style="color:var(--ds-teal,#14b8a6);">R {{ number_format($thisYearRevShare, 2) }}</div>
                    <div class="text-xs" style="color:var(--text-secondary);">Revenue share</div>
                </div>
            </div>

            <div class="lg:flex-1 lg:min-h-0 overflow-y-auto corex-brand-scroll px-5 py-4 flex flex-col gap-5">

                {{-- Tier 1 agents --}}
                <div class="flex flex-col gap-2.5">
                    <div class="flex items-baseline justify-between gap-2">
                        <div class="text-[11px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Your tier 1 agents</div>
                        @if($tier1Agents->isNotEmpty())
                            <span class="text-xs" style="color:var(--text-secondary);">GCI this month</span>
                        @endif
                    </div>

                    @if($tier1Agents->isEmpty())
                        <div class="py-6 px-4 text-center rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
                            <div class="w-10 h-10 rounded-full mx-auto mb-3 flex items-center justify-center"
                                 style="background:color-mix(in srgb, var(--brand-icon) 12%, transparent); color:var(--brand-icon);">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" /></svg>
                            </div>
                            <div class="text-sm font-semibold mb-0.5" style="color:var(--text-primary);">No sponsored agents yet</div>
                            <p class="text-xs" style="color:var(--text-muted);">Agents you recruit will appear here.</p>
                        </div>
                    @else
                        <div class="flex flex-col gap-2">
                            @foreach($tier1Agents as $agent)
                            @php $gciPct = $tierMaxGci > 0 ? round($agent['month_gci'] / $tierMaxGci * 100) : 0; @endphp
                            <div class="flex items-center justify-between gap-3 px-2.5 py-2 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
                                <div class="flex items-center gap-2.5 min-w-0">
                                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
                                         style="background:var(--brand-default,#0b2a4a);">
                                        {{ collect(explode(' ', $agent['name']))->map(fn($w) => strtoupper(substr($w, 0, 1)))->take(2)->join('') }}
                                    </div>
                                    <div class="min-w-0">
                                        <div class="text-sm font-medium truncate" style="color:var(--text-primary);">{{ $agent['name'] }}</div>
                                        <div class="mt-1 h-1 w-28 rounded-full overflow-hidden" style="background:var(--border);">
                                            <div class="h-full rounded-full" style="width:{{ $gciPct }}%; background:var(--ds-teal,#14b8a6);"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-sm font-semibold tabular-nums whitespace-nowrap" style="color:var(--text-secondary);">
                                    R {{ number_format($agent['month_gci'], 2) }}
                                    <span class="text-xs font-normal" style="color:var(--text-muted);">/mo</span>
                                </div>
                            </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Rev share by month --}}
                <div class="flex flex-col gap-2">
                    <div class="flex items-baseline justify-between gap-2">
                        <div class="text-[11px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Rev share by month</div>
                        <span class="text-xs" style="color:var(--text-secondary);">Last 12 months</span>
                    </div>
                    <div class="flex items-end gap-1.5" style="height:80px;">
                        @foreach($monthlyData as $m)
                        @php $revPct = $revHasAny ? max(2, round($m['revShare'] / $revMax * 100)) : 2; @endphp
                        <div class="flex-1 rounded-t-sm" style="height:{{ $revPct }}%; background:var(--ds-teal,#14b8a6); opacity:{{ $m['revShare'] > 0 ? '0.75' : '0.25' }};" title="{{ $m['label'] }}: R {{ number_format($m['revShare'], 2) }}"></div>
                        @endforeach
                    </div>
                    <div class="flex justify-between text-[10px]" style="color:var(--text-muted);">
                        <span>{{ $monthlyData[0]['label'] ?? '' }}</span>
                        <span>{{ $monthlyData[6]['label'] ?? '' }}</span>
                        <span>{{ $monthlyData[11]['label'] ?? '' }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<style>
    /* Cell dividers for the commission headline strip: stacked below sm, one row of 3 at sm+. */
    .earn-cell { border-bottom: 1px solid var(--border); }
    @media (min-width: 640px) {
        .earn-cell { border-bottom: none; border-right: 1px solid var(--border); }
    }
</style>

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
                        padding: 12,
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
