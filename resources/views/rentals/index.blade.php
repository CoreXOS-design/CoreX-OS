@extends('layouts.corex')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Rentals Register</h1>
                <p class="text-sm text-white/60">All assigned rentals &mdash; not period-based.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('rentals.create') }}" class="corex-btn-primary inline-flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    New Rental
                </a>
            </div>
        </div>
    </div>

    {{-- Summary stats --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="rounded-md px-4 py-3 flex items-center gap-3" style="background: var(--surface); border: 1px solid var(--border);">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-md flex-shrink-0"
                  style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12 12 3l9.75 9m-17.25-.75V21h4.5v-6h6v6h4.5V11.25" />
                </svg>
            </span>
            <div class="min-w-0">
                <div class="text-[1.625rem] font-semibold leading-none" style="color: var(--text-primary);">{{ number_format($summary->total_count ?? 0) }}</div>
                <div class="text-[0.6875rem] font-medium mt-1 uppercase tracking-wider" style="color: var(--text-muted);">Total Rentals</div>
            </div>
        </div>
        <div class="rounded-md px-4 py-3 flex items-center gap-3" style="background: var(--surface); border: 1px solid var(--border);">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-md flex-shrink-0"
                  style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
            </span>
            <div class="min-w-0">
                <div class="text-[1.625rem] font-semibold leading-none" style="color: var(--text-primary);">R {{ number_format($summary->total_comm ?? 0, 0) }}</div>
                <div class="text-[0.6875rem] font-medium mt-1 uppercase tracking-wider" style="color: var(--text-muted);">Commission (Excl VAT)</div>
            </div>
        </div>
    </div>

    {{-- Per Agent Summary --}}
    <div class="ds-status-card" style="border-left-color: var(--brand-icon, #0ea5e9);">
        <h3 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Per Agent</h3>
        @if(count($summary_per_agent) === 0)
            <p class="text-sm" style="color: var(--text-muted);">No agent splits yet.</p>
        @else
            <div class="flex flex-wrap gap-3">
                @foreach($summary_per_agent as $a)
                    <div class="rounded-md px-3 py-2" style="background: var(--surface-2); border: 1px solid var(--border);">
                        <div class="font-semibold text-sm" style="color: var(--text-primary);">{{ data_get($a, 'name') }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-secondary);">
                            {{ number_format((int) data_get($a, 'rental_count', 0)) }} rentals &mdash;
                            R {{ number_format((float) data_get($a, 'total_comm', 0), 0) }}
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Rentals Table --}}
    @if($rentals->isEmpty())
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-6 h-6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12 12 3l9.75 9m-17.25-.75V21h4.5v-6h6v6h4.5V11.25" />
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No rentals yet</h3>
            <p class="text-sm mb-4" style="color: var(--text-muted);">Capture your first rental to start tracking lease income.</p>
            <a href="{{ route('rentals.create') }}" class="corex-btn-primary">New Rental</a>
        </div>
    @else
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Address</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Lease Start</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Lease End</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);" title="Month-to-month lease">Month&#8209;to&#8209;Month</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Active</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Commission (Excl)</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);" title="Rental assist arrangement">Rental Assist</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agents</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach($rentals as $rental)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-3 font-semibold" style="color: var(--text-primary);">
                                {{ $rental->lease_address }}
                            </td>

                            <td class="px-4 py-3" style="color: var(--text-secondary);">
                                {{ optional($rental->lease_start_date)->format('Y-m-d') ?? '—' }}
                            </td>

                            <td class="px-4 py-3" style="color: var(--text-secondary);">
                                {{ optional($rental->lease_end_date)->format('Y-m-d') ?? '—' }}
                            </td>

                            <td class="px-4 py-3 text-center">
                                @if($rental->is_month_to_month)
                                    <span class="ds-badge ds-badge-info" title="Month-to-month lease">Month&#8209;to&#8209;Month</span>
                                @else
                                    <span style="color: var(--text-muted);">&mdash;</span>
                                @endif
                            </td>

                            <td class="px-4 py-3 text-center">
                                @if($rental->is_active)
                                    <span class="ds-badge ds-badge-success">Active</span>
                                @else
                                    <span class="ds-badge ds-badge-default">Inactive</span>
                                @endif
                            </td>

                            <td class="px-4 py-3 text-right font-semibold" style="color: var(--text-primary);">
                                R {{ number_format(optional($rental->currentAmountVersion)->commission_excl ?? 0, 0) }}
                            </td>

                            <td class="px-4 py-3 text-center">
                                @if($rental->is_rental_assist)
                                    <span class="ds-badge ds-badge-info" title="Rental assist arrangement">Yes</span>
                                @else
                                    <span style="color: var(--text-muted);">&mdash;</span>
                                @endif
                            </td>

                            <td class="px-4 py-3" style="color: var(--text-secondary);">
                                @forelse($rental->agents as $agent)
                                    <div>{{ $agent->name }}</div>
                                @empty
                                    <span style="color: var(--text-muted);">&mdash;</span>
                                @endforelse
                            </td>

                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('rentals.edit', $rental->id) }}" class="text-xs font-semibold" style="color: var(--brand-icon, #0ea5e9);">
                                    Edit
                                </a>
                            </td>

                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

</div>
@endsection
