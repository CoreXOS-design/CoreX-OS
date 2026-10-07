{{-- Rentals step — the repeater lists an agency words for itself: refusal reasons,
     inspection condition states (+ the "All Good" baseline), photo-note
     classifications, inventory condition states, and (Build I-2) the agency's own
     room types.

     Same repeater markup as the full settings screens (corex/settings/
     rental-inspections.blade.php, rental-inventory.blade.php), rendered inside the
     wizard's single form and saved through the SAME canonical savers:
       - refusal_reason_presets  -> RentalInspectionSettingsController::update
         (has()-guarded; nothing posted = list left alone)
       - condition_states / baseline_condition_key
         + photo_note_classifications -> via RentalListsWizardSaver, which only
         delegates when this partial's *_submitted marker is present
       - inventory_condition_states -> RentalInventorySettingsController::
         updateConditionStates (own field name: the wizard form is one combined form).
       - custom_room_types -> via RentalListsWizardSaver, only when this partial's
         custom_room_types_submitted marker is present; only ACTIVE types are rendered,
         and the canonical saver archives (never deletes) a type removed here.
     Every list posts its own hidden marker so a post that never rendered this partial
     can never wipe or fail anything (agency-onboarding-setup.md §6.1).

     Vars: $wzRefusalPresets, $wzConditionStates, $wzBaselineConditionKey,
           $wzPhotoClassifications, $wzInventoryConditionStates, $wzCustomRoomTypes. --}}

@php
    $wzBox = 'background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); border-radius:6px;';
    $wzInput = 'border:1px solid var(--border,#e5e7eb);';
@endphp

<div class="space-y-6">
    <div>
        <h3 class="text-sm font-bold mb-1" style="color:var(--text-primary);">Inspection and inventory wording</h3>
        <p class="text-xs" style="color:var(--text-muted);">
            The words your team picks from when they inspect a property and record its contents. The defaults suit most
            agencies &mdash; reword them to match your own paperwork. Each list can be changed again any time from Settings.
        </p>
    </div>

    {{-- Refusal reasons --}}
    <div class="p-4 space-y-2" style="{{ $wzBox }}" x-data="{ presets: {{ Js::from(collect($wzRefusalPresets)->reject(fn ($p) => $p['key'] === 'other')->values()) }} }">
        <h4 class="text-sm font-semibold" style="color:var(--text-primary);">Reasons a tenant or landlord may refuse to sign an inspection</h4>
        <p class="text-xs" style="color:var(--text-muted);">
            What it is: the one-tap list an agent picks from when someone refuses to sign an inspection ("Other" is always available and is not listed here).
        </p>
        <p class="text-[11px]" style="color:var(--text-muted);">
            <span class="font-semibold">What this changes:</span> The choices offered on the refusal screen, and the wording recorded on the inspection and its signed report.
        </p>
        <template x-for="(preset, i) in presets" :key="i">
            <div class="flex items-center gap-2">
                <input type="text" x-model="preset.label" :name="`refusal_reason_presets[${i}][label]`" maxlength="191"
                       class="flex-1 rounded-md px-3 py-2 text-sm" style="{{ $wzInput }}">
                <input type="hidden" :name="`refusal_reason_presets[${i}][key]`" :value="preset.key">
                <button type="button" @click="presets.splice(i, 1)" class="text-xs font-semibold px-2 py-1 rounded-md" style="color:var(--ds-crimson,#e11d48);">Remove</button>
            </div>
        </template>
        <button type="button" @click="presets.push({ key: 'custom_' + Date.now(), label: '' })" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="{{ $wzInput }} color:var(--text-primary);">+ Add a reason</button>
    </div>

    {{-- Inspection condition states --}}
    <div class="p-4 space-y-2" style="{{ $wzBox }}"
         x-data="{
             states: {{ Js::from($wzConditionStates) }},
             baseline: {{ Js::from($wzBaselineConditionKey) }},
             severities: {{ Js::from(\App\Models\RentalInspectionSetting::SEVERITY_LABELS) }},
             addState() { this.states.push({ key: 'custom_' + Date.now(), label: '', requires_notes: true, severity: 'red' }); },
         }">
        <input type="hidden" name="condition_states_submitted" value="1">
        <h4 class="text-sm font-semibold" style="color:var(--text-primary);">Inspection condition ratings</h4>
        <p class="text-xs" style="color:var(--text-muted);">
            What it is: what an inspector can grade an item as, in the order offered. "Needs a reason" means a note must be typed before that rating can be saved; "Colour" is how it shows on screen and on the signed report.
        </p>
        <p class="text-[11px]" style="color:var(--text-muted);">
            <span class="font-semibold">What this changes:</span> The rating buttons agents see on every rental inspection, which ratings demand a note, and which ratings count towards "Needs attention".
        </p>
        <template x-for="(state, i) in states" :key="state.key">
            <div class="flex flex-wrap items-center gap-2">
                <input type="text" x-model="state.label" :name="`condition_states[${i}][label]`" maxlength="60" placeholder="Label"
                       class="flex-1 min-w-[10rem] rounded-md px-3 py-2 text-sm" style="{{ $wzInput }}">
                <label class="flex items-center gap-1.5 text-xs whitespace-nowrap" style="color:var(--text-secondary,#475569);">
                    <input type="checkbox" x-model="state.requires_notes"> Needs a reason
                </label>
                <label class="flex items-center gap-1.5 text-xs whitespace-nowrap" style="color:var(--text-secondary,#475569);">
                    Colour
                    <select x-model="state.severity" :name="`condition_states[${i}][severity]`" class="rounded-md px-2 py-1 text-xs" style="{{ $wzInput }}">
                        <template x-for="(label, sevKey) in severities" :key="sevKey">
                            <option :value="sevKey" x-text="label"></option>
                        </template>
                    </select>
                </label>
                <input type="hidden" :name="`condition_states[${i}][key]`" :value="state.key">
                <input type="hidden" :name="`condition_states[${i}][requires_notes]`" :value="state.requires_notes ? '1' : '0'">
                <button type="button" @click="states.splice(i, 1)" :disabled="states.length <= 1" class="text-xs font-semibold px-2 py-1 rounded-md" style="color:var(--ds-crimson,#e11d48);">Remove</button>
            </div>
        </template>
        <button type="button" @click="addState()" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="{{ $wzInput }} color:var(--text-primary);">+ Add a rating</button>
        <div class="pt-2" style="border-top:1px solid var(--border,#e5e7eb);">
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary,#475569);">"All Good" bulk-fill uses this rating</label>
            <select name="baseline_condition_key" x-model="baseline" class="rounded-md px-3 py-2 text-sm" style="{{ $wzInput }}">
                <template x-for="state in states" :key="state.key">
                    <option :value="state.key" x-text="state.label"></option>
                </template>
            </select>
        </div>
    </div>

    {{-- Photo-note classifications --}}
    <div class="p-4 space-y-2" style="{{ $wzBox }}" x-data="{ classifications: {{ Js::from($wzPhotoClassifications) }} }">
        <input type="hidden" name="photo_note_classifications_submitted" value="1">
        <h4 class="text-sm font-semibold" style="color:var(--text-primary);">Photo note types</h4>
        <p class="text-xs" style="color:var(--text-muted);">
            What it is: what an inspector can label a note on a photo as (for example a defect, or normal wear and tear). At least one is required.
        </p>
        <p class="text-[11px]" style="color:var(--text-muted);">
            <span class="font-semibold">What this changes:</span> The choices offered when a note is added to an inspection photo; the "Defect" type feeds the defect list at the end of the printed report.
        </p>
        <template x-for="(row, i) in classifications" :key="row.key">
            <div class="flex items-center gap-2">
                <input type="text" x-model="row.label" :name="`photo_note_classifications[${i}][label]`" maxlength="60" placeholder="Label"
                       class="flex-1 rounded-md px-3 py-2 text-sm" style="{{ $wzInput }}">
                <input type="hidden" :name="`photo_note_classifications[${i}][key]`" :value="row.key">
                <button type="button" @click="classifications.splice(i, 1)" :disabled="classifications.length <= 1" class="text-xs font-semibold px-2 py-1 rounded-md" style="color:var(--ds-crimson,#e11d48);">Remove</button>
            </div>
        </template>
        <button type="button" @click="classifications.push({ key: 'custom_' + Date.now(), label: '' })" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="{{ $wzInput }} color:var(--text-primary);">+ Add a type</button>
    </div>

    {{-- Your own room types (Build I-2, §45.4 item 3) --}}
    <div class="p-4 space-y-2" style="{{ $wzBox }}" x-data="{ types: {{ Js::from(collect($wzCustomRoomTypes ?? [])->map(fn ($t) => ['key' => $t['key'], 'label' => $t['label']])->values()) }} }">
        <input type="hidden" name="custom_room_types_submitted" value="1">
        <h4 class="text-sm font-semibold" style="color:var(--text-primary);">Your own room types</h4>
        <p class="text-xs" style="color:var(--text-muted);">
            What it is: rooms or areas you inspect that CoreX's 50 standard room types do not cover &mdash; for example a roof space, a DB board or a pool house. Leave this empty if the standard list is enough.
        </p>
        <p class="text-[11px]" style="color:var(--text-muted);">
            <span class="font-semibold">What this changes:</span> The room-type choices offered when an agent adds a room to a property's inspection checklist, plus the room-type checklists and walking order in Settings. Removing one here only archives it &mdash; rooms already using it are not touched, and it can be restored from Settings.
        </p>
        <template x-for="(row, i) in types" :key="i">
            <div class="flex items-center gap-2">
                <input type="text" x-model="row.label" :name="`custom_room_types[${i}][label]`" maxlength="60" placeholder="Room type name"
                       class="flex-1 rounded-md px-3 py-2 text-sm" style="{{ $wzInput }}">
                <input type="hidden" :name="`custom_room_types[${i}][key]`" :value="row.key">
                <button type="button" @click="types.splice(i, 1)" class="text-xs font-semibold px-2 py-1 rounded-md" style="color:var(--ds-crimson,#e11d48);">Remove</button>
            </div>
        </template>
        <button type="button" @click="types.push({ key: '', label: '' })" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="{{ $wzInput }} color:var(--text-primary);">+ Add a room type</button>
    </div>

    {{-- Inventory condition states --}}
    <div class="p-4 space-y-2" style="{{ $wzBox }}" x-data="{ states: {{ Js::from($wzInventoryConditionStates) }} }">
        <input type="hidden" name="inventory_condition_states_submitted" value="1">
        <h4 class="text-sm font-semibold" style="color:var(--text-primary);">Inventory condition ratings</h4>
        <p class="text-xs" style="color:var(--text-muted);">
            What it is: the condition choices offered when an agent records an item in a rental inventory. "Needs a reason" means a note is required for that rating.
        </p>
        <p class="text-[11px]" style="color:var(--text-muted);">
            <span class="font-semibold">What this changes:</span> The condition buttons shown on every inventory line, and which ratings force a note to be typed.
        </p>
        <template x-for="(state, i) in states" :key="i">
            <div class="flex flex-wrap items-center gap-2">
                <input type="text" x-model="state.label" :name="`inventory_condition_states[${i}][label]`" maxlength="191" placeholder="Label"
                       class="flex-1 min-w-[10rem] rounded-md px-3 py-2 text-sm" style="{{ $wzInput }}">
                <label class="flex items-center gap-1.5 text-xs whitespace-nowrap" style="color:var(--text-secondary,#475569);">
                    <input type="checkbox" x-model="state.requires_notes"> Needs a reason
                </label>
                <input type="hidden" :name="`inventory_condition_states[${i}][key]`" :value="state.key">
                <input type="hidden" :name="`inventory_condition_states[${i}][requires_notes]`" :value="state.requires_notes ? '1' : '0'">
                <button type="button" @click="states.splice(i, 1)" class="text-xs font-semibold px-2 py-1 rounded-md" style="color:var(--ds-crimson,#e11d48);">Remove</button>
            </div>
        </template>
        <button type="button" @click="states.push({ key: 'custom_' + Date.now(), label: '', requires_notes: false })" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="{{ $wzInput }} color:var(--text-primary);">+ Add a rating</button>
    </div>
</div>
