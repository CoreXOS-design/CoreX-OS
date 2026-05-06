<div class="max-w-lg space-y-4">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Date <span class="text-red-500">*</span></label>
            <input type="date" name="holiday_date" required value="{{ old('holiday_date', $holiday->holiday_date?->format('Y-m-d')) }}"
                   class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;">
            @error('holiday_date') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Name <span class="text-red-500">*</span></label>
            <input type="text" name="name" required maxlength="100" value="{{ old('name', $holiday->name ?? '') }}"
                   class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;"
                   placeholder="e.g. Election Day">
            @error('name') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Country Code</label>
            <input type="text" name="country_code" required maxlength="2" value="{{ old('country_code', $holiday->country_code ?? 'ZA') }}"
                   class="w-24 px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Year <span class="text-red-500">*</span></label>
            <input type="number" name="applies_to_year" required min="2020" max="2099" value="{{ old('applies_to_year', $holiday->applies_to_year ?? now()->year) }}"
                   class="w-32 px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;">
        </div>
    </div>
    <label class="relative inline-flex items-center cursor-pointer gap-3">
        <input type="hidden" name="is_movable" value="0">
        <input type="checkbox" name="is_movable" value="1" class="sr-only peer" {{ old('is_movable', $holiday->is_movable ?? false) ? 'checked' : '' }}>
        <div class="w-10 h-5 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" style="background:var(--border, #cbd5e1); border-radius:10px; transition:background 0.2s;"></div>
        <span class="text-sm" style="color:var(--text-primary, #0f172a);">Moveable (calculated from Easter)</span>
    </label>

    <div class="flex items-center gap-3 pt-2">
        <button type="submit" class="px-4 py-2 text-sm font-semibold text-white transition" style="background:#00d4aa; border-radius:6px;">{{ $holiday->exists ? 'Update' : 'Save' }} Holiday</button>
        <a href="{{ route('payroll.leave.public-holidays.index') }}" class="px-4 py-2 text-sm font-semibold transition" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:6px;">Cancel</a>
    </div>
</div>
