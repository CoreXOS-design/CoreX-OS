{{--
    Rental inspection recording — shared by In Inspection and Out Inspection.
    Rendered inside the `rentalImages()` Alpine component.
    Spec: .ai/specs/rental-inspections.md §4/§14

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

        <template x-for="item in activeItems()" :key="item.id">
            <div class="py-2 space-y-1.5" style="border-bottom:1px solid var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-sm" style="color:var(--text-primary);" x-text="item.label"></span>
                    <span x-show="conditionFor({{ $sectionJs }}, item.id)" class="text-xs uppercase tracking-wide"
                          style="color:var(--text-muted);" x-text="conditionFor({{ $sectionJs }}, item.id)?.condition"></span>
                </div>
                <div x-show="obsError[_obsKey({{ $sectionJs }}, item.id)]" x-cloak class="text-xs" style="color:#ef4444;"
                     x-text="obsError[_obsKey({{ $sectionJs }}, item.id)]"></div>
                <div class="flex flex-wrap items-center gap-2">
                    <select x-model="obsField({{ $sectionJs }}, item.id).condition" class="prop-input" style="max-width:9rem;">
                        <option value="">Record…</option>
                        <option value="good">Good</option>
                        <option value="fair">Fair</option>
                        <option value="damaged">Damaged</option>
                        <option value="not_working">Not working</option>
                        <option value="missing">Missing</option>
                        <option value="other">Other</option>
                    </select>
                    <input type="text" x-show="obsField({{ $sectionJs }}, item.id).condition && obsField({{ $sectionJs }}, item.id).condition !== 'good'"
                           x-model="obsField({{ $sectionJs }}, item.id).notes" placeholder="Notes (required)"
                           class="prop-input flex-1" style="min-width:10rem;">
                    <label class="text-xs font-semibold px-3 py-2 rounded-md cursor-pointer" style="background:var(--surface-2); color:var(--text-secondary);">
                        <span x-text="obsField({{ $sectionJs }}, item.id).photo ? obsField({{ $sectionJs }}, item.id).photo.name : 'Photo'"></span>
                        <input type="file" accept="image/*" class="hidden"
                               @change="obsField({{ $sectionJs }}, item.id).photo = $event.target.files[0] || null">
                    </label>
                    <button type="button" :disabled="obsBusy[_obsKey({{ $sectionJs }}, item.id)] || !obsField({{ $sectionJs }}, item.id).condition"
                            @click="recordObservation({{ $sectionJs }}, item)"
                            class="px-3 py-2 rounded-md text-xs font-semibold text-white" style="background:var(--brand-button,#0ea5e9);"
                            x-text="obsBusy[_obsKey({{ $sectionJs }}, item.id)] ? 'Saving…' : 'Save'"></button>
                </div>
            </div>
        </template>

        <div x-show="lifecycleError" x-cloak class="text-xs" style="color:#ef4444;" x-text="lifecycleError"></div>

        {{-- §15 (2026-09-20), Stages 2-3 — one shared signing block for
             both sections: per-tenant rows, the landlord row (if
             Property::sellerOwnerContact() resolves one — §15.4), then the
             agent's own signature, which only becomes available once every
             other required party already has a disposition (§15.2a). No
             refusal option yet (Stage 4) — markCompleted() does not yet
             require any of this on either type (Stage 5), so Complete
             still works unsigned in the meantime; this only ADDS the
             ability to sign, ready for Stage 5 to start requiring it. --}}
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
                                <span class="text-xs font-semibold uppercase tracking-wide" style="color:var(--text-muted);">Signed</span>
                            </template>
                            <template x-if="!tenantDisposition({{ $sectionJs }}, tenant.contact_id)">
                                <button type="button" @click="openSigningFor({{ $sectionJs }} + '_tenant_' + tenant.contact_id)"
                                        class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Sign</button>
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
                                    <span class="text-xs font-semibold uppercase tracking-wide" style="color:var(--text-muted);">Signed</span>
                                </template>
                                <template x-if="!landlordDisposition({{ $sectionJs }})">
                                    <button type="button" @click="openSigningFor({{ $sectionJs }} + '_landlord')"
                                            class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Sign</button>
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
