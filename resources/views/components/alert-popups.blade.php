{{--
    AT-451 — live alerts for a staff browser session (Johan, 12 Oct 2026; reworked 14 Oct 2026 - spec notification-system-overview.md section 16).

    ONE script owns everything an agent sees of their alerts on a page:
      - the POLL of the session-authenticated feed GET /api/v1/alerts/unread (Api\V1\AlertFeedController, routes/web.php beside the
        reminders poll - NOT /api/v1/notifications, the mobile app's token-only route, which answered a browser session with 401);
      - the TOAST STACK (top right): newest on top, at most 5 shown, older ones collapse into one "+N more" row; a toast closes ITSELF 5 seconds after it is
        first shown (AT-509, Andre 27 Oct 2026) - it stays unread and is still in the bell - or sooner when clicked (opens the alert and
        marks it read) or closed with x (marks it read). The 5 seconds run on across a page change, never restart. The ids of toasts not yet dismissed
        are kept in this tab's sessionStorage, so they are still there after a page change; each poll asks the server which of them are still
        unread, so reading one in the bell or another tab removes it here;
      - the BELL in the left menu (components/alert-bell.blade.php is only the button): unread count badge, and a panel of the latest unread
        alerts (title, text, time-ago) with "Mark all read" and "View all".

    The feed is a CURSOR feed, never "all unread": a poll sends ?after=<cursor> (the tab's stored point) and gets only the user's unread
    alerts created since; a tab with no stored point (a new tab, a tab that slept) gets only the last N minutes (agency setting, default 10)
    as toasts - everything older is in the bell. A user with tens of thousands of unread alerts costs the same as one with none (the unread
    count is capped at 100 and shown as "99+").

    Rules this honours:
      - how often it asks is the agency's setting (Settings -> Dashboard -> "Check for new alerts every", default 30s, never below 15s);
      - an alert is never toasted twice in a session (ids seen are kept in sessionStorage, per user);
      - nothing is polled while the tab is hidden, and it catches up the moment the tab is visible again;
      - a failed poll is retried with a growing delay, never given up on; a 401/419 is checked against the session probe
        (/api/v1/csrf-token): only if THAT also says the login is gone does polling stop;
      - the user's own in-app switch wins: if in-app alerts are off, nothing new is toasted;
      - staff layouts only (layouts/partials/_staff-alerts.blade.php) - never the public portal or signing pages - and never inside a frame.
--}}
@auth
@php
    $__alertPopupUser = auth()->user();
    $__alertPopupSeconds = app(\App\Services\CommandCenter\AlertSwitchService::class)->pollSecondsFor($__alertPopupUser->effectiveAgencyId());
@endphp
<style>
    #cxa-stack { position: fixed; top: 4.5rem; right: 1rem; z-index: 65; display: flex; flex-direction: column; gap: .5rem; width: 22rem; max-width: calc(100vw - 2rem); pointer-events: none; }
    .cxa-toast, .cxa-more { pointer-events: auto; display: flex; align-items: flex-start; gap: .5rem; padding: .625rem .75rem; border-radius: .5rem; background: var(--surface, #fff); color: var(--text-primary, #111); border: 1px solid var(--border, #ddd); border-left: 4px solid var(--brand-button, #2563eb); box-shadow: 0 6px 18px rgba(0,0,0,.22); }
    .cxa-toast.is-warning { border-left-color: #d97706; }
    .cxa-toast.is-critical { border-left-color: #dc2626; }
    .cxa-toast a { flex: 1; min-width: 0; color: inherit; text-decoration: none; }
    .cxa-title { font-size: .8125rem; font-weight: 600; line-height: 1.25; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
    .cxa-text { font-size: .75rem; color: var(--text-secondary, #555); margin-top: .125rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .cxa-close { flex: none; width: 1.25rem; height: 1.25rem; line-height: 1; border: 0; background: transparent; color: var(--text-muted, #777); cursor: pointer; font-size: 1.125rem; border-radius: .25rem; }
    .cxa-close:hover { color: var(--text-primary, #111); }
    .cxa-more { cursor: pointer; font-size: .8125rem; font-weight: 600; justify-content: center; text-decoration: none; }
    #cxa-panel { position: fixed; z-index: 70; width: 24rem; max-width: calc(100vw - 1rem); display: none; flex-direction: column; background: var(--surface, #fff); color: var(--text-primary, #111); border: 1px solid var(--border, #ddd); border-radius: .5rem; box-shadow: 0 10px 30px rgba(0,0,0,.3); overflow: hidden; }
    #cxa-panel.is-open { display: flex; }
    #cxa-panel .cxa-head, #cxa-panel .cxa-foot { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .5rem .75rem; font-size: .75rem; }
    #cxa-panel .cxa-head { border-bottom: 1px solid var(--border, #ddd); font-weight: 600; }
    #cxa-panel .cxa-foot { border-top: 1px solid var(--border, #ddd); }
    #cxa-panel .cxa-list { overflow-y: auto; }
    #cxa-panel .cxa-item { display: block; padding: .5rem .75rem; border-bottom: 1px solid var(--border, #ddd); color: inherit; text-decoration: none; }
    #cxa-panel .cxa-item:hover { background: var(--surface-2, rgba(128,128,128,.12)); }
    #cxa-panel .cxa-time { font-size: .6875rem; color: var(--text-muted, #777); margin-top: .125rem; }
    #cxa-panel .cxa-empty { padding: 1.25rem .75rem; text-align: center; font-size: .75rem; color: var(--text-muted, #777); }
    #cxa-panel .cxa-btn { font-size: .75rem; padding: .25rem .625rem; border-radius: .375rem; border: 1px solid var(--border, #ddd); background: var(--surface-2, transparent); color: var(--text-primary, #111); cursor: pointer; text-decoration: none; line-height: 1.25rem; }
    .cxa-badge { position: absolute; top: -.25rem; right: -.25rem; min-width: 1rem; height: 1rem; padding: 0 .25rem; border-radius: 9999px; background: #dc2626; color: #fff; font-size: .625rem; font-weight: 700; line-height: 1rem; text-align: center; display: none; }
    .cxa-badge.is-on { display: block; }
</style>
<script>
(function () {
    if (window.__corexAlertPopups || window.self !== window.top) { return; }
    window.__corexAlertPopups = true;

    var FEED = @json(route('api.v1.alerts.unread'));
    var BELL = @json(route('api.v1.alerts.bell'));
    var READ_URL = @json(route('api.v1.alerts.read', ['id' => '00000000-0000-0000-0000-000000000000']));
    var READ_ALL = @json(route('api.v1.alerts.read-all'));
    var SESSION_PROBE = @json(route('api.v1.csrf-token'));
    var INBOX = @json(route('corex.alerts.index'));
    var INBOX_UNREAD = @json(route('corex.alerts.index', ['status' => 'unread']));
    var INTERVAL_MS = Math.max(15, {{ (int) $__alertPopupSeconds }}) * 1000;
    var MAX_DELAY_MS = 5 * 60 * 1000;
    var MAX_TOASTS = 5;
    var AUTO_CLOSE_MS = 5000;
    var KEY = 'corex_alerts_seen_{{ (int) $__alertPopupUser->id }}';
    var CURSOR_KEY = 'corex_alerts_cursor_{{ (int) $__alertPopupUser->id }}';
    var PENDING_KEY = 'corex_alerts_toasts_{{ (int) $__alertPopupUser->id }}';

    var seen = {};
    var pending = [];       // toasts not yet dismissed, newest first (whole items, so a new page can draw them with no request)
    var cursor = null;      // this tab's "last checked" point: read from the tab's storage on load, null = a brand-new tab
    var unreadCount = null; // capped at 100 by the server
    var failures = 0;
    var stopped = false;
    var busy = false;
    var timer = null;
    var stack = null;
    var panel = null;
    var panelButton = null;
    var closeTimers = {};   // id -> the timer that closes that toast on its own

    try {
        var raw = window.sessionStorage.getItem(KEY);
        if (raw) { JSON.parse(raw).forEach(function (id) { seen[id] = true; }); }
    } catch (e) { /* storage blocked - the in-memory set still stops repeats on this page */ }
    try { cursor = window.sessionStorage.getItem(CURSOR_KEY) || null; } catch (e) { /* this page starts as a new tab would */ }
    try {
        var p = window.sessionStorage.getItem(PENDING_KEY);
        if (p) { pending = (JSON.parse(p) || []).filter(function (i) { return i && i.id; }); }
    } catch (e) { pending = []; }

    function saveCursor() { try { if (cursor) { window.sessionStorage.setItem(CURSOR_KEY, cursor); } } catch (e) { /* ignore */ } }
    function savePending() { try { window.sessionStorage.setItem(PENDING_KEY, JSON.stringify(pending.slice(0, 50))); } catch (e) { /* ignore */ } }
    function saveSeen() {
        try {
            var ids = Object.keys(seen);
            if (ids.length > 300) {
                ids = ids.slice(ids.length - 300);
                seen = {};
                ids.forEach(function (id) { seen[id] = true; });
            }
            window.sessionStorage.setItem(KEY, JSON.stringify(ids));
        } catch (e) { /* ignore */ }
    }

    function stamp(item) { var t = Date.parse(item && item.created_at); return isNaN(t) ? 0 : t; }
    function titleOf(item) { return item.title || 'New notification'; }

    function ago(item) {
        var secs = Math.max(0, Math.round((Date.now() - stamp(item)) / 1000));
        if (secs < 60) { return 'just now'; }
        if (secs < 3600) { return Math.round(secs / 60) + ' min ago'; }
        if (secs < 86400) { return Math.round(secs / 3600) + ' h ago'; }
        if (secs < 7 * 86400) { return Math.round(secs / 86400) + ' d ago'; }
        return new Date(stamp(item)).toLocaleDateString();
    }

    function el(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) { node.className = cls; }
        if (text != null) { node.textContent = text; }
        return node;
    }

    // ---------------------------------------------------------------- server writes

    function post(url) {
        var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
        var opts = { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf } };
        return fetch(url, opts).then(function (res) { return res.ok ? res.json() : null; }).catch(function () { return null; });
    }

    function markRead(id) {
        return post(READ_URL.replace('00000000-0000-0000-0000-000000000000', encodeURIComponent(id))).then(function (data) {
            if (data && typeof data.unread_count === 'number') { setCount(data.unread_count); }
        });
    }

    // ---------------------------------------------------------------- bell badge

    function setCount(n) {
        unreadCount = n;
        document.querySelectorAll('[data-alert-bell-badge]').forEach(function (badge) {
            badge.textContent = n >= 100 ? '99+' : String(n);
            badge.classList.toggle('is-on', n > 0);
        });
    }

    // ---------------------------------------------------------------- toast stack

    function ensureStack() {
        if (stack) { return stack; }
        stack = el('div');
        stack.id = 'cxa-stack';
        stack.setAttribute('aria-live', 'polite');
        document.body.appendChild(stack);
        return stack;
    }

    function clearTimer(id) {
        if (closeTimers[id]) { clearTimeout(closeTimers[id]); delete closeTimers[id]; }
    }

    function dropPending(id) {
        clearTimer(id);
        pending = pending.filter(function (i) { return i.id !== id; });
        savePending();
    }

    /** A toast on screen closes itself AUTO_CLOSE_MS after it was first shown. It is NOT marked read - it stays in the bell. */
    function armAutoClose(item) {
        if (closeTimers[item.id]) { return; }
        if (!item.shown_at) { item.shown_at = Date.now(); savePending(); }
        var left = Math.max(0, AUTO_CLOSE_MS - (Date.now() - item.shown_at));
        closeTimers[item.id] = setTimeout(function () {
            delete closeTimers[item.id];
            dropPending(item.id);
            renderToasts();
        }, left);
    }

    function renderToasts() {
        if (!pending.length && !stack) { return; }
        var box = ensureStack();
        box.textContent = '';
        pending.slice(0, MAX_TOASTS).forEach(function (item) {
            var toast = el('div', 'cxa-toast' + (item.severity === 'warning' ? ' is-warning' : '') + (item.severity === 'critical' || item.severity === 'error' ? ' is-critical' : ''));
            toast.setAttribute('data-alert-toast', item.id);
            var link = el('a');
            link.href = item.open_url || INBOX;
            link.appendChild(el('div', 'cxa-title', titleOf(item)));
            if (item.body) { link.appendChild(el('div', 'cxa-text', item.body)); }
            link.addEventListener('click', function () { dropPending(item.id); });   // the server marks it read as it opens it
            var close = el('button', 'cxa-close', '×');
            close.type = 'button';
            close.title = 'Close';
            close.setAttribute('aria-label', 'Close');
            close.addEventListener('click', function () {
                dropPending(item.id);
                renderToasts();
                markRead(item.id);
            });
            toast.appendChild(link);
            toast.appendChild(close);
            box.appendChild(toast);
            armAutoClose(item);
        });
        var rest = pending.length - MAX_TOASTS;
        if (rest > 0) {
            var more = el('a', 'cxa-more', '+' + rest + ' more');
            more.href = INBOX_UNREAD;
            more.setAttribute('data-alert-more', '1');
            box.appendChild(more);
        }
    }

    // ---------------------------------------------------------------- bell panel

    function closePanel() {
        if (panel) { panel.classList.remove('is-open'); }
        if (panelButton) { panelButton.setAttribute('aria-expanded', 'false'); }
    }

    function placePanel(button) {
        var r = button.getBoundingClientRect();
        var width = Math.min(384, window.innerWidth - 16);
        var left = (window.innerWidth - r.right - 8 >= width) ? r.right + 8 : Math.max(8, Math.min(r.left, window.innerWidth - width - 8));
        panel.style.left = left + 'px';
        if (r.top < window.innerHeight / 2) {
            // A bell near the top of the screen (the menu's top bar): the panel opens downward from it.
            var top = Math.max(8, r.top);
            panel.style.top = top + 'px';
            panel.style.bottom = 'auto';
            panel.style.maxHeight = Math.max(160, Math.min(window.innerHeight * 0.7, window.innerHeight - top - 8)) + 'px';
            return;
        }
        panel.style.top = 'auto';
        panel.style.bottom = Math.max(8, window.innerHeight - r.bottom) + 'px';
        panel.style.maxHeight = Math.max(160, Math.min(window.innerHeight * 0.7, r.bottom - 8)) + 'px';
    }

    function fillPanel(items) {
        var list = panel.querySelector('.cxa-list');
        list.textContent = '';
        if (!items.length) {
            list.appendChild(el('div', 'cxa-empty', 'No unread notifications'));
            return;
        }
        items.forEach(function (item) {
            var a = el('a', 'cxa-item');
            a.href = item.open_url || INBOX;
            a.setAttribute('data-alert-item', item.id);
            a.appendChild(el('div', 'cxa-title', titleOf(item)));
            if (item.body) { a.appendChild(el('div', 'cxa-text', item.body)); }
            a.appendChild(el('div', 'cxa-time', ago(item)));
            a.addEventListener('click', function () { dropPending(item.id); });
            list.appendChild(a);
        });
    }

    function ensurePanel() {
        if (panel) { return panel; }
        panel = el('div');
        panel.id = 'cxa-panel';
        var head = el('div', 'cxa-head');
        head.appendChild(el('span', null, 'Notifications'));
        var all = el('button', 'cxa-btn', 'Mark all read');
        all.type = 'button';
        all.setAttribute('data-alert-read-all', '1');
        all.addEventListener('click', function () {
            pending.forEach(function (i) { clearTimer(i.id); });
            pending = []; savePending(); renderToasts();
            post(READ_ALL).then(function () { setCount(0); fillPanel([]); });
        });
        head.appendChild(all);
        panel.appendChild(head);
        panel.appendChild(el('div', 'cxa-list'));
        var foot = el('div', 'cxa-foot');
        var view = el('a', 'cxa-btn', 'View all');
        view.href = INBOX;
        foot.appendChild(view);
        panel.appendChild(foot);
        document.body.appendChild(panel);
        return panel;
    }

    function openPanel(button) {
        ensurePanel();
        panelButton = button;
        button.setAttribute('aria-expanded', 'true');
        placePanel(button);
        panel.classList.add('is-open');
        getJson(BELL).then(function (data) {
            if (typeof data.unread_count === 'number') { setCount(data.unread_count); }
            fillPanel(data.items || []);
            placePanel(button);
        }).catch(function () {
            fillPanel([]);
        });
    }

    function wireBell() {
        document.querySelectorAll('[data-alert-bell]').forEach(function (button) {
            if (button.__cxaWired) { return; }
            button.__cxaWired = true;
            button.addEventListener('click', function (e) {
                e.stopPropagation();
                if (panel && panel.classList.contains('is-open') && panelButton === button) { closePanel(); } else { openPanel(button); }
            });
        });
        if (unreadCount !== null) { setCount(unreadCount); }
    }

    document.addEventListener('click', function (e) {
        if (panel && panel.classList.contains('is-open') && !panel.contains(e.target)) { closePanel(); }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closePanel(); } });
    window.addEventListener('resize', function () { if (panel && panel.classList.contains('is-open') && panelButton) { placePanel(panelButton); } });

    // ---------------------------------------------------------------- poll

    function handle(payload) {
        if (!payload) { return; }
        if (payload.cursor) { cursor = payload.cursor; saveCursor(); }
        if (typeof payload.unread_count === 'number') { setCount(payload.unread_count); }

        // a toast whose alert was read somewhere else (the bell, another tab, the inbox) goes
        if (Array.isArray(payload.still_unread)) {
            var alive = {};
            payload.still_unread.forEach(function (id) { alive[id] = true; });
            pending.forEach(function (i) { if (!alive[i.id]) { clearTimer(i.id); } });
            pending = pending.filter(function (i) { return alive[i.id]; });
        }

        var items = payload.items || [];
        var fresh = items.filter(function (item) { return !seen[item.id]; });
        items.forEach(function (item) { seen[item.id] = true; });
        saveSeen();

        if (fresh.length && payload.in_app === true) {
            var have = {};
            pending.forEach(function (i) { have[i.id] = true; });
            fresh.forEach(function (item) { if (!have[item.id]) { pending.push(item); } });
            pending.sort(function (a, b) { return stamp(b) - stamp(a); });
        }
        savePending();
        renderToasts();
    }

    function getJson(url) {
        if (window.CoreX && window.CoreX.api) { return window.CoreX.api.fetch(url); }
        return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }).then(function (res) {
            if (!res.ok) { var err = new Error('alerts ' + res.status); err.status = res.status; throw err; }
            return res.json();
        });
    }

    function delay() { return Math.min(MAX_DELAY_MS, INTERVAL_MS * Math.pow(2, Math.min(failures, 6))); }

    function schedule() {
        if (stopped) { return; }
        if (timer) { clearTimeout(timer); }
        timer = setTimeout(tick, delay());
    }

    function stop() {
        stopped = true;
        if (timer) { clearTimeout(timer); timer = null; }
        document.removeEventListener('visibilitychange', onVisible);
    }

    /** True while the login is alive. Only a definite "logged out" answer (401/419) from the session probe says false. */
    function sessionAlive() {
        return getJson(SESSION_PROBE).then(function () { return true; }).catch(function (err) {
            return !(err && (err.status === 401 || err.status === 419));
        });
    }

    function pollUrl() {
        var params = [];
        if (cursor) { params.push('after=' + encodeURIComponent(cursor)); }
        if (pending.length) { params.push('pending=' + encodeURIComponent(pending.slice(0, 25).map(function (i) { return i.id; }).join(','))); }

        return FEED + (params.length ? '?' + params.join('&') : '');
    }

    function poll() {
        if (stopped || busy) { return Promise.resolve(); }
        busy = true;
        return getJson(pollUrl()).then(function (payload) {
            failures = 0;
            handle(payload);
        }).catch(function (err) {
            failures++;
            if (err && (err.status === 401 || err.status === 419)) {
                return sessionAlive().then(function (alive) { if (!alive) { stop(); } });
            }
        }).then(function () {
            busy = false;
            schedule();
        });
    }

    function tick() {
        if (document.hidden) { schedule(); return; }
        poll();
    }

    function onVisible() { if (!document.hidden && !stopped) { poll(); } }

    function start() {
        wireBell();
        renderToasts();      // toasts not yet dismissed on the previous page are drawn at once; the first poll then checks they are still unread
        poll();              // straight away on every page load
        document.addEventListener('visibilitychange', onVisible);
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
</script>
@endauth
