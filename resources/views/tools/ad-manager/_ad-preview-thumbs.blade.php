{{--
    Template Manager previews (Johan 2026-10-18: "it should show the actual template like a preview and not just the
    name"). One shared preview for both tabs — Property templates and Company ads: each row's ad drawn by the SAME render
    kernel as the Ad Builder, scaled down and letterboxed into the box, with the page's own sample data (the user's own
    agency and details — never another agency's).

    Usage: a box `<div class="adt-thumb" data-ad-thumb="{id}"></div>` per row, then
    corexDrawAdThumbs({ id: { layout } , ... }, sampleData, { placeholders: bool }) once the page is ready.
--}}
@include('tools.ad-manager._ad-kernel-script')
@once
<style>
    .adt-thumb { width: 176px; height: 132px; border-radius: 6px; overflow: hidden; position: relative; background: var(--surface-2); border: 1px solid var(--border); }
    .adt-thumb > .adt-thumb-inner { position: absolute; left: 0; top: 0; transform-origin: 0 0; pointer-events: none; }
    @media (max-width: 640px) { .adt-thumb { width: 120px; height: 90px; } }
</style>
<script>
window.corexDrawAdThumbs = function (layouts, data, opts) {
    if (!window.CoreXAd) return;
    document.querySelectorAll('[data-ad-thumb]').forEach(function (box) {
        var entry = (layouts || {})[box.dataset.adThumb];
        if (!entry || !entry.layout) return;
        var l = entry.layout, w = l.canvasW || 1200, h = l.canvasH || 628;
        var bw = box.clientWidth || 176, bh = box.clientHeight || 132;
        var scale = Math.min(bw / w, bh / h);
        box.innerHTML = '';
        var inner = document.createElement('div');
        inner.className = 'adt-thumb-inner';
        inner.style.width = w + 'px';
        inner.style.height = h + 'px';
        inner.style.transform = 'translate(' + ((bw - w * scale) / 2) + 'px,' + ((bh - h * scale) / 2) + 'px) scale(' + scale + ')';
        box.appendChild(inner);
        try {
            window.CoreXAd.renderLayout(l, data || {}, inner, { paintBackground: true, placeholders: !!(opts && opts.placeholders) });
        } catch (e) { /* one broken layout must never blank the rest of the list */ }
    });
};
</script>
@endonce
