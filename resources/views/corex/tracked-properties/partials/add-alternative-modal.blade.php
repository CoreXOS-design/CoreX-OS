{{-- Phase C3 — Add alternative address modal. Listens for 'open-add-alternative'. --}}
<div x-data="{ open: false }"
     @open-add-alternative.window="open = true"
     @keydown.escape.window="open = false"
     x-show="open" x-cloak
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     style="background: rgba(15, 23, 42, 0.55);">
    <div @click.outside="open = false"
         class="w-full max-w-lg rounded-md shadow-lg overflow-hidden"
         style="background: var(--surface); border: 1px solid var(--border);">
        <form method="POST" action="{{ route('corex.tracked-properties.address.add-alternative', $tp) }}">
            @csrf
            <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);">
                <h3 class="text-base font-semibold" style="color: var(--text-primary);">Add alternative address</h3>
                <p class="text-xs mt-1" style="color: var(--text-muted);">
                    Records a second valid address for this property without changing the primary. Both will be matched against future captures.
                </p>
            </div>

            <div class="px-5 py-4 space-y-3 max-h-[70vh] overflow-y-auto">
                <div class="grid grid-cols-3 gap-2">
                    <label class="block text-xs col-span-1" style="color: var(--text-secondary);">
                        Street #
                        <input type="text" name="street_number" value="{{ old('street_number') }}"
                               maxlength="50"
                               class="mt-1 w-full rounded px-2 py-1.5 text-sm"
                               style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">
                    </label>
                    <label class="block text-xs col-span-2" style="color: var(--text-secondary);">
                        Street name <span style="color: var(--ds-crimson, #dc2626);">*</span>
                        <input type="text" name="street_name" value="{{ old('street_name') }}"
                               required maxlength="200"
                               class="mt-1 w-full rounded px-2 py-1.5 text-sm"
                               style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">
                    </label>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <label class="block text-xs" style="color: var(--text-secondary);">
                        Unit
                        <input type="text" name="unit_number" value="{{ old('unit_number') }}"
                               maxlength="50"
                               class="mt-1 w-full rounded px-2 py-1.5 text-sm"
                               style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">
                    </label>
                    <label class="block text-xs" style="color: var(--text-secondary);">
                        Complex name
                        <input type="text" name="complex_name" value="{{ old('complex_name') }}"
                               maxlength="200"
                               class="mt-1 w-full rounded px-2 py-1.5 text-sm"
                               style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">
                    </label>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <label class="block text-xs" style="color: var(--text-secondary);">
                        Suburb <span style="color: var(--ds-crimson, #dc2626);">*</span>
                        <input type="text" name="suburb" value="{{ old('suburb') }}"
                               required maxlength="100"
                               class="mt-1 w-full rounded px-2 py-1.5 text-sm"
                               style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">
                    </label>
                    <label class="block text-xs" style="color: var(--text-secondary);">
                        Town
                        <input type="text" name="town" value="{{ old('town') }}"
                               maxlength="100"
                               class="mt-1 w-full rounded px-2 py-1.5 text-sm"
                               style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">
                    </label>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <label class="block text-xs col-span-2" style="color: var(--text-secondary);">
                        Province
                        <input type="text" name="province" value="{{ old('province') }}"
                               maxlength="100"
                               class="mt-1 w-full rounded px-2 py-1.5 text-sm"
                               style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">
                    </label>
                    <label class="block text-xs" style="color: var(--text-secondary);">
                        Postal
                        <input type="text" name="postal_code" value="{{ old('postal_code') }}"
                               maxlength="20"
                               class="mt-1 w-full rounded px-2 py-1.5 text-sm"
                               style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">
                    </label>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <label class="block text-xs" style="color: var(--text-secondary);">
                        Latitude
                        <input type="number" step="any" min="-90" max="90"
                               name="latitude" value="{{ old('latitude') }}"
                               class="mt-1 w-full rounded px-2 py-1.5 text-sm font-mono"
                               style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">
                    </label>
                    <label class="block text-xs" style="color: var(--text-secondary);">
                        Longitude
                        <input type="number" step="any" min="-180" max="180"
                               name="longitude" value="{{ old('longitude') }}"
                               class="mt-1 w-full rounded px-2 py-1.5 text-sm font-mono"
                               style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">
                    </label>
                </div>

                <label class="block text-xs" style="color: var(--text-secondary);">
                    Notes (optional)
                    <textarea name="notes" maxlength="1000" rows="2"
                              placeholder="e.g. side entrance, complex secondary reference"
                              class="mt-1 w-full rounded px-2 py-1.5 text-sm"
                              style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">{{ old('notes') }}</textarea>
                </label>
            </div>

            <div class="px-5 py-3 flex items-center justify-end gap-2"
                 style="border-top: 1px solid var(--border); background: var(--surface-2);">
                <button type="button" @click="open = false"
                        class="px-3 py-1.5 text-xs font-medium rounded"
                        style="background: transparent; color: var(--text-secondary); border: 1px solid var(--border);">
                    Cancel
                </button>
                <button type="submit"
                        class="px-3 py-1.5 text-xs font-medium rounded"
                        style="background: var(--brand-button); color: #fff;">
                    Add alternative
                </button>
            </div>
        </form>
    </div>
</div>
