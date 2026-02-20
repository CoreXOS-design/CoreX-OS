@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    {{-- Breadcrumb --}}
    <div class="text-sm text-slate-500 dark:text-slate-400">
        <a href="{{ route('admin.finance.audit.index') }}" class="hover:underline text-blue-600 dark:text-blue-400">Audit Center</a>
        <span class="mx-1">/</span>
        <a href="{{ route('admin.finance.audit.run', $run) }}" class="hover:underline text-blue-600 dark:text-blue-400">Run #{{ $run->id }}</a>
        <span class="mx-1">/</span>
        <span>Deal #{{ $deal->id }}</span>
    </div>

    {{-- Header --}}
    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 p-5 shadow-sm">
        <div class="flex flex-wrap gap-6 items-start justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">
                    Deal #{{ $deal->id }}
                    @if($deal->deal_no)
                        <span class="text-slate-500 font-normal text-lg">({{ $deal->deal_no }})</span>
                    @endif
                </h1>
                @if($deal->property_address)
                    <div class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ $deal->property_address }}</div>
                @endif
                <div class="mt-2 flex flex-wrap gap-4 text-xs text-slate-500 dark:text-slate-400">
                    <span>Period: <span class="font-mono font-semibold text-slate-700 dark:text-slate-200">{{ $deal->period ?? '—' }}</span></span>
                    <span>Status: <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $deal->commission_status ?? '—' }}</span></span>
                    <span>Deal date: <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $deal->deal_date?->format('d M Y') ?? '—' }}</span></span>
                </div>
            </div>

            <div class="text-right">
                <div class="text-xs text-slate-400">Audit Run</div>
                <div class="font-semibold text-slate-700 dark:text-slate-300">#{{ $run->id }}</div>
                <div class="text-xs font-mono text-slate-500">{{ $run->period }}</div>
                <div class="mt-1">
                    @if($run->status === 'complete')
                        <span class="inline-flex items-center rounded-full bg-emerald-50 dark:bg-emerald-900/30 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:text-emerald-400">complete</span>
                    @elseif($run->status === 'failed')
                        <span class="inline-flex items-center rounded-full bg-red-50 dark:bg-red-900/30 px-2 py-0.5 text-xs font-semibold text-red-700 dark:text-red-400">failed</span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-amber-50 dark:bg-amber-900/30 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:text-amber-400">{{ $run->status }}</span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if($items->isEmpty())
        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 p-8 text-center text-slate-400 dark:text-slate-600">
            No audit items found for this deal in run #{{ $run->id }}.
        </div>
    @else

    {{-- Audit Metrics table --}}
    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-900/40">
            <div class="font-semibold text-slate-900 dark:text-slate-100">Audit Metrics</div>
            <div class="text-xs text-slate-400">Definition-by-definition breakdown for this deal.</div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-100 dark:border-slate-800 text-slate-600 dark:text-slate-400 bg-white dark:bg-slate-950">
                        <th class="text-left px-4 py-3 w-20">Sev</th>
                        <th class="text-left px-4 py-3">Definition</th>
                        <th class="text-right px-4 py-3">Expected</th>
                        <th class="text-right px-4 py-3">Actual</th>
                        <th class="text-right px-4 py-3">Diff</th>
                        <th class="text-left px-4 py-3">Message</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach($items as $item)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-900/40">
                            <td class="px-4 py-3">
                                @if($item->severity === 'error')
                                    <span class="inline-flex items-center rounded-full bg-red-50 dark:bg-red-900/30 px-2 py-0.5 text-xs font-semibold text-red-700 dark:text-red-400">error</span>
                                @elseif($item->severity === 'warn')
                                    <span class="inline-flex items-center rounded-full bg-amber-50 dark:bg-amber-900/30 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:text-amber-400">warn</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-slate-100 dark:bg-slate-800 px-2 py-0.5 text-xs text-slate-500">info</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-700 dark:text-slate-300">{{ $item->definition_key }}</td>
                            <td class="px-4 py-3 text-right font-mono text-slate-600 dark:text-slate-300 text-xs">
                                {{ $item->expected_numeric !== null ? number_format((float)$item->expected_numeric, 2) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-slate-600 dark:text-slate-300 text-xs">
                                {{ $item->actual_numeric !== null ? number_format((float)$item->actual_numeric, 2) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-xs">
                                @if($item->diff_numeric !== null)
                                    @php $diff = (float)$item->diff_numeric; @endphp
                                    <span class="{{ $diff > 0.01 ? 'text-red-600 dark:text-red-400 font-semibold' : ($diff < -0.01 ? 'text-amber-600 dark:text-amber-400 font-semibold' : 'text-slate-400') }}">
                                        {{ number_format($diff, 2) }}
                                    </span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-500 dark:text-slate-400 text-xs max-w-xs" title="{{ $item->message }}">
                                {{ $item->message ?? '—' }}
                            </td>
                        </tr>

                        {{-- Agent allocations: render if expected_json or actual_json contains by_agent --}}
                        @php
                            $expJson = $item->expected_json ?? [];
                            $actJson = $item->actual_json ?? [];
                            $expAgents = is_array($expJson['by_agent'] ?? null) ? $expJson['by_agent'] : null;
                            $actAgents = is_array($actJson['by_agent'] ?? null) ? $actJson['by_agent'] : null;
                            $hasAgentData = $expAgents || $actAgents;
                            $allAgentIds = collect(array_merge(array_keys($expAgents ?? []), array_keys($actAgents ?? [])))->unique()->sort()->values();
                        @endphp
                        @if($hasAgentData)
                            <tr class="bg-slate-50/60 dark:bg-slate-900/20">
                                <td colspan="6" class="px-6 py-3">
                                    <div class="text-xs font-semibold text-slate-600 dark:text-slate-400 mb-2 uppercase tracking-wide">
                                        Agent Allocations — {{ $item->definition_key }}
                                    </div>
                                    <div class="overflow-x-auto">
                                        <table class="text-xs border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden w-auto min-w-[400px]">
                                            <thead>
                                                <tr class="bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400">
                                                    <th class="text-left px-3 py-2">Agent ID</th>
                                                    <th class="text-right px-3 py-2">Expected</th>
                                                    <th class="text-right px-3 py-2">Actual</th>
                                                    <th class="text-right px-3 py-2">Diff</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
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
                                                    <tr class="bg-white dark:bg-slate-950">
                                                        <td class="px-3 py-1.5 font-mono text-slate-700 dark:text-slate-300">#{{ $agentId }}</td>
                                                        <td class="px-3 py-1.5 text-right font-mono text-slate-600 dark:text-slate-300">
                                                            {{ $expVal !== null ? number_format($expVal, 2) : '—' }}
                                                        </td>
                                                        <td class="px-3 py-1.5 text-right font-mono text-slate-600 dark:text-slate-300">
                                                            {{ $actVal !== null ? number_format($actVal, 2) : '—' }}
                                                        </td>
                                                        <td class="px-3 py-1.5 text-right font-mono">
                                                            @if($diffVal !== null)
                                                                <span class="{{ abs($diffVal) > 0.01 ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-slate-400' }}">
                                                                    {{ number_format($diffVal, 2) }}
                                                                </span>
                                                            @else
                                                                <span class="text-slate-400">—</span>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                                {{-- Totals row --}}
                                                <tr class="bg-slate-50 dark:bg-slate-900 font-semibold border-t-2 border-slate-200 dark:border-slate-700">
                                                    <td class="px-3 py-1.5 text-slate-700 dark:text-slate-300">Total</td>
                                                    <td class="px-3 py-1.5 text-right font-mono text-slate-700 dark:text-slate-300">{{ number_format($expTotal, 2) }}</td>
                                                    <td class="px-3 py-1.5 text-right font-mono text-slate-700 dark:text-slate-300">{{ number_format($actTotal, 2) }}</td>
                                                    <td class="px-3 py-1.5 text-right font-mono">
                                                        <span class="{{ abs($diffTotal) > 0.01 ? 'text-red-600 dark:text-red-400' : 'text-slate-400' }}">
                                                            {{ number_format($diffTotal, 2) }}
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

    {{-- Back link --}}
    <div>
        <a href="{{ route('admin.finance.audit.run', $run) }}"
           class="text-sm text-blue-600 dark:text-blue-400 hover:underline">
            &larr; Back to Run #{{ $run->id }}
        </a>
    </div>

</div>
@endsection
