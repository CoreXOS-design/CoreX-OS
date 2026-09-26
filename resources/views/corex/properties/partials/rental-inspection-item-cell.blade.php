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
            {{-- AT-433 Part A, 2026-09-26 — photo strip. Tiles come from
                 stripTilesForInspection()/stripTilesFor() in show.blade.php,
                 each { index, photo }: photo null means "shorter side"
                 (renders the NO MATCH placeholder below), and both cells
                 read the SAME shared count (stripPairCount()) so slot N is
                 always slot N on both sides — the point of this screen.
                 Pairing is POSITIONAL ONLY right now (tile.index is plain
                 array position, not a real photo-match id) — see the
                 stripPairCount() docblock in show.blade.php for exactly
                 where Part B's real pair id replaces it, including the two
                 :key bindings and the two x-text badges below. --}}
            <div class="rir-strip-row">
{{-- FIX, 2026-09-26 — the first draft of this block nested two sibling
     <template x-if> tags (photo / NO MATCH) inside this <template x-for>.
     x-for requires exactly ONE root element per iteration to clone, same
     as x-if's own single-root rule above it — two sibling <template>
     children broke that and rendered nothing. Fixed to one root
     <div>/<button> per iteration, with x-show (not nested x-if) toggling
     the photo-vs-placeholder content inside it. --}}
@if($readOnly)
                {{-- §24.6, AT-433 Part B — the predecessor tile is a drop
                     target ONLY while a pairing drag is in progress (Johan's
                     ruling: "no persistent affordance, no hover state,
                     nothing on that cell when the agent is not dragging"),
                     styled entirely within this tile's own stacking context
                     — never a new absolutely-positioned sibling of
                     .rir-strip-row (the exact overlap bug class the strip
                     was just rebuilt to remove, see that class's own
                     docblock in rental-inspection-recording.blade.php).
                     pairDragOverTile()/pairDropOnPredecessor() never call
                     preventDefault() unless pairDragActive is true, so an
                     unrelated drag (a desktop file, say) over this exact
                     tile behaves exactly as before this feature existed —
                     not this round's concern (Johan, 2026-09-26). --}}
                <template x-for="tile in stripTilesForInspection({{ $inspectionJs }}, item).slice(0, stripVisibleCount(item))" :key="tile.index">
                    <div class="relative rounded-md rir-strip-tile"
                         :class="[tile.photo ? '' : 'rir-strip-nomatch', (pairDragActive && tile.photo) ? 'rir-strip-pair-eligible' : '', (pairDragActive && tile.photo && pairDragOverId === tile.photo.id) ? 'rir-strip-pair-over' : '']"
                         @dragover="pairDragOverTile($event, tile.photo)"
                         @dragleave="pairDragOverId = null"
                         @drop="pairDropOnPredecessor($event, tile.photo)">
                        <span class="rir-strip-badge" x-text="tile.index + 1"></span>
                        {{-- openCompareViewer(photo, insp) — on this
                             (readOnly) branch $inspectionJs IS the
                             inspection object already (chainPredecessor
                             by default, see this file's own docblock),
                             so it is passed straight through as insp. --}}
                        <img x-show="tile.photo" :src="tile.photo ? tile.photo.storage_path : ''"
                             style="display:block; width:100%; height:100%; object-fit:cover; cursor:pointer;"
                             @click="tile.photo && openCompareViewer(tile.photo, {{ $inspectionJs }})" alt="">
                        <span class="rir-strip-nomatch-label" x-show="!tile.photo">NO MATCH</span>
                    </div>
                </template>
@else
                <template x-for="tile in stripTilesFor({{ $inspectionJs }}, item).slice(0, stripVisibleCount(item))" :key="tile.index">
                    {{-- §24.6, AT-433 Part B — drag this (current-inspection)
                         photo onto its predecessor-side counterpart. Johan's
                         own words: "drag it left onto the photo it
                         matches" — this tile is the only ever DRAG SOURCE;
                         the read-only cell above is the only ever DROP
                         TARGET, never the reverse. Reuses
                         photoUploader().dragStartSelection() exactly as the
                         untagged tray already does for its own
                         drag-onto-a-room gesture — see
                         photoDraggedForPairing()'s own docblock in
                         show.blade.php for why this is not a second drag
                         mechanism. --}}
                    <div class="relative rounded-md rir-strip-tile" :class="tile.photo ? '' : 'rir-strip-nomatch'"
                         :style="tile.photo && photoUploader({{ $inspectionJs }}).isSelected(tile.photo.id) ? 'outline:2px solid var(--brand-icon,#0ea5e9);' : ''"
                         :draggable="!!tile.photo"
                         @dragstart="tile.photo && photoDraggedForPairing({{ $inspectionJs }}, tile.photo.id, $event)"
                         @dragend="photoDragEndForPairing()">
                        <span class="rir-strip-badge" x-text="tile.index + 1"></span>
                        {{-- openCompareViewer(photo, insp) — on THIS
                             (live) branch $inspectionJs is a section-type
                             expression ('tailSection()' per this file's
                             own docblock), not an inspection object, so it
                             cannot be passed as insp here. This branch
                             only ever renders the chain's tail, so
                             chainTail (the same root-level property cc2's
                             own implementation already reads via
                             this.chainTail) is the correct inspection
                             object. --}}
                        <img x-show="tile.photo" :src="tile.photo ? tile.photo.storage_path : ''"
                             style="display:block; width:100%; height:100%; object-fit:cover; cursor:pointer;"
                             @click="tile.photo && openCompareViewer(tile.photo, chainTail)" alt="">
                        <span class="rir-strip-nomatch-label" x-show="!tile.photo">NO MATCH</span>
                        <button type="button" x-show="tile.photo" @click.stop="photoUploader({{ $inspectionJs }}).toggleSelected(tile.photo.id)"
                                class="absolute bottom-0.5 left-0.5 w-4 h-4 rounded-full flex items-center justify-center text-xs font-bold"
                                :style="tile.photo && photoUploader({{ $inspectionJs }}).isSelected(tile.photo.id) ? 'background:var(--brand-icon,#0ea5e9); color:#fff;' : 'background:rgba(0,0,0,0.5); color:#fff;'"
                                title="Select">&check;</button>
                        <button type="button" x-show="tile.photo && group.room" @click.stop="photoUploader({{ $inspectionJs }}).tagPhoto(tile.photo.id, { property_room_id: group.room?.id })"
                                class="absolute top-0.5 right-0.5 w-4 h-4 rounded-full flex items-center justify-center text-xs font-bold"
                                style="background:rgba(0,0,0,0.65); color:#fff; line-height:1;" title="Back to room">&uarr;</button>
                        <button type="button" x-show="tile.photo" @click.stop="photoUploader({{ $inspectionJs }}).untagPhoto(tile.photo.id)"
                                class="absolute bottom-0.5 right-0.5 w-4 h-4 rounded-full flex items-center justify-center text-xs font-bold"
                                style="background:rgba(0,0,0,0.65); color:#fff; line-height:1;" title="Back to untagged">&#8657;</button>
                    </div>
                </template>
@endif
                {{-- Item 4, AT-433 Part A — beyond 4 slots, collapse the
                     rest behind a count tile; clicking it (or the
                     room-level control) expands every slot for this item
                     on both sides. --}}
                <template x-if="stripMoreCount(item) > 0">
                    <button type="button" class="relative rounded-md rir-strip-tile rir-strip-more" @click="toggleItemStrip(item)"
                            :title="'Show all ' + stripPairCount(item)">
                        <span x-text="'+' + stripMoreCount(item)"></span>
                    </button>
                </template>
@unless($readOnly)
                {{-- Item 2, 2026-09-26 — a photo picked before the item is
                     "recorded" is staged in obsField(...).photos (see
                     onItemPhotosSelected in show.blade.php) and NOT yet
                     uploaded, so it has no server photo id and takes no
                     part in stripPairCount()'s predecessor/tail pairing.
                     Rendered here so picking a file is never invisible —
                     always shown, never collapsed behind "+N". --}}
                <template x-for="(file, idx) in stagedPhotosFor({{ $inspectionJs }}, item)" :key="'staged-' + idx">
                    {{-- Johan's ruling, 2026-09-26 — a staged photo has no
                         server id yet, so it cannot be paired: dragging it
                         still works (the agent has no way to know in
                         advance it will be refused), but
                         pairDropOnPredecessor() in show.blade.php detects
                         the id-less payload and shows the reason visibly
                         rather than silently doing nothing. Passing `null`
                         to dragStartSelection() (same reused function as the
                         real-photo tile above) is what marks the drag this
                         way. --}}
                    <div class="relative rounded-md rir-strip-tile"
                         draggable="true"
                         @dragstart="photoDraggedForPairing({{ $inspectionJs }}, null, $event)"
                         @dragend="photoDragEndForPairing()">
                        <img :src="file._corexPreviewUrl" style="display:block; width:100%; height:100%; object-fit:cover; opacity:0.55;" alt="">
                        <span class="rir-strip-pending-label">PENDING</span>
                    </div>
                </template>
                {{-- Add-tile — the strip's own last flex child now (see
                     .rir-add-tile's own comment in rental-inspection-
                     recording.blade.php for why this moved out of
                     absolute positioning). No `left` to compute here. --}}
                <label class="rounded-md cursor-pointer rir-add-tile"
                       :style="(obsField({{ $inspectionJs }}, item.id).photos || []).length
                            ? 'background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 20%, transparent); color:var(--brand-icon,#0ea5e9);'
                            : 'background:var(--surface-2); color:var(--text-secondary);'"
                       :title="(obsField({{ $inspectionJs }}, item.id).photos || []).length ? 'Photo(s) attached — saves once this item is recorded' : 'Add photo(s)'">
                    <span>&#128247;</span>
                    <input type="file" accept="image/*" multiple class="hidden"
                           @change="onItemPhotosSelected({{ $inspectionJs }}, item, $event.target.files); $event.target.value = null;">
                </label>
@endunless
            </div>
        </div>
    </div>
</div>
