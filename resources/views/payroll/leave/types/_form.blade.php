{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@php
    // Shared input styling — token-driven, matches UI_DESIGN_SYSTEM.md §3.6.
    $inputClass = 'w-full px-3 py-2 text-sm rounded-md focus:outline-none';
    $inputStyle = 'background:var(--surface, #ffffff); border:1px solid var(--border, rgba(0,0,0,0.07)); color:var(--text-primary, #111827);';
    $labelStyle = 'color:var(--text-secondary, #4b5563);';
    $helpStyle  = 'color:var(--text-muted, #9ca3af);';
@endphp
<div class="max-w-3xl space-y-5" x-data="{
    code: '{{ old('code', $type->code ?? '') }}',
    accrualMethod: '{{ old('accrual_method', $type->accrual_method ?? 'none') }}',
    requiresDoc: {{ old('requires_documentation', $type->requires_documentation ?? false) ? 'true' : 'false' }},
    affectsPayroll: {{ old('affects_payroll', $type->affects_payroll ?? false) ? 'true' : 'false' }},
    isActive: {{ old('is_active', $type->is_active ?? true) ? 'true' : 'false' }}
}">
    @if(isset($locked) && ($locked['code'] ?? false))
    <div class="p-3 text-xs font-semibold rounded-md" style="background:color-mix(in srgb, var(--ds-amber) 8%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 25%, transparent); color:var(--ds-amber);">
        This is a BCEA-mandated leave type. Some fields are locked for compliance. You can adjust the label, description, documentation rules, advance notice, and active state.
    </div>
    @endif

    {{-- Section 1: Identification --}}
    <div class="p-4 rounded-md" style="background:var(--surface-2, #f0f2f8); border:1px solid var(--border, rgba(0,0,0,0.07));">
        <h4 class="text-xs font-bold uppercase tracking-wider mb-3" style="{{ $labelStyle }}">Identification</h4>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold mb-1" style="{{ $labelStyle }}">Code <span class="text-red-500">*</span></label>
                <input type="text" name="code" x-model="code" @blur="code = code.toLowerCase()" required maxlength="50"
                       class="{{ $inputClass }} font-mono" style="{{ $inputStyle }}"
                       placeholder="e.g. sabbatical_leave"
                       {{ isset($locked) && ($locked['code'] ?? false) ? 'disabled title=Locked on system types' : '' }}>
                <p class="text-[0.6875rem] mt-0.5" style="{{ $helpStyle }}">Internal reference — lowercase, hyphens, underscores.</p>
                @error('code') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="{{ $labelStyle }}">Label <span class="text-red-500">*</span></label>
                <input type="text" name="label" value="{{ old('label', $type->label ?? '') }}" required maxlength="150"
                       class="{{ $inputClass }}" style="{{ $inputStyle }}">
                @error('label') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label class="block text-xs font-semibold mb-1" style="{{ $labelStyle }}">Description</label>
                <textarea name="description" rows="2" class="{{ $inputClass }}" style="{{ $inputStyle }}">{{ old('description', $type->description ?? '') }}</textarea>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="{{ $labelStyle }}">Category <span class="text-red-500">*</span></label>
                <select name="category" required class="{{ $inputClass }}" style="{{ $inputStyle }}"
                        {{ isset($locked) && ($locked['category'] ?? false) ? 'disabled' : '' }}>
                    @foreach(['annual'=>'Annual','sick'=>'Sick','family_responsibility'=>'Family Responsibility','parental'=>'Parental','study'=>'Study','unpaid'=>'Unpaid','special'=>'Special','other'=>'Other'] as $val => $lbl)
                        <option value="{{ $val }}" {{ old('category', $type->category ?? '') === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                    @endforeach
                </select>
                @error('category') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="{{ $labelStyle }}">Sort Order</label>
                <input type="number" name="sort_order" min="0" value="{{ old('sort_order', $type->sort_order ?? ($nextSort ?? 10)) }}"
                       class="w-32 px-3 py-2 text-sm rounded-md focus:outline-none" style="{{ $inputStyle }}">
            </div>
        </div>
    </div>

    {{-- Section 2: Tax & Pay --}}
    <div class="p-4 rounded-md" style="background:var(--surface-2, #f0f2f8); border:1px solid var(--border, rgba(0,0,0,0.07));">
        <h4 class="text-xs font-bold uppercase tracking-wider mb-3" style="{{ $labelStyle }}">Tax & Pay Treatment</h4>
        <div class="space-y-3">
            @foreach([
                ['is_paid', 'Paid leave (employee receives normal pay)', $locked['is_paid'] ?? false],
                ['is_uif_claimable', 'Employee can claim UIF benefits during this leave', $locked['is_uif'] ?? false],
                ['affects_payroll', 'Reduces gross on payslip (working days x daily rate)', false],
                ['payout_on_termination', 'Pay out unused balance on termination', $locked['payout'] ?? false],
            ] as [$field, $label, $isLocked])
                <label class="relative inline-flex items-center cursor-pointer gap-3">
                    <input type="hidden" name="{{ $field }}" value="0">
                    <input type="checkbox" name="{{ $field }}" value="1" class="sr-only peer"
                           {{ old($field, $type->$field ?? false) ? 'checked' : '' }}
                           {{ $isLocked ? 'disabled' : '' }}
                           @if($field === 'affects_payroll') x-model="affectsPayroll" @endif>
                    <div class="relative w-10 h-5 rounded-full transition-colors after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5 peer-checked:[background:var(--brand-icon,#0ea5e9)]" style="background: color-mix(in srgb, var(--text-muted, #9ca3af) 40%, transparent);"></div>
                    <span class="text-sm" style="color:var(--text-primary, #111827);">{{ $label }}</span>
                </label>
            @endforeach
        </div>
    </div>

    {{-- Section 3: Entitlement & Cycle --}}
    <div class="p-4 rounded-md" style="background:var(--surface-2, #f0f2f8); border:1px solid var(--border, rgba(0,0,0,0.07));">
        <h4 class="text-xs font-bold uppercase tracking-wider mb-3" style="{{ $labelStyle }}">Entitlement & Cycle</h4>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold mb-1" style="{{ $labelStyle }}">Entitlement (5-day week) <span class="text-red-500">*</span></label>
                <input type="number" name="entitlement_days_per_cycle" step="0.5" min="0" max="999.99" required
                       value="{{ old('entitlement_days_per_cycle', $type->entitlement_days_per_cycle ?? 0) }}"
                       class="{{ $inputClass }}" style="{{ $inputStyle }}"
                       {{ isset($locked) && ($locked['entitlement'] ?? false) ? 'disabled' : '' }}>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="{{ $labelStyle }}">Entitlement (6-day week) <span class="text-red-500">*</span></label>
                <input type="number" name="entitlement_days_per_cycle_six_day" step="0.5" min="0" max="999.99" required
                       value="{{ old('entitlement_days_per_cycle_six_day', $type->entitlement_days_per_cycle_six_day ?? 0) }}"
                       class="{{ $inputClass }}" style="{{ $inputStyle }}"
                       {{ isset($locked) && ($locked['entitlement'] ?? false) ? 'disabled' : '' }}>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="{{ $labelStyle }}">Cycle (months) <span class="text-red-500">*</span></label>
                <input type="number" name="cycle_months" min="0" max="60" required
                       value="{{ old('cycle_months', $type->cycle_months ?? 12) }}"
                       class="w-32 px-3 py-2 text-sm rounded-md focus:outline-none" style="{{ $inputStyle }}"
                       {{ isset($locked) && ($locked['cycle'] ?? false) ? 'disabled' : '' }}>
                <p class="text-[0.6875rem] mt-0.5" style="{{ $helpStyle }}">12 for annual, 36 for sick, 0 for per-child (parental).</p>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="{{ $labelStyle }}">Accrual Method <span class="text-red-500">*</span></label>
                <select name="accrual_method" x-model="accrualMethod" required class="{{ $inputClass }}" style="{{ $inputStyle }}"
                        {{ isset($locked) && ($locked['accrual'] ?? false) ? 'disabled' : '' }}>
                    <option value="full_at_start">Full at cycle start</option>
                    <option value="accrual_per_day_worked">Accrual per day worked</option>
                    <option value="accrual_first_six_months">First 6 months special</option>
                    <option value="none">None (manual only)</option>
                </select>
            </div>
            <div x-show="accrualMethod === 'accrual_per_day_worked' || accrualMethod === 'accrual_first_six_months'" x-cloak>
                <label class="block text-xs font-semibold mb-1" style="{{ $labelStyle }}">Accrual Rate (1 day per N worked)</label>
                <input type="number" name="accrual_rate_per_days" min="1" max="365"
                       value="{{ old('accrual_rate_per_days', $type->accrual_rate_per_days ?? 17) }}"
                       class="w-32 px-3 py-2 text-sm rounded-md focus:outline-none" style="{{ $inputStyle }}"
                       {{ isset($locked) && ($locked['accrual'] ?? false) ? 'disabled' : '' }}>
            </div>
        </div>
        <div class="mt-3 space-y-3">
            @foreach([
                ['carries_over_to_next_cycle', 'Unused days carry to next cycle'],
                ['accrual_starts_at_employment_date', 'Accrual starts at employment date'],
            ] as [$field, $label])
                <label class="relative inline-flex items-center cursor-pointer gap-3">
                    <input type="hidden" name="{{ $field }}" value="0">
                    <input type="checkbox" name="{{ $field }}" value="1" class="sr-only peer"
                           {{ old($field, $type->$field ?? ($field === 'accrual_starts_at_employment_date' ? true : false)) ? 'checked' : '' }}>
                    <div class="relative w-10 h-5 rounded-full transition-colors after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5 peer-checked:[background:var(--brand-icon,#0ea5e9)]" style="background: color-mix(in srgb, var(--text-muted, #9ca3af) 40%, transparent);"></div>
                    <span class="text-sm" style="color:var(--text-primary, #111827);">{{ $label }}</span>
                </label>
            @endforeach
            <div>
                <label class="block text-xs font-semibold mb-1" style="{{ $labelStyle }}">Forfeit after (months)</label>
                <input type="number" name="forfeit_after_months" min="0"
                       value="{{ old('forfeit_after_months', $type->forfeit_after_months ?? '') }}"
                       class="w-32 px-3 py-2 text-sm rounded-md focus:outline-none" style="{{ $inputStyle }}"
                       placeholder="Never">
                <p class="text-[0.6875rem] mt-0.5" style="{{ $helpStyle }}">Leave blank for no auto-forfeit.</p>
            </div>
        </div>
    </div>

    {{-- Section 4: Application rules --}}
    <div class="p-4 rounded-md" style="background:var(--surface-2, #f0f2f8); border:1px solid var(--border, rgba(0,0,0,0.07));">
        <h4 class="text-xs font-bold uppercase tracking-wider mb-3" style="{{ $labelStyle }}">Application Rules</h4>
        <div class="space-y-3">
            <label class="relative inline-flex items-center cursor-pointer gap-3">
                <input type="hidden" name="requires_pre_approval" value="0">
                <input type="checkbox" name="requires_pre_approval" value="1" class="sr-only peer"
                       {{ old('requires_pre_approval', $type->requires_pre_approval ?? true) ? 'checked' : '' }}>
                <div class="relative w-10 h-5 rounded-full transition-colors after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5 peer-checked:[background:var(--brand-icon,#0ea5e9)]" style="background: color-mix(in srgb, var(--text-muted, #9ca3af) 40%, transparent);"></div>
                <span class="text-sm" style="color:var(--text-primary, #111827);">Requires pre-approval</span>
            </label>
            <div>
                <label class="block text-xs font-semibold mb-1" style="{{ $labelStyle }}">Min advance notice (days) <span class="text-red-500">*</span></label>
                <input type="number" name="min_advance_notice_days" min="0" max="365" required
                       value="{{ old('min_advance_notice_days', $type->min_advance_notice_days ?? 0) }}"
                       class="w-32 px-3 py-2 text-sm rounded-md focus:outline-none" style="{{ $inputStyle }}">
            </div>
            <label class="relative inline-flex items-center cursor-pointer gap-3">
                <input type="hidden" name="allows_negative_balance" value="0">
                <input type="checkbox" name="allows_negative_balance" value="1" class="sr-only peer"
                       {{ old('allows_negative_balance', $type->allows_negative_balance ?? false) ? 'checked' : '' }}>
                <div class="relative w-10 h-5 rounded-full transition-colors after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5 peer-checked:[background:var(--brand-icon,#0ea5e9)]" style="background: color-mix(in srgb, var(--text-muted, #9ca3af) 40%, transparent);"></div>
                <span class="text-sm" style="color:var(--text-primary, #111827);">Allow negative balance</span>
            </label>
            <label class="relative inline-flex items-center cursor-pointer gap-3">
                <input type="hidden" name="requires_documentation" value="0">
                <input type="checkbox" name="requires_documentation" value="1" x-model="requiresDoc" class="sr-only peer"
                       {{ old('requires_documentation', $type->requires_documentation ?? false) ? 'checked' : '' }}>
                <div class="relative w-10 h-5 rounded-full transition-colors after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5 peer-checked:[background:var(--brand-icon,#0ea5e9)]" style="background: color-mix(in srgb, var(--text-muted, #9ca3af) 40%, transparent);"></div>
                <span class="text-sm" style="color:var(--text-primary, #111827);">Requires documentation</span>
            </label>
            <div x-show="requiresDoc" x-cloak class="ml-[52px] grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="{{ $labelStyle }}">Documentation label</label>
                    <input type="text" name="documentation_label" maxlength="150"
                           value="{{ old('documentation_label', $type->documentation_label ?? '') }}"
                           class="{{ $inputClass }}" style="{{ $inputStyle }}"
                           placeholder="e.g. Medical certificate">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="{{ $labelStyle }}">Required if leave &gt; N days</label>
                    <input type="number" name="documentation_threshold_days" min="0" max="30"
                           value="{{ old('documentation_threshold_days', $type->documentation_threshold_days ?? '') }}"
                           class="w-32 px-3 py-2 text-sm rounded-md focus:outline-none" style="{{ $inputStyle }}"
                           placeholder="e.g. 2">
                </div>
            </div>
        </div>
    </div>

    {{-- Section 5: Status --}}
    <div class="flex items-center gap-4">
        <label class="relative inline-flex items-center cursor-pointer gap-3">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" x-model="isActive" class="sr-only peer">
            <div class="relative w-10 h-5 rounded-full transition-colors after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5 peer-checked:[background:var(--brand-icon,#0ea5e9)]" style="background: color-mix(in srgb, var(--text-muted, #9ca3af) 40%, transparent);"></div>
            <span class="text-sm font-medium" style="color:var(--text-primary, #111827);">Active</span>
        </label>
    </div>

    {{-- Actions --}}
    <div class="flex items-center gap-3 pt-2">
        <button type="submit" class="corex-btn-primary text-sm">
            {{ isset($type) && $type->exists ? 'Update' : 'Save' }} Leave Type
        </button>
        <a href="{{ route('payroll.leave.types.index') }}" class="corex-btn-outline text-sm">Cancel</a>
    </div>
</div>
