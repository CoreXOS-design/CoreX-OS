{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Commission Management</h1>
                <p class="text-sm text-white/60">View, confirm, and manage all commission entries.</p>
            </div>
        </div>
    </div>

    {{-- Session success message (alert pattern §3.9) --}}
    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" style="color: var(--ds-green, #059669);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div class="flex-1">{{ session('success') }}</div>
        </div>
    @endif

    {{-- ══════════════════════════════════════
         FILTERS
         ══════════════════════════════════════ --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" action="{{ route('commission.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
            {{-- Agent --}}
            <div>
                <label for="filter_agent_id" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Agent</label>
                <select id="filter_agent_id" name="agent_id" class="w-full rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">All Agents</option>
                    @foreach($allAgents as $agent)
                        <option value="{{ $agent->id }}" {{ request('agent_id') == $agent->id ? 'selected' : '' }}>{{ $agent->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Status --}}
            <div>
                <label for="filter_status" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Status</label>
                <select id="filter_status" name="status" class="w-full rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="all" {{ request('status') === 'all' ? 'selected' : '' }}>All</option>
                    <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="confirmed" {{ request('status') === 'confirmed' ? 'selected' : '' }}>Confirmed</option>
                    <option value="paid" {{ request('status') === 'paid' ? 'selected' : '' }}>Paid</option>
                    <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                </select>
            </div>

            {{-- Type --}}
            <div>
                <label for="filter_type" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Type</label>
                <select id="filter_type" name="transaction_type" class="w-full rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="all" {{ request('transaction_type') === 'all' ? 'selected' : '' }}>All</option>
                    <option value="sale" {{ request('transaction_type') === 'sale' ? 'selected' : '' }}>Sale</option>
                    <option value="rental_letting" {{ request('transaction_type') === 'rental_letting' ? 'selected' : '' }}>Letting</option>
                    <option value="rental_management" {{ request('transaction_type') === 'rental_management' ? 'selected' : '' }}>Rental</option>
                    <option value="referral" {{ request('transaction_type') === 'referral' ? 'selected' : '' }}>Referral</option>
                    <option value="other" {{ request('transaction_type') === 'other' ? 'selected' : '' }}>Other</option>
                </select>
            </div>

            {{-- Date from --}}
            <div>
                <label for="filter_date_from" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">From</label>
                <input id="filter_date_from" type="date" name="date_from" value="{{ request('date_from') }}"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>

            {{-- Date to --}}
            <div>
                <label for="filter_date_to" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">To</label>
                <input id="filter_date_to" type="date" name="date_to" value="{{ request('date_to') }}"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>

            {{-- Filter / Clear buttons --}}
            <div class="flex gap-2">
                <button type="submit" class="corex-btn-primary">Filter</button>
                <a href="{{ route('commission.index') }}" class="corex-btn-outline">Clear</a>
            </div>
        </form>

        @if($entries->total() > 0)
            <div class="mt-3 pt-3 text-xs" style="border-top: 1px solid var(--border); color: var(--text-muted);">
                Showing {{ $entries->firstItem() }}&ndash;{{ $entries->lastItem() }} of {{ number_format($entries->total()) }} entries
            </div>
        @endif
    </div>

    {{-- ══════════════════════════════════════
         COMMISSION TABLE
         ══════════════════════════════════════ --}}
    @if($entries->isEmpty())
        {{-- Empty state (§3.10) --}}
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No commission entries found</h3>
            <p class="text-sm" style="color: var(--text-muted);">Adjust your filters or wait for deals to close.</p>
        </div>
    @else
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Date</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Description</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Type</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Gross</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent Split</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Company $</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($entries as $entry)
                            @php
                                $typeBadge = match($entry->transaction_type) {
                                    'sale'              => ['tint' => 'var(--brand-icon, #0ea5e9)', 'label' => 'Sale'],
                                    'rental_letting'    => ['tint' => 'var(--ds-green, #059669)',   'label' => 'Letting'],
                                    'rental_management' => ['tint' => 'var(--ds-green, #059669)',   'label' => 'Rental'],
                                    'referral'          => ['tint' => 'var(--ds-amber, #f59e0b)',   'label' => 'Referral'],
                                    default             => ['tint' => 'var(--text-muted, #9ca3af)', 'label' => 'Other'],
                                };

                                $statusBadgeClass = match($entry->status) {
                                    'pending'   => 'ds-badge ds-badge-warning',
                                    'confirmed' => 'ds-badge ds-badge-info',
                                    'paid'      => 'ds-badge ds-badge-success',
                                    'cancelled' => 'ds-badge ds-badge-danger',
                                    default     => 'ds-badge ds-badge-default',
                                };
                                $statusBadgeLabel = match($entry->status) {
                                    'pending'   => 'Pending',
                                    'confirmed' => 'Confirmed',
                                    'paid'      => 'Paid',
                                    'cancelled' => 'Cancelled',
                                    default     => ucfirst((string) $entry->status),
                                };
                            @endphp
                            <tr style="border-top: 1px solid var(--border);">
                                <td class="px-4 py-3 whitespace-nowrap" style="color: var(--text-secondary);">
                                    {{ $entry->deal_date ? $entry->deal_date->format('d M Y') : $entry->created_at->format('d M Y') }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap" style="color: var(--text-primary);">
                                    {{ $entry->user?->name ?? '—' }}
                                </td>
                                <td class="px-4 py-3 max-w-xs truncate" style="color: var(--text-secondary);">
                                    {{ \Illuminate\Support\Str::limit($entry->description, 40) }}
                                </td>
                                <td class="px-4 py-3 text-center whitespace-nowrap">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold whitespace-nowrap"
                                          style="background: color-mix(in srgb, {{ $typeBadge['tint'] }} 12%, transparent); color: {{ $typeBadge['tint'] }};">
                                        {{ $typeBadge['label'] }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap" style="color: var(--text-primary);">
                                    R {{ number_format((float) $entry->gross_commission, 0) }}
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap" style="color: var(--text-secondary);">
                                    R {{ number_format((float) $entry->net_agent_amount, 0) }}
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap font-semibold" style="color: var(--text-primary);">
                                    R {{ number_format((float) $entry->company_dollar, 0) }}
                                </td>
                                <td class="px-4 py-3 text-center whitespace-nowrap">
                                    <span class="{{ $statusBadgeClass }}">{{ $statusBadgeLabel }}</span>
                                </td>
                                <td class="px-4 py-3 text-center whitespace-nowrap">
                                    <div class="flex items-center justify-center gap-1">
                                        @if($entry->status === 'pending')
                                            <form method="POST" action="{{ route('commission.confirm', $entry->id) }}" class="inline"
                                                  onsubmit="return confirm('Confirm this commission entry?')">
                                                @csrf
                                                <button type="submit"
                                                        class="px-2.5 py-1 text-xs font-semibold rounded-md transition-colors"
                                                        style="background: color-mix(in srgb, var(--brand-button, #0ea5e9) 12%, transparent);
                                                               color: var(--brand-button, #0ea5e9);
                                                               border: 1px solid color-mix(in srgb, var(--brand-button, #0ea5e9) 25%, transparent);">
                                                    Confirm
                                                </button>
                                            </form>
                                        @elseif($entry->status === 'confirmed')
                                            <form method="POST" action="{{ route('commission.pay', $entry->id) }}" class="inline"
                                                  onsubmit="return confirm('Mark this entry as paid?')">
                                                @csrf
                                                <button type="submit"
                                                        class="px-2.5 py-1 text-xs font-semibold rounded-md transition-colors"
                                                        style="background: color-mix(in srgb, var(--ds-green, #059669) 12%, transparent);
                                                               color: var(--ds-green, #059669);
                                                               border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 25%, transparent);">
                                                    Mark Paid
                                                </button>
                                            </form>
                                        @elseif($entry->status === 'paid')
                                            <span class="text-xs" style="color: var(--text-muted);">
                                                {{ $entry->paid_at ? $entry->paid_at->format('d M') : '—' }}
                                            </span>
                                        @else
                                            <span class="text-xs" style="color: var(--text-muted);">—</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($entries->hasPages())
                <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
                    {{ $entries->links() }}
                </div>
            @endif
        </div>
    @endif

</div>
@endsection
