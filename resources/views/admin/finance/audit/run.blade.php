@php use App\Support\Finance\AuditLabelHelper; @endphp
@extends('layouts.corex')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <a href="{{ route('admin.finance.audit.index') }}" class="text-sm text-white/60 hover:underline">&larr; Audit Center</a>
                <h2 class="text-xl font-bold text-white leading-tight mt-1">Audit Run #{{ $run->id }}</h2>
                <div class="text-sm text-white/60">
                    Period: <span class="text-white/80 font-medium">{{ AuditLabelHelper::periodLabel($run->period) }}</span>
                    &middot;
                    Engine: <span class="font-mono text-white/80">{{ $run->engine_version }}</span>
                    &middot;
                    @if($run->status === 'complete')
                        <span class="inline-flex items-center rounded-full bg-emerald-500/20 px-2 py-0.5 text-xs font-semibold text-emerald-300">complete</span>
                    @elseif($run->status === 'failed')
                        <span class="inline-flex items-center rounded-full bg-red-500/20 px-2 py-0.5 text-xs font-semibold text-red-300">failed</span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-amber-500/20 px-2 py-0.5 text-xs font-semibold text-amber-300">{{ $run->status }}</span>
                    @endif
                </div>
                <div class="text-xs text-white/40 mt-1">
                    Started: {{ $run->started_at?->format('d M Y H:i:s') ?? '—' }}
                    &middot;
                    Finished: {{ $run->finished_at?->format('d M Y H:i:s') ?? '—' }}
                </div>
            </div>

            <div class="flex gap-4">
                <div class="text-center">
                    <div class="text-2xl font-extrabold text-white">{{ number_format($counts['total']) }}</div>
                    <div class="text-xs text-white/50 uppercase tracking-wide">Total</div>
                </div>
                <div class="text-center">
                    <div class="text-2xl font-extrabold text-emerald-400">{{ number_format($counts['matches']) }}</div>
                    <div class="text-xs text-white/50 uppercase tracking-wide">Match</div>
                </div>
                <div class="text-center">
                    <div class="text-2xl font-extrabold text-red-400">{{ number_format($counts['errors']) }}</div>
                    <div class="text-xs text-white/50 uppercase tracking-wide">Fail</div>
                </div>
                <div class="text-center">
                    <div class="text-2xl font-extrabold text-amber-400">{{ number_format($counts['warnings']) }}</div>
                    <div class="text-xs text-white/50 uppercase tracking-wide">Warn</div>
                </div>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3">{{ session('status') }}</div>
    @endif

    {{-- Grouped Items --}}
    <div class="space-y-3" x-data="{ openGroups: {} }">
        @foreach($groupedItems as $groupKey => $groupItems)
            @php
                [$entityType, $entityId] = explode(':', $groupKey, 2);
                $summary = $groupSummaries[$groupKey];

                $entityName = "#{$entityId}";
                $entitySubtext = '';
                $editUrl = null;

                if ($entityType === 'deal') {
                    $deal = $dealMap[(int)$entityId] ?? null;
                    if ($deal) {
                        $entityName = $deal->deal_no ? "Deal {$deal->deal_no}" : "Deal #{$entityId}";
                        $entitySubtext = $deal->property_address ?? '';
                        if ($deal->agents->isNotEmpty()) {
                            $entitySubtext .= ($entitySubtext ? ' — ' : '') . $deal->agents->pluck('name')->join(', ');
                        }
                        $editUrl = route('admin.deals.edit', $deal);
                    }
                } elseif ($entityType === 'agent_period') {
                    $user = $userMap[(int)$entityId] ?? null;
                    $entityName = $user ? $user->name : "Agent #{$entityId}";
                    $branch = $user && $user->branch_id ? ($branchMap[(int)$user->branch_id] ?? null) : null;
                    $entitySubtext = ($branch ? $branch->name . ' — ' : '') . AuditLabelHelper::periodLabel($run->period);
                } elseif ($entityType === 'branch_period') {
                    $branch = $branchMap[(int)$entityId] ?? null;
                    $entityName = $branch ? $branch->name : "Branch #{$entityId}";
                    $entitySubtext = AuditLabelHelper::periodLabel($run->period);
                } elseif ($entityType === 'company_period') {
                    $entityName = 'Company';
                    $entitySubtext = AuditLabelHelper::periodLabel($run->period);
                }
            @endphp

            <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-hidden">
                <button type="button"
                        x-on:click="openGroups['{{ $groupKey }}'] = !openGroups['{{ $groupKey }}']"
                        class="w-full px-5 py-4 flex items-center justify-between text-left
                               {{ $summary['errors'] > 0 ? 'bg-red-50/60 dark:bg-red-900/10' : 'bg-slate-50/60 dark:bg-slate-900/40' }}
                               hover:bg-slate-100 dark:hover:bg-slate-900/60 transition-colors">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-semibold text-slate-900 dark:text-slate-100">{{ $entityName }}</span>
                            <span class="text-xs text-slate-500 dark:text-slate-400 uppercase">{{ str_replace('_', ' ', $entityType) }}</span>
                            @if($editUrl)
                                <a href="{{ $editUrl }}" class="text-xs text-[#0b2a4a] dark:text-[#00b4d8] hover:underline" onclick="event.stopPropagation()">View Deal</a>
                            @endif
                        </div>
                        @if($entitySubtext)
                            <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 truncate">{{ $entitySubtext }}</div>
                        @endif
                    </div>
                    <div class="flex items-center gap-3 flex-shrink-0 ml-4">
                        @if($summary['matches'] > 0)
                            <span class="inline-flex items-center rounded-full bg-emerald-100 dark:bg-emerald-900/30 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 dark:text-emerald-400">{{ $summary['matches'] }} match</span>
                        @endif
                        @if($summary['errors'] > 0)
                            <span class="inline-flex items-center rounded-full bg-red-100 dark:bg-red-900/30 px-2.5 py-0.5 text-xs font-semibold text-red-700 dark:text-red-400">{{ $summary['errors'] }} fail</span>
                        @endif
                        @if($summary['warnings'] > 0)
                            <span class="inline-flex items-center rounded-full bg-amber-100 dark:bg-amber-900/30 px-2.5 py-0.5 text-xs font-semibold text-amber-700 dark:text-amber-400">{{ $summary['warnings'] }} warn</span>
                        @endif
                        <svg class="w-5 h-5 text-slate-400 transition-transform"
                             :class="openGroups['{{ $groupKey }}'] ? 'rotate-180' : ''"
                             fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </div>
                </button>

                <div x-show="openGroups['{{ $groupKey }}']" x-cloak>
                    <table class="min-w-full text-sm ds-table">
                        <thead>
                            <tr class="border-b border-t border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-400 bg-slate-50 dark:bg-slate-900/40">
                                <th class="text-left px-4 py-2 w-20">Status</th>
                                <th class="text-left px-4 py-2">Definition</th>
                                <th class="text-right px-4 py-2">Expected</th>
                                <th class="text-right px-4 py-2">Actual</th>
                                <th class="text-right px-4 py-2">Diff</th>
                                <th class="text-left px-4 py-2">Message</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            @foreach($groupItems as $item)
                                <tr class="{{ $item->severity === 'error' ? 'bg-red-50/40 dark:bg-red-900/10' : '' }} hover:bg-slate-50/80 dark:hover:bg-slate-900/30">
                                    <td class="px-4 py-2.5">
                                        @if($item->severity === 'error')
                                            <span class="inline-flex items-center rounded-full bg-red-100 dark:bg-red-900/30 px-2 py-0.5 text-xs font-semibold text-red-700 dark:text-red-400">fail</span>
                                        @elseif($item->severity === 'warn')
                                            <span class="inline-flex items-center rounded-full bg-amber-100 dark:bg-amber-900/30 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:text-amber-400">warn</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-emerald-100 dark:bg-emerald-900/30 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:text-emerald-400">match</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <div class="text-sm text-slate-700 dark:text-slate-300">{{ AuditLabelHelper::label($item->definition_key) }}</div>
                                        <div class="text-xs text-slate-400 font-mono">{{ $item->definition_key }}</div>
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-mono text-slate-600 dark:text-slate-300 text-xs">
                                        @if($item->expected_numeric !== null)
                                            {{ AuditLabelHelper::zar((float)$item->expected_numeric) }}
                                        @elseif($item->expected_json)
                                            {{ AuditLabelHelper::jsonSummary($item->expected_json) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-mono text-slate-600 dark:text-slate-300 text-xs">
                                        @if($item->actual_numeric !== null)
                                            {{ AuditLabelHelper::zar((float)$item->actual_numeric) }}
                                        @elseif($item->actual_json)
                                            {{ AuditLabelHelper::jsonSummary($item->actual_json) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-mono text-xs">
                                        @if($item->diff_numeric !== null)
                                            @php $diff = (float)$item->diff_numeric; @endphp
                                            <span class="{{ abs($diff) > 0.01 ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-emerald-600 dark:text-emerald-400' }}">
                                                {{ AuditLabelHelper::zar($diff) }}
                                            </span>
                                        @else
                                            <span class="text-slate-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-slate-500 dark:text-slate-400 text-xs max-w-xs truncate" title="{{ $item->message }}">
                                        {{ $item->message ?? '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </div>

    @if($groupedItems->isEmpty())
        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 p-8 text-center text-slate-500 dark:text-slate-400">
            No audit items found for this run.
        </div>
    @endif

</div>
@endsection
