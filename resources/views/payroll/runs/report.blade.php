@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Payroll Report â€” {{ $run->run_number }}" :back-route="route('payroll.runs.show', $run)" back-label="Run {{ $run->run_number }}" :flush="true">
        <x-slot:actions>
            <a href="{{ route('payroll.runs.bundle', $run) }}" class="corex-btn-primary text-xs">Download Bundle</a>
            <button onclick="window.print()" class="corex-btn-outline text-xs">Print Report</button>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6 max-w-7xl">
        <p class="text-xs mb-4" style="color:var(--text-muted);">
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
            <div class="p-3 text-center" style="background:var(--surface); border:1px solid var(--border); border-radius:6px;">
                <p class="text-[10px] font-semibold uppercase" style="color:var(--text-muted);">{{ $lbl }}</p>
                <p class="text-sm font-bold" style="color:{{ $lbl === 'Net Pay' ? 'var(--brand-icon)' : 'var(--text-primary)' }};">{{ $val }}</p>
            </div>
            @endforeach
        </div>

        {{-- SECTION 1: EMP201 Statutory Summary --}}
        <div class="mb-6 p-4" style="background:var(--surface); border:1px solid var(--border); border-radius:6px;">
            <div class="flex flex-col lg:flex-row gap-6">
                <div class="lg:w-1/2">
                    <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-muted); letter-spacing:0.05em;">EMP201 Submission Data</h4>
                    <table class="w-full text-sm" style="border-collapse:collapse;">
                        <tbody>
                            <tr style="border-bottom:1px solid var(--border);">
                                <td class="py-2 text-xs" style="color:var(--text-primary);">PAYE <span style="color:var(--text-muted); font-family:monospace;">(4102)</span></td>
                                <td class="py-2 text-right text-xs font-semibold" style="color:var(--text-primary);">R {{ number_format($statutory['paye'], 2) }}</td>
                            </tr>
                            <tr style="border-bottom:1px solid var(--border);">
                                <td class="py-2 text-xs" style="color:var(--text-primary);">UIF Employee <span style="color:var(--text-muted); font-family:monospace;">(4141)</span></td>
                                <td class="py-2 text-right text-xs font-semibold" style="color:var(--text-primary);">R {{ number_format($statutory['uif_employee'], 2) }}</td>
                            </tr>
                            <tr style="border-bottom:1px solid var(--border);">
                                <td class="py-2 text-xs" style="color:var(--text-primary);">UIF Employer</td>
                                <td class="py-2 text-right text-xs font-semibold" style="color:var(--text-primary);">R {{ number_format($statutory['uif_employer'], 2) }}</td>
                            </tr>
                            <tr style="border-bottom:1px solid var(--border);">
                                <td class="py-2 text-xs" style="color:var(--text-primary);">SDL</td>
                                <td class="py-2 text-right text-xs font-semibold" style="color:var(--text-primary);">R {{ number_format($statutory['sdl'], 2) }}</td>
                            </tr>
                            <tr>
                                <td class="py-2 text-xs font-bold" style="color:var(--text-primary);">Total Statutory Liability</td>
                                <td class="py-2 text-right text-xs font-bold" style="color:var(--brand-icon);">R {{ number_format($statutory['total'], 2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="lg:w-1/2">
                    <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-muted); letter-spacing:0.05em;">Help</h4>
                    <p class="text-xs" style="color:var(--text-muted); line-height:1.6;">
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
            <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-muted); letter-spacing:0.05em;">Per-Branch Breakdown</h4>
            <div class="overflow-x-auto rounded-md" style="background:var(--surface); border:1px solid var(--border);">
                <table class="w-full text-sm" style="border-collapse:collapse;">
                    <thead>
                        <tr style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">Branch</th>
                            <th class="text-center px-2 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">Head</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">Gross</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">PAYE</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">UIF</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">Net</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($branchBreakdown as $branch => $b)
                        <tr style="border-bottom:1px solid var(--border);">
                            <td class="px-3 py-2 text-xs font-semibold" style="color:var(--text-primary);">{{ $branch }}</td>
                            <td class="px-2 py-2 text-center text-xs" style="color:var(--text-muted);">{{ $b['headcount'] }}</td>
                            <td class="px-3 py-2 text-right text-xs" style="color:var(--text-primary);">R {{ number_format($b['gross'], 2) }}</td>
                            <td class="px-3 py-2 text-right text-xs" style="color:var(--text-muted);">R {{ number_format($b['paye'], 2) }}</td>
                            <td class="px-3 py-2 text-right text-xs" style="color:var(--text-muted);">R {{ number_format($b['uif_employee'], 2) }}</td>
                            <td class="px-3 py-2 text-right text-xs font-semibold" style="color:var(--text-primary);">R {{ number_format($b['net'], 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        {{-- SECTION 3: Earning Lines Summary --}}
        <div class="mb-6">
            <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-muted); letter-spacing:0.05em;">Earning Lines Summary</h4>
            <div class="overflow-x-auto rounded-md" style="background:var(--surface); border:1px solid var(--border);">
                <table class="w-full text-sm" style="border-collapse:collapse;">
                    <thead>
                        <tr style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">SARS Code</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">Description</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">Total Amount</th>
                            <th class="text-center px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($earningsSummary as $item)
                        <tr style="border-bottom:1px solid var(--border);">
                            <td class="px-3 py-2 text-xs" style="color:var(--text-muted); font-family:monospace;">{{ $item['sars'] ?: '-' }}</td>
                            <td class="px-3 py-2 text-xs font-semibold" style="color:var(--text-primary);">{{ $item['label'] }}</td>
                            <td class="px-3 py-2 text-right text-xs font-semibold" style="color:var(--text-primary);">R {{ number_format($item['total'], 2) }}</td>
                            <td class="px-3 py-2 text-center text-xs" style="color:var(--text-muted);">{{ $item['count'] }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- SECTION 4: Deduction Lines Summary --}}
        <div class="mb-6">
            <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-muted); letter-spacing:0.05em;">Deduction Lines Summary</h4>
            <div class="overflow-x-auto rounded-md" style="background:var(--surface); border:1px solid var(--border);">
                <table class="w-full text-sm" style="border-collapse:collapse;">
                    <thead>
                        <tr style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">SARS Code</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">Description</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">Total Amount</th>
                            <th class="text-center px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($deductionsSummary as $item)
                        <tr style="border-bottom:1px solid var(--border);">
                            <td class="px-3 py-2 text-xs" style="color:var(--text-muted); font-family:monospace;">{{ $item['sars'] ?: '-' }}</td>
                            <td class="px-3 py-2 text-xs font-semibold" style="color:var(--text-primary);">{{ $item['label'] }}</td>
                            <td class="px-3 py-2 text-right text-xs font-semibold" style="color:var(--text-primary);">R {{ number_format($item['total'], 2) }}</td>
                            <td class="px-3 py-2 text-center text-xs" style="color:var(--text-muted);">{{ $item['count'] }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- SECTION 5: Leave Taken in Period --}}
        @if(isset($leaveTakenInPeriod) && $leaveTakenInPeriod->count() > 0)
        <div class="mb-6">
            <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-muted); letter-spacing:0.05em;">Leave Taken in Period</h4>
            <div class="overflow-x-auto rounded-md" style="background:var(--surface); border:1px solid var(--border);">
                <table class="w-full text-sm" style="border-collapse:collapse;">
                    <thead>
                        <tr style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">Employee</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">Type</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">Period</th>
                            <th class="text-center px-2 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">Days</th>
                            <th class="text-center px-2 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">Affects Pay</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($leaveTakenInPeriod as $la)
                        <tr style="border-bottom:1px solid var(--border);">
                            <td class="px-3 py-2 text-xs font-semibold" style="color:var(--text-primary);">{{ $la->user->name ?? '?' }}</td>
                            <td class="px-3 py-2 text-xs" style="color:var(--text-muted);">{{ $la->leaveType->label ?? '-' }}</td>
                            <td class="px-3 py-2 text-xs" style="color:var(--text-primary);">{{ $la->start_date?->format('d M') }} â€” {{ $la->end_date?->format('d M') }}</td>
                            <td class="px-2 py-2 text-center text-xs font-semibold" style="color:var(--text-primary);">{{ number_format($la->working_days_requested, 1) }}</td>
                            <td class="px-2 py-2 text-center">
                                @if($la->affects_payroll)
                                    <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); color:var(--ds-crimson); border-radius:6px;">Yes</span>
                                @else
                                    <span class="text-xs" style="color:var(--text-muted);">No</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        {{-- SECTION 6: Per-Employee Breakdown --}}
        <div class="mb-6">
            <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-muted); letter-spacing:0.05em;">Per-Employee Breakdown</h4>
            <div class="overflow-x-auto rounded-md" style="background:var(--surface); border:1px solid var(--border);">
                <table class="w-full text-sm" style="border-collapse:collapse;">
                    <thead>
                        <tr style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">Employee</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">Branch</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">Gross</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">PAYE</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">UIF</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">Net</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($run->payslips as $ps)
                        <tr style="border-bottom:1px solid var(--border);">
                            <td class="px-3 py-2 text-xs font-semibold" style="color:var(--text-primary);">{{ $ps->employee_name_snapshot }}</td>
                            <td class="px-3 py-2 text-xs" style="color:var(--text-muted);">{{ $ps->employee?->user?->branch?->name ?? '-' }}</td>
                            <td class="px-3 py-2 text-right text-xs" style="color:var(--text-primary);">R {{ number_format($ps->total_earnings, 2) }}</td>
                            <td class="px-3 py-2 text-right text-xs" style="color:var(--text-muted);">R {{ number_format($ps->paye_amount, 2) }}</td>
                            <td class="px-3 py-2 text-right text-xs" style="color:var(--text-muted);">R {{ number_format($ps->uif_employee_amount, 2) }}</td>
                            <td class="px-3 py-2 text-right text-xs font-semibold" style="color:var(--text-primary);">R {{ number_format($ps->net_pay, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr style="border-top:2px solid var(--border);">
                            <td colspan="2" class="px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-muted);">Totals</td>
                            <td class="px-3 py-2 text-right text-xs font-bold" style="color:var(--text-primary);">R {{ number_format($run->total_gross ?? 0, 2) }}</td>
                            <td class="px-3 py-2 text-right text-xs font-bold" style="color:var(--text-muted);">R {{ number_format($run->total_paye ?? 0, 2) }}</td>
                            <td class="px-3 py-2 text-right text-xs font-bold" style="color:var(--text-muted);">R {{ number_format($run->total_uif_employee ?? 0, 2) }}</td>
                            <td class="px-3 py-2 text-right text-xs font-bold" style="color:var(--brand-icon);">R {{ number_format($run->total_net ?? 0, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
