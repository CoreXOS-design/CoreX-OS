// CoreX Session Keepalive + Connectivity Indicator — AT-165 (complements D + E)
// -----------------------------------------------------------------------------
// D) Keepalive: while a long form is on screen, a lightweight heartbeat pings
//    GET /api/v1/session/ping so a 40-minute capture doesn't lapse into a 419 on
//    submit. Only runs on pages that host a draft-registered form, and never
//    pings into a dead network.
// E) Indicator: a global "Offline — your work is being saved on this device"
//    banner driven by online/offline events, so silent loss becomes visible.
// -----------------------------------------------------------------------------

(function () {
    'use strict';
    if (typeof window === 'undefined') return;

    const PING_URL = '/api/v1/session/ping';
    const DEFAULT_INTERVAL_MS = 4 * 60 * 1000; // under the session lifetime

    // ── Connectivity indicator (always on) ────────────────────────────────────
    function ensureBar() {
        let bar = document.getElementById('corex-offline-bar');
        if (bar) return bar;
        bar = document.createElement('div');
        bar.id = 'corex-offline-bar';
        bar.setAttribute('role', 'status');
        bar.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:9999;display:none;'
            + 'padding:.5rem .9rem;text-align:center;font-size:.82rem;font-weight:600;'
            + 'background:#7c2d12;color:#fff;box-shadow:0 -2px 8px rgba(0,0,0,.2);';
        bar.textContent = 'Offline — your work is being saved on this device.';
        document.body.appendChild(bar);
        return bar;
    }
    function reflectConnectivity() {
        const bar = ensureBar();
        bar.style.display = navigator.onLine ? 'none' : 'block';
    }

    // ── Keepalive heartbeat ───────────────────────────────────────────────────
    let timer = null;
    function ping() {
        if (!navigator.onLine) return; // no point pinging into a dead network
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        fetch(PING_URL, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf } }).catch(() => {});
    }
    function start(intervalMs) {
        if (timer) return;
        timer = setInterval(ping, intervalMs || DEFAULT_INTERVAL_MS);
    }
    function stop() { if (timer) { clearInterval(timer); timer = null; } }

    function boot() {
        window.addEventListener('online', reflectConnectivity);
        window.addEventListener('offline', reflectConnectivity);
        reflectConnectivity();
        // Only keep the session warm on pages that actually host a long form.
        const hasLongForm = document.querySelector('form[data-draft], form[data-keepalive]');
        if (hasLongForm && document.querySelector('meta[name="corex-auth"]')?.content === '1') {
            const attr = document.querySelector('form[data-keepalive]')?.getAttribute('data-keepalive');
            start(attr ? parseInt(attr, 10) * 1000 : DEFAULT_INTERVAL_MS);
            document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') stop(); else start(); });
        }
    }

    window.CoreX = window.CoreX || {};
    window.CoreX.sessionKeepalive = { start, stop, ping };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
