{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6" x-data="{ showFinalise: false }">
    <x-page-header title="Payroll Run {{ $run->run_number }}" :back-route="route('payroll.runs.index')" back-label="Runs" :flush="true">
        <x-slot:actions>
            @if($run->isDraft())
                <button type="button" @click="showFinalise = true" class="corex-btn-primary text-sm">Finalise</button>
            @elseif($run->isFinalised())
                <a href="{{ route('payroll.runs.report', $run) }}" class="corex-btn-outline text-sm">View Report</a>
                <a href="{{ route('payroll.runs.bundle', $run) }}" class="corex-btn-primary text-sm">Download Bundle</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6 space-y-6">
        {{-- Flash --}}
        @if(session('success'))
            <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
                 style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                        color: var(--text-primary);">
                <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                </svg>
                <div class="flex-1">{{ session('success') }}</div>
            </div>
        @endif
        @if(session('error'))
            <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
                 style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                        color: var(--text-primary);">
                <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
                </svg>
                <div class="flex-1">{{ session('error') }}</div>
            </div>
        @endif

        {{-- Run header card --}}
        <div class="rounded-md p-4 flex flex-wrap items-center gap-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1">
                    <h2 class="text-base font-semibold" style="color: var(--text-primary);">{{ $run->period_month?->format('F Y') }}</h2>
                    @if($run->isDraft())
                        <span class="ds-badge ds-badge-warning">Draft</span>
                    @elseif($run->isFinalised())
                        <span class="ds-badge ds-badge-success">Finalised</span>
                    @else
                        <span class="ds-badge ds-badge-default">Cancelled</span>
                    @endif
                </div>
                <p class="text-xs" style="color: var(--text-secondary);">Pay date: {{ $run->pay_date?->format('d M Y') }} &middot; Created by {{ $run->createdBy->name ?? '—' }} on {{ $run->created_at?->format('d M Y H:i') }}</p>
                @if($run->isFinalised())
                    <p class="text-xs mt-1" style="color: var(--ds-green);">Finalised by {{ $run->finalisedBy->name ?? '—' }} on {{ $run->finalised_at?->format('d M Y H:i') }}</p>
                @endif
                @if($run->cancellation_reason)
                    <p class="text-xs mt-1" style="color: var(--ds-crimson);">Cancelled: {{ $run->cancellation_reason }}</p>
                @endif
            </div>
        </div>

        {{-- Summary totals --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 xl:gap-4">
            <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <p class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Headcount</p>
                <p class="text-lg font-semibold mt-1" style="color: var(--text-primary);">{{ number_format($run->payslip_count ?? 0) }}</p>
            </div>
            <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <p class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Total Gross</p>
                <p class="text-lg font-semibold mt-1 font-mono" style="color: var(--text-primary);">R {{ number_format($run->total_gross ?? 0, 2) }}</p>
            </div>
            <div class="rounded-md p-3 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <p class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Total Deductions</p>
                <p class="text-lg font-semibold mt-1 font-mono" style="color: var(--text-primary);">R {{ number_format(($run->total_paye ?? 0) + ($run->total_uif_employee ?? 0), 2) }}</p>
            </div>
            <div class="rounded-md p-3 text-center" style="background: color-mix(in srgb, var(--brand-icon) 6%, transparent); border: 1px solid color-mix(in srgb, var(--brand-icon) 20%, transparent);">
                <p class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Total Net</p>
                <p class="text-lg font-semibold mt-1 font-mono" style="color: var(--brand-icon);">R {{ number_format($run->total_net ?? 0, 2) }}</p>
            </div>
        </div>

        {{-- Payslip list --}}
        @if($run->payslips->isEmpty())
            <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z"/></svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No payslips in this run</h3>
                <p class="text-sm" style="color: var(--text-muted);">This run has no generated payslips.</p>
            </div>
        @else
            <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm ds-table">
                        <colgroup>
                            <col>{{-- Employee --}}
                            <col style="width:120px;">{{-- Branch --}}
                            <col style="width:120px;">{{-- Gross --}}
                            <col style="width:110px;">{{-- PAYE --}}
                            <col style="width:100px;">{{-- UIF --}}
                            <col style="width:120px;">{{-- Net --}}
                            <col style="width:90px;">{{-- Status --}}
                            <col style="width:140px;">{{-- Actions --}}
                        </colgroup>
                        <thead>
                            <tr style="background: var(--surface-2);">
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Employee</th>
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Branch</th>
                                <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Gross</th>
                                <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">PAYE</th>
                                <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">UIF</th>
                                <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Net</th>
                                <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                                <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($run->payslips as $ps)
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-full flex items-center justify-center text-[0.6875rem] font-bold text-white flex-shrink-0" style="background: var(--brand-icon);">{{ strtoupper(substr($ps->employee_name_snapshot, 0, 1)) }}</div>
                                        <div class="min-w-0">
                                            <p class="text-xs font-semibold truncate" style="color: var(--text-primary);">{{ $ps->employee_name_snapshot }}</p>
                                            <p class="text-[0.6875rem] truncate" style="color: var(--text-muted);">{{ $ps->designation_snapshot }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $ps->employee?->user?->branch?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-right text-xs font-semibold font-mono" style="color: var(--text-primary);">R {{ number_format($ps->total_earnings, 2) }}</td>
                                <td class="px-4 py-3 text-right text-xs font-mono" style="color: var(--text-secondary);">R {{ number_format($ps->paye_amount, 2) }}</td>
                                <td class="px-4 py-3 text-right text-xs font-mono" style="color: var(--text-secondary);">R {{ number_format($ps->uif_employee_amount, 2) }}</td>
                                <td class="px-4 py-3 text-right text-xs font-semibold font-mono" style="color: var(--text-primary);">R {{ number_format($ps->net_pay, 2) }}</td>
                                <td class="px-4 py-3 text-center whitespace-nowrap">
                                    @if($run->isFinalised())
                                        <span class="ds-badge ds-badge-success">Final</span>
                                    @elseif($run->isDraft())
                                        <span class="ds-badge ds-badge-warning">Draft</span>
                                    @else
                                        <span class="ds-badge ds-badge-default">Cancelled</span>
                                    @endif
                                    @if($ps->notes)
                                        <span class="ml-1 inline-flex items-center justify-center w-4 h-4 rounded-full text-[0.625rem] font-bold align-middle" style="background: color-mix(in srgb, var(--ds-amber) 15%, transparent); color: var(--ds-amber);" title="{{ $ps->notes }}">!</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-3">
                                        <a href="{{ route('payroll.runs.payslips.show', [$run, $ps]) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">View</a>
                                        @if($run->isDraft())
                                            <a href="{{ route('payroll.runs.payslips.edit', [$run, $ps]) }}" class="text-xs font-semibold" style="color: var(--text-secondary);">Edit</a>
                                        @endif
                                        @if($ps->document_id || $run->isFinalised())
                                            <a href="{{ route('payroll.runs.payslips.pdf-download', [$run, $ps]) }}" class="text-xs font-semibold" style="color: var(--text-secondary);">PDF</a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr style="background: var(--surface-2); border-top: 2px solid var(--border);">
                                <td colspan="2" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Totals</td>
                                <td class="px-4 py-2.5 text-right text-xs font-bold font-mono" style="color: var(--text-primary);">R {{ number_format($run->total_gross ?? 0, 2) }}</td>
                                <td class="px-4 py-2.5 text-right text-xs font-bold font-mono" style="color: var(--text-secondary);">R {{ number_format($run->total_paye ?? 0, 2) }}</td>
                                <td class="px-4 py-2.5 text-right text-xs font-bold font-mono" style="color: var(--text-secondary);">R {{ number_format($run->total_uif_employee ?? 0, 2) }}</td>
                                <td class="px-4 py-2.5 text-right text-xs font-bold font-mono" style="color: var(--brand-icon);">R {{ number_format($run->total_net ?? 0, 2) }}</td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        @endif

        {{-- Cancel Run section (draft only) --}}
        @if($run->isDraft())
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ showCancel: false }">
            <button type="button" @click="showCancel = !showCancel" class="text-xs font-semibold" style="color: var(--ds-crimson); background: none; border: none; cursor: pointer;">Cancel this run</button>
            <form method="POST" action="{{ route('payroll.runs.cancel', $run) }}" x-show="showCancel" x-cloak class="mt-3 space-y-3">
                @csrf
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Reason for cancellation <span class="text-red-500">*</span></label>
                    <input type="text" name="cancellation_reason" required maxlength="500" placeholder="e.g. Wrong period selected"
                           class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    @error('cancellation_reason') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="corex-btn-primary text-sm" style="background: var(--ds-crimson, #c41e3a);" onclick="return confirm('This will cancel the run and soft-delete all draft payslips. Continue?')">Cancel Run</button>
                    <button type="button" @click="showCancel = false" class="corex-btn-outline text-sm">Keep Draft</button>
                </div>
            </form>
        </div>
        @endif
    </div>

    {{-- Finalise confirmation modal --}}
    @if($run->isDraft())
    <div x-show="showFinalise" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.5);" @click.self="showFinalise = false">
        <div class="w-full max-w-md rounded-md p-6" style="background: var(--surface); box-shadow: 0 10px 30px rgba(0,0,0,0.18);">
            <h3 class="text-lg font-semibold mb-3" style="color: var(--text-primary);">Finalise Payroll Run?</h3>
            <p class="text-sm mb-4" style="color: var(--text-secondary);">
                This will finalise <strong>{{ number_format($run->payslip_count ?? 0) }}</strong> payslip(s) totalling
                <strong style="color: var(--brand-icon);">R {{ number_format($run->total_net ?? 0, 2) }}</strong> net pay.
                PDFs will be generated and filed to each employee's document profile.
                <br><br>
                <strong style="color: var(--ds-crimson);">This action cannot be undone.</strong> Finalised runs are permanently locked.
            </p>
            <div class="flex justify-end gap-2">
                <button type="button" @click="showFinalise = false" class="corex-btn-outline text-sm">Cancel</button>
                <form method="POST" action="{{ route('payroll.runs.finalise', $run) }}" class="inline">
                    @csrf
                    <button type="submit" class="corex-btn-primary text-sm">Yes, Finalise</button>
                </form>
            </div>
        </div>
    </div>
    @endif
</div>
@endsection
