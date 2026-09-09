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

     Freehand redesign, 2026-09-09 — Johan, from real marked-up bank
     statements: "no lines as it strikes out." The original design was a
     horizontal band plus a solid underline, which assumed a clean swipe
     along one line of text. Nobody marks up that way — strokes wander
     across several rows, loop around a figure, overlap each other. A hard
     line drawn through crossed text reads as struck-out, colliding with
     the real strike-out feature on the assessment panel. Fixed by
     removing the underline concept ENTIRELY: a mark is now translucent
     ink only, following the actual drawn path with round caps/joins (a
     real marker pen), blended with `mix-blend-mode: multiply` (see
     strokesSvgFor()) so text underneath stays readable and overlapping
     strokes darken naturally instead of an opaque line painting over
     anything.

     Highlighter collection expansion, 2026-09-09 — Johan: "we allow an
     agency to set up which highlighters they want... an agency can have
     10 highlighters set up, each with their own label." Replaces the
     fixed three-category/six-colour scheme entirely: `highlighters` is
     the agency's own arbitrary-length collection, passed in from the
     controller as an array of `{id, label, color, role_scope, archived}`
     (RentalApplicationHighlighter::allFor()) — full CRUD on the settings
     screen, never hardcoded. EVERY highlighter is always present here,
     including archived ones — rendering an EXISTING mark
     (fillFor()/mark.highlighterId) must resolve its colour regardless of
     archived state (Johan: "an archived highlighter still renders its
     existing marks perfectly"). The drawing PICKER is the one place that
     only ever offers the CURRENT user's own active, role-visible ones
     (pickerHighlighters()) — Johan: "do not show anyone six," now "do not
     show anyone the other role's, or the archived ones." It is a single
     dropdown button, not one button per highlighter — a row of swatches
     stopped scaling once the count became agency-defined rather than a
     fixed three (see the picker markup in review.blade.php /
     authorisation/show.blade.php); the same dropdown works whether an
     agency has configured 2 highlighters or 12.

     A recoloured highlighter changes every mark drawn with it, because
     fillFor() always resolves live against `highlighters` — a saved mark
     stores `highlighterId`, never a copy of the colour (Johan: "it is the
     same pen, refilled with different ink").

     Every mark stores a stable id, its author (id/name/role), and which
     highlighter drew it. A user may edit (remove) only their own marks —
     canEditMark() gates every remove control in the shared page-rendering
     partial. A save sends `base_version` (the marks_version this tab
     loaded) and the server refuses (409) if it has since moved — a
     genuine collision is visible and recoverable (reloadHighlighter()),
     never silently overwritten. No live locking — Johan: "more machinery
     than the problem needs." --}}
<script>
function rentalDocumentHighlighter({ initialMarkedUpDocIds, currentUserId, currentUserName, currentUserRole, highlighters } = {}) {
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
        marks: [],   // FLAT array: {id, type:'highlight', page, points:[{x,y}], width, highlighterId, authorUserId, authorName, authorRole} | {id, type:'note', page, x, y, text, highlighterId, authorUserId, authorName, authorRole}
        dirty: false,

        // Highlighter collection expansion, 2026-09-09 — Johan: "an agency
        // can have 10 highlighters set up, each with their own label."
        // Replaces the fixed three-category picker: `highlighters` is the
        // agency's own arbitrary-length collection — {id, label, color,
        // role_scope, archived} — passed in from the controller
        // (RentalApplicationHighlighter::allFor()). EVERY highlighter is
        // present here, including archived ones: rendering an EXISTING mark
        // (fillFor()) must resolve its colour regardless of archived state
        // — Johan: "an archived highlighter still renders its existing
        // marks perfectly." Only the DRAWING PICKER (pickerHighlighters())
        // filters to active + role-visible — the same "don't show anyone
        // six" rule as before, now "don't show anyone the other role's, or
        // the archived ones."
        highlighters: highlighters || [],
        activeHighlighterId: null,
        pickerOpen: false,
        /** Choosable right now for a NEW mark — not archived, visible to this viewer's role (its own scope, or 'both'), in the agency's own configured order. Works identically whether an agency has 2 or 12. */
        pickerHighlighters() {
            return this.highlighters.filter(h => !h.archived && (h.role_scope === this.currentUserRole || h.role_scope === 'both'));
        },
        /** The currently-selected highlighter object, or null if none is choosable (an agency with zero highlighters configured for this role — full CRUD makes that a real, if rare, state). */
        activeHighlighter() {
            return this.highlighters.find(h => h.id === this.activeHighlighterId) || null;
        },
        selectHighlighter(id) {
            this.activeHighlighterId = id;
            this.pickerOpen = false;
        },
        /** The legend's own list — every NON-archived highlighter (so it always explains what's currently choosable, for either role) PLUS any archived highlighter that still has at least one mark actually on THIS open document, so an old mark's colour is never left unexplained just because someone tidied the settings screen. */
        legendHighlighters() {
            const usedArchivedIds = new Set(
                this.marks.filter(m => m.highlighterId !== null).map(m => m.highlighterId)
            );

            return this.highlighters.filter(h => !h.archived || usedArchivedIds.has(h.id));
        },
        /** An EXISTING mark's own colour — resolved live against `highlighters` (including archived), never a value frozen at draw time. Johan: "the same pen, refilled with different ink" — recolouring a highlighter here changes every mark drawn with it, on the next render. */
        fillFor(mark) {
            const h = this.highlighters.find(h => h.id === (mark && mark.highlighterId));

            return h ? h.color : '#94a3b8';
        },
        /** A note popover's own "which highlighter" caption — the highlighter's own label, resolved the same way fillFor() resolves its colour. */
        labelFor(mark) {
            const h = this.highlighters.find(h => h.id === (mark && mark.highlighterId));

            return h ? h.label : 'Unlabelled';
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
        // Freehand redesign, 2026-09-09 — "thickness stays, and now matters
        // more since there is no underline to carry the colour. All three
        // must read clearly as ink." With the underline gone, opacity and
        // the multiply blend (strokesSvgFor()) are what make even Thin read
        // as real ink rather than a hairline — verified by eye against a
        // real dense document, not derived by arithmetic (see the freehand
        // redesign's own verification pass).
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
            // Default to the first of THIS viewer's own choosable
            // highlighters, in the agency's own configured order — never a
            // hardcoded category. null (drawing disabled, see the toolbar)
            // if this agency has none configured for this role right now.
            const firstChoosable = this.pickerHighlighters()[0];
            this.activeHighlighterId = firstChoosable ? firstChoosable.id : null;
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
                    // highlighter_id/id/author survive round-trips verbatim
                    // — the server pass-through of an unchanged mark
                    // preserves them exactly (see
                    // RentalApplicationDocumentHighlightService::
                    // normalizeForStorage()). A mark with no
                    // highlighter_id/author is a genuinely legacy one,
                    // saved before this scheme existed — left honestly
                    // unattributed, not guessed. fillFor() falls back to a
                    // neutral grey for those rather than resolving nothing.
                    const common = {
                        id: m.id || null,
                        highlighterId: m.highlighter_id ?? null,
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
        // Delete-handle hover, freehand redesign 2026-09-09 — Johan: "every
        // stroke currently carries a black circled x... eight of them
        // scattered down the page... competes with the marks themselves."
        // Fixed by tracking which mark's INK is currently under the cursor
        // (set via @mouseover/@mouseout delegated on the <svg> itself, in
        // document-highlighter-pages.blade.php — each polyline below carries
        // pointer-events:stroke + a data-mark-id so the browser's own hit-
        // testing against the actual drawn path decides "hovering," not a
        // bounding box) and only rendering that ONE mark's remove handle.
        hoveredMarkId: null,
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
        // Freehand redesign, 2026-09-09 — ONE polyline per stroke now (the
        // underline is gone entirely), following the mark's actual drawn
        // path with round caps/joins — a real marker-pen gesture, never a
        // rectangle. `mix-blend-mode:multiply` (set in CSS on the SVG
        // itself, see document-highlighter-pages.blade.php) is what keeps
        // text underneath readable and makes overlapping strokes darken
        // naturally instead of an opaque band painting over anything —
        // Johan: "if the printed figures become hard to read, the whole
        // feature is worthless." pointer-events:stroke + data-mark-id on
        // each polyline is what makes hover-to-reveal-delete (above)
        // possible — an SVG child can enable its own hit-testing even
        // though the parent <svg> stays pointer-events:none for everything
        // else, so drawing a NEW stroke elsewhere on the page is untouched.
        strokesSvgFor(p) {
            // `mix-blend-mode:multiply` is set on EACH polyline here, not
            // once on the containing <svg> (document-highlighter-pages.blade.php)
            // — a blend mode set on the SVG container would flatten every
            // stroke into one composited layer first (plain alpha-blending
            // them together) and only THEN multiply that single result
            // against the page once. Set per-polyline instead, each stroke
            // multiplies independently against everything already beneath
            // it — other strokes AND the page image — which is what makes
            // two overlapping strokes genuinely compound and darken further,
            // not just darken once as a flattened group.
            const poly = (points, color, width, opacity, markId) =>
                '<polyline points="' + points.map(pt => Number(pt.x) + ',' + Number(pt.y)).join(' ') + '"'
                + ' fill="none" stroke="' + color + '" stroke-opacity="' + opacity + '" stroke-width="' + Number(width) + '"'
                + ' stroke-linecap="round" stroke-linejoin="round"'
                + ' style="mix-blend-mode:multiply;' + (markId ? ' cursor:pointer;' : '') + '"'
                + (markId ? ' pointer-events="stroke" data-mark-id="' + markId + '"' : '')
                + '></polyline>';
            let svg = '';
            this.strokesFor(p).forEach(m => {
                // Floor, 2026-09-08 — Johan: "with a floor so it can never
                // collapse." A mark restored from an old save (or scaled
                // down oddly by the raster<->display conversion) must still
                // read as a real band, never thin out to a hairline.
                const fillWidth = Math.max(m.width, 8);
                // Opacity raised slightly from the original 0.5 now that
                // there's no underline to lean on for legibility — verified
                // by eye against a real dense document (multiply blend
                // already darkens more assertively than plain alpha ever
                // did, so this is a small nudge, not a big compensation).
                svg += poly(m.points, this.fillFor(m), fillWidth, 0.55, m.id);
            });
            if (this.drag.active && this.drag.page === p && this.activeTool === 'highlight') {
                const preview = { highlighterId: this.activeHighlighterId };
                svg += poly(this.drag.points, this.fillFor(preview), Math.max(this.strokeWidth, 8), 0.55, null);
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
        // Defensive backstop, freehand redesign 2026-09-09 (Johan: "say how
        // you will keep that sane"). A real hand-drawn stroke, even a long
        // loopy one, is nowhere near this — a full-page zigzag at the
        // existing 2px-minimum spacing is still well under 1000 points. This
        // exists only to bound a pathological case (a very long, very slow
        // drag, or a crafted request) rather than let a single stroke grow
        // without limit; RDP simplification (below, applied at save time)
        // is what actually keeps a normal dense-document session's total
        // storage small, this is just the ceiling that can never be crossed.
        MAX_STROKE_POINTS: 800,
        moveDraw(e, page) {
            if (!this.drag.active || this.drag.page !== page) return;
            if (this.drag.points.length >= this.MAX_STROKE_POINTS) return;
            const r = e.currentTarget.getBoundingClientRect();
            const x = e.clientX - r.left, y = e.clientY - r.top;
            const last = this.drag.points[this.drag.points.length - 1];
            // Only add a point once the cursor has actually moved a few px —
            // keeps the stored path small without losing the drawn shape.
            if (!last || Math.hypot(x - last.x, y - last.y) > 2) {
                this.drag.points.push({ x, y });
            }
        },
        /**
         * Ramer–Douglas–Peucker path simplification, freehand redesign
         * 2026-09-09 — applied once at SAVE time (not while drawing, so the
         * live gesture never feels different from what gets stored), on top
         * of the existing 2px-minimum-spacing throttle above. A slow or
         * wobbly hand produces far more points than the visual shape
         * actually needs; a small epsilon (in RASTER document pixels, not
         * screen pixels, so it scales with the document's own resolution
         * rather than the viewer's zoom) removes the redundant ones while
         * staying visually identical — never a visible change to the shape,
         * only to how many points describe it.
         */
        simplifyPath(points, epsilon) {
            if (points.length < 3) return points;
            const sqEpsilon = epsilon * epsilon;
            const sqDistToSegment = (p, a, b) => {
                let dx = b.x - a.x, dy = b.y - a.y;
                if (dx === 0 && dy === 0) { dx = p.x - a.x; dy = p.y - a.y; return dx * dx + dy * dy; }
                const t = Math.max(0, Math.min(1, ((p.x - a.x) * dx + (p.y - a.y) * dy) / (dx * dx + dy * dy)));
                const projX = a.x + t * dx, projY = a.y + t * dy;
                dx = p.x - projX; dy = p.y - projY;
                return dx * dx + dy * dy;
            };
            const simplifySegment = (pts) => {
                if (pts.length < 3) return pts;
                let maxDist = 0, maxIndex = 0;
                for (let i = 1; i < pts.length - 1; i++) {
                    const d = sqDistToSegment(pts[i], pts[0], pts[pts.length - 1]);
                    if (d > maxDist) { maxDist = d; maxIndex = i; }
                }
                if (maxDist > sqEpsilon) {
                    const left = simplifySegment(pts.slice(0, maxIndex + 1));
                    const right = simplifySegment(pts.slice(maxIndex));
                    return left.slice(0, -1).concat(right);
                }
                return [pts[0], pts[pts.length - 1]];
            };
            return simplifySegment(points);
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
            // Highlighter collection expansion, 2026-09-09 — an agency can
            // archive every highlighter for a role (full CRUD makes that a
            // real, if rare, state). Refuse to create a HIGHLIGHT with no
            // colour behind it rather than silently falling back to
            // something unintended — the toolbar already disables drawing
            // in this state (see review.blade.php), this is the same rule
            // enforced at the one call site that actually creates a mark.
            // Deliberately NOT applied to notes (see commitNote() below) —
            // a stroke's entire meaning IS its colour; a note's isn't.
            if (this.drag.points.length >= 2 && this.activeHighlighterId !== null) {
                this.pushHistory();
                this.marks.push({
                    id: this.generateMarkId(), type: 'highlight', page, points: this.drag.points, width: this.strokeWidth,
                    highlighterId: this.activeHighlighterId, authorUserId: this.currentUserId, authorName: this.currentUserName, authorRole: this.currentUserRole,
                });
                this.dirty = true;
            }
            this.drag = { active: false, page: null, points: [] };
        },
        commitNote() {
            if (!this.pendingNote) return;
            const text = this.pendingNoteText.trim();
            // 2026-09-10 (cc5 regression pass, silent data loss) — a note is
            // text, not a colour. This used to require activeHighlighterId
            // !== null (copied from the highlight guard right above without
            // reconsidering whether it made sense here), so an agency
            // archiving every highlighter for a role made the Note button
            // (never disabled — see review.blade.php) type a real note that
            // silently vanished on commit: no error, textarea just closed.
            // fillFor()/labelFor() above already render a null highlighterId
            // gracefully ('#94a3b8' / 'Unlabelled'), and the server side
            // (RentalApplicationDocumentHighlightService::normalizeNewMark())
            // now accepts a note with no highlighter_id the same way — so a
            // note never needs one at all, regardless of what's archived.
            if (text !== '') {
                this.pushHistory();
                this.marks.push({
                    id: this.generateMarkId(), type: 'note', page: this.pendingNote.page, x: this.pendingNote.x, y: this.pendingNote.y, text,
                    highlighterId: this.activeHighlighterId, authorUserId: this.currentUserId, authorName: this.currentUserName, authorRole: this.currentUserRole,
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
                        id: m.id, highlighter_id: m.highlighterId,
                        // author fields are NOT sent — the server always
                        // stamps the CURRENT caller onto a genuinely new
                        // mark (unrecognised id) and never trusts a client-
                        // supplied author; for an existing id it ignores
                        // whatever the client sends entirely and echoes
                        // back its own stored copy (see
                        // RentalApplicationDocumentHighlightService::
                        // normalizeForStorage()).
                    });
                    const strokes = this.strokesFor(page.index).map(m => {
                        // RASTER px first, THEN simplify — epsilon is in the
                        // document's own resolution, not the viewer's zoom,
                        // so a stroke drawn at any screen size simplifies by
                        // the same real-world amount.
                        const rasterPoints = m.points.map(p => ({ x: Math.round(p.x * scaleX), y: Math.round(p.y * scaleY) }));
                        return {
                            ...toServerMark(m),
                            type: 'highlight',
                            points: this.simplifyPath(rasterPoints, 1.5),
                            width: Math.round(m.width * scaleX),
                        };
                    });
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
