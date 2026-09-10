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

    Included by layouts/corex.blade.php and layouts/corex-app.blade.php only.
--}}
<script>
(function () {
    'use strict';

    // Stop the browser from also trying to restore window scroll (always 0 here).
    if ('scrollRestoration' in history) {
        try { history.scrollRestoration = 'manual'; } catch (e) {}
    }

    function keyFor() { return 'corexScroll:' + location.pathname + location.search; }

    function scroller() {
        return document.getElementById('appScroll')
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

    function restore() {
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
