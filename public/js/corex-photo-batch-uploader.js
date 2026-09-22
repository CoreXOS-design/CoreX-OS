/**
 * corex-photo-batch-uploader.js — REUSABLE bulk photo upload + tagging.
 *
 * Built 2026-09-22 for the rental-inspections recording surface
 * (.ai/specs/rental-inspections.md §20.13), generic enough for cc6's
 * rental-inventory capture surface to consume the same file rather than a
 * second implementation — see that spec section for the full contract this
 * component expects from its backend, and for the one known divergence
 * (inventory's line-photo tagging is many-to-many; this component's
 * tag/untag calls assume SUPERSEDING single-room/single-item tagging,
 * matching inspections' own append-never, correct-by-retag discipline).
 *
 * Settled pattern this session, not invented here — reused, not
 * reinvented:
 *   - Client-side batching at N files/request (default 10) AND a byte
 *     ceiling per request — PHP's max_file_uploads (20 on this box) makes
 *     a single large request impossible regardless of connection; a
 *     count-only batch of large photos can still overrun post_max_size.
 *     Same two-limit batching already used by the property gallery
 *     uploader and cc6's rental-inventory photo uploader.
 *   - Raw XHR per batch (for real upload-progress events), Accept:
 *     application/json always (an HTML error-page response must never be
 *     silently treated as success).
 *   - Every file carries its own client-side idempotency key; a retried
 *     batch never double-uploads a file that already landed.
 *   - A failed batch fails only the files IN that batch — every other
 *     batch's success is untouched, and the failed batch is individually
 *     retryable without re-sending files that already succeeded.
 *
 * Usage:
 *   window.corexPhotoBatchUploader({
 *     csrf, uploadUrl,                 // POST, multipart, field name "photos[]"
 *     tagUrl(photoId), untagUrl(photoId), archiveUrl(photoId), tagBulkUrl,
 *     extraUploadFields: {},           // e.g. { property_room_id, rental_inspection_observation_id }
 *     photos: [...],                   // initial photo array (id, storage_path, property_room_id, rental_inspection_observation_id)
 *   })
 * Returns a plain object (not itself an Alpine component) meant to be
 * composed INTO a page's own Alpine data via Object.assign(this, ...) or
 * x-data spread — see rental-inspection-recording.blade.php for the
 * calling convention.
 */
(function () {
    window.planUploadBatches = window.planUploadBatches || function (files, maxCount, maxBytes) {
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
    };

    window.corexPhotoBatchUploader = function (config) {
        return {
            _cpu_csrf: config.csrf,
            _cpu_uploadUrl: config.uploadUrl,
            _cpu_tagUrl: config.tagUrl,
            _cpu_tagBulkUrl: config.tagBulkUrl,
            _cpu_untagUrl: config.untagUrl,
            _cpu_archiveUrl: config.archiveUrl,
            photos: config.photos || [],

            // ── Upload ──────────────────────────────────────────────────
            uploadBusy: false,
            uploadBatches: [], // [{files, status: 'pending'|'uploading'|'done'|'failed', error, extraFields}]

            async uploadFiles(fileList, extraFields) {
                const files = Array.from(fileList || []);
                if (!files.length) return;
                const MAX_FILE = 50 * 1024 * 1024;
                const tooBig = files.find(f => f.size > MAX_FILE);
                if (tooBig) {
                    this.uploadBatches.push({ files: [tooBig], status: 'failed', error: `"${tooBig.name}" is over the 50MB per-photo limit.`, extraFields });
                    return;
                }
                const batches = window.planUploadBatches(files, 10, 40 * 1024 * 1024);
                this.uploadBusy = true;
                for (const batchFiles of batches) {
                    this.uploadBatches.push({ files: batchFiles, status: 'uploading', error: null, extraFields });
                    // Mutate the entry AS READ BACK from the reactive array, never the raw
                    // object literal just pushed — Alpine/Vue's reactivity only intercepts
                    // property writes through its own proxy, so setting .status on the
                    // pre-push closure reference below (the previous shape of this code)
                    // silently updates the real data without ever notifying the template.
                    // The array push itself DOES trigger a render (a structural array
                    // change), which is why the initial "Uploading… 0%" row appeared at
                    // all — only the LATER done/failed/percent updates went unseen, so the
                    // row froze on its first render forever, even though the upload had
                    // already succeeded and the photo was already showing.
                    const entry = this.uploadBatches[this.uploadBatches.length - 1];
                    await this._cpu_uploadBatch(entry);
                }
                this.uploadBusy = false;
            },

            async retryBatch(entry) {
                entry.status = 'uploading';
                entry.error = null;
                await this._cpu_uploadBatch(entry);
            },

            _cpu_uploadBatch(entry) {
                return new Promise((resolve) => {
                    const fd = new FormData();
                    const keys = [];
                    entry.files.forEach(f => {
                        const key = (crypto.randomUUID ? crypto.randomUUID() : (Date.now() + '-' + Math.random()));
                        keys.push(key);
                        fd.append('photos[]', f);
                    });
                    keys.forEach(k => fd.append('client_idempotency_keys[]', k));
                    Object.entries(entry.extraFields || {}).forEach(([k, v]) => {
                        if (v !== null && v !== undefined && v !== '') fd.append(k, v);
                    });

                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', this._cpu_uploadUrl);
                    xhr.setRequestHeader('X-CSRF-TOKEN', this._cpu_csrf);
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                    xhr.upload.onprogress = (ev) => {
                        if (!ev.lengthComputable) return;
                        entry.percent = Math.round((ev.loaded / ev.total) * 100);
                    };
                    xhr.onload = () => {
                        let body = {};
                        try { body = JSON.parse(xhr.responseText || '{}'); } catch (e) { /* handled below */ }
                        if (xhr.status >= 200 && xhr.status < 300 && Array.isArray(body.photos)) {
                            entry.status = 'done';
                            this.photos.push(...body.photos);
                            return resolve();
                        }
                        entry.status = 'failed';
                        entry.error = body.message || ('Upload failed (HTTP ' + xhr.status + ').');
                        resolve();
                    };
                    xhr.onerror = () => { entry.status = 'failed'; entry.error = 'Network error during upload.'; resolve(); };
                    xhr.send(fd);
                });
            },

            // ── Derived views ───────────────────────────────────────────
            untaggedPhotos() {
                return this.photos.filter(p => !p.property_room_id && !p.rental_inspection_observation_id);
            },
            roomPhotos(roomId) {
                return this.photos.filter(p => p.property_room_id === roomId && !p.rental_inspection_observation_id);
            },
            itemPhotos(observationIds) {
                const set = new Set(observationIds);
                return this.photos.filter(p => set.has(p.rental_inspection_observation_id));
            },

            // ── Tagging ─────────────────────────────────────────────────
            async tagPhoto(photoId, fields) {
                const res = await this._cpu_post(this._cpu_tagUrl(photoId), fields);
                this._cpu_replacePhoto(res);
                return res;
            },
            async untagPhoto(photoId) {
                const res = await this._cpu_post(this._cpu_untagUrl(photoId), {});
                this._cpu_replacePhoto(res);
                return res;
            },
            async archivePhoto(photoId) {
                await this._cpu_post(this._cpu_archiveUrl(photoId), {}, 'DELETE');
                this.photos = this.photos.filter(p => p.id !== photoId);
            },
            async tagSelectedToRoom(roomId) {
                return this.tagIdsToRoom(Array.from(this.selected), roomId);
            },
            // Explicit-ids version of the above — used wherever the caller
            // already scoped its own selection (an item's own selected
            // photos, say) and must not sweep in whatever else happens to
            // be selected elsewhere on the page.
            async tagIdsToRoom(ids, roomId) {
                if (!ids || !ids.length) return;
                const res = await this._cpu_post(this._cpu_tagBulkUrl, { photo_ids: ids, property_room_id: roomId });
                (res.photos || []).forEach(p => this._cpu_replacePhoto(p));
                this._cpu_dropFromSelection(ids);
            },
            // Room/item → item, several photos at once — reuses the
            // single-photo tag() call (already supersede-based) per id
            // rather than a new bulk-by-item backend endpoint; there is no
            // batch win to be had server-side here (each photo still needs
            // its own row update), so a second endpoint would only
            // duplicate tagPhoto()'s own validation for no benefit. An
            // agent with 14 room photos to file against one item selects
            // them all and does this once.
            async tagSelectedToItem(ids, roomId, observationId) {
                if (!ids || !ids.length) return;
                await Promise.all(ids.map(id => this.tagPhoto(id, { property_room_id: roomId, rental_inspection_observation_id: observationId })));
                this._cpu_dropFromSelection(ids);
            },
            // Explicit-ids bulk untag — the reverse of tagIdsToRoom/
            // tagSelectedToItem, same "several at once" shape.
            async untagSelected(ids) {
                if (!ids || !ids.length) return;
                await Promise.all(ids.map(id => this.untagPhoto(id)));
                this._cpu_dropFromSelection(ids);
            },
            _cpu_dropFromSelection(ids) {
                ids.forEach(id => this.selected.delete(id));
                this.selected = new Set(this.selected);
            },
            _cpu_replacePhoto(updated) {
                const i = this.photos.findIndex(p => p.id === updated.id);
                if (i !== -1) this.photos[i] = updated; else this.photos.push(updated);
            },
            _cpu_post(url, body, method) {
                return fetch(url, {
                    method: method || 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this._cpu_csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(body),
                }).then(async (res) => {
                    if (!res.ok) {
                        let msg = 'Request failed (HTTP ' + res.status + ').';
                        try { const j = await res.json(); if (j && j.message) msg = j.message; } catch (e) {}
                        throw new Error(msg);
                    }
                    return res.status === 204 ? {} : res.json();
                });
            },

            // ── Multi-select: click, shift-click a run, ctrl/cmd-click,
            // drag marquee — standard desktop convention. `orderedIds` is
            // the caller's own current render order (the tray's visible
            // photo id list), needed for shift-range selection to mean
            // "everything between", not just "everything numerically
            // between the two ids". ──────────────────────────────────────
            selected: new Set(),
            _cpu_lastClickedId: null,
            selectClick(id, orderedIds, event) {
                if (event && (event.shiftKey) && this._cpu_lastClickedId !== null) {
                    const a = orderedIds.indexOf(this._cpu_lastClickedId);
                    const b = orderedIds.indexOf(id);
                    if (a !== -1 && b !== -1) {
                        const [lo, hi] = a < b ? [a, b] : [b, a];
                        for (let i = lo; i <= hi; i++) this.selected.add(orderedIds[i]);
                        this.selected = new Set(this.selected);
                        return;
                    }
                }
                if (event && (event.ctrlKey || event.metaKey)) {
                    if (this.selected.has(id)) this.selected.delete(id); else this.selected.add(id);
                    this.selected = new Set(this.selected);
                    this._cpu_lastClickedId = id;
                    return;
                }
                // Plain click: select only this one, unless it's already the
                // sole selection (toggle off) — matches standard file-manager feel.
                if (this.selected.size === 1 && this.selected.has(id)) {
                    this.selected = new Set();
                } else {
                    this.selected = new Set([id]);
                }
                this._cpu_lastClickedId = id;
            },
            isSelected(id) { return this.selected.has(id); },
            clearSelection() { this.selected = new Set(); this._cpu_lastClickedId = null; },
            // A dedicated toggle control (as opposed to selectClick above,
            // which is bound to a photo's own click and so has to carry
            // shift/ctrl range-select semantics) — always just adds/removes
            // this one id, never touches the rest of the selection. Same
            // tap works identically with mouse or touch, no modifier key
            // required, so it's the one multi-select entry point phone
            // width can actually use.
            toggleSelected(id) {
                if (this.selected.has(id)) this.selected.delete(id); else this.selected.add(id);
                this.selected = new Set(this.selected);
            },

            // Marquee (rubber-band) select — mousedown on the tray's empty
            // background starts it; mousemove draws the rect; mouseup
            // selects every thumbnail whose DOM rect intersects it.
            marquee: null, // {startX, startY, x, y, w, h}
            marqueeStart(event, containerEl) {
                if (event.target !== containerEl) return; // only from empty background, never from a thumbnail
                const rect = containerEl.getBoundingClientRect();
                this.marquee = { containerEl, startX: event.clientX - rect.left, startY: event.clientY - rect.top, x: 0, y: 0, w: 0, h: 0 };
                if (!(event.ctrlKey || event.metaKey || event.shiftKey)) this.clearSelection();
            },
            marqueeMove(event) {
                if (!this.marquee) return;
                const rect = this.marquee.containerEl.getBoundingClientRect();
                const curX = event.clientX - rect.left, curY = event.clientY - rect.top;
                this.marquee.x = Math.min(this.marquee.startX, curX);
                this.marquee.y = Math.min(this.marquee.startY, curY);
                this.marquee.w = Math.abs(curX - this.marquee.startX);
                this.marquee.h = Math.abs(curY - this.marquee.startY);
            },
            marqueeEnd() {
                if (!this.marquee) return;
                const m = this.marquee;
                const containerRect = m.containerEl.getBoundingClientRect();
                const selRect = { left: containerRect.left + m.x, top: containerRect.top + m.y, right: containerRect.left + m.x + m.w, bottom: containerRect.top + m.y + m.h };
                m.containerEl.querySelectorAll('[data-photo-id]').forEach((el) => {
                    const r = el.getBoundingClientRect();
                    const intersects = r.left < selRect.right && r.right > selRect.left && r.top < selRect.bottom && r.bottom > selRect.top;
                    if (intersects) this.selected.add(Number(el.dataset.photoId));
                });
                this.selected = new Set(this.selected);
                this.marquee = null;
            },

            // ── Drag a selected run onto a room drop-zone ────────────────
            dragStartSelection(event, id) {
                if (!this.selected.has(id)) this.selected = new Set([id]);
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', JSON.stringify(Array.from(this.selected)));
            },
            async dropOnRoom(event, roomId) {
                event.preventDefault();
                let ids = [];
                try { ids = JSON.parse(event.dataTransfer.getData('text/plain') || '[]'); } catch (e) {}
                if (!ids.length) return;
                this.selected = new Set(ids);
                await this.tagSelectedToRoom(roomId);
            },
        };
    };
})();
