@extends('layouts.corex')

@section('content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <div class="text-sm text-white/60">
                    <a class="hover:underline text-white/60" href="{{ route('agent.daily.summary') }}">&larr; Daily Summary</a>
                </div>
                <h2 class="text-xl font-bold text-white leading-tight mt-1">Daily Activity (v2)</h2>
                <div class="text-sm text-white/60">
                    Date: <span class="font-medium text-white">{{ $selectedDate }}</span>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('agent.daily.print', ['date' => $selectedDate]) }}" target="_blank"
                   class="corex-btn-outline text-sm">
                   Print Sheet
                </a>
                <form method="GET" action="{{ route('agent.daily') }}" class="flex items-center gap-2">
                    <input
                        type="date"
                        name="date"
                        value="{{ $selectedDate }}"
                        class="rounded-lg border-0 bg-white/10 text-white text-sm px-3 py-1.5 placeholder:text-white/40"
                        onchange="this.form.submit()"
                    />
                </form>
            </div>
        </div>
    </div>

{{-- Week strip (shared via controller) --}}
    @if(isset($agentDailyWeek) && isset($agentDailyWeek['days']))
        <div class="ds-status-card p-3">
            <div class="flex flex-wrap gap-2">
                @foreach($agentDailyWeek['days'] as $d)
                    <a href="{{ route('agent.daily', ['date' => $d['date']]) }}"
                       class="px-3 py-2 rounded-lg border text-sm
                       {{ $d['is_selected'] ? 'bg-[#0b2a4a] text-white border-[#0b2a4a]' : 'bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
                        <div class="font-medium">{{ $d['label'] }}</div>
                        @if($d['is_today'])
                            <div class="text-xs opacity-80">today</div>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Monthly summary --}}
    <div class="ds-status-card" style="border-left-color: var(--ds-cyan);">
        <div class="flex items-start justify-between gap-4">
            <div>
                <div class="ds-label">Month</div>
                <div class="ds-value text-lg">{{ $period }}</div>
            </div>

            <div class="grid grid-cols-3 gap-4 text-right">
                <div>
                    <div class="ds-label">Monthly target</div>
                    <div class="ds-value-lg">{{ (int)($monthlyTarget ?? 0) }}</div>
                </div>
                <div>
                    <div class="ds-label">Points MTD</div>
                    <div class="ds-value-lg">{{ (int)($mtdPoints ?? 0) }}</div>
                </div>
                <div>
                    <div class="ds-label">Remaining</div>
                    <div class="ds-value-lg">{{ (int)($remainingPoints ?? 0) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="ds-status-card">
        <form method="POST" action="{{ route('agent.daily') }}">
            @csrf
            <input type="hidden" name="activity_date" value="{{ $selectedDate }}"/>

            <div class="flex items-center justify-between gap-4">
                <div>
                    <h3 class="ds-section-header">Capture activity</h3>
                    <div class="text-sm text-slate-500 dark:text-slate-400">
                        Date: <span class="font-medium text-slate-900 dark:text-slate-100">{{ $selectedDate }}</span>
                    </div>
                </div>

                <button class="corex-btn-primary">
                    Save
                </button>
            </div>

            <div class="mt-5 overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead class="bg-slate-50 dark:bg-slate-900/40 text-slate-600 dark:text-slate-300">
                        <tr>
                            <th class="text-left py-2 pr-4 px-3">Activity</th>
                            <th class="text-left py-2 pr-4 px-3 w-32">Weight</th>
                            <th class="text-left py-2 pr-4 px-3 w-40">Done / Qty</th>
                            <th class="text-left py-2 pr-0 px-3 w-40">Points</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        @forelse($definitions as $def)
                            @php
                                $val = (int)($values[$def->id] ?? 0);
                                $pts = $val * (int)$def->weight;
                            @endphp
                            <tr>
                                <td class="py-3 pr-4 px-3">
                                    <div class="font-medium text-slate-900 dark:text-slate-100">{{ $def->name }}</div>
                                </td>
                                <td class="py-3 pr-4 px-3 text-slate-700 dark:text-slate-200">{{ (int)$def->weight }}</td>
                                <td class="py-3 pr-4 px-3">
                                    @php($mode = (string)($def->scoring_mode ?? 'count'))
                                    @if($mode === 'once')
                                        <div class="flex items-center gap-3">
                                            <input type="hidden" name="values[{{ $def->id }}]" value="0">
                                            <label class="inline-flex items-center gap-2">
                                                <input
                                                    type="checkbox"
                                                    name="values[{{ $def->id }}]"
                                                    value="1"
                                                    @checked($val > 0)
                                                    class="h-5 w-5 rounded border-slate-300 dark:border-slate-600"
                                                >
                                                <span class="text-sm text-slate-700 dark:text-slate-200">Done</span>
                                            </label>
                                        </div>
                                        <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">Tick to score once today.</div>
                                    @else
                                        <input
                                            type="number"
                                            min="0"
                                            step="1"
                                            name="values[{{ $def->id }}]"
                                            value="{{ $val }}"
                                            class="w-28 border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 rounded-lg px-3 py-2"
                                        />
                                        <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">Enter quantity to score per action.</div>
                                    @endif
                                </td>
                                <td class="py-3 pr-0 px-3 text-slate-900 dark:text-slate-100">
                                    {{ $pts }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-6 text-center text-slate-500 dark:text-slate-400">
                                    No enabled activity definitions found for your branch.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4 text-sm text-slate-700 dark:text-slate-200">
                <span class="font-medium">Total points today:</span> {{ $totalPoints }}
            </div>
        </form>
    </div>

    <div class="text-xs text-slate-500 dark:text-slate-400">
        v2 uses activity_definitions + daily_activity_entries (no legacy dynamic columns).
    </div>
</div>
@endsection
