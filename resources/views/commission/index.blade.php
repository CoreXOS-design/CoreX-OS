@extends('layouts.corex')

@section('corex-content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    {{-- Page header --}}
    <div style="background:var(--brand-default, #0b2a4a); border-radius:6px; padding:20px 24px;">
        <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">Commission Management</h2>
        <div style="font-size:0.875rem; color:rgba(255,255,255,0.55);">View, confirm, and manage all commission entries.</div>
    </div>

    {{-- Session messages --}}
    @if(session('success'))
        <div class="rounded-md border px-4 py-3 text-sm font-medium" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
            {{ session('success') }}
        </div>
    @endif

    {{-- ══════════════════════════════════════
         FILTERS
         ══════════════════════════════════════ --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:16px 20px;">
        <form method="GET" action="{{ route('commission.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
            {{-- Agent --}}
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Agent</label>
                <select name="agent_id" class="w-full rounded-md px-3 py-2 text-sm"
                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    <option value="">All Agents</option>
                    @foreach($allAgents as $agent)
                        <option value="{{ $agent->id }}" {{ request('agent_id') == $agent->id ? 'selected' : '' }}>{{ $agent->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Status --}}
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Status</label>
                <select name="status" class="w-full rounded-md px-3 py-2 text-sm"
                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    <option value="all" {{ request('status') === 'all' ? 'selected' : '' }}>All</option>
                    <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="confirmed" {{ request('status') === 'confirmed' ? 'selected' : '' }}>Confirmed</option>
                    <option value="paid" {{ request('status') === 'paid' ? 'selected' : '' }}>Paid</option>
                    <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                </select>
            </div>

            {{-- Type --}}
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Type</label>
                <select name="transaction_type" class="w-full rounded-md px-3 py-2 text-sm"
                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
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
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">From</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
            </div>

            {{-- Date to --}}
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">To</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
            </div>

            {{-- Filter button --}}
            <div class="flex gap-2">
                <button type="submit" class="corex-btn-primary text-sm px-4 py-2">Filter</button>
                <a href="{{ route('commission.index') }}" class="px-3 py-2 text-sm rounded-md no-underline" style="color:var(--text-secondary); border:1px solid var(--border);">Clear</a>
            </div>
        </form>
    </div>

    {{-- ══════════════════════════════════════
         COMMISSION TABLE
         ══════════════════════════════════════ --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
        @if($entries->isEmpty())
            <div class="p-8 text-center">
                <div class="text-sm" style="color:var(--text-secondary);">No commission entries found.</div>
                <div class="text-xs mt-1" style="color:var(--text-muted);">Adjust your filters or wait for deals to close.</div>
            </div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr style="border-bottom:1px solid var(--border); background:var(--surface-2, rgba(0,0,0,0.05));">
                        <th class="text-left text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">Date</th>
                        <th class="text-left text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">Agent</th>
                        <th class="text-left text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">Description</th>
                        <th class="text-center text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">Type</th>
                        <th class="text-right text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">Gross</th>
                        <th class="text-right text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">Agent Split</th>
                        <th class="text-right text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">Company $</th>
                        <th class="text-center text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">Status</th>
                        <th class="text-center text-xs font-bold uppercase tracking-wider px-4 py-2.5" style="color:var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($entries as $entry)
                    <tr style="border-bottom:1px solid var(--border);" class="hover:bg-white/5 transition-colors">
                        <td class="px-4 py-2.5 whitespace-nowrap" style="color:var(--text-secondary);">
                            {{ $entry->deal_date ? $entry->deal_date->format('d M Y') : $entry->created_at->format('d M Y') }}
                        </td>
                        <td class="px-4 py-2.5 whitespace-nowrap" style="color:var(--text-primary);">
                            {{ $entry->user?->name ?? 'N/A' }}
                        </td>
                        <td class="px-4 py-2.5 max-w-xs truncate" style="color:var(--text-secondary);">
                            {{ \Illuminate\Support\Str::limit($entry->description, 40) }}
                        </td>
                        <td class="px-4 py-2.5 text-center whitespace-nowrap">
                            @php
                                $typeBadge = match($entry->transaction_type) {
                                    'sale' => ['bg' => 'rgba(59,130,246,0.12)', 'color' => '#3b82f6', 'label' => 'Sale'],
                                    'rental_letting' => ['bg' => 'rgba(20,184,166,0.12)', 'color' => '#14b8a6', 'label' => 'Letting'],
                                    'rental_management' => ['bg' => 'rgba(20,184,166,0.12)', 'color' => '#14b8a6', 'label' => 'Rental'],
                                    'referral' => ['bg' => 'rgba(168,85,247,0.12)', 'color' => '#a855f7', 'label' => 'Referral'],
                                    default => ['bg' => 'rgba(148,163,184,0.12)', 'color' => '#94a3b8', 'label' => 'Other'],
                                };
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold"
                                  style="background:{{ $typeBadge['bg'] }}; color:{{ $typeBadge['color'] }};">
                                {{ $typeBadge['label'] }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap" style="color:var(--text-primary);">
                            R {{ number_format($entry->gross_commission, 2) }}
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap" style="color:var(--text-secondary);">
                            R {{ number_format($entry->net_agent_amount, 2) }}
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap font-semibold" style="color:var(--text-primary);">
                            R {{ number_format($entry->company_dollar, 2) }}
                        </td>
                        <td class="px-4 py-2.5 text-center whitespace-nowrap">
                            @php
                                $statusBadge = match($entry->status) {
                                    'pending' => ['bg' => 'rgba(245,158,11,0.12)', 'color' => '#f59e0b', 'label' => 'Pending'],
                                    'confirmed' => ['bg' => 'rgba(59,130,246,0.12)', 'color' => '#3b82f6', 'label' => 'Confirmed'],
                                    'paid' => ['bg' => 'rgba(34,197,94,0.12)', 'color' => '#22c55e', 'label' => 'Paid'],
                                    'cancelled' => ['bg' => 'rgba(239,68,68,0.12)', 'color' => '#ef4444', 'label' => 'Cancelled'],
                                    default => ['bg' => 'rgba(148,163,184,0.12)', 'color' => '#94a3b8', 'label' => ucfirst($entry->status)],
                                };
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold"
                                  style="background:{{ $statusBadge['bg'] }}; color:{{ $statusBadge['color'] }};">
                                {{ $statusBadge['label'] }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-center whitespace-nowrap">
                            <div class="flex items-center justify-center gap-1">
                                @if($entry->status === 'pending')
                                <form method="POST" action="{{ route('commission.confirm', $entry->id) }}" class="inline"
                                      onsubmit="return confirm('Confirm this commission entry?')">
                                    @csrf
                                    <button type="submit" class="px-2 py-1 text-xs font-medium rounded transition-colors"
                                            style="background:rgba(59,130,246,0.12); color:#3b82f6; border:1px solid rgba(59,130,246,0.25);">
                                        Confirm
                                    </button>
                                </form>
                                @elseif($entry->status === 'confirmed')
                                <form method="POST" action="{{ route('commission.pay', $entry->id) }}" class="inline"
                                      onsubmit="return confirm('Mark this entry as paid?')">
                                    @csrf
                                    <button type="submit" class="px-2 py-1 text-xs font-medium rounded transition-colors"
                                            style="background:rgba(34,197,94,0.12); color:#22c55e; border:1px solid rgba(34,197,94,0.25);">
                                        Mark Paid
                                    </button>
                                </form>
                                @elseif($entry->status === 'paid')
                                <span class="text-xs" style="color:var(--text-muted);">
                                    {{ $entry->paid_at ? $entry->paid_at->format('d M') : '—' }}
                                </span>
                                @else
                                <span class="text-xs" style="color:var(--text-muted);">—</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($entries->hasPages())
        <div class="px-5 py-3" style="border-top:1px solid var(--border);">
            {{ $entries->links() }}
        </div>
        @endif
        @endif
    </div>

</div>
@endsection
