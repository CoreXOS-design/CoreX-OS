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
--}}

@section('content')
<div class="p-4 sm:p-6 max-w-3xl mx-auto space-y-4"
     @if($inventory) x-data="rentalInventoryCapture({{ $inventory->id }}, {{ $property->id }})" @endif>

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
            <div class="rounded-md p-3 text-xs flex items-center justify-between gap-3" style="background: var(--surface-2); color: var(--text-secondary);">
                <span>Every change here saves itself — nothing to press.</span>
                <a href="{{ route('corex.rental-inventories.show', $inventory) }}" class="font-semibold shrink-0" style="color: var(--brand-button,#0ea5e9);">Signatures &amp; complete →</a>
            </div>
        @endif

        @if($rooms->isEmpty())
            <div class="rounded-md p-4 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">
                This property has no rooms set up yet. Add rooms from the property's Inspection Items section first —
                inventory always uses the same room list as inspections, so an agent never retypes a room name.
            </div>
        @endif

        <template x-for="room in rooms" :key="room.id">
            <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
                <button type="button" class="w-full flex items-center justify-between gap-3 px-4 py-3" @click="toggleRoom(room.id)">
                    <span class="text-sm font-semibold" x-text="room.label"></span>
                    <span class="flex items-center gap-2 text-xs shrink-0" style="color: var(--text-muted);">
                        <span x-text="linesFor(room.id).length + ' item' + (linesFor(room.id).length === 1 ? '' : 's')"></span>
                        <span>·</span>
                        <span x-text="photosFor(room.id).length + ' photo' + (photosFor(room.id).length === 1 ? '' : 's')"></span>
                        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" :style="openRooms[room.id] ? 'transform:rotate(90deg);' : ''" style="transition:transform .15s;"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                    </span>
                </button>

                <div x-show="openRooms[room.id]" x-collapse class="px-4 pb-4 space-y-3">
                    {{-- Line items --}}
                    <div class="space-y-1">
                        <template x-for="line in linesFor(room.id)" :key="line.id">
                            <div class="flex items-start justify-between gap-2 py-1.5 text-sm" style="border-bottom:1px solid var(--border);">
                                <div class="min-w-0">
                                    <span class="font-semibold" x-text="line.quantity + 'x'"></span>
                                    <span x-text="line.description"></span>
                                    <template x-if="line.photos && line.photos.length">
                                        <span class="text-xs ml-1" style="color: var(--text-muted);" x-text="'(' + line.photos.length + ' photo tag' + (line.photos.length === 1 ? '' : 's') + ')'"></span>
                                    </template>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <button type="button" x-show="photosFor(room.id).length" @click="openTagger(line)" class="text-xs font-semibold" style="color: var(--brand-button,#0ea5e9);">Tag photo</button>
                                    <button type="button" @click="retireLine(line)" class="text-xs font-semibold" style="color: var(--ds-crimson,#c41e3a);">Remove</button>
                                </div>
                            </div>
                        </template>
                        <p x-show="!linesFor(room.id).length" class="text-xs py-1" style="color: var(--text-muted);">No items yet.</p>
                    </div>

                    {{-- Add line — autosaves on Enter or on leaving the description field, no Save button --}}
                    <form @submit.prevent="addLine(room)" class="grid grid-cols-[4.5rem_1fr_auto] gap-2 items-center pt-1">
                        <input type="number" min="0" x-model="newLine[room.id].quantity" placeholder="Qty"
                               class="prop-input" style="width:100%;">
                        <input type="text" x-model="newLine[room.id].description" placeholder="e.g. White wooden headboard"
                               class="prop-input" @keydown.enter.prevent="addLine(room)">
                        <button type="submit" :disabled="lineBusy[room.id]"
                                class="text-xs font-semibold px-3 py-2 rounded-md text-white shrink-0" style="background:var(--brand-button,#0ea5e9);">Add</button>
                    </form>

                    {{-- Photos --}}
                    <div class="pt-2" style="border-top:1px solid var(--border);">
                        <div class="flex flex-wrap gap-2 pt-2">
                            <template x-for="photo in photosFor(room.id)" :key="photo.id">
                                <button type="button" @click="openTaggerForPhoto(room, photo)" class="relative shrink-0" style="width:64px; height:64px;">
                                    <img :src="photo.storage_path" class="w-full h-full object-cover rounded-md" style="border:1px solid var(--border);" alt="Room photo">
                                    <span x-show="photo.lines && photo.lines.length" class="absolute -top-1 -right-1 text-[10px] font-bold text-white rounded-full flex items-center justify-center" style="width:16px; height:16px; background:var(--brand-button,#0ea5e9);" x-text="photo.lines.length"></span>
                                </button>
                            </template>
                            <label class="flex items-center justify-center rounded-md cursor-pointer shrink-0" style="width:64px; height:64px; border:1px dashed var(--border); color:var(--text-muted);">
                                <span class="text-xs text-center leading-tight" x-text="roomUploading[room.id] ? '…' : '+ Photo'"></span>
                                <input type="file" accept="image/*,.heic,.heif" multiple class="hidden" @change="uploadPhotos(room, $event.target.files); $event.target.value = ''">
                            </label>
                        </div>
                        <p x-show="uploadError[room.id]" x-cloak class="text-xs pt-1" style="color:#ef4444;" x-text="uploadError[room.id]"></p>
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
function rentalInventoryCapture(inventoryId, propertyId) {
    return {
        csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        baseUrl: `/corex/rental-inventories/${inventoryId}`,
        rooms: @json($roomsForJs),
        lines: @json($linesForJs),
        photos: @json($photosForJs),

        openRooms: {},
        newLine: {},
        lineBusy: {},
        roomUploading: {},
        uploadError: {},
        tagger: { open: false, mode: null, line: null, photo: null, photos: [], lines: [] },

        init() {
            this.rooms.forEach(r => {
                this.openRooms[r.id] = this.linesFor(r.id).length === 0;
                this.newLine[r.id] = { quantity: 1, description: '' };
                this.lineBusy[r.id] = false;
                this.roomUploading[r.id] = false;
                this.uploadError[r.id] = '';
            });
        },
        toggleRoom(id) { this.openRooms[id] = !this.openRooms[id]; },
        linesFor(roomId) { return this.lines.filter(l => Number(l.property_room_id) === Number(roomId)); },
        photosFor(roomId) { return this.photos.filter(p => Number(p.property_room_id) === Number(roomId)); },

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

        planUploadBatches(files, maxCount, maxBytes) {
            const batches = [];
            let cur = [], curBytes = 0;
            for (const f of files) {
                if (cur.length && (cur.length >= maxCount || curBytes + f.size > maxBytes)) {
                    batches.push(cur); cur = []; curBytes = 0;
                }
                cur.push(f); curBytes += f.size;
            }
            if (cur.length) batches.push(cur);
            return batches;
        },
        async uploadPhotos(room, fileList) {
            const files = Array.from(fileList || []);
            if (!files.length) return;
            this.uploadError[room.id] = '';
            this.roomUploading[room.id] = true;
            const batches = this.planUploadBatches(files, 10, 500 * 1024 * 1024);
            try {
                for (const batch of batches) {
                    const fd = new FormData();
                    fd.append('property_room_id', room.id);
                    batch.forEach(f => {
                        fd.append('photos[]', f);
                        fd.append('client_idempotency_keys[]', (crypto.randomUUID ? crypto.randomUUID() : (Date.now() + '-' + Math.random())));
                    });
                    const res = await fetch(`${this.baseUrl}/photos`, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                        body: fd,
                    });
                    if (!res.ok) { this.uploadError[room.id] = 'Some photos failed to upload — try again.'; continue; }
                    const body = await res.json();
                    (body.photos || []).forEach(p => this.photos.push({ id: p.id, property_room_id: p.property_room_id, storage_path: p.storage_path, lines: [] }));
                }
            } finally {
                this.roomUploading[room.id] = false;
            }
        },

        openTagger(line) {
            this.tagger = { open: true, mode: 'line', line, photo: null, photos: this.photosFor(line.property_room_id), lines: [] };
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
            const photoRef = this.photos.find(p => p.id === photo.id);
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
