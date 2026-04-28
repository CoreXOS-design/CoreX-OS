@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Edit Payslip {{ $payslip->payslip_number }}" :back-route="route('payroll.runs.show', $run)" back-label="Run {{ $run->run_number }}" :flush="true">
        <x-slot:actions>
            <a href="{{ route('payroll.runs.payslips.show', [$run, $payslip]) }}" class="inline-flex items-center gap-1 px-3 py-2 text-xs font-semibold transition" style="color:var(--text-primary, #0f172a); border:1px solid var(--border, #e5e7eb); border-radius:3px;">View Payslip</a>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        @if(session('success'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:rgba(0,212,170,0.08); border:1px solid rgba(0,212,170,0.25); border-radius:3px; color:#00d4aa;">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.25); border-radius:3px; color:#ef4444;">{{ session('error') }}</div>
        @endif

        @if($payslip->notes)
            <div class="mb-4 p-3 text-xs" style="background:rgba(234,179,8,0.08); border:1px solid rgba(234,179,8,0.25); border-radius:3px; color:#eab308;">
                Auto-generated warnings: {{ $payslip->notes }}. Review and clear via Notes if appropriate.
            </div>
        @endif

        <div class="flex flex-col lg:flex-row gap-6 max-w-7xl">
            {{-- ═══ LEFT COLUMN (1/3) ═══ --}}
            <div class="lg:w-1/3 space-y-4">
                {{-- Employee snapshot --}}
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:3px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Employee Snapshot</h4>
                    <dl class="space-y-1.5 text-xs">
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Name</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a);">{{ $payslip->employee_name_snapshot }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">ID</dt><dd style="color:var(--text-primary, #0f172a); font-family:monospace;">{{ $payslip->id_number_snapshot ?? '-' }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Tax Ref</dt><dd style="color:var(--text-primary, #0f172a); font-family:monospace;">{{ $payslip->tax_reference_snapshot ?? '[Pending]' }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Designation</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a);">{{ $payslip->designation_snapshot }}</dd></div>
                    </dl>
                    <p class="text-[10px] mt-2" style="color:var(--text-secondary, #94a3b8);">Snapshots taken at run creation; will not change if employee profile is edited.</p>
                </div>

                {{-- Recalculate --}}
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:3px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Quick Actions</h4>
                    <form method="POST" action="{{ route('payroll.runs.payslips.recalculate', [$run, $payslip]) }}">
                        @csrf
                        <button type="submit" class="w-full px-3 py-2 text-xs font-semibold text-white transition" style="background:#eab308; border-radius:3px;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'" onclick="return confirm('This will discard all manual edits and recalculate from the employee\'s current earnings/deductions template. Continue?')">
                            Recalculate from Current Profile
                        </button>
                    </form>
                    <p class="text-[10px] mt-1.5" style="color:var(--text-secondary, #94a3b8);">Discards all manual edits and pulls fresh values from the employee's current earnings/deductions template.</p>
                </div>

                {{-- Notes --}}
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:3px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Notes</h4>
                    <form method="POST" action="{{ route('payroll.runs.payslips.notes', [$run, $payslip]) }}">
                        @csrf
                        @method('PATCH')
                        <textarea name="notes" rows="3" maxlength="2000" class="w-full px-2 py-1.5 text-xs focus:outline-none mb-2" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:3px; color:var(--text-primary, #0f172a);">{{ $payslip->notes }}</textarea>
                        <button type="submit" class="px-3 py-1.5 text-xs font-semibold text-white" style="background:#00d4aa; border-radius:3px;">Save Notes</button>
                    </form>
                    <p class="text-[10px] mt-1.5" style="color:var(--text-secondary, #94a3b8);">Internal notes visible to admin. Will appear on the run summary report.</p>
                </div>
            </div>

            {{-- ═══ RIGHT COLUMN (2/3) ═══ --}}
            <div class="lg:w-2/3 space-y-6">
                {{-- ── EARNINGS ── --}}
                <div>
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Earnings</h4>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" style="border-collapse:collapse;">
                            <thead>
                                <tr style="border-bottom:2px solid var(--border, #e5e7eb);">
                                    <th class="text-left px-2 py-1.5 text-xs font-bold" style="color:var(--text-secondary, #94a3b8);">Label</th>
                                    <th class="text-left px-2 py-1.5 text-xs font-bold" style="color:var(--text-secondary, #94a3b8); width:60px;">SARS</th>
                                    <th class="text-right px-2 py-1.5 text-xs font-bold" style="color:var(--text-secondary, #94a3b8); width:140px;">Amount</th>
                                    <th class="text-right px-2 py-1.5 text-xs font-bold" style="color:var(--text-secondary, #94a3b8); width:100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($earningLines as $line)
                                <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                                    <td class="px-2 py-2 text-xs font-semibold" style="color:var(--text-primary, #0f172a);">{{ $line->label_snapshot }}</td>
                                    <td class="px-2 py-2 text-xs" style="color:var(--text-secondary, #6b7280); font-family:monospace;">{{ $line->sars_source_code_snapshot ?? '-' }}</td>
                                    <td class="px-2 py-2 text-right">
                                        <form method="POST" action="{{ route('payroll.runs.payslips.lines.update', [$run, $payslip, $line]) }}" class="flex items-center justify-end gap-1">
                                            @csrf
                                            @method('PATCH')
                                            <span class="text-xs" style="color:var(--text-secondary, #6b7280);">R</span>
                                            <input type="number" name="amount" value="{{ $line->amount }}" step="0.01" min="0" required class="w-24 px-2 py-1 text-xs text-right focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:3px; color:var(--text-primary, #0f172a);">
                                            <button type="submit" class="px-2 py-1 text-[10px] font-semibold text-white" style="background:#00d4aa; border-radius:3px;">Save</button>
                                        </form>
                                    </td>
                                    <td class="px-2 py-2 text-right">
                                        <form method="POST" action="{{ route('payroll.runs.payslips.lines.destroy', [$run, $payslip, $line]) }}" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-[10px] font-semibold" style="color:#ef4444; background:none; border:none; cursor:pointer;" onclick="return confirm('Remove this earning line?')">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr style="border-top:2px solid var(--border, #e5e7eb);">
                                    <td colspan="2" class="px-2 py-2 text-xs font-bold" style="color:var(--text-secondary, #94a3b8);">Total Earnings</td>
                                    <td class="px-2 py-2 text-right text-xs font-bold" style="color:var(--text-primary, #0f172a);">R {{ number_format($payslip->total_earnings, 2) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    {{-- Add earning --}}
                    <div x-data="{ adding: false }" class="mt-2">
                        <button @click="adding = true" x-show="!adding" class="text-xs font-semibold" style="color:#00d4aa; background:none; border:none; cursor:pointer;">+ Add Earning</button>
                        <form method="POST" action="{{ route('payroll.runs.payslips.lines.store', [$run, $payslip]) }}" x-show="adding" x-cloak class="flex flex-wrap items-end gap-2 p-3 mt-1" style="background:rgba(0,212,170,0.03); border:1px solid rgba(0,212,170,0.15); border-radius:3px;">
                            @csrf
                            <input type="hidden" name="line_type" value="earning">
                            <div>
                                <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Earning Type</label>
                                <select name="source_type_id" required class="w-48 px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:3px; color:var(--text-primary, #0f172a);">
                                    <option value="">-- Select --</option>
                                    @foreach($earningTypes as $et)
                                        <option value="{{ $et->id }}">{{ $et->label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Amount (R)</label>
                                <input type="number" name="amount" step="0.01" min="0" required class="w-28 px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:3px; color:var(--text-primary, #0f172a);">
                            </div>
                            <button type="submit" class="px-3 py-1.5 text-xs font-semibold text-white" style="background:#00d4aa; border-radius:3px;">Save</button>
                            <button type="button" @click="adding = false" class="px-3 py-1.5 text-xs font-semibold" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:3px; background:none; cursor:pointer;">Cancel</button>
                        </form>
                    </div>
                </div>

                {{-- ── DEDUCTIONS ── --}}
                <div>
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Deductions</h4>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" style="border-collapse:collapse;">
                            <thead>
                                <tr style="border-bottom:2px solid var(--border, #e5e7eb);">
                                    <th class="text-left px-2 py-1.5 text-xs font-bold" style="color:var(--text-secondary, #94a3b8);">Label</th>
                                    <th class="text-left px-2 py-1.5 text-xs font-bold" style="color:var(--text-secondary, #94a3b8); width:60px;">SARS</th>
                                    <th class="text-right px-2 py-1.5 text-xs font-bold" style="color:var(--text-secondary, #94a3b8); width:140px;">Amount</th>
                                    <th class="text-right px-2 py-1.5 text-xs font-bold" style="color:var(--text-secondary, #94a3b8); width:100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($deductionLines as $line)
                                @php
                                    $deductionType = \App\Models\Payroll\PayrollDeductionType::find($line->source_type_id);
                                    $isStatutory = $deductionType && $deductionType->is_statutory;
                                @endphp
                                <tr style="border-bottom:1px solid var(--border, #e5e7eb); {{ $isStatutory ? 'background:rgba(234,179,8,0.02);' : '' }}">
                                    <td class="px-2 py-2 text-xs font-semibold" style="color:var(--text-primary, #0f172a);">
                                        @if($isStatutory)
                                            <svg class="w-3 h-3 inline mr-0.5" style="color:#eab308;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                        @endif
                                        {{ $line->label_snapshot }}
                                        @if($isStatutory)
                                            <span class="ml-1 text-[9px] px-1 py-0.5 font-bold" style="background:rgba(234,179,8,0.1); color:#eab308; border-radius:2px;">Statutory</span>
                                        @endif
                                    </td>
                                    <td class="px-2 py-2 text-xs" style="color:var(--text-secondary, #6b7280); font-family:monospace;">{{ $line->sars_source_code_snapshot ?? '-' }}</td>
                                    <td class="px-2 py-2 text-right">
                                        <form method="POST" action="{{ route('payroll.runs.payslips.lines.update', [$run, $payslip, $line]) }}" class="flex items-center justify-end gap-1">
                                            @csrf
                                            @method('PATCH')
                                            <span class="text-xs" style="color:var(--text-secondary, #6b7280);">R</span>
                                            <input type="number" name="amount" value="{{ $line->amount }}" step="0.01" min="0" required class="w-24 px-2 py-1 text-xs text-right focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:3px; color:var(--text-primary, #0f172a);">
                                            <button type="submit" class="px-2 py-1 text-[10px] font-semibold text-white" style="background:#00d4aa; border-radius:3px;">Save</button>
                                        </form>
                                    </td>
                                    <td class="px-2 py-2 text-right">
                                        @if($isStatutory)
                                            <span class="text-[10px]" style="color:var(--text-secondary, #cbd5e1); cursor:not-allowed;" title="Statutory deductions cannot be removed. Edit the amount to override.">Remove</span>
                                        @else
                                            <form method="POST" action="{{ route('payroll.runs.payslips.lines.destroy', [$run, $payslip, $line]) }}" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-[10px] font-semibold" style="color:#ef4444; background:none; border:none; cursor:pointer;" onclick="return confirm('Remove this deduction line?')">Remove</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr style="border-top:2px solid var(--border, #e5e7eb);">
                                    <td colspan="2" class="px-2 py-2 text-xs font-bold" style="color:var(--text-secondary, #94a3b8);">Total Deductions</td>
                                    <td class="px-2 py-2 text-right text-xs font-bold" style="color:var(--text-primary, #0f172a);">R {{ number_format($payslip->total_deductions, 2) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    {{-- Add deduction --}}
                    <div x-data="{ adding: false }" class="mt-2">
                        <button @click="adding = true" x-show="!adding" class="text-xs font-semibold" style="color:#00d4aa; background:none; border:none; cursor:pointer;">+ Add Deduction</button>
                        <form method="POST" action="{{ route('payroll.runs.payslips.lines.store', [$run, $payslip]) }}" x-show="adding" x-cloak class="flex flex-wrap items-end gap-2 p-3 mt-1" style="background:rgba(0,212,170,0.03); border:1px solid rgba(0,212,170,0.15); border-radius:3px;">
                            @csrf
                            <input type="hidden" name="line_type" value="deduction">
                            <div>
                                <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Deduction Type</label>
                                <select name="source_type_id" required class="w-48 px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:3px; color:var(--text-primary, #0f172a);">
                                    <option value="">-- Select --</option>
                                    @foreach($deductionTypes as $dt)
                                        <option value="{{ $dt->id }}">{{ $dt->label }}{{ $dt->is_statutory ? ' (statutory)' : '' }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Amount (R)</label>
                                <input type="number" name="amount" step="0.01" min="0" required class="w-28 px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:3px; color:var(--text-primary, #0f172a);">
                            </div>
                            <button type="submit" class="px-3 py-1.5 text-xs font-semibold text-white" style="background:#00d4aa; border-radius:3px;">Save</button>
                            <button type="button" @click="adding = false" class="px-3 py-1.5 text-xs font-semibold" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:3px; background:none; cursor:pointer;">Cancel</button>
                        </form>
                    </div>
                </div>

                {{-- ── EMPLOYER CONTRIBUTIONS (read-only) ── --}}
                @if($contributionLines->isNotEmpty())
                <div>
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Employer Contributions</h4>
                    <p class="text-[10px] mb-2" style="color:var(--text-secondary, #94a3b8);">Employer contributions are calculated. Use Recalculate to refresh after major changes.</p>
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

                {{-- ── TOTALS ── --}}
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div class="p-3 text-center" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:3px;">
                        <p class="text-[10px] font-semibold uppercase" style="color:var(--text-secondary, #94a3b8);">Gross</p>
                        <p class="text-sm font-bold" style="color:var(--text-primary, #0f172a);">R {{ number_format($payslip->total_earnings, 2) }}</p>
                    </div>
                    <div class="p-3 text-center" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:3px;">
                        <p class="text-[10px] font-semibold uppercase" style="color:var(--text-secondary, #94a3b8);">Total Deductions</p>
                        <p class="text-sm font-bold" style="color:var(--text-primary, #0f172a);">R {{ number_format($payslip->total_deductions, 2) }}</p>
                    </div>
                    <div class="p-3 text-center" style="background:rgba(0,212,170,0.04); border:1px solid rgba(0,212,170,0.15); border-radius:3px;">
                        <p class="text-[10px] font-semibold uppercase" style="color:var(--text-secondary, #94a3b8);">Net Pay</p>
                        <p class="text-sm font-bold" style="color:#00d4aa;">R {{ number_format($payslip->net_pay, 2) }}</p>
                    </div>
                    <div class="p-3 text-center" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:3px;">
                        <p class="text-[10px] font-semibold uppercase" style="color:var(--text-secondary, #94a3b8);">Taxable Income</p>
                        <p class="text-sm font-bold" style="color:var(--text-primary, #0f172a);">R {{ number_format($payslip->taxable_income, 2) }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
