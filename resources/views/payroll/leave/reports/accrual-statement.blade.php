@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Accrual Statement" :back-route="route('payroll.leave.reports.register')" back-label="Reports" :flush="true" />

    <div class="p-4 lg:p-6 max-w-5xl">
        {{-- Employee selector --}}
        <div class="mb-4">
            <form method="GET" class="flex items-center gap-2">
                <select onchange="window.location.href=this.value" class="px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;">
                    @foreach($employees as $emp)
                        <option value="{{ route('payroll.leave.reports.accrual-statement', $emp) }}" {{ $emp->id == $employee->id ? 'selected' : '' }}>{{ $emp->user->name }}</option>
                    @endforeach
                </select>
            </form>
        </div>

        {{-- Employee header --}}
        <div class="p-4 mb-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:3px;">
            <p class="text-sm font-bold" style="color:var(--text-primary, #0f172a);">{{ $employee->user->name }}</p>
            <p class="text-xs" style="color:var(--text-secondary, #6b7280);">{{ $employee->designation_snapshot }} | {{ $employee->user->branch->name ?? '-' }} | Employed: {{ $employee->employment_date?->format('d M Y') }}</p>
        </div>

        {{-- Per-type statements --}}
        @foreach($statements as $stmt)
        @php $type = $stmt['type']; $bal = $stmt['balance']; $txns = $stmt['transactions']; @endphp
        <div class="mb-6">
            <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">{{ $type->label }}{{ $type->cycle_months == 36 ? ' (3-yr cycle)' : '' }}</h4>

            <div class="grid grid-cols-3 sm:grid-cols-6 gap-2 mb-3">
                @foreach(['Entitled'=>$bal['entitlement_days'],'Accrued'=>$bal['accrued_days'],'Carryover'=>$bal['carryover_from_previous_cycle'],'Taken'=>$bal['taken_days'],'Pending'=>$bal['pending_days'],'Available'=>$bal['available_days']] as $lbl=>$val)
                <div class="p-2 text-center" style="background:{{ $lbl==='Available' ? 'rgba(0,212,170,0.04)' : 'var(--surface-2, #f8fafc)' }}; border:1px solid {{ $lbl==='Available' ? 'rgba(0,212,170,0.15)' : 'var(--border, #e5e7eb)' }}; border-radius:3px;">
                    <p class="text-[9px] font-semibold uppercase" style="color:var(--text-secondary, #94a3b8);">{{ $lbl }}</p>
                    <p class="text-sm font-bold" style="color:{{ $lbl==='Available' ? '#00d4aa' : 'var(--text-primary, #0f172a)' }};">{{ number_format((float)$val, 2) }}</p>
                </div>
                @endforeach
            </div>

            <p class="text-[10px] mb-2" style="color:var(--text-secondary, #94a3b8);">
                Cycle: {{ $bal['cycle_start_date']?->format('d M Y') ?? '-' }} — {{ $bal['cycle_end_date']?->format('d M Y') ?? '-' }}
            </p>

            @if($txns->count() > 0)
            <table class="w-full text-sm" style="border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                        <th class="text-left px-2 py-1.5 text-[10px] font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Date</th>
                        <th class="text-left px-2 py-1.5 text-[10px] font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Type</th>
                        <th class="text-right px-2 py-1.5 text-[10px] font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Days</th>
                        <th class="text-right px-2 py-1.5 text-[10px] font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Balance</th>
                        <th class="text-left px-2 py-1.5 text-[10px] font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Description</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($txns as $txn)
                    <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                        <td class="px-2 py-1.5 text-xs" style="color:var(--text-secondary, #6b7280);">{{ $txn->effective_date?->format('d M Y') }}</td>
                        <td class="px-2 py-1.5 text-xs" style="color:var(--text-secondary, #6b7280);">{{ ucfirst(str_replace('_',' ',$txn->transaction_type)) }}</td>
                        <td class="px-2 py-1.5 text-right text-xs font-semibold" style="color:{{ (float)$txn->days_delta >= 0 ? '#00d4aa' : '#ef4444' }};">{{ $txn->days_delta > 0 ? '+' : '' }}{{ number_format((float)$txn->days_delta, 2) }}</td>
                        <td class="px-2 py-1.5 text-right text-xs font-semibold" style="color:var(--text-primary, #0f172a);">{{ number_format((float)$txn->running_balance, 2) }}</td>
                        <td class="px-2 py-1.5 text-xs" style="color:var(--text-primary, #0f172a);">{{ \Illuminate\Support\Str::limit($txn->description, 60) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @else
                <p class="text-xs py-2" style="color:var(--text-secondary, #94a3b8);">No transactions in this cycle.</p>
            @endif
        </div>
        @endforeach

        <p class="text-[10px] mt-4 pt-3" style="color:var(--text-secondary, #94a3b8); border-top:1px solid var(--border, #e5e7eb);">
            Generated: {{ now()->format('d M Y H:i') }} | This statement is generated from the CoreX OS immutable audit ledger.
        </p>
    </div>
</div>
@endsection
