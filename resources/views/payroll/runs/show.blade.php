@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6" x-data="{ showFinalise: false }">
    <x-page-header title="Payroll Run {{ $run->run_number }}" :back-route="route('payroll.runs.index')" back-label="Runs" :flush="true">
        <x-slot:actions>
            @if($run->isDraft())
                <button @click="showFinalise = true" class="inline-flex items-center px-3 py-2 text-xs font-semibold text-white transition" style="background:#00d4aa; border-radius:6px; cursor:pointer;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">Finalise</button>
            @elseif($run->isFinalised())
                <a href="{{ route('payroll.runs.report', $run) }}" class="inline-flex items-center px-3 py-2 text-xs font-semibold transition" style="color:var(--text-primary, #0f172a); border:1px solid var(--border, #e5e7eb); border-radius:6px;">View Report</a>
                <a href="{{ route('payroll.runs.bundle', $run) }}" class="inline-flex items-center px-3 py-2 text-xs font-semibold text-white transition" style="background:#00d4aa; border-radius:6px;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">Download Bundle</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        @if(session('success'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:rgba(0,212,170,0.08); border:1px solid rgba(0,212,170,0.25); border-radius:6px; color:#00d4aa;">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.25); border-radius:6px; color:#ef4444;">{{ session('error') }}</div>
        @endif

        {{-- Run header card --}}
        <div class="flex flex-wrap items-center gap-4 mb-4 p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1">
                    <h2 class="text-sm font-bold" style="color:var(--text-primary, #0f172a);">{{ $run->period_month?->format('F Y') }}</h2>
                    @if($run->isDraft())
                        <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(234,179,8,0.1); color:#eab308; border-radius:6px;">Draft</span>
                    @elseif($run->isFinalised())
                        <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(0,212,170,0.1); color:#00d4aa; border-radius:6px;">Finalised</span>
                    @else
                        <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(148,163,184,0.15); color:#94a3b8; border-radius:6px;">Cancelled</span>
                    @endif
                </div>
                <p class="text-xs" style="color:var(--text-secondary, #6b7280);">Pay date: {{ $run->pay_date?->format('d M Y') }} | Created by {{ $run->createdBy->name ?? '?' }} on {{ $run->created_at?->format('d M Y H:i') }}</p>
                @if($run->isFinalised())
                    <p class="text-xs mt-1" style="color:#00d4aa;">Finalised by {{ $run->finalisedBy->name ?? '?' }} on {{ $run->finalised_at?->format('d M Y H:i') }}</p>
                @endif
                @if($run->cancellation_reason)
                    <p class="text-xs mt-1" style="color:#ef4444;">Cancelled: {{ $run->cancellation_reason }}</p>
                @endif
            </div>
        </div>

        {{-- Summary totals --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
            <div class="p-3 text-center" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                <p class="text-[10px] font-semibold uppercase" style="color:var(--text-secondary, #94a3b8);">Headcount</p>
                <p class="text-lg font-bold" style="color:var(--text-primary, #0f172a);">{{ $run->payslip_count ?? 0 }}</p>
            </div>
            <div class="p-3 text-center" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                <p class="text-[10px] font-semibold uppercase" style="color:var(--text-secondary, #94a3b8);">Total Gross</p>
                <p class="text-lg font-bold" style="color:var(--text-primary, #0f172a);">R {{ number_format($run->total_gross ?? 0, 2) }}</p>
            </div>
            <div class="p-3 text-center" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                <p class="text-[10px] font-semibold uppercase" style="color:var(--text-secondary, #94a3b8);">Total Deductions</p>
                <p class="text-lg font-bold" style="color:var(--text-primary, #0f172a);">R {{ number_format(($run->total_paye ?? 0) + ($run->total_uif_employee ?? 0), 2) }}</p>
            </div>
            <div class="p-3 text-center" style="background:rgba(0,212,170,0.04); border:1px solid rgba(0,212,170,0.15); border-radius:6px;">
                <p class="text-[10px] font-semibold uppercase" style="color:var(--text-secondary, #94a3b8);">Total Net</p>
                <p class="text-lg font-bold" style="color:#00d4aa;">R {{ number_format($run->total_net ?? 0, 2) }}</p>
            </div>
        </div>

        {{-- Payslip list --}}
        @if($run->payslips->isEmpty())
            <div class="py-8 text-center text-sm" style="color:var(--text-secondary, #94a3b8);">No payslips in this run.</div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm" style="border-collapse:collapse;">
                    <colgroup>
                        <col>{{-- Employee --}}
                        <col style="width:100px;">{{-- Branch --}}
                        <col style="width:110px;">{{-- Gross --}}
                        <col style="width:100px;">{{-- PAYE --}}
                        <col style="width:90px;">{{-- UIF --}}
                        <col style="width:110px;">{{-- Net --}}
                        <col style="width:70px;">{{-- Status --}}
                        <col style="width:120px;">{{-- Actions --}}
                    </colgroup>
                    <thead>
                        <tr style="border-bottom:2px solid var(--border, #e5e7eb);">
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Employee</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Branch</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Gross</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">PAYE</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">UIF</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Net</th>
                            <th class="text-center px-2 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Status</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($run->payslips as $ps)
                        <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                            <td class="px-3 py-2.5">
                                <div class="flex items-center gap-2">
                                    <div class="w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold text-white" style="background:#00d4aa;">{{ strtoupper(substr($ps->employee_name_snapshot, 0, 1)) }}</div>
                                    <div>
                                        <p class="text-xs font-semibold" style="color:var(--text-primary, #0f172a);">{{ $ps->employee_name_snapshot }}</p>
                                        <p class="text-[10px]" style="color:var(--text-secondary, #94a3b8);">{{ $ps->designation_snapshot }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-2.5 text-xs" style="color:var(--text-secondary, #6b7280);">{{ $ps->employee?->user?->branch?->name ?? '-' }}</td>
                            <td class="px-3 py-2.5 text-right text-xs font-semibold" style="color:var(--text-primary, #0f172a);">R {{ number_format($ps->total_earnings, 2) }}</td>
                            <td class="px-3 py-2.5 text-right text-xs" style="color:var(--text-secondary, #6b7280);">R {{ number_format($ps->paye_amount, 2) }}</td>
                            <td class="px-3 py-2.5 text-right text-xs" style="color:var(--text-secondary, #6b7280);">R {{ number_format($ps->uif_employee_amount, 2) }}</td>
                            <td class="px-3 py-2.5 text-right text-xs font-semibold" style="color:var(--text-primary, #0f172a);">R {{ number_format($ps->net_pay, 2) }}</td>
                            <td class="px-2 py-2.5 text-center">
                                @if($run->isFinalised())
                                    <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(0,212,170,0.1); color:#00d4aa; border-radius:6px;">Final</span>
                                @elseif($run->isDraft())
                                    <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(234,179,8,0.1); color:#eab308; border-radius:6px;">Draft</span>
                                @else
                                    <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(148,163,184,0.15); color:#94a3b8; border-radius:6px;">Cancelled</span>
                                @endif
                                @if($ps->notes)
                                    <span class="ml-1 text-[9px] px-1 py-0.5 font-bold" style="background:rgba(239,68,68,0.1); color:#ef4444; border-radius:2px;" title="{{ $ps->notes }}">!</span>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('payroll.runs.payslips.show', [$run, $ps]) }}" class="text-xs font-semibold" style="color:#00d4aa;">View</a>
                                    @if($run->isDraft())
                                        <a href="{{ route('payroll.runs.payslips.edit', [$run, $ps]) }}" class="text-xs font-semibold" style="color:var(--text-secondary, #6b7280);">Edit</a>
                                    @endif
                                    @if($ps->document_id || $run->isFinalised())
                                        <a href="{{ route('payroll.runs.payslips.pdf-download', [$run, $ps]) }}" class="text-xs font-semibold" style="color:var(--text-secondary, #6b7280);">PDF</a>
                                    @endif
                                </div>
                            </td>
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
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif

        {{-- Cancel Run section (draft only) --}}
        @if($run->isDraft())
        <div class="mt-6 p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;" x-data="{ showCancel: false }">
            <button @click="showCancel = !showCancel" class="text-xs font-semibold" style="color:#ef4444; background:none; border:none; cursor:pointer;">Cancel this run</button>
            <form method="POST" action="{{ route('payroll.runs.cancel', $run) }}" x-show="showCancel" x-cloak class="mt-3 space-y-3">
                @csrf
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Reason for cancellation <span class="text-red-500">*</span></label>
                    <input type="text" name="cancellation_reason" required maxlength="500" placeholder="e.g. Wrong period selected"
                           class="w-full px-3 py-2 text-sm focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;">
                    @error('cancellation_reason') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="px-3 py-1.5 text-xs font-semibold text-white" style="background:#ef4444; border-radius:6px;" onclick="return confirm('This will cancel the run and soft-delete all draft payslips. Continue?')">Cancel Run</button>
                    <button type="button" @click="showCancel = false" class="px-3 py-1.5 text-xs font-semibold" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:6px; background:none; cursor:pointer;">Keep Draft</button>
                </div>
            </form>
        </div>
        @endif
    </div>

    {{-- Finalise confirmation modal --}}
    @if($run->isDraft())
    <div x-show="showFinalise" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" style="background:rgba(0,0,0,0.5);" @click.self="showFinalise = false">
        <div class="w-full max-w-md p-6" style="background:var(--surface-1, #fff); border-radius:6px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
            <h3 class="text-sm font-bold mb-3" style="color:var(--text-primary, #0f172a);">Finalise Payroll Run?</h3>
            <p class="text-xs mb-4" style="color:var(--text-secondary, #6b7280);">
                This will finalise <strong>{{ $run->payslip_count }}</strong> payslip(s) totalling
                <strong style="color:#00d4aa;">R {{ number_format($run->total_net ?? 0, 2) }}</strong> net pay.
                PDFs will be generated and filed to each employee's document profile.
                <br><br>
                <strong style="color:#ef4444;">This action cannot be undone.</strong> Finalised runs are permanently locked.
            </p>
            <div class="flex justify-end gap-2">
                <button @click="showFinalise = false" class="px-4 py-2 text-xs font-semibold" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:6px; background:none; cursor:pointer;">Cancel</button>
                <form method="POST" action="{{ route('payroll.runs.finalise', $run) }}" class="inline">
                    @csrf
                    <button type="submit" class="px-4 py-2 text-xs font-semibold text-white" style="background:#00d4aa; border-radius:6px; cursor:pointer;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">Yes, Finalise</button>
                </form>
            </div>
        </div>
    </div>
    @endif
</div>
@endsection
