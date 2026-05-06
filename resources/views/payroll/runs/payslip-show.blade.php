@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Payslip {{ $payslip->payslip_number }}" :back-route="route('payroll.runs.show', $run)" back-label="Run {{ $run->run_number }}" :flush="true">
        <x-slot:actions>
            <a href="{{ route('payroll.runs.payslips.pdf-preview', [$run, $payslip]) }}" target="_blank" class="inline-flex items-center gap-1 px-3 py-2 text-xs font-semibold transition" style="color:var(--text-primary, #0f172a); border:1px solid var(--border, #e5e7eb); border-radius:6px;">Preview PDF</a>
            @if($run->isDraft())
                <a href="{{ route('payroll.runs.payslips.edit', [$run, $payslip]) }}" class="inline-flex items-center gap-1 px-3 py-2 text-xs font-semibold text-white transition" style="background:var(--brand-icon); border-radius:6px;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">Edit Payslip</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        @if(!$run->isDraft())
            <div class="mb-4 p-3 text-xs font-semibold" style="background:rgba(148,163,184,0.08); border:1px solid rgba(148,163,184,0.25); border-radius:6px; color:#94a3b8;">
                This payslip is {{ $run->status }} and cannot be edited.
            </div>
        @endif
        @if($payslip->notes)
            <div class="mb-4 p-3 text-xs font-semibold" style="background:color-mix(in srgb, var(--ds-amber) 8%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 25%, transparent); border-radius:6px; color:var(--ds-amber);">
                Warnings: {{ $payslip->notes }}
            </div>
        @endif

        <div class="flex flex-col lg:flex-row gap-6">
            {{-- LEFT COLUMN (1/3) --}}
            <div class="lg:w-1/3 space-y-4">
                {{-- Employee details --}}
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Employee</h4>
                    <dl class="space-y-1.5 text-xs">
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Name</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a);">{{ $payslip->employee_name_snapshot }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">ID</dt><dd style="color:var(--text-primary, #0f172a); font-family:monospace;">{{ $payslip->id_number_snapshot ?? '-' }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Tax Ref</dt><dd style="color:var(--text-primary, #0f172a); font-family:monospace;">{{ $payslip->tax_reference_snapshot ?? '[Pending]' }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Designation</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a);">{{ $payslip->designation_snapshot }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Employed</dt><dd style="color:var(--text-primary, #0f172a);">{{ $payslip->employment_date_snapshot?->format('d M Y') ?? '-' }}</dd></div>
                    </dl>
                </div>

                {{-- Banking --}}
                @php $banking = $payslip->employee?->user?->bankingDetail; @endphp
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Banking</h4>
                    @if($banking)
                        <dl class="space-y-1.5 text-xs">
                            <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Bank</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a);">{{ $banking->bank_name }}</dd></div>
                            <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Account</dt><dd style="color:var(--text-primary, #0f172a); font-family:monospace;">{{ $banking->masked_account_number }}</dd></div>
                            <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Type</dt><dd style="color:var(--text-primary, #0f172a);">{{ ucfirst($banking->account_type) }}</dd></div>
                        </dl>
                    @else
                        <p class="text-xs" style="color:var(--text-secondary, #94a3b8);">No banking details on file.</p>
                    @endif
                </div>

                {{-- Period info --}}
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Payslip Info</h4>
                    <dl class="space-y-1.5 text-xs">
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Payslip #</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a); font-family:monospace;">{{ $payslip->payslip_number }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Period</dt><dd style="color:var(--text-primary, #0f172a);">{{ $payslip->period_month?->format('F Y') }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Pay Date</dt><dd style="color:var(--text-primary, #0f172a);">{{ $payslip->pay_date?->format('d M Y') }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Run</dt><dd style="color:var(--text-primary, #0f172a); font-family:monospace;">{{ $run->run_number }}</dd></div>
                    </dl>
                </div>
            </div>

            {{-- RIGHT COLUMN (2/3) --}}
            <div class="lg:w-2/3 space-y-4">
                {{-- Earnings --}}
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Earnings</h4>
                    <table class="w-full text-sm" style="border-collapse:collapse;">
                        <thead>
                            <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                                <th class="text-left px-2 py-1.5 text-xs font-bold" style="color:var(--text-secondary, #94a3b8);">Description</th>
                                <th class="text-left px-2 py-1.5 text-xs font-bold" style="color:var(--text-secondary, #94a3b8);">SARS</th>
                                <th class="text-right px-2 py-1.5 text-xs font-bold" style="color:var(--text-secondary, #94a3b8);">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($earningLines as $line)
                            <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                                <td class="px-2 py-2 text-xs" style="color:var(--text-primary, #0f172a);">{{ $line->label_snapshot }}</td>
                                <td class="px-2 py-2 text-xs" style="color:var(--text-secondary, #6b7280); font-family:monospace;">{{ $line->sars_source_code_snapshot ?? '-' }}</td>
                                <td class="px-2 py-2 text-right text-xs font-semibold" style="color:var(--text-primary, #0f172a);">R {{ number_format($line->amount, 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr style="border-top:2px solid var(--border, #e5e7eb);">
                                <td colspan="2" class="px-2 py-2 text-xs font-bold" style="color:var(--text-secondary, #94a3b8);">Total Earnings</td>
                                <td class="px-2 py-2 text-right text-xs font-bold" style="color:var(--text-primary, #0f172a);">R {{ number_format($payslip->total_earnings, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                {{-- Deductions --}}
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Deductions</h4>
                    <table class="w-full text-sm" style="border-collapse:collapse;">
                        <thead>
                            <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                                <th class="text-left px-2 py-1.5 text-xs font-bold" style="color:var(--text-secondary, #94a3b8);">Description</th>
                                <th class="text-left px-2 py-1.5 text-xs font-bold" style="color:var(--text-secondary, #94a3b8);">SARS</th>
                                <th class="text-right px-2 py-1.5 text-xs font-bold" style="color:var(--text-secondary, #94a3b8);">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($deductionLines as $line)
                            <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                                <td class="px-2 py-2 text-xs" style="color:var(--text-primary, #0f172a);">{{ $line->label_snapshot }}</td>
                                <td class="px-2 py-2 text-xs" style="color:var(--text-secondary, #6b7280); font-family:monospace;">{{ $line->sars_source_code_snapshot ?? '-' }}</td>
                                <td class="px-2 py-2 text-right text-xs font-semibold" style="color:var(--text-primary, #0f172a);">R {{ number_format($line->amount, 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr style="border-top:2px solid var(--border, #e5e7eb);">
                                <td colspan="2" class="px-2 py-2 text-xs font-bold" style="color:var(--text-secondary, #94a3b8);">Total Deductions</td>
                                <td class="px-2 py-2 text-right text-xs font-bold" style="color:var(--text-primary, #0f172a);">R {{ number_format($payslip->total_deductions, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                {{-- Employer contributions --}}
                @if($contributionLines->isNotEmpty())
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Employer Contributions</h4>
                    <p class="text-[10px] mb-2" style="color:var(--text-secondary, #94a3b8);">Not deducted from employee.</p>
                    <table class="w-full text-sm" style="border-collapse:collapse;">
                        <tbody>
                            @foreach($contributionLines as $line)
                            <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                                <td class="px-2 py-2 text-xs" style="color:var(--text-primary, #0f172a);">{{ $line->label_snapshot }}</td>
                                <td class="px-2 py-2 text-right text-xs" style="color:var(--text-secondary, #6b7280);">R {{ number_format($line->amount, 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif

                {{-- Net pay card --}}
                <div class="p-4" style="background:rgba(0,212,170,0.04); border:1px solid color-mix(in srgb, var(--brand-icon) 15%, transparent); border-radius:6px;">
                    <div class="flex items-center justify-between">
                        <h4 class="text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Net Pay</h4>
                        <p class="text-xl font-bold" style="color:var(--brand-icon);">R {{ number_format($payslip->net_pay, 2) }}</p>
                    </div>
                    <div class="flex justify-between mt-2 text-[10px]" style="color:var(--text-secondary, #94a3b8);">
                        <span>Taxable income: R {{ number_format($payslip->taxable_income, 2) }}</span>
                        <span>UIF (employer): R {{ number_format($payslip->uif_employer_amount, 2) }} | SDL: R {{ number_format($payslip->sdl_amount, 2) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
