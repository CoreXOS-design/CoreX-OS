@extends('layouts.corex')

{{--
    .ai/specs/rental-inventory.md §8 — the move-out disposition vocabulary,
    agency-configurable with a sensible default, never hardcoded. Own
    settings model/screen — see RentalInventorySetting's own docblock for
    why this is not folded into the rental-inspections settings page.
--}}

@section('corex-content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div>
            <h1 class="text-xl font-bold text-white leading-tight">Inventory Settings</h1>
            <p class="text-sm text-white/60">The options an agent picks from when recording what was found at move-out.</p>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('corex.settings.rental-inventory.update') }}" class="space-y-4"
          x-data="{ presets: {{ Js::from($dispositionPresets) }}, conditionStates: {{ Js::from($conditionStates) }}, baseline: {{ Js::from($baselineDispositionKey) }} }">
        @csrf

        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Move-in condition options</h3>
            </div>
            <div class="p-5 space-y-3">
                <p class="text-xs" style="color: var(--text-muted);">
                    The condition chip an agent taps for an item while capturing the move-in inventory.
                    "Requires a note" means the agent must explain why — useful for damaged, not usually
                    needed for a plain New/Good/Fair.
                </p>
                <template x-for="(state, i) in conditionStates" :key="i">
                    <div class="flex items-center gap-2">
                        <input type="text" x-model="state.label" :name="`condition_states[${i}][label]`"
                               maxlength="191" required placeholder="Label"
                               class="flex-1 rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                        <input type="hidden" :name="`condition_states[${i}][key]`" :value="state.key">
                        <label class="flex items-center gap-1.5 text-xs whitespace-nowrap" style="color: var(--text-secondary);">
                            <input type="checkbox" x-model="state.requires_notes" :name="`condition_states[${i}][requires_notes]`" value="1">
                            Requires a note
                        </label>
                        <button type="button" @click="conditionStates.splice(i, 1)"
                                class="text-xs font-semibold px-2 py-1 rounded-md" style="color: var(--ds-crimson);">Remove</button>
                    </div>
                </template>
                <button type="button"
                        @click="conditionStates.push({ key: 'custom_' + Date.now(), label: '', requires_notes: false })"
                        class="corex-btn-outline text-xs">+ Add an option</button>
            </div>
        </div>

        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Move-out disposition options</h3>
            </div>
            <div class="p-5 space-y-3">
                <p class="text-xs" style="color: var(--text-muted);">
                    When an agent records what an inventory item looks like at move-out, they pick one of
                    these. "Requires a note" means the agent must explain why — useful for damaged or
                    missing, not usually needed for present or short (the quantity already says that).
                </p>
                <template x-for="(preset, i) in presets" :key="i">
                    <div class="flex items-center gap-2">
                        <input type="text" x-model="preset.label" :name="`disposition_presets[${i}][label]`"
                               maxlength="191" required placeholder="Label"
                               class="flex-1 rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                        <input type="hidden" :name="`disposition_presets[${i}][key]`" :value="preset.key">
                        <label class="flex items-center gap-1.5 text-xs whitespace-nowrap" style="color: var(--text-secondary);">
                            <input type="checkbox" x-model="preset.requires_notes" :name="`disposition_presets[${i}][requires_notes]`" value="1">
                            Requires a note
                        </label>
                        <button type="button" @click="presets.splice(i, 1)"
                                class="text-xs font-semibold px-2 py-1 rounded-md" style="color: var(--ds-crimson);">Remove</button>
                    </div>
                </template>
                <button type="button"
                        @click="presets.push({ key: 'custom_' + Date.now(), label: '', requires_notes: false })"
                        class="corex-btn-outline text-xs">+ Add an option</button>

                {{-- §14 — the comparison screen's "unchanged lines collapse
                     to one grey line" rule needs to know which option means
                     nothing is wrong, never hardcoded to the word
                     "All there". Same pattern as the Inspections tab's own
                     "All Good" bulk-fill baseline picker. --}}
                <div class="pt-2" style="border-top:1px solid var(--border);">
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">
                        "Unchanged" on the move-out comparison means this option
                    </label>
                    <select name="baseline_disposition_key" x-model="baseline" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                        <template x-for="preset in presets" :key="preset.key">
                            <option :value="preset.key" x-text="preset.label"></option>
                        </template>
                    </select>
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-sm">Save</button>
        </div>
    </form>

    {{-- §41-follow-up (Job 3, 2026-09-28) — "auto-send on/off is an agency
         setting, default ON," mirrored from the same toggle on the
         rental-inspections settings page. Filing to the property is never
         optional (this toggle only governs the automatic EMAIL); the
         manual "Resend report" button on a completed inventory always
         works regardless of this setting. --}}
    <form method="POST" action="{{ route('corex.settings.rental-inventory.auto-send-report') }}" class="space-y-3">
        @csrf
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Automatic report sending</h3>
            </div>
            <div class="p-5">
                <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
                    {{-- Hidden fallback BEFORE the checkbox, same name — an
                         unchecked checkbox sends nothing at all, so without
                         this the field is simply absent from the POST. Same
                         pattern the Inspections settings page already uses.
                         Field name is `inventory_auto_send_report_enabled`,
                         not the bare `auto_send_report_enabled` Inspections'
                         own toggle uses — see the saver's own docblock: both
                         toggles are registered on the SAME onboarding wizard
                         step, whose single combined form renders each
                         field's key as a literal HTML name attribute, so a
                         shared name would collide. --}}
                    <input type="hidden" name="inventory_auto_send_report_enabled" value="0">
                    <input type="checkbox" name="inventory_auto_send_report_enabled" value="1" @checked($autoSendReportEnabled)>
                    Email the signed report to the seller/landlord (and tenant(s), when this inventory has a lease) automatically the moment an inventory completes
                </label>
                <p class="text-xs mt-1" style="color: var(--text-muted);">
                    Sent from the completing agent's own mailbox, with a copy in their Sent Items and the agent CC'd.
                    Turning this off does not remove the manual "Resend report" button on a completed inventory.
                </p>
            </div>
        </div>
        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-sm">Save</button>
        </div>
    </form>
</div>
@endsection
