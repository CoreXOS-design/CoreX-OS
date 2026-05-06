<div class="max-w-2xl space-y-5" x-data="{
    code: '{{ old('code', $type->code ?? '') }}',
    isTaxable: {{ old('is_taxable', $type->is_taxable ?? true) ? 'true' : 'false' }},
    isFringeBenefit: {{ old('is_fringe_benefit', $type->is_fringe_benefit ?? false) ? 'true' : 'false' }},
    affectsUif: {{ old('affects_uif_remuneration', $type->affects_uif_remuneration ?? true) ? 'true' : 'false' }},
    affectsSdl: {{ old('affects_sdl_remuneration', $type->affects_sdl_remuneration ?? true) ? 'true' : 'false' }},
    isActive: {{ old('is_active', $type->is_active ?? true) ? 'true' : 'false' }},
    sarsCode: '{{ old('sars_source_code', $type->sars_source_code ?? '') }}'
}">
    @if(isset($locked) && ($locked['code'] ?? false))
    <div class="p-3 text-xs font-semibold" style="background:rgba(234,179,8,0.08); border:1px solid rgba(234,179,8,0.25); border-radius:3px; color:#eab308;">
        This is a system earning type. Code, SARS code, and tax treatment are locked. You can still edit the label, sort order, and active state.
    </div>
    @endif

    {{-- Code + Label --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Code <span class="text-red-500">*</span></label>
            <input type="text" name="code" x-model="code" @blur="code = code.toLowerCase()" required maxlength="30"
                   class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px; font-family:monospace;"
                   placeholder="e.g. cell_allowance"
                   {{ isset($locked) && ($locked['code'] ?? false) ? 'disabled title=System types have locked codes' : '' }}>
            <p class="text-[10px] mt-0.5" style="color:var(--text-secondary, #94a3b8);">Internal reference — lowercase, hyphens, underscores. e.g. 'cell_allowance'. Cannot change after creation if in use.</p>
            @error('code') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Label <span class="text-red-500">*</span></label>
            <input type="text" name="label" value="{{ old('label', $type->label ?? '') }}" required maxlength="100"
                   class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;"
                   placeholder="e.g. Cell Allowance">
            <p class="text-[10px] mt-0.5" style="color:var(--text-secondary, #94a3b8);">Shown on payslip. e.g. 'Cell Allowance'.</p>
            @error('label') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>
    </div>

    {{-- SARS Source Code --}}
    <div>
        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">SARS Source Code</label>
        <input type="text" name="sars_source_code" x-model="sarsCode" maxlength="4" pattern="\d{4}"
               class="w-32 px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px; font-family:monospace;"
               placeholder="e.g. 3713"
               {{ isset($locked) && ($locked['sars'] ?? false) ? 'disabled title=System types have locked SARS codes' : '' }}>
        <p class="text-[10px] mt-0.5" style="color:var(--text-secondary, #94a3b8);">IRP5 source code. e.g. 3713 for general allowances. Leave blank if not SARS-reportable.</p>
        @error('sars_source_code') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror

        {{-- Quick-pick chips --}}
        @if(!isset($locked) || !($locked['sars'] ?? false))
        <div class="flex flex-wrap gap-1.5 mt-2">
            @foreach(['3601' => '3601', '3605' => '3605', '3606' => '3606', '3607' => '3607', '3701' => '3701', '3703' => '3703', '3713' => '3713', '3714' => '3714'] as $code => $display)
                <button type="button" @click="sarsCode = '{{ $code }}'"
                        class="px-2 py-0.5 text-[10px] font-semibold transition cursor-pointer"
                        style="border:1px solid var(--border, #e5e7eb); border-radius:3px; color:var(--text-secondary, #6b7280); background:var(--surface-2, #f8fafc);"
                        onmouseover="this.style.borderColor='#00d4aa'; this.style.color='#00d4aa';"
                        onmouseout="this.style.borderColor=''; this.style.color='var(--text-secondary, #6b7280)';">{{ $display }}</button>
            @endforeach
        </div>
        @endif
    </div>

    {{-- Tax & contribution rules --}}
    <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:3px;">
        <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em; font-family:'Plus Jakarta Sans',sans-serif;">Tax & Contribution Rules</h4>
        <div class="space-y-4">
            {{-- is_taxable --}}
            <label class="relative inline-flex items-center cursor-pointer gap-3">
                <input type="hidden" name="is_taxable" value="0">
                <input type="checkbox" name="is_taxable" value="1" x-model="isTaxable" class="sr-only peer"
                       {{ isset($locked) && ($locked['taxable'] ?? false) ? 'disabled' : '' }}>
                <div class="w-10 h-5 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" style="background:var(--border, #cbd5e1); border-radius:10px; transition:background 0.2s;" :style="isTaxable ? 'background:#00d4aa' : ''"></div>
                <div>
                    <span class="text-sm font-medium" style="color:var(--text-primary, #0f172a);">Subject to PAYE</span>
                    @if(isset($locked) && ($locked['taxable'] ?? false))
                        <p class="text-[10px]" style="color:var(--text-secondary, #94a3b8);">Locked on system types</p>
                    @endif
                </div>
            </label>

            {{-- is_fringe_benefit --}}
            <label class="relative inline-flex items-center cursor-pointer gap-3">
                <input type="hidden" name="is_fringe_benefit" value="0">
                <input type="checkbox" name="is_fringe_benefit" value="1" x-model="isFringeBenefit" class="sr-only peer"
                       {{ isset($locked) && ($locked['taxable'] ?? false) ? 'disabled' : '' }}>
                <div class="w-10 h-5 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" style="background:var(--border, #cbd5e1); border-radius:10px; transition:background 0.2s;" :style="isFringeBenefit ? 'background:#00d4aa' : ''"></div>
                <div>
                    <span class="text-sm font-medium" style="color:var(--text-primary, #0f172a);">Treated as fringe benefit</span>
                    @if(isset($locked) && ($locked['taxable'] ?? false))
                        <p class="text-[10px]" style="color:var(--text-secondary, #94a3b8);">Locked on system types</p>
                    @endif
                </div>
            </label>

            {{-- affects_uif --}}
            <label class="relative inline-flex items-center cursor-pointer gap-3">
                <input type="hidden" name="affects_uif_remuneration" value="0">
                <input type="checkbox" name="affects_uif_remuneration" value="1" x-model="affectsUif" class="sr-only peer"
                       {{ isset($locked) && ($locked['taxable'] ?? false) ? 'disabled' : '' }}>
                <div class="w-10 h-5 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" style="background:var(--border, #cbd5e1); border-radius:10px; transition:background 0.2s;" :style="affectsUif ? 'background:#00d4aa' : ''"></div>
                <span class="text-sm font-medium" style="color:var(--text-primary, #0f172a);">Counts toward UIF</span>
            </label>

            {{-- affects_sdl --}}
            <label class="relative inline-flex items-center cursor-pointer gap-3">
                <input type="hidden" name="affects_sdl_remuneration" value="0">
                <input type="checkbox" name="affects_sdl_remuneration" value="1" x-model="affectsSdl" class="sr-only peer"
                       {{ isset($locked) && ($locked['taxable'] ?? false) ? 'disabled' : '' }}>
                <div class="w-10 h-5 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" style="background:var(--border, #cbd5e1); border-radius:10px; transition:background 0.2s;" :style="affectsSdl ? 'background:#00d4aa' : ''"></div>
                <span class="text-sm font-medium" style="color:var(--text-primary, #0f172a);">Counts toward SDL</span>
            </label>
        </div>
    </div>

    {{-- Sort order + Active --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="w-32">
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Sort Order</label>
            <input type="number" name="sort_order" min="0" value="{{ old('sort_order', $type->sort_order ?? ($nextSort ?? 10)) }}"
                   class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;">
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
        <button type="submit" class="px-4 py-2 text-sm font-semibold text-white transition" style="background:#00d4aa; border-radius:3px;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
            {{ isset($type) && $type->exists ? 'Update' : 'Save' }} Earning Type
        </button>
        <a href="{{ route('payroll.earning-types.index') }}" class="px-4 py-2 text-sm font-semibold transition" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:3px;">Cancel</a>
    </div>
</div>
