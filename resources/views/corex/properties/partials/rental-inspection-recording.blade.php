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
                {{-- 2026-09-22 fix — reads discrepancy.item directly (the
                     discrepancy's own item() relation) rather than
                     discrepancy.observations[0]?.item, which depended on a
                     nested eager-load path that was never actually loaded
                     and rendered the literal string "undefined" as the
                     label (Johan, property 5792). --}}
                <div class="font-semibold" style="color:var(--text-primary);"
                     x-text="(discrepancy.item?.label || 'Unknown item') + ': ' + discrepancy.observations.map(o => o.condition).join(' vs ')"></div>
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
        {{-- 2026-09-22, Johan (property 5792, regression report): a fully-
             recorded room auto-collapsed with no way to reopen it, and
             "All Good"/"Mark room N/A" sat there doing nothing once every
             item was already recorded — from the screen, both read as
             "nothing here is clickable." Fixed at the class level:
             - the toggle chevron+heading is one clickable row with an
               explicit hover state so it visibly IS a control;
             - "All Good"/"Mark room N/A" disappear once a room has nothing
               left to fill (they had zero effect at that point anyway —
               a control that visibly does nothing is worse than no control);
             - "Expand all" / "Collapse all" gives a reviewing agent one
               action for the whole inspection instead of clicking every
               room; x-collapse is dropped for a plain x-show (the Collapse
               plugin isn't installed in this app — confirmed in
               node_modules/alpinejs/dist/cdn.js, it silently no-ops
               rather than blocking x-show, but it bought nothing here and
               reads as if it should be doing something). --}}
        <div x-show="activeItems().length" class="flex items-center justify-between gap-2">
            <span class="text-xs font-semibold uppercase tracking-wide" style="color:var(--text-muted);"
                  x-text="inspectionProgress({{ $sectionJs }}).recorded + '/' + inspectionProgress({{ $sectionJs }}).total + ' recorded'"></span>
            <div class="flex items-center gap-2">
                <button type="button" @click="toggleAllRooms({{ $sectionJs }})"
                        class="text-xs font-semibold underline" style="color:var(--text-secondary);"
                        x-text="allRoomsOpen({{ $sectionJs }}) ? 'Collapse all' : 'Expand all'"></button>
                <button type="button" x-show="inspectionProgress({{ $sectionJs }}).recorded < inspectionProgress({{ $sectionJs }}).total"
                        :disabled="markAllGoodBusy" @click="markAllGood({{ $sectionJs }})"
                        class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);"
                        x-text="markAllGoodBusy ? 'Marking…' : 'All Good — whole inspection'"></button>
            </div>
        </div>

        {{-- Item 3, 2026-09-22 — bulk upload straight to the whole
             inspection, then tag. Untagged photos land here; the tray's
             own count IS the progress indicator (no separate badge).
             Multi-select: click, shift-click a run, ctrl/cmd-click, or
             drag a marquee over the thumbnails; drop the selection onto a
             room heading above, or use "Tag to room" below — the
             touch/mobile equivalent of the same action, since native
             HTML5 drag-and-drop is unreliable on phones (item 7). --}}
        <div class="rounded-md p-3 space-y-2" style="background:var(--surface-2);" x-show="activeItems().length">
            <div class="flex items-center justify-between gap-2">
                <label class="text-xs font-semibold px-3 py-1.5 rounded-md cursor-pointer inline-flex items-center gap-1" style="background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);">
                    <span>&#128247;</span>
                    <span>Upload photos to this inspection</span>
                    <input type="file" accept="image/*" multiple class="hidden"
                           @change="photoUploader({{ $sectionJs }}).uploadFiles($event.target.files, {}); $event.target.value = null;">
                </label>
                <span x-show="photoUploader({{ $sectionJs }}).untaggedPhotos().length" class="text-xs font-semibold" style="color:var(--text-muted);"
                      x-text="photoUploader({{ $sectionJs }}).untaggedPhotos().length + ' untagged'"></span>
            </div>

            <template x-for="(batch, idx) in photoUploader({{ $sectionJs }}).uploadBatches.filter(b => b.status !== 'done')" :key="idx">
                <div class="flex items-center justify-between gap-2 text-xs px-2 py-1 rounded-md"
                     :style="batch.status === 'failed' ? 'background:color-mix(in srgb, var(--ds-crimson) 10%, transparent);' : 'background:var(--surface);'">
                    <span :style="batch.status === 'failed' ? 'color:var(--ds-crimson);' : 'color:var(--text-secondary);'"
                          x-text="batch.status === 'failed' ? (batch.files.length + ' photo(s) failed — ' + batch.error) : ('Uploading ' + batch.files.length + ' photo(s)… ' + (batch.percent || 0) + '%')"></span>
                    <button type="button" x-show="batch.status === 'failed'" @click="photoUploader({{ $sectionJs }}).retryBatch(batch)"
                            class="text-xs font-semibold underline" style="color:var(--text-secondary);">Retry</button>
                </div>
            </template>

            <template x-if="photoUploader({{ $sectionJs }}).untaggedPhotos().length">
                <div class="space-y-2">
                    <div class="flex items-center gap-2 flex-wrap p-2 rounded-md relative" style="background:var(--surface); min-height:3.5rem;"
                         @mousedown="photoUploader({{ $sectionJs }}).marqueeStart($event, $el)"
                         @mousemove.window="photoUploader({{ $sectionJs }}).marqueeMove($event)"
                         @mouseup.window="photoUploader({{ $sectionJs }}).marqueeEnd()">
                        <template x-for="photo in photoUploader({{ $sectionJs }}).untaggedPhotos()" :key="photo.id">
                            <div :data-photo-id="photo.id" draggable="true"
                                 @dragstart="photoUploader({{ $sectionJs }}).dragStartSelection($event, photo.id)"
                                 @click="photoUploader({{ $sectionJs }}).selectClick(photo.id, photoUploader({{ $sectionJs }}).untaggedPhotos().map(p => p.id), $event)"
                                 class="rounded-md cursor-pointer" style="position:relative;"
                                 :style="photoUploader({{ $sectionJs }}).isSelected(photo.id) ? 'outline:2px solid var(--brand-icon,#0ea5e9);' : ''">
                                <img :src="photo.storage_path" class="rounded-md object-cover" style="width:3rem; height:3rem;" alt="">
                                {{-- Standards — a removed photo is archived,
                                     never hard-deleted. Screened out of the
                                     tray BEFORE filing, the most common real
                                     case (a duplicate or blurry shot). --}}
                                <button type="button" @click.stop="if (confirm('Archive this photo?')) photoUploader({{ $sectionJs }}).archivePhoto(photo.id)"
                                        class="absolute -top-1.5 -right-1.5 w-4 h-4 rounded-full flex items-center justify-center text-xs font-bold"
                                        style="background:var(--ds-crimson); color:#fff; line-height:1;" title="Archive">&times;</button>
                            </div>
                        </template>
                        <template x-if="photoUploader({{ $sectionJs }}).marquee">
                            <div style="position:absolute; border:1px dashed var(--brand-icon,#0ea5e9); background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent); pointer-events:none;"
                                 :style="'left:' + photoUploader({{ $sectionJs }}).marquee.x + 'px; top:' + photoUploader({{ $sectionJs }}).marquee.y + 'px; width:' + photoUploader({{ $sectionJs }}).marquee.w + 'px; height:' + photoUploader({{ $sectionJs }}).marquee.h + 'px;'"></div>
                        </template>
                    </div>

                    <div class="flex items-center gap-2 flex-wrap" x-show="photoUploader({{ $sectionJs }}).selected.size">
                        <span class="text-xs" style="color:var(--text-secondary);" x-text="photoUploader({{ $sectionJs }}).selected.size + ' selected'"></span>
                        <select class="prop-input text-xs" style="max-width:10rem;" x-model.number="trayTagRoomChoice">
                            <option value="">Tag to room…</option>
                            <template x-for="group in roomGroups().filter(g => g.room)" :key="group.room.id">
                                <option :value="group.room.id" x-text="group.room.label"></option>
                            </template>
                        </select>
                        <button type="button" :disabled="!trayTagRoomChoice" @click="photoUploader({{ $sectionJs }}).tagSelectedToRoom(trayTagRoomChoice); trayTagRoomChoice = ''"
                                class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Tag selected</button>
                        <button type="button" @click="photoUploader({{ $sectionJs }}).clearSelection()" class="text-xs font-semibold underline" style="color:var(--text-secondary);">Clear</button>
                    </div>
                </div>
            </template>
        </div>

        {{-- 2026-09-21, Johan on property 5792 — same room-heading grouping
             as the Inspection Items panel above, applied here so a walkthrough
             actually walks room by room instead of a flat list scattered by
             creation order. Keyed on group.room.id (roomGroups()), never on
             the room's free-text label. Item 7, 2026-09-22 — heading shows
             recorded/total + photo count and collapses once the room is
             fully recorded — always a default, never a lock: click the
             heading row to expand/collapse any time, whatever its state. --}}
        <template x-for="group in roomGroups()" :key="group.room ? 'room-' + group.room.id : 'general'">
            <div class="space-y-1 pt-2">
                <div class="flex items-center justify-between gap-2 rounded-md px-1 -mx-1"
                     :style="(dragOverRoom === (group.room?.id ?? null) ? 'background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 15%, transparent); outline:2px dashed var(--brand-icon,#0ea5e9);' : '') + 'cursor:pointer; transition:background .1s;'"
                     @mouseenter="$el.style.background = 'var(--surface-2)'" @mouseleave="$el.style.background = 'transparent'; dragOverRoom = null"
                     @click="toggleRoomOpen({{ $sectionJs }}, group)"
                     @dragover.prevent="group.room && (dragOverRoom = group.room.id)"
                     @dragleave="dragOverRoom = null"
                     @drop.prevent="group.room && photoUploader({{ $sectionJs }}).dropOnRoom($event, group.room.id); dragOverRoom = null">
                    <button type="button" class="flex items-center gap-1.5 text-left py-1" tabindex="0">
                        <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"
                             :style="'color:var(--text-muted); transition:transform .15s; transform:rotate(' + (isRoomOpen({{ $sectionJs }}, group) ? 90 : 0) + 'deg);'">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                        </svg>
                        <h4 class="text-xs font-bold uppercase tracking-wide" style="color:var(--text-secondary);"
                            x-text="(group.room ? group.room.label : 'General') + ' — ' + roomProgress({{ $sectionJs }}, group).recorded + '/' + roomProgress({{ $sectionJs }}, group).total
                                    + (roomProgress({{ $sectionJs }}, group).photos ? ' · ' + roomProgress({{ $sectionJs }}, group).photos + ' photo' + (roomProgress({{ $sectionJs }}, group).photos === 1 ? '' : 's') : '')"></h4>
                    </button>
                    <div class="flex items-center gap-1" @click.stop>
                        {{-- Item 2, 2026-09-22 — the room's own general
                             photo(s), independent of any item; always
                             available (not gated on recording progress).
                             The heading row itself is also a drop target
                             for the tray's multi-select-and-drop (item 3). --}}
                        <template x-if="group.room">
                            <label class="text-xs font-semibold px-1.5 py-1 rounded-md cursor-pointer inline-flex items-center" style="background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);" title="Add room photo(s)">
                                <span>&#128247;</span>
                                <input type="file" accept="image/*" multiple class="hidden"
                                       @change="onRoomPhotosSelected({{ $sectionJs }}, group.room, $event.target.files); $event.target.value = null;">
                            </label>
                        </template>
                        {{-- Item 5/7 fix, 2026-09-22 — these have zero effect
                             once the room has nothing left to fill; showing
                             a "working" button that does nothing on click
                             is worse than not showing it. --}}
                        <template x-if="group.room && roomProgress({{ $sectionJs }}, group).recorded < roomProgress({{ $sectionJs }}, group).total">
                            <div class="flex items-center gap-1">
                                <button type="button" :disabled="markGoodBusy[group.room?.id]"
                                        @click="markRoomGood({{ $sectionJs }}, group.room)"
                                        class="text-xs font-semibold px-2 py-1 rounded-md" style="background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);"
                                        x-text="markGoodBusy[group.room?.id] ? 'Marking…' : 'All Good'"></button>
                                {{-- §17, Johan on Retha's real paper form: "she
                                     strikes ENTIRE ROOMS out with one big N/A."
                                     Only offered when N/A is actually one of
                                     the agency's configured condition states. --}}
                                <button type="button" x-show="hasNaConditionState()" :disabled="markNaBusy[group.room?.id]"
                                        @click="markRoomNa({{ $sectionJs }}, group.room)"
                                        class="text-xs font-semibold px-2 py-1 rounded-md" style="background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);"
                                        x-text="markNaBusy[group.room?.id] ? 'Marking…' : 'Mark room N/A'"></button>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- R1, 2026-09-22, Johan: "the small images is a waste of
                     time. it either has to show it big enough like on
                     gallery" — same square-tile size/grid as the property
                     Gallery (rental-section-body.blade.php: grid-cols-3
                     sm:grid-cols-5, aspect-ratio 1/1), one row by default
                     (a 15-room property must not push items ten screens
                     down), expanding to every row on click. Only rendered
                     when at least one general room photo exists (screen
                     space: nothing rendered otherwise). --}}
                <template x-if="group.room && roomPhotosFor({{ $sectionJs }}, group.room).length">
                    <div class="pl-5 space-y-1">
                        <div class="grid grid-cols-3 sm:grid-cols-5 gap-2 overflow-hidden"
                             :style="roomPhotosExpanded[group.room.id] ? '' : 'max-height:6.5rem;'">
                            <template x-for="photo in roomPhotosFor({{ $sectionJs }}, group.room)" :key="photo.id">
                                <div class="relative rounded-md overflow-hidden" style="aspect-ratio:1/1; background:var(--surface-3);">
                                    <img :src="photo.storage_path" class="w-full h-full object-cover cursor-pointer"
                                         @click="viewer = { open: true, images: roomPhotosFor({{ $sectionJs }}, group.room).map(p => p.storage_path), index: roomPhotosFor({{ $sectionJs }}, group.room).indexOf(photo) }" alt="">
                                    {{-- Bug 2, 2026-09-22, Johan: "cant tag room and
                                         then ceiling as example" — room first, then
                                         an item within that room. Supersedes the
                                         room-only tag (kept, same server call), never
                                         a second tag. --}}
                                    <select class="absolute bottom-0 left-0 right-0 text-[0.65rem]" style="background:rgba(0,0,0,0.65); color:#fff; border:0;"
                                            @click.stop
                                            :value="photo.rental_inspection_observation_id || ''"
                                            @change="photoUploader({{ $sectionJs }}).tagPhoto(photo.id, { property_room_id: group.room.id, rental_inspection_observation_id: $event.target.value || null })">
                                        <option value="">General</option>
                                        <template x-for="i in group.items" :key="i.id">
                                            <option :value="i.id" x-text="i.label"></option>
                                        </template>
                                    </select>
                                </div>
                            </template>
                        </div>
                        <button type="button" x-show="roomPhotosFor({{ $sectionJs }}, group.room).length > 3"
                                @click="roomPhotosExpanded[group.room.id] = !roomPhotosExpanded[group.room.id]"
                                class="text-xs font-semibold underline" style="color:var(--text-secondary);"
                                x-text="roomPhotosExpanded[group.room.id] ? 'Show less' : ('Show all ' + roomPhotosFor({{ $sectionJs }}, group.room).length)"></button>
                    </div>
                </template>

                <div x-show="isRoomOpen({{ $sectionJs }}, group)" class="space-y-1">
                    <template x-for="item in group.items" :key="item.id">
                        <div class="py-2 pl-3 space-y-1.5" style="border-bottom:1px solid var(--border);">
                            <span class="text-sm" style="color:var(--text-primary);" x-text="item.label"></span>
                            {{-- R2, 2026-09-22, Johan: "why dont we stack the buttons
                                 neat and tidy on top of each other and use 2 columns
                                 for all the buttons... sizing should work out that
                                 its essentially the same height as the photos running
                                 next to the buttons towards the right".
                                 FIX, 2026-09-22 (third pass, Johan): "surely theres a
                                 specific size the buttons take up and that same size
                                 can be applied to photos" — right, and the fixed 80px
                                 from the previous pass was wrong for any agency but
                                 the one it was measured against. Standard mechanism
                                 for exactly this: the row is flex+stretch; the button
                                 grid is flex:none so IT alone sets the row's height
                                 (3 rows for 6 states, 4 for 7, whatever the agency has
                                 configured); the strip is flex:1 with min-width:0,
                                 min-height:0 and position:relative, and contains
                                 NOTHING that can contribute height itself — inside it,
                                 an ABSOLUTELY POSITIONED scroller (inset:0) does the
                                 actual horizontal scrolling. Because the scroller is
                                 out of flow, nothing inside it — however large a
                                 photo's native resolution — can ever feed back into
                                 the row's height; the row is sized purely by the
                                 buttons and the strip stretches to match, for any
                                 agency's condition-state count. --}}
                            <div class="flex items-stretch gap-3" style="display:flex; align-items:stretch; min-height:0;">
                                <div class="flex-none space-y-1.5" style="flex:none;">
                                    {{-- Item 3, 2026-09-22 — one tap, agency's own
                                         condition vocabulary
                                         (RentalInspectionSetting::conditionStatesFor()),
                                         now a 2-column grid (R2) instead of a wrapping
                                         row — holds for any list length, never a
                                         hardcoded count. Item 4 — no separate condition
                                         text anywhere else; the selected button itself
                                         is the only place the current condition shows. --}}
                                    <div class="grid grid-cols-2 gap-1">
                                        <template x-for="state in conditionStates" :key="state.key">
                                            <button type="button"
                                                    :disabled="isObsBusy({{ $sectionJs }}, item.id)"
                                                    @click="onConditionTap({{ $sectionJs }}, item, state.key)"
                                                    class="text-xs font-semibold px-2.5 py-1.5 rounded-md"
                                                    :style="selectedConditionFor({{ $sectionJs }}, item) === state.key
                                                        ? 'background:var(--brand-button,#0ea5e9); color:#fff;'
                                                        : 'background:var(--surface-2); color:var(--text-secondary);'"
                                                    x-text="state.label"></button>
                                        </template>
                                    </div>
                                    <input type="text"
                                           x-show="selectedConditionFor({{ $sectionJs }}, item) && conditionRequiresNotes(selectedConditionFor({{ $sectionJs }}, item))"
                                           x-model="obsField({{ $sectionJs }}, item.id).notes"
                                           @input="onNotesInput({{ $sectionJs }}, item)"
                                           placeholder="Notes (required)"
                                           class="prop-input w-full">
                                </div>

                                {{-- R2 — item photos, height derived from the button
                                     grid above via the row's own stretch, never a
                                     hardcoded pixel value. The strip itself (flex:1)
                                     contributes NO height of its own — the absolutely
                                     positioned scroller inside it holds every photo
                                     and the add tile, out of flow, so nothing in there
                                     can ever grow the row. One always-present add tile
                                     covers both the "no photo yet" and "add more"
                                     cases from before — same multi-file input, same
                                     staged-until-recorded behaviour (Item 1/6). --}}
                                <div style="display:block; flex:1; align-self:stretch; min-width:0; min-height:0; position:relative;">
                                    <div style="position:absolute; top:0; left:0; right:0; bottom:0; height:100%; overflow-x:auto; overflow-y:hidden; white-space:nowrap; font-size:0;">
                                        <template x-for="photo in itemPhotosFor({{ $sectionJs }}, item)" :key="photo.id">
                                            <div class="relative rounded-md" style="display:inline-block; height:100%; overflow:hidden; background:var(--surface-3); margin-right:0.375rem;">
                                                <img :src="photo.storage_path" style="display:inline-block; height:100%; width:auto; object-fit:cover; cursor:pointer;"
                                                     @click="viewer = { open: true, images: itemPhotosFor({{ $sectionJs }}, item).map(p => p.storage_path), index: itemPhotosFor({{ $sectionJs }}, item).indexOf(photo) }" alt="">
                                                {{-- Bug 2 — untag steps back the same way:
                                                     the exact reverse of the room→item tag
                                                     above, one click, back to a general
                                                     room shot. Only offered when this item
                                                     actually belongs to a real room (never
                                                     shown in the roomless "General"
                                                     meters/legacy group — there is no room
                                                     to step back to). --}}
                                                <button type="button" x-show="group.room" @click.stop="photoUploader({{ $sectionJs }}).tagPhoto(photo.id, { property_room_id: group.room?.id })"
                                                        class="absolute top-0.5 right-0.5 w-4 h-4 rounded-full flex items-center justify-center text-xs font-bold"
                                                        style="background:rgba(0,0,0,0.65); color:#fff; line-height:1;" title="Back to room">&uarr;</button>
                                            </div>
                                        </template>
                                        <label class="rounded-md cursor-pointer"
                                               style="display:inline-flex; align-items:center; justify-content:center; vertical-align:top; height:100%; width:2.5rem; font-size:1rem;"
                                               :style="(obsField({{ $sectionJs }}, item.id).photos || []).length
                                                    ? 'background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 20%, transparent); color:var(--brand-icon,#0ea5e9);'
                                                    : 'background:var(--surface-2); color:var(--text-secondary);'"
                                               :title="(obsField({{ $sectionJs }}, item.id).photos || []).length ? 'Photo(s) attached — saves once this item is recorded' : 'Add photo(s)'">
                                            <span>&#128247;</span>
                                            <input type="file" accept="image/*" multiple class="hidden"
                                                   @change="onItemPhotosSelected({{ $sectionJs }}, item, $event.target.files); $event.target.value = null;">
                                        </label>
                                    </div>
                                </div>
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
