@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    {{-- Breadcrumb --}}
    <div class="text-sm text-slate-500 dark:text-slate-400">
        <a href="{{ route('admin.finance.audit.index') }}" class="hover:underline text-blue-600 dark:text-blue-400">Audit Center</a>
        <span class="mx-1">/</span>
        <span>Run #{{ $run->id }}</span>
    </div>

    {{-- Header card --}}
    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 p-5 shadow-sm">
        <div class="flex flex-wrap gap-6 items-start justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">Audit Run #{{ $run->id }}</h1>
                <div class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Period: <span class="font-mono font-semibold text-slate-700 dark:text-slate-200">{{ $run->period }}</span>
                    &nbsp;·&nbsp;
                    Engine: <span class="font-mono text-slate-700 dark:text-slate-200">{{ $run->engine_version }}</span>
                    &nbsp;·&nbsp;
                    @if($run->status === 'complete')
                        <span class="inline-flex items-center rounded-full bg-emerald-50 dark:bg-emerald-900/30 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:text-emerald-400">complete</span>
                    @elseif($run->status === 'failed')
                        <span class="inline-flex items-center rounded-full bg-red-50 dark:bg-red-900/30 px-2 py-0.5 text-xs font-semibold text-red-700 dark:text-red-400">failed</span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-amber-50 dark:bg-amber-900/30 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:text-amber-400">{{ $run->status }}</span>
                    @endif
                </div>
                <div class="mt-1 text-xs text-slate-400">
                    Started: {{ $run->started_at?->format('d M Y H:i:s') ?? '—' }}
                    &nbsp;·&nbsp;
                    Finished: {{ $run->finished_at?->format('d M Y H:i:s') ?? '—' }}
                </div>
            </div>

            <div class="flex gap-4">
                <div class="text-center">
                    <div class="text-2xl font-extrabold text-slate-900 dark:text-slate-100">{{ number_format($counts['total']) }}</div>
                    <div class="text-xs text-slate-500 uppercase tracking-wide">Total</div>
                </div>
                <div class="text-center">
                    <div class="text-2xl font-extrabold text-red-600 dark:text-red-400">{{ number_format($counts['errors']) }}</div>
                    <div class="text-xs text-slate-500 uppercase tracking-wide">Errors</div>
                </div>
                <div class="text-center">
                    <div class="text-2xl font-extrabold text-amber-600 dark:text-amber-400">{{ number_format($counts['warnings']) }}</div>
                    <div class="text-xs text-slate-500 uppercase tracking-wide">Warnings</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ route('admin.finance.audit.run', $run) }}"
          class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 p-4 shadow-sm">
        <div class="flex flex-wrap gap-3 items-end">
            <div>
                <label class="block text-xs text-slate-500 mb-1">Severity</label>
                <select name="severity"
                        class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                    <option value="all"   {{ request('severity','all') === 'all'   ? 'selected' : '' }}>All</option>
                    <option value="error" {{ request('severity') === 'error' ? 'selected' : '' }}>Error</option>
                    <option value="warn"  {{ request('severity') === 'warn'  ? 'selected' : '' }}>Warning</option>
                    <option value="info"  {{ request('severity') === 'info'  ? 'selected' : '' }}>Info</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Definition key</label>
                <input type="text" name="definition_key" value="{{ request('definition_key') }}"
                       placeholder="e.g. net_commission"
                       class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm w-44">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Entity type</label>
                <select name="entity_type"
                        class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                    <option value="">Any</option>
                    <option value="deal"           {{ request('entity_type') === 'deal'           ? 'selected' : '' }}>deal</option>
                    <option value="agent_period"   {{ request('entity_type') === 'agent_period'   ? 'selected' : '' }}>agent_period</option>
                    <option value="branch_period"  {{ request('entity_type') === 'branch_period'  ? 'selected' : '' }}>branch_period</option>
                    <option value="company_period" {{ request('entity_type') === 'company_period' ? 'selected' : '' }}>company_period</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Entity ID</label>
                <input type="text" name="entity_id" value="{{ request('entity_id') }}"
                       placeholder="ID"
                       class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm w-24">
            </div>
            <div class="flex items-center gap-2 pb-0.5">
                <input type="checkbox" name="diff_only" id="diff_only" value="1"
                       {{ request('diff_only') ? 'checked' : '' }}
                       class="rounded border-slate-300">
                <label for="diff_only" class="text-sm text-slate-700 dark:text-slate-300 select-none">Diff only</label>
            </div>
            <div class="flex gap-2">
                <button class="rounded-xl bg-slate-900 dark:bg-slate-700 text-white px-4 py-2 text-sm font-semibold">Apply</button>
                <a href="{{ route('admin.finance.audit.run', $run) }}"
                   class="rounded-xl border border-slate-200 dark:border-slate-700 px-4 py-2 text-sm text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">Clear</a>
            </div>
        </div>
    </form>

    {{-- Items table --}}
    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-900/40 flex items-center justify-between">
            <div class="font-semibold text-slate-900 dark:text-slate-100">Audit Items</div>
            <div class="text-xs text-slate-400">{{ $items->total() }} result{{ $items->total() === 1 ? '' : 's' }}</div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-100 dark:border-slate-800 text-slate-600 dark:text-slate-400 bg-white dark:bg-slate-950">
                        <th class="text-left px-4 py-3 w-20">Sev</th>
                        <th class="text-left px-4 py-3">Definition</th>
                        <th class="text-left px-4 py-3">Type</th>
                        <th class="text-left px-4 py-3">Entity</th>
                        <th class="text-right px-4 py-3">Expected</th>
                        <th class="text-right px-4 py-3">Actual</th>
                        <th class="text-right px-4 py-3">Diff</th>
                        <th class="text-left px-4 py-3">Message</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse($items as $item)
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
                            <td class="px-4 py-3 text-slate-500 text-xs">{{ $item->entity_type }}</td>
                            <td class="px-4 py-3">
                                @if($item->entity_type === 'deal')
                                    <a href="{{ route('admin.finance.audit.deal', [$item->entity_id, 'run_id' => $run->id]) }}"
                                       class="text-blue-600 dark:text-blue-400 hover:underline font-semibold">
                                        #{{ $item->entity_id }}
                                    </a>
                                @else
                                    <span class="text-slate-700 dark:text-slate-300">#{{ $item->entity_id }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-slate-600 dark:text-slate-300 text-xs">
                                {{ $item->expected_numeric !== null ? number_format((float)$item->expected_numeric, 2) : ($item->expected_json ? '[json]' : '—') }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-slate-600 dark:text-slate-300 text-xs">
                                {{ $item->actual_numeric !== null ? number_format((float)$item->actual_numeric, 2) : ($item->actual_json ? '[json]' : '—') }}
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
                            <td class="px-4 py-3 text-slate-500 dark:text-slate-400 text-xs max-w-xs truncate" title="{{ $item->message }}">
                                {{ $item->message ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-slate-400 dark:text-slate-600">No items match the current filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($items->hasPages())
            <div class="px-5 py-4 border-t border-slate-100 dark:border-slate-800">
                {{ $items->links() }}
            </div>
        @endif
    </div>

</div>
@endsection
