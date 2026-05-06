@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="New Payroll Run" :back-route="route('payroll.runs.index')" back-label="Runs" :flush="true" />

    <div class="p-4 lg:p-6">
        @if(session('error'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.25); border-radius:6px; color:#ef4444;">{{ session('error') }}</div>
        @endif

        @if($existingRun)
            <div class="mb-4 p-3 text-sm" style="background:rgba(234,179,8,0.08); border:1px solid rgba(234,179,8,0.25); border-radius:6px; color:#eab308;">
                A {{ $existingRun->status }} run already exists for {{ $defaultPeriod->format('F Y') }}.
                <a href="{{ route('payroll.runs.show', $existingRun) }}" class="underline font-semibold">View it here.</a>
            </div>
        @endif

        <form method="POST" action="{{ route('payroll.runs.store') }}" x-data="{
            allChecked: true,
            employeeIds: @json($employees->pluck('id')->toArray()),
            toggleAll() {
                this.allChecked = !this.allChecked;
                document.querySelectorAll('input[name=\'employee_ids[]\']').forEach(cb => cb.checked = this.allChecked);
                this.employeeIds = this.allChecked ? @json($employees->pluck('id')->toArray()) : [];
            },
            updateCount() {
                this.employeeIds = Array.from(document.querySelectorAll('input[name=\'employee_ids[]\']:checked')).map(cb => parseInt(cb.value));
                this.allChecked = this.employeeIds.length === {{ $employees->count() }};
            }
        }">
            @csrf

            <div class="max-w-5xl space-y-6">
                {{-- Card 1: Run details --}}
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Run Details</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Period Month <span class="text-red-500">*</span></label>
                            <input type="month" name="period_month_display" value="{{ old('period_month', $defaultPeriod->format('Y-m')) }}"
                                   class="w-full px-3 py-2 text-sm focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;"
                                   onchange="document.getElementById('period_month_hidden').value = this.value + '-01'">
                            <input type="hidden" name="period_month" id="period_month_hidden" value="{{ old('period_month', $defaultPeriod->format('Y-m-d')) }}">
                            @error('period_month') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Pay Date <span class="text-red-500">*</span></label>
                            <input type="date" name="pay_date" value="{{ old('pay_date', $defaultPeriod->copy()->day(25)->format('Y-m-d')) }}" required
                                   class="w-full px-3 py-2 text-sm focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;">
                            @error('pay_date') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Notes</label>
                            <input type="text" name="notes" value="{{ old('notes', '') }}" maxlength="2000" placeholder="Optional run notes"
                                   class="w-full px-3 py-2 text-sm focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;">
                        </div>
                    </div>
                </div>

                {{-- Card 2: Select employees --}}
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <div class="flex items-center justify-between mb-3">
                        <h4 class="text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Select Employees</h4>
                        <span class="text-xs font-semibold" style="color:#00d4aa;" x-text="employeeIds.length + ' of {{ $employees->count() }} selected'"></span>
                    </div>

                    @if($employees->isEmpty())
                        <p class="text-xs py-4 text-center" style="color:var(--text-secondary, #94a3b8);">No active payroll employees found. Add employees first.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm" style="border-collapse:collapse;">
                                <thead>
                                    <tr style="border-bottom:2px solid var(--border, #e5e7eb);">
                                        <th class="px-2 py-2 text-center" style="width:40px;">
                                            <input type="checkbox" :checked="allChecked" @change="toggleAll()" style="accent-color:#00d4aa;">
                                        </th>
                                        <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Employee</th>
                                        <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Branch</th>
                                        <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Basic Salary</th>
                                        <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Last Run</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($employees as $emp)
                                    <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                                        <td class="px-2 py-2.5 text-center">
                                            <input type="checkbox" name="employee_ids[]" value="{{ $emp->id }}" checked @change="updateCount()" style="accent-color:#00d4aa;">
                                        </td>
                                        <td class="px-3 py-2.5">
                                            <div class="flex items-center gap-2">
                                                <div class="w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold text-white" style="background:#00d4aa;">{{ strtoupper(substr($emp->user->name ?? '?', 0, 1)) }}</div>
                                                <div>
                                                    <p class="text-xs font-semibold" style="color:var(--text-primary, #0f172a);">{{ $emp->user->name ?? 'Unknown' }}</p>
                                                    <p class="text-[10px]" style="color:var(--text-secondary, #94a3b8);">{{ $emp->designation_snapshot }}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-3 py-2.5 text-xs" style="color:var(--text-secondary, #6b7280);">{{ $emp->user->branch->name ?? '-' }}</td>
                                        <td class="px-3 py-2.5 text-right text-xs font-semibold" style="color:var(--text-primary, #0f172a);">
                                            @if($emp->basic_salary !== null)
                                                R {{ number_format($emp->basic_salary, 2) }}
                                            @else
                                                <span style="color:var(--text-secondary, #94a3b8);">-</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5 text-xs" style="color:var(--text-secondary, #6b7280);">
                                            {{ $emp->last_run_period ? $emp->last_run_period->format('M Y') : 'Never' }}
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    @error('employee_ids') <p class="text-xs text-red-500 mt-2">{{ $message }}</p> @enderror
                </div>

                {{-- Card 3: Projected totals --}}
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Projected Totals</h4>
                    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3">
                        @foreach([
                            'Headcount' => $projectedTotals['headcount'],
                            'Gross' => 'R ' . number_format($projectedTotals['gross'], 2),
                            'PAYE' => 'R ' . number_format($projectedTotals['paye'], 2),
                            'UIF (Employee)' => 'R ' . number_format($projectedTotals['uif_employee'], 2),
                            'UIF (Employer)' => 'R ' . number_format($projectedTotals['uif_employer'], 2),
                            'SDL' => 'R ' . number_format($projectedTotals['sdl'], 2),
                            'Net' => 'R ' . number_format($projectedTotals['net'], 2),
                        ] as $lbl => $val)
                            <div class="p-2 text-center" style="background:rgba(0,212,170,0.04); border:1px solid rgba(0,212,170,0.15); border-radius:6px;">
                                <p class="text-[10px] font-semibold uppercase" style="color:var(--text-secondary, #94a3b8);">{{ $lbl }}</p>
                                <p class="text-sm font-bold" style="color:var(--text-primary, #0f172a);">{{ $val }}</p>
                            </div>
                        @endforeach
                    </div>
                    <p class="text-[10px] mt-2" style="color:var(--text-secondary, #94a3b8);">Final amounts may differ slightly â€” verify on the run detail page after creation.</p>
                </div>

                {{-- Actions --}}
                <div class="flex items-center gap-3">
                    <button type="submit" class="px-4 py-2 text-sm font-semibold text-white transition" style="background:#00d4aa; border-radius:6px;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'"
                            {{ $employees->isEmpty() ? 'disabled' : '' }}>
                        Create Draft Run
                    </button>
                    <a href="{{ route('payroll.runs.index') }}" class="px-4 py-2 text-sm font-semibold transition" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:6px;">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
