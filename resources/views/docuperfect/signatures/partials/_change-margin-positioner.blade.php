{{-- AT-368 — RIGHT-MARGIN change-initial-block positioner (cc1 render half).
     The wet-ink margin initial blocks (cc6 `.change-margin` + cc1 `.change-margin-initials`) are baked
     INLINE at the change (inside the paragraph). float:right is unreliable once the document is paginated
     / re-parented (it collapsed to the top-left in Johan's screenshot). This positions each margin block
     ABSOLUTELY in the page's reserved right gutter, vertically aligned to its own change anchor — immune to
     float/flex/pagination. dompdf (PDF, no JS) keeps the float:right fallback. Runs after pagination + on
     resize. Idempotent. --}}
<style>
    /* Reserve a right gutter on each page ONLY when it carries change margins (added by the positioner). */
    .corex-a4-page.has-change-margins { padding-right: 48mm; }
    .change-margin, .change-margin-initials { box-sizing: border-box; }
    /* When positioned (browser), the block is absolute in the gutter — these override the inline float. */
    .change-margin.is-gutter-positioned, .change-margin-initials.is-gutter-positioned {
        position: absolute; right: 6mm; left: auto; float: none; clear: none;
        width: 40mm; max-width: 40mm; margin: 0; z-index: 2;
    }
</style>
<script>
(function () {
    function positionChangeMargins() {
        try {
            var pages = document.querySelectorAll('.corex-a4-page');
            var contexts = pages.length ? pages
                : document.querySelectorAll('#webDocContent, [x-ref="webDocContent"], .corex-document-wrapper');
            contexts.forEach(function (page) {
                var margins = page.querySelectorAll('.change-margin, .change-margin-initials');
                if (!margins.length) return;
                if (getComputedStyle(page).position === 'static') page.style.position = 'relative';
                page.classList.add('has-change-margins');

                var placed = [];   // bottoms already used, to avoid overlap (stack down)
                Array.prototype.forEach.call(margins, function (m) {
                    var id = m.getAttribute('data-change-id') || '';
                    // The change anchor = the struck text carrying the same id (NOT the margin itself).
                    var anchor = page.querySelector(
                        '.change-anchor[data-change-id="' + id + '"],' +
                        '.change-inline[data-change-id="' + id + '"],' +
                        'del.change-del[data-change-id="' + id + '"]'
                    ) || m.previousElementSibling || m.parentElement;

                    var aTop = anchor.getBoundingClientRect().top;
                    // Re-parent to the page so absolute positioning is page-relative (survives inline nesting).
                    if (m.parentElement !== page) page.appendChild(m);
                    m.classList.add('is-gutter-positioned');

                    var pageTop = page.getBoundingClientRect().top;
                    var top = Math.max(4, aTop - pageTop);
                    // simple anti-overlap: if this top would collide with the previous block, push below it
                    for (var k = 0; k < placed.length; k++) {
                        if (top < placed[k] + 6) top = placed[k] + 6;
                    }
                    m.style.top = Math.round(top) + 'px';
                    placed.push(top + m.getBoundingClientRect().height);
                });
            });
        } catch (e) { /* never break the page over a cosmetic reflow */ }
    }
    window.positionChangeMargins = positionChangeMargins;
    // Run after layout settles + after pagination, and on resize.
    if (document.readyState !== 'loading') setTimeout(positionChangeMargins, 60);
    document.addEventListener('DOMContentLoaded', function () { setTimeout(positionChangeMargins, 60); });
    window.addEventListener('load', function () { setTimeout(positionChangeMargins, 120); });
    window.addEventListener('resize', function () { clearTimeout(window.__cmpT); window.__cmpT = setTimeout(positionChangeMargins, 150); });
})();
</script>
