<div class="max-w-3xl space-y-5" x-data="{
    code: '{{ old('code', $type->code ?? '') }}',
    accrualMethod: '{{ old('accrual_method', $type->accrual_method ?? 'none') }}',
    requiresDoc: {{ old('requires_documentation', $type->requires_documentation ?? false) ? 'true' : 'false' }},
    affectsPayroll: {{ old('affects_payroll', $type->affects_payroll ?? false) ? 'true' : 'false' }},
    isActive: {{ old('is_active', $type->is_active ?? true) ? 'true' : 'false' }}
}">
    @if(isset($locked) && ($locked['code'] ?? false))
    <div class="p-3 text-xs font-semibold" style="background:rgba(234,179,8,0.08); border:1px solid rgba(234,179,8,0.25); border-radius:6px; color:#eab308;">
        This is a BCEA-mandated leave type. Some fields are locked for compliance. You can adjust the label, description, documentation rules, advance notice, and active state.
    </div>
    @endif

    {{-- Section 1: Identification --}}
    <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
        <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Identification</h4>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Code <span class="text-red-500">*</span></label>
                <input type="text" name="code" x-model="code" @blur="code = code.toLowerCase()" required maxlength="50"
                       class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px; font-family:monospace;"
                       placeholder="e.g. sabbatical_leave"
                       {{ isset($locked) && ($locked['code'] ?? false) ? 'disabled title=Locked on system types' : '' }}>
                <p class="text-[10px] mt-0.5" style="color:var(--text-secondary, #94a3b8);">Internal reference â€” lowercase, hyphens, underscores.</p>
                @error('code') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Label <span class="text-red-500">*</span></label>
                <input type="text" name="label" value="{{ old('label', $type->label ?? '') }}" required maxlength="150"
                       class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;">
                @error('label') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Description</label>
                <textarea name="description" rows="2" class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;">{{ old('description', $type->description ?? '') }}</textarea>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Category <span class="text-red-500">*</span></label>
                <select name="category" required class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;"
                        {{ isset($locked) && ($locked['category'] ?? false) ? 'disabled' : '' }}>
                    @foreach(['annual'=>'Annual','sick'=>'Sick','family_responsibility'=>'Family Responsibility','parental'=>'Parental','study'=>'Study','unpaid'=>'Unpaid','special'=>'Special','other'=>'Other'] as $val => $lbl)
                        <option value="{{ $val }}" {{ old('category', $type->category ?? '') === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                    @endforeach
                </select>
                @error('category') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="w-32">
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Sort Order</label>
                <input type="number" name="sort_order" min="0" value="{{ old('sort_order', $type->sort_order ?? ($nextSort ?? 10)) }}"
                       class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;">
            </div>
        </div>
    </div>

    {{-- Section 2: Tax & Pay --}}
    <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
        <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Tax & Pay Treatment</h4>
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
                    <div class="w-10 h-5 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" style="background:var(--border, #cbd5e1); border-radius:10px; transition:background 0.2s;" :style="$el.previousElementSibling.checked ? 'background:#00d4aa' : ''"></div>
                    <span class="text-sm" style="color:var(--text-primary, #0f172a);">{{ $label }}</span>
                </label>
            @endforeach
        </div>
    </div>

    {{-- Section 3: Entitlement & Cycle --}}
    <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
        <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Entitlement & Cycle</h4>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Entitlement (5-day week) <span class="text-red-500">*</span></label>
                <input type="number" name="entitlement_days_per_cycle" step="0.5" min="0" max="999.99" required
                       value="{{ old('entitlement_days_per_cycle', $type->entitlement_days_per_cycle ?? 0) }}"
                       class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;"
                       {{ isset($locked) && ($locked['entitlement'] ?? false) ? 'disabled' : '' }}>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Entitlement (6-day week) <span class="text-red-500">*</span></label>
                <input type="number" name="entitlement_days_per_cycle_six_day" step="0.5" min="0" max="999.99" required
                       value="{{ old('entitlement_days_per_cycle_six_day', $type->entitlement_days_per_cycle_six_day ?? 0) }}"
                       class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;"
                       {{ isset($locked) && ($locked['entitlement'] ?? false) ? 'disabled' : '' }}>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Cycle (months) <span class="text-red-500">*</span></label>
                <input type="number" name="cycle_months" min="0" max="60" required
                       value="{{ old('cycle_months', $type->cycle_months ?? 12) }}"
                       class="w-32 px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;"
                       {{ isset($locked) && ($locked['cycle'] ?? false) ? 'disabled' : '' }}>
                <p class="text-[10px] mt-0.5" style="color:var(--text-secondary, #94a3b8);">12 for annual, 36 for sick, 0 for per-child (parental).</p>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Accrual Method <span class="text-red-500">*</span></label>
                <select name="accrual_method" x-model="accrualMethod" required class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;"
                        {{ isset($locked) && ($locked['accrual'] ?? false) ? 'disabled' : '' }}>
                    <option value="full_at_start">Full at cycle start</option>
                    <option value="accrual_per_day_worked">Accrual per day worked</option>
                    <option value="accrual_first_six_months">First 6 months special</option>
                    <option value="none">None (manual only)</option>
                </select>
            </div>
            <div x-show="accrualMethod === 'accrual_per_day_worked' || accrualMethod === 'accrual_first_six_months'" x-cloak>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Accrual Rate (1 day per N worked)</label>
                <input type="number" name="accrual_rate_per_days" min="1" max="365"
                       value="{{ old('accrual_rate_per_days', $type->accrual_rate_per_days ?? 17) }}"
                       class="w-32 px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;"
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
                    <div class="w-10 h-5 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" style="background:var(--border, #cbd5e1); border-radius:10px; transition:background 0.2s;" :style="$el.previousElementSibling.checked ? 'background:#00d4aa' : ''"></div>
                    <span class="text-sm" style="color:var(--text-primary, #0f172a);">{{ $label }}</span>
                </label>
            @endforeach
            <div class="w-48">
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Forfeit after (months)</label>
                <input type="number" name="forfeit_after_months" min="0"
                       value="{{ old('forfeit_after_months', $type->forfeit_after_months ?? '') }}"
                       class="w-32 px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;"
                       placeholder="Never">
                <p class="text-[10px] mt-0.5" style="color:var(--text-secondary, #94a3b8);">Leave blank for no auto-forfeit.</p>
            </div>
        </div>
    </div>

    {{-- Section 4: Application rules --}}
    <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
        <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Application Rules</h4>
        <div class="space-y-3">
            <label class="relative inline-flex items-center cursor-pointer gap-3">
                <input type="hidden" name="requires_pre_approval" value="0">
                <input type="checkbox" name="requires_pre_approval" value="1" class="sr-only peer"
                       {{ old('requires_pre_approval', $type->requires_pre_approval ?? true) ? 'checked' : '' }}>
                <div class="w-10 h-5 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" style="background:var(--border, #cbd5e1); border-radius:10px; transition:background 0.2s;" :style="$el.previousElementSibling.checked ? 'background:#00d4aa' : ''"></div>
                <span class="text-sm" style="color:var(--text-primary, #0f172a);">Requires pre-approval</span>
            </label>
            <div class="w-48">
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Min advance notice (days) <span class="text-red-500">*</span></label>
                <input type="number" name="min_advance_notice_days" min="0" max="365" required
                       value="{{ old('min_advance_notice_days', $type->min_advance_notice_days ?? 0) }}"
                       class="w-32 px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;">
            </div>
            <label class="relative inline-flex items-center cursor-pointer gap-3">
                <input type="hidden" name="allows_negative_balance" value="0">
                <input type="checkbox" name="allows_negative_balance" value="1" class="sr-only peer"
                       {{ old('allows_negative_balance', $type->allows_negative_balance ?? false) ? 'checked' : '' }}>
                <div class="w-10 h-5 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" style="background:var(--border, #cbd5e1); border-radius:10px; transition:background 0.2s;" :style="$el.previousElementSibling.checked ? 'background:#00d4aa' : ''"></div>
                <span class="text-sm" style="color:var(--text-primary, #0f172a);">Allow negative balance</span>
            </label>
            <label class="relative inline-flex items-center cursor-pointer gap-3">
                <input type="hidden" name="requires_documentation" value="0">
                <input type="checkbox" name="requires_documentation" value="1" x-model="requiresDoc" class="sr-only peer"
                       {{ old('requires_documentation', $type->requires_documentation ?? false) ? 'checked' : '' }}>
                <div class="w-10 h-5 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" style="background:var(--border, #cbd5e1); border-radius:10px; transition:background 0.2s;" :style="requiresDoc ? 'background:#00d4aa' : ''"></div>
                <span class="text-sm" style="color:var(--text-primary, #0f172a);">Requires documentation</span>
            </label>
            <div x-show="requiresDoc" x-cloak class="ml-[52px] grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Documentation label</label>
                    <input type="text" name="documentation_label" maxlength="150"
                           value="{{ old('documentation_label', $type->documentation_label ?? '') }}"
                           class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;"
                           placeholder="e.g. Medical certificate">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Required if leave > N days</label>
                    <input type="number" name="documentation_threshold_days" min="0" max="30"
                           value="{{ old('documentation_threshold_days', $type->documentation_threshold_days ?? '') }}"
                           class="w-32 px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;"
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
            <div class="w-10 h-5 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" style="background:var(--border, #cbd5e1); border-radius:10px; transition:background 0.2s;" :style="isActive ? 'background:#00d4aa' : ''"></div>
            <span class="text-sm font-medium" style="color:var(--text-primary, #0f172a);">Active</span>
        </label>
    </div>

    {{-- Actions --}}
    <div class="flex items-center gap-3 pt-2">
        <button type="submit" class="px-4 py-2 text-sm font-semibold text-white transition" style="background:#00d4aa; border-radius:6px;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
            {{ isset($type) && $type->exists ? 'Update' : 'Save' }} Leave Type
        </button>
        <a href="{{ route('payroll.leave.types.index') }}" class="px-4 py-2 text-sm font-semibold transition" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:6px;">Cancel</a>
    </div>
</div>
