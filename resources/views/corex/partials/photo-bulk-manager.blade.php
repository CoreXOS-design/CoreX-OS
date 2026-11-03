{{--
    The bulk photo manager (.ai/specs/rental-inventory.md section 29), shared by the inventory screen and the inspection screen.
    $kind: 'inventory' | 'inspection'   $listUrl / $targetsUrl / $actUrl: the screen's own three routes   $title: the heading
    A dense grid of every photo with a tick box each, "select all on this page" / "select all N", and an actions bar: delete selected, move selected,
    delete all, and (under "Show deleted") restore selected. Nothing is hard-deleted.
--}}
@once
<script>
window.corexPhotoBulk = window.corexPhotoBulk || function (cfg) {
    return {
        cfg, open: false, loaded: false, loading: false, error: '', info: '',
        items: [], total: 0, page: 1, pages: 1, rooms: [], canEdit: false, lockedReason: '', trashedTotal: 0, maxPerAction: 2000,
        f: { room: '', from: '', to: '', deleted: false, sort: 'newest' },
        picked: {}, allN: false, busy: false,
        move: { show: false, loading: false, targets: [], rooms: [], targetType: 'inspection', targetId: '', room: 'keep' },
        dlg: { show: false, kind: '', typed: '' },
        csrf() { return (document.querySelector('meta[name="csrf-token"]') || {}).content || ''; },
        toggleOpen() { this.open = !this.open; if (this.open && !this.loaded) this.load(1); },
        qs(page) {
            const p = new URLSearchParams({ page: page || this.page, sort: this.f.sort });
            if (this.f.room !== '') p.set('room', this.f.room);
            if (this.f.from) p.set('from', this.f.from);
            if (this.f.to) p.set('to', this.f.to);
            if (this.f.deleted) p.set('deleted', '1');
            return p.toString();
        },
        async load(page) {
            this.loading = true; this.error = '';
            try {
                const r = await fetch(this.cfg.listUrl + '?' + this.qs(page), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                if (!r.ok) throw new Error((await r.json().catch(() => ({}))).message || ('Could not load the photos (' + r.status + ')'));
                const d = await r.json();
                this.items = d.items; this.total = d.total; this.page = d.page; this.pages = d.pages; this.rooms = d.rooms;
                this.canEdit = d.can_edit; this.lockedReason = d.locked_reason || ''; this.trashedTotal = d.trashed_total || 0; this.maxPerAction = d.max_per_action || 2000;
                this.loaded = true;
                if (this.allN) { const p = {}; this.items.forEach((i) => { p[i.id] = true; }); this.picked = p; }
                else this.picked = Object.fromEntries(Object.keys(this.picked).filter((id) => this.items.some((i) => String(i.id) === id)).map((id) => [id, true]));
            } catch (e) { this.error = e.message; } finally { this.loading = false; }
        },
        refilter() { this.picked = {}; this.allN = false; this.load(1); },
        goto(p) { if (p >= 1 && p <= this.pages) this.load(p); },
        get pickedCount() { return this.allN ? this.total : Object.keys(this.picked).length; },
        get pageAllPicked() { return this.items.length > 0 && this.items.every((i) => this.picked[i.id]); },
        togglePage() { if (this.pageAllPicked) { this.items.forEach((i) => delete this.picked[i.id]); this.picked = { ...this.picked }; this.allN = false; } else { const p = { ...this.picked }; this.items.forEach((i) => { p[i.id] = true; }); this.picked = p; } },
        toggle(i) { const p = { ...this.picked }; if (p[i.id]) delete p[i.id]; else p[i.id] = true; this.picked = p; this.allN = false; },
        selectAllN() { this.allN = true; const p = {}; this.items.forEach((i) => { p[i.id] = true; }); this.picked = p; },
        clear() { this.picked = {}; this.allN = false; },
        body(extra) {
            return Object.assign({
                scope: this.allN ? 'filtered' : 'ids', ids: this.allN ? [] : Object.keys(this.picked).map(Number),
                expected_count: this.allN ? this.total : null,
                filters: { room: this.f.room, from: this.f.from, to: this.f.to, sort: this.f.sort },
            }, extra);
        },
        async send(payload) {
            this.busy = true; this.error = ''; this.info = '';
            try {
                const r = await fetch(this.cfg.actUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf(), 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(payload) });
                const d = await r.json().catch(() => ({}));
                if (!r.ok) throw new Error(d.message || ('That did not work (' + r.status + ')'));
                this.info = d.message; this.clear(); this.move.show = false; this.dlg.show = false;
                await this.load(this.page);
                window.dispatchEvent(new CustomEvent('corex-photos-changed', { detail: { kind: this.cfg.kind } }));
            } catch (e) { this.error = e.message; } finally { this.busy = false; }
        },
        askDelete(all) { this.dlg = { show: true, kind: all ? 'all' : 'selected', typed: '' }; if (all) { this.allN = true; } },
        get deleteCount() { return this.dlg.kind === 'all' ? this.total : this.pickedCount; },
        get typedOk() { return this.dlg.kind !== 'all' || String(this.dlg.typed).trim() === String(this.total); },
        cancelDlg() { if (this.dlg.kind === 'all') { this.allN = false; this.picked = {}; } this.dlg.show = false; },
        confirmDelete() { if (this.typedOk) this.send(this.body({ action: 'delete' })); },
        restoreSelected() { this.send(this.body({ action: 'restore' })); },
        async openMove() {
            this.move.show = true; this.move.loading = true; this.move.room = 'keep'; this.move.targetId = '';
            try {
                const r = await fetch(this.cfg.targetsUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                const d = await r.json();
                this.move.rooms = d.rooms || [];
                this.move.targets = (this.cfg.kind === 'inventory' ? d.inspections : d.inventories) || [];
                this.move.targetType = this.cfg.kind === 'inventory' ? 'inspection' : (this.move.targets.length ? 'inventory' : 'room');
                const first = this.move.targets.find((t) => t.open);
                this.move.targetId = first ? String(first.id) : '';
            } catch (e) { this.error = 'Could not load where photos can go.'; } finally { this.move.loading = false; }
        },
        get canMove() { return this.move.targetType === 'room' || this.move.targetId !== ''; },
        confirmMove() {
            if (!this.canMove) return;
            this.send(this.body({ action: 'move', target_type: this.move.targetType, target_id: this.move.targetType === 'room' ? null : Number(this.move.targetId), room: this.move.room }));
        },
        init() { this.$watch('f.deleted', () => this.refilter()); },
    };
};
</script>
@endonce

<section class="rounded-lg mb-3" style="border:1px solid var(--border); background: var(--surface);" data-qa="photo-bulk-{{ $kind }}"
         x-data="corexPhotoBulk({ kind: '{{ $kind }}', listUrl: @js($listUrl), targetsUrl: @js($targetsUrl), actUrl: @js($actUrl) })">
    <button type="button" class="w-full flex items-center justify-between px-3 py-2 text-left" @click="toggleOpen()" data-qa="photo-bulk-toggle">
        <span class="text-sm font-semibold" style="color: var(--text-primary);">{{ $title }}</span>
        <span class="text-xs" style="color: var(--text-muted);"><span x-show="loaded" x-text="total + ' photo' + (total === 1 ? '' : 's')"></span> <span x-text="open ? '▲ close' : '▼ select, move or delete'"></span></span>
    </button>

    <div x-show="open" x-cloak class="px-3 pb-3 space-y-2">
        <p x-show="error" x-cloak class="text-xs font-semibold" style="color:#ef4444;" x-text="error" data-qa="photo-bulk-error"></p>
        <p x-show="info" x-cloak class="text-xs font-semibold" style="color:#16a34a;" x-text="info" data-qa="photo-bulk-info"></p>
        <p x-show="loaded && !canEdit && lockedReason" x-cloak class="text-xs" style="color: var(--text-muted);" x-text="lockedReason"></p>

        {{-- filters: one line --}}
        <div class="flex flex-wrap items-end gap-2 text-xs">
            <label class="flex flex-col gap-0.5"><span style="color:var(--text-muted);">Room</span>
                <select x-model="f.room" @change="refilter()" class="rounded border px-1 py-1" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);" data-qa="photo-bulk-room">
                    <option value="">All rooms</option><option value="none">No room</option>
                    <template x-for="r in rooms" :key="r.id"><option :value="r.id" x-text="r.label"></option></template>
                </select></label>
            <label class="flex flex-col gap-0.5"><span style="color:var(--text-muted);">From</span><input type="date" x-model="f.from" @change="refilter()" class="rounded border px-1 py-1" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);"></label>
            <label class="flex flex-col gap-0.5"><span style="color:var(--text-muted);">To</span><input type="date" x-model="f.to" @change="refilter()" class="rounded border px-1 py-1" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);"></label>
            <label class="flex flex-col gap-0.5"><span style="color:var(--text-muted);">Order</span>
                <select x-model="f.sort" @change="refilter()" class="rounded border px-1 py-1" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);"><option value="newest">Newest first</option><option value="oldest">Oldest first</option></select></label>
            <label class="flex items-center gap-1 pb-1"><input type="checkbox" x-model="f.deleted" data-qa="photo-bulk-show-deleted"> <span>Show deleted (<span x-text="trashedTotal"></span>)</span></label>
        </div>

        {{-- selection + actions bar --}}
        <div class="flex flex-wrap items-center gap-2 text-xs rounded-md px-2 py-1.5" style="background: var(--surface-2);" x-show="loaded && items.length" x-cloak>
            <label class="flex items-center gap-1" x-show="canEdit" x-cloak><input type="checkbox" :checked="pageAllPicked" @change="togglePage()" data-qa="photo-bulk-select-page"> <span>Select all on this page (<span x-text="items.length"></span>)</span></label>
            <button type="button" class="font-semibold underline" style="color: var(--brand-button,#0ea5e9);" x-show="canEdit && pageAllPicked && total > items.length && !allN" x-cloak @click="selectAllN()" data-qa="photo-bulk-select-all" x-text="'Select all ' + total"></button>
            <span x-show="pickedCount > 0" x-cloak class="font-semibold" style="color: var(--text-primary);" data-qa="photo-bulk-count" x-text="pickedCount + ' selected'"></span>
            <button type="button" x-show="pickedCount > 0" x-cloak class="underline" @click="clear()">Clear</button>
            <span class="flex-1"></span>
            <template x-if="canEdit && !f.deleted">
                <span class="flex flex-wrap items-center gap-2">
                    <button type="button" :disabled="pickedCount === 0 || busy" @click="askDelete(false)" class="rounded px-2 py-1 font-semibold text-white disabled:opacity-40" style="background:#dc2626;" data-qa="photo-bulk-delete-selected">Delete selected</button>
                    <button type="button" :disabled="pickedCount === 0 || busy" @click="openMove()" class="rounded px-2 py-1 font-semibold disabled:opacity-40" style="border:1px solid var(--border); background:var(--surface); color:var(--text-primary);" data-qa="photo-bulk-move">{{ $kind === 'inventory' ? 'Move selected to inspection…' : 'Move selected…' }}</button>
                    <button type="button" :disabled="total === 0 || busy" @click="askDelete(true)" class="rounded px-2 py-1 font-semibold disabled:opacity-40" style="border:1px solid #dc2626; color:#dc2626; background:var(--surface);" data-qa="photo-bulk-delete-all" x-text="'Delete all ' + total + '…'"></button>
                </span>
            </template>
            <template x-if="canEdit && f.deleted">
                <button type="button" :disabled="pickedCount === 0 || busy" @click="restoreSelected()" class="rounded px-2 py-1 font-semibold text-white disabled:opacity-40" style="background:#0ea5e9;" data-qa="photo-bulk-restore">Restore selected</button>
            </template>
        </div>

        <p x-show="loading" x-cloak class="text-xs" style="color: var(--text-muted);">Loading…</p>
        <p x-show="loaded && !loading && !items.length" x-cloak class="text-xs" style="color: var(--text-muted);" x-text="f.deleted ? 'No deleted photos.' : 'No photos match.'"></p>

        {{-- the grid --}}
        <div class="grid gap-1.5" style="grid-template-columns: repeat(auto-fill, minmax(92px, 1fr));" data-qa="photo-bulk-grid">
            <template x-for="i in items" :key="i.id">
                <div class="relative rounded overflow-hidden cursor-pointer" :style="'aspect-ratio:1/1; background:var(--surface-3); border:2px solid ' + (picked[i.id] ? 'var(--brand-button,#0ea5e9)' : 'transparent')" @click="canEdit && toggle(i)" :data-qa="'photo-bulk-item-' + i.id">
                    <img :src="i.thumb" loading="lazy" class="w-full h-full object-cover" alt="" :style="i.deleted ? 'opacity:.45' : ''">
                    <input type="checkbox" x-show="canEdit" class="absolute top-1 left-1" :checked="!!picked[i.id]" @click.stop="toggle(i)" :data-qa="'photo-bulk-tick-' + i.id">
                    <span class="absolute bottom-0 left-0 right-0 px-1 text-[9px] leading-tight text-white truncate" style="background:rgba(0,0,0,.55);" x-text="(i.room || 'No room') + ' · ' + (i.when || '')"></span>
                    <span x-show="i.shared" class="absolute top-1 right-1 text-[9px] font-semibold text-white rounded px-1" style="background:rgba(0,0,0,.55);" title="Shared with the inspection">Insp.</span>
                </div>
            </template>
        </div>

        {{-- pages --}}
        <div class="flex items-center gap-2 text-xs" x-show="pages > 1" x-cloak>
            <button type="button" class="rounded px-2 py-1" style="border:1px solid var(--border);" :disabled="page <= 1" @click="goto(page - 1)">‹ Prev</button>
            <span x-text="'Page ' + page + ' of ' + pages"></span>
            <button type="button" class="rounded px-2 py-1" style="border:1px solid var(--border);" :disabled="page >= pages" @click="goto(page + 1)">Next ›</button>
        </div>
    </div>

    {{-- delete confirm: one confirm with the count; "delete all" makes you type the count back --}}
    <div x-show="dlg.show" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" style="background:rgba(0,0,0,.45);" data-qa="photo-bulk-delete-dialog">
        <div class="rounded-lg p-4 max-w-sm w-full mx-4" style="background:var(--surface); border:1px solid var(--border);">
            <h3 class="text-sm font-semibold mb-2" style="color:var(--text-primary);" x-text="'Delete ' + deleteCount + ' photo' + (deleteCount === 1 ? '' : 's') + '?'"></h3>
            <p class="text-xs mb-2" style="color:var(--text-muted);">They are not erased - they go to <strong>Show deleted</strong> and can be restored.</p>
            <template x-if="dlg.kind === 'all'">
                <div class="mb-2"><label class="text-xs" style="color:var(--text-secondary);">Type <strong x-text="total"></strong> to confirm</label>
                    <input type="text" inputmode="numeric" x-model="dlg.typed" class="w-full rounded border px-2 py-1 text-sm mt-1" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);" data-qa="photo-bulk-typed"></div>
            </template>
            <div class="flex justify-end gap-2 text-xs">
                <button type="button" class="rounded px-3 py-1.5" style="border:1px solid var(--border);" @click="cancelDlg()">Cancel</button>
                <button type="button" class="rounded px-3 py-1.5 font-semibold text-white disabled:opacity-40" style="background:#dc2626;" :disabled="!typedOk || busy" @click="confirmDelete()" data-qa="photo-bulk-delete-confirm">Delete</button>
            </div>
        </div>
    </div>

    {{-- move picker --}}
    <div x-show="move.show" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" style="background:rgba(0,0,0,.45);" data-qa="photo-bulk-move-dialog">
        <div class="rounded-lg p-4 max-w-md w-full mx-4 space-y-2" style="background:var(--surface); border:1px solid var(--border);">
            <h3 class="text-sm font-semibold" style="color:var(--text-primary);" x-text="'Move ' + pickedCount + ' photo' + (pickedCount === 1 ? '' : 's')"></h3>
            <p x-show="move.loading" class="text-xs" style="color:var(--text-muted);">Loading…</p>
            @if($kind === 'inspection')
                <div class="text-xs flex gap-3" x-show="!move.loading">
                    <label class="flex items-center gap-1"><input type="radio" value="inventory" x-model="move.targetType" :disabled="!move.targets.length"> To the inventory</label>
                    <label class="flex items-center gap-1"><input type="radio" value="room" x-model="move.targetType"> To another room here</label>
                </div>
            @endif
            <div x-show="!move.loading && move.targetType !== 'room'">
                <label class="text-xs block mb-1" style="color:var(--text-secondary);">{{ $kind === 'inventory' ? 'Which inspection (newest first)' : 'Which inventory' }}</label>
                <select x-model="move.targetId" class="w-full rounded border px-2 py-1 text-sm" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);" data-qa="photo-bulk-target">
                    <option value="">Choose…</option>
                    <template x-for="t in move.targets" :key="t.id"><option :value="t.id" :disabled="!t.open" x-text="t.label + (t.open ? '' : ' (signed or closed - cannot take photos)')"></option></template>
                </select>
                <p x-show="!move.targets.length" class="text-xs mt-1" style="color:var(--text-muted);">{{ $kind === 'inventory' ? 'This property has no inspection you may add photos to.' : 'This property has no draft inventory.' }}</p>
            </div>
            <div x-show="!move.loading">
                <label class="text-xs block mb-1" style="color:var(--text-secondary);">Room</label>
                <select x-model="move.room" class="w-full rounded border px-2 py-1 text-sm" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);" data-qa="photo-bulk-target-room">
                    <option value="keep">Keep each photo's own room</option>
                    <template x-for="r in move.rooms" :key="r.id"><option :value="r.id" x-text="'All into: ' + r.label"></option></template>
                    <option value="none">Unassigned ({{ $kind === 'inventory' ? "the inspection's untagged photos" : 'no room' }})</option>
                </select>
                <p class="text-xs mt-1" style="color:var(--text-muted);">The same picture is used - nothing is uploaded again. Tags to inventory lines or inspection items do not carry across; file the photo to an item afterwards.</p>
            </div>
            <div class="flex justify-end gap-2 text-xs">
                <button type="button" class="rounded px-3 py-1.5" style="border:1px solid var(--border);" @click="move.show = false">Cancel</button>
                <button type="button" class="rounded px-3 py-1.5 font-semibold text-white disabled:opacity-40" style="background:var(--brand-button,#0ea5e9);" :disabled="!canMove || busy" @click="confirmMove()" data-qa="photo-bulk-move-confirm">Move</button>
            </div>
        </div>
    </div>
</section>
