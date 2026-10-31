@php use App\Support\Finance\AuditLabelHelper; @endphp
@extends('layouts.corex')

@section('corex-content')
@include('admin.finance.audit._styles')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner" data-tour="cop-fin-deal-intro">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="min-w-0">
                <nav class="fa-crumbs" aria-label="Breadcrumb">
                    <a href="{{ route('admin.finance.audit.index') }}">Audit Center</a>
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    <a href="{{ route('admin.finance.audit.run', $run) }}">Run #{{ $run->id }}</a>
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    <span>Deal {{ $deal->deal_no ?? '#'.$deal->id }}</span>
                </nav>
                <h1 class="text-base font-bold leading-tight mt-1" style="color: var(--text-primary);">Deal {{ $deal->deal_no ?? '#'.$deal->id }}</h1>
                @if($deal->property_address)
                    <p class="text-xs" style="color: var(--text-muted);">{{ $deal->property_address }}</p>
                @endif
                <div class="fa-meta">
                    @if($deal->agents->isNotEmpty())
                        <span>Agents: <b>{{ $deal->agents->pluck('name')->join(', ') }}</b></span>
                    @endif
                    <span>Period: <b>{{ AuditLabelHelper::periodLabel($deal->period ?? $run->period) }}</b></span>
                    <span>Status: <b>{{ $deal->commission_status ?? '—' }}</b></span>
                    <span>Deal date: <b>{{ $deal->deal_date?->format('d M Y') ?? '—' }}</b></span>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3 md:justify-end">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
                <div class="text-right" data-tour="cop-fin-deal-run">
                    <div class="text-xs" style="color: var(--text-muted);">Audit run #{{ $run->id }} &middot; {{ AuditLabelHelper::periodLabel($run->period) }}</div>
                    <div class="mt-1">
                        @if($run->status === 'complete')
                            <span class="ds-badge ds-badge-pill ds-badge-success">complete</span>
                        @elseif($run->status === 'failed')
                            <span class="ds-badge ds-badge-pill ds-badge-danger">failed</span>
                        @else
                            <span class="ds-badge ds-badge-pill ds-badge-warning">{{ $run->status }}</span>
                        @endif
                    </div>
                </div>
                @feature('deals')
                <a href="{{ route('deals-dr2.edit', $deal) }}" class="corex-btn-outline text-xs shrink-0">View in Deal Register</a>
                @endfeature
            </div>
        </div>
    </div>

    @if($items->isEmpty())
        <div class="fa-card px-6 py-10 text-center text-sm" style="color: var(--text-muted);">
            No audit items found for this deal in run #{{ $run->id }}.
        </div>
    @else

    <div class="fa-card" data-tour="cop-fin-deal-metrics">
        <div class="fa-card-head">
            <div class="fa-card-title">Audit metrics</div>
            <div class="text-xs" style="color: var(--text-muted);">Definition-by-definition breakdown for this deal.</div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table fa-table">
                <thead>
                    <tr class="border-b" style="border-color:var(--border); color:var(--text-secondary); background:var(--surface-2)">
                        <th class="text-left px-4 py-3 w-20">Status</th>
                        <th class="text-left px-4 py-3">Definition</th>
                        <th class="text-right px-4 py-3">Expected</th>
                        <th class="text-right px-4 py-3">Actual</th>
                        <th class="text-right px-4 py-3">Diff</th>
                        <th class="text-left px-4 py-3">Message</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[color:var(--border)]">
                    @foreach($items as $item)
                        <tr class="{{ $item->severity === 'error' ? 'fa-row-err' : '' }}">
                            <td class="px-4 py-3" @if($loop->first) data-tour="cop-fin-deal-status" @endif>
                                @if($item->severity === 'error')
                                    <span class="ds-badge ds-badge-danger">fail</span>
                                @elseif($item->severity === 'warn')
                                    <span class="ds-badge ds-badge-warning">warn</span>
                                @else
                                    <span class="ds-badge ds-badge-success">match</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-sm" style="color:var(--text-primary)">{{ AuditLabelHelper::label($item->definition_key) }}</div>
                                <div class="text-xs font-mono" style="color:var(--text-muted)">{{ $item->definition_key }}</div>
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-xs" style="color:var(--text-secondary)">
                                @if($item->expected_numeric !== null)
                                    {{ AuditLabelHelper::zar((float)$item->expected_numeric) }}
                                @elseif($item->expected_json)
                                    {{ AuditLabelHelper::jsonSummary($item->expected_json) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-xs" style="color:var(--text-secondary)">
                                @if($item->actual_numeric !== null)
                                    {{ AuditLabelHelper::zar((float)$item->actual_numeric) }}
                                @elseif($item->actual_json)
                                    {{ AuditLabelHelper::jsonSummary($item->actual_json) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-xs">
                                @if($item->diff_numeric !== null)
                                    @php $diff = (float)$item->diff_numeric; @endphp
                                    <span class="{{ abs($diff) > 0.01 ? 'fa-neg' : 'fa-ok' }}">
                                        {{ AuditLabelHelper::zar($diff) }}
                                    </span>
                                @else
                                    <span style="color:var(--text-muted)">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs max-w-xs" style="color:var(--text-muted)" title="{{ $item->message }}">
                                {{ $item->message ?? '—' }}
                            </td>
                        </tr>

                        @php
                            $expJson = $item->expected_json ?? [];
                            $actJson = $item->actual_json ?? [];
                            $expAgents = is_array($expJson['by_agent'] ?? null) ? $expJson['by_agent'] : null;
                            $actAgents = is_array($actJson['by_agent'] ?? null) ? $actJson['by_agent'] : null;
                            $hasAgentData = $expAgents || $actAgents;
                            $allAgentIds = collect(array_merge(array_keys($expAgents ?? []), array_keys($actAgents ?? [])))->unique()->sort()->values();
                        @endphp
                        @if($hasAgentData)
                            <tr class="fa-sub" style="background:var(--surface-2)">
                                <td colspan="6" class="px-6 py-3">
                                    <div class="text-xs font-semibold mb-2" style="color:var(--text-secondary)">
                                        Agent allocations — {{ AuditLabelHelper::label($item->definition_key) }}
                                    </div>
                                    <div class="overflow-x-auto">
                                        <table class="text-xs w-auto min-w-[400px] rounded-md overflow-hidden" style="border:1px solid var(--border)">
                                            <thead>
                                                <tr style="background:var(--surface-2); color:var(--text-muted)">
                                                    <th class="text-left px-3 py-2">Agent</th>
                                                    <th class="text-right px-3 py-2">Expected</th>
                                                    <th class="text-right px-3 py-2">Actual</th>
                                                    <th class="text-right px-3 py-2">Diff</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-[color:var(--border)]">
                                                @php
                                                    $expTotal = 0; $actTotal = 0; $diffTotal = 0;
                                                @endphp
                                                @foreach($allAgentIds as $agentId)
                                                    @php
                                                        $expVal = isset($expAgents[$agentId]) ? (float)$expAgents[$agentId] : null;
                                                        $actVal = isset($actAgents[$agentId]) ? (float)$actAgents[$agentId] : null;
                                                        $diffVal = ($expVal !== null && $actVal !== null) ? $actVal - $expVal : null;
                                                        $expTotal += $expVal ?? 0;
                                                        $actTotal += $actVal ?? 0;
                                                        $diffTotal += $diffVal ?? 0;
                                                    @endphp
                                                    <tr style="background:var(--surface)">
                                                        <td class="px-3 py-1.5" style="color:var(--text-primary)">
                                                            {{ $agentNameMap[$agentId] ?? "Agent #{$agentId}" }}
                                                        </td>
                                                        <td class="px-3 py-1.5 text-right font-mono" style="color:var(--text-secondary)">
                                                            {{ AuditLabelHelper::zar($expVal) }}
                                                        </td>
                                                        <td class="px-3 py-1.5 text-right font-mono" style="color:var(--text-secondary)">
                                                            {{ AuditLabelHelper::zar($actVal) }}
                                                        </td>
                                                        <td class="px-3 py-1.5 text-right font-mono">
                                                            @if($diffVal !== null)
                                                                <span class="{{ abs($diffVal) > 0.01 ? 'fa-neg' : 'fa-ok' }}">
                                                                    {{ AuditLabelHelper::zar($diffVal) }}
                                                                </span>
                                                            @else
                                                                <span style="color:var(--text-muted)">—</span>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                                <tr class="font-semibold" style="background:var(--surface-2); border-top:2px solid var(--border)">
                                                    <td class="px-3 py-1.5" style="color:var(--text-primary)">Total</td>
                                                    <td class="px-3 py-1.5 text-right font-mono" style="color:var(--text-secondary)">{{ AuditLabelHelper::zar($expTotal) }}</td>
                                                    <td class="px-3 py-1.5 text-right font-mono" style="color:var(--text-secondary)">{{ AuditLabelHelper::zar($actTotal) }}</td>
                                                    <td class="px-3 py-1.5 text-right font-mono">
                                                        <span class="{{ abs($diffTotal) > 0.01 ? 'fa-neg' : 'fa-ok' }}">
                                                            {{ AuditLabelHelper::zar($diffTotal) }}
                                                        </span>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @endif

    <div>
        <a href="{{ route('admin.finance.audit.run', $run) }}" data-tour="cop-fin-deal-back" class="corex-btn-outline text-xs inline-flex items-center gap-1.5">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to Run #{{ $run->id }}
        </a>
    </div>

</div>
@endsection
