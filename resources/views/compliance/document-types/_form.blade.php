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
                   class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, var(--text-primary)); border-radius:6px;"
                   placeholder="e.g. FFC Certificate">
            @error('name') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Slug <span class="text-red-500">*</span></label>
            <input type="text" name="slug" x-model="slug" @focus="autoSlug = false" required maxlength="100" pattern="[a-z0-9_]+"
                   class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, var(--text-primary)); border-radius:6px; font-family:monospace;"
                   placeholder="e.g. ffc_certificate">
            <p class="text-[10px] mt-0.5" style="color:var(--text-secondary, #94a3b8);">URL-safe identifier, used internally. Lowercase letters, numbers, underscores only.</p>
            @error('slug') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>
    </div>

    {{-- Description --}}
    <div>
        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Description</label>
        <textarea name="description" rows="2" maxlength="500"
                  class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, var(--text-primary)); border-radius:6px;"
                  placeholder="Optional helper text for staff">{{ old('description', $type->description ?? '') }}</textarea>
        @error('description') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
    </div>

    {{-- Expiry & Renewal --}}
    <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
        <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Expiry & Renewal</h4>
        <div class="space-y-3">
            <label class="relative inline-flex items-center cursor-pointer gap-3">
                <input type="hidden" name="has_expiry" value="0">
                <input type="checkbox" name="has_expiry" value="1" x-model="hasExpiry" class="sr-only peer">
                <div class="w-10 h-5 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" style="background:var(--border, #cbd5e1); border-radius:10px; transition:background 0.2s;" :style="hasExpiry ? 'background:var(--brand-icon)' : ''"></div>
                <span class="text-sm font-medium" style="color:var(--text-primary, var(--text-primary));">This document expires</span>
            </label>

            <div x-show="hasExpiry" x-cloak class="ml-[52px]">
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Auto-create renewal task X days before expiry</label>
                <input type="number" name="renewal_days" min="1" max="3650"
                       value="{{ old('renewal_days', $type->renewal_days ?? '') }}"
                       class="w-32 px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, var(--text-primary)); border-radius:6px;"
                       placeholder="e.g. 90">
                <p class="text-[10px] mt-0.5" style="color:var(--text-secondary, #94a3b8);">Leave blank for no auto-reminder. Typical: 30 (lease), 90 (bank letter), 365 (FFC).</p>
                @error('renewal_days') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    {{-- Options --}}
    <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
        <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Options</h4>
        <div class="space-y-4">
            <label class="relative inline-flex items-center cursor-pointer gap-3" x-data="{ on: {{ old('required', $type->required ?? true) ? 'true' : 'false' }} }">
                <input type="hidden" name="required" value="0">
                <input type="checkbox" name="required" value="1" x-model="on" class="sr-only peer">
                <div class="w-10 h-5 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5" style="background:var(--border, #cbd5e1); border-radius:10px; transition:background 0.2s;" :style="on ? 'background:var(--brand-icon)' : ''"></div>
                <div>
                    <span class="text-sm font-medium" style="color:var(--text-primary, var(--text-primary));">Required</span>
                    <p class="text-[10px]" style="color:var(--text-secondary, #94a3b8);">Mark document as mandatory for compliance</p>
                </div>
            </label>

        </div>
    </div>

    {{-- Sort order --}}
    <div class="w-32">
        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Sort Order</label>
        <input type="number" name="sort_order" min="0" value="{{ old('sort_order', $type->sort_order ?? 0) }}"
               class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, var(--text-primary)); border-radius:6px;">
    </div>

    {{-- Actions --}}
    <div class="flex items-center gap-3 pt-2">
        <button type="submit" class="px-4 py-2 text-sm font-semibold text-white transition" style="background:var(--brand-icon); border-radius:6px;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
            {{ isset($type) && $type->exists ? 'Update' : 'Create' }} Document Type
        </button>
        <a href="{{ route('compliance.document-types.index') }}" class="px-4 py-2 text-sm font-semibold transition" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:6px;">Cancel</a>
    </div>
</div>
