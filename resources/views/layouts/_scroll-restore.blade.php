{{--
    Global container-scroll preserve/restore (2026-07-29).

    CoreX scrolls an inner <main id="appScroll" class="overflow-y-auto"> — the
    window/body is pinned (h-screen overflow-hidden) — so the browser's native
    history.scrollRestoration cannot help (window scroll is always 0). We persist
    the CONTAINER's scrollTop across the full-page reloads that save/delete/action
    buttons cause.

    Covers BOTH mutation patterns: classic Laravel PRG (POST → redirect → GET
    reload) and the Alpine fetch()+location.reload()/location.href minority — both
    fire pagehide/beforeunload, so one hook catches all of them.

    Guards (per the approved design):
      • container, not window — targets #appScroll / main.overflow-y-auto
      • key by PATHNAME + QUERY STRING — a PRG redirect ordinarily lands back on
        the exact same full URL (redirect()->back() preserves it), so a save/
        delete/action-button reload still restores scroll; but a NEW query string
        on the SAME pathname — a pagination link (?page=2), a changed filter — is
        genuinely different content and gets its own (empty) key, so it lands at
        top instead of inheriting the previous page's scroll depth. (2026-09-08 —
        keying by pathname alone was restoring page 1's scroll position onto
        page 2/3/... of the same list, since both share one pathname.)
      • clamp on restore — content may have shrunk (e.g. after a delete)
      • rAF + timed retries — late Alpine/async content that grows height
      • explicit URL #fragment wins — defers to ->withFragment() anchors
      • consume-once — restore clears the key so unrelated later visits land at top

    AT-464 - "land on the Next bar": when the redirect was flashed `land_on_next` (App\Http\Middleware\LandOnNextBar - a posted
    action that came back to the fault report / work order / job card screen) the saved scroll position is DROPPED and the page is put on
    the element marked `data-land-on-next` (the top status / Next bar), which is briefly highlighted. A page that has already placed the
    user deliberately (the job card's keep-scroll forms) sets window.cxKeepScroll and is left alone.

    Included by layouts/corex.blade.php and layouts/corex-app.blade.php only.
--}}
<style>
    [data-land-on-next] { scroll-margin-top: 12px; }
    @keyframes cxLandFlash { 0% { box-shadow: 0 0 0 0 rgba(14,165,233,.55); } 35% { box-shadow: 0 0 0 5px rgba(14,165,233,.35); } 100% { box-shadow: 0 0 0 0 rgba(14,165,233,0); } }
    .cx-land-flash { animation: cxLandFlash 1.6s ease-out 1; border-radius: 8px; }
    @media (prefers-reduced-motion: reduce) { .cx-land-flash { animation: none; outline: 2px solid rgba(14,165,233,.6); } }
</style>
<script>
(function () {
    'use strict';

    var LAND_ON_NEXT = {{ session('land_on_next') ? 'true' : 'false' }};

    // Stop the browser from also trying to restore window scroll (always 0 here).
    if ('scrollRestoration' in history) {
        try { history.scrollRestoration = 'manual'; } catch (e) {}
    }

    function keyFor() { return 'corexScroll:' + location.pathname + location.search; }

    // A page may freeze its own header and scroll an inner region instead of <main>
    // (contacts show, AT-393) — it marks that region `data-scroll-region` and we
    // preserve/restore THAT element's scrollTop; every other page keeps #appScroll.
    function scroller() {
        return document.querySelector('[data-scroll-region]')
            || document.getElementById('appScroll')
            || document.querySelector('main.overflow-y-auto');
    }

    function save() {
        var el = scroller();
        if (!el) return;
        try { sessionStorage.setItem(keyFor(), String(el.scrollTop)); } catch (e) {}
    }

    // Save on every navigation away: PRG form POST, link click, and the Alpine
    // location.reload()/location.href actions all trigger these.
    window.addEventListener('beforeunload', save);
    window.addEventListener('pagehide', save);
    // Belt-and-suspenders: capture-phase submit fires before navigation begins,
    // in case beforeunload is skipped for a programmatic form submit.
    document.addEventListener('submit', save, true);

    // AT-464 - put the page on the Next bar (the first element marked data-land-on-next) and flash every marked element once.
    // If the bar sits inside the first screenful the whole top of the page is shown (header and stepper stay in view); otherwise
    // the bar is scrolled to the top edge. Repeated a few times so late-rendering content cannot push it away.
    function landOnNext() {
        var flashed = false;
        function go() {
            if (window.cxKeepScroll) return; // a form that asked to keep its place
            var box = scroller();
            if (!box) return;
            var els = document.querySelectorAll('[data-land-on-next]');
            if (els.length) {
                var top = els[0].getBoundingClientRect().top - box.getBoundingClientRect().top + box.scrollTop;
                box.scrollTop = (top + els[0].offsetHeight < box.clientHeight - 16) ? 0 : Math.max(0, top - 12);
                if (!flashed) {
                    flashed = true;
                    Array.prototype.forEach.call(els, function (el) {
                        el.classList.add('cx-land-flash');
                        setTimeout(function () { el.classList.remove('cx-land-flash'); }, 2200);
                    });
                }
            } else {
                box.scrollTop = 0;
            }
        }
        go();
        requestAnimationFrame(function () { go(); requestAnimationFrame(go); });
        setTimeout(go, 60);
        setTimeout(go, 200);
        window.addEventListener('load', function () { setTimeout(go, 30); });
    }

    function restore() {
        if (LAND_ON_NEXT) {
            try { sessionStorage.removeItem(keyFor()); } catch (e) {} // the old position is not wanted - drop it
            landOnNext();
            return;
        }
        // An explicit URL #fragment (e.g. a controller's ->withFragment) must win:
        // let the anchor position the page and drop any stored scroll for this url.
        if (location.hash && location.hash.length > 1) {
            try { sessionStorage.removeItem(keyFor()); } catch (e) {}
            return;
        }

        var raw = null;
        try { raw = sessionStorage.getItem(keyFor()); } catch (e) {}
        if (raw === null) return;
        try { sessionStorage.removeItem(keyFor()); } catch (e) {} // consume-once

        var target = parseInt(raw, 10);
        if (!isFinite(target) || target <= 0) return;

        var apply = function () {
            var el = scroller();
            if (!el) return;
            var max = el.scrollHeight - el.clientHeight; // clamp: content may have
            el.scrollTop = Math.max(0, Math.min(target, max)); // shrunk after a delete.
        };

        apply();
        // Retry for content that renders/grows after first paint (Alpine lists, images).
        requestAnimationFrame(function () { apply(); requestAnimationFrame(apply); });
        setTimeout(apply, 60);
        setTimeout(apply, 200);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', restore);
    } else {
        restore();
    }
})();
</script>
