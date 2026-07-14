{{--
    AT-220 — Global session armour + persistent connection indicator.

    Spec: a persistent connection indicator on EVERY long-lived (authenticated)
    screen, with click-to-reconnect and a "Back online ✓" toast — and NEVER a raw
    419 / HTTP code shown to an agent. This is the reusable mount: it loads the
    guard asset (public/js/corex-session-guard.js — plain asset, no Vite build)
    and starts a SINGLE heartbeat whose freshly-refreshed CSRF token is written
    back into <meta name="csrf-token"> (the page-wide token every CoreX AJAX call
    reads), while sliding the session so an open tab never expires.

    Long-lived authoring pages (document/template editor, e-sign wizard) that
    additionally route their own save() through CoreXSessionGuard.guardedSubmit()
    still work unchanged — the guard is load-once and its heartbeat is idempotent
    (they just register an extra token sink). See public/js/corex-session-guard.js.
--}}
@auth
<script src="{{ asset('js/corex-session-guard.js') }}"></script>
<script>
(function () {
    if (!window.CoreXSessionGuard) return;
    var meta = document.querySelector('meta[name="csrf-token"]');
    // Global token sink — keep the page-wide CSRF meta fresh so the whole
    // page's AJAX (window.CoreX.api, notification bell, theme toggle, forms
    // reading the meta) stays valid, and let page code react if it wants to.
    window.CoreXSessionGuard.startHeartbeat(function (token) {
        if (meta && token) meta.setAttribute('content', token);
        try {
            window.dispatchEvent(new CustomEvent('corex:csrf-refreshed', { detail: { token: token } }));
        } catch (e) { /* CustomEvent unsupported — non-fatal */ }
    });
})();
</script>
@endauth
