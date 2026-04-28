@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Payroll Report — {{ $run->run_number }}" :back-route="route('payroll.runs.show', $run)" back-label="Run {{ $run->run_number }}" :flush="true">
        <x-slot:actions>
            <a href="{{ route('payroll.runs.bundle', $run) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-white transition" style="background:#00d4aa; border-radius:3px;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">Download Bundle</a>
            <button onclick="window.print()" class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold transition" style="color:var(--text-primary, #0f172a); border:1px solid var(--border, #e5e7eb); border-radius:3px; background:none; cursor:pointer;">Print Report</button>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6 max-w-7xl">
        <p class="text-xs mb-4" style="color:var(--text-secondary, #6b7280);">
            {{ $run->period_month?->format('F Y') }} | {{ $run->payslip_count }} employees | Finalised {{ $run->finalised_at?->format('d M Y H:i') }} by {{ $run->finalisedBy->name ?? '?' }}
        </p>

        {{-- Top stats cards --}}
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-6">
            @foreach([
                'Headcount' => $run->payslip_count,
                'Total Gross' => 'R ' . number_format($run->total_gross ?? 0, 2),
                'Total PAYE' => 'R ' . number_format($run->total_paye ?? 0, 2),
                'Total UIF' => 'R ' . number_format($run->total_uif_employee ?? 0, 2),
                'Net Pay' => 'R ' . number_format($run->total_net ?? 0, 2),
            ] as $lbl => $val)
            <div class="p-3 text-center" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:3px;">
                <p class="text-[10px] font-semibold uppercase" style="color:var(--text-secondary, #94a3b8);">{{ $lbl }}</p>
                <p class="text-sm font-bold" style="color:{{ $lbl === 'Net Pay' ? '#00d4aa' : 'var(--text-primary, #0f172a)' }};">{{ $val }}</p>
            </div>
            @endforeach
        </div>

        {{-- SECTION 1: EMP201 Statutory Summary --}}
        <div class="mb-6 p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:3px;">
            <div class="flex flex-col lg:flex-row gap-6">
                <div class="lg:w-1/2">
                    <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">EMP201 Submission Data</h4>
                    <table class="w-full text-sm" style="border-collapse:collapse;">
                        <tbody>
                            <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                                <td class="py-2 text-xs" style="color:var(--text-primary, #0f172a);">PAYE <span style="color:var(--text-secondary, #94a3b8); font-family:monospace;">(4102)</span></td>
                                <td class="py-2 text-right text-xs font-semibold" style="color:var(--text-primary, #0f172a);">R {{ number_format($statutory['paye'], 2) }}</td>
                            </tr>
                            <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                                <td class="py-2 text-xs" style="color:var(--text-primary, #0f172a);">UIF Employee <span style="color:var(--text-secondary, #94a3b8); font-family:monospace;">(4141)</span></td>
                                <td class="py-2 text-right text-xs font-semibold" style="color:var(--text-primary, #0f172a);">R {{ number_format($statutory['uif_employee'], 2) }}</td>
                            </tr>
                            <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                                <td class="py-2 text-xs" style="color:var(--text-primary, #0f172a);">UIF Employer</td>
                                <td class="py-2 text-right text-xs font-semibold" style="color:var(--text-primary, #0f172a);">R {{ number_format($statutory['uif_employer'], 2) }}</td>
                            </tr>
                            <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                                <td class="py-2 text-xs" style="color:var(--text-primary, #0f172a);">SDL</td>
                                <td class="py-2 text-right text-xs font-semibold" style="color:var(--text-primary, #0f172a);">R {{ number_format($statutory['sdl'], 2) }}</td>
                            </tr>
                            <tr>
                                <td class="py-2 text-xs font-bold" style="color:var(--text-primary, #0f172a);">Total Statutory Liability</td>
                                <td class="py-2 text-right text-xs font-bold" style="color:#00d4aa;">R {{ number_format($statutory['total'], 2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="lg:w-1/2">
                    <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Help</h4>
                    <p class="text-xs" style="color:var(--text-secondary, #6b7280); line-height:1.6;">
                        These figures match the totals SARS expects on your monthly EMP201 submission via eFiling.
                        PAYE (4102) and UIF (4141) source codes map directly to the EMP201 form fields.
                        Full IRP5/EMP201 auto-generation arrives in Tier 2.
                    </p>
                </div>
            </div>
        </div>

        {{-- SECTION 2: Per-Branch Breakdown --}}
        @if(count($branchBreakdown) > 1)
        <div class="mb-6">
            <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Per-Branch Breakdown</h4>
            <div class="overflow-x-auto">
                <table class="w-full text-sm" style="border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border, #e5e7eb);">
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Branch</th>
                            <th class="text-center px-2 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Head</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Gross</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">PAYE</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">UIF</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Net</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($branchBreakdown as $branch => $b)
                        <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                            <td class="px-3 py-2 text-xs font-semibold" style="color:var(--text-primary, #0f172a);">{{ $branch }}</td>
                            <td class="px-2 py-2 text-center text-xs" style="color:var(--text-secondary, #6b7280);">{{ $b['headcount'] }}</td>
                            <td class="px-3 py-2 text-right text-xs" style="color:var(--text-primary, #0f172a);">R {{ number_format($b['gross'], 2) }}</td>
                            <td class="px-3 py-2 text-right text-xs" style="color:var(--text-secondary, #6b7280);">R {{ number_format($b['paye'], 2) }}</td>
                            <td class="px-3 py-2 text-right text-xs" style="color:var(--text-secondary, #6b7280);">R {{ number_format($b['uif_employee'], 2) }}</td>
                            <td class="px-3 py-2 text-right text-xs font-semibold" style="color:var(--text-primary, #0f172a);">R {{ number_format($b['net'], 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        {{-- SECTION 3: Earning Lines Summary --}}
        <div class="mb-6">
            <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Earning Lines Summary</h4>
            <div class="overflow-x-auto">
                <table class="w-full text-sm" style="border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border, #e5e7eb);">
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">SARS Code</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Description</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Total Amount</th>
                            <th class="text-center px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($earningsSummary as $item)
                        <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                            <td class="px-3 py-2 text-xs" style="color:var(--text-secondary, #6b7280); font-family:monospace;">{{ $item['sars'] ?: '-' }}</td>
                            <td class="px-3 py-2 text-xs font-semibold" style="color:var(--text-primary, #0f172a);">{{ $item['label'] }}</td>
                            <td class="px-3 py-2 text-right text-xs font-semibold" style="color:var(--text-primary, #0f172a);">R {{ number_format($item['total'], 2) }}</td>
                            <td class="px-3 py-2 text-center text-xs" style="color:var(--text-secondary, #6b7280);">{{ $item['count'] }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- SECTION 4: Deduction Lines Summary --}}
        <div class="mb-6">
            <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Deduction Lines Summary</h4>
            <div class="overflow-x-auto">
                <table class="w-full text-sm" style="border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border, #e5e7eb);">
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">SARS Code</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Description</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Total Amount</th>
                            <th class="text-center px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($deductionsSummary as $item)
                        <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                            <td class="px-3 py-2 text-xs" style="color:var(--text-secondary, #6b7280); font-family:monospace;">{{ $item['sars'] ?: '-' }}</td>
                            <td class="px-3 py-2 text-xs font-semibold" style="color:var(--text-primary, #0f172a);">{{ $item['label'] }}</td>
                            <td class="px-3 py-2 text-right text-xs font-semibold" style="color:var(--text-primary, #0f172a);">R {{ number_format($item['total'], 2) }}</td>
                            <td class="px-3 py-2 text-center text-xs" style="color:var(--text-secondary, #6b7280);">{{ $item['count'] }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- SECTION 5: Per-Employee Breakdown --}}
        <div class="mb-6">
            <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Per-Employee Breakdown</h4>
            <div class="overflow-x-auto">
                <table class="w-full text-sm" style="border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border, #e5e7eb);">
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Employee</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Branch</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Gross</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">PAYE</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">UIF</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Net</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($run->payslips as $ps)
                        <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                            <td class="px-3 py-2 text-xs font-semibold" style="color:var(--text-primary, #0f172a);">{{ $ps->employee_name_snapshot }}</td>
                            <td class="px-3 py-2 text-xs" style="color:var(--text-secondary, #6b7280);">{{ $ps->employee?->user?->branch?->name ?? '-' }}</td>
                            <td class="px-3 py-2 text-right text-xs" style="color:var(--text-primary, #0f172a);">R {{ number_format($ps->total_earnings, 2) }}</td>
                            <td class="px-3 py-2 text-right text-xs" style="color:var(--text-secondary, #6b7280);">R {{ number_format($ps->paye_amount, 2) }}</td>
                            <td class="px-3 py-2 text-right text-xs" style="color:var(--text-secondary, #6b7280);">R {{ number_format($ps->uif_employee_amount, 2) }}</td>
                            <td class="px-3 py-2 text-right text-xs font-semibold" style="color:var(--text-primary, #0f172a);">R {{ number_format($ps->net_pay, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr style="border-top:2px solid var(--border, #e5e7eb);">
                            <td colspan="2" class="px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Totals</td>
                            <td class="px-3 py-2 text-right text-xs font-bold" style="color:var(--text-primary, #0f172a);">R {{ number_format($run->total_gross ?? 0, 2) }}</td>
                            <td class="px-3 py-2 text-right text-xs font-bold" style="color:var(--text-secondary, #6b7280);">R {{ number_format($run->total_paye ?? 0, 2) }}</td>
                            <td class="px-3 py-2 text-right text-xs font-bold" style="color:var(--text-secondary, #6b7280);">R {{ number_format($run->total_uif_employee ?? 0, 2) }}</td>
                            <td class="px-3 py-2 text-right text-xs font-bold" style="color:#00d4aa;">R {{ number_format($run->total_net ?? 0, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
