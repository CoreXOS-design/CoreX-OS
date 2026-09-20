{{--
    §15.5, Stage 4 — the reason picker shown when a tenant or landlord row's
    "Refuses" button is clicked. Shared by both the tenant loop and the
    landlord row in rental-inspection-recording.blade.php.

    Required include vars:
      $key        — JS expression evaluating to this row's signing key
                    (e.g. "('in' + '_tenant_' + tenant.contact_id)")
      $saveMethod — JS expression to call on submit (e.g.
                    "saveTenantRefusalFor('in', tenant)")

    One tap for the common case (a preset reason), free text only required
    for "Other" — an agent at a front door is never blocked by a form.
--}}
<template x-if="activeRefusalKey === {!! $key !!}">
    <div class="space-y-2 pt-2">
        <select x-model="refusalField({!! $key !!}).preset" class="prop-input w-full">
            <option value="">Select a reason…</option>
            <template x-for="preset in refusalReasonPresets" :key="preset.key">
                <option :value="preset.key" x-text="preset.label"></option>
            </template>
        </select>
        <input type="text" x-show="refusalField({!! $key !!}).preset === 'other'"
               x-model="refusalField({!! $key !!}).note" placeholder="Note (required for 'Other')"
               class="prop-input w-full">
        <div class="flex items-center gap-2">
            <button type="button" @click="activeRefusalKey = null" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Back</button>
            <button type="button" @click="{!! $saveMethod !!}"
                    :disabled="!refusalField({!! $key !!}).preset || (refusalField({!! $key !!}).preset === 'other' && !refusalField({!! $key !!}).note.trim())"
                    class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Record refusal</button>
        </div>
    </div>
</template>
