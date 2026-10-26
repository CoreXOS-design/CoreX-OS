@php use App\Support\Finance\AuditLabelHelper; @endphp
@extends('layouts.corex')

@section('corex-content')
@include('admin.finance.audit._styles')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Audit Run #{{ $run->id }}</h1>
                <div class="fa-meta" style="margin-top:.15rem">
                    <span>Period: <b>{{ AuditLabelHelper::periodLabel($run->period) }}</b></span>
                    <span>Engine: <b class="font-mono">{{ $run->engine_version }}</b></span>
                    <span>Started: <b>{{ $run->started_at?->format('d M Y H:i:s') ?? '—' }}</b></span>
                    <span>Finished: <b>{{ $run->finished_at?->format('d M Y H:i:s') ?? '—' }}</b></span>
                    <span>
                        @if($run->status === 'complete')
                            <span class="ds-badge ds-badge-pill ds-badge-success">complete</span>
                        @elseif($run->status === 'failed')
                            <span class="ds-badge ds-badge-pill ds-badge-danger">failed</span>
                        @else
                            <span class="ds-badge ds-badge-pill ds-badge-warning">{{ $run->status }}</span>
                        @endif
                    </span>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.finance.audit.index') }}" class="corex-btn-outline text-xs shrink-0 inline-flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    Audit Center
                </a>
            </div>
        </div>
    </div>

    {{-- Totals --}}
    <div class="fa-tiles">
        <div class="fa-tile"><div class="fa-tile-n">{{ number_format($counts['total']) }}</div><div class="fa-tile-l">Total checks</div></div>
        <div class="fa-tile ok"><div class="fa-tile-n">{{ number_format($counts['matches']) }}</div><div class="fa-tile-l">Match</div></div>
        <div class="fa-tile bad"><div class="fa-tile-n">{{ number_format($counts['errors']) }}</div><div class="fa-tile-l">Fail</div></div>
        <div class="fa-tile warn"><div class="fa-tile-n">{{ number_format($counts['warnings']) }}</div><div class="fa-tile-l">Warn</div></div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent); color: var(--text-primary);">
            {{ session('status') }}
        </div>
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

            <div class="fa-card">
                <button type="button"
                        x-on:click="openGroups['{{ $groupKey }}'] = !openGroups['{{ $groupKey }}']"
                        class="fa-group-btn {{ $summary['errors'] > 0 ? 'has-err' : '' }}">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-semibold text-sm" style="color:var(--text-primary)">{{ $entityName }}</span>
                            <span class="text-xs" style="color:var(--text-muted)">{{ ucfirst(str_replace('_', ' ', $entityType)) }}</span>
                            @if($editUrl)
                                <a href="{{ $editUrl }}" class="fa-link text-xs" onclick="event.stopPropagation()">View deal</a>
                            @endif
                        </div>
                        @if($entitySubtext)
                            <div class="text-xs mt-0.5 truncate" style="color:var(--text-muted)">{{ $entitySubtext }}</div>
                        @endif
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        @if($summary['matches'] > 0)
                            <span class="ds-badge ds-badge-pill ds-badge-success">{{ $summary['matches'] }} match</span>
                        @endif
                        @if($summary['errors'] > 0)
                            <span class="ds-badge ds-badge-pill ds-badge-danger">{{ $summary['errors'] }} fail</span>
                        @endif
                        @if($summary['warnings'] > 0)
                            <span class="ds-badge ds-badge-pill ds-badge-warning">{{ $summary['warnings'] }} warn</span>
                        @endif
                        <svg class="w-4 h-4 transition-transform" style="color:var(--text-muted)"
                             :class="openGroups['{{ $groupKey }}'] ? 'rotate-180' : ''"
                             fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </div>
                </button>

                <div x-show="openGroups['{{ $groupKey }}']" x-cloak class="overflow-x-auto">
                    <table class="min-w-full text-sm ds-table fa-table">
                        <thead>
                            <tr class="border-b border-t" style="border-color:var(--border); color:var(--text-secondary); background:var(--surface-2)">
                                <th class="text-left px-4 py-2 w-20">Status</th>
                                <th class="text-left px-4 py-2">Definition</th>
                                <th class="text-right px-4 py-2">Expected</th>
                                <th class="text-right px-4 py-2">Actual</th>
                                <th class="text-right px-4 py-2">Diff</th>
                                <th class="text-left px-4 py-2">Message</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[color:var(--border)]">
                            @foreach($groupItems as $item)
                                <tr class="{{ $item->severity === 'error' ? 'fa-row-err' : '' }}">
                                    <td class="px-4 py-2.5">
                                        @if($item->severity === 'error')
                                            <span class="ds-badge ds-badge-danger">fail</span>
                                        @elseif($item->severity === 'warn')
                                            <span class="ds-badge ds-badge-warning">warn</span>
                                        @else
                                            <span class="ds-badge ds-badge-success">match</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <div class="text-sm" style="color:var(--text-primary)">{{ AuditLabelHelper::label($item->definition_key) }}</div>
                                        <div class="text-xs font-mono" style="color:var(--text-muted)">{{ $item->definition_key }}</div>
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-mono text-xs" style="color:var(--text-secondary)">
                                        @if($item->expected_numeric !== null)
                                            {{ AuditLabelHelper::zar((float)$item->expected_numeric) }}
                                        @elseif($item->expected_json)
                                            {{ AuditLabelHelper::jsonSummary($item->expected_json) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-mono text-xs" style="color:var(--text-secondary)">
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
                                            <span class="{{ abs($diff) > 0.01 ? 'fa-neg' : 'fa-ok' }}">
                                                {{ AuditLabelHelper::zar($diff) }}
                                            </span>
                                        @else
                                            <span style="color:var(--text-muted)">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-xs max-w-xs truncate" style="color:var(--text-muted)" title="{{ $item->message }}">
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
        <div class="fa-card px-6 py-10 text-center text-sm" style="color: var(--text-muted);">
            No audit items found for this run.
        </div>
    @endif

</div>
@endsection
