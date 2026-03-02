@extends('layouts.corex')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-white leading-tight">Finance Definitions</h2>
                <div class="text-sm text-white/60">
                    All formula definitions registered in the Finance Engine.
                    <span class="font-medium text-white/80">{{ $computedCount }}</span> computed values stored.
                </div>
            </div>

            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('admin.finance.audit.index') }}" class="corex-btn-outline text-sm">Audit History</a>
                <form method="POST" action="{{ route('admin.finance.recalculate') }}" class="flex items-center gap-2"
                      id="recalcForm">
                    @csrf
                    <input type="hidden" name="mode" id="recalcMode" value="single">
                    <select name="period"
                            class="rounded-lg border-0 bg-white/10 text-white text-sm px-3 py-1.5 [&>option]:text-slate-900">
                        @foreach($availablePeriods as $p)
                            <option value="{{ $p }}" {{ $p === now()->format('Y-m') ? 'selected' : '' }}>
                                {{ \Carbon\Carbon::createFromFormat('Y-m', $p)->format('F Y') }}
                            </option>
                        @endforeach
                    </select>
                    <button type="submit"
                            onclick="document.getElementById('recalcMode').value='single'"
                            class="corex-btn-primary text-sm whitespace-nowrap">
                        Recalculate Period
                    </button>
                    <button type="submit"
                            onclick="if(!confirm('This will recalculate ALL periods with deals. This may take a while. Continue?')){event.preventDefault();return;}document.getElementById('recalcMode').value='all'"
                            class="rounded-lg bg-amber-600 hover:bg-amber-700 text-white px-4 py-2 text-sm font-semibold whitespace-nowrap">
                        Recalculate ALL
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- Flash messages handled by global toast system --}}

    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-800">
            <h3 class="ds-section-header">Definitions ({{ $definitions->count() }})</h3>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr class="border-b text-slate-600 dark:text-slate-300 bg-slate-50 dark:bg-slate-900/40">
                        <th class="text-left px-4 py-3">Key</th>
                        <th class="text-left px-4 py-3">Entity Type</th>
                        <th class="text-left px-4 py-3">Value Type</th>
                        <th class="text-left px-4 py-3">Version</th>
                        <th class="text-left px-4 py-3">Status</th>
                        <th class="text-left px-4 py-3">Notes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse($definitions as $def)
                        <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-900/30">
                            <td class="px-4 py-3 font-mono text-xs text-slate-800 dark:text-slate-200">{{ $def->key }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-400">{{ $def->entity_type }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-400">{{ $def->value_type }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-400">v{{ $def->version }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                    {{ $def->status === 'active' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400' }}">
                                    {{ $def->status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400 max-w-xs truncate">{{ $def->notes }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-slate-500 dark:text-slate-400">
                                No definitions registered yet. Run a recalculation to auto-create them.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
