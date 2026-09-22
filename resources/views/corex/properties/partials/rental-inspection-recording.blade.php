{{--
    Rental inspection recording — shared by In Inspection and Out Inspection.
    Rendered inside the `rentalImages()` Alpine component.
    Spec: .ai/specs/rental-inspections.md §4/§14, §18 (Inspections-tab
    rebuild, 2026-09-22 — autosave, one-tap condition, All Good bulk-fill,
    progress/collapse, read-only property type).

    Required include var:
      $section — 'in' or 'out' (literal PHP string, used to build the JS
                 string literal below)

    currentInspection() never returns a completed/cancelled one (§0.5's
    currentFor() excludes them), so the statuses possible here are only
    draft, in_progress, awaiting_signature — the lifecycle controls below
    don't need a "completed" branch.
--}}
@php($sectionJs = "'{$section}'")

<template x-if="!currentInspection({{ $sectionJs }})">
    <div class="space-y-2">
        <div x-show="startError[{{ $sectionJs }}]" x-cloak class="text-xs" style="color:#ef4444;" x-text="startError[{{ $sectionJs }}]"></div>
        <button type="button" :disabled="startBusy[{{ $sectionJs }}]" @click="startInspection({{ $sectionJs }})"
                class="px-4 py-2 rounded-md text-sm font-semibold text-white" style="background:var(--brand-button,#0ea5e9);"
                x-text="startBusy[{{ $sectionJs }}] ? 'Starting…' : 'Start {{ ucfirst($section) }}-Inspection'"></button>
    </div>
</template>

<template x-if="currentInspection({{ $sectionJs }})">
    <div class="space-y-3">
        {{--
            §17 — the header block, from Johan's real paper form: everything
            above the room tables. "Pull what we already know" — landlord,
            tenants and the recording agent are DISPLAY ONLY here (read from
            the same lease/property/user relations §15's signing block
            already resolves — never a second name field to type into).
            Meter readings, furnished state, keys/remotes and (out-inspection
            only) the move-in date are editable, defaulted from the
            property/lease at inspection start (RentalInspection::start()) —
            confirm-or-correct, not retype. Item 2/8 (2026-09-22): every
            field autosaves on change — no Save button — and property type
            is read-only, derived from the Property record (item 8: "the
            property already knows it").
        --}}
        <div class="rounded-md p-3 space-y-2" style="background:var(--surface-2);">
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-1 text-xs" style="color:var(--text-secondary);">
                <div><span style="color:var(--text-muted);">Landlord:</span> <span x-text="landlordContact ? (landlordContact.first_name + ' ' + landlordContact.last_name) : '—'"></span></div>
                <template x-for="(tenant, idx) in inspectionTenants({{ $sectionJs }})" :key="tenant.contact_id">
                    <div><span style="color:var(--text-muted);" x-text="'Tenant ' + (idx + 1) + ':'"></span> <span x-text="tenantName(tenant)"></span></div>
                </template>
                <div><span style="color:var(--text-muted);">Inspection done by:</span> <span x-text="currentInspection({{ $sectionJs }}).created_by?.name || '—'"></span></div>
                <div><span style="color:var(--text-muted);">Property type:</span> <span x-text="propertyType || '—'"></span></div>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 pt-1" style="border-top:1px solid var(--border);">
                <div>
                    <label class="text-xs font-semibold" style="color:var(--text-secondary);">Electricity meter</label>
                    <input type="text" x-model="currentInspection({{ $sectionJs }}).electricity_meter_reading"
                           @input="autosaveDetails({{ $sectionJs }})" placeholder="Reading, or e.g. BODY CORP" class="prop-input w-full">
                </div>
                <div>
                    <label class="text-xs font-semibold" style="color:var(--text-secondary);">Water meter</label>
                    <input type="text" x-model="currentInspection({{ $sectionJs }}).water_meter_reading"
                           @input="autosaveDetails({{ $sectionJs }})" placeholder="Reading, or e.g. BODY CORP" class="prop-input w-full">
                </div>
                <div>
                    <label class="text-xs font-semibold" style="color:var(--text-secondary);">Furnished</label>
                    <select x-model="currentInspection({{ $sectionJs }}).furnished_status" @change="autosaveDetails({{ $sectionJs }})" class="prop-input w-full">
                        <option value="">— Select —</option>
                        @foreach($settingItems['furnishedStatuses'] ?? [] as $fs)
                            <option value="{{ $fs->name }}">{{ $fs->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex gap-1">
                    <div style="width:4.5rem;">
                        <label class="text-xs font-semibold" style="color:var(--text-secondary);">Keys</label>
                        <input type="number" min="0" x-model.number="currentInspection({{ $sectionJs }}).keys_count"
                               @input="autosaveDetails({{ $sectionJs }})" class="prop-input w-full">
                    </div>
                    <div class="flex-1">
                        <label class="text-xs font-semibold" style="color:var(--text-secondary);">&nbsp;</label>
                        <input type="text" x-model="currentInspection({{ $sectionJs }}).keys_description"
                               @input="autosaveDetails({{ $sectionJs }})" placeholder="e.g. set keys" class="prop-input w-full">
                    </div>
                </div>
                <div class="flex gap-1">
                    <div style="width:4.5rem;">
                        <label class="text-xs font-semibold" style="color:var(--text-secondary);">Remotes</label>
                        <input type="number" min="0" x-model.number="currentInspection({{ $sectionJs }}).remotes_count"
                               @input="autosaveDetails({{ $sectionJs }})" class="prop-input w-full">
                    </div>
                    <div class="flex-1">
                        <label class="text-xs font-semibold" style="color:var(--text-secondary);">&nbsp;</label>
                        <input type="text" x-model="currentInspection({{ $sectionJs }}).remotes_description"
                               @input="autosaveDetails({{ $sectionJs }})" placeholder="e.g. gate remotes" class="prop-input w-full">
                    </div>
                </div>
                @if($section === 'out')
                <div>
                    <label class="text-xs font-semibold" style="color:var(--text-secondary);">Move-in date</label>
                    <input type="date" x-model="currentInspection({{ $sectionJs }}).move_in_date_recorded"
                           @change="autosaveDetails({{ $sectionJs }})" class="prop-input w-full">
                </div>
                @endif
            </div>
        </div>

        {{-- One banner per conflicting group — §0.4, must be resolved before completion. --}}
        <template x-for="discrepancy in (currentInspection({{ $sectionJs }}).discrepancies || []).filter(d => !d.resolved_at)" :key="discrepancy.id">
            <div class="rounded-md px-4 py-3 text-sm space-y-2" style="background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);">
                <div class="font-semibold" style="color:var(--text-primary);"
                     x-text="discrepancy.observations[0]?.item?.label + ': ' + discrepancy.observations.map(o => o.condition).join(' vs ')"></div>
                <div class="flex flex-wrap items-center gap-3">
                    <template x-for="obs in discrepancy.observations" :key="obs.id">
                        <label class="flex items-center gap-1.5 text-xs" style="color:var(--text-secondary);">
                            <input type="radio" :name="'accept_' + discrepancy.id" :value="obs.id" x-model.number="discField(discrepancy.id).accepted_observation_id">
                            <span x-text="obs.condition + (obs.notes ? ' — ' + obs.notes : '')"></span>
                        </label>
                    </template>
                    <button type="button" :disabled="discBusy[discrepancy.id] || !discField(discrepancy.id).accepted_observation_id"
                            @click="resolveDiscrepancy({{ $sectionJs }}, discrepancy)"
                            class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--ds-crimson);"
                            x-text="discBusy[discrepancy.id] ? 'Resolving…' : 'Resolve'"></button>
                </div>
            </div>
        </template>

        {{-- Real empty state (BUILD_STANDARD §1a) — 2026-09-21, Johan: a
             started inspection with zero items just showed "Ready to sign"
             with nothing above it, which read as the button doing nothing.
             Point back at where items are actually added rather than
             rendering silently. --}}
        <p x-show="!activeItems().length" class="text-xs" style="color:var(--text-muted);">
            This property has no inspection items yet. Add rooms and meters under
            Inspection Items above, then come back here to record them.
        </p>

        {{-- Item 5/7, 2026-09-22 — overall progress + the whole-inspection
             "All Good" bulk-fill, sitting above the room list so it's the
             first thing seen once there's something to record. --}}
        <div x-show="activeItems().length" class="flex items-center justify-between gap-2">
            <span class="text-xs font-semibold uppercase tracking-wide" style="color:var(--text-muted);"
                  x-text="inspectionProgress({{ $sectionJs }}).recorded + '/' + inspectionProgress({{ $sectionJs }}).total + ' recorded'"></span>
            <button type="button" :disabled="markAllGoodBusy" @click="markAllGood({{ $sectionJs }})"
                    class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);"
                    x-text="markAllGoodBusy ? 'Marking…' : 'All Good — whole inspection'"></button>
        </div>

        {{-- 2026-09-21, Johan on property 5792 — same room-heading grouping
             as the Inspection Items panel above, applied here so a walkthrough
             actually walks room by room instead of a flat list scattered by
             creation order. Keyed on group.room.id (roomGroups()), never on
             the room's free-text label. Item 7, 2026-09-22 — heading shows
             recorded/total + photo count and collapses once the room is
             fully recorded (click the heading to expand/collapse any time). --}}
        <template x-for="group in roomGroups()" :key="group.room ? 'room-' + group.room.id : 'general'">
            <div class="space-y-1 pt-2">
                <div class="flex items-center justify-between gap-2">
                    <button type="button" class="flex items-center gap-1.5 text-left" @click="toggleRoomOpen({{ $sectionJs }}, group)">
                        <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"
                             :style="'color:var(--text-muted); transition:transform .15s; transform:rotate(' + (isRoomOpen({{ $sectionJs }}, group) ? 90 : 0) + 'deg);'">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                        </svg>
                        <h4 class="text-xs font-bold uppercase tracking-wide" style="color:var(--text-secondary);"
                            x-text="(group.room ? group.room.label : 'General') + ' — ' + roomProgress({{ $sectionJs }}, group).recorded + '/' + roomProgress({{ $sectionJs }}, group).total
                                    + (roomProgress({{ $sectionJs }}, group).photos ? ' · ' + roomProgress({{ $sectionJs }}, group).photos + ' photo' + (roomProgress({{ $sectionJs }}, group).photos === 1 ? '' : 's') : '')"></h4>
                    </button>
                    <div class="flex items-center gap-1">
                        <button type="button" x-show="group.room" :disabled="markGoodBusy[group.room?.id]"
                                @click="markRoomGood({{ $sectionJs }}, group.room)"
                                class="text-xs font-semibold px-2 py-1 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);"
                                x-text="markGoodBusy[group.room?.id] ? 'Marking…' : 'All Good'"></button>
                        {{-- §17, Johan on Retha's real paper form: "she
                             strikes ENTIRE ROOMS out with one big N/A." Only
                             offered when N/A is actually one of the agency's
                             configured condition states. --}}
                        <button type="button" x-show="group.room && hasNaConditionState()" :disabled="markNaBusy[group.room?.id]"
                                @click="markRoomNa({{ $sectionJs }}, group.room)"
                                class="text-xs font-semibold px-2 py-1 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);"
                                x-text="markNaBusy[group.room?.id] ? 'Marking…' : 'Mark room N/A'"></button>
                    </div>
                </div>

                <div x-show="isRoomOpen({{ $sectionJs }}, group)" x-collapse class="space-y-1">
                    <template x-for="item in group.items" :key="item.id">
                        <div class="py-2 pl-3 space-y-1.5" style="border-bottom:1px solid var(--border);">
                            <span class="text-sm" style="color:var(--text-primary);" x-text="item.label"></span>
                            <div class="flex flex-wrap items-center gap-2">
                                {{-- Item 3, 2026-09-22 — one tap, agency's
                                     own condition vocabulary
                                     (RentalInspectionSetting::
                                     conditionStatesFor()), rendered
                                     left-to-right in the agency's configured
                                     order. Item 4 — no separate condition
                                     text anywhere else; the selected button
                                     itself is the only place the current
                                     condition is shown. --}}
                                <template x-for="state in conditionStates" :key="state.key">
                                    <button type="button"
                                            :disabled="obsBusy[_obsKey({{ $sectionJs }}, item.id)]"
                                            @click="onConditionTap({{ $sectionJs }}, item, state.key)"
                                            class="text-xs font-semibold px-2.5 py-1.5 rounded-md"
                                            :style="selectedConditionFor({{ $sectionJs }}, item) === state.key
                                                ? 'background:var(--brand-button,#0ea5e9); color:#fff;'
                                                : 'background:var(--surface-2); color:var(--text-secondary);'"
                                            x-text="state.label"></button>
                                </template>
                                <input type="text"
                                       x-show="selectedConditionFor({{ $sectionJs }}, item) && conditionRequiresNotes(selectedConditionFor({{ $sectionJs }}, item))"
                                       x-model="obsField({{ $sectionJs }}, item.id).notes"
                                       @input="onNotesInput({{ $sectionJs }}, item)"
                                       placeholder="Notes (required)"
                                       class="prop-input flex-1" style="min-width:10rem;">

                                {{-- Item 6, 2026-09-22 — camera control only
                                     when the item has no photo yet; a
                                     thumbnail + count (clickable) once it
                                     does, with a compact "+" to add more. --}}
                                <template x-if="!itemPhotosFor({{ $sectionJs }}, item).length">
                                    <label class="text-xs font-semibold px-2.5 py-1.5 rounded-md cursor-pointer inline-flex items-center"
                                           :style="obsField({{ $sectionJs }}, item.id).photo
                                                ? 'background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 20%, transparent); color:var(--brand-icon,#0ea5e9);'
                                                : 'background:var(--surface-2); color:var(--text-secondary);'"
                                           :title="obsField({{ $sectionJs }}, item.id).photo ? 'Photo attached — saves once this item is recorded' : 'Add photo'">
                                        <span>&#128247;</span>
                                        <input type="file" accept="image/*" class="hidden"
                                               @change="onPhotoSelected({{ $sectionJs }}, item, $event.target.files[0] || null); $event.target.value = null;">
                                    </label>
                                </template>
                                <template x-if="itemPhotosFor({{ $sectionJs }}, item).length">
                                    <div class="flex items-center gap-1">
                                        <button type="button" @click="openItemPhotos({{ $sectionJs }}, item)" title="View photos">
                                            <img :src="itemPhotosFor({{ $sectionJs }}, item).slice(-1)[0].storage_path" class="rounded-md object-cover" style="width:2rem; height:2rem;" alt="">
                                        </button>
                                        <span class="text-xs" style="color:var(--text-muted);" x-text="itemPhotosFor({{ $sectionJs }}, item).length"></span>
                                        <label class="text-xs font-semibold px-1.5 py-1 rounded-md cursor-pointer" style="background:var(--surface-2); color:var(--text-secondary);" title="Add another photo">
                                            <span>&#43;</span>
                                            <input type="file" accept="image/*" class="hidden"
                                                   @change="onPhotoSelected({{ $sectionJs }}, item, $event.target.files[0] || null); $event.target.value = null;">
                                        </label>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>

                    {{-- §17, Johan 2026-09-21, from Retha's real paper form:
                         one free-text notes box per room, holding evidence
                         that belongs to the whole room, not any single item —
                         "3x nails in wall", "damp under windows in corner".
                         In addition to per-item notes above, not instead.
                         Item 2, 2026-09-22 — autosaves; no Save button. --}}
                    <template x-if="group.room">
                        <div class="pl-3 pt-1" x-init="roomNoteField({{ $sectionJs }}, group.room.id)">
                            <textarea x-model="roomNoteDraft[{{ $sectionJs }} + '_' + group.room.id]"
                                      @input="autosaveRoomNote({{ $sectionJs }}, group.room)"
                                      placeholder="Room notes (e.g. 3x nails in wall, damp under windows in corner)"
                                      rows="2" class="prop-input w-full text-xs"></textarea>
                        </div>
                    </template>
                </div>
            </div>
        </template>

        {{-- §17, Johan 2026-09-21, from Retha's real paper form: a single
             free-text summary for the whole inspection, at the foot — hers
             reads "OVERALL - APARTMENT CLEAN - FAIR - PARTIALLY FURNISHED".
             Item 2, 2026-09-22 — autosaves; no Save button. --}}
        <div class="pt-2 space-y-1" style="border-top:1px solid var(--border);">
            <label class="block text-xs font-bold uppercase tracking-wide" style="color:var(--text-secondary);">Overall notes</label>
            <textarea x-model="currentInspection({{ $sectionJs }}).overall_notes" rows="2"
                      @input="autosaveOverallNotes({{ $sectionJs }})"
                      placeholder="e.g. Apartment clean, fair condition, partially furnished"
                      class="prop-input w-full text-xs"></textarea>
        </div>

        <div x-show="lifecycleError" x-cloak class="text-xs" style="color:#ef4444;" x-text="lifecycleError"></div>

        {{-- §15 (2026-09-20) — one shared signing block for both sections:
             per-tenant rows, the landlord row (if Property::
             sellerOwnerContact() resolves one — §15.4), then the agent's
             own signature, which only becomes available once every other
             required party already has a disposition — signed OR refused
             (§15.2a). Refusal (Stage 4) is a first-class, equally-weighted
             outcome, never styled as an error or a problem (Johan: "none of
             these should feel like [an error state] in the UI") — tenant
             signs, landlord refuses is a normal, complete outcome. The
             agent alone has no refusal option. markCompleted() does not
             yet require any of this on either type (Stage 5) — Complete
             still works unsigned/undispositioned in the meantime. --}}
        <template x-if="currentInspection({{ $sectionJs }}).status !== 'awaiting_signature'">
            <div class="flex justify-end pt-1">
                <button type="button" :disabled="hasUnresolvedDiscrepancy({{ $sectionJs }})" @click="startAwaitingSignature({{ $sectionJs }})"
                        class="px-4 py-2 rounded-md text-sm font-semibold"
                        :style="hasUnresolvedDiscrepancy({{ $sectionJs }}) ? 'background:var(--surface-2); color:var(--text-muted);' : 'background:var(--brand-button,#0ea5e9); color:#fff;'">
                    Ready to sign
                </button>
            </div>
        </template>

        <template x-if="currentInspection({{ $sectionJs }}).status === 'awaiting_signature'">
            <div class="space-y-2 pt-1" style="border-top:1px solid var(--border);">
                <template x-for="tenant in inspectionTenants({{ $sectionJs }})" :key="tenant.contact_id">
                    <div class="py-1.5" style="border-bottom:1px solid var(--border);">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm" x-text="tenantName(tenant)" style="color:var(--text-primary);"></span>
                            <template x-if="tenantDisposition({{ $sectionJs }}, tenant.contact_id)">
                                <span class="flex items-center gap-2">
                                    <span class="text-xs font-semibold uppercase tracking-wide" style="color:var(--text-muted);"
                                          x-text="dispositionLabel(tenantDisposition({{ $sectionJs }}, tenant.contact_id))"></span>
                                    <button type="button" x-show="canReplaceWetInk({{ $sectionJs }}, tenantDisposition({{ $sectionJs }}, tenant.contact_id))"
                                            @click="openWetInkFor({{ $sectionJs }} + '_tenant_' + tenant.contact_id)"
                                            class="text-xs font-medium underline" style="color:var(--text-secondary);">Replace</button>
                                </span>
                            </template>
                            <template x-if="!tenantDisposition({{ $sectionJs }}, tenant.contact_id)">
                                <div class="flex items-center gap-2">
                                    <button type="button" @click="openSigningFor({{ $sectionJs }} + '_tenant_' + tenant.contact_id)"
                                            class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Sign</button>
                                    <button type="button" @click="openWetInkFor({{ $sectionJs }} + '_tenant_' + tenant.contact_id)"
                                            class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Wet ink</button>
                                    <button type="button" @click="openRefusalFor({{ $sectionJs }} + '_tenant_' + tenant.contact_id)"
                                            class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Refuses</button>
                                </div>
                            </template>
                        </div>
                        <template x-if="activeSigningKey === ({{ $sectionJs }} + '_tenant_' + tenant.contact_id)">
                            <div class="space-y-2 pt-2">
                                <canvas x-init="$nextTick(() => initSignaturePadFor({{ $sectionJs }} + '_tenant_' + tenant.contact_id, $el))"
                                        class="w-full block rounded-md" style="height:110px; touch-action:none; cursor:crosshair; background:#fff; border:1px solid var(--border);"></canvas>
                                <div class="flex items-center gap-2">
                                    <button type="button" @click="clearSignatureFor({{ $sectionJs }} + '_tenant_' + tenant.contact_id)" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Clear</button>
                                    <button type="button" @click="saveTenantSignatureFor({{ $sectionJs }}, tenant)" class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Save signature</button>
                                </div>
                            </div>
                        </template>
                        @include('corex.properties.partials.rental-inspection-refusal-form', ['key' => "({$sectionJs} + '_tenant_' + tenant.contact_id)", 'saveMethod' => "saveTenantRefusalFor({$sectionJs}, tenant)"])
                        @include('corex.properties.partials.rental-inspection-wetink-form', ['key' => "({$sectionJs} + '_tenant_' + tenant.contact_id)", 'saveMethod' => "saveTenantWetInkFor({$sectionJs}, tenant)"])
                    </div>
                </template>

                {{-- §15.4 — landlord, property-level. Plain "nothing to sign"
                     line when unresolvable, never hidden and never blocking. --}}
                <div class="py-1.5" style="border-bottom:1px solid var(--border);">
                    <template x-if="!landlordContact">
                        <span class="text-xs" style="color:var(--text-muted);">Landlord: not linked to this property — nothing to sign.</span>
                    </template>
                    <template x-if="landlordContact">
                        <div>
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm" style="color:var(--text-primary);" x-text="landlordContact.first_name + ' ' + landlordContact.last_name + ' (Landlord)'"></span>
                                <template x-if="landlordDisposition({{ $sectionJs }})">
                                    <span class="flex items-center gap-2">
                                        <span class="text-xs font-semibold uppercase tracking-wide" style="color:var(--text-muted);"
                                              x-text="dispositionLabel(landlordDisposition({{ $sectionJs }}))"></span>
                                        <button type="button" x-show="canReplaceWetInk({{ $sectionJs }}, landlordDisposition({{ $sectionJs }}))"
                                                @click="openWetInkFor({{ $sectionJs }} + '_landlord')"
                                                class="text-xs font-medium underline" style="color:var(--text-secondary);">Replace</button>
                                    </span>
                                </template>
                                <template x-if="!landlordDisposition({{ $sectionJs }})">
                                    <div class="flex items-center gap-2">
                                        <button type="button" @click="openSigningFor({{ $sectionJs }} + '_landlord')"
                                                class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Sign</button>
                                        <button type="button" @click="openWetInkFor({{ $sectionJs }} + '_landlord')"
                                                class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Wet ink</button>
                                        <button type="button" @click="openRefusalFor({{ $sectionJs }} + '_landlord')"
                                                class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Refuses</button>
                                    </div>
                                </template>
                            </div>
                            <template x-if="activeSigningKey === ({{ $sectionJs }} + '_landlord')">
                                <div class="space-y-2 pt-2">
                                    <canvas x-init="$nextTick(() => initSignaturePadFor({{ $sectionJs }} + '_landlord', $el))"
                                            class="w-full block rounded-md" style="height:110px; touch-action:none; cursor:crosshair; background:#fff; border:1px solid var(--border);"></canvas>
                                    <div class="flex items-center gap-2">
                                        <button type="button" @click="clearSignatureFor({{ $sectionJs }} + '_landlord')" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Clear</button>
                                        <button type="button" @click="saveLandlordSignatureFor({{ $sectionJs }})" class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Save signature</button>
                                    </div>
                                </div>
                            </template>
                            @include('corex.properties.partials.rental-inspection-refusal-form', ['key' => "({$sectionJs} + '_landlord')", 'saveMethod' => "saveLandlordRefusalFor({$sectionJs})"])
                            @include('corex.properties.partials.rental-inspection-wetink-form', ['key' => "({$sectionJs} + '_landlord')", 'saveMethod' => "saveLandlordWetInkFor({$sectionJs})"])
                        </div>
                    </template>
                </div>

                <div class="py-1.5">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm font-semibold" style="color:var(--text-primary);">Agent</span>
                        <template x-if="agentDisposition({{ $sectionJs }})">
                            <span class="text-xs font-semibold uppercase tracking-wide" style="color:var(--text-muted);">Signed</span>
                        </template>
                        <template x-if="!agentDisposition({{ $sectionJs }}) && allRequiredPartiesDispositioned({{ $sectionJs }})">
                            <button type="button" @click="openSigningFor({{ $sectionJs }} + '_agent')"
                                    class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Sign</button>
                        </template>
                    </div>
                    <template x-if="activeSigningKey === ({{ $sectionJs }} + '_agent')">
                        <div class="space-y-2 pt-2">
                            <canvas x-init="$nextTick(() => initSignaturePadFor({{ $sectionJs }} + '_agent', $el))"
                                    class="w-full block rounded-md" style="height:110px; touch-action:none; cursor:crosshair; background:#fff; border:1px solid var(--border);"></canvas>
                            <div class="flex items-center gap-2">
                                <button type="button" @click="clearSignatureFor({{ $sectionJs }} + '_agent')" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Clear</button>
                                <button type="button" @click="saveAgentSignatureFor({{ $sectionJs }})" class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Save signature</button>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="flex justify-end pt-1">
                    <button type="button" :disabled="hasUnresolvedDiscrepancy({{ $sectionJs }})" @click="completeInspection({{ $sectionJs }})"
                            class="px-4 py-2 rounded-md text-sm font-semibold"
                            :style="hasUnresolvedDiscrepancy({{ $sectionJs }}) ? 'background:var(--surface-2); color:var(--text-muted);' : 'background:var(--brand-button,#0ea5e9); color:#fff;'">
                        Complete
                    </button>
                </div>
            </div>
        </template>
    </div>
</template>
