@php use App\Support\Finance\AuditLabelHelper; @endphp
@extends('layouts.corex')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:var(--brand-default);" class="rounded-2xl px-6 py-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <div class="text-sm text-white/60 space-x-2">
                    <a href="{{ route('admin.finance.audit.index') }}" class="hover:underline">Audit Center</a>
                    <span>&rsaquo;</span>
                    <a href="{{ route('admin.finance.audit.run', $run) }}" class="hover:underline">Run #{{ $run->id }}</a>
                    <span>&rsaquo;</span>
                    <span class="text-white/80">Deal {{ $deal->deal_no ?? '#'.$deal->id }}</span>
                </div>
                <h2 class="text-xl font-bold text-white leading-tight mt-1">
                    Deal {{ $deal->deal_no ?? '#'.$deal->id }}
                </h2>
                @if($deal->property_address)
                    <div class="text-sm text-white/60">{{ $deal->property_address }}</div>
                @endif
                @if($deal->agents->isNotEmpty())
                    <div class="text-sm text-white/50">
                        Agents: {{ $deal->agents->pluck('name')->join(', ') }}
                    </div>
                @endif
                <div class="mt-1 flex flex-wrap gap-4 text-xs text-white/40">
                    <span>Period: <span class="text-white/70">{{ AuditLabelHelper::periodLabel($deal->period ?? $run->period) }}</span></span>
                    <span>Status: <span class="text-white/70">{{ $deal->commission_status ?? '—' }}</span></span>
                    <span>Deal date: <span class="text-white/70">{{ $deal->deal_date?->format('d M Y') ?? '—' }}</span></span>
                </div>
            </div>

            <div class="flex flex-col items-end gap-2">
                <div class="text-right">
                    <div class="text-xs text-white/40">Audit Run</div>
                    <div class="font-semibold text-white">#{{ $run->id }}</div>
                    <div class="text-xs text-white/50">{{ AuditLabelHelper::periodLabel($run->period) }}</div>
                    <div class="mt-1">
                        @if($run->status === 'complete')
                            <span class="inline-flex items-center rounded-full bg-emerald-500/20 px-2 py-0.5 text-xs font-semibold text-emerald-300">complete</span>
                        @elseif($run->status === 'failed')
                            <span class="inline-flex items-center rounded-full bg-red-500/20 px-2 py-0.5 text-xs font-semibold text-red-300">failed</span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-amber-500/20 px-2 py-0.5 text-xs font-semibold text-amber-300">{{ $run->status }}</span>
                        @endif
                    </div>
                </div>
                <a href="{{ route('admin.deals.edit', $deal) }}" class="corex-btn-outline text-sm">
                    View in Deal Register &rarr;
                </a>
            </div>
        </div>
    </div>

    @if($items->isEmpty())
        <div class="rounded-2xl border p-8 text-center" style="border-color:var(--border); background:var(--surface); color:var(--text-muted)">
            No audit items found for this deal in run #{{ $run->id }}.
        </div>
    @else

    <div class="rounded-2xl border overflow-hidden" style="border-color:var(--border); background:var(--surface)">
        <div class="px-4 py-3 border-b flex items-center justify-between" style="border-color:var(--border)">
            <h3 class="ds-section-header">Audit Metrics</h3>
            <div class="text-xs" style="color:var(--text-muted)">Definition-by-definition breakdown for this deal.</div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
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
                        <tr class="{{ $item->severity === 'error' ? 'bg-red-50/40 dark:bg-red-900/10' : '' }} hover:bg-[color:var(--surface-2)]">
                            <td class="px-4 py-3">
                                @if($item->severity === 'error')
                                    <span class="inline-flex items-center rounded-full bg-red-100 dark:bg-red-900/30 px-2 py-0.5 text-xs font-semibold text-red-700 dark:text-red-400">fail</span>
                                @elseif($item->severity === 'warn')
                                    <span class="inline-flex items-center rounded-full bg-amber-100 dark:bg-amber-900/30 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:text-amber-400">warn</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 dark:bg-emerald-900/30 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:text-emerald-400">match</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-sm" style="color:var(--text-secondary)">{{ AuditLabelHelper::label($item->definition_key) }}</div>
                                <div class="text-xs font-mono" style="color:var(--text-faint)">{{ $item->definition_key }}</div>
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
                                    <span class="{{ abs($diff) > 0.01 ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-emerald-600 dark:text-emerald-400' }}">
                                        {{ AuditLabelHelper::zar($diff) }}
                                    </span>
                                @else
                                    <span style="color:var(--text-faint)">—</span>
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
                            <tr style="background:var(--surface-2)">
                                <td colspan="6" class="px-6 py-3">
                                    <div class="text-xs font-semibold mb-2 uppercase tracking-wide" style="color:var(--text-muted)">
                                        Agent Allocations — {{ AuditLabelHelper::label($item->definition_key) }}
                                    </div>
                                    <div class="overflow-x-auto">
                                        <table class="text-xs border rounded-xl overflow-hidden w-auto min-w-[400px]" style="border-color:var(--border)">
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
                                                        <td class="px-3 py-1.5" style="color:var(--text-secondary)">
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
                                                                <span class="{{ abs($diffVal) > 0.01 ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-emerald-600 dark:text-emerald-400' }}">
                                                                    {{ AuditLabelHelper::zar($diffVal) }}
                                                                </span>
                                                            @else
                                                                <span style="color:var(--text-faint)">—</span>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                                <tr class="font-semibold border-t-2" style="background:var(--surface-2); border-color:var(--border)">
                                                    <td class="px-3 py-1.5" style="color:var(--text-secondary)">Total</td>
                                                    <td class="px-3 py-1.5 text-right font-mono" style="color:var(--text-secondary)">{{ AuditLabelHelper::zar($expTotal) }}</td>
                                                    <td class="px-3 py-1.5 text-right font-mono" style="color:var(--text-secondary)">{{ AuditLabelHelper::zar($actTotal) }}</td>
                                                    <td class="px-3 py-1.5 text-right font-mono">
                                                        <span class="{{ abs($diffTotal) > 0.01 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">
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
        <a href="{{ route('admin.finance.audit.run', $run) }}"
           class="text-sm hover:underline" style="color:var(--brand-icon)">
            &larr; Back to Run #{{ $run->id }}
        </a>
    </div>

</div>
@endsection
