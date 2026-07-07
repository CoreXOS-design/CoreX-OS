{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
<div class="max-w-2xl space-y-4">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label for="holiday_date" class="block text-xs font-medium mb-1" style="color: var(--text-secondary, #4b5563);">Date <span class="text-red-500">*</span></label>
            <input id="holiday_date" type="date" name="holiday_date" required value="{{ old('holiday_date', $holiday->holiday_date?->format('Y-m-d')) }}"
                   class="w-full rounded-md px-3 py-2 text-sm"
                   style="background: var(--surface-2, #f0f2f8); border: 1px solid var(--border, #e5e7eb); color: var(--text-primary, #111827);">
            @error('holiday_date') <p class="mt-1 text-xs" style="color: var(--ds-crimson, #c41e3a);">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="name" class="block text-xs font-medium mb-1" style="color: var(--text-secondary, #4b5563);">Name <span class="text-red-500">*</span></label>
            <input id="name" type="text" name="name" required maxlength="100" value="{{ old('name', $holiday->name ?? '') }}"
                   class="w-full rounded-md px-3 py-2 text-sm"
                   style="background: var(--surface-2, #f0f2f8); border: 1px solid var(--border, #e5e7eb); color: var(--text-primary, #111827);"
                   placeholder="e.g. Election Day">
            @error('name') <p class="mt-1 text-xs" style="color: var(--ds-crimson, #c41e3a);">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="country_code" class="block text-xs font-medium mb-1" style="color: var(--text-secondary, #4b5563);">Country Code</label>
            <input id="country_code" type="text" name="country_code" required maxlength="2" value="{{ old('country_code', $holiday->country_code ?? 'ZA') }}"
                   class="w-24 rounded-md px-3 py-2 text-sm"
                   style="background: var(--surface-2, #f0f2f8); border: 1px solid var(--border, #e5e7eb); color: var(--text-primary, #111827);">
            @error('country_code') <p class="mt-1 text-xs" style="color: var(--ds-crimson, #c41e3a);">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="applies_to_year" class="block text-xs font-medium mb-1" style="color: var(--text-secondary, #4b5563);">Year <span class="text-red-500">*</span></label>
            <input id="applies_to_year" type="number" name="applies_to_year" required min="2020" max="2099" value="{{ old('applies_to_year', $holiday->applies_to_year ?? now()->year) }}"
                   class="w-32 rounded-md px-3 py-2 text-sm"
                   style="background: var(--surface-2, #f0f2f8); border: 1px solid var(--border, #e5e7eb); color: var(--text-primary, #111827);">
            @error('applies_to_year') <p class="mt-1 text-xs" style="color: var(--ds-crimson, #c41e3a);">{{ $message }}</p> @enderror
        </div>
    </div>

    <label class="inline-flex items-center gap-3 cursor-pointer">
        <input type="hidden" name="is_movable" value="0">
        <span class="relative inline-flex flex-shrink-0" style="width:38px; height:22px;">
            <input type="checkbox" name="is_movable" value="1" class="peer sr-only" {{ old('is_movable', $holiday->is_movable ?? false) ? 'checked' : '' }}>
            <span class="absolute inset-0 rounded-full transition-colors bg-[var(--border)] peer-checked:bg-[var(--brand-button)]"></span>
            <span class="absolute top-0.5 left-0.5 w-[18px] h-[18px] rounded-full bg-white shadow transition-transform peer-checked:translate-x-4"></span>
        </span>
        <span class="text-sm" style="color: var(--text-primary, #111827);">Moveable (calculated from Easter)</span>
    </label>

    <div class="flex items-center gap-3 pt-2">
        <button type="submit" class="corex-btn-primary text-sm">{{ $holiday->exists ? 'Update' : 'Save' }} Holiday</button>
        <a href="{{ route('payroll.leave.public-holidays.index') }}" class="corex-btn-outline text-sm">Cancel</a>
    </div>
</div>
