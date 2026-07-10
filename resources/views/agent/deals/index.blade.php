@extends('layouts.corex')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page Header --}}
    <div class="rounded-md px-6 py-5" data-tour="at-agent-deals-header" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">My Deals</h1>
                <p class="text-sm text-white/60" data-tour="at-agent-deals-intro">Deals where you are allocated on listing and/or selling side.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @include('layouts.partials.tour-header-launcher')
                <span class="inline-flex items-center rounded-md px-3 py-1 text-xs font-semibold text-white"
                      data-tour="at-agent-deals-count"
                      style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2);">
                    {{ number_format($deals->count()) }} {{ \Illuminate\Support\Str::plural('deal', $deals->count()) }}
                </span>
            </div>
        </div>
    </div>

    @if($deals->isEmpty())
        {{-- Empty state --}}
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-6 h-6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2Z" />
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No deals allocated to you yet</h3>
            <p class="text-sm" style="color: var(--text-muted);">Once you are allocated on a deal (listing or selling side) it will appear here.</p>
        </div>
    @else
        {{-- Deal Register Table --}}
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">

            {{-- Table Header --}}
            <div class="px-4 py-3" data-tour="at-agent-deals-register" style="border-bottom: 1px solid var(--border);">
                <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Deal Register</h3>
                <div class="text-xs mt-1" style="color: var(--text-muted);">Read-only. You can add remarks in the log.</div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Deal</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Property</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Branch</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Selling Price</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Our Total (Ex VAT)</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach($deals->sortByDesc('deal_no') as $deal)
                            @php
                                $b = $branches->firstWhere('id', $deal->branch_id);
                                $acceptedMap = ['P'=>'Pending','D'=>'Declined','G'=>'Granted','R'=>'Registered'];
                                $acceptedLabel = $deal->accepted_status
                                    ? ($acceptedMap[$deal->accepted_status] ?? $deal->accepted_status)
                                    : '—';

                                $asVal = (string)($deal->accepted_status ?? '');
                                $statusBadge = 'ds-badge-default';
                                if ($asVal === 'G') $statusBadge = 'ds-badge-success';
                                elseif ($asVal === 'R') $statusBadge = 'ds-badge-info';
                                elseif ($asVal === 'D') $statusBadge = 'ds-badge-danger';
                                elseif ($asVal === 'P') $statusBadge = 'ds-badge-warning';

                                $csVal = (string)($deal->commission_status ?? '');
                                $commBadge = 'ds-badge-default';
                                if ($csVal === 'Paid') $commBadge = 'ds-badge-paid';
                                elseif ($csVal === 'Not Paid') $commBadge = 'ds-badge-notpaid';
                                elseif ($csVal === 'Loss') $commBadge = 'ds-badge-loss';
                            @endphp
                            <tr style="border-top: 1px solid var(--border);">
                                <td class="px-4 py-3">
                                    <div class="font-bold" style="color: var(--brand-icon, #0ea5e9);">#{{ $deal->deal_no }}</div>
                                    <div class="mt-1 flex items-center gap-1.5 flex-wrap">
                                        <span class="ds-badge {{ $statusBadge }}">{{ $acceptedLabel }}</span>
                                        @if($csVal !== '')
                                            <span class="ds-badge {{ $commBadge }}">{{ $csVal }}</span>
                                        @endif
                                    </div>
                                </td>

                                <td class="px-4 py-3">
                                    <div style="color: var(--text-primary);">{{ \Illuminate\Support\Str::limit($deal->property_address, 60) }}</div>
                                    <div class="mt-0.5 text-xs" style="color: var(--text-muted);">{{ $deal->seller_name }} &rarr; {{ $deal->buyer_name }}</div>
                                </td>

                                <td class="px-4 py-3">
                                    <div class="font-medium" style="color: var(--text-primary);">{{ $b?->name ?? '—' }}</div>
                                    <div class="mt-0.5 text-xs" style="color: var(--text-muted);">Period: <span style="color: var(--text-secondary);">{{ $deal->period ?: '—' }}</span></div>
                                </td>

                                <td class="px-4 py-3 text-right">
                                    <div class="font-semibold" style="color: var(--text-primary);">R {{ number_format((float)$deal->property_value, 0) }}</div>
                                </td>

                                <td class="px-4 py-3 text-right">
                                    <div class="font-semibold" style="color: var(--text-primary);">R {{ number_format((float)$deal->totalOurCommission(), 0) }}</div>
                                    <div class="mt-0.5 text-xs" style="color: var(--text-muted);">Company + agents (Ex VAT)</div>
                                </td>

                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('agent.deals.log', $deal) }}"
                                       @if($loop->first) data-tour="at-agent-deals-log" @endif
                                       class="text-xs font-semibold" style="color: var(--brand-icon, #0ea5e9);">
                                        Log
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

</div>
@endsection
