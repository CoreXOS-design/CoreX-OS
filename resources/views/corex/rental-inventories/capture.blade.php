@extends('layouts.corex')

{{--
    .ai/specs/rental-inventory.md §0b — the capture surface, rebuilt
    2026-09-22 per Johan: "it lives on a property... agent needs to see the
    spaces again, like with inspections... upload photos and tag it to the
    room, then type in what inventory is in that room, and if they want to
    even tag the line item added to a photo in that room." Reached only from
    the property (see properties/show.blade.php's single Inventory link) —
    the property is already known, so it is never asked for here.

    Deliberately its own page with its OWN Alpine component
    (rentalInventoryCapture below) — not extending or touching the giant
    shared component in properties/show.blade.php, to avoid any collision
    with concurrent work there this session. Rooms are the SAME PropertyRoom
    rows and ordering (sort_order, id) that surface already uses.

    Autosave throughout — every add/edit/tag fires its own request the
    moment the agent acts; there is no Save button anywhere on this page.
    Autosave is not announced with helper text (Johan: never say it, just
    behave that way) — every line on this screen is either data or a
    control.

    §13, 2026-09-27 — Johan's approved capture-screen mockup replaced the
    all-rooms-open accordion list (design pass 2, 2026-09-22) with a single
    active room at a time, selected from a horizontal, never-wrapping strip
    of room chips across the top, each carrying a status dot (captured /
    part done / not opened). NOTE FOR THE RECORD: at the time this was
    built, no equivalent chip/tab pattern with status dots existed anywhere
    on the rental-inspections recording screen to reuse verbatim (confirmed
    by search — that screen ships a room-accordion list with an
    Expand-all/Collapse-all control, per §11.5's own investigation two days
    earlier). Built fresh here per the approved mockup; if Johan wants
    inspections to match, that is a follow-up pass on that screen, not
    something this pass silently invented a second version of.
--}}

@section('content')
{{-- §4a — the SAME shared uploader rental-inspections built
     (rental-inspections.md §20.13.4), not a second bespoke one. --}}
@if($inventory)
<script src="{{ asset_v('js/corex-photo-batch-uploader.js') }}"></script>
@endif
<div class="p-4 sm:p-6 max-w-3xl mx-auto space-y-4"
     @if($inventory) x-data="rentalInventoryCapture({{ $inventory->id }}, {{ $property->id }}, '{{ $spaceStoreUrl ?? '' }}')" @endif>

    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-lg font-semibold truncate">Inventory — {{ $property->buildDisplayAddress() }}</h1>
            @if($inventory)
                <p class="text-xs mt-0.5" style="color: var(--text-muted);">
                    <span class="ds-badge {{ $inventory->status === 'completed' ? 'ds-badge-success' : ($inventory->status === 'cancelled' ? 'ds-badge-danger' : 'ds-badge-muted') }}">
                        {{ ucfirst(str_replace('_', ' ', $inventory->status)) }}
                    </span>
                </p>
            @endif
        </div>
        <a href="{{ route('corex.properties.show', $property) }}" class="corex-btn-outline text-xs shrink-0">Back to property</a>
    </div>

    @if(!$inventory)
        {{-- §0a — a property with no active lease has nothing to attach an
             inventory to yet; honest state, not a silent 404 or crash. --}}
        <div class="rounded-md p-4 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">
            This property has no active lease yet, so there's nothing to attach an inventory to.
            Start a lease first, then come back here to capture the inventory.
        </div>
    @else
        @if(in_array($inventory->status, ['completed', 'cancelled']))
            <div class="rounded-md p-3 text-xs" style="background: var(--surface-2); color: var(--text-secondary);">
                This inventory is {{ $inventory->status }} and read-only here.
                <a href="{{ route('corex.rental-inventories.show', $inventory) }}" class="font-semibold" style="color: var(--brand-button,#0ea5e9);">Open the full record</a>
                @if($inventory->status === 'completed')
                    for signatures and the move-out comparison.
                @endif
            </div>
        @else
            <div class="flex items-center justify-end">
                <a href="{{ route('corex.rental-inventories.show', $inventory) }}" class="text-xs font-semibold" style="color: var(--brand-button,#0ea5e9);">Signatures &amp; complete →</a>
            </div>
        @endif

        {{-- Add space — Johan: "we specced inventory being blank then you can
             create the spaces same as with inspections." Same write path
             the Inspection Items section's own "Space (new room)" control
             uses (RentalInspectionRecordingController::storeItem(), kind=
             space) — a room created here is the SAME PropertyRoom row
             inspections sees, not a second space model. Always available,
             not just when blank — an agent adds more spaces as the walk-
             through finds them, same as on the inspection side. --}}
        <div class="rounded-md p-3" style="background: var(--surface-2);">
            <template x-if="!rooms.length">
                <p class="text-xs pb-2" style="color: var(--text-secondary);">
                    This property has no spaces set up yet — add the first one below. Inventory and Inspections share the same room list.
                </p>
            </template>
            <form @submit.prevent="addSpace()" class="flex items-end gap-2 flex-wrap">
                <div>
                    <label class="text-xs font-semibold block" style="color: var(--text-secondary);">Room type</label>
                    <select x-model="newSpace.space_type" class="prop-input" style="max-width:11rem;">
                        <option value="">Room type…</option>
                        @foreach($spaceTypes as $spaceType)
                            <option value="{{ $spaceType }}">{{ $spaceType }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex-1" style="min-width:10rem;">
                    <label class="text-xs font-semibold block" style="color: var(--text-secondary);">Name</label>
                    <input type="text" x-model="newSpace.label" placeholder="e.g. Bedroom 1" maxlength="191"
                           class="prop-input w-full" @keydown.enter.prevent="addSpace()">
                </div>
                <button type="submit" :disabled="spaceBusy || !newSpace.label.trim() || !newSpace.space_type"
                        class="text-xs font-semibold rounded-md text-white px-3 py-1.5" style="background:var(--brand-button,#0ea5e9);"
                        x-text="spaceBusy ? 'Adding…' : 'Add space'"></button>
            </form>
            <p x-show="spaceError" x-cloak class="text-xs pt-1" style="color:#ef4444;" x-text="spaceError"></p>
        </div>

        {{-- §13 — "Copy from last inventory." Only rendered when the server
             confirmed a prior inventory genuinely exists for this property
             (RentalInventory::priorInventory()) — the endpoint is the real
             gate either way, this just avoids offering a control that can
             only ever 404. --}}
        @if($hasPriorInventory)
        <div class="flex items-center justify-between gap-2 rounded-md p-3" style="background: var(--surface-2);">
            <p class="text-xs" style="color: var(--text-secondary);">This property has an earlier inventory on record.</p>
            <button type="button" :disabled="copyBusy" @click="copyFromLastInventory()"
                    class="text-xs font-semibold rounded-md px-3 py-1.5 shrink-0" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                    x-text="copyBusy ? 'Copying…' : 'Copy from last inventory'"></button>
        </div>
        <p x-show="copyError" x-cloak class="text-xs" style="color:#ef4444;" x-text="copyError"></p>
        @endif

        {{-- §13 — the room strip: a horizontal, NEVER-wrapping row of chips,
             one per room, each carrying a status dot (captured / part done /
             not opened — roomStatus() below). Clicking a chip switches the
             single active-room panel beneath it; this REPLACES the previous
             all-rooms-open accordion list entirely (§0c/§0b), not
             alongside it. overflow-x-auto + flex-nowrap is the whole
             mechanism — no custom scroller, same as every other horizontal
             strip already on this page (photo galleries, below). --}}
        <div x-show="rooms.length" class="flex items-center gap-2 overflow-x-auto pb-1" style="flex-wrap:nowrap;">
            <template x-for="room in rooms" :key="room.id">
                {{-- Same :style clobber trap as the dot below — the
                     "never wraps" white-space rule has to live INSIDE the
                     one bound :style expression, not as a co-located
                     static style="..." on this same button. --}}
                <button type="button" @click="selectRoom(room.id)"
                        class="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full shrink-0"
                        :style="(room.id === activeRoomId ? 'background:var(--brand-button,#0ea5e9); color:#fff;' : 'background:var(--surface-2); color:var(--text-secondary);') + ' white-space:nowrap;'">
                    {{-- Alpine's :style clobber trap (rental-inspections.md
                         §22.3b): a bound :style REPLACES the whole style
                         attribute on every reactive render, so a co-located
                         static style="..." on the same tag silently
                         disappears the moment the binding evaluates. The
                         fixed 8x8 size lives INSIDE the one bound
                         expression instead, never as a separate static
                         attribute on this tag. --}}
                    <span class="rounded-full shrink-0" :style="'width:8px; height:8px; background:' + roomStatusColor(room.id) + ';'"></span>
                    <span x-text="room.label"></span>
                </button>
            </template>
        </div>

        {{-- The single active room's content — everything below is scoped
             to ONE room at a time now, never several stacked accordions. --}}
        <template x-if="activeRoom()">
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" :data-room-id="activeRoomId">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold" x-text="activeRoom().label"></h2>
                    <span class="text-xs shrink-0" style="color: var(--text-muted);" x-text="linesFor(activeRoomId).length + ' item' + (linesFor(activeRoomId).length === 1 ? '' : 's')"></span>
                </div>

                {{-- Line items. §0b+2026-09-26 — every committed line is a
                     live grid cell (arrow-key navigation, onCellKeydown()
                     below); §13 adds a condition-chip row and a per-line
                     photo strip beneath the qty/description grid row, so
                     each item is now two stacked rows, not one. --}}
                <div>
                    <template x-for="line in linesFor(activeRoomId)" :key="line.id">
                        <div class="space-y-1.5 text-sm" style="padding:0.5rem 0; border-bottom:1px solid var(--border);">
                            <div style="display:grid; grid-template-columns:4.5rem 1fr 7.5rem; gap:0.5rem; align-items:center;">
                                <input type="text" inputmode="numeric" pattern="[0-9]*" x-model="line.quantity"
                                       class="prop-input" style="width:100%;"
                                       :data-row="'line-' + line.id" data-cell="qty"
                                       @keydown="onCellKeydown($event, activeRoom(), 'line', line, 'qty')"
                                       @blur="commitRow(activeRoom(), 'line', line)">
                                <div class="min-w-0">
                                    <input type="text" x-model="line.description"
                                           class="prop-input w-full"
                                           :data-row="'line-' + line.id" data-cell="description"
                                           @keydown="onCellKeydown($event, activeRoom(), 'line', line, 'description')"
                                           @blur="commitRow(activeRoom(), 'line', line)">
                                    <template x-if="photosForLine(line).length">
                                        <span class="text-xs ml-1" style="color: var(--text-muted);" x-text="'(' + photosForLine(line).length + ' photo' + (photosForLine(line).length === 1 ? '' : 's') + ')'"></span>
                                    </template>
                                </div>
                                <div style="display:flex; align-items:center; justify-content:flex-end; gap:0.5rem;">
                                    {{-- tabindex="-1" on both: mouse-clickable, never a Tab/keyboard stop (§2 of the grid spec). --}}
                                    <button type="button" tabindex="-1" x-show="photoUploader().roomPhotos(activeRoomId).length" @click="openTagger(line)" class="text-xs font-semibold" style="color: var(--brand-button,#0ea5e9);">Tag photo</button>
                                    <button type="button" tabindex="-1" @click="retireLine(line)" class="text-xs font-semibold" style="color: var(--ds-crimson,#c41e3a);">Remove</button>
                                </div>
                            </div>

                            {{-- §13 — condition chips, the agency-configurable
                                 move-in vocabulary. A plain click sets it —
                                 no separate save step, matching every other
                                 control on this page. --}}
                            <div class="flex items-center gap-1 flex-wrap">
                                <template x-for="state in conditionStates" :key="state.key">
                                    <button type="button" tabindex="-1" @click="setLineCondition(line, state.key)"
                                            class="text-[11px] font-semibold px-2 py-0.5 rounded-full"
                                            :style="line.condition_key === state.key ? 'background:var(--brand-button,#0ea5e9); color:#fff;' : 'background:var(--surface-2); color:var(--text-secondary);'"
                                            x-text="state.label"></button>
                                </template>
                            </div>

                            {{-- §13, Johan's approved mockup — "Photos are a
                                 horizontal strip on the line, and a photo
                                 uploads THE MOMENT it is selected. No
                                 staging, ever." A bare file input behind a
                                 "+" tile, same reasoning as every other
                                 immediate-upload control on this page: the
                                 native file picker is free, no custom camera
                                 UI needed. Uploads AND tags to this line in
                                 ONE request (storePhotos() with
                                 rental_inventory_line_id) — never a
                                 stage-then-tag two-step. --}}
                            <div class="flex items-center gap-1.5 overflow-x-auto" style="flex-wrap:nowrap;">
                                <template x-for="photo in photosForLine(line)" :key="photo.id">
                                    <img :src="photo.storage_path" class="rounded-md object-cover shrink-0" style="width:44px; height:44px; background:var(--surface-3);" alt="Item photo">
                                </template>
                                <label class="flex items-center justify-center rounded-md cursor-pointer shrink-0" style="width:44px; height:44px; background:var(--surface-2); border:1px dashed var(--border); font-size:16px; color:var(--text-muted);">
                                    <span>+</span>
                                    <input type="file" accept="image/*,.heic,.heif" multiple class="hidden" tabindex="-1"
                                           @change="uploadPhotoToLine(line, $event.target.files); $event.target.value=''">
                                </label>
                            </div>
                        </div>
                    </template>
                    <div x-show="!linesFor(activeRoomId).length" class="flex items-center justify-between gap-2 py-1">
                        <p class="text-xs" style="color: var(--text-muted);">No items yet.</p>
                        {{-- §12 — the room-level counterpart to a line: an
                             explicit "I checked, there's nothing here"
                             confirmation, the same shape as rental-
                             inspections' "Mark room N/A". Only offered
                             while the room genuinely has no items — once
                             a real item exists the room already satisfies
                             the completion gate through that line, and
                             marking it empty too would just be
                             contradictory. No "unmark": same one-way
                             shape as inspections' own control. --}}
                        <template x-if="!isRoomMarkedEmpty(activeRoomId)">
                            <button type="button" tabindex="-1" :disabled="markRoomBusy[activeRoomId]"
                                    @click="markRoomEmpty(activeRoom())"
                                    class="text-xs font-semibold shrink-0" style="color: var(--text-secondary);"
                                    x-text="markRoomBusy[activeRoomId] ? 'Marking…' : 'Nothing in this room'"></button>
                        </template>
                        <template x-if="isRoomMarkedEmpty(activeRoomId)">
                            <span class="text-xs font-semibold shrink-0" style="color: var(--ds-green,#16a34a);">&#10003; Nothing in this room</span>
                        </template>
                    </div>
                </div>

                {{-- Add line — spreadsheet-grid capture (Johan,
                     2026-09-25/26), §13: ALWAYS open, already ready to type
                     into — no "add item" click ever stands between the
                     agent and a blank line. See onCellKeydown() for the
                     full contract (Tab/Enter/→-at-end commits and moves to
                     the next row's qty; ←-at-start moves to the previous
                     row's description; ↑/↓ always move a row in the same
                     column). This row is always the blank bottom row —
                     committing resets it in place SYNCHRONOUSLY, before the
                     save request resolves, so a fast typist filling the
                     next row never races their own in-flight save. --}}
                <form @submit.prevent="commitDraftRow(activeRoom())" style="display:grid; grid-template-columns:4.5rem 1fr 7.5rem; gap:0.5rem; align-items:center; padding-top:0.5rem;">
                    <input type="text" inputmode="numeric" pattern="[0-9]*" x-model="newLine[activeRoomId].quantity" placeholder="Qty"
                           class="prop-input" style="width:100%;"
                           data-row="draft" data-cell="qty"
                           @keydown="onCellKeydown($event, activeRoom(), 'draft', null, 'qty')"
                           @blur="commitRow(activeRoom(), 'draft', null)">
                    <input type="text" x-model="newLine[activeRoomId].description" placeholder="e.g. White wooden headboard"
                           class="prop-input"
                           data-row="draft" data-cell="description"
                           @keydown="onCellKeydown($event, activeRoom(), 'draft', null, 'description')"
                           @blur="commitRow(activeRoom(), 'draft', null)">
                    <button type="submit" tabindex="-1" :disabled="lineBusy[activeRoomId]"
                            class="text-xs font-semibold rounded-md text-white" style="background:var(--brand-button,#0ea5e9); padding:0.375rem 0.75rem; justify-self:end;">Add</button>
                </form>
                {{-- §7 — quiet, in-place feedback, never a toast: a
                     one-line fade that disappears on its own. --}}
                <p x-show="lineSavedFlash[activeRoomId]" x-cloak x-transition.opacity.duration.400ms
                   class="text-xs" style="color:#16a34a;">&#10003; Saved</p>
                <p x-show="lineSaveError[activeRoomId]" x-cloak
                   class="text-xs" style="color:var(--ds-crimson,#c41e3a);">Couldn't save that item — check your connection and try again.</p>

                {{-- Room-level general photos — unchanged from before this
                     pass: photos of the room overall, not tied to any one
                     item (a different concept from the per-line strips
                     above, which §13 adds alongside this, not instead of
                     it — the many-to-many line-photo tag still needs a pool
                     of untagged/general photos to tag FROM via the modal
                     below). §4a: adopts the SAME batched uploader and the
                     SAME gallery-sized, count-clipped layout rental-
                     inspections settled on. --}}
                <div class="pt-2 space-y-1" style="border-top:1px solid var(--border);">
                    <template x-if="photoUploader().roomPhotos(activeRoomId).length">
                        <div class="grid grid-cols-3 sm:grid-cols-5 gap-2">
                            <template x-for="photo in (roomPhotosExpanded[activeRoomId] ? photoUploader().roomPhotos(activeRoomId) : photoUploader().roomPhotos(activeRoomId).slice(0, 3))" :key="photo.id">
                                <div class="relative rounded-md overflow-hidden" style="aspect-ratio:1/1; background:var(--surface-3);">
                                    <img :src="photo.storage_path" class="w-full h-full object-cover cursor-pointer" @click="openTaggerForPhoto(activeRoom(), photo)" alt="Room photo">
                                    <span x-show="photo.lines && photo.lines.length" class="absolute top-0.5 left-0.5 text-[10px] font-bold text-white rounded-full flex items-center justify-center" style="width:16px; height:16px; background:var(--brand-button,#0ea5e9);" x-text="photo.lines.length"></span>
                                    <button type="button" tabindex="-1" @click.stop="if (confirm('Archive this photo?')) photoUploader().archivePhoto(photo.id)"
                                            class="absolute top-0.5 right-0.5 w-4 h-4 rounded-full flex items-center justify-center text-xs font-bold"
                                            style="background:var(--ds-crimson,#c41e3a); color:#fff; line-height:1;" title="Archive">&times;</button>
                                </div>
                            </template>
                        </div>
                    </template>
                    <div class="flex items-center gap-2 flex-wrap pt-1">
                        <button type="button" tabindex="-1" x-show="photoUploader().roomPhotos(activeRoomId).length > 3"
                                @click="roomPhotosExpanded[activeRoomId] = !roomPhotosExpanded[activeRoomId]"
                                class="text-xs font-semibold underline" style="color:var(--text-secondary);"
                                x-text="roomPhotosExpanded[activeRoomId] ? 'Show less' : ('Show all ' + photoUploader().roomPhotos(activeRoomId).length)"></button>
                        <label class="text-xs font-semibold px-3 py-1.5 rounded-md cursor-pointer inline-flex items-center gap-1" style="background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);">
                            <span>&#128247;</span>
                            <span>Add photo(s)</span>
                            <input type="file" accept="image/*,.heic,.heif" multiple class="hidden" @change="photoUploader().uploadFiles($event.target.files, { property_room_id: activeRoomId }); $event.target.value = ''">
                        </label>
                    </div>
                    {{-- Same batch progress/retry rows as the inspections
                         recording surface — real upload-progress percent,
                         a batch that fails is independently retryable. --}}
                    <template x-for="(batch, idx) in photoUploader().uploadBatches.filter(b => b.status !== 'done' && b.extraFields && Number(b.extraFields.property_room_id) === Number(activeRoomId))" :key="idx">
                        <div class="flex items-center justify-between gap-2 text-xs px-2 py-1 rounded-md"
                             :style="batch.status === 'failed' ? 'background:color-mix(in srgb, var(--ds-crimson) 10%, transparent);' : 'background:var(--surface-2);'">
                            <span :style="batch.status === 'failed' ? 'color:var(--ds-crimson);' : 'color:var(--text-secondary);'"
                                  x-text="batch.status === 'failed' ? (batch.files.length + ' photo(s) failed — ' + batch.error) : ('Uploading ' + batch.files.length + ' photo(s)… ' + (batch.percent || 0) + '%')"></span>
                            <button type="button" tabindex="-1" x-show="batch.status === 'failed'" @click="photoUploader().retryBatch(batch)"
                                    class="text-xs font-semibold underline" style="color:var(--text-secondary);">Retry</button>
                        </div>
                    </template>
                </div>
            </div>
        </template>

        {{-- Tag-to-photo picker — opened per line or per photo; toggling a
             checkbox tags/untags immediately, same autosave rule as everywhere
             else on this page. --}}
        <div x-show="tagger.open" x-cloak class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" style="background: rgba(0,0,0,0.5);" @click.self="tagger.open = false">
            <div class="w-full sm:max-w-sm rounded-t-lg sm:rounded-lg p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border); max-height: 80vh; overflow-y: auto;">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold">Tag to a photo</h3>
                    <button type="button" @click="tagger.open = false" class="text-xs" style="color: var(--text-muted);">Close</button>
                </div>
                <template x-if="tagger.mode === 'line'">
                    <div class="flex flex-wrap gap-2">
                        <template x-for="photo in tagger.photos" :key="photo.id">
                            <button type="button" @click="toggleTag(tagger.line, photo)" class="relative shrink-0" style="width:64px; height:64px;">
                                <img :src="photo.storage_path" class="w-full h-full object-cover rounded-md" :style="isTagged(tagger.line, photo) ? 'border:2px solid var(--brand-button,#0ea5e9);' : 'border:1px solid var(--border);'" alt="Room photo">
                            </button>
                        </template>
                    </div>
                </template>
                <template x-if="tagger.mode === 'photo'">
                    <div class="space-y-1">
                        <template x-for="line in tagger.lines" :key="line.id">
                            <label class="flex items-center gap-2 py-1 text-sm">
                                <input type="checkbox" :checked="isTagged(line, tagger.photo)" @change="toggleTag(line, tagger.photo)">
                                <span x-text="line.quantity + 'x ' + line.description"></span>
                            </label>
                        </template>
                        <p x-show="!tagger.lines.length" class="text-xs" style="color: var(--text-muted);">No items in this room yet.</p>
                    </div>
                </template>
            </div>
        </div>
    @endif
</div>
@endsection

@if($inventory)
@push('scripts')
<script>
function rentalInventoryCapture(inventoryId, propertyId, spaceStoreUrl) {
    return {
        csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        baseUrl: `/corex/rental-inventories/${inventoryId}`,
        spaceStoreUrl,
        rooms: @json($roomsForJs),
        lines: @json($linesForJs),
        // §13 — the capture-time condition chip vocabulary, agency-
        // configurable, never hardcoded.
        conditionStates: @json($conditionStates),
        // §12 — the completion gate's "nothing in this room" state. A Set of
        // property_room_ids an agent has explicitly confirmed empty, the
        // same distinction rental-inspections' markRoomNa() draws per room.
        markedEmptyRoomIds: new Set(@json($roomMarksForJs).map(m => Number(m.property_room_id))),
        markRoomBusy: {},

        // §13 — exactly one room's content shows at a time, selected from
        // the chip strip. Replaces the old openRooms/toggleRoom() accordion
        // state entirely — see the file's own header comment for why this
        // isn't a second navigation pattern layered on top of the old one.
        activeRoomId: null,
        selectRoom(id) { this.activeRoomId = id; },
        activeRoom() { return this.rooms.find(r => r.id === this.activeRoomId) || null; },
        // Landing room: the first room nobody has opened yet, else the
        // first one that's part-done, else just the first room — an agent
        // walking a property should land somewhere that still needs work,
        // not have to hunt for it themselves.
        pickInitialActiveRoom() {
            if (!this.rooms.length) { this.activeRoomId = null; return; }
            const notOpened = this.rooms.find(r => this.roomStatus(r.id) === 'not_opened');
            if (notOpened) { this.activeRoomId = notOpened.id; return; }
            const partDone = this.rooms.find(r => this.roomStatus(r.id) === 'part_done');
            if (partDone) { this.activeRoomId = partDone.id; return; }
            this.activeRoomId = this.rooms[0].id;
        },
        // §13 — the chip's status dot. [design call, flagged rather than
        // silently assumed]: Inventory has no per-item checklist the way
        // Inspections does, so "part done" here means "has items, but not
        // every one of them has a condition picked yet" — a real, actionable
        // signal (something is genuinely left to do), not an arbitrary
        // three-way split. "captured" is the SAME condition markCompleted()
        // (§12) already accepts for this room (a mark, or at least one
        // line) PLUS every line having a condition — so a green dot always
        // means "this room would not block completion AND every item in it
        // has been graded," never just the bare minimum.
        roomStatus(roomId) {
            if (this.isRoomMarkedEmpty(roomId)) return 'captured';
            const lines = this.linesFor(roomId);
            if (!lines.length) return 'not_opened';
            return lines.every(l => l.condition_key) ? 'captured' : 'part_done';
        },
        roomStatusColor(roomId) {
            const status = this.roomStatus(roomId);
            if (status === 'captured') return '#16a34a';
            if (status === 'part_done') return '#d97706';
            return '#9ca3af';
        },

        openRooms: {},
        newLine: {},
        lineBusy: {},
        // §"spreadsheet-speed capture" (Johan, 2026-09-25/26) — per-room
        // quiet-save feedback (§7) and per-line dirty-check snapshots for
        // the arrow-key grid (keyed by real line id, across all rooms —
        // not per-room, since a line id is already globally unique).
        lineSavedFlash: {},
        lineSaveError: {},
        lineSnapshots: {},
        // §11.2/§12 investigation, 2026-09-27 — an existing line's commit can
        // now fire twice in quick succession: once explicitly (a keydown-
        // driven Tab/Enter/arrow commit) and once implicitly (the native
        // `blur` that same focus change also triggers, now wired to commit
        // too — see the new @blur bindings below, added to close the exact
        // gap that investigation found: a mouse click away from an edited
        // cell, with no Tab/Enter in between, previously discarded the edit
        // with zero warning). Both calls see the same not-yet-saved values,
        // because the first call's PUT is still in flight when blur fires,
        // so without this guard both would send an identical, redundant
        // request. Keyed by line id -> the in-flight request's own value
        // signature, not a bare busy boolean, so a GENUINE second edit
        // (different values) is never suppressed. This SAME mechanism is
        // also what makes switching rooms via the chip strip safe: Alpine's
        // x-if tears down the outgoing room's inputs, which the browser
        // resolves by blurring whatever was focused first — the existing
        // @blur handlers commit it exactly as if the agent had tabbed away,
        // with no extra code needed for the room-switch case specifically.
        _pendingLineCommits: {},
        newSpace: { space_type: '', label: '' },
        spaceBusy: false,
        spaceError: '',
        // §13 — "Copy from last inventory."
        copyBusy: false,
        copyError: '',
        // §4a — same photo layout discipline as inspections (rental-
        // inspections.md §20.14.3): clipping is COUNT-based (first 3, "Show
        // all N"), never height-based — a height clip is what cut real
        // tiles in half on the deployed box the first time this shipped.
        roomPhotosExpanded: {},
        tagger: { open: false, mode: null, line: null, photo: null, photos: [], lines: [] },

        init() {
            this.rooms.forEach(r => this._initRoomState(r));
            // §4c/qty-nullable (Johan, 2026-10-02) — a blank quantity is
            // `null` server-side but ALWAYS '' client-side, never null: this
            // keeps every x-model-bound qty input dealing with one blank
            // representation instead of reasoning about null vs ''.
            this.lines.forEach(l => {
                l.quantity = this.normalizeQtyDisplay(l.quantity);
                this.lineSnapshots[l.id] = { quantity: l.quantity, description: l.description };
            });
            this.pickInitialActiveRoom();
            // §11.2 investigation, 2026-09-27 — closing an existing item's
            // edit down to the ONE commit path this grid already had (a
            // Tab/Enter/arrow-boundary keydown) meant leaving the page
            // entirely (closing the tab, hitting back, switching apps on a
            // phone) while still focused in a cell never fired that path,
            // and silently discarded the edit. `visibilitychange`/
            // `beforeunload` are the two hooks a keydown-only contract can
            // never cover, because neither one is a keydown — the tab is
            // simply gone. `keepalive: true` on the flush request (see
            // flushDirtyLines()) is what lets that request actually survive
            // the page going away, which a plain fetch() does not guarantee.
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') this.flushDirtyLines();
            });
            window.addEventListener('beforeunload', () => this.flushDirtyLines());
        },
        // §11.2 — every existing line whose current value differs from its
        // last-saved snapshot, PLUS any trailing draft row with an
        // un-submitted description, each sent as a best-effort keepalive
        // request so it survives the page unloading mid-request (a plain
        // fetch() gives no such guarantee once the tab is actually closing —
        // `keepalive: true` is what lets the browser finish sending it).
        flushDirtyLines() {
            this.lines.forEach(line => {
                const description = (line.description || '').trim();
                if (!description) return;
                const quantity = this.normalizeQtyDisplay(line.quantity);
                const snap = this.lineSnapshots[line.id] || { quantity, description: line.description };
                if (snap.quantity === quantity && snap.description === description) return;
                fetch(`${this.baseUrl}/lines/${line.id}`, {
                    method: 'PUT',
                    keepalive: true,
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ property_room_id: line.property_room_id, quantity: this.qtyForPayload(quantity), description }),
                }).then(() => { this.lineSnapshots[line.id] = { quantity, description }; }).catch(() => {});
            });
            // The trailing draft row has the identical gap (§11.2): typed but
            // never committed, and about to disappear along with the tab.
            // Same request shape commitDraftRow() itself fires, just also
            // reachable from the unload path.
            Object.keys(this.newLine).forEach(roomId => {
                const form = this.newLine[roomId];
                const description = (form.description || '').trim();
                if (!description) return;
                const payload = { property_room_id: Number(roomId), quantity: this.qtyForPayload(form.quantity), description };
                this.newLine[roomId] = { quantity: '', description: '' };
                fetch(`${this.baseUrl}/lines`, {
                    method: 'POST',
                    keepalive: true,
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                }).then(async (res) => {
                    if (!res.ok) return;
                    const line = await res.json();
                    const quantity = this.normalizeQtyDisplay(line.quantity);
                    this.lines.push({ id: line.id, property_room_id: line.property_room_id, quantity, description: line.description, condition_key: line.condition_key ?? null, photos: [] });
                    this.lineSnapshots[line.id] = { quantity, description: line.description };
                }).catch(() => {});
            });
        },
        // Extracted so a room added live via addSpace() (never present at
        // page-load init()) gets the exact same per-room state a
        // server-seeded room gets — one place, not two copies of this setup
        // that could drift.
        _initRoomState(room) {
            // Johan, 2026-10-02: "I type the qty, tabbing goes to desc" —
            // qty defaulting to 1 meant deleting it first to type 4. Starts
            // blank now; stays blank if left blank (never silently saved as
            // 0 or 1 — see commitDraftRow()/qtyForPayload()).
            this.newLine[room.id] = { quantity: '', description: '' };
            this.lineBusy[room.id] = false;
        },
        // Blank is always '' client-side (never null/undefined) — see init().
        normalizeQtyDisplay(v) {
            return (v === null || v === undefined) ? '' : v;
        },
        // Blank is always null server-side (never silently 0) — Johan,
        // 2026-10-02: "lets get that to null and it will work perfect."
        qtyForPayload(v) {
            const t = (v === null || v === undefined) ? '' : String(v).trim();
            return t === '' ? null : t;
        },
        linesFor(roomId) { return this.lines.filter(l => Number(l.property_room_id) === Number(roomId)); },

        // Johan: "we specced inventory being blank then you can create the
        // spaces same as with inspections." Calls the SAME
        // rental-inspection-items.store endpoint (kind=space) the
        // Inspection Items section's own "Space (new room)" control uses —
        // a real PropertyRoom row, not a second space model. The response
        // is a list of freshly-created RentalInspectionItem checklist rows
        // (this room's default inspection facets) each carrying its own
        // `.room` relation — inventory only cares about the room itself,
        // never the checklist, so it takes items[0].room and discards the
        // rest; every item shares the exact same room, so which one is
        // arbitrary.
        async addSpace() {
            const label = this.newSpace.label.trim();
            if (!label || !this.newSpace.space_type) return;
            this.spaceBusy = true;
            this.spaceError = '';
            try {
                const res = await fetch(this.spaceStoreUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ kind: 'space', label, space_type: this.newSpace.space_type }),
                });
                if (!res.ok) {
                    const body = await res.json().catch(() => ({}));
                    this.spaceError = body.message || 'Could not add that space — try again.';
                    return;
                }
                const result = await res.json();
                const room = result.items?.[0]?.room;
                if (!room) return;
                this.rooms.push({ id: room.id, label: room.label });
                this._initRoomState({ id: room.id });
                this.newSpace = { space_type: '', label: '' };
                // A freshly-added space is exactly the "not opened" room the
                // agent just asked for — land there immediately rather than
                // leaving whatever was active before.
                this.activeRoomId = room.id;
            } finally {
                this.spaceBusy = false;
            }
        },

        // §13, Johan's approved mockup — "a furnished flat is re-let with
        // the same contents, and re-typing forty lines is the work we are
        // supposed to be doing for them." Additive only: appends the
        // copied lines to whatever already exists, never overwrites.
        async copyFromLastInventory() {
            if (!confirm('Copy every item from the last inventory into this one?')) return;
            this.copyBusy = true;
            this.copyError = '';
            try {
                const res = await fetch(`${this.baseUrl}/copy-from-last`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                });
                if (!res.ok) {
                    const body = await res.json().catch(() => ({}));
                    this.copyError = body.message || 'Could not copy from the last inventory.';
                    return;
                }
                const body = await res.json();
                (body.lines || []).forEach(line => {
                    const quantity = this.normalizeQtyDisplay(line.quantity);
                    this.lines.push({ id: line.id, property_room_id: line.property_room_id, quantity, description: line.description, condition_key: line.condition_key ?? null, photos: [] });
                    this.lineSnapshots[line.id] = { quantity, description: line.description };
                });
                this.pickInitialActiveRoom();
            } finally {
                this.copyBusy = false;
            }
        },

        // §4a — the SAME reusable component rental-inspections built
        // (public/js/corex-photo-batch-uploader.js, rental-inspections.md
        // §20.13.4), not a second bespoke upload/progress implementation.
        // One instance for the whole inventory (there is only one document
        // here, unlike inspections' In/Out sections) — memoized so every
        // call site shares the same reactive `photos`/`uploadBatches`
        // state. `roomPhotos(roomId)` is the uploader's own generic
        // filter — it also excludes anything carrying a
        // rental_inspection_observation_id, a key inventory photos never
        // have, so it filters correctly here with no changes needed.
        _photoUploader: null,
        photoUploader() {
            if (!this._photoUploader) {
                this._photoUploader = window.corexPhotoBatchUploader({
                    csrf: this.csrf,
                    uploadUrl: `${this.baseUrl}/photos`,
                    archiveUrl: (photoId) => `${this.baseUrl}/photos/${photoId}`,
                    photos: @json($photosForJs),
                });
            }
            return this._photoUploader;
        },
        // §13 — the per-line photo strip's own photo OBJECTS (not just the
        // ids `line.photos` holds) — looked up against the uploader's own
        // reactive `photos` array so a strip always reflects the current
        // state (including after an archive elsewhere removes one; a stale
        // id left behind in `line.photos` is silently dropped here by
        // `.filter(Boolean)` rather than needing a second cleanup pass).
        photosForLine(line) {
            return (line.photos || []).map(id => this.photoUploader().photos.find(p => p.id === id)).filter(Boolean);
        },
        // §13 — upload AND tag to this line in ONE request
        // (rental_inventory_line_id on storePhotos()), never a stage-then-
        // tag two-step. onBatchDone (the uploader's own 3rd, optional arg)
        // pushes the new photo's id onto this line's own `photos` array the
        // moment the batch's POST succeeds — the same bookkeeping
        // toggleTag() already does for the modal-based tag path, just
        // triggered from upload instead of a checkbox.
        uploadPhotoToLine(line, files) {
            this.photoUploader().uploadFiles(files, { property_room_id: line.property_room_id, rental_inventory_line_id: line.id }, (body) => {
                (body.photos || []).forEach(p => { if (!line.photos.includes(p.id)) line.photos.push(p.id); });
            });
        },

        // ── Spreadsheet-grid capture (Johan, 2026-09-25, expanded
        // 2026-09-26) ─────────────────────────────────────────────────
        // Contract — this is what gets tested key-by-key on the deployed
        // page, so it lives here as one readable block, not spread out:
        //   Tab (no shift), from description      → commit this row if
        //     non-empty, land on next row's qty (selected)
        //   Enter, from either cell                → same as above
        //   → (ArrowRight), caret at END, no selection, from description
        //                                           → same as above
        //   → (ArrowRight), caret at END, no selection, from qty
        //                                           → same row's description
        //     (selected), no commit — still the same row
        //   ← (ArrowLeft), caret at START, no selection, from qty
        //                                           → commit this row if
        //     changed, land on PREVIOUS row's description (selected)
        //   ← (ArrowLeft), caret at START, no selection, from description
        //                                           → same row's qty
        //     (selected), no commit — still the same row
        //   ↑ (ArrowUp) / ↓ (ArrowDown), any caret position
        //                                           → commit this row if
        //     changed, land on the SAME column of the prev/next row
        //     (selected); no-op at the top/bottom edge of the grid
        //   Any arrow key when the caret is NOT at that boundary, or when
        //     text is selected                      → normal cursor /
        //     selection-collapse behaviour, untouched
        //   Shift+Tab                                → left un-intercepted;
        //     native tab order already lands on the right cell because
        //     every other focusable control in a room's block carries
        //     tabindex="-1" (Add button, condition chips, per-line photo
        //     input, photo archive/show-all/retry, Tag photo/Remove)
        // Committing a row never blocks the focus move — save requests are
        // fire-and-forget (commitRow() is called, not awaited) so the
        // keyboard never waits on the network. A row is never saved empty
        // (draft) or blanked to empty (existing line — reverts instead).
        gridRowKeys(room) {
            return [...this.linesFor(room.id).map(l => 'line-' + l.id), 'draft'];
        },
        gridCell(container, rowKey, cellKind) {
            return container ? container.querySelector(`[data-row="${rowKey}"][data-cell="${cellKind}"]`) : null;
        },
        focusAndSelect(el) {
            if (!el) return;
            el.focus();
            el.select();
        },
        onCellKeydown(event, room, rowKind, rowRef, cellKind) {
            const key = event.key;
            if (key !== 'Enter' && key !== 'Tab' && key !== 'ArrowLeft' && key !== 'ArrowRight' && key !== 'ArrowUp' && key !== 'ArrowDown') return;

            const el = event.target;
            const container = el.closest('[data-room-id]');
            const keys = this.gridRowKeys(room);
            const currentKey = rowKind === 'draft' ? 'draft' : ('line-' + rowRef.id);
            const idx = keys.indexOf(currentKey);
            const hasSelection = el.selectionStart !== el.selectionEnd;
            const atStart = el.selectionStart === 0 && el.selectionEnd === 0;
            const atEnd = el.selectionStart === el.value.length && el.selectionEnd === el.value.length;

            const gotoNextRowQty = () => {
                this.commitRow(room, rowKind, rowRef);
                this.focusAndSelect(this.gridCell(container, keys[idx + 1] ?? 'draft', 'qty'));
            };

            if (key === 'Enter') { event.preventDefault(); gotoNextRowQty(); return; }

            if (key === 'Tab') {
                if (event.shiftKey || cellKind === 'qty') return;
                event.preventDefault();
                gotoNextRowQty();
                return;
            }

            if (key === 'ArrowRight') {
                if (hasSelection || !atEnd) return;
                event.preventDefault();
                if (cellKind === 'qty') this.focusAndSelect(this.gridCell(container, currentKey, 'description'));
                else gotoNextRowQty();
                return;
            }

            if (key === 'ArrowLeft') {
                if (hasSelection || !atStart) return;
                event.preventDefault();
                if (cellKind === 'description') {
                    this.focusAndSelect(this.gridCell(container, currentKey, 'qty'));
                } else if (idx > 0) {
                    this.commitRow(room, rowKind, rowRef);
                    this.focusAndSelect(this.gridCell(container, keys[idx - 1], 'description'));
                }
                return;
            }

            if (key === 'ArrowDown') {
                event.preventDefault();
                if (idx < keys.length - 1) {
                    this.commitRow(room, rowKind, rowRef);
                    this.focusAndSelect(this.gridCell(container, keys[idx + 1], cellKind));
                }
                return;
            }

            if (key === 'ArrowUp') {
                event.preventDefault();
                if (idx > 0) {
                    this.commitRow(room, rowKind, rowRef);
                    this.focusAndSelect(this.gridCell(container, keys[idx - 1], cellKind));
                }
                return;
            }
        },
        commitRow(room, rowKind, rowRef) {
            if (rowKind === 'draft') this.commitDraftRow(room);
            else this.commitExistingLineIfDirty(room, rowRef);
        },
        flashSaved(roomId) {
            this.lineSavedFlash[roomId] = true;
            setTimeout(() => { if (this.lineSavedFlash[roomId]) this.lineSavedFlash[roomId] = false; }, 1200);
        },
        // The trailing blank row. Resets IN PLACE, synchronously, before the
        // save request resolves — a fast typist starting the next row must
        // never race their own in-flight POST for this one.
        commitDraftRow(room) {
            const form = this.newLine[room.id];
            const description = (form.description || '').trim();
            if (!description) return; // never save an empty row
            // Blank qty ships as null, never silently defaulted to 0/1
            // (Johan, 2026-10-02).
            const payload = { property_room_id: room.id, quantity: this.qtyForPayload(form.quantity), description };
            this.newLine[room.id] = { quantity: '', description: '' };
            this.lineBusy[room.id] = true;
            fetch(`${this.baseUrl}/lines`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            }).then(async (res) => {
                if (!res.ok) { this.lineSaveError[room.id] = true; return; }
                const line = await res.json();
                const quantity = this.normalizeQtyDisplay(line.quantity);
                this.lines.push({ id: line.id, property_room_id: line.property_room_id, quantity, description: line.description, condition_key: line.condition_key ?? null, photos: [] });
                this.lineSnapshots[line.id] = { quantity, description: line.description };
                this.lineSaveError[room.id] = false;
                this.flashSaved(room.id);
            }).catch(() => { this.lineSaveError[room.id] = true; })
              .finally(() => { this.lineBusy[room.id] = false; });
        },
        // An existing (already-persisted) line, edited via the grid.
        // Dirty-checked against the last-saved snapshot so pure navigation
        // (arrowing through rows to review them) never fires a request.
        async commitExistingLineIfDirty(room, line) {
            const description = (line.description || '').trim();
            const quantity = this.normalizeQtyDisplay(line.quantity);
            const snap = this.lineSnapshots[line.id] || { quantity, description: line.description };
            if (!description) {
                // An edit can't blank an existing item's description out —
                // that's not "removing an empty row", it's erasing a real
                // one. Restore rather than silently losing it; "Remove" is
                // its own explicit control (retireLine) for that.
                line.description = snap.description;
                return;
            }
            if (snap.quantity === quantity && snap.description === description) return;
            // §11.2/§12 — a keydown commit and the @blur it triggers a moment
            // later (see the comment on _pendingLineCommits above) both reach
            // this point with the identical, not-yet-saved values. Skip the
            // second one rather than fire a redundant duplicate PUT.
            const signature = quantity + '|' + description;
            if (this._pendingLineCommits[line.id] === signature) return;
            this._pendingLineCommits[line.id] = signature;
            try {
                const res = await fetch(`${this.baseUrl}/lines/${line.id}`, {
                    method: 'PUT',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ property_room_id: line.property_room_id, quantity: this.qtyForPayload(quantity), description }),
                });
                if (!res.ok) { this.lineSaveError[room.id] = true; return; }
                const updated = await res.json();
                line.quantity = this.normalizeQtyDisplay(updated.quantity);
                line.description = updated.description;
                this.lineSnapshots[line.id] = { quantity: line.quantity, description: updated.description };
                this.lineSaveError[room.id] = false;
                this.flashSaved(room.id);
            } catch (e) {
                this.lineSaveError[room.id] = true;
            } finally {
                delete this._pendingLineCommits[line.id];
            }
        },
        // §13 — the condition chip's own immediate commit. Optimistic
        // (sets the chip active right away) with a rollback on failure,
        // same discipline as every other click-to-set control here.
        async setLineCondition(line, key) {
            if (line.condition_key === key) return;
            const previous = line.condition_key;
            line.condition_key = key;
            try {
                const res = await fetch(`${this.baseUrl}/lines/${line.id}`, {
                    method: 'PUT',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        property_room_id: line.property_room_id,
                        quantity: this.qtyForPayload(this.normalizeQtyDisplay(line.quantity)),
                        description: line.description,
                        condition_key: key,
                    }),
                });
                if (!res.ok) { line.condition_key = previous; return; }
                const updated = await res.json();
                line.condition_key = updated.condition_key;
                this.lineSnapshots[line.id] = { quantity: this.normalizeQtyDisplay(updated.quantity), description: updated.description };
            } catch (e) {
                line.condition_key = previous;
            }
        },
        // §12 — "nothing in this room," the explicit counterpart to a room
        // with real lines in it. Idempotent: marking an already-marked room
        // again just refreshes who/when server-side; client state doesn't
        // change. No "unmark" control — same one-way shape as rental-
        // inspections' own markRoomNa(); if the agent later adds a real
        // item, the room satisfies the completion gate through that line
        // instead, and the earlier mark simply stops mattering.
        isRoomMarkedEmpty(roomId) { return this.markedEmptyRoomIds.has(Number(roomId)); },
        async markRoomEmpty(room) {
            this.markRoomBusy[room.id] = true;
            try {
                const res = await fetch(`${this.baseUrl}/rooms/${room.id}/mark-empty`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                });
                if (!res.ok) return;
                this.markedEmptyRoomIds.add(Number(room.id));
            } finally {
                this.markRoomBusy[room.id] = false;
            }
        },
        async retireLine(line) {
            if (!confirm('Remove this item? It stays in the record, marked removed.')) return;
            const res = await fetch(`${this.baseUrl}/lines/${line.id}/retire`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
            });
            if (!res.ok) return;
            this.lines = this.lines.filter(l => l.id !== line.id);
        },

        openTagger(line) {
            this.tagger = { open: true, mode: 'line', line, photo: null, photos: this.photoUploader().roomPhotos(line.property_room_id), lines: [] };
        },
        openTaggerForPhoto(room, photo) {
            this.tagger = { open: true, mode: 'photo', line: null, photo, photos: [], lines: this.linesFor(room.id) };
        },
        isTagged(line, photo) {
            if (!line || !photo) return false;
            return (line.photos || []).includes(photo.id);
        },
        async toggleTag(line, photo) {
            const tagged = this.isTagged(line, photo);
            const method = tagged ? 'DELETE' : 'POST';
            const res = await fetch(`${this.baseUrl}/lines/${line.id}/photos/${photo.id}`, {
                method,
                headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
            });
            if (!res.ok) return;
            const lineRef = this.lines.find(l => l.id === line.id);
            const photoRef = this.photoUploader().photos.find(p => p.id === photo.id);
            if (tagged) {
                if (lineRef) lineRef.photos = lineRef.photos.filter(id => id !== photo.id);
                if (photoRef) photoRef.lines = photoRef.lines.filter(id => id !== line.id);
            } else {
                if (lineRef && !lineRef.photos.includes(photo.id)) lineRef.photos.push(photo.id);
                if (photoRef && !photoRef.lines.includes(line.id)) photoRef.lines.push(line.id);
            }
        },
    };
}
</script>
@endpush
@endif
