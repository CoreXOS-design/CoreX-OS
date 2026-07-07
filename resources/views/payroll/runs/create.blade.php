{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="New Payroll Run" :back-route="route('payroll.runs.index')" back-label="Runs" :flush="true" />

    <div class="p-4 lg:p-6">
        @if(session('error'))
            <div class="mb-4 rounded-md px-4 py-3 text-sm flex items-start gap-3"
                 style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                        color: var(--text-primary);">
                <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
                </svg>
                <div class="flex-1">{{ session('error') }}</div>
            </div>
        @endif

        @if($existingRun)
            <div class="mb-4 rounded-md px-4 py-3 text-sm flex items-start gap-3"
                 style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);
                        color: var(--text-primary);">
                <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-amber);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                </svg>
                <div class="flex-1">
                    A {{ $existingRun->status }} run already exists for {{ $defaultPeriod->format('F Y') }}.
                    <a href="{{ route('payroll.runs.show', $existingRun) }}" class="underline font-semibold">View it here.</a>
                </div>
            </div>
        @endif

        <form method="POST" action="{{ route('payroll.runs.store') }}" x-data="{
            allChecked: true,
            employeeIds: @js($employees->pluck('id')->toArray()),
            toggleAll() {
                this.allChecked = !this.allChecked;
                document.querySelectorAll('input[name=\'employee_ids[]\']').forEach(cb => cb.checked = this.allChecked);
                this.employeeIds = this.allChecked ? @js($employees->pluck('id')->toArray()) : [];
            },
            updateCount() {
                this.employeeIds = Array.from(document.querySelectorAll('input[name=\'employee_ids[]\']:checked')).map(cb => parseInt(cb.value));
                this.allChecked = this.employeeIds.length === {{ $employees->count() }};
            }
        }">
            @csrf

            <div class="w-full space-y-6">
                {{-- Card 1: Run details --}}
                <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                    <h4 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color: var(--text-muted);">Run Details</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Period Month <span class="text-red-500">*</span></label>
                            <input type="month" name="period_month_display" value="{{ old('period_month', $defaultPeriod->format('Y-m')) }}"
                                   class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                   onchange="document.getElementById('period_month_hidden').value = this.value + '-01'">
                            <input type="hidden" name="period_month" id="period_month_hidden" value="{{ old('period_month', $defaultPeriod->format('Y-m-d')) }}">
                            @error('period_month') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Pay Date <span class="text-red-500">*</span></label>
                            <input type="date" name="pay_date" value="{{ old('pay_date', $defaultPeriod->copy()->day(25)->format('Y-m-d')) }}" required
                                   class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                            @error('pay_date') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Notes</label>
                            <input type="text" name="notes" value="{{ old('notes', '') }}" maxlength="2000" placeholder="Optional run notes"
                                   class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                    </div>
                </div>

                {{-- Card 2: Select employees --}}
                <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-center justify-between mb-3">
                        <h4 class="text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Select Employees</h4>
                        <span class="text-xs font-semibold" style="color: var(--brand-icon);" x-text="employeeIds.length + ' of {{ $employees->count() }} selected'"></span>
                    </div>

                    @if($employees->isEmpty())
                        <div class="rounded-md py-8 px-6 text-center" style="background: var(--surface-2); border: 1px solid var(--border);">
                            <p class="text-sm" style="color: var(--text-muted);">No active payroll employees found. Add employees first.</p>
                        </div>
                    @else
                        <div class="rounded-md overflow-hidden" style="border: 1px solid var(--border);">
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm ds-table">
                                    <thead>
                                        <tr style="background: var(--surface-2);">
                                            <th class="px-4 py-2.5 text-center" style="width:44px;">
                                                <input type="checkbox" :checked="allChecked" @change="toggleAll()" style="accent-color: var(--brand-icon);">
                                            </th>
                                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Employee</th>
                                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Branch</th>
                                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Basic Salary</th>
                                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Last Run</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($employees as $emp)
                                        <tr>
                                            <td class="px-4 py-3 text-center">
                                                <input type="checkbox" name="employee_ids[]" value="{{ $emp->id }}" checked @change="updateCount()" style="accent-color: var(--brand-icon);">
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-2">
                                                    <div class="w-7 h-7 rounded-full flex items-center justify-center text-[0.6875rem] font-bold text-white flex-shrink-0" style="background: var(--brand-icon);">{{ strtoupper(substr($emp->user->name ?? '?', 0, 1)) }}</div>
                                                    <div class="min-w-0">
                                                        <p class="text-xs font-semibold truncate" style="color: var(--text-primary);">{{ $emp->user->name ?? 'Unknown' }}</p>
                                                        <p class="text-[0.6875rem] truncate" style="color: var(--text-muted);">{{ $emp->designation_snapshot }}</p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $emp->user->branch->name ?? '—' }}</td>
                                            <td class="px-4 py-3 text-right text-xs font-semibold font-mono" style="color: var(--text-primary);">
                                                @if($emp->basic_salary !== null)
                                                    R {{ number_format($emp->basic_salary, 2) }}
                                                @else
                                                    <span style="color: var(--text-muted);">—</span>
                                                @endif
                                            </td>
                                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">
                                                {{ $emp->last_run_period ? $emp->last_run_period->format('M Y') : 'Never' }}
                                            </td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                    @error('employee_ids') <p class="text-xs text-red-500 mt-2">{{ $message }}</p> @enderror
                </div>

                {{-- Card 3: Projected totals --}}
                <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                    <h4 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color: var(--text-muted);">Projected Totals</h4>
                    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3">
                        @foreach([
                            'Headcount' => number_format($projectedTotals['headcount']),
                            'Gross' => 'R ' . number_format($projectedTotals['gross'], 2),
                            'PAYE' => 'R ' . number_format($projectedTotals['paye'], 2),
                            'UIF (Employee)' => 'R ' . number_format($projectedTotals['uif_employee'], 2),
                            'UIF (Employer)' => 'R ' . number_format($projectedTotals['uif_employer'], 2),
                            'SDL' => 'R ' . number_format($projectedTotals['sdl'], 2),
                            'Net' => 'R ' . number_format($projectedTotals['net'], 2),
                        ] as $lbl => $val)
                            <div class="rounded-md p-2 text-center" style="background: color-mix(in srgb, var(--brand-icon) 6%, transparent); border: 1px solid color-mix(in srgb, var(--brand-icon) 20%, transparent);">
                                <p class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">{{ $lbl }}</p>
                                <p class="text-sm font-semibold mt-1 font-mono" style="color: var(--text-primary);">{{ $val }}</p>
                            </div>
                        @endforeach
                    </div>
                    <p class="text-[0.6875rem] mt-2" style="color: var(--text-muted);">Final amounts may differ slightly — verify on the run detail page after creation.</p>
                </div>

                {{-- Actions --}}
                <div class="flex items-center gap-3">
                    <button type="submit" class="corex-btn-primary text-sm disabled:opacity-40 disabled:cursor-not-allowed" {{ $employees->isEmpty() ? 'disabled' : '' }}>
                        Create Draft Run
                    </button>
                    <a href="{{ route('payroll.runs.index') }}" class="corex-btn-outline text-sm">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
