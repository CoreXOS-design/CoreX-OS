<div class="max-w-2xl space-y-5" x-data="{
    name: '{{ old('name', $type->name ?? '') }}',
    slug: '{{ old('slug', $type->slug ?? '') }}',
    hasExpiry: {{ old('has_expiry', $type->has_expiry ?? true) ? 'true' : 'false' }},
    autoSlug: {{ isset($type) && $type->exists ? 'false' : 'true' }}
}">
    {{-- Name + Slug --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Name <span class="text-red-500">*</span></label>
            <input type="text" name="name" x-model="name" @input="if(autoSlug) slug = name.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '')" required maxlength="100"
                   class="w-full rounded-md px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #f0f2f8); border:1px solid var(--border, rgba(0,0,0,0.07)); color:var(--text-primary, #111827);"
                   placeholder="e.g. FFC Certificate">
            @error('name') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Slug <span class="text-red-500">*</span></label>
            <input type="text" name="slug" x-model="slug" @focus="autoSlug = false" required maxlength="100" pattern="[a-z0-9_]+"
                   class="w-full rounded-md px-3 py-2 text-sm font-mono focus:outline-none" style="background:var(--surface-2, #f0f2f8); border:1px solid var(--border, rgba(0,0,0,0.07)); color:var(--text-primary, #111827);"
                   placeholder="e.g. ffc_certificate">
            <p class="text-[0.6875rem] mt-0.5" style="color:var(--text-muted, #9ca3af);">URL-safe identifier, used internally. Lowercase letters, numbers, underscores only.</p>
            @error('slug') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>
    </div>

    {{-- Description --}}
    <div>
        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Description</label>
        <textarea name="description" rows="2" maxlength="500"
                  class="w-full rounded-md px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #f0f2f8); border:1px solid var(--border, rgba(0,0,0,0.07)); color:var(--text-primary, #111827);"
                  placeholder="Optional helper text for staff">{{ old('description', $type->description ?? '') }}</textarea>
        @error('description') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
    </div>

    {{-- Expiry & Renewal --}}
    <div class="rounded-md p-4" style="background:var(--surface-2, #f0f2f8); border:1px solid var(--border, rgba(0,0,0,0.07));">
        <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-muted, #9ca3af); letter-spacing:0.05em;">Expiry & Renewal</h4>
        <div class="space-y-3">
            <label class="inline-flex items-center cursor-pointer gap-3">
                <input type="hidden" name="has_expiry" value="0">
                <input type="checkbox" name="has_expiry" value="1" x-model="hasExpiry" class="sr-only peer">
                <div class="relative w-10 h-5 rounded-full flex-shrink-0 peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" style="background:var(--border, rgba(0,0,0,0.14)); transition:background 0.2s;" :style="hasExpiry ? 'background:var(--brand-icon, #0ea5e9)' : ''"></div>
                <span class="text-sm font-medium" style="color:var(--text-primary, #111827);">This document expires</span>
            </label>

            <div x-show="hasExpiry" x-cloak class="ml-[52px]">
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Auto-create renewal task X days before expiry</label>
                <input type="number" name="renewal_days" min="1" max="3650"
                       value="{{ old('renewal_days', $type->renewal_days ?? '') }}"
                       class="w-32 rounded-md px-3 py-2 text-sm focus:outline-none" style="background:var(--surface, #ffffff); border:1px solid var(--border, rgba(0,0,0,0.07)); color:var(--text-primary, #111827);"
                       placeholder="e.g. 90">
                <p class="text-[0.6875rem] mt-0.5" style="color:var(--text-muted, #9ca3af);">Leave blank for no auto-reminder. Typical: 30 (lease), 90 (bank letter), 365 (FFC).</p>
                @error('renewal_days') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    {{-- Options --}}
    <div class="rounded-md p-4" style="background:var(--surface-2, #f0f2f8); border:1px solid var(--border, rgba(0,0,0,0.07));">
        <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-muted, #9ca3af); letter-spacing:0.05em;">Options</h4>
        <div class="space-y-4">
            <label class="inline-flex items-center cursor-pointer gap-3" x-data="{ on: {{ old('required', $type->required ?? true) ? 'true' : 'false' }} }">
                <input type="hidden" name="required" value="0">
                <input type="checkbox" name="required" value="1" x-model="on" class="sr-only peer">
                <div class="relative w-10 h-5 rounded-full flex-shrink-0 peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" style="background:var(--border, rgba(0,0,0,0.14)); transition:background 0.2s;" :style="on ? 'background:var(--brand-icon, #0ea5e9)' : ''"></div>
                <div>
                    <span class="text-sm font-medium" style="color:var(--text-primary, #111827);">Required</span>
                    <p class="text-[0.6875rem]" style="color:var(--text-muted, #9ca3af);">Mark document as mandatory for compliance</p>
                </div>
            </label>

        </div>
    </div>

    {{-- Sort order --}}
    <div class="w-32">
        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Sort Order</label>
        <input type="number" name="sort_order" min="0" value="{{ old('sort_order', $type->sort_order ?? 0) }}"
               class="w-full rounded-md px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #f0f2f8); border:1px solid var(--border, rgba(0,0,0,0.07)); color:var(--text-primary, #111827);">
    </div>

    {{-- Actions --}}
    <div class="flex items-center gap-3 pt-2">
        <button type="submit" class="corex-btn-primary">
            {{ isset($type) && $type->exists ? 'Update' : 'Create' }} Document Type
        </button>
        <a href="{{ route('compliance.document-types.index') }}" class="corex-btn-outline">Cancel</a>
    </div>
</div>
