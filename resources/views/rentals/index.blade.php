@extends('layouts.corex')

@section('content')

<div class="max-w-7xl mx-auto px-4 py-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
            <div>
                <h2 class="text-xl font-bold text-white leading-tight">Rentals Register</h2>
                <div class="text-sm text-white/60">All assigned rentals &mdash; not period-based</div>
            </div>
            <div class="flex items-center gap-6">
                <div class="text-right">
                    <div class="text-xs uppercase tracking-wide text-white/60">Total rentals</div>
                    <div class="text-2xl font-bold text-white">{{ $summary->total_count ?? 0 }}</div>
                </div>
                <div class="text-right">
                    <div class="text-xs uppercase tracking-wide text-white/60">Commission (Excl VAT)</div>
                    <div class="text-2xl font-bold text-white">R {{ number_format($summary->total_comm ?? 0, 2) }}</div>
                </div>
                <a href="{{ route('rentals.create') }}" class="corex-btn-primary">+ New Rental</a>
            </div>
        </div>
    </div>

    <div class="ds-status-card" style="border-left-color: var(--ds-cyan);">
        <h3 class="ds-section-header">Per Agent</h3>
        <div class="flex flex-wrap gap-3 mt-3">
            @foreach($summary_per_agent as $a)
                <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2">
                    <div class="font-semibold text-slate-900 dark:text-slate-100">{{ data_get($a, 'name') }}</div>
                    <div class="text-xs text-slate-500 dark:text-slate-400">
                        {{ data_get($a, 'rental_count', 0) }} rentals &mdash;
                        R {{ number_format((float) data_get($a, 'total_comm', 0), 2) }}
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-hidden">

        <table class="min-w-full text-sm ds-table">

            <thead class="bg-slate-50 dark:bg-slate-900/40 text-slate-600 dark:text-slate-300">
                <tr>
                    <th class="text-left px-4 py-2">Address</th>
                    <th class="text-left px-4 py-2">Lease Start</th>
                    <th class="text-left px-4 py-2">Lease End</th>
                    <th class="text-center px-4 py-2">M2M</th>
                    <th class="text-center px-4 py-2">Active</th>
                    <th class="text-right px-4 py-2">Commission (excl)</th>
                    <th class="text-center px-4 py-2">Assist</th>
                    <th class="text-left px-4 py-2">Agents</th>
                    <th class="text-right px-4 py-2">Edit</th>
                </tr>
            </thead>

            <tbody>

                @forelse($rentals as $rental)

                <tr>

                    <td class="px-4 py-2">
                        {{ $rental->lease_address }}
                    </td>

                    <td class="px-4 py-2">
                        {{ optional($rental->lease_start_date)->format('Y-m-d') }}
                    </td>

                    <td class="px-4 py-2">
                        {{ optional($rental->lease_end_date)->format('Y-m-d') }}
                    </td>

                    <td class="px-4 py-2 text-center">
                        @if($rental->is_month_to_month) ✓ @endif
                    </td>

                    <td class="px-4 py-2 text-center">
                        @if($rental->is_active) ✓ @endif
                    </td>

                    <td class="px-4 py-2 text-right">
                        {{ number_format(optional($rental->currentAmountVersion)->commission_excl ?? 0, 2) }}
                    </td>

                    <td class="px-4 py-2 text-center">
                        @if($rental->is_rental_assist) ✓ @endif
                    </td>

                    <td class="px-4 py-2">

                        @foreach($rental->agents as $agent)
                            <div>{{ $agent->name }}</div>
                        @endforeach

                    </td>

                    <td class="px-4 py-2 text-right">

                        <a href="{{ route('rentals.edit', $rental->id) }}"
                           class="ds-link">
                            Edit
                        </a>

                    </td>

                </tr>

                @empty

                <tr>
                    <td colspan="9" class="px-4 py-6 text-center text-slate-500 dark:text-slate-400">
                        No rentals found
                    </td>
                </tr>

                @endforelse

            </tbody>

        </table>

    </div>

</div>

@endsection
