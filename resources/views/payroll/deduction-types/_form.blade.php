<div class="max-w-2xl space-y-5" x-data="{
    code: '{{ old('code', $type->code ?? '') }}',
    isStatutory: {{ old('is_statutory', $type->is_statutory ?? false) ? 'true' : 'false' }},
    isActive: {{ old('is_active', $type->is_active ?? true) ? 'true' : 'false' }},
    sarsCode: '{{ old('sars_source_code', $type->sars_source_code ?? '') }}'
}">
    @if(isset($locked) && ($locked['statutory'] ?? false))
    <div class="p-3 text-xs font-semibold" style="background:rgba(234,179,8,0.08); border:1px solid rgba(234,179,8,0.25); border-radius:6px; color:#eab308;">
        This is a statutory deduction (PAYE/UIF). Code, SARS code, and statutory flag are locked. PAYE and UIF amounts are auto-calculated by the payroll engine â€” these rows define the deduction TYPE only.
    </div>
    @elseif(isset($locked) && ($locked['code'] ?? false))
    <div class="p-3 text-xs font-semibold" style="background:rgba(234,179,8,0.08); border:1px solid rgba(234,179,8,0.25); border-radius:6px; color:#eab308;">
        This is a system deduction type. Code and SARS code are locked. You can still edit the label, sort order, and active state.
    </div>
    @endif

    {{-- Code + Label --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Code <span class="text-red-500">*</span></label>
            <input type="text" name="code" x-model="code" @blur="code = code.toLowerCase()" required maxlength="30"
                   class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px; font-family:monospace;"
                   placeholder="e.g. loan_repayment"
                   {{ isset($locked) && ($locked['code'] ?? false) ? 'disabled title=System/statutory types have locked codes' : '' }}>
            <p class="text-[10px] mt-0.5" style="color:var(--text-secondary, #94a3b8);">Internal reference â€” lowercase, hyphens, underscores. e.g. 'loan_repayment'.</p>
            @error('code') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Label <span class="text-red-500">*</span></label>
            <input type="text" name="label" value="{{ old('label', $type->label ?? '') }}" required maxlength="100"
                   class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;"
                   placeholder="e.g. Loan Repayment">
            <p class="text-[10px] mt-0.5" style="color:var(--text-secondary, #94a3b8);">Shown on payslip.</p>
            @error('label') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>
    </div>

    {{-- SARS Source Code --}}
    <div>
        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">SARS Source Code</label>
        <input type="text" name="sars_source_code" x-model="sarsCode" maxlength="4" pattern="\d{4}"
               class="w-32 px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px; font-family:monospace;"
               placeholder="e.g. 4102"
               {{ isset($locked) && ($locked['sars'] ?? false) ? 'disabled title=Locked on system/statutory types' : '' }}>
        <p class="text-[10px] mt-0.5" style="color:var(--text-secondary, #94a3b8);">IRP5 source code. e.g. 4102 for PAYE, 4141 for UIF. Leave blank if not SARS-reportable.</p>
        @error('sars_source_code') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror

        {{-- Quick-pick chips --}}
        @if(!isset($locked) || !($locked['sars'] ?? false))
        <div class="flex flex-wrap gap-1.5 mt-2">
            @foreach(['4102' => '4102 (PAYE)', '4141' => '4141 (UIF)'] as $code => $display)
                <button type="button" @click="sarsCode = '{{ explode(' ', $code)[0] }}'"
                        class="px-2 py-0.5 text-[10px] font-semibold transition cursor-pointer"
                        style="border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-secondary, #6b7280); background:var(--surface-2, #f8fafc);"
                        onmouseover="this.style.borderColor='#00d4aa'; this.style.color='#00d4aa';"
                        onmouseout="this.style.borderColor=''; this.style.color='var(--text-secondary, #6b7280)';">{{ $display }}</button>
            @endforeach
        </div>
        @endif
    </div>

    {{-- Statutory toggle --}}
    <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
        <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Classification</h4>
        <label class="relative inline-flex items-center cursor-pointer gap-3">
            <input type="hidden" name="is_statutory" value="0">
            <input type="checkbox" name="is_statutory" value="1" x-model="isStatutory" class="sr-only peer"
                   {{ isset($locked) && ($locked['statutory'] ?? false) ? 'disabled' : '' }}>
            <div class="w-10 h-5 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" style="background:var(--border, #cbd5e1); border-radius:10px; transition:background 0.2s;" :style="isStatutory ? 'background:#00d4aa' : ''"></div>
            <div>
                <span class="text-sm font-medium" style="color:var(--text-primary, #0f172a);">Statutory deduction</span>
                <p class="text-[10px]" style="color:var(--text-secondary, #94a3b8);">PAYE, UIF â€” amounts auto-calculated by the payroll engine</p>
                @if(isset($locked) && ($locked['statutory'] ?? false))
                    <p class="text-[10px]" style="color:var(--text-secondary, #94a3b8);">Locked â€” statutory flag cannot be changed</p>
                @endif
            </div>
        </label>
    </div>

    {{-- Sort order + Active --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="w-32">
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Sort Order</label>
            <input type="number" name="sort_order" min="0" value="{{ old('sort_order', $type->sort_order ?? ($nextSort ?? 10)) }}"
                   class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Active</label>
            <label class="relative inline-flex items-center cursor-pointer gap-3 mt-1">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" x-model="isActive" class="sr-only peer">
                <div class="w-10 h-5 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" style="background:var(--border, #cbd5e1); border-radius:10px; transition:background 0.2s;" :style="isActive ? 'background:#00d4aa' : ''"></div>
                <span class="text-sm font-medium" style="color:var(--text-primary, #0f172a);">Active</span>
            </label>
        </div>
    </div>

    {{-- Actions --}}
    <div class="flex items-center gap-3 pt-2">
        <button type="submit" class="px-4 py-2 text-sm font-semibold text-white transition" style="background:#00d4aa; border-radius:6px;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
            {{ isset($type) && $type->exists ? 'Update' : 'Save' }} Deduction Type
        </button>
        <a href="{{ route('payroll.deduction-types.index') }}" class="px-4 py-2 text-sm font-semibold transition" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:6px;">Cancel</a>
    </div>
</div>
