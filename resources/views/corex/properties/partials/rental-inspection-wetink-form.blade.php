{{--
    .ai/specs/rental-inspections.md §16 — the upload picker shown when a
    tenant or landlord row's "Wet ink" button is clicked. Shared by both the
    tenant loop and the landlord row in rental-inspection-recording.blade.php,
    same pattern as rental-inspection-refusal-form.blade.php.

    Required include vars:
      $key        — JS expression evaluating to this row's signing key
      $saveMethod — JS expression to call on submit
--}}
<template x-if="activeWetInkKey === {!! $key !!}">
    <div class="space-y-2 pt-2">
        <p class="text-xs" style="color:var(--text-secondary);">Upload a photo or scan of the page they signed on paper.</p>
        <input type="file" accept="image/*,application/pdf"
               @change="wetInkField({!! $key !!}).file = $event.target.files[0] || null"
               class="prop-input w-full">
        <div class="flex items-center gap-2">
            <button type="button" @click="activeWetInkKey = null" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Back</button>
            <button type="button" @click="{!! $saveMethod !!}"
                    :disabled="!wetInkField({!! $key !!}).file || wetInkBusy[{!! $key !!}]"
                    class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);"
                    x-text="wetInkBusy[{!! $key !!}] ? 'Uploading…' : 'Save upload'"></button>
        </div>
    </div>
</template>
