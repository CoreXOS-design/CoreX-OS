// AT-463 / .ai/specs/rental-inspections.md §61 - the ONE drawing pad for inspection signatures.
//
// This is the pad that lived inline on the party's signing page (public/partials/sign-section.blade.php, the §46.11 fix:
// "the drawing pad is independent of everything else on the form"). It is now one shared mixin so the signing page and the
// agent's in-place "Sign now" on the inspection screens draw, size and re-fit identically - there is no second pad.
//
// Use it from an Alpine component:   return Object.assign(window.corexSignaturePadMixin(), { ...yourState, init() { this.watchPad(); } })
// and give the canvas x-ref="pad". The pad is built once, as soon as the page can draw on it, and kept in step with the
// canvas's real size for the life of the page (on load, pageshow, resize, phone rotation, and whenever the canvas itself
// changes size - a ResizeObserver). If the signature library or the canvas size is not ready yet, ONE watcher (libTimer)
// waits for it instead of giving up; if the library was never loaded on this page it is fetched once. Strokes already
// drawn are kept when the pad is re-fitted. Nothing the form does (a tick box, a radio button) ever touches the pad.
window.corexSignaturePadMixin = function () {
    return {
        pad: null,
        padW: 0,
        padH: 0,
        padTries: 0,
        libTimer: null,
        padLibRequested: false,
        padWatching: false,

        // Start keeping the pad in step with its canvas. Safe to call more than once; only the first call does anything.
        watchPad() {
            if (this.padWatching) return;
            this.padWatching = true;
            const sync = () => this.syncPad();
            this.$nextTick(sync);
            document.addEventListener('DOMContentLoaded', sync);
            window.addEventListener('load', sync);
            window.addEventListener('pageshow', sync);
            window.addEventListener('resize', sync);
            window.addEventListener('orientationchange', sync);
            if (window.ResizeObserver && this.$refs.pad) new ResizeObserver(sync).observe(this.$refs.pad);
        },

        // The signature library is bundled on the signing page; on the agent's screens it may not be loaded yet.
        requestPadLibrary() {
            if (window.SignaturePad || this.padLibRequested) return;
            this.padLibRequested = true;
            import('signature_pad').then((m) => { window.SignaturePad = m.default; this.syncPad(); }).catch(() => { this.padLibRequested = false; });
        },

        // Create the pad if it is missing, or re-fit its drawing surface when the canvas has changed size (phone turned,
        // address bar hidden, photos above finished loading). Strokes already drawn are kept.
        syncPad() {
            const canvas = this.$refs.pad;
            if (!canvas) return;
            if (!window.SignaturePad) {
                this.requestPadLibrary();
                // the signature library has not arrived yet (slow phone): one watcher waits for it rather than leaving a dead pad
                if (!this.libTimer) {
                    this.libTimer = setInterval(() => {
                        if (window.SignaturePad || ++this.padTries > 300) { clearInterval(this.libTimer); this.libTimer = null; this.syncPad(); }
                    }, 100);
                }
                return;
            }
            const w = canvas.offsetWidth, h = canvas.offsetHeight;
            if (!w || !h) return;            // not laid out / hidden: the ResizeObserver calls us again when it is
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            if (this.pad && this.padW === w && this.padH === h && canvas.width === Math.round(w * ratio)) return;
            const had = this.pad && !this.pad.isEmpty() ? this.pad.toData() : null;
            const sx = this.padW ? w / this.padW : 1, sy = this.padH ? h / this.padH : 1;
            canvas.width = Math.round(w * ratio);
            canvas.height = Math.round(h * ratio);
            canvas.getContext('2d').scale(ratio, ratio);
            if (!this.pad) {
                this.pad = new window.SignaturePad(canvas, { penColor: '#111827', backgroundColor: 'rgba(255,255,255,0)' });
            } else {
                this.pad.clear();
            }
            this.padW = w; this.padH = h;
            if (had) {
                had.forEach(stroke => (stroke.points || []).forEach(pt => { pt.x *= sx; pt.y *= sy; }));
                this.pad.fromData(had);
            }
        },

        clearPad() { if (this.pad) this.pad.clear(); },
        padIsEmpty() { return !this.pad || this.pad.isEmpty(); },
        padDataUrl() { return this.pad.toDataURL('image/png'); },
    };
};

// For a component whose own object has getters (Object.assign would evaluate them): add the pad's members, keeping the
// target's descriptors untouched.
window.corexAttachSignaturePad = function (target) {
    return Object.defineProperties(target, Object.getOwnPropertyDescriptors(window.corexSignaturePadMixin()));
};
