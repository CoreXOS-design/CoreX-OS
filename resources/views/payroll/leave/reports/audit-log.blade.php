@extends('layouts.corex-app')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Leave Audit Log</h1>
                <p class="text-sm text-white/60">Immutable ledger of every leave transaction across the agency.</p>
            </div>
        </div>
    </div>

    {{-- Report navigation tabs --}}
    @include('payroll.leave.reports._tabs')

    {{-- Filter bar --}}
    <form method="GET" action="{{ route('payroll.leave.reports.audit-log') }}"
          class="rounded-md p-4 flex flex-wrap items-end gap-3"
          style="background: var(--surface); border: 1px solid var(--border);">
        <div>
            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">From</label>
            <input type="date" name="from" value="{{ $dateFrom }}" class="list-header-filter">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">To</label>
            <input type="date" name="to" value="{{ $dateTo }}" class="list-header-filter">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Transaction Type</label>
            <select name="txn_type" class="list-header-filter">
                <option value="">All</option>
                @foreach(['opening_balance','accrual','application_approved','application_cancelled','manual_adjustment','carry_over','forfeiture','reversal'] as $t)
                    <option value="{{ $t }}" {{ ($txnType ?? '') === $t ? 'selected' : '' }}>{{ ucfirst(str_replace('_',' ',$t)) }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="corex-btn-primary text-sm">Apply</button>
        @if($txnType || ($dateFrom ?? false) || ($dateTo ?? false))
            <a href="{{ route('payroll.leave.reports.audit-log') }}" class="text-xs font-medium" style="color:var(--text-muted);">Reset</a>
        @endif
        <div class="ml-auto text-xs" style="color:var(--text-muted);">
            Showing {{ number_format($transactions->count()) }} of {{ number_format($transactions->total()) }}
        </div>
    </form>

    {{-- Immutable-ledger notice --}}
    <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
         style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 10%, transparent);
                border: 1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 30%, transparent);
                color: var(--text-primary);">
        <svg class="w-5 h-5 flex-shrink-0" style="color: var(--brand-icon, #0ea5e9);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
        </svg>
        <div class="flex-1">This table is the immutable ledger — records cannot be edited or deleted.</div>
    </div>

    {{-- Transactions table / empty state --}}
    @if($transactions->isEmpty())
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z" />
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No transactions found</h3>
            <p class="text-sm" style="color:var(--text-muted);">No leave transactions match this period or filters. Try widening the date range or clearing filters.</p>
        </div>
    @else
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Date</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Employee</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Leave Type</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Transaction</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Days</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Description</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($transactions as $txn)
                        <tr style="border-top:1px solid var(--border);">
                            <td class="px-4 py-3 text-xs" style="color:var(--text-secondary);">{{ $txn->effective_date?->format('d M Y') }}</td>
                            <td class="px-4 py-3 text-xs font-semibold" style="color:var(--text-primary);">{{ $txn->user->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-xs" style="color:var(--text-secondary);">{{ $txn->leaveType->label ?? '—' }}</td>
                            <td class="px-4 py-3 text-xs" style="color:var(--text-secondary);">{{ ucfirst(str_replace('_',' ',$txn->transaction_type)) }}</td>
                            <td class="px-4 py-3 text-right text-xs font-semibold font-mono" style="color:{{ (float)$txn->days_delta >= 0 ? 'var(--ds-green, #059669)' : 'var(--text-primary)' }};">{{ $txn->days_delta > 0 ? '+' : '' }}{{ number_format((float)$txn->days_delta, 3) }}</td>
                            <td class="px-4 py-3 text-xs" style="color:var(--text-primary);">{{ \Illuminate\Support\Str::limit($txn->description, 50) }}</td>
                            <td class="px-4 py-3 text-xs" style="color:var(--text-secondary);">{{ $txn->createdBy->name ?? 'System' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($transactions->hasPages())
                <div class="px-4 py-3" style="border-top:1px solid var(--border);">{{ $transactions->links() }}</div>
            @endif
        </div>
    @endif
</div>
@endsection
