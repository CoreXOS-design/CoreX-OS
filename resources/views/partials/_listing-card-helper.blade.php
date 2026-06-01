{{--
    Shared listing card builder — used by:
      - presentations/show.blade.php  (CAPTURED PROPERTIES grid)
      - presentations/review.blade.php (Active Competition list)

    Card visual stays identical to the original buildPropertyCard at
    show.blade.php (pre-extraction). One source of truth so visual
    drift between the two pages is impossible.

    Input shape:
      {
        image_url:           string | null,
        title:               string,
        address:             string | null   (only rendered when different from title),
        price:               int | null      (whole Rands; formatted to "R 1 234 567"),
        beds, baths, garages: int | null,
        erf_m2, floor_m2:    int | null,
        agent_name:          string | null,
        ref:                 string | null   ("P24-12345"),
        click_url:           string | null   (external listing URL),
        badges:              [{label, fg, bg}] | []   (footer-left small pills),
        top_right_pill:      {label, fg, bg} | null   (e.g. match %),
        actions_html:        string | null   (footer-right action buttons —
                                              page-specific: delete on
                                              CAPTURED PROPERTIES; include
                                              checkbox on competitor list),
      }

    Idempotent: same input → same DOM output. No external state.
--}}
<script>
(function () {
    if (typeof window.CoreXBuildListingCard === 'function') return;

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function formatPrice(p) {
        if (p === null || p === undefined || p === '') return '';
        var n = parseInt(String(p).replace(/[^\d]/g, ''), 10);
        if (isNaN(n) || n === 0) return '';
        return 'R ' + n.toLocaleString('en-ZA', { useGrouping: true, maximumFractionDigits: 0 }).replace(/,/g, ' ');
    }

    // SVG used as the placeholder when no image is available — same
    // glyph the original buildPropertyCard rendered.
    var PLACEHOLDER_SVG =
        '<svg class="w-10 h-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">'
        + '<path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/>'
        + '</svg>';

    window.CoreXBuildListingCard = function (card) {
        card = card || {};
        var title    = (card.title || '').replace(/\s*[-|–].*(Property24|PrivateProperty).*$/i, '').trim();
        if (title.length > 60) title = title.substring(0, 57) + '...';
        var address  = card.address || '';
        var priceStr = formatPrice(card.price);

        var html = '<div class="rounded-lg border border-slate-100 overflow-hidden hover:border-slate-200 transition-colors" style="position:relative;">';

        // Image + overlay (placeholder when no image).
        html += '<div class="relative h-28 bg-slate-100 overflow-hidden">';
        if (card.image_url) {
            html += '<img src="' + esc(card.image_url) + '" alt="" class="w-full h-full object-cover" loading="lazy"'
                +  ' onerror="this.closest(\'.relative\').querySelector(\'.placeholder-icon\').classList.remove(\'hidden\');this.style.display=\'none\'">';
            html += '<div class="placeholder-icon hidden absolute inset-0 flex items-center justify-center">';
        } else {
            html += '<div class="placeholder-icon absolute inset-0 flex items-center justify-center">';
        }
        html += PLACEHOLDER_SVG;
        html += '</div>';

        // Top-right pill (e.g. match %).
        if (card.top_right_pill && card.top_right_pill.label) {
            var p = card.top_right_pill;
            html += '<span style="position:absolute;top:6px;right:6px;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;'
                + 'background:' + esc(p.bg || 'rgba(255,255,255,0.92)') + ';color:' + esc(p.fg || '#0f172a') + ';">'
                + esc(p.label) + '</span>';
        }

        if (priceStr) {
            html += '<div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/60 to-transparent px-2.5 py-1.5">';
            html += '<span class="text-sm font-bold text-white">' + esc(priceStr) + '</span>';
            html += '</div>';
        }
        html += '</div>';

        html += '<div class="px-3 py-2.5">';

        html += '<p class="text-xs font-semibold text-slate-700 truncate" title="' + esc(title) + '">' + esc(title || 'Property') + '</p>';
        if (address && address !== title) {
            html += '<p class="text-[11px] text-slate-400 truncate mt-0.5">' + esc(address) + '</p>';
        }

        var stats = [];
        if (card.beds)    stats.push(card.beds + ' bed');
        if (card.baths)   stats.push(card.baths + ' bath');
        if (card.garages) stats.push(card.garages + ' garage');
        if (card.erf_m2)  stats.push(card.erf_m2 + ' m² erf');
        if (card.floor_m2) stats.push(card.floor_m2 + ' m² floor');
        if (stats.length > 0) {
            html += '<p class="text-[11px] text-slate-500 mt-1">' + stats.join(' · ') + '</p>';
        }

        if (card.agent_name) {
            html += '<p class="text-[10px] text-slate-400 mt-0.5">' + esc(card.agent_name) + '</p>';
        }

        // Footer: ref + badges (left) · click-through + page-specific actions (right).
        html += '<div class="flex items-center justify-between mt-2 pt-1.5 border-t border-slate-50">';
        html += '<div class="flex items-center gap-2 flex-wrap">';
        if (card.ref) {
            html += '<span class="text-[10px] text-slate-400 font-mono">' + esc(card.ref) + '</span>';
        }
        if (Array.isArray(card.badges)) {
            for (var i = 0; i < card.badges.length; i++) {
                var b = card.badges[i] || {};
                if (!b.label) continue;
                html += '<span style="padding:1px 6px;border-radius:8px;font-size:10px;font-weight:600;'
                    + 'background:' + esc(b.bg || '#f1f5f9') + ';color:' + esc(b.fg || '#475569') + ';">'
                    + esc(b.label) + '</span>';
            }
        }
        html += '</div>';

        html += '<div class="flex items-center gap-2">';
        if (card.click_url) {
            html += '<a href="' + esc(card.click_url) + '" target="_blank" rel="noopener"'
                +  ' class="text-[#00d4aa] hover:text-[#0f172a]" title="View on portal">';
            html += '<svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">'
                +  '<path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>'
                +  '</svg>';
            html += '</a>';
        }
        if (card.actions_html) {
            html += card.actions_html;
        }
        html += '</div>';
        html += '</div>';
        html += '</div>'; // /px-3 py-2.5
        html += '</div>'; // /card
        return html;
    };
})();
</script>
