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

    2026-09-22, design pass 2 (cc6): the add-line row and every item row
    share ONE grid template (qty 4.5rem | description 1fr | actions 7.5rem)
    so a room's item list reads as an aligned table, not loose boxes, at
    any item count, and lines up with the entry row beneath it. That grid
    is inline `style="display:grid; grid-template-columns:..."`, not a
    Tailwind grid-cols-[...] arbitrary-value class — the first version of
    this row (design pass 1) used exactly that utility and it rendered as
    three stacked full-width fields on the deployed box, because the
    compiled CSS bundle there predated this file and Tailwind never
    compiled the class in. Inline style has no build step to go stale.
--}}

@section('content')
{{-- §4a — the SAME shared uploader rental-inspections built
     (rental-inspections.md §20.13.4), not a second bespoke one. --}}
@if($inventory)
<script src="{{ asset_v('js/corex-photo-batch-uploader.js') }}"></script>
{{-- Alpine's :style clobber trap (rental-inspections.md §22.3b): a bound
     :style="..." REPLACES the whole style attribute on every reactive
     render rather than merging with a co-located static style="...", so
     the static declaration silently disappears the moment the bound
     expression evaluates to ''. Static declarations that share a tag with
     a :style binding live in a real class instead. --}}
<style>
    .riv-room-chevron { transition:transform .15s; }
</style>
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

        <template x-for="room in rooms" :key="room.id">
            <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
                <button type="button" class="w-full flex items-center justify-between gap-3 px-4 py-3" @click="toggleRoom(room.id)">
                    <span class="text-sm font-semibold" x-text="room.label"></span>
                    <span class="flex items-center gap-2 text-xs shrink-0" style="color: var(--text-muted);">
                        <span x-text="linesFor(room.id).length + ' item' + (linesFor(room.id).length === 1 ? '' : 's')"></span>
                        <span>·</span>
                        <span x-text="photoUploader().roomPhotos(room.id).length + ' photo' + (photoUploader().roomPhotos(room.id).length === 1 ? '' : 's')"></span>
                        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" class="riv-room-chevron" :style="openRooms[room.id] ? 'transform:rotate(90deg);' : ''"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                    </span>
                </button>

                <div x-show="openRooms[room.id]" x-collapse class="px-4 pb-4 space-y-3">
                    {{-- Line items — same 3-column layout as the entry row
                         below, so quantity/description/actions line up
                         exactly whether the room has 1 item or 20. Inline
                         `display:grid` on purpose, not a Tailwind
                         grid-cols-[...] utility — an arbitrary-value class
                         like that only exists in the compiled CSS if the
                         asset bundle was rebuilt after this file was added,
                         and this screen has already shipped once with a
                         stale bundle on the deployed box. Inline style has
                         no such dependency. --}}
                    <div>
                        <template x-for="line in linesFor(room.id)" :key="line.id">
                            <div class="text-sm" style="display:grid; grid-template-columns:4.5rem 1fr 7.5rem; gap:0.5rem; align-items:center; padding:0.375rem 0; border-bottom:1px solid var(--border);">
                                <span style="color: var(--text-secondary);" x-text="line.quantity + '×'"></span>
                                <div class="min-w-0">
                                    <span x-text="line.description"></span>
                                    <template x-if="line.photos && line.photos.length">
                                        <span class="text-xs ml-1" style="color: var(--text-muted);" x-text="'(' + line.photos.length + ' photo tag' + (line.photos.length === 1 ? '' : 's') + ')'"></span>
                                    </template>
                                </div>
                                <div style="display:flex; align-items:center; justify-content:flex-end; gap:0.5rem;">
                                    <button type="button" x-show="photoUploader().roomPhotos(room.id).length" @click="openTagger(line)" class="text-xs font-semibold" style="color: var(--brand-button,#0ea5e9);">Tag photo</button>
                                    <button type="button" @click="retireLine(line)" class="text-xs font-semibold" style="color: var(--ds-crimson,#c41e3a);">Remove</button>
                                </div>
                            </div>
                        </template>
                        <p x-show="!linesFor(room.id).length" class="text-xs py-1" style="color: var(--text-muted);">No items yet.</p>
                    </div>

                    {{-- Add line — one row: qty, description, add. Same
                         columns as the item rows above, so the entry row
                         lines up with the list it's adding to. Enter in the
                         description field adds the item; no Save button. --}}
                    <form @submit.prevent="addLine(room)" style="display:grid; grid-template-columns:4.5rem 1fr 7.5rem; gap:0.5rem; align-items:center; padding-top:0.5rem;">
                        <input type="number" min="0" x-model="newLine[room.id].quantity" placeholder="Qty"
                               class="prop-input" style="width:100%;">
                        <input type="text" x-model="newLine[room.id].description" placeholder="e.g. White wooden headboard"
                               class="prop-input" @keydown.enter.prevent="addLine(room)">
                        <button type="submit" :disabled="lineBusy[room.id]"
                                class="text-xs font-semibold rounded-md text-white" style="background:var(--brand-button,#0ea5e9); padding:0.375rem 0.75rem; justify-self:end;">Add</button>
                    </form>

                    {{-- Photos — §4a: adopts the SAME batched uploader
                         (public/js/corex-photo-batch-uploader.js) and the
                         SAME gallery-sized, count-clipped layout rental-
                         inspections settled on (rental-inspections.md
                         §20.14.3/§22.3) after four attempts got it wrong —
                         never a height-based clip, never a frame size
                         derived from a photo's own natural resolution. Tiles
                         are a fixed aspect-ratio:1/1 grid cell with
                         object-cover, so a 2560px-long-edge photo can never
                         grow the tile or the row around it. --}}
                    <div class="pt-2 space-y-1" style="border-top:1px solid var(--border);">
                        <template x-if="photoUploader().roomPhotos(room.id).length">
                            <div class="grid grid-cols-3 sm:grid-cols-5 gap-2">
                                <template x-for="photo in (roomPhotosExpanded[room.id] ? photoUploader().roomPhotos(room.id) : photoUploader().roomPhotos(room.id).slice(0, 3))" :key="photo.id">
                                    <div class="relative rounded-md overflow-hidden" style="aspect-ratio:1/1; background:var(--surface-3);">
                                        <img :src="photo.storage_path" class="w-full h-full object-cover cursor-pointer" @click="openTaggerForPhoto(room, photo)" alt="Room photo">
                                        <span x-show="photo.lines && photo.lines.length" class="absolute top-0.5 left-0.5 text-[10px] font-bold text-white rounded-full flex items-center justify-center" style="width:16px; height:16px; background:var(--brand-button,#0ea5e9);" x-text="photo.lines.length"></span>
                                        <button type="button" @click.stop="if (confirm('Archive this photo?')) photoUploader().archivePhoto(photo.id)"
                                                class="absolute top-0.5 right-0.5 w-4 h-4 rounded-full flex items-center justify-center text-xs font-bold"
                                                style="background:var(--ds-crimson,#c41e3a); color:#fff; line-height:1;" title="Archive">&times;</button>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <div class="flex items-center gap-2 flex-wrap pt-1">
                            <button type="button" x-show="photoUploader().roomPhotos(room.id).length > 3"
                                    @click="roomPhotosExpanded[room.id] = !roomPhotosExpanded[room.id]"
                                    class="text-xs font-semibold underline" style="color:var(--text-secondary);"
                                    x-text="roomPhotosExpanded[room.id] ? 'Show less' : ('Show all ' + photoUploader().roomPhotos(room.id).length)"></button>
                            <label class="text-xs font-semibold px-3 py-1.5 rounded-md cursor-pointer inline-flex items-center gap-1" style="background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);">
                                <span>&#128247;</span>
                                <span>Add photo(s)</span>
                                <input type="file" accept="image/*,.heic,.heif" multiple class="hidden" @change="photoUploader().uploadFiles($event.target.files, { property_room_id: room.id }); $event.target.value = ''">
                            </label>
                        </div>
                        {{-- Same batch progress/retry rows as the inspections
                             recording surface — real upload-progress percent,
                             a batch that fails is independently retryable. --}}
                        <template x-for="(batch, idx) in photoUploader().uploadBatches.filter(b => b.status !== 'done' && b.extraFields && Number(b.extraFields.property_room_id) === Number(room.id))" :key="idx">
                            <div class="flex items-center justify-between gap-2 text-xs px-2 py-1 rounded-md"
                                 :style="batch.status === 'failed' ? 'background:color-mix(in srgb, var(--ds-crimson) 10%, transparent);' : 'background:var(--surface-2);'">
                                <span :style="batch.status === 'failed' ? 'color:var(--ds-crimson);' : 'color:var(--text-secondary);'"
                                      x-text="batch.status === 'failed' ? (batch.files.length + ' photo(s) failed — ' + batch.error) : ('Uploading ' + batch.files.length + ' photo(s)… ' + (batch.percent || 0) + '%')"></span>
                                <button type="button" x-show="batch.status === 'failed'" @click="photoUploader().retryBatch(batch)"
                                        class="text-xs font-semibold underline" style="color:var(--text-secondary);">Retry</button>
                            </div>
                        </template>
                    </div>
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

        openRooms: {},
        newLine: {},
        lineBusy: {},
        newSpace: { space_type: '', label: '' },
        spaceBusy: false,
        spaceError: '',
        // §4a — same photo layout discipline as inspections (rental-
        // inspections.md §20.14.3): clipping is COUNT-based (first 3, "Show
        // all N"), never height-based — a height clip is what cut real
        // tiles in half on the deployed box the first time this shipped.
        roomPhotosExpanded: {},
        tagger: { open: false, mode: null, line: null, photo: null, photos: [], lines: [] },

        init() {
            this.rooms.forEach(r => this._initRoomState(r));
        },
        // Extracted so a room added live via addSpace() (never present at
        // page-load init()) gets the exact same per-room state a
        // server-seeded room gets — one place, not two copies of this setup
        // that could drift.
        _initRoomState(room) {
            this.openRooms[room.id] = this.linesFor(room.id).length === 0;
            this.newLine[room.id] = { quantity: 1, description: '' };
            this.lineBusy[room.id] = false;
        },
        toggleRoom(id) { this.openRooms[id] = !this.openRooms[id]; },
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
            } finally {
                this.spaceBusy = false;
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

        async addLine(room) {
            const form = this.newLine[room.id];
            if (!form.description || !form.description.trim()) return;
            this.lineBusy[room.id] = true;
            try {
                const res = await fetch(`${this.baseUrl}/lines`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ property_room_id: room.id, quantity: form.quantity || 0, description: form.description.trim() }),
                });
                if (!res.ok) return;
                const line = await res.json();
                this.lines.push({ id: line.id, property_room_id: line.property_room_id, quantity: line.quantity, description: line.description, photos: [] });
                this.newLine[room.id] = { quantity: 1, description: '' };
            } finally {
                this.lineBusy[room.id] = false;
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
