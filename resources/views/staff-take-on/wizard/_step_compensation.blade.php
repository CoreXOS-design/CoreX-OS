{{-- Step 5: Compensation --}}
@php $pe = $payrollEmployee ?? $takeOn->payrollEmployee; @endphp
<form method="POST" action="{{ route('staff-take-on.save-step', [$takeOn, 'compensation']) }}">
    @csrf
    @method('PATCH')

    <div class="p-4 rounded-md" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb);">
        <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">5. Compensation Setup</h4>

        @if(!$pe)
            <p class="text-xs" style="color:var(--ds-crimson);">Employment must be set up first (Step 4).</p>
        @else
            <div class="mb-4">
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Basic Salary (R) <span style="color:var(--ds-crimson, #c41e3a);">*</span></label>
                @php
                    $basicType = $earningTypes->firstWhere('code', 'basic');
                    $currentBasic = $pe && $basicType ? $pe->currentEarnings()->where('earning_type_id', $basicType->id)->value('amount') : 0;
                @endphp
                <input type="number" name="basic_salary" step="0.01" min="0" value="{{ old('basic_salary', $currentBasic ?? 0) }}" required class="w-48 px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a);">
                <p class="text-[10px] mt-0.5" style="color:var(--text-secondary, #94a3b8);">Monthly basic salary. Additional earnings can be added on the employee profile after take-on.</p>
            </div>

            <p class="text-xs" style="color:var(--text-secondary, #94a3b8);">PAYE and UIF deductions will be auto-configured. Additional earnings/deductions can be added via the payroll employee profile after completing the take-on.</p>
        @endif
    </div>

    <button type="submit" class="corex-btn-primary mt-4">Save & Continue</button>
</form>
