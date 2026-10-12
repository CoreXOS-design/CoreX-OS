{{--
    ONE shared photo viewer (AT-452, .ai/specs/rentals-faults-work-orders.md §18.2). A popup over the page - never a new tab.
    Include ONCE per page (it guards itself against a second include). No framework: plain DOM, so the staff pages (Alpine), the tenant /
    owner portal (Alpine) and the contractor pages (no JS framework) all use the same file.

        CorexPhotoViewer.open({
            photos:   [{ id, url, thumb_url, alt, marks: [{id, shape, geometry, colour, by, at}], note: {text, by, at} | null }],
            index:    0,
            editable: false,                       // true only on the staff fault page, for a user who may edit the fault
            handlers: { addMark(photo, mark) -> Promise<mark>, updateMark(photo, mark) -> Promise<mark>, removeMark(photo, mark) -> Promise,
                        restoreMark(photo, mark) -> Promise<mark>, saveNote(photo, text) -> Promise<note>, clearNote(photo) -> Promise },
            returnFocus: element,
        });

    The photo objects are updated in place, so the page that opened the viewer sees the same marks and notes afterwards. Marks are an SVG
    overlay in the picture's own coordinate space (normalised 0..1) - the picture file is never drawn on or re-encoded. A circle's radius is
    relative to the picture's WIDTH, so it stays a true circle on any shape of photo.
--}}
@verbatim
<style>
    .cxpv { position: fixed; inset: 0; z-index: 100000; display: flex; flex-direction: column; background: rgba(8, 12, 20, .94); color: #f4f6fb; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: 14px; -webkit-user-select: none; user-select: none; }
    .cxpv[hidden] { display: none; }
    .cxpv button { font: inherit; color: inherit; cursor: pointer; }
    .cxpv-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 8px 12px; background: rgba(0, 0, 0, .35); }
    .cxpv-count { font-weight: 700; min-width: 64px; }
    .cxpv-sp { flex: 1 1 auto; }
    .cxpv-btn { min-width: 40px; height: 40px; padding: 0 10px; border-radius: 10px; border: 1px solid rgba(255, 255, 255, .25); background: rgba(255, 255, 255, .08); line-height: 1; }
    .cxpv-btn:hover:not(:disabled) { background: rgba(255, 255, 255, .18); }
    .cxpv-btn:disabled { opacity: .35; cursor: default; }
    .cxpv-btn.on { background: #0ea5e9; border-color: #0ea5e9; color: #fff; }
    .cxpv-btn:focus-visible, .cxpv-thumb:focus-visible, .cxpv-tick input:focus-visible, .cxpv-note textarea:focus-visible { outline: 3px solid #7dd3fc; outline-offset: 2px; }
    .cxpv-tick { display: inline-flex; align-items: center; gap: 6px; padding: 0 6px; cursor: pointer; font-weight: 600; }
    .cxpv-tick input { width: 18px; height: 18px; }
    .cxpv-stage { position: relative; flex: 1 1 auto; min-height: 0; overflow: hidden; touch-action: none; cursor: grab; }
    .cxpv-stage.zoomed { cursor: move; }
    .cxpv-stage.drawing { cursor: crosshair; }
    .cxpv-fig { position: absolute; left: 0; top: 0; transform-origin: 0 0; will-change: transform; }
    .cxpv-fig img { display: block; width: 100%; height: 100%; pointer-events: none; -webkit-user-drag: none; }
    .cxpv-fig svg { position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible; }
    .cxpv-nav { position: absolute; top: 50%; transform: translateY(-50%); width: 48px; height: 64px; font-size: 26px; z-index: 2; }
    .cxpv-prev { left: 8px; }
    .cxpv-next { right: 8px; }
    .cxpv-msg { position: absolute; left: 50%; bottom: 10px; transform: translateX(-50%); max-width: 90%; padding: 8px 14px; border-radius: 10px; background: #b3261e; color: #fff; z-index: 3; font-weight: 600; }
    .cxpv-msg[hidden] { display: none; }
    .cxpv-tools { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 8px 12px; background: rgba(0, 0, 0, .35); }
    .cxpv-tools[hidden], .cxpv-note[hidden], .cxpv-strip[hidden] { display: none; }
    .cxpv-sw { width: 30px; height: 30px; min-width: 30px; padding: 0; border-radius: 50%; border: 2px solid rgba(255, 255, 255, .55); }
    .cxpv-sw.on { border-color: #fff; box-shadow: 0 0 0 3px #0ea5e9; }
    .cxpv-note { padding: 8px 12px 10px; background: rgba(0, 0, 0, .45); max-height: 34vh; overflow: auto; }
    .cxpv-note textarea { width: 100%; box-sizing: border-box; min-height: 56px; padding: 8px 10px; border-radius: 10px; border: 1px solid rgba(255, 255, 255, .3); background: rgba(255, 255, 255, .1); color: #fff; font: inherit; resize: vertical; -webkit-user-select: text; user-select: text; }
    .cxpv-note .cxpv-text { white-space: pre-wrap; -webkit-user-select: text; user-select: text; }
    .cxpv-note .cxpv-by { opacity: .7; font-size: 12px; margin-top: 2px; }
    .cxpv-note .cxpv-row { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
    .cxpv-strip { display: flex; gap: 6px; padding: 6px 12px 10px; overflow-x: auto; background: rgba(0, 0, 0, .35); }
    .cxpv-thumb { flex: 0 0 auto; width: 64px; height: 48px; padding: 0; border-radius: 8px; border: 2px solid transparent; background: #000; overflow: hidden; opacity: .65; }
    .cxpv-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .cxpv-thumb.on { border-color: #0ea5e9; opacity: 1; }
    @media (max-width: 600px) {
        .cxpv-bar { gap: 6px; padding: 6px 8px; }
        .cxpv-btn { min-width: 44px; height: 44px; }
        .cxpv-nav { width: 40px; height: 56px; }
        .cxpv-tick span { font-size: 13px; }
    }
</style>
<script>
(function () {
    'use strict';
    if (window.CorexPhotoViewer) return;

    // ── pure maths (unit-tested in tests/js/photo-viewer.test.mjs) ──────────────────────────────────────────────────────────
    var MIN_ZOOM = 1, MAX_ZOOM = 8;
    var COLOURS = ['#e11d48', '#f59e0b', '#16a34a', '#2563eb', '#ffffff', '#111827'];

    function clamp(v, lo, hi) { return Math.min(hi, Math.max(lo, v)); }

    /** The size a picture is shown at so the whole of it fits the box (never stretched). */
    function fitSize(natW, natH, boxW, boxH) {
        if (!natW || !natH || boxW <= 0 || boxH <= 0) return { w: 0, h: 0 };
        var s = Math.min(boxW / natW, boxH / natH);
        return { w: natW * s, h: natH * s };
    }

    /** Where the figure's top-left sits on screen for a zoom z and pan (x, y), kept so the picture never leaves the box. */
    function clampPan(fig, box, z, x, y) {
        var bx = (box.w - fig.w) / 2, by = (box.h - fig.h) / 2;
        var W = fig.w * z, H = fig.h * z;
        var L = bx + x, T = by + y;
        L = W <= box.w ? (box.w - W) / 2 : clamp(L, box.w - W, 0);
        T = H <= box.h ? (box.h - H) / 2 : clamp(T, box.h - H, 0);
        return { x: L - bx, y: T - by };
    }

    /** New zoom/pan after zooming to z2 about the screen point (sx, sy) in box coordinates. */
    function zoomAt(fig, box, z, x, y, z2, sx, sy) {
        z2 = clamp(z2, MIN_ZOOM, MAX_ZOOM);
        var bx = (box.w - fig.w) / 2, by = (box.h - fig.h) / 2;
        var k = z2 / z;
        var nx = sx - bx - (sx - bx - x) * k;
        var ny = sy - by - (sy - by - y) * k;
        var p = clampPan(fig, box, z2, nx, ny);
        return { z: z2, x: p.x, y: p.y };
    }

    /** A client point -> 0..1 inside the picture (its on-screen rectangle already includes zoom and pan). */
    function toNormalised(rect, cx, cy) {
        return { x: clamp((cx - rect.left) / rect.width, 0, 1), y: clamp((cy - rect.top) / rect.height, 0, 1) };
    }

    /** Geometry of a mark being dragged out: a circle from its centre, an arrow from its tail. aspect = natH / natW. */
    function shapeFromDrag(shape, a, b, aspect) {
        if (shape === 'arrow') return { x1: a.x, y1: a.y, x2: b.x, y2: b.y };
        var dx = b.x - a.x, dy = (b.y - a.y) * aspect;   // the radius is relative to the picture WIDTH
        return { cx: a.x, cy: a.y, r: clamp(Math.sqrt(dx * dx + dy * dy), 0, 1) };
    }

    /** Moves a mark's geometry by (dx, dy) in normalised units, keeping every point inside the picture. */
    function moveGeometry(shape, g, dx, dy) {
        var out = {}, k;
        if (shape === 'arrow') {
            var minX = Math.min(g.x1, g.x2), maxX = Math.max(g.x1, g.x2), minY = Math.min(g.y1, g.y2), maxY = Math.max(g.y1, g.y2);
            dx = clamp(dx, -minX, 1 - maxX); dy = clamp(dy, -minY, 1 - maxY);
            out.x1 = g.x1 + dx; out.y1 = g.y1 + dy; out.x2 = g.x2 + dx; out.y2 = g.y2 + dy;
        } else {
            out.cx = clamp(g.cx + dx, 0, 1); out.cy = clamp(g.cy + dy, 0, 1); out.r = g.r;
        }
        for (k in out) out[k] = Math.round(out[k] * 10000) / 10000;
        return out;
    }

    /** Swipe: a mostly-horizontal drag of at least 50px. Returns -1 (previous), 1 (next) or 0. */
    function swipeDirection(dx, dy) {
        if (Math.abs(dx) < 50 || Math.abs(dx) < Math.abs(dy) * 1.5) return 0;
        return dx < 0 ? 1 : -1;
    }

    // ── the viewer ──────────────────────────────────────────────────────────────────────────────────────────────────────────
    var SVGNS = 'http://www.w3.org/2000/svg';
    var s = null;          // state of the open viewer, null while closed
    var ui = null;         // the DOM, built on first open

    function el(tag, attrs, kids) {
        var n = document.createElement(tag), k;
        for (k in (attrs || {})) {
            if (k === 'text') n.textContent = attrs[k];
            else if (k === 'class') n.className = attrs[k];
            else n.setAttribute(k, attrs[k]);
        }
        (kids || []).forEach(function (c) { n.appendChild(c); });
        return n;
    }
    function svgEl(tag, attrs) {
        var n = document.createElementNS(SVGNS, tag), k;
        for (k in (attrs || {})) n.setAttribute(k, attrs[k]);
        return n;
    }
    function fmtWhen(iso) {
        if (!iso) return '';
        var d = new Date(iso);
        return isNaN(d.getTime()) ? '' : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
    }

    function build() {
        var root = el('div', { 'class': 'cxpv', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Photo viewer', hidden: 'hidden', 'data-photo-viewer': '' });

        var count = el('div', { 'class': 'cxpv-count', 'aria-live': 'polite', 'data-pv-count': '' });
        var zoomOut = el('button', { 'class': 'cxpv-btn', type: 'button', 'aria-label': 'Zoom out', text: '−', 'data-pv-zoom-out': '' });
        var zoomReset = el('button', { 'class': 'cxpv-btn', type: 'button', 'aria-label': 'Reset zoom', text: '100%', 'data-pv-zoom-reset': '' });
        var zoomIn = el('button', { 'class': 'cxpv-btn', type: 'button', 'aria-label': 'Zoom in', text: '+', 'data-pv-zoom-in': '' });
        var tickInput = el('input', { type: 'checkbox', checked: 'checked', 'data-pv-marks-toggle': '' });
        var tick = el('label', { 'class': 'cxpv-tick' }, [tickInput, el('span', { text: "Agent's marks and notes" })]);
        var closeBtn = el('button', { 'class': 'cxpv-btn', type: 'button', 'aria-label': 'Close the photo viewer', text: '✕', 'data-pv-close': '' });
        var bar = el('div', { 'class': 'cxpv-bar' }, [count, el('div', { 'class': 'cxpv-sp' }), zoomOut, zoomReset, zoomIn, tick, closeBtn]);

        var img = el('img', { alt: '', draggable: 'false' });
        var svg = svgEl('svg', { preserveAspectRatio: 'none', 'data-pv-overlay': '' });
        var fig = el('div', { 'class': 'cxpv-fig' }, [img]);
        fig.appendChild(svg);
        var prev = el('button', { 'class': 'cxpv-btn cxpv-nav cxpv-prev', type: 'button', 'aria-label': 'Previous photo', text: '‹', 'data-pv-prev': '' });
        var next = el('button', { 'class': 'cxpv-btn cxpv-nav cxpv-next', type: 'button', 'aria-label': 'Next photo', text: '›', 'data-pv-next': '' });
        var msg = el('div', { 'class': 'cxpv-msg', role: 'alert', hidden: 'hidden', 'data-pv-msg': '' });
        var stage = el('div', { 'class': 'cxpv-stage', 'data-pv-stage': '' }, [fig, prev, next, msg]);

        var toolBtns = {
            select: el('button', { 'class': 'cxpv-btn', type: 'button', text: 'Move', 'data-pv-tool': 'select', 'aria-label': 'Select and move a mark' }),
            circle: el('button', { 'class': 'cxpv-btn', type: 'button', text: 'Circle', 'data-pv-tool': 'circle', 'aria-label': 'Draw a circle' }),
            arrow: el('button', { 'class': 'cxpv-btn', type: 'button', text: 'Arrow', 'data-pv-tool': 'arrow', 'aria-label': 'Draw an arrow' }),
        };
        var swatches = COLOURS.map(function (c) {
            var b = el('button', { 'class': 'cxpv-sw', type: 'button', 'aria-label': 'Colour ' + c, 'data-pv-colour': c });
            b.style.background = c;
            return b;
        });
        var removeBtn = el('button', { 'class': 'cxpv-btn', type: 'button', text: 'Remove mark', 'data-pv-remove-mark': '' });
        var undoBtn = el('button', { 'class': 'cxpv-btn', type: 'button', text: 'Undo remove', 'data-pv-undo-remove': '' });
        var tools = el('div', { 'class': 'cxpv-tools', hidden: 'hidden', 'data-pv-tools': '' }, [toolBtns.select, toolBtns.circle, toolBtns.arrow].concat(swatches, [removeBtn, undoBtn]));

        var note = el('div', { 'class': 'cxpv-note', hidden: 'hidden', 'data-pv-note': '' });
        var strip = el('div', { 'class': 'cxpv-strip', hidden: 'hidden', 'data-pv-strip': '' });

        [bar, stage, tools, note, strip].forEach(function (c) { root.appendChild(c); });
        document.body.appendChild(root);

        ui = { root: root, count: count, zoomReset: zoomReset, tick: tick, tickInput: tickInput, stage: stage, fig: fig, img: img, svg: svg, prev: prev, next: next, msg: msg,
               tools: tools, toolBtns: toolBtns, swatches: swatches, removeBtn: removeBtn, undoBtn: undoBtn, note: note, strip: strip, closeBtn: closeBtn };

        closeBtn.addEventListener('click', close);
        root.addEventListener('mousedown', function (e) { if (e.target === root) close(); });
        stage.addEventListener('click', function (e) { if (e.target === stage && !s.moved) close(); });   // the backdrop around the picture
        prev.addEventListener('click', function () { go(-1); });
        next.addEventListener('click', function () { go(1); });
        zoomIn.addEventListener('click', function () { zoomBy(1.5); });
        zoomOut.addEventListener('click', function () { zoomBy(1 / 1.5); });
        zoomReset.addEventListener('click', function () { setView(1, 0, 0); });
        tickInput.addEventListener('change', function () { s.marksOn = tickInput.checked; if (!s.marksOn) s.selected = null; refresh(); });
        Object.keys(toolBtns).forEach(function (k) { toolBtns[k].addEventListener('click', function () { s.tool = k; if (k !== 'select') s.selected = null; refresh(); }); });
        swatches.forEach(function (b) { b.addEventListener('click', function () { pickColour(b.getAttribute('data-pv-colour')); }); });
        removeBtn.addEventListener('click', removeSelected);
        undoBtn.addEventListener('click', undoRemove);

        stage.addEventListener('wheel', onWheel, { passive: false });
        stage.addEventListener('pointerdown', onDown);
        stage.addEventListener('pointermove', onMove);
        stage.addEventListener('pointerup', onUp);
        stage.addEventListener('pointercancel', onUp);
        stage.addEventListener('dblclick', function (e) {
            if (s.tool !== 'select' && s.editable && s.marksOn) return;
            var r = stage.getBoundingClientRect();
            if (s.z > 1) setView(1, 0, 0); else zoomTo(2.5, e.clientX - r.left, e.clientY - r.top);
        });
        document.addEventListener('keydown', onKey, true);
        window.addEventListener('resize', function () { if (s) layout(); });
    }

    function open(opts) {
        if (!opts || !opts.photos || !opts.photos.length) return;
        if (!ui) build();
        if (s) close(true);
        s = {
            photos: opts.photos, i: clamp(opts.index || 0, 0, opts.photos.length - 1),
            editable: !!opts.editable, handlers: opts.handlers || {},
            returnFocus: opts.returnFocus || document.activeElement,
            marksOn: true, tool: 'select', colour: COLOURS[0], selected: null, lastRemoved: null,
            z: 1, x: 0, y: 0, nat: { w: 0, h: 0 }, pointers: {}, gesture: null, moved: false, busy: false,
        };
        ui.tickInput.checked = true;
        ui.root.hidden = false;
        document.documentElement.setAttribute('data-pv-open', '1');
        s.prevOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        showPhoto();
        ui.closeBtn.focus();
    }

    function close(silent) {
        if (!s) return;
        var back = s.returnFocus;
        document.body.style.overflow = s.prevOverflow || '';
        document.documentElement.removeAttribute('data-pv-open');
        ui.root.hidden = true;
        ui.img.removeAttribute('src');
        s = null;
        if (!silent && back && back.focus && document.contains(back)) { try { back.focus(); } catch (e) { /* element gone */ } }
    }

    function cur() { return s.photos[s.i]; }
    function box() { return { w: ui.stage.clientWidth, h: ui.stage.clientHeight }; }
    function fig() { return { w: parseFloat(ui.fig.style.width) || 0, h: parseFloat(ui.fig.style.height) || 0 }; }

    function showPhoto() {
        var p = cur();
        s.selected = null; s.lastRemoved = null; s.z = 1; s.x = 0; s.y = 0; s.nat = { w: 0, h: 0 };
        ui.fig.style.width = '0px'; ui.fig.style.height = '0px';
        ui.img.alt = p.alt || ('Photo ' + (s.i + 1));
        // the small copy shows at once, the full picture takes over as soon as it has loaded
        ui.img.src = p.thumb_url || p.url;
        var wanted = s.i;
        if (p.url && p.url !== (p.thumb_url || p.url)) {
            var big = new Image();
            big.onload = function () { if (s && s.i === wanted) { ui.img.src = p.url; } };
            big.src = p.url;
        }
        ui.img.onload = function () { if (!s) return; s.nat = { w: ui.img.naturalWidth, h: ui.img.naturalHeight }; layout(); };
        if (ui.img.complete && ui.img.naturalWidth) { s.nat = { w: ui.img.naturalWidth, h: ui.img.naturalHeight }; layout(); }
        refresh();
    }

    /** Sizes the picture to the stage and re-applies zoom / pan; redraws the marks. */
    function layout() {
        if (!s || !s.nat.w) return;
        var b = box(), f = fitSize(s.nat.w, s.nat.h, b.w, b.h);
        ui.fig.style.width = f.w + 'px'; ui.fig.style.height = f.h + 'px';
        ui.fig.style.left = ((b.w - f.w) / 2) + 'px'; ui.fig.style.top = ((b.h - f.h) / 2) + 'px';
        var p = clampPan(f, b, s.z, s.x, s.y);
        s.x = p.x; s.y = p.y;
        applyView();
        drawMarks();
    }

    function applyView() {
        ui.fig.style.transform = 'translate(' + s.x + 'px,' + s.y + 'px) scale(' + s.z + ')';
        ui.zoomReset.textContent = Math.round(s.z * 100) + '%';
        ui.stage.classList.toggle('zoomed', s.z > 1);
    }
    function setView(z, x, y) {
        var b = box(), f = fig(), p = clampPan(f, b, z, x, y);
        s.z = z; s.x = p.x; s.y = p.y; applyView();
    }
    function zoomTo(z2, sx, sy) {
        var v = zoomAt(fig(), box(), s.z, s.x, s.y, z2, sx, sy);
        s.z = v.z; s.x = v.x; s.y = v.y; applyView();
    }
    function zoomBy(k) { var b = box(); zoomTo(s.z * k, b.w / 2, b.h / 2); }

    function go(d) {
        if (!s || s.photos.length < 2) return;
        s.i = (s.i + d + s.photos.length) % s.photos.length;
        showPhoto();
    }

    /** Everything that depends on the current photo / ticks: counter, nav, tools, note area, strip, marks. */
    function refresh() {
        var n = s.photos.length, p = cur();
        ui.count.textContent = (s.i + 1) + ' of ' + n;
        ui.prev.hidden = ui.next.hidden = n < 2;
        var edit = s.editable && s.marksOn;
        ui.tools.hidden = !edit;
        Object.keys(ui.toolBtns).forEach(function (k) { ui.toolBtns[k].classList.toggle('on', s.tool === k); });
        ui.swatches.forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-pv-colour') === (selectedMark() ? selectedMark().colour : s.colour)); });
        ui.removeBtn.disabled = !selectedMark();
        ui.undoBtn.hidden = !s.lastRemoved;
        ui.stage.classList.toggle('drawing', edit && s.tool !== 'select');
        renderNote(p, edit);
        renderStrip();
        drawMarks();
    }

    function renderNote(p, edit) {
        var box = ui.note;
        box.textContent = '';
        if (!s.marksOn) { box.hidden = true; return; }
        if (edit) {
            var ta = el('textarea', { maxlength: '500', rows: '2', 'aria-label': 'Short description of this photo', placeholder: 'Short description of this photo (the owner and the contractor will read it)', 'data-pv-note-input': '' });
            ta.value = p.note ? p.note.text : '';
            var save = el('button', { 'class': 'cxpv-btn', type: 'button', text: 'Save description', 'data-pv-note-save': '' });
            save.addEventListener('click', function () { saveNote(ta.value); });
            var row = el('div', { 'class': 'cxpv-row' }, [save]);
            if (p.note) {
                var del = el('button', { 'class': 'cxpv-btn', type: 'button', text: 'Remove description', 'data-pv-note-clear': '' });
                del.addEventListener('click', clearNote);
                row.appendChild(del);
            }
            box.appendChild(ta); box.appendChild(row);
            if (p.note) box.appendChild(el('div', { 'class': 'cxpv-by', text: p.note.by + (p.note.at ? ' · ' + fmtWhen(p.note.at) : '') }));
            box.hidden = false;
        } else if (p.note && p.note.text) {
            box.appendChild(el('div', { 'class': 'cxpv-text', text: p.note.text, 'data-pv-note-text': '' }));
            box.appendChild(el('div', { 'class': 'cxpv-by', text: p.note.by + (p.note.at ? ' · ' + fmtWhen(p.note.at) : '') }));
            box.hidden = false;
        } else box.hidden = true;
    }

    function renderStrip() {
        var strip = ui.strip;
        strip.textContent = '';
        if (s.photos.length < 2) { strip.hidden = true; return; }
        strip.hidden = false;
        s.photos.forEach(function (p, i) {
            var b = el('button', { 'class': 'cxpv-thumb' + (i === s.i ? ' on' : ''), type: 'button', 'aria-label': 'Photo ' + (i + 1), 'aria-current': i === s.i ? 'true' : 'false' }, [el('img', { src: p.thumb_url || p.url, alt: '', loading: 'lazy', draggable: 'false' })]);
            b.addEventListener('click', function () { s.i = i; showPhoto(); });
            strip.appendChild(b);
        });
    }

    // ── marks ───────────────────────────────────────────────────────────────────────────────────────────────────────────────
    function selectedMark() {
        if (s.selected == null) return null;
        var m = cur().marks.filter(function (x) { return x.id === s.selected; })[0];
        return m || null;
    }

    function drawMarks() {
        var svg = ui.svg;
        svg.textContent = '';
        if (!s.nat.w) return;
        var W = 1000, H = 1000 * s.nat.h / s.nat.w;
        svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
        if (!s.marksOn) return;
        var sw = 5;
        var list = cur().marks.slice();
        if (s.gesture && s.gesture.kind === 'draw' && s.gesture.draft) list.push(s.gesture.draft);
        list.forEach(function (m) {
            var g = svgEl('g', { 'data-mark-id': m.id == null ? 'draft' : m.id, 'data-shape': m.shape });
            var geo = m.geometry, sel = m.id === s.selected;
            var parts = [];
            if (m.shape === 'arrow') {
                var x1 = geo.x1 * W, y1 = geo.y1 * H, x2 = geo.x2 * W, y2 = geo.y2 * H;
                var ang = Math.atan2(y2 - y1, x2 - x1), hl = 34, ha = 0.45;
                var hx1 = x2 - hl * Math.cos(ang - ha), hy1 = y2 - hl * Math.sin(ang - ha), hx2 = x2 - hl * Math.cos(ang + ha), hy2 = y2 - hl * Math.sin(ang + ha);
                parts.push(['line', { x1: x1, y1: y1, x2: x2, y2: y2 }]);
                parts.push(['polyline', { points: hx1 + ',' + hy1 + ' ' + x2 + ',' + y2 + ' ' + hx2 + ',' + hy2, fill: 'none' }]);
            } else {
                parts.push(['ellipse', { cx: geo.cx * W, cy: geo.cy * H, rx: geo.r * W, ry: geo.r * W, fill: 'none' }]);
            }
            if (sel) parts.forEach(function (p) {   // a white halo under the selected mark
                var halo = svgEl(p[0], Object.assign({ stroke: '#ffffff', 'stroke-width': sw + 9, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-dasharray': '1 14', fill: 'none' }, p[1]));
                g.appendChild(halo);
            });
            parts.forEach(function (p) {
                g.appendChild(svgEl(p[0], Object.assign({ stroke: m.colour, 'stroke-width': sw, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', fill: 'none' }, p[1])));
            });
            if (s.editable) parts.forEach(function (p) {   // a wide invisible target so a finger can pick the mark
                var hit = svgEl(p[0], Object.assign({ stroke: 'transparent', 'stroke-width': 34, fill: 'none', 'pointer-events': 'stroke', 'data-hit': '1' }, p[1]));
                g.appendChild(hit);
            });
            svg.appendChild(g);
        });
    }

    function markIdAt(target) {
        var n = target;
        while (n && n !== ui.svg) {
            if (n.getAttribute && n.getAttribute('data-mark-id') && n.getAttribute('data-mark-id') !== 'draft') return parseInt(n.getAttribute('data-mark-id'), 10);
            n = n.parentNode;
        }
        return null;
    }

    function say(text) {
        ui.msg.textContent = text || '';
        ui.msg.hidden = !text;
        if (text) { clearTimeout(say.t); say.t = setTimeout(function () { if (ui) ui.msg.hidden = true; }, 5000); }
    }
    function failed(e) { say((e && e.message) ? e.message : 'That could not be saved. Please try again.'); }

    function pickColour(c) {
        s.colour = c;
        var m = selectedMark();
        if (m && s.editable && s.handlers.updateMark) {
            var before = m.colour; m.colour = c; refresh();
            s.handlers.updateMark(cur(), m).then(function (srv) { if (srv && s) { m.colour = srv.colour || c; refresh(); } }).catch(function (e) { m.colour = before; if (s) refresh(); failed(e); });
        } else refresh();
    }

    function removeSelected() {
        var m = selectedMark(), p = cur();
        if (!m || !s.handlers.removeMark) return;
        p.marks = p.marks.filter(function (x) { return x !== m; });
        s.selected = null; s.lastRemoved = { photo: p, mark: m };
        refresh();
        s.handlers.removeMark(p, m).catch(function (e) { p.marks.push(m); s.lastRemoved = null; if (s) refresh(); failed(e); });
    }

    function undoRemove() {
        var r = s.lastRemoved;
        if (!r || !s.handlers.restoreMark) return;
        s.lastRemoved = null;
        s.handlers.restoreMark(r.photo, r.mark).then(function (srv) {
            r.photo.marks.push(Object.assign({}, r.mark, srv || {}));
            if (s) refresh();
        }).catch(function (e) { if (s) { s.lastRemoved = r; refresh(); } failed(e); });
        refresh();
    }

    function saveNote(text) {
        var p = cur();
        if (!s.handlers.saveNote) return;
        text = (text || '').trim();
        if (!text) { if (p.note) clearNote(); else say('Write a short description first.'); return; }
        s.handlers.saveNote(p, text).then(function (n) { p.note = n; if (s) refresh(); }).catch(failed);
    }
    function clearNote() {
        var p = cur();
        if (!s.handlers.clearNote) return;
        s.handlers.clearNote(p).then(function () { p.note = null; if (s) refresh(); }).catch(failed);
    }

    // ── pointer / wheel / keyboard ──────────────────────────────────────────────────────────────────────────────────────────
    function onWheel(e) {
        if (!s) return;
        e.preventDefault();
        var r = ui.stage.getBoundingClientRect();
        zoomTo(s.z * (e.deltaY < 0 ? 1.15 : 1 / 1.15), e.clientX - r.left, e.clientY - r.top);
    }

    function pointCount() { return Object.keys(s.pointers).length; }
    function figRect() { return ui.fig.getBoundingClientRect(); }

    function onDown(e) {
        if (!s || e.target.closest('button')) return;
        try { ui.stage.setPointerCapture(e.pointerId); } catch (x) { /* synthetic pointer */ }
        s.pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
        s.moved = false;
        if (pointCount() === 2) {
            var ids = Object.keys(s.pointers), a = s.pointers[ids[0]], b = s.pointers[ids[1]];
            s.gesture = { kind: 'pinch', d0: Math.hypot(a.x - b.x, a.y - b.y) || 1, z0: s.z };
            return;
        }
        if (pointCount() > 2) return;
        var edit = s.editable && s.marksOn;
        var hit = edit ? markIdAt(e.target) : null;
        if (edit && s.tool !== 'select') {
            var n = toNormalised(figRect(), e.clientX, e.clientY);
            s.gesture = { kind: 'draw', start: n, draft: null };
        } else if (edit && hit != null) {
            s.selected = hit;
            var m = selectedMark();
            s.gesture = { kind: 'move', start: toNormalised(figRect(), e.clientX, e.clientY), orig: JSON.parse(JSON.stringify(m.geometry)), mark: m, changed: false };
            refresh();
        } else if (s.z > 1) {
            s.gesture = { kind: 'pan', sx: e.clientX, sy: e.clientY, x0: s.x, y0: s.y };
        } else {
            s.gesture = { kind: 'swipe', sx: e.clientX, sy: e.clientY };
            if (edit && s.selected != null) { s.selected = null; refresh(); }
        }
    }

    function onMove(e) {
        if (!s || !s.pointers[e.pointerId]) return;
        s.pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
        var g = s.gesture;
        if (!g) return;
        if (g.kind === 'pinch' && pointCount() >= 2) {
            var ids = Object.keys(s.pointers), a = s.pointers[ids[0]], b = s.pointers[ids[1]];
            var r = ui.stage.getBoundingClientRect();
            zoomTo(g.z0 * (Math.hypot(a.x - b.x, a.y - b.y) / g.d0), (a.x + b.x) / 2 - r.left, (a.y + b.y) / 2 - r.top);
            s.moved = true;
        } else if (g.kind === 'pan') {
            if (Math.abs(e.clientX - g.sx) + Math.abs(e.clientY - g.sy) > 3) s.moved = true;
            setView(s.z, g.x0 + (e.clientX - g.sx), g.y0 + (e.clientY - g.sy));
        } else if (g.kind === 'swipe') {
            if (Math.abs(e.clientX - g.sx) + Math.abs(e.clientY - g.sy) > 6) s.moved = true;
        } else if (g.kind === 'draw') {
            var n = toNormalised(figRect(), e.clientX, e.clientY);
            if (Math.abs(n.x - g.start.x) + Math.abs(n.y - g.start.y) > 0.004) s.moved = true;
            g.draft = { id: null, shape: s.tool, geometry: shapeFromDrag(s.tool, g.start, n, s.nat.h / s.nat.w), colour: s.colour };
            drawMarks();
        } else if (g.kind === 'move') {
            var q = toNormalised(figRect(), e.clientX, e.clientY);
            if (Math.abs(q.x - g.start.x) + Math.abs(q.y - g.start.y) > 0.004) { s.moved = true; g.changed = true; }
            g.mark.geometry = moveGeometry(g.mark.shape, g.orig, q.x - g.start.x, q.y - g.start.y);
            drawMarks();
        }
    }

    function onUp(e) {
        if (!s || !s.pointers[e.pointerId]) return;
        delete s.pointers[e.pointerId];
        var g = s.gesture;
        if (g && g.kind === 'pinch') { if (pointCount() < 2) s.gesture = null; return; }
        if (pointCount() > 0) return;
        s.gesture = null;
        if (!g) return;
        if (g.kind === 'swipe' && s.z === 1) {
            var d = swipeDirection(e.clientX - g.sx, e.clientY - g.sy);
            if (d) go(d);
        } else if (g.kind === 'draw' && g.draft && s.moved && s.handlers.addMark) {
            var p = cur(), draft = g.draft, temp = { id: null, shape: draft.shape, geometry: draft.geometry, colour: draft.colour };
            p.marks.push(temp);
            drawMarks();
            s.handlers.addMark(p, { shape: temp.shape, geometry: temp.geometry, colour: temp.colour }).then(function (srv) {
                Object.assign(temp, srv || {});
                if (s) { s.selected = temp.id; s.tool = 'select'; refresh(); }
            }).catch(function (err) { p.marks = p.marks.filter(function (x) { return x !== temp; }); if (s) refresh(); failed(err); });
        } else if (g.kind === 'draw') {
            drawMarks();
        } else if (g.kind === 'move') {
            if (g.changed && s.handlers.updateMark) {
                var m = g.mark;
                s.handlers.updateMark(cur(), m).catch(function (err) { m.geometry = g.orig; if (s) refresh(); failed(err); });
            }
            refresh();
        }
    }

    function focusables() {
        return Array.prototype.filter.call(ui.root.querySelectorAll('button, input, textarea, [tabindex]:not([tabindex="-1"])'), function (n) {
            return !n.disabled && !n.hidden && n.offsetParent !== null || n === document.activeElement;
        });
    }

    function onKey(e) {
        if (!s) return;
        var typing = e.target && (e.target.tagName === 'TEXTAREA' || (e.target.tagName === 'INPUT' && e.target.type === 'text'));
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); return; }
        if (e.key === 'Tab') {   // focus stays inside the viewer
            var f = focusables();
            if (!f.length) { e.preventDefault(); return; }
            var first = f[0], last = f[f.length - 1], inside = ui.root.contains(document.activeElement);
            if (e.shiftKey && (document.activeElement === first || !inside)) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && (document.activeElement === last || !inside)) { e.preventDefault(); first.focus(); }
            return;
        }
        if (typing) return;
        if (e.key === 'ArrowLeft') { e.preventDefault(); go(-1); }
        else if (e.key === 'ArrowRight') { e.preventDefault(); go(1); }
        else if (e.key === '+' || e.key === '=') { e.preventDefault(); zoomBy(1.5); }
        else if (e.key === '-' || e.key === '_') { e.preventDefault(); zoomBy(1 / 1.5); }
        else if (e.key === '0') { e.preventDefault(); setView(1, 0, 0); }
        else if ((e.key === 'Delete' || e.key === 'Backspace') && s.editable && s.marksOn && selectedMark()) { e.preventDefault(); removeSelected(); }
    }

    window.CorexPhotoViewer = {
        open: open,
        close: function () { close(); },
        isOpen: function () { return !!s; },
        _math: { fitSize: fitSize, clampPan: clampPan, zoomAt: zoomAt, toNormalised: toNormalised, shapeFromDrag: shapeFromDrag, moveGeometry: moveGeometry, swipeDirection: swipeDirection },
    };
})();
</script>
@endverbatim
