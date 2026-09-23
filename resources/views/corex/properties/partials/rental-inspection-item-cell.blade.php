{{--
    rental-inspection-item-cell.blade.php — the ONE way an item's
    condition, notes and photos render, shared by BOTH cells of the
    side-by-side comparison grid in rental-inspection-recording.blade.php
    (predecessor/left, tail/right). Johan, 2026-09-23, property 5792:
    "Left renders a condition as a coloured pill on the right of the
    row... Right renders a 7-button grid... Same data, two completely
    different visual languages." This partial is now the only place
    either look exists — both cells render this exact same skeleton.
    Read-only strips interactivity (disabled buttons, no upload/tag
    controls, no autosave) but never changes layout, label positions,
    photo strip position, or tile sizes: "Read-only means disabled
    controls or a static rendering of the same component — it does
    not mean a different component with a different look."

    Required include vars:
      $inspectionJs — a raw JS expression (NOT a quoted string). Its
                 MEANING depends on $readOnly:
                   readOnly = true  → an INSPECTION OBJECT expression
                              ('chainPredecessor') — read directly via
                              conditionForInspection()/
                              itemPhotosForInspection(), which accept
                              any plain inspection object and are
                              null-safe (a null predecessor renders
                              every item's cell as its own empty state,
                              row by row — no separate "first in chain"
                              special-case needed here).
                   readOnly = false → a SECTION-TYPE expression, the
                              SAME value rental-inspection-recording.
                              blade.php's own $sectionJs resolves to
                              ('tailSection()' in the chain caller) —
                              read via the existing live/autosave
                              methods (selectedConditionFor(),
                              itemPhotosFor(), photoUploader(), etc.),
                              UNCHANGED from before this partial
                              existed. This is deliberate: the tail
                              cell keeps using the already-proven
                              upload/tag/autosave pipeline rather than
                              a rewritten generic reader, so none of
                              that pipeline's risk surface changes.
      $readOnly     — PHP bool, compile-time. Governs ONLY which JS
                 accessor family a value is read through and whether
                 controls are interactive — never the HTML skeleton.

    Assumes `item` and `group` are in scope from the caller's own
    shared x-for (roomGroups() / group.items) — the SAME iteration
    drives both cells, so item N is always item N on both sides by
    construction, never by luck.
--}}
<div class="flex items-stretch gap-3" style="display:flex; align-items:stretch; min-height:0;">
    <div class="flex-none space-y-1.5" style="flex:none;">
        <div class="grid grid-cols-2 gap-1">
            <template x-for="state in conditionStates" :key="state.key">
@if($readOnly)
                <button type="button" disabled
                        class="text-xs font-semibold px-2.5 py-1.5 rounded-md"
                        :style="(conditionForInspection({{ $inspectionJs }}, item.id)?.condition === state.key)
                            ? 'background:var(--brand-button,#0ea5e9); color:#fff;'
                            : 'background:var(--surface-2); color:var(--text-secondary); opacity:0.5;'"
                        x-text="state.label"></button>
@else
                <button type="button"
                        :disabled="isObsBusy({{ $inspectionJs }}, item.id)"
                        @click="onConditionTap({{ $inspectionJs }}, item, state.key)"
                        class="text-xs font-semibold px-2.5 py-1.5 rounded-md"
                        :style="selectedConditionFor({{ $inspectionJs }}, item) === state.key
                            ? 'background:var(--brand-button,#0ea5e9); color:#fff;'
                            : 'background:var(--surface-2); color:var(--text-secondary);'"
                        x-text="state.label"></button>
@endif
            </template>
        </div>
@if($readOnly)
        <p x-show="conditionForInspection({{ $inspectionJs }}, item.id)?.notes"
           class="text-xs mt-0.5" style="color:var(--text-muted);"
           x-text="conditionForInspection({{ $inspectionJs }}, item.id)?.notes"></p>
@else
        <input type="text"
               x-show="selectedConditionFor({{ $inspectionJs }}, item) && conditionRequiresNotes(selectedConditionFor({{ $inspectionJs }}, item))"
               x-model="obsField({{ $inspectionJs }}, item.id).notes"
               @input="onNotesInput({{ $inspectionJs }}, item)"
               placeholder="Notes (required)"
               class="prop-input w-full">
@endif
    </div>

    <div style="display:flex; align-items:stretch; flex:1; min-width:0;">
        <div style="display:block; flex:1; align-self:stretch; min-width:0; min-height:0; position:relative;">
            <div style="position:absolute; top:0; left:0; right:0; bottom:0; height:100%; overflow-x:auto; overflow-y:hidden; white-space:nowrap; font-size:0;">
@if($readOnly)
                <template x-for="photo in itemPhotosForInspection({{ $inspectionJs }}, item.id)" :key="photo.id">
                    <div class="relative rounded-md rir-item-photo-tile">
                        {{-- Johan, 2026-09-23 — the standalone Compare
                             section is being removed (cc2); comparison now
                             lives entirely in cc2's photo-comparison modal,
                             opened the SAME way from either cell. No
                             separate single-photo viewer here — clicking
                             either side's thumbnail opens the one shared
                             comparison modal for this item (cc2 owns
                             openCompareViewer() and everything it reads,
                             untouched by this file). --}}
                        <img :src="photo.storage_path" style="display:block; width:100%; height:100%; object-fit:cover; cursor:pointer;"
                             @click="openCompareViewer('item', 'item_' + item.id, null, item)" alt="">
                    </div>
                </template>
@else
                <template x-for="photo in itemPhotosFor({{ $inspectionJs }}, item)" :key="photo.id">
                    <div class="relative rounded-md rir-item-photo-tile"
                         :style="photoUploader({{ $inspectionJs }}).isSelected(photo.id) ? 'outline:2px solid var(--brand-icon,#0ea5e9);' : ''">
                        <img :src="photo.storage_path" style="display:block; width:100%; height:100%; object-fit:cover; cursor:pointer;"
                             @click="openCompareViewer('item', 'item_' + item.id, null, item)" alt="">
                        <button type="button" @click.stop="photoUploader({{ $inspectionJs }}).toggleSelected(photo.id)"
                                class="absolute top-0.5 left-0.5 w-4 h-4 rounded-full flex items-center justify-center text-xs font-bold"
                                :style="photoUploader({{ $inspectionJs }}).isSelected(photo.id) ? 'background:var(--brand-icon,#0ea5e9); color:#fff;' : 'background:rgba(0,0,0,0.5); color:#fff;'"
                                title="Select">&check;</button>
                        <button type="button" x-show="group.room" @click.stop="photoUploader({{ $inspectionJs }}).tagPhoto(photo.id, { property_room_id: group.room?.id })"
                                class="absolute top-0.5 right-0.5 w-4 h-4 rounded-full flex items-center justify-center text-xs font-bold"
                                style="background:rgba(0,0,0,0.65); color:#fff; line-height:1;" title="Back to room">&uarr;</button>
                        <button type="button" @click.stop="photoUploader({{ $inspectionJs }}).untagPhoto(photo.id)"
                                class="absolute bottom-0.5 right-0.5 w-4 h-4 rounded-full flex items-center justify-center text-xs font-bold"
                                style="background:rgba(0,0,0,0.65); color:#fff; line-height:1;" title="Back to untagged">&#8657;</button>
                    </div>
                </template>
@endif
            </div>
@unless($readOnly)
            <label class="rounded-md cursor-pointer rir-add-tile"
                   :style="'left:min(' + (itemPhotosFor({{ $inspectionJs }}, item).length * 171) + 'px, calc(100% - 124px));' + ((obsField({{ $inspectionJs }}, item.id).photos || []).length
                        ? 'background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 20%, transparent); color:var(--brand-icon,#0ea5e9);'
                        : 'background:var(--surface-2); color:var(--text-secondary);')"
                   :title="(obsField({{ $inspectionJs }}, item.id).photos || []).length ? 'Photo(s) attached — saves once this item is recorded' : 'Add photo(s)'">
                <span>&#128247;</span>
                <input type="file" accept="image/*" multiple class="hidden"
                       @change="onItemPhotosSelected({{ $inspectionJs }}, item, $event.target.files); $event.target.value = null;">
            </label>
@endunless
        </div>
    </div>
</div>
