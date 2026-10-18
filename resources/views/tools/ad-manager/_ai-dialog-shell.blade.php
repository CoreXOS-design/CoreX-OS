{{--
    The shared shell of the AI pop-ups (AT-456 §B.10 "Create with AI" and Company Ads §8 "Create company ad with AI"):
    the dialog / card / full-size viewer styles (prefixed aitp- / aitv-, CoreX tokens only, so light and dark both work and
    no `npm run build` is needed) and corexAiDialogShell(), the behaviour both pop-ups mix in. One copy, reused - not copied.
--}}
@once
<style>
    .aitp-overlay { position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 16px; }
    .aitp-backdrop { position: absolute; inset: 0; background: rgba(0, 0, 0, .55); }
    .aitp-dialog {
        position: relative; display: flex; flex-direction: column; width: 100%; max-width: 1040px;
        max-height: 90vh; max-height: 90dvh; min-height: 0;
        background: var(--surface); color: var(--text-primary); border: 1px solid var(--border);
        border-radius: 8px; box-shadow: 0 20px 50px rgba(0, 0, 0, .35); outline: none; overflow: hidden;
    }
    .aitp-head, .aitp-foot { flex: 0 0 auto; position: sticky; background: var(--surface); z-index: 2; }
    .aitp-head { top: 0; display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 16px 20px; border-bottom: 1px solid var(--border); }
    .aitp-foot { bottom: 0; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px 16px; padding: 12px 20px; border-top: 1px solid var(--border); }
    .aitp-foot-actions { display: flex; align-items: center; gap: 8px; margin-left: auto; }
    .aitp-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; overflow-x: hidden; overscroll-behavior: contain; padding: 16px 20px 20px; }
    .aitp-x { flex: 0 0 auto; width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; border: 1px solid var(--border); background: transparent; color: var(--text-secondary); cursor: pointer; font-size: 20px; line-height: 1; }
    .aitp-x:hover:not(:disabled) { background: var(--surface-2); color: var(--text-primary); }
    .aitp-x:disabled { opacity: .5; cursor: not-allowed; }
    .aitp-form { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px 16px; align-items: start; }
    .aitp-full { grid-column: 1 / -1; }
    .aitp-lbl { display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 4px; color: var(--text-secondary); }
    .aitp-hint { font-size: 12px; color: var(--text-muted); }
    .aitp-field { width: 100%; max-width: 100%; box-sizing: border-box; border-radius: 6px; padding: 8px 12px; font-size: 14px; background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary); }
    .aitp-checks { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 200px), 1fr)); gap: 6px 14px; }
    .aitp-check { display: flex; align-items: flex-start; gap: 8px; font-size: 14px; cursor: pointer; min-width: 0; }
    .aitp-check input { margin-top: 3px; flex: 0 0 auto; }
    .aitp-check[data-disabled="1"] { opacity: .55; cursor: not-allowed; }
    .aitp-group { border: 1px solid var(--border); border-radius: 6px; padding: 10px 12px; background: var(--surface-2); min-width: 0; }
    .aitp-groups { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .aitp-note { border-radius: 6px; padding: 10px 14px; font-size: 14px; background: var(--surface-2); border: 1px solid var(--border); }
    .aitp-alert { border-radius: 6px; padding: 10px 14px; font-size: 14px; background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 35%, transparent); }
    .aitp-results { margin-top: 18px; }
    .aitp-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 290px), 1fr)); gap: 16px; }
    .aitp-card { min-width: 0; border-radius: 6px; padding: 12px; background: var(--surface-2); border: 1px solid var(--border); display: flex; flex-direction: column; gap: 8px; }
    .aitp-prev { position: relative; width: 100%; overflow: hidden; border-radius: 4px; background: #111; }
    .aitp-prev-inner { position: relative; overflow: hidden; transform-origin: top left; }
    .aitp-skel { background: linear-gradient(100deg, color-mix(in srgb, var(--text-muted) 18%, transparent) 30%, color-mix(in srgb, var(--text-muted) 32%, transparent) 50%, color-mix(in srgb, var(--text-muted) 18%, transparent) 70%); background-size: 300% 100%; animation: aitp-shimmer 1.6s linear infinite; border-radius: 4px; }
    .aitp-skel-line { height: 12px; }
    @keyframes aitp-shimmer { from { background-position: 100% 0; } to { background-position: 0 0; } }
    @media (prefers-reduced-motion: reduce) { .aitp-skel { animation: none; } }
    .aitp-switch { display: flex; flex-wrap: wrap; gap: 4px; }
    .aitp-pill { font-size: 12px; padding: 3px 10px; border-radius: 999px; border: 1px solid var(--border); background: transparent; color: var(--text-secondary); cursor: pointer; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .aitp-pill[aria-pressed="true"] { background: var(--accent, #4f7cff); border-color: var(--accent, #4f7cff); color: #fff; }
    .aitp-small { font-size: 12px; color: var(--text-secondary); }
    .aitp-prev[data-zoom="1"] { cursor: zoom-in; }
    .aitp-view-btn { align-self: flex-start; }
    /* Full-screen viewer (always a dark stage, so light and dark themes both read well). */
    .aitv-overlay { position: fixed; inset: 0; z-index: 10000; display: flex; flex-direction: column; overscroll-behavior: contain; touch-action: none; color: #fff; }
    .aitv-backdrop { position: absolute; inset: 0; background: rgba(8, 10, 14, .97); }
    .aitv-top, .aitv-bottom, .aitv-switch { position: relative; flex: 0 0 auto; display: flex; align-items: center; gap: 8px 12px; padding: 10px 16px; pointer-events: none; }
    .aitv-top { justify-content: space-between; }
    .aitv-top > *, .aitv-bottom > *, .aitv-switch > * { pointer-events: auto; }
    .aitv-title { font-size: 14px; font-weight: 600; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .aitv-switch { flex-wrap: wrap; justify-content: center; padding-top: 0; }
    .aitv-bottom { justify-content: center; }
    .aitv-btn { min-width: 44px; height: 44px; padding: 0 14px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; border-radius: 8px; border: 1px solid rgba(255, 255, 255, .35); background: rgba(255, 255, 255, .1); color: #fff; font-size: 14px; cursor: pointer; }
    .aitv-btn:hover:not(:disabled) { background: rgba(255, 255, 255, .22); }
    .aitv-btn:focus-visible, .aitv-pill:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
    .aitv-x { font-size: 26px; line-height: 1; padding: 0; width: 44px; }
    .aitv-pill { font-size: 13px; padding: 6px 14px; border-radius: 999px; border: 1px solid rgba(255, 255, 255, .35); background: transparent; color: #fff; cursor: pointer; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .aitv-pill[aria-pressed="true"] { background: #fff; color: #111; border-color: #fff; }
    .aitv-stage { position: relative; flex: 1 1 auto; min-height: 0; display: flex; align-items: center; justify-content: center; padding: 8px 16px; pointer-events: none; overflow: hidden; }
    .aitv-frame { position: relative; flex: 0 0 auto; overflow: hidden; background: #111; box-shadow: 0 10px 40px rgba(0, 0, 0, .6); pointer-events: auto; }
    .aitv-inner { position: absolute; top: 0; left: 0; overflow: hidden; transform-origin: top left; }
    @media (max-width: 760px) {
        .aitv-top, .aitv-bottom, .aitv-switch { padding-left: 12px; padding-right: 12px; }
        .aitv-stage { padding: 6px 12px; }
    }
    @media (max-width: 760px) {
        .aitp-form { grid-template-columns: minmax(0, 1fr); }
        .aitp-groups { grid-template-columns: minmax(0, 1fr); }
        .aitp-head, .aitp-foot { padding-left: 14px; padding-right: 14px; }
        .aitp-body { padding: 14px; }
        .aitp-dialog { max-height: calc(100vh - 32px); max-height: calc(100dvh - 32px); }
        .aitp-foot-actions { width: 100%; }
        .aitp-foot-actions > * { flex: 1 1 auto; justify-content: center; }
    }
</style>

<script>
/**
 * The behaviour every AI pop-up shares (property templates and company ads): open / close with focus kept inside and
 * returned, Esc order, the card previews and the full-size viewer. A pop-up mixes it in with Object.assign and adds
 * its own form; it must provide layoutFor(opt) and sampleFor(opt) (what to draw, and with which sample data).
 */
function corexAiDialogShell() {
    return {
        open: false, loading: false, options: [], savedAny: false,
        _opener: null, _bodyOverflow: '',
        viewer: { open: false, index: 0 }, _viewerRO: null,

        openPanel() {
            this._opener = document.activeElement;
            this._bodyOverflow = document.body.style.overflow;
            this.open = true; document.body.style.overflow = 'hidden';
            this.$nextTick(() => { (this.$refs.idea || this.$refs.dialog).focus(); });
        },
        closePanel() {
            this.closeViewer(false);
            this.open = false; document.body.style.overflow = this._bodyOverflow;
            if (this._opener && typeof this._opener.focus === 'function') { try { this._opener.focus(); } catch (e) {} }
            // Newly saved templates only appear in the table after a reload.
            if (this.savedAny) window.location.reload();
        },
        // Esc closes the full-size viewer first; only a second Esc closes the pop-up (and never while a design is being made).
        onEscape() {
            if (this.viewer.open) { this.closeViewer(); return; }
            if (this.open && !this.loading) this.closePanel();
        },
        // Keep Tab / Shift+Tab inside the dialog (or the viewer).
        trapTab(e, box) {
            const dlg = box || this.$refs.dialog; if (!dlg) return;
            const items = Array.from(dlg.querySelectorAll('a[href], button, textarea, input, select, [tabindex]'))
                .filter(el => !el.disabled && el.tabIndex !== -1 && el.type !== 'hidden' && el.offsetParent !== null);
            if (!items.length) { e.preventDefault(); dlg.focus(); return; }
            const first = items[0], last = items[items.length - 1], active = document.activeElement;
            if (e.shiftKey && (active === first || active === dlg)) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && (active === last || !dlg.contains(active))) { e.preventDefault(); first.focus(); }
        },
        // ── Full-size viewer ──────────────────────────────────────────────
        viewerOpt() { return this.options[this.viewer.index] || null; },
        viewerTitle() {
            const o = this.viewerOpt(); if (!o) return '';
            return 'Design ' + (this.viewer.index + 1) + ' of ' + this.options.length + (o.name ? ' · ' + o.name : '') + (o.view ? ' · ' + o.view : '');
        },
        openViewer(opt) {
            if (this.loading) return;
            const i = this.options.indexOf(opt); if (i < 0) return;
            this.viewer.index = i; this.viewer.open = true;
            this.$nextTick(() => {
                this.paintViewer();
                if (this.$refs.viewerClose) this.$refs.viewerClose.focus();
                if (!this._viewerRO && window.ResizeObserver && this.$refs.viewerStage) {
                    this._viewerRO = new ResizeObserver(() => this.fitViewer());
                    this._viewerRO.observe(this.$refs.viewerStage);
                }
            });
        },
        closeViewer(restoreFocus = true) {
            if (!this.viewer.open) return;
            const opt = this.viewerOpt();
            this.viewer.open = false;
            if (restoreFocus && opt) {
                this.$nextTick(() => {
                    const btn = document.querySelector('[data-aitp-view="' + opt.index + '"]');
                    if (btn) { try { btn.focus(); } catch (e) {} }
                });
            }
        },
        viewerStep(dir) {
            if (!this.viewer.open || this.options.length < 2) return;
            const n = this.options.length;
            this.viewer.index = (this.viewer.index + dir + n) % n;
            this.$nextTick(() => this.paintViewer());
        },
        // Same render kernel as the cards, the Ad Builder and the export, drawn at the canvas's own size and scaled to fit.
        paintViewer() {
            const opt = this.viewerOpt(), inner = this.$refs.viewerInner;
            const l = opt && this.layoutFor(opt);
            if (!l || !inner || !window.CoreXAd) return;
            inner.style.width = l.canvasW + 'px'; inner.style.height = l.canvasH + 'px';
            inner.dataset.cw = l.canvasW; inner.dataset.ch = l.canvasH;
            window.CoreXAd.renderLayout(l, this.sampleFor(opt), inner, { paintBackground: true, placeholders: true });
            this.fitViewer();
        },
        // The largest size that fits the stage with the ad's aspect kept.
        fitViewer() {
            const stage = this.$refs.viewerStage, frame = this.$refs.viewerFrame, inner = this.$refs.viewerInner;
            if (!this.viewer.open || !stage || !frame || !inner) return;
            const cw = parseFloat(inner.dataset.cw || '0'), ch = parseFloat(inner.dataset.ch || '0');
            if (!cw || !ch) return;
            const cs = getComputedStyle(stage);
            const aw = stage.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
            const ah = stage.clientHeight - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom);
            if (aw <= 0 || ah <= 0) return;
            const k = Math.min(aw / cw, ah / ch);
            frame.style.width = Math.floor(cw * k) + 'px'; frame.style.height = Math.floor(ch * k) + 'px';
            inner.style.transform = 'scale(' + k + ')';
        },
        // Draws the chosen version into the card's preview box and keeps it scaled to the box's width as the box resizes.
        paintPreview(opt) {
            const box = document.getElementById('aitp-prev-' + opt.index);
            const l = this.layoutFor(opt);
            if (!box || !l || !window.CoreXAd) return;
            const inner = box.firstElementChild;
            box.style.aspectRatio = l.canvasW + ' / ' + l.canvasH;
            inner.style.width = l.canvasW + 'px'; inner.style.height = l.canvasH + 'px';
            box.dataset.cw = l.canvasW;
            window.CoreXAd.renderLayout(l, this.sampleFor(opt), inner, { paintBackground: true, placeholders: true });
            this.scalePreview(box);
            if (!box._aitpRO && window.ResizeObserver) {
                box._aitpRO = new ResizeObserver(() => this.scalePreview(box));
                box._aitpRO.observe(box);
            }
        },
        scalePreview(box) {
            const inner = box.firstElementChild, cw = parseFloat(box.dataset.cw || '0');
            if (!inner || !cw) return;
            inner.style.transform = 'scale(' + ((box.clientWidth || 320) / cw) + ')';
        },
    };
}
</script>
@endonce
