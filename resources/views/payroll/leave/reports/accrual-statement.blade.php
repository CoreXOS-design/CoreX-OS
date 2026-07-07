@extends('layouts.corex-app')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Accrual Statement" :back-route="route('payroll.leave.reports.register')" back-label="Reports" :flush="true" />

    <div class="p-4 lg:p-6 max-w-5xl">
        {{-- Employee selector --}}
        <div class="mb-4">
            <form method="GET" class="flex items-center gap-2">
                <select onchange="window.location.href=this.value" class="list-header-filter">
                    @foreach($employees as $emp)
                        <option value="{{ route('payroll.leave.reports.accrual-statement', $emp) }}" {{ $emp->id == $employee->id ? 'selected' : '' }}>{{ $emp->user->name }}</option>
                    @endforeach
                </select>
            </form>
        </div>

        {{-- Employee header --}}
        <div class="rounded-md p-4 mb-4" style="background:var(--surface); border:1px solid var(--border);">
            <p class="text-sm font-bold" style="color:var(--text-primary);">{{ $employee->user->name }}</p>
            <p class="text-xs" style="color:var(--text-secondary);">{{ $employee->designation_snapshot }} | {{ $employee->user->branch->name ?? '—' }} | Employed: {{ $employee->employment_date?->format('d M Y') }}</p>
        </div>

        {{-- Per-type statements --}}
        @foreach($statements as $stmt)
        @php $type = $stmt['type']; $bal = $stmt['balance']; $txns = $stmt['transactions']; @endphp
        <div class="mb-6">
            <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary); letter-spacing:0.05em;">{{ $type->label }}{{ $type->cycle_months == 36 ? ' (3-yr cycle)' : '' }}</h4>

            <div class="grid grid-cols-3 sm:grid-cols-6 gap-2 mb-3">
                @foreach(['Entitled'=>$bal['entitlement_days'],'Accrued'=>$bal['accrued_days'],'Carryover'=>$bal['carryover_from_previous_cycle'],'Taken'=>$bal['taken_days'],'Pending'=>$bal['pending_days'],'Available'=>$bal['available_days']] as $lbl=>$val)
                <div class="rounded-md p-2 text-center" style="background:{{ $lbl==='Available' ? 'color-mix(in srgb, var(--brand-icon, #0ea5e9) 6%, transparent)' : 'var(--surface-2)' }}; border:1px solid {{ $lbl==='Available' ? 'color-mix(in srgb, var(--brand-icon, #0ea5e9) 15%, transparent)' : 'var(--border)' }};">
                    <p class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">{{ $lbl }}</p>
                    <p class="text-sm font-bold" style="color:{{ $lbl==='Available' ? 'var(--brand-icon, #0ea5e9)' : 'var(--text-primary)' }};">{{ number_format((float)$val, 2) }}</p>
                </div>
                @endforeach
            </div>

            <p class="text-xs mb-2" style="color:var(--text-muted);">
                Cycle: {{ $bal['cycle_start_date']?->format('d M Y') ?? '—' }} – {{ $bal['cycle_end_date']?->format('d M Y') ?? '—' }}
            </p>

            @if($txns->count() > 0)
            <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm ds-table">
                        <thead>
                            <tr style="background: var(--surface-2);">
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Date</th>
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Type</th>
                                <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Days</th>
                                <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Balance</th>
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($txns as $txn)
                            <tr style="border-top:1px solid var(--border);">
                                <td class="px-4 py-3 text-xs" style="color:var(--text-secondary);">{{ $txn->effective_date?->format('d M Y') }}</td>
                                <td class="px-4 py-3 text-xs" style="color:var(--text-secondary);">{{ ucfirst(str_replace('_',' ',$txn->transaction_type)) }}</td>
                                <td class="px-4 py-3 text-right text-xs font-semibold font-mono" style="color:{{ (float)$txn->days_delta >= 0 ? 'var(--ds-green, #059669)' : 'var(--text-primary)' }};">{{ $txn->days_delta > 0 ? '+' : '' }}{{ number_format((float)$txn->days_delta, 2) }}</td>
                                <td class="px-4 py-3 text-right text-xs font-semibold font-mono" style="color:var(--text-primary);">{{ number_format((float)$txn->running_balance, 2) }}</td>
                                <td class="px-4 py-3 text-xs" style="color:var(--text-primary);">{{ \Illuminate\Support\Str::limit($txn->description, 60) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @else
                <p class="text-xs py-2" style="color:var(--text-muted);">No transactions in this cycle.</p>
            @endif
        </div>
        @endforeach

        <p class="text-xs mt-4 pt-3" style="color:var(--text-muted); border-top:1px solid var(--border);">
            Generated: {{ now()->format('d M Y H:i') }} | This statement is generated from the CoreX OS immutable audit ledger.
        </p>
    </div>
</div>
@endsection
