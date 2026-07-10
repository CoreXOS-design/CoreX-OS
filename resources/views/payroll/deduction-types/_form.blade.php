<div class="max-w-2xl space-y-5" x-data="{
    code: '{{ old('code', $type->code ?? '') }}',
    isStatutory: {{ old('is_statutory', $type->is_statutory ?? false) ? 'true' : 'false' }},
    isActive: {{ old('is_active', $type->is_active ?? true) ? 'true' : 'false' }},
    sarsCode: '{{ old('sars_source_code', $type->sars_source_code ?? '') }}'
}">
    @if(isset($locked) && ($locked['statutory'] ?? false))
    <div class="p-3 text-xs font-semibold rounded-md" style="background:color-mix(in srgb, var(--ds-amber) 8%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 25%, transparent); color:var(--ds-amber);">
        This is a statutory deduction (PAYE/UIF). Code, SARS code, and statutory flag are locked. PAYE and UIF amounts are auto-calculated by the payroll engine — these rows define the deduction TYPE only.
    </div>
    @elseif(isset($locked) && ($locked['code'] ?? false))
    <div class="p-3 text-xs font-semibold rounded-md" style="background:color-mix(in srgb, var(--ds-amber) 8%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 25%, transparent); color:var(--ds-amber);">
        This is a system deduction type. Code and SARS code are locked. You can still edit the label, sort order, and active state.
    </div>
    @endif

    {{-- Code + Label --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #4b5563);">Code <span class="text-red-500">*</span></label>
            <input type="text" name="code" x-model="code" @blur="code = code.toLowerCase()" required maxlength="30"
                   class="w-full px-3 py-2 text-sm rounded-md font-mono focus:outline-none" style="background:var(--surface-2, #f0f2f8); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #111827);"
                   placeholder="e.g. loan_repayment"
                   {{ isset($locked) && ($locked['code'] ?? false) ? 'disabled title=System/statutory types have locked codes' : '' }}>
            <p class="text-[0.6875rem] mt-0.5" style="color:var(--text-muted, #9ca3af);">Internal reference — lowercase, hyphens, underscores. e.g. 'loan_repayment'.</p>
            @error('code') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #4b5563);">Label <span class="text-red-500">*</span></label>
            <input type="text" name="label" value="{{ old('label', $type->label ?? '') }}" required maxlength="100"
                   class="w-full px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface-2, #f0f2f8); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #111827);"
                   placeholder="e.g. Loan Repayment">
            <p class="text-[0.6875rem] mt-0.5" style="color:var(--text-muted, #9ca3af);">Shown on payslip.</p>
            @error('label') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>
    </div>

    {{-- SARS Source Code --}}
    <div>
        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #4b5563);">SARS Source Code</label>
        <input type="text" name="sars_source_code" x-model="sarsCode" maxlength="4" pattern="\d{4}"
               class="w-32 px-3 py-2 text-sm rounded-md font-mono focus:outline-none" style="background:var(--surface-2, #f0f2f8); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #111827);"
               placeholder="e.g. 4102"
               {{ isset($locked) && ($locked['sars'] ?? false) ? 'disabled title=Locked on system/statutory types' : '' }}>
        <p class="text-[0.6875rem] mt-0.5" style="color:var(--text-muted, #9ca3af);">IRP5 source code. e.g. 4102 for PAYE, 4141 for UIF. Leave blank if not SARS-reportable.</p>
        @error('sars_source_code') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror

        {{-- Quick-pick chips --}}
        @if(!isset($locked) || !($locked['sars'] ?? false))
        <div class="flex flex-wrap gap-1.5 mt-2">
            @foreach(['4102' => '4102 (PAYE)', '4141' => '4141 (UIF)'] as $code => $display)
                <button type="button" @click="sarsCode = '{{ explode(' ', $code)[0] }}'"
                        class="px-2 py-0.5 text-[0.6875rem] font-semibold rounded-md transition-all duration-300 cursor-pointer"
                        style="border:1px solid var(--border, #e5e7eb); color:var(--text-secondary, #4b5563); background:var(--surface-2, #f0f2f8);"
                        onmouseover="this.style.borderColor='var(--brand-icon)'; this.style.color='var(--brand-icon)';"
                        onmouseout="this.style.borderColor='var(--border, #e5e7eb)'; this.style.color='var(--text-secondary, #4b5563)';">{{ $display }}</button>
            @endforeach
        </div>
        @endif
    </div>

    {{-- Statutory toggle --}}
    <div class="p-4 rounded-md" style="background:var(--surface-2, #f0f2f8); border:1px solid var(--border, #e5e7eb);">
        <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-muted, #9ca3af); letter-spacing:0.05em;">Classification</h4>
        <label class="relative inline-flex items-center cursor-pointer gap-3">
            <input type="hidden" name="is_statutory" value="0">
            <input type="checkbox" name="is_statutory" value="1" x-model="isStatutory" class="sr-only peer"
                   {{ isset($locked) && ($locked['statutory'] ?? false) ? 'disabled' : '' }}>
            <div class="relative w-10 h-5 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" style="background:var(--border, #cbd5e1); transition:background 0.2s;" :style="isStatutory ? 'background:var(--brand-icon)' : ''"></div>
            <div>
                <span class="text-sm font-medium" style="color:var(--text-primary, #111827);">Statutory deduction</span>
                <p class="text-[0.6875rem]" style="color:var(--text-muted, #9ca3af);">PAYE, UIF — amounts auto-calculated by the payroll engine</p>
                @if(isset($locked) && ($locked['statutory'] ?? false))
                    <p class="text-[0.6875rem]" style="color:var(--text-muted, #9ca3af);">Locked — statutory flag cannot be changed</p>
                @endif
            </div>
        </label>
    </div>

    {{-- Sort order + Active --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="w-32">
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #4b5563);">Sort Order</label>
            <input type="number" name="sort_order" min="0" value="{{ old('sort_order', $type->sort_order ?? ($nextSort ?? 10)) }}"
                   class="w-full px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface-2, #f0f2f8); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #111827);">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #4b5563);">Active</label>
            <label class="relative inline-flex items-center cursor-pointer gap-3 mt-1">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" x-model="isActive" class="sr-only peer">
                <div class="relative w-10 h-5 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" style="background:var(--border, #cbd5e1); transition:background 0.2s;" :style="isActive ? 'background:var(--brand-icon)' : ''"></div>
                <span class="text-sm font-medium" style="color:var(--text-primary, #111827);">Active</span>
            </label>
        </div>
    </div>

    {{-- Actions --}}
    <div class="flex items-center gap-3 pt-2">
        <button type="submit" class="corex-btn-primary text-sm">
            {{ isset($type) && $type->exists ? 'Update' : 'Save' }} Deduction Type
        </button>
        <a href="{{ route('payroll.deduction-types.index') }}" class="corex-btn-outline text-sm">Cancel</a>
    </div>
</div>
