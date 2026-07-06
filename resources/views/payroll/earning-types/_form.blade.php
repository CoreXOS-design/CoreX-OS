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
    <div class="p-3 text-xs font-semibold rounded-md" style="background:color-mix(in srgb, var(--ds-amber) 8%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 25%, transparent); color:var(--ds-amber);">
        This is a system earning type. Code, SARS code, and tax treatment are locked. You can still edit the label, sort order, and active state.
    </div>
    @endif

    {{-- Code + Label --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #4b5563);">Code <span class="text-red-500">*</span></label>
            <input type="text" name="code" x-model="code" @blur="code = code.toLowerCase()" required maxlength="30"
                   class="w-full px-3 py-2 text-sm rounded-md font-mono focus:outline-none" style="background:var(--surface-2, #f0f2f8); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #111827);"
                   placeholder="e.g. cell_allowance"
                   {{ isset($locked) && ($locked['code'] ?? false) ? 'disabled title=System types have locked codes' : '' }}>
            <p class="text-[0.6875rem] mt-0.5" style="color:var(--text-muted, #9ca3af);">Internal reference — lowercase, hyphens, underscores. e.g. 'cell_allowance'. Cannot change after creation if in use.</p>
            @error('code') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #4b5563);">Label <span class="text-red-500">*</span></label>
            <input type="text" name="label" value="{{ old('label', $type->label ?? '') }}" required maxlength="100"
                   class="w-full px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface-2, #f0f2f8); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #111827);"
                   placeholder="e.g. Cell Allowance">
            <p class="text-[0.6875rem] mt-0.5" style="color:var(--text-muted, #9ca3af);">Shown on payslip. e.g. 'Cell Allowance'.</p>
            @error('label') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>
    </div>

    {{-- SARS Source Code --}}
    <div>
        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #4b5563);">SARS Source Code</label>
        <input type="text" name="sars_source_code" x-model="sarsCode" maxlength="4" pattern="\d{4}"
               class="w-32 px-3 py-2 text-sm rounded-md font-mono focus:outline-none" style="background:var(--surface-2, #f0f2f8); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #111827);"
               placeholder="e.g. 3713"
               {{ isset($locked) && ($locked['sars'] ?? false) ? 'disabled title=System types have locked SARS codes' : '' }}>
        <p class="text-[0.6875rem] mt-0.5" style="color:var(--text-muted, #9ca3af);">IRP5 source code. e.g. 3713 for general allowances. Leave blank if not SARS-reportable.</p>
        @error('sars_source_code') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror

        {{-- Quick-pick chips --}}
        @if(!isset($locked) || !($locked['sars'] ?? false))
        <div class="flex flex-wrap gap-1.5 mt-2">
            @foreach(['3601' => '3601', '3605' => '3605', '3606' => '3606', '3607' => '3607', '3701' => '3701', '3703' => '3703', '3713' => '3713', '3714' => '3714'] as $code => $display)
                <button type="button" @click="sarsCode = '{{ $code }}'"
                        class="px-2 py-0.5 text-[0.6875rem] font-semibold rounded-md transition-all duration-300 cursor-pointer"
                        style="border:1px solid var(--border, #e5e7eb); color:var(--text-secondary, #4b5563); background:var(--surface-2, #f0f2f8);"
                        onmouseover="this.style.borderColor='var(--brand-icon)'; this.style.color='var(--brand-icon)';"
                        onmouseout="this.style.borderColor='var(--border, #e5e7eb)'; this.style.color='var(--text-secondary, #4b5563)';">{{ $display }}</button>
            @endforeach
        </div>
        @endif
    </div>

    {{-- Tax & contribution rules --}}
    <div class="p-4 rounded-md" style="background:var(--surface-2, #f0f2f8); border:1px solid var(--border, #e5e7eb);">
        <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-muted, #9ca3af); letter-spacing:0.05em;">Tax & Contribution Rules</h4>
        <div class="space-y-4">
            {{-- is_taxable --}}
            <label class="relative inline-flex items-center cursor-pointer gap-3">
                <input type="hidden" name="is_taxable" value="0">
                <input type="checkbox" name="is_taxable" value="1" x-model="isTaxable" class="sr-only peer"
                       {{ isset($locked) && ($locked['taxable'] ?? false) ? 'disabled' : '' }}>
                <div class="relative w-10 h-5 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" style="background:var(--border, #cbd5e1); transition:background 0.2s;" :style="isTaxable ? 'background:var(--brand-icon)' : ''"></div>
                <div>
                    <span class="text-sm font-medium" style="color:var(--text-primary, #111827);">Subject to PAYE</span>
                    @if(isset($locked) && ($locked['taxable'] ?? false))
                        <p class="text-[0.6875rem]" style="color:var(--text-muted, #9ca3af);">Locked on system types</p>
                    @endif
                </div>
            </label>

            {{-- is_fringe_benefit --}}
            <label class="relative inline-flex items-center cursor-pointer gap-3">
                <input type="hidden" name="is_fringe_benefit" value="0">
                <input type="checkbox" name="is_fringe_benefit" value="1" x-model="isFringeBenefit" class="sr-only peer"
                       {{ isset($locked) && ($locked['taxable'] ?? false) ? 'disabled' : '' }}>
                <div class="relative w-10 h-5 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" style="background:var(--border, #cbd5e1); transition:background 0.2s;" :style="isFringeBenefit ? 'background:var(--brand-icon)' : ''"></div>
                <div>
                    <span class="text-sm font-medium" style="color:var(--text-primary, #111827);">Treated as fringe benefit</span>
                    @if(isset($locked) && ($locked['taxable'] ?? false))
                        <p class="text-[0.6875rem]" style="color:var(--text-muted, #9ca3af);">Locked on system types</p>
                    @endif
                </div>
            </label>

            {{-- affects_uif --}}
            <label class="relative inline-flex items-center cursor-pointer gap-3">
                <input type="hidden" name="affects_uif_remuneration" value="0">
                <input type="checkbox" name="affects_uif_remuneration" value="1" x-model="affectsUif" class="sr-only peer"
                       {{ isset($locked) && ($locked['taxable'] ?? false) ? 'disabled' : '' }}>
                <div class="relative w-10 h-5 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" style="background:var(--border, #cbd5e1); transition:background 0.2s;" :style="affectsUif ? 'background:var(--brand-icon)' : ''"></div>
                <span class="text-sm font-medium" style="color:var(--text-primary, #111827);">Counts toward UIF</span>
            </label>

            {{-- affects_sdl --}}
            <label class="relative inline-flex items-center cursor-pointer gap-3">
                <input type="hidden" name="affects_sdl_remuneration" value="0">
                <input type="checkbox" name="affects_sdl_remuneration" value="1" x-model="affectsSdl" class="sr-only peer"
                       {{ isset($locked) && ($locked['taxable'] ?? false) ? 'disabled' : '' }}>
                <div class="relative w-10 h-5 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" style="background:var(--border, #cbd5e1); transition:background 0.2s;" :style="affectsSdl ? 'background:var(--brand-icon)' : ''"></div>
                <span class="text-sm font-medium" style="color:var(--text-primary, #111827);">Counts toward SDL</span>
            </label>
        </div>
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
            {{ isset($type) && $type->exists ? 'Update' : 'Save' }} Earning Type
        </button>
        <a href="{{ route('payroll.earning-types.index') }}" class="corex-btn-outline text-sm">Cancel</a>
    </div>
</div>
