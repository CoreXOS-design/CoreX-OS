{{--
    Rental inspection recording — shared by In Inspection and Out Inspection.
    Rendered inside the `rentalImages()` Alpine component.
    Spec: .ai/specs/rental-inspections.md §4/§14

    Required include var:
      $section — 'in' or 'out' (literal PHP string, used to build the JS
                 string literal below)

    Slice 3 of the tab rebuild — item recording only. Discrepancy banners,
    signature capture, and the complete/start-signing controls are Slice 4,
    landed separately.
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
    <div class="space-y-1">
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
    </div>
</template>
