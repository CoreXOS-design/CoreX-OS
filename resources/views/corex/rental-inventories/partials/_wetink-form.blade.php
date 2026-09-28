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
        <button type="button" @click="saveWetInk({!! $key !!})"
                :disabled="!wetInkField({!! $key !!}).file || wetInkBusy[{!! $key !!}]"
                class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);"
                x-text="wetInkBusy[{!! $key !!}] ? 'Uploading…' : 'Save upload'"></button>
    </div>
</div>
