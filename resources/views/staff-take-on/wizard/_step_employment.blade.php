{{-- Step 4: Employment Terms --}}
@php $pe = $payrollEmployee ?? $takeOn->payrollEmployee; @endphp
<form method="POST" action="{{ route('staff-take-on.save-step', [$takeOn, 'employment']) }}">
    @csrf
    @method('PATCH')

    <div class="p-4 rounded-md" style="background:var(--surface); border:1px solid var(--border);">
        <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-muted); letter-spacing:0.05em;">4. Employment Terms</h4>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div><label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Original Employment Start Date <span style="color:var(--ds-crimson);">*</span></label>
                <input type="date" name="original_employment_start_date" required value="{{ old('original_employment_start_date', $takeOn->original_employment_start_date?->format('Y-m-d') ?? $pe?->employment_date?->format('Y-m-d') ?? $takeOn->take_on_date->format('Y-m-d')) }}" class="w-full px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <p class="text-[10px] mt-0.5" style="color:var(--text-muted);">Can be earlier than take-on date for migration scenarios.</p></div>
            <div><label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Designation <span style="color:var(--ds-crimson);">*</span></label>
                <input type="text" name="designation_snapshot" required value="{{ old('designation_snapshot', $pe?->designation_snapshot ?? $takeOn->user->designation ?? '') }}" maxlength="100" class="w-full px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"></div>
            <div><label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Branch</label>
                <select name="branch_id" class="w-full px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    @foreach(\App\Models\Branch::orderBy('name')->get() as $b)
                        <option value="{{ $b->id }}" {{ ($pe?->branch_id ?? $takeOn->user->branch_id) == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                    @endforeach
                </select></div>
            <div><label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Working Pattern <span style="color:var(--ds-crimson);">*</span></label>
                <select name="working_pattern" required class="w-full px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);" onchange="document.getElementById('wdpw').value = this.value === 'monday_to_saturday' ? 6 : 5">
                    <option value="monday_to_friday" {{ ($pe?->working_pattern ?? 'monday_to_friday') === 'monday_to_friday' ? 'selected' : '' }}>Monday to Friday (5-day)</option>
                    <option value="monday_to_saturday" {{ ($pe?->working_pattern ?? '') === 'monday_to_saturday' ? 'selected' : '' }}>Monday to Saturday (6-day)</option>
                    <option value="custom" {{ ($pe?->working_pattern ?? '') === 'custom' ? 'selected' : '' }}>Custom</option>
                </select></div>
            <input type="hidden" name="working_days_per_week" id="wdpw" value="{{ $pe?->working_days_per_week ?? 5 }}">
            <div><label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Hours per Day <span style="color:var(--ds-crimson);">*</span></label>
                <input type="number" name="hours_per_day" required step="0.5" min="1" max="24" value="{{ old('hours_per_day', $pe?->hours_per_day ?? 8) }}" class="w-32 px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"></div>
            <div><label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Pay Day of Month <span style="color:var(--ds-crimson);">*</span></label>
                <input type="number" name="pay_day_of_month" required min="1" max="31" value="{{ old('pay_day_of_month', $pe?->pay_day_of_month ?? 25) }}" class="w-32 px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"></div>
            <div><label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Daily Rate Basis <span style="color:var(--ds-crimson);">*</span></label>
                <select name="daily_rate_basis" required class="w-full px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    <option value="fixed_21_67" {{ ($pe?->daily_rate_basis ?? 'fixed_21_67') === 'fixed_21_67' ? 'selected' : '' }}>Fixed (salary / 21.67)</option>
                    <option value="calendar_working_days" {{ ($pe?->daily_rate_basis ?? '') === 'calendar_working_days' ? 'selected' : '' }}>Calendar working days</option>
                    <option value="hours_per_day" {{ ($pe?->daily_rate_basis ?? '') === 'hours_per_day' ? 'selected' : '' }}>Hours per day</option>
                </select></div>
        </div>
    </div>

    <button type="submit" class="corex-btn-primary mt-4">Save & Continue</button>
</form>
