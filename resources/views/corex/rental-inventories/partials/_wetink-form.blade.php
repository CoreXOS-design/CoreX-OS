{{--
    Conductor brief 2026-09-29 — the upload picker shown when a party's
    "Wet ink" / "Upload scan" / "Replace" action is clicked. Mirrors
    resources/views/corex/properties/partials/rental-inspection-wetink-form.blade.php
    for Inventory's own dedicated Alpine component (rentalInventoryShow in
    show.blade.php's own <script> block) — same pattern, different host page.

    Required include var:
      $key — JS expression evaluating to this row's wet-ink key
--}}
<div class="space-y-2 pt-2">
    <p class="text-xs" style="color:var(--text-secondary);">Upload a photo or scan of the page they signed on paper.</p>
    <input type="file" accept="image/*,application/pdf"
           @change="wetInkField({!! $key !!}).file = $event.target.files[0] || null"
           class="prop-input w-full">
    <div class="flex items-center gap-2">
        <button type="button" @click="activeWetInkKey = null" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Back</button>
        {{-- rental-inventory.md §21 (found via the real click-through) —
             wetInkBusy[key] is undefined until saveWetInk() runs at least
             once, and `false || undefined` is `undefined`, not `false`.
             :disabled binds that straight to toggleAttribute('disabled',
             undefined) — a WebIDL optional-boolean arg passed as literal
             undefined is spec'd as OMITTED, so it just flips the
             attribute's CURRENT state instead of forcing it — the exact
             bug class §20.1 already documents for this codebase. `!!`
             coerces to a genuine boolean on every evaluation. --}}
        <button type="button" @click="saveWetInk({!! $key !!})"
                :disabled="!wetInkField({!! $key !!}).file || !!wetInkBusy[{!! $key !!}]"
                class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);"
                x-text="wetInkBusy[{!! $key !!}] ? 'Uploading…' : 'Save upload'"></button>
    </div>
</div>
