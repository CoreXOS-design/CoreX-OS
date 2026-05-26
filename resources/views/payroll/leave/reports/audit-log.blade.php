@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Leave Audit Log" :flush="true" />

    <div class="p-4 lg:p-6">
        {{-- Report navigation tabs --}}
        <div class="flex gap-1 mb-4" style="border-bottom:1px solid var(--border, #e5e7eb);">
            <a href="{{ route('payroll.leave.reports.register') }}" class="px-3 py-1.5 text-xs font-semibold" style="color:var(--text-secondary, #6b7280);">Register</a>
            <a href="{{ route('payroll.leave.reports.branch-summary') }}" class="px-3 py-1.5 text-xs font-semibold" style="color:var(--text-secondary, #6b7280);">Branch Summary</a>
            <a href="{{ route('payroll.leave.reports.audit-log') }}" class="px-3 py-1.5 text-xs font-semibold" style="border-bottom:2px solid #00d4aa; color:var(--brand-icon);">Audit Log</a>
        </div>

        {{-- Filters --}}
        <form method="GET" action="{{ route('payroll.leave.reports.audit-log') }}" class="flex flex-wrap items-end gap-3 mb-4">
            <div><label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">From</label>
                <input type="date" name="from" value="{{ $dateFrom }}" class="px-2 py-1.5 text-xs focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);"></div>
            <div><label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">To</label>
                <input type="date" name="to" value="{{ $dateTo }}" class="px-2 py-1.5 text-xs focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);"></div>
            <div><label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Transaction Type</label>
                <select name="txn_type" class="px-2 py-1.5 text-xs focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                    <option value="">All</option>
                    @foreach(['opening_balance','accrual','application_approved','application_cancelled','manual_adjustment','carry_over','forfeiture','reversal'] as $t)
                        <option value="{{ $t }}" {{ ($txnType ?? '') === $t ? 'selected' : '' }}>{{ ucfirst(str_replace('_',' ',$t)) }}</option>
                    @endforeach
                </select></div>
            <button type="submit" class="px-3 py-1.5 text-xs font-semibold text-white" style="background:var(--brand-icon); border-radius:6px;">Apply</button>
            <a href="{{ route('payroll.leave.reports.audit-log') }}" class="text-xs" style="color:var(--text-secondary, #94a3b8);">Reset</a>
        </form>

        <p class="text-[10px] mb-3" style="color:var(--text-secondary, #94a3b8);">Showing {{ $transactions->total() }} transaction(s). This table is the immutable ledger â€” records cannot be edited or deleted.</p>

        @if($transactions->isEmpty())
            <div class="py-8 text-center text-xs" style="color:var(--text-secondary, #94a3b8);">No transactions found for this period.</div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm" style="border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border, #e5e7eb);">
                            <th class="text-left px-2 py-2 text-[10px] font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Date</th>
                            <th class="text-left px-2 py-2 text-[10px] font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Employee</th>
                            <th class="text-left px-2 py-2 text-[10px] font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Leave Type</th>
                            <th class="text-left px-2 py-2 text-[10px] font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Transaction</th>
                            <th class="text-right px-2 py-2 text-[10px] font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Days</th>
                            <th class="text-left px-2 py-2 text-[10px] font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Description</th>
                            <th class="text-left px-2 py-2 text-[10px] font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($transactions as $txn)
                        <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                            <td class="px-2 py-2 text-xs" style="color:var(--text-secondary, #6b7280);">{{ $txn->effective_date?->format('d M Y') }}</td>
                            <td class="px-2 py-2 text-xs font-semibold" style="color:var(--text-primary, #0f172a);">{{ $txn->user->name ?? '-' }}</td>
                            <td class="px-2 py-2 text-xs" style="color:var(--text-secondary, #6b7280);">{{ $txn->leaveType->label ?? '-' }}</td>
                            <td class="px-2 py-2 text-xs" style="color:var(--text-secondary, #6b7280);">{{ ucfirst(str_replace('_',' ',$txn->transaction_type)) }}</td>
                            <td class="px-2 py-2 text-right text-xs font-semibold" style="color:{{ (float)$txn->days_delta >= 0 ? '#00d4aa' : '#ef4444' }};">{{ $txn->days_delta > 0 ? '+' : '' }}{{ number_format((float)$txn->days_delta, 3) }}</td>
                            <td class="px-2 py-2 text-xs" style="color:var(--text-primary, #0f172a);">{{ \Illuminate\Support\Str::limit($txn->description, 50) }}</td>
                            <td class="px-2 py-2 text-xs" style="color:var(--text-secondary, #94a3b8);">{{ $txn->createdBy->name ?? 'System' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $transactions->links() }}</div>
        @endif
    </div>
</div>
@endsection
