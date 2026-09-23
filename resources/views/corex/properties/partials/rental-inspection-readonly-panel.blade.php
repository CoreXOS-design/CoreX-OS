{{--
    Johan's ruling, 2026-09-23 — the LEFT, read-only side of the chain's
    predecessor/current pair. "That is the whole point of the change:
    Johan's objection was that the out inspection sits BELOW the in
    inspection as a separate section... Make them sit side by side."

    Deliberately a SEPARATE, lean partial from rental-inspection-recording.
    blade.php — not a read-only mode bolted onto that file. This panel has
    no autosave, no photo upload, no condition-tap grid, no tagging
    destinations — none of the machinery that took four rounds of surgery
    to get right on the editable side, so there is nothing here that can
    regress it. Read-only display only: whatever the given inspection
    already has recorded, nothing more.

    Photos here are plain, fixed-size thumbnails — no viewer, no flip, no
    match. That interactive photo-comparison behaviour is cc2's own
    territory (comparePhotoUploader()/compareLeft/compareRight, the
    GROUP-based match model they're building on cc2-photo-comparison-
    viewer-2026-09-23) — this panel does not build a second one. See this
    file's own docblock note further down for the exact seam.

    Required include var:
      $inspectionJs — a raw JS expression (NOT a quoted string) naming
                 which reactive property on the parent Alpine component to
                 read — 'chainPredecessor' for this panel's normal use, or
                 'chainTail' for the one case the tail itself is shown
                 read-only (already completed, nothing left to record
                 without pressing "Next inspection" first — see the
                 unified section's own markup in show.blade.php).

    Aligned row by row with the editable panel beside it: both iterate
    the SAME roomGroups() in the SAME order (items belong to the
    property, not to any one inspection — §20.15.4's own "alignment is
    automatic" reasoning applies here identically), so item N here is
    always item N on the editable side, never re-sorted or filtered
    differently.
--}}
<template x-if="!{{ $inspectionJs }}">
    <div class="rounded-md p-4 flex items-center justify-center" style="background:var(--surface-2); border:1px dashed var(--border); min-height:6rem;">
        <p class="text-xs" style="color:var(--text-muted);">First inspection in this chain — nothing yet to compare against.</p>
    </div>
</template>
<template x-if="{{ $inspectionJs }}">
    <div class="space-y-3">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color:var(--text-secondary);"
             x-text="({{ $inspectionJs }}.type === 'out' ? 'Out' : ({{ $inspectionJs }}.type === 'in' ? 'In' : 'Routine')) + '-inspection' + ({{ $inspectionJs }}.scheduled_for ? ' · ' + {{ $inspectionJs }}.scheduled_for : '')"></div>

        <template x-for="group in roomGroups()" :key="'ro-' + ({{ $inspectionJs }}.id) + '-' + (group.room ? 'room-' + group.room.id : 'general')">
            <div class="space-y-1.5 pt-2" style="border-top:1px solid var(--border);">
                <h4 class="text-xs font-bold uppercase tracking-wide" style="color:var(--text-secondary);" x-text="group.room ? group.room.label : 'General'"></h4>

                {{-- Room-level general photos — plain fixed-size thumbnails,
                     no scroller/viewer, no elastic sizing (deliberately not
                     the .rir-room-photo-tile mechanism — that class belongs
                     to the editable panel's own interactive grid; this is a
                     simpler, always-explicit-both-dimensions <img>, the
                     safest possible shape given tonight's whole :style/
                     sizing history in this file). --}}
                <template x-if="group.room && roomPhotosForInspection({{ $inspectionJs }}, group.room.id).length">
                    <div class="flex flex-wrap gap-1">
                        <template x-for="photo in roomPhotosForInspection({{ $inspectionJs }}, group.room.id)" :key="'ro-room-photo-' + photo.id">
                            <img :src="photo.storage_path" alt="" class="rounded" style="width:56px; height:56px; object-fit:cover; display:block;">
                        </template>
                    </div>
                </template>

                <template x-for="item in group.items" :key="'ro-item-' + item.id">
                    <div class="py-1.5" style="border-top:1px dashed var(--border);">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs" style="color:var(--text-primary);" x-text="item.label"></span>
                            <template x-if="conditionForInspection({{ $inspectionJs }}, item.id)">
                                <span class="ds-badge"
                                      :style="conditionForInspection({{ $inspectionJs }}, item.id).condition === 'good' ? 'background:var(--ds-green,#16a34a); color:#fff;' : 'background:var(--ds-crimson,#dc2626); color:#fff;'"
                                      x-text="conditionForInspection({{ $inspectionJs }}, item.id).condition.charAt(0).toUpperCase() + conditionForInspection({{ $inspectionJs }}, item.id).condition.slice(1)"></span>
                            </template>
                            <template x-if="!conditionForInspection({{ $inspectionJs }}, item.id)">
                                <span class="text-xs" style="color:var(--text-muted);">Not recorded</span>
                            </template>
                        </div>
                        <template x-if="conditionForInspection({{ $inspectionJs }}, item.id)?.notes">
                            <p class="text-xs mt-0.5" style="color:var(--text-muted);" x-text="conditionForInspection({{ $inspectionJs }}, item.id).notes"></p>
                        </template>
                        <template x-if="(conditionForInspection({{ $inspectionJs }}, item.id)?.photos || []).length">
                            <div class="flex flex-wrap gap-1 mt-1">
                                <template x-for="photo in (conditionForInspection({{ $inspectionJs }}, item.id)?.photos || [])" :key="'ro-item-photo-' + photo.id">
                                    <img :src="photo.storage_path" alt="" class="rounded" style="width:56px; height:56px; object-fit:cover; display:block;">
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </template>
    </div>
</template>
