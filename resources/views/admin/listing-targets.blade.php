@extends('layouts.corex')

@section('content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-white leading-tight">Listing Targets</h2>
                <div class="text-sm text-white/60">Set per-agent listing targets for each month.</div>
            </div>

            <div class="flex items-center gap-2">
                <form method="GET" action="{{ route('admin.listing-targets') }}" class="flex items-center gap-2">
                    <input type="month" name="period" value="{{ $period }}"
                           class="rounded-lg border-0 bg-white/10 text-white text-sm px-3 py-1.5">
                    <button class="corex-btn-primary text-sm">View</button>
                </form>

                <a href="{{ route('admin.dashboard', ['period' => $period]) }}" class="corex-btn-outline text-sm">&larr; Dashboard</a>
            </div>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('admin.listing-targets.store') }}">
        @csrf
        <input type="hidden" name="period" value="{{ $period }}">

        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                <h3 class="ds-section-header">Targets for {{ $period }}</h3>
                <button class="corex-btn-primary text-sm">Save Targets</button>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr class="border-b text-slate-600 dark:text-slate-300 bg-slate-50 dark:bg-slate-900/40">
                            <th class="text-left px-4 py-3">Agent</th>
                            <th class="text-left px-4 py-3">Target Listings</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        @forelse($agents as $agent)
                            @php
                                $existing = $targets->get($agent->id);
                                $value = old('targets.' . $agent->id, $existing?->target_listings ?? 0);
                            @endphp
                            <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-900/30">
                                <td class="px-4 py-3 font-medium text-slate-900 dark:text-slate-100">{{ $agent->email }}</td>
                                <td class="px-4 py-3">
                                    <input type="number" min="0"
                                           name="targets[{{ $agent->id }}]"
                                           value="{{ $value }}"
                                           class="w-40 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="2" class="px-4 py-6 text-center text-slate-500 dark:text-slate-400">No agents found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @error('targets') <div class="px-4 py-2 text-sm text-rose-600">{{ $message }}</div> @enderror
        </div>
    </form>

</div>
@endsection
