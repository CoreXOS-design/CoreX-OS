// CoreX Desk - the one Echo (Reverb) connection a tab uses. Spec .ai/specs/corex-desk.md section 7.1.
// The key / host / port come from the page (window.CorexDesk.reverb, rendered by layouts/partials/desk-client.blade.php), not from
// build-time VITE_ variables, so one build works on every box.
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let echo = null;

export function getEcho() {
    if (echo) return echo;
    const cfg = window.CorexDesk || {};
    const r = cfg.reverb || {};
    window.Pusher = Pusher;
    echo = new Echo({
        broadcaster: 'reverb',
        key: r.key,
        wsHost: r.host,
        wsPort: r.port || 80,
        wssPort: r.port || 443,
        forceTLS: (r.scheme || 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: { headers: { 'X-CSRF-TOKEN': cfg.csrf || '', 'X-Requested-With': 'XMLHttpRequest' } },
    });
    return echo;
}

export function dropEcho() {
    if (echo) {
        try { echo.disconnect(); } catch (e) { /* already gone */ }
        echo = null;
    }
}
