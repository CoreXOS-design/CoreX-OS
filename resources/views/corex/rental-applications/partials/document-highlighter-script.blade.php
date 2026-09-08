{{-- AT-392, 2026-09-08 — the highlight/note viewer logic, shared between the
     agent's own review screen (rentalReview()) and the authoriser's screen
     (rentalAuthorisationViewer()) now that the authoriser also marks up
     documents (Johan: "the auth should be able to write on the docs as well
     making notes etc."). Extracted out of review.blade.php's rentalReview()
     rather than copy-pasted a second time — this exact logic already carries
     several hard-won, easy-to-reintroduce bugs (the SVG-inside-<template>
     clone failure, the pointerdown-bubbling focus loss, the img.decode()
     restore race) that a second independently-maintained copy would risk
     drifting away from or reintroducing one at a time. Both screens spread
     this factory's return value into their own root x-data object:
       return { ...rentalDocumentHighlighter({ initialMarkedUpDocIds, currentUserId, currentUserName, currentUserRole }), ... own fields ... }
     Zero cross-references to either screen's own state (income/expense rows,
     approve/decline forms) — this factory only ever touches its own
     pages/marks/activeDocId-shaped state, which is what makes the split
     safe.

     Six-colour scheme + ownership, Johan-approved 2026-09-08:
       HUE = category (income green / expense amber / unpaid red).
       ROLE = treatment (agent = lighter fill, authoriser = darker fill; a
       third, role-neutral "underline" shade per category carries role even
       when hue can't — readable in greyscale, safe for red/green colour-
       blindness). Colours come from CSS custom properties cc4 owns the
       definition of (var(--rah-mark-<category>-<agent|authoriser>-fill, #hex)
       / var(--rah-mark-<category>-underline, #hex)) — the hex values below
       are ONLY the fallback if those properties are never defined; they are
       never read directly by anything that draws a mark. If cc4's tokens
       use different names, only the var() names below need to change.

       Every mark stores a stable id, its author (id/name/role), and its
       category. A user may edit (remove) only their own marks —
       canEditMark() gates every remove control in the shared page-rendering
       partial. A save sends `base_version` (the marks_version this tab
       loaded) and the server refuses (409) if it has since moved — a
       genuine collision is visible and recoverable (reloadHighlighter()),
       never silently overwritten. No live locking — Johan: "more machinery
       than the problem needs." --}}
<script>
function rentalDocumentHighlighter({ initialMarkedUpDocIds, currentUserId, currentUserName, currentUserRole } = {}) {
    return {
        markedUpDocIds: initialMarkedUpDocIds || [],
        currentUserId: currentUserId ?? null,
        currentUserName: currentUserName || '',
        currentUserRole: currentUserRole === 'authoriser' ? 'authoriser' : 'agent',

        activeDocId: null,
        activeTool: 'highlight', // default tool = highlighter (Johan)
        loading: false,
        loadError: '',
        applyError: '',
        applyErrorReason: '',
        applying: false,
        justSaved: false,
        label: '',
        firstPageUrl: '',
        remainingPagesUrl: '',
        postUrl: '',
        pages: [],
        totalPages: 0,
        // Progressive load, 2026-09-08 (Johan's decision on the measured 9.2s
        // cold-open cost) — page 1 loads first, the rest load behind it.
        // `pagesLoading` drives the "N more pages loading" banner; `_savedByPage`
        // holds ALL saved marks (raster px, keyed by page index) from the
        // first-page response so marks for not-yet-loaded pages can still be
        // restored the moment their page actually arrives — never dropped.
        pagesLoading: false,
        _savedByPage: {},
        loadedVersion: 0, // marks_version at the moment this document was (re)loaded — sent back as base_version on save
        marks: [],   // FLAT array: {id, type:'highlight', page, points:[{x,y}], width, category, authorUserId, authorName, authorRole} | {id, type:'note', page, x, y, text, category, authorUserId, authorName, authorRole}
        dirty: false,

        // Six-colour scheme — category is what the agent/authoriser picks;
        // role (which shade of it) comes from whoever authored the mark, not
        // whoever is currently looking at it.
        categories: [
            { key: 'income',  label: 'Income' },
            { key: 'expense', label: 'Expense' },
            { key: 'unpaid',  label: 'Unpaid' },
        ],
        markPalette: {
            income:  { agentFill: 'var(--rah-mark-income-agent-fill, #a7f3cf)',  authoriserFill: 'var(--rah-mark-income-authoriser-fill, #4ec99a)',  underline: 'var(--rah-mark-income-underline, #046c4e)' },
            expense: { agentFill: 'var(--rah-mark-expense-agent-fill, #fde8a8)', authoriserFill: 'var(--rah-mark-expense-authoriser-fill, #f2b33d)', underline: 'var(--rah-mark-expense-underline, #9a5b06)' },
            unpaid:  { agentFill: 'var(--rah-mark-unpaid-agent-fill, #fbcdc9)',  authoriserFill: 'var(--rah-mark-unpaid-authoriser-fill, #ee7c72)',  underline: 'var(--rah-mark-unpaid-underline, #a91d13)' },
        },
        activeCategory: 'income',
        fillFor(mark) {
            const p = this.markPalette[mark && mark.category] || this.markPalette.unpaid;
            return (mark && mark.authorRole === 'authoriser') ? p.authoriserFill : p.agentFill;
        },
        underlineFor(mark) {
            const p = this.markPalette[mark && mark.category] || this.markPalette.unpaid;
            return p.underline;
        },
        canEditMark(mark) {
            return mark.authorUserId === null || mark.authorUserId === undefined || mark.authorUserId === this.currentUserId;
        },
        generateMarkId() {
            try { return crypto.randomUUID(); } catch (_) { return 'm-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10); }
        },

        // Highlighter size, 2026-09-08 — Johan: "current on highlights too
        // much lines on bank statement - lines are small there." Three
        // presets, not a slider.
        //
        // 2026-09-08, revised — Johan, testing live: at Thin (and to a
        // lesser extent Medium) a mark read as "a hairline dark-green
        // stroke", no visible translucent band — only at Thick did the
        // agreed marker-pen look actually show up. Root cause: the
        // UNDERLINE was a fixed 3px at every size, so at a 10px fill it was
        // 30% of the band's own width, fully opaque next to a fill at only
        // 0.4 opacity — the underline visually dominated. Fixed alongside
        // strokesSvgFor() below: bigger fill sizes (10/16/24, Johan's own
        // numbers, checked by eye against the real document, not derived by
        // arithmetic), a slimmer fixed 2px underline so it can never
        // out-weigh even the thinnest band, and a higher fill opacity.
        strokeSizes: [
            { key: 'thin',   label: 'Thin',   px: 10 },
            { key: 'medium', label: 'Medium', px: 16 },
            { key: 'thick',  label: 'Thick',  px: 24 },
        ],
        strokeSizeKey: 'medium',
        strokeWidth: 16,
        setStrokeSize(key) {
            const s = this.strokeSizes.find(s => s.key === key);
            if (!s) return;
            this.strokeSizeKey = key;
            this.strokeWidth = s.px;
            try { localStorage.setItem('rahStrokeSizeKey', key); } catch (_) {}
        },
        drag: { active: false, page: null, points: [] },
        openNote: null,       // {page, index} — an existing note's popover open
        pendingNote: null,    // {page, x, y} — a new note being typed
        pendingNoteText: '',
        undoStack: [],
        redoStack: [],

        // Called from each screen's own init() — registers the unsaved-marks
        // beforeunload guard and restores this device's remembered stroke size.
        initHighlighterPrefs() {
            window.addEventListener('beforeunload', (e) => {
                if (this.dirty) { e.preventDefault(); e.returnValue = ''; }
            });
            try {
                const saved = localStorage.getItem('rahStrokeSizeKey');
                if (saved && this.strokeSizes.some(s => s.key === saved)) {
                    this.strokeSizeKey = saved;
                    this.strokeWidth = this.strokeSizes.find(s => s.key === saved).px;
                }
            } catch (_) {} // localStorage unavailable (private browsing etc.) — default size is fine
        },

        async openHighlighter(detail) {
            // Switching documents while unsaved marks exist — 2026-09-08: this
            // USED to auto-save silently here, which is exactly the ambiguity
            // Johan flagged ("will it automatically save?" — he could not
            // tell). Explicit-save only now, matching the viewing-pack
            // redaction tool: ask, in words, rather than act silently. Never
            // lose the marks either way — declining just keeps the viewer on
            // the current document with their marks intact.
            if (this.activeDocId !== null && this.activeDocId !== detail.documentId && this.dirty) {
                if (! confirm('You have unsaved highlights or notes on this document. Save them before switching?')) {
                    return;
                }
                await this.applyHighlights();
                if (this.applyError) return; // save failed — stay put, don't lose the marks by switching away
            }
            if (this.activeDocId === detail.documentId) {
                this.activeDocId = null; // toggle closed
                return;
            }

            this.activeDocId = detail.documentId;
            this.firstPageUrl = detail.firstPageUrl;
            this.remainingPagesUrl = detail.remainingPagesUrl;
            this.postUrl = detail.postUrl;
            this.label = detail.label || '';
            await this.loadDocument();
        },

        /** The actual fetch-and-populate — split out of openHighlighter() so reloadHighlighter() (version-conflict recovery) can rerun it without the open/close toggling logic. */
        async loadDocument() {
            this.activeTool = 'highlight';
            this.pages = [];
            this.totalPages = 0;
            this.marks = [];
            this._savedByPage = {};
            this.loadedVersion = 0;
            this.dirty = false;
            this.undoStack = [];
            this.redoStack = [];
            this.loadError = '';
            this.applyError = '';
            this.applyErrorReason = '';
            this.justSaved = false;
            this.pagesLoading = false;
            this.openNote = null;
            this.pendingNote = null;
            this.activeCategory = 'income';
            this.loading = true;
            try {
                const res = await fetch(this.firstPageUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                if (!res.ok) {
                    let msg = '';
                    try { msg = (await res.json()).error || ''; } catch (_) {}
                    this.loadError = msg || ('This document could not be opened (HTTP ' + res.status + ').');
                    this.loading = false;
                    return;
                }
                const data = await res.json();
                if (!data.page) { this.loadError = 'The document opened but produced no pages.'; this.loading = false; return; }
                this.pages = [data.page];
                this.totalPages = data.total_pages || 1;
                this.loadedVersion = data.marks_version || 0;
                // Existing saved marks come back in ONE blob (RASTER px, keyed by
                // page index) — never split per page, so a mark for a page that
                // hasn't loaded yet is never at risk of being dropped; it's just
                // restored later, the moment its page actually arrives (see
                // fetchRemainingPages() below).
                this._savedByPage = data.marks || {};
                // 2026-09-08 — `loading` flips false BEFORE restoring, not
                // after. It used to be the other way round (restore, THEN
                // reveal), which reads safer but is actually a circular
                // dependency: the page images sit inside a container gated
                // by `x-show="!loading"`, so while `loading` is still true
                // that container — and every <img> inside it — has ZERO
                // rendered size no matter how long restoreSavedMarksForPages()
                // waits for layout. No amount of polling breaks a genuine
                // deadlock. Revealing first means marks can lag the document
                // by a beat on a slow render (WHEN this document isn't
                // already cached) — real, but far better than the marks
                // silently never restoring at all, which is what actually
                // happened: reproduced consistently on the authoriser
                // screen, which mounts a second, independent Alpine
                // component (the assessment editor) at the same moment,
                // measurably slowing how long the reveal takes to actually
                // land in the DOM.
                this.loading = false;
                await this.restoreSavedMarksForPages([0]);

                if (this.totalPages > 1) {
                    this.fetchRemainingPages();
                }
            } catch (e) {
                this.loadError = 'This document could not be opened: ' + (e && e.message ? e.message : 'network error') + '.';
                this.loading = false;
            }
        },
        /**
         * Version-conflict recovery (Johan: visible and recoverable, not
         * silent — no live locking). Re-fetches the current, now-
         * authoritative state (fresh page images, the current version,
         * anyone else's marks) — but does NOT throw away marks this tab
         * drew and hadn't saved yet.
         *
         * 2026-09-08 — Johan: "nobody should lose what they just drew
         * because of a save refusal. The marks must stay on screen so the
         * user can save them after reloading." The original version just
         * called loadDocument(), which resets `marks` to whatever the
         * server already knew about — exactly the marks that caused the
         * conflict AND the one just drawn both vanished. Fixed by
         * snapshotting anything in `this.marks` whose id isn't anywhere in
         * `_savedByPage` (i.e. drawn locally since the last load/save, the
         * server has never heard of it) before reloading, then re-adding
         * it on top of the freshly-loaded state and marking the document
         * dirty again so the banner clears but Save is still live.
         */
        async reloadHighlighter() {
            const pending = this.marks.filter(m => m.id && !this._isMarkKnownToServer(m.id));
            await this.loadDocument();
            if (pending.length) {
                this.marks.push(...pending);
                this.dirty = true;
            }
        },
        _isMarkKnownToServer(id) {
            return Object.values(this._savedByPage).some(list => (list || []).some(m => m.id === id));
        },
        // Progressive load, 2026-09-08 — runs AFTER page 1 is already on
        // screen; deliberately not awaited by openHighlighter() so the viewer
        // can start reading/marking page 1 immediately. `pagesLoading` drives
        // the on-screen "N more pages loading" banner (Johan: the agent must
        // be able to SEE more pages are coming, not just guess).
        async fetchRemainingPages() {
            this.pagesLoading = true;
            try {
                const res = await fetch(this.remainingPagesUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                if (!res.ok) {
                    let msg = '';
                    try { msg = (await res.json()).error || ''; } catch (_) {}
                    this.loadError = msg || ('The rest of this document could not be loaded (HTTP ' + res.status + ').');
                    this.pagesLoading = false;
                    return;
                }
                const data = await res.json();
                const newPages = data.pages || [];
                this.pages = this.pages.concat(newPages);
                // 2026-09-08 — MUST be awaited. restoreSavedMarksForPages()
                // is itself async (per-page $nextTick + img.decode()); firing
                // it without awaiting let pagesLoading flip false — the
                // signal both the "still loading" banner and applyHighlights()'s
                // save-refusal guard key off — before restoration had
                // actually finished, so a save (or a glance at the screen)
                // right as the banner disappeared could see marks still
                // missing. Same race class as the img.decode() fix itself,
                // one layer up.
                await this.restoreSavedMarksForPages(newPages.map(p => p.index));
            } catch (e) {
                this.loadError = 'The rest of this document could not be loaded: ' + (e && e.message ? e.message : 'network error') + '.';
            }
            this.pagesLoading = false;
        },
        // Waits for an already-decoded <img> to actually have LAID OUT (a
        // non-zero clientWidth) — genuinely a different thing from decoding.
        // 2026-09-08 — root-caused the SAME "marks silently missing" symptom
        // recurring on the authoriser screen after the img.decode() fix:
        // decode() only guarantees the PIXEL DATA is ready, not that the
        // browser has finished a LAYOUT pass assigning the element real box
        // dimensions (this <img> is `max-width:100%; height:auto`, so its
        // size depends on layout, a separate pipeline stage from decode).
        // Two rAF callbacks reliably straddle a real layout/paint cycle in
        // every evergreen browser; the short poll below is pure defence for
        // the rare case that still isn't enough (e.g. a very busy main
        // thread — the authoriser screen also initialises a second,
        // independent Alpine component for its assessment editor at the
        // same moment a document is opened, which measurably slowed this
        // down in testing).
        async _waitForLayout(img) {
            await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            for (let attempt = 0; attempt < 10 && !img.clientWidth; attempt++) {
                await new Promise(resolve => setTimeout(resolve, 50));
            }
        },
        // Shared by both the first-page load and the remaining-pages load —
        // converts saved marks (RASTER px) to DISPLAY px for the given page
        // indexes, once their images have actually laid out.
        //
        // 2026-09-08 — Johan: notes (and, it turned out, highlights too)
        // silently failed to restore some of the time. Root cause: $nextTick()
        // only guarantees Alpine's OWN DOM mutation applied (the <img> tag
        // exists with its src set) — not that the browser has finished
        // DECODING it, which clientWidth/clientHeight need to be reliable.
        // Fixed with img.decode() — a real Promise that resolves only once
        // decode is complete — before reading layout metrics. Decode alone
        // still wasn't always enough (see _waitForLayout() above) — decoded
        // pixels and a finished layout pass are two different guarantees.
        async restoreSavedMarksForPages(pageIndexes) {
            for (const pageIndex of pageIndexes) {
                const saved = this._savedByPage[String(pageIndex)] || this._savedByPage[pageIndex];
                if (!saved) continue;
                await this.$nextTick();
                const img = document.querySelector('img.rah-page-img[data-page="' + pageIndex + '"]');
                if (!img) continue;
                try { if (img.decode) await img.decode(); } catch (_) {} // decode() can reject on a since-removed <img> (fast document switching) — fall through to the clientWidth guard below either way
                if (!img.clientWidth) { await this._waitForLayout(img); }
                const page = this.pages.find(p => p.index === pageIndex);
                if (!page || !img.clientWidth) continue;
                const scaleX = img.clientWidth / page.width;
                const scaleY = img.clientHeight / page.height;
                saved.forEach(m => {
                    // category/id/author survive round-trips verbatim — the
                    // server pass-through of an unchanged mark preserves them
                    // exactly (see RentalApplicationDocumentHighlightService::
                    // normalizeForStorage()). A mark with no category/author
                    // is a genuinely legacy one, saved before this scheme
                    // existed — left honestly unattributed, not guessed.
                    const common = {
                        id: m.id || null,
                        category: m.category || null,
                        authorUserId: m.author_user_id ?? null,
                        authorName: m.author_name || null,
                        authorRole: m.author_role || null,
                    };
                    if (m.type === 'note') {
                        this.marks.push({ ...common, type: 'note', page: pageIndex, x: m.x * scaleX, y: m.y * scaleY, text: m.text });
                    } else {
                        this.marks.push({
                            ...common, type: 'highlight', page: pageIndex,
                            points: (m.points || []).map(p => ({ x: p.x * scaleX, y: p.y * scaleY })),
                            width: (m.width || 26) * scaleX,
                        });
                    }
                });
            }
        },
        async closeHighlighter() {
            // Same explicit-confirm rule as openHighlighter() above — "Done"
            // must never silently save-or-discard without the viewer knowing
            // which happened.
            if (this.dirty) {
                if (! confirm('You have unsaved highlights or notes on this document. Save them before closing?')) {
                    return;
                }
                await this.applyHighlights();
                if (this.applyError) return; // save failed — stay open so nothing is lost
            }
            this.activeDocId = null;
        },
        strokesFor(p) { return this.marks.filter(m => m.type === 'highlight' && m.page === p); },
        notesFor(p) { return this.marks.filter(m => m.type === 'note' && m.page === p); },
        // 2026-09-08 — built as a markup STRING and bound via x-html on the
        // <svg> itself, deliberately NOT <template x-for>/<template x-if>
        // inside the svg (a browser parses <template> INSIDE an <svg> as
        // foreign SVG content, so it never gets the "cloneable fragment"
        // treatment real HTML <template> gets elsewhere — Alpine's clone step
        // threw a real "Failed to execute 'importNode'" error on every draw).
        // Still fully reactive: x-html re-evaluates this on every dependency
        // change exactly like x-text/x-show does, and every value here comes
        // from this component's own numeric drag/mark state, never free-typed
        // text, so there is nothing here that needs HTML-escaping.
        //
        // Six-colour scheme, 2026-09-08 — TWO polylines per stroke: the wide
        // translucent fill (the category+role colour) plus a thin, fully
        // opaque "underline" polyline offset downward — the role-neutral
        // signal that survives a bad greyscale scan or red/green colour-
        // blindness. The offset is a fixed vertical shift, not a true
        // perpendicular-to-path one: strokes on this screen are drawn over
        // roughly-horizontal statement lines, so this reads as an underline
        // for the documents the tool actually sees without needing full
        // perpendicular-vector geometry for an arbitrary drag angle.
        strokesSvgFor(p) {
            const poly = (points, color, width, opacity) =>
                '<polyline points="' + points.map(pt => Number(pt.x) + ',' + Number(pt.y)).join(' ') + '" fill="none" stroke="' + color + '" stroke-opacity="' + opacity + '" stroke-width="' + Number(width) + '" stroke-linecap="round" stroke-linejoin="round"></polyline>';
            let svg = '';
            this.strokesFor(p).forEach(m => {
                // Floor, 2026-09-08 — Johan: "with a floor so it can never
                // collapse." A mark restored from an old save (or scaled
                // down oddly by the raster<->display conversion) must still
                // read as a real band, never thin out to a hairline.
                const fillWidth = Math.max(m.width, 8);
                svg += poly(m.points, this.fillFor(m), fillWidth, 0.5);
                // Underline stays a small FIXED 2px at every size — Johan:
                // "the underline stays 1-2px and never dominates" — so even
                // the thinnest band (10px) keeps it a clearly subordinate
                // accent, not a competing stroke.
                const offset = fillWidth * 0.45;
                svg += poly(m.points.map(pt => ({ x: pt.x, y: pt.y + offset })), this.underlineFor(m), 2, 1);
            });
            if (this.drag.active && this.drag.page === p && this.activeTool === 'highlight') {
                const preview = { category: this.activeCategory, authorRole: this.currentUserRole };
                svg += poly(this.drag.points, this.fillFor(preview), Math.max(this.strokeWidth, 8), 0.5);
            }
            return svg;
        },
        /** Clears only the CURRENT USER's own marks on this page — leaving anyone else's untouched (mark ownership, Johan-approved 2026-09-08). */
        clearPage(p) {
            this.pushHistory();
            this.marks = this.marks.filter(m => m.page !== p || !this.canEditMark(m));
            this.dirty = true;
        },
        markCount() { return this.marks.length; },

        _snapshot() { return JSON.parse(JSON.stringify(this.marks)); },
        pushHistory() {
            this.undoStack.push(this._snapshot());
            if (this.undoStack.length > 100) this.undoStack.shift();
            this.redoStack = [];
        },
        canUndo() { return this.undoStack.length > 0; },
        canRedo() { return this.redoStack.length > 0; },
        undo() {
            if (!this.undoStack.length) return;
            this.redoStack.push(this._snapshot());
            this.marks = this.undoStack.pop();
            this.dirty = true;
        },
        redo() {
            if (!this.redoStack.length) return;
            this.undoStack.push(this._snapshot());
            this.marks = this.redoStack.pop();
            this.dirty = true;
        },
        /** Mark ownership, 2026-09-08 — no-ops silently if the mark isn't the current user's (or unattributed); the shared page-rendering partial already hides the control in that case, this is the same rule enforced at the one call site, not a UI decision. */
        removeMark(page, idxWithinType, type) {
            const list = type === 'note' ? this.notesFor(page) : this.strokesFor(page);
            const target = list[idxWithinType];
            if (!target || !this.canEditMark(target)) return;
            const idx = this.marks.indexOf(target);
            if (idx === -1) return;
            this.pushHistory();
            this.marks.splice(idx, 1);
            this.dirty = true;
            this.openNote = null;
        },
        toggleNotePopover(page, idx) {
            if (this.openNote && this.openNote.page === page && this.openNote.index === idx) {
                this.openNote = null;
            } else {
                this.openNote = { page, index: idx };
            }
        },
        onHighlightKey(e) {
            if (this.activeDocId === null) return;
            const mod = e.ctrlKey || e.metaKey;
            if (!mod) return;
            const k = (e.key || '').toLowerCase();
            if (k === 'z' && !e.shiftKey) { e.preventDefault(); this.undo(); }
            else if ((k === 'z' && e.shiftKey) || k === 'y') { e.preventDefault(); this.redo(); }
        },

        // Highlight drag: capture the ACTUAL path (a real marker-pen gesture),
        // not just a start/end rectangle.
        startDraw(e, page) {
            if (this.activeTool === 'note') { return; }
            try { e.currentTarget.setPointerCapture(e.pointerId); } catch (_) {}
            const r = e.currentTarget.getBoundingClientRect();
            const x = e.clientX - r.left, y = e.clientY - r.top;
            this.drag = { active: true, page, points: [{ x, y }] };
        },
        moveDraw(e, page) {
            if (!this.drag.active || this.drag.page !== page) return;
            const r = e.currentTarget.getBoundingClientRect();
            const x = e.clientX - r.left, y = e.clientY - r.top;
            const last = this.drag.points[this.drag.points.length - 1];
            // Only add a point once the cursor has actually moved a few px —
            // keeps the stored path small without losing the drawn shape.
            if (!last || Math.hypot(x - last.x, y - last.y) > 2) {
                this.drag.points.push({ x, y });
            }
        },
        endDraw(e, page) {
            if (this.activeTool === 'note') {
                if (this.pendingNote) return; // one pending note at a time
                const r = e.currentTarget.getBoundingClientRect();
                const x = e.clientX - r.left, y = e.clientY - r.top;
                this.pendingNote = { page, x, y };
                this.pendingNoteText = '';
                // 2026-09-08 — this markup lives inside x-for="page in pages"
                // — one note textarea per loaded page, ALL sharing the ref
                // name x-ref="pendingNoteInput" would resolve unreliably with
                // N pages loaded, and calling .focus() on a hidden
                // (display:none) element does nothing, silently. Fixed by
                // never relying on a ref shared across loop iterations: query
                // the ONE textarea tagged with THIS page's own index instead.
                this.$nextTick(() => {
                    const el = document.querySelector('textarea[data-pending-note-page="' + page + '"]');
                    if (el) el.focus();
                });
                return;
            }
            if (!this.drag.active || this.drag.page !== page) return;
            try { e.currentTarget.releasePointerCapture(e.pointerId); } catch (_) {}
            if (this.drag.points.length >= 2) {
                this.pushHistory();
                this.marks.push({
                    id: this.generateMarkId(), type: 'highlight', page, points: this.drag.points, width: this.strokeWidth,
                    category: this.activeCategory, authorUserId: this.currentUserId, authorName: this.currentUserName, authorRole: this.currentUserRole,
                });
                this.dirty = true;
            }
            this.drag = { active: false, page: null, points: [] };
        },
        commitNote() {
            if (!this.pendingNote) return;
            const text = this.pendingNoteText.trim();
            if (text !== '') {
                this.pushHistory();
                this.marks.push({
                    id: this.generateMarkId(), type: 'note', page: this.pendingNote.page, x: this.pendingNote.x, y: this.pendingNote.y, text,
                    category: this.activeCategory, authorUserId: this.currentUserId, authorName: this.currentUserName, authorRole: this.currentUserRole,
                });
                this.dirty = true;
            }
            this.pendingNote = null;
            this.pendingNoteText = '';
        },

        async applyHighlights() {
            if (this.applying || this.activeDocId === null) return;
            // Progressive load, 2026-09-08 — the save payload is built from
            // `this.pages` (only the pages loaded so far) and REPLACES the
            // document's whole mark set server-side. Saving while the
            // remaining pages are still loading would silently wipe out any
            // already-saved marks on those not-yet-loaded pages — exactly
            // the "silently lose it" Johan ruled out. Refuse, don't guess.
            if (this.pagesLoading) {
                this.applyError = 'Still loading the rest of this document — wait a moment, then save.';
                this.applyErrorReason = '';
                return;
            }
            this.applyError = '';
            this.applyErrorReason = '';
            this.justSaved = false;
            this.applying = true;
            try {
                const marksByPage = {};
                for (const page of this.pages) {
                    const img = document.querySelector('img.rah-page-img[data-page="' + page.index + '"]');
                    if (!img || !img.clientWidth) continue;
                    const scaleX = page.width / img.clientWidth;
                    const scaleY = page.height / img.clientHeight;

                    const toServerMark = m => ({
                        id: m.id, category: m.category,
                        // author fields are NOT sent — the server always
                        // stamps the CURRENT caller onto a genuinely new
                        // mark (unrecognised id) and never trusts a client-
                        // supplied author; for an existing id it ignores
                        // whatever the client sends entirely and echoes
                        // back its own stored copy (see
                        // RentalApplicationDocumentHighlightService::
                        // normalizeForStorage()).
                    });
                    const strokes = this.strokesFor(page.index).map(m => ({
                        ...toServerMark(m),
                        type: 'highlight',
                        points: m.points.map(p => ({ x: Math.round(p.x * scaleX), y: Math.round(p.y * scaleY) })),
                        width: Math.round(m.width * scaleX),
                    }));
                    const notes = this.notesFor(page.index).map(m => ({
                        ...toServerMark(m),
                        type: 'note',
                        x: Math.round(m.x * scaleX), y: Math.round(m.y * scaleY),
                        text: m.text,
                    }));
                    // 2026-09-08 — ALWAYS assign, even an empty array. The
                    // server requires the payload to name every one of the
                    // document's pages (see applyHighlight()'s completeness
                    // check) so it can tell "this page genuinely has zero
                    // marks" apart from "this page was never mentioned" —
                    // omitting empty pages here would make every real,
                    // complete save look incomplete and get refused.
                    marksByPage[page.index] = [...strokes, ...notes];
                }

                const res = await fetch(this.postUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ marks: marksByPage, base_version: this.loadedVersion }),
                });
                if (!res.ok) {
                    let msg = '', reason = '';
                    try { const j = await res.json(); msg = j.error || ''; reason = j.reason || ''; } catch (_) {}
                    this.applyError = msg || ('Saving failed (HTTP ' + res.status + ').');
                    this.applyErrorReason = reason;
                    this.applying = false;
                    return;
                }
                const data = await res.json().catch(() => ({}));
                this.applying = false;
                this.dirty = false;
                if (typeof data.marks_version === 'number') { this.loadedVersion = data.marks_version; }
                // Keep the document list's "Marked up" badge in sync without a full
                // reload — a reload would collapse the inline viewer.
                const hasAny = this.markCount() > 0;
                const idx = this.markedUpDocIds.indexOf(this.activeDocId);
                if (hasAny && idx === -1) this.markedUpDocIds.push(this.activeDocId);
                if (!hasAny && idx !== -1) this.markedUpDocIds.splice(idx, 1);
                // Visible proof it saved (Johan: "a silent save is indistinguishable
                // from no save") — a persistent header badge, not a message that can
                // vanish unnoticed while the viewer is scrolled away from it.
                this.justSaved = true;
                setTimeout(() => { this.justSaved = false; }, 3000);
            } catch (err) {
                this.applyError = 'Saving failed: ' + (err && err.message ? err.message : 'network error') + '.';
                this.applyErrorReason = '';
                this.applying = false;
            }
        },
    };
}
</script>
