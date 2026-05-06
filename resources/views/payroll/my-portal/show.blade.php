@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Payslip {{ $payslip->payslip_number }}" :back-route="route('my-portal.payslips')" back-label="My Payslips" :flush="true">
        <x-slot:actions>
            <a href="{{ route('my-portal.payslips.pdf', $payslip) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-white transition" style="background:#00d4aa; border-radius:6px;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                Download PDF
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        <div class="flex flex-col lg:flex-row gap-6">
            {{-- LEFT COLUMN (1/3) --}}
            <div class="lg:w-1/3 space-y-4">
                {{-- Employee details --}}
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Employee</h4>
                    <dl class="space-y-1.5 text-xs">
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Name</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a);">{{ $payslip->employee_name_snapshot }}</dd></div>
                        @if($payslip->id_number_snapshot)
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">ID</dt><dd style="color:var(--text-primary, #0f172a); font-family:monospace;">{{ $payslip->id_number_snapshot }}</dd></div>
                        @endif
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Designation</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a);">{{ $payslip->designation_snapshot }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Employed</dt><dd style="color:var(--text-primary, #0f172a);">{{ $payslip->employment_date_snapshot?->format('d M Y') ?? '-' }}</dd></div>
                    </dl>
                </div>

                {{-- Banking --}}
                @php $banking = $payslip->employee?->user?->bankingDetail; @endphp
                @if($banking)
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Banking</h4>
                    <dl class="space-y-1.5 text-xs">
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Bank</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a);">{{ $banking->bank_name }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Account</dt><dd style="color:var(--text-primary, #0f172a); font-family:monospace;">{{ $banking->masked_account_number }}</dd></div>
                    </dl>
                </div>
                @endif

                {{-- Payslip info --}}
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Payslip Info</h4>
                    <dl class="space-y-1.5 text-xs">
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Payslip #</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a); font-family:monospace;">{{ $payslip->payslip_number }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Period</dt><dd style="color:var(--text-primary, #0f172a);">{{ $payslip->period_month?->format('F Y') }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Pay Date</dt><dd style="color:var(--text-primary, #0f172a);">{{ $payslip->pay_date?->format('d M Y') }}</dd></div>
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
                                <th class="text-right px-2 py-1.5 text-xs font-bold" style="color:var(--text-secondary, #94a3b8);">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($earningLines as $line)
                            <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                                <td class="px-2 py-2 text-xs" style="color:var(--text-primary, #0f172a);">{{ $line->label_snapshot }}</td>
                                <td class="px-2 py-2 text-right text-xs font-semibold" style="color:var(--text-primary, #0f172a);">R {{ number_format($line->amount, 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr style="border-top:2px solid var(--border, #e5e7eb);">
                                <td class="px-2 py-2 text-xs font-bold" style="color:var(--text-secondary, #94a3b8);">Total Earnings</td>
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
                                <th class="text-right px-2 py-1.5 text-xs font-bold" style="color:var(--text-secondary, #94a3b8);">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($deductionLines as $line)
                            <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                                <td class="px-2 py-2 text-xs" style="color:var(--text-primary, #0f172a);">{{ $line->label_snapshot }}</td>
                                <td class="px-2 py-2 text-right text-xs font-semibold" style="color:var(--text-primary, #0f172a);">R {{ number_format($line->amount, 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr style="border-top:2px solid var(--border, #e5e7eb);">
                                <td class="px-2 py-2 text-xs font-bold" style="color:var(--text-secondary, #94a3b8);">Total Deductions</td>
                                <td class="px-2 py-2 text-right text-xs font-bold" style="color:var(--text-primary, #0f172a);">R {{ number_format($payslip->total_deductions, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                {{-- Employer contributions --}}
                @if($contributionLines->isNotEmpty())
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Employer Contributions</h4>
                    <p class="text-[10px] mb-2" style="color:var(--text-secondary, #94a3b8);">Not deducted from your pay.</p>
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
                <div class="p-4" style="background:rgba(0,212,170,0.04); border:1px solid rgba(0,212,170,0.15); border-radius:6px;">
                    <div class="flex items-center justify-between">
                        <h4 class="text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Net Pay</h4>
                        <p class="text-xl font-bold" style="color:#00d4aa;">R {{ number_format($payslip->net_pay, 2) }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
