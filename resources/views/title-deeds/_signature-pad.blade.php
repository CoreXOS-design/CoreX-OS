{{--
    AT-450 round 2 (item 9) - the signature pad for the Title Deed Register: the handover screen AND the public "sign on your own
    phone" page. ONE small, self-contained implementation - deliberately NOT the third-party SignaturePad library loaded as a
    separate script:

      - It depends on nothing else loading. (The library was a separate module script that the Alpine page only found if it
        had arrived first; a pad that quietly fails to start looks exactly like "the box does nothing".)
      - It lives OUTSIDE Alpine's reactive state. Alpine wraps whatever is stored in x-data in a proxy; the pad is kept in a plain
        registry (window.tdPads) and reached through window.tdSignaturePad.of(canvas).
      - It sizes itself from what is actually on screen, every time the box changes size (a late layout, a sidebar toggle, a
        rotation, browser zoom), redraws what was drawn, and says so when it cannot start. Mouse, touch and pen all work
        (pointer events, with a touch/mouse fallback).

    Usage:  window.tdSignaturePad.attach(canvasEl, { onChange(isEmpty) })  ->  { isEmpty(), toDataURL(), clear(), ready() }
--}}
<script>
(function () {
    if (window.tdSignaturePad) return;
    const registry = new WeakMap();

    function attach(canvas, opts) {
        opts = opts || {};
        const existing = registry.get(canvas);
        if (existing) return existing;

        const strokes = [];           // [{ pts: [[x, y], ...] }] in CSS pixels
        let current = null;
        let cssW = 0, cssH = 0, ratio = 1, ctx = null, ok = false;

        function size() {
            const r = canvas.getBoundingClientRect();
            const w = Math.round(r.width), h = Math.round(r.height);
            if (w < 20 || h < 20) { ok = false; return; }                 // not laid out yet (or hidden): try again when it is
            ratio = Math.max(window.devicePixelRatio || 1, 1);
            if (w !== cssW || h !== cssH || canvas.width !== Math.round(w * ratio)) {
                cssW = w; cssH = h;
                canvas.width = Math.round(w * ratio);
                canvas.height = Math.round(h * ratio);
                ctx = canvas.getContext('2d');
                ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
                ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111827';
                redraw();
            }
            ok = true;
        }

        function redraw() {
            if (!ctx) return;
            ctx.clearRect(0, 0, cssW, cssH);
            strokes.forEach(drawStroke);
        }

        function drawStroke(s) {
            const p = s.pts;
            if (!p.length) return;
            ctx.beginPath();
            ctx.moveTo(p[0][0], p[0][1]);
            if (p.length === 1) { ctx.lineTo(p[0][0] + 0.01, p[0][1] + 0.01); }
            for (let i = 1; i < p.length - 1; i++) {
                const mx = (p[i][0] + p[i + 1][0]) / 2, my = (p[i][1] + p[i + 1][1]) / 2;
                ctx.quadraticCurveTo(p[i][0], p[i][1], mx, my);
            }
            if (p.length > 1) ctx.lineTo(p[p.length - 1][0], p[p.length - 1][1]);
            ctx.stroke();
        }

        function point(e) {
            const r = canvas.getBoundingClientRect();
            return [e.clientX - r.left, e.clientY - r.top];
        }

        function down(e) {
            if (e.button !== undefined && e.button > 0) return;
            size();
            if (!ok) return;
            e.preventDefault();
            current = { pts: [point(e)] };
            strokes.push(current);
            if (e.pointerId !== undefined && canvas.setPointerCapture) { try { canvas.setPointerCapture(e.pointerId); } catch (x) {} }
            drawStroke(current);
            notify();
        }
        function move(e) {
            if (!current) return;
            e.preventDefault();
            current.pts.push(point(e));
            redraw();
        }
        function up(e) {
            if (!current) return;
            current = null;
            notify();
        }
        function notify() { if (opts.onChange) opts.onChange(strokes.length === 0); }

        canvas.style.touchAction = 'none';
        if (window.PointerEvent) {
            canvas.addEventListener('pointerdown', down);
            canvas.addEventListener('pointermove', move);
            canvas.addEventListener('pointerup', up);
            canvas.addEventListener('pointercancel', up);
            canvas.addEventListener('lostpointercapture', up);
        } else {
            canvas.addEventListener('mousedown', down);
            window.addEventListener('mousemove', move);
            window.addEventListener('mouseup', up);
            canvas.addEventListener('touchstart', function (e) { down(e.changedTouches[0] ? Object.assign(e.changedTouches[0], { preventDefault: function () { e.preventDefault(); } }) : e); }, { passive: false });
            canvas.addEventListener('touchmove', function (e) { move(e.changedTouches[0] ? Object.assign(e.changedTouches[0], { preventDefault: function () { e.preventDefault(); } }) : e); }, { passive: false });
            canvas.addEventListener('touchend', up);
        }
        if (window.ResizeObserver) new ResizeObserver(size).observe(canvas);
        window.addEventListener('resize', size);
        size();

        const api = {
            isEmpty: function () { return strokes.length === 0; },
            ready: function () { size(); return ok; },
            clear: function () { strokes.length = 0; current = null; redraw(); notify(); },
            toDataURL: function () { size(); return strokes.length ? canvas.toDataURL('image/png') : ''; },
        };
        registry.set(canvas, api);

        return api;
    }

    window.tdSignaturePad = { attach: attach, of: function (c) { return registry.get(c) || null; } };
})();
</script>
