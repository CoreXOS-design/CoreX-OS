@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Edit Payslip {{ $payslip->payslip_number }}" :back-route="route('payroll.runs.show', $run)" back-label="Run {{ $run->run_number }}" :flush="true">
        <x-slot:actions>
            <a href="{{ route('payroll.runs.payslips.pdf-preview', [$run, $payslip]) }}" target="_blank" class="corex-btn-outline text-xs">Preview PDF</a>
            <a href="{{ route('payroll.runs.payslips.show', [$run, $payslip]) }}" class="corex-btn-outline text-xs">View Payslip</a>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        @if(session('success'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); border-radius:6px; color:var(--text-primary);">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:color-mix(in srgb, var(--ds-crimson) 8%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent); border-radius:6px; color:var(--ds-crimson);">{{ session('error') }}</div>
        @endif

        @if($payslip->notes)
            <div class="mb-4 p-3 text-xs" style="background:color-mix(in srgb, var(--ds-amber) 8%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 25%, transparent); border-radius:6px; color:var(--ds-amber);">
                Auto-generated warnings: {{ $payslip->notes }}. Review and clear via Notes if appropriate.
            </div>
        @endif

        <div class="flex flex-col lg:flex-row gap-6 max-w-7xl">
            {{-- â•â•â• LEFT COLUMN (1/3) â•â•â• --}}
            <div class="lg:w-1/3 space-y-4">
                {{-- Employee snapshot --}}
                <div class="p-4" style="background:var(--surface); border:1px solid var(--border); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-muted); letter-spacing:0.05em;">Employee Snapshot</h4>
                    <dl class="space-y-1.5 text-xs">
                        <div class="flex justify-between"><dt style="color:var(--text-muted);">Name</dt><dd class="font-semibold" style="color:var(--text-primary);">{{ $payslip->employee_name_snapshot }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-muted);">ID</dt><dd style="color:var(--text-primary); font-family:monospace;">{{ $payslip->id_number_snapshot ?? '-' }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-muted);">Tax Ref</dt><dd style="color:var(--text-primary); font-family:monospace;">{{ $payslip->tax_reference_snapshot ?? '[Pending]' }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-muted);">Designation</dt><dd class="font-semibold" style="color:var(--text-primary);">{{ $payslip->designation_snapshot }}</dd></div>
                    </dl>
                    <p class="text-[10px] mt-2" style="color:var(--text-muted);">Snapshots taken at run creation; will not change if employee profile is edited.</p>
                </div>

                {{-- Recalculate --}}
                <div class="p-4" style="background:var(--surface); border:1px solid var(--border); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-muted); letter-spacing:0.05em;">Quick Actions</h4>
                    <form method="POST" action="{{ route('payroll.runs.payslips.recalculate', [$run, $payslip]) }}">
                        @csrf
                        <button type="submit" class="w-full px-3 py-2 text-xs font-semibold text-white transition" style="background:var(--ds-amber); border-radius:6px;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'" onclick="return confirm('This will discard all manual edits and recalculate from the employee\'s current earnings/deductions template. Continue?')">
                            Recalculate from Current Profile
                        </button>
                    </form>
                    <p class="text-[10px] mt-1.5" style="color:var(--text-muted);">Discards all manual edits and pulls fresh values from the employee's current earnings/deductions template.</p>
                </div>

                {{-- Notes --}}
                <div class="p-4" style="background:var(--surface); border:1px solid var(--border); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-muted); letter-spacing:0.05em;">Notes</h4>
                    <form method="POST" action="{{ route('payroll.runs.payslips.notes', [$run, $payslip]) }}">
                        @csrf
                        @method('PATCH')
                        <textarea name="notes" rows="3" maxlength="2000" class="w-full px-2 py-1.5 text-xs focus:outline-none mb-2" style="background:var(--surface-2); border:1px solid var(--border); border-radius:6px; color:var(--text-primary);">{{ $payslip->notes }}</textarea>
                        <button type="submit" class="px-3 py-1.5 text-xs font-semibold text-white" style="background:var(--brand-button); border-radius:6px;">Save Notes</button>
                    </form>
                    <p class="text-[10px] mt-1.5" style="color:var(--text-muted);">Internal notes visible to admin. Will appear on the run summary report.</p>
                </div>
            </div>

            {{-- â•â•â• RIGHT COLUMN (2/3) â•â•â• --}}
            <div class="lg:w-2/3 space-y-6">
                {{-- â”€â”€ EARNINGS â”€â”€ --}}
                <div>
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-muted); letter-spacing:0.05em;">Earnings</h4>
                    <div class="overflow-x-auto rounded-md" style="background:var(--surface); border:1px solid var(--border);">
                        <table class="w-full text-sm" style="border-collapse:collapse;">
                            <thead>
                                <tr style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                                    <th class="text-left px-2 py-1.5 text-xs font-bold" style="color:var(--text-muted);">Label</th>
                                    <th class="text-left px-2 py-1.5 text-xs font-bold" style="color:var(--text-muted); width:60px;">SARS</th>
                                    <th class="text-right px-2 py-1.5 text-xs font-bold" style="color:var(--text-muted); width:140px;">Amount</th>
                                    <th class="text-right px-2 py-1.5 text-xs font-bold" style="color:var(--text-muted); width:100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($earningLines as $line)
                                <tr style="border-bottom:1px solid var(--border);">
                                    <td class="px-2 py-2 text-xs font-semibold" style="color:var(--text-primary);">{{ $line->label_snapshot }}</td>
                                    <td class="px-2 py-2 text-xs" style="color:var(--text-muted); font-family:monospace;">{{ $line->sars_source_code_snapshot ?? '-' }}</td>
                                    <td class="px-2 py-2 text-right">
                                        <form method="POST" action="{{ route('payroll.runs.payslips.lines.update', [$run, $payslip, $line]) }}" class="flex items-center justify-end gap-1">
                                            @csrf
                                            @method('PATCH')
                                            <span class="text-xs" style="color:var(--text-muted);">R</span>
                                            <input type="number" name="amount" value="{{ $line->amount }}" step="0.01" min="0" required class="w-24 px-2 py-1 text-xs text-right focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); border-radius:6px; color:var(--text-primary);">
                                            <button type="submit" class="px-2 py-1 text-[10px] font-semibold text-white" style="background:var(--brand-button); border-radius:6px;">Save</button>
                                        </form>
                                    </td>
                                    <td class="px-2 py-2 text-right">
                                        <form method="POST" action="{{ route('payroll.runs.payslips.lines.destroy', [$run, $payslip, $line]) }}" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-[10px] font-semibold" style="color:var(--ds-crimson); background:none; border:none; cursor:pointer;" onclick="return confirm('Remove this earning line?')">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr style="border-top:2px solid var(--border);">
                                    <td colspan="2" class="px-2 py-2 text-xs font-bold" style="color:var(--text-muted);">Total Earnings</td>
                                    <td class="px-2 py-2 text-right text-xs font-bold" style="color:var(--text-primary);">R {{ number_format($payslip->total_earnings, 2) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    {{-- Add earning --}}
                    <div x-data="{ adding: false }" class="mt-2">
                        <button @click="adding = true" x-show="!adding" class="text-xs font-semibold" style="color:var(--brand-icon); background:none; border:none; cursor:pointer;">+ Add Earning</button>
                        <form method="POST" action="{{ route('payroll.runs.payslips.lines.store', [$run, $payslip]) }}" x-show="adding" x-cloak class="flex flex-wrap items-end gap-2 p-3 mt-1" style="background:var(--surface-2); border:1px solid var(--border); border-radius:6px;">
                            @csrf
                            <input type="hidden" name="line_type" value="earning">
                            <div>
                                <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-muted);">Earning Type</label>
                                <select name="source_type_id" required class="w-48 px-2 py-1.5 text-xs focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); border-radius:6px; color:var(--text-primary);">
                                    <option value="">-- Select --</option>
                                    @foreach($earningTypes as $et)
                                        <option value="{{ $et->id }}">{{ $et->label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-muted);">Amount (R)</label>
                                <input type="number" name="amount" step="0.01" min="0" required class="w-28 px-2 py-1.5 text-xs focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); border-radius:6px; color:var(--text-primary);">
                            </div>
                            <button type="submit" class="px-3 py-1.5 text-xs font-semibold text-white" style="background:var(--brand-button); border-radius:6px;">Save</button>
                            <button type="button" @click="adding = false" class="px-3 py-1.5 text-xs font-semibold" style="color:var(--text-muted); border:1px solid var(--border); border-radius:6px; background:none; cursor:pointer;">Cancel</button>
                        </form>
                    </div>
                </div>

                {{-- â”€â”€ DEDUCTIONS â”€â”€ --}}
                <div>
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-muted); letter-spacing:0.05em;">Deductions</h4>
                    <div class="overflow-x-auto rounded-md" style="background:var(--surface); border:1px solid var(--border);">
                        <table class="w-full text-sm" style="border-collapse:collapse;">
                            <thead>
                                <tr style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                                    <th class="text-left px-2 py-1.5 text-xs font-bold" style="color:var(--text-muted);">Label</th>
                                    <th class="text-left px-2 py-1.5 text-xs font-bold" style="color:var(--text-muted); width:60px;">SARS</th>
                                    <th class="text-right px-2 py-1.5 text-xs font-bold" style="color:var(--text-muted); width:140px;">Amount</th>
                                    <th class="text-right px-2 py-1.5 text-xs font-bold" style="color:var(--text-muted); width:100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($deductionLines as $line)
                                @php
                                    $deductionType = \App\Models\Payroll\PayrollDeductionType::find($line->source_type_id);
                                    $isStatutory = $deductionType && $deductionType->is_statutory;
                                @endphp
                                <tr style="border-bottom:1px solid var(--border); {{ $isStatutory ? 'background:color-mix(in srgb, var(--ds-amber) 5%, transparent);' : '' }}">
                                    <td class="px-2 py-2 text-xs font-semibold" style="color:var(--text-primary);">
                                        @if($isStatutory)
                                            <svg class="w-3 h-3 inline mr-0.5" style="color:var(--ds-amber);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                        @endif
                                        {{ $line->label_snapshot }}
                                        @if($isStatutory)
                                            <span class="ml-1 text-[9px] px-1 py-0.5 font-bold" style="background:color-mix(in srgb, var(--ds-amber) 10%, transparent); color:var(--ds-amber); border-radius:2px;">Statutory</span>
                                        @endif
                                    </td>
                                    <td class="px-2 py-2 text-xs" style="color:var(--text-muted); font-family:monospace;">{{ $line->sars_source_code_snapshot ?? '-' }}</td>
                                    <td class="px-2 py-2 text-right">
                                        <form method="POST" action="{{ route('payroll.runs.payslips.lines.update', [$run, $payslip, $line]) }}" class="flex items-center justify-end gap-1">
                                            @csrf
                                            @method('PATCH')
                                            <span class="text-xs" style="color:var(--text-muted);">R</span>
                                            <input type="number" name="amount" value="{{ $line->amount }}" step="0.01" min="0" required class="w-24 px-2 py-1 text-xs text-right focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); border-radius:6px; color:var(--text-primary);">
                                            <button type="submit" class="px-2 py-1 text-[10px] font-semibold text-white" style="background:var(--brand-button); border-radius:6px;">Save</button>
                                        </form>
                                    </td>
                                    <td class="px-2 py-2 text-right">
                                        @if($isStatutory)
                                            <span class="text-[10px]" style="color:var(--text-faint); cursor:not-allowed;" title="Statutory deductions cannot be removed. Edit the amount to override.">Remove</span>
                                        @else
                                            <form method="POST" action="{{ route('payroll.runs.payslips.lines.destroy', [$run, $payslip, $line]) }}" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-[10px] font-semibold" style="color:var(--ds-crimson); background:none; border:none; cursor:pointer;" onclick="return confirm('Remove this deduction line?')">Remove</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr style="border-top:2px solid var(--border);">
                                    <td colspan="2" class="px-2 py-2 text-xs font-bold" style="color:var(--text-muted);">Total Deductions</td>
                                    <td class="px-2 py-2 text-right text-xs font-bold" style="color:var(--text-primary);">R {{ number_format($payslip->total_deductions, 2) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    {{-- Add deduction --}}
                    <div x-data="{ adding: false }" class="mt-2">
                        <button @click="adding = true" x-show="!adding" class="text-xs font-semibold" style="color:var(--brand-icon); background:none; border:none; cursor:pointer;">+ Add Deduction</button>
                        <form method="POST" action="{{ route('payroll.runs.payslips.lines.store', [$run, $payslip]) }}" x-show="adding" x-cloak class="flex flex-wrap items-end gap-2 p-3 mt-1" style="background:var(--surface-2); border:1px solid var(--border); border-radius:6px;">
                            @csrf
                            <input type="hidden" name="line_type" value="deduction">
                            <div>
                                <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-muted);">Deduction Type</label>
                                <select name="source_type_id" required class="w-48 px-2 py-1.5 text-xs focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); border-radius:6px; color:var(--text-primary);">
                                    <option value="">-- Select --</option>
                                    @foreach($deductionTypes as $dt)
                                        <option value="{{ $dt->id }}">{{ $dt->label }}{{ $dt->is_statutory ? ' (statutory)' : '' }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-muted);">Amount (R)</label>
                                <input type="number" name="amount" step="0.01" min="0" required class="w-28 px-2 py-1.5 text-xs focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); border-radius:6px; color:var(--text-primary);">
                            </div>
                            <button type="submit" class="px-3 py-1.5 text-xs font-semibold text-white" style="background:var(--brand-button); border-radius:6px;">Save</button>
                            <button type="button" @click="adding = false" class="px-3 py-1.5 text-xs font-semibold" style="color:var(--text-muted); border:1px solid var(--border); border-radius:6px; background:none; cursor:pointer;">Cancel</button>
                        </form>
                    </div>
                </div>

                {{-- â”€â”€ EMPLOYER CONTRIBUTIONS (read-only) â”€â”€ --}}
                @if($contributionLines->isNotEmpty())
                <div>
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-muted); letter-spacing:0.05em;">Employer Contributions</h4>
                    <p class="text-[10px] mb-2" style="color:var(--text-muted);">Employer contributions are calculated. Use Recalculate to refresh after major changes.</p>
                    <div class="overflow-x-auto rounded-md" style="background:var(--surface); border:1px solid var(--border);">
                        <table class="w-full text-sm" style="border-collapse:collapse;">
                            <tbody>
                                @foreach($contributionLines as $line)
                                <tr style="border-bottom:1px solid var(--border);">
                                    <td class="px-2 py-2 text-xs" style="color:var(--text-primary);">{{ $line->label_snapshot }}</td>
                                    <td class="px-2 py-2 text-right text-xs" style="color:var(--text-muted);">R {{ number_format($line->amount, 2) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @endif

                {{-- â”€â”€ TOTALS â”€â”€ --}}
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div class="p-3 text-center" style="background:var(--surface); border:1px solid var(--border); border-radius:6px;">
                        <p class="text-[10px] font-semibold uppercase" style="color:var(--text-muted);">Gross</p>
                        <p class="text-sm font-bold" style="color:var(--text-primary);">R {{ number_format($payslip->total_earnings, 2) }}</p>
                    </div>
                    <div class="p-3 text-center" style="background:var(--surface); border:1px solid var(--border); border-radius:6px;">
                        <p class="text-[10px] font-semibold uppercase" style="color:var(--text-muted);">Total Deductions</p>
                        <p class="text-sm font-bold" style="color:var(--text-primary);">R {{ number_format($payslip->total_deductions, 2) }}</p>
                    </div>
                    <div class="p-3 text-center" style="background:var(--surface); border:1px solid var(--border); border-radius:6px;">
                        <p class="text-[10px] font-semibold uppercase" style="color:var(--text-muted);">Net Pay</p>
                        <p class="text-sm font-bold" style="color:var(--brand-icon);">R {{ number_format($payslip->net_pay, 2) }}</p>
                    </div>
                    <div class="p-3 text-center" style="background:var(--surface); border:1px solid var(--border); border-radius:6px;">
                        <p class="text-[10px] font-semibold uppercase" style="color:var(--text-muted);">Taxable Income</p>
                        <p class="text-sm font-bold" style="color:var(--text-primary);">R {{ number_format($payslip->taxable_income, 2) }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
