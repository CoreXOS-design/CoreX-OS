{{--
    AT-451 — live popup for a NEW alert (Johan, 12 Oct 2026). Round 2: now works in a real staff browser session.

    A quiet poll of the session-authenticated feed GET /api/v1/alerts/unread (Api\V1\AlertFeedController, routes/web.php beside
    the reminders poll — NOT /api/v1/notifications, which is the mobile app's token-only route and answered a browser session
    with 401). An unread alert that appears AFTER the page loaded and that this browser session has not shown yet pops up
    through the app's own toast (window.showToast, components/toast-notifications) and clicks through to the alert's own link.

    The feed is a CURSOR feed, never "all unread": the first poll of a page (the baseline) sends no cursor and the server just
    answers with its clock; every later poll sends ?after=<cursor> and gets only the user's unread alerts created since, via
    the index notifications_feed_index (notifiable_type, notifiable_id, created_at). Whatever was already waiting at page load is
    therefore never popped up, and a user with tens of thousands of unread alerts costs the same as one with none.

    Rules this honours:
      - how often it asks is the agency's setting (Settings -> Dashboard -> "Check for new alerts every", default 30s, never
        below 15s) — AlertSwitchService::pollSecondsFor();
      - an alert is never shown twice in a session (ids seen are kept in sessionStorage, per user);
      - nothing is polled while the tab is hidden, and it catches up the moment the tab is visible again;
      - a failed poll is retried with a growing delay (the interval x2, x4 ... capped at 5 minutes), never given up on; a 401/419
        is checked against the session-armour probe (/api/v1/csrf-token): only if THAT also says the login is gone does polling
        stop, otherwise it was a hiccup and polling carries on;
      - the user's own in-app switch wins: the server says whether in-app alerts are on, and if not, nothing pops up;
      - staff layouts only (layouts/corex.blade.php) — never the public portal or public signing pages — and never inside a frame;
      - more than three new alerts at once show two and a "N more" line, so a burst never buries the screen.
--}}
@auth
@php
    $__alertPopupUser = auth()->user();
    $__alertPopupSeconds = app(\App\Services\CommandCenter\AlertSwitchService::class)->pollSecondsFor($__alertPopupUser->effectiveAgencyId());
@endphp
<script>
(function () {
    if (window.__corexAlertPopups || window.self !== window.top) { return; }
    window.__corexAlertPopups = true;

    var FEED = @json(route('api.v1.alerts.unread'));
    var SESSION_PROBE = @json(route('api.v1.csrf-token'));
    var INBOX = @json(route('command-center.notifications'));
    var INTERVAL_MS = Math.max(15, {{ (int) $__alertPopupSeconds }}) * 1000;
    var MAX_DELAY_MS = 5 * 60 * 1000;
    var KEY = 'corex_alerts_seen_{{ (int) $__alertPopupUser->id }}';

    var seen = {};
    var cursor = null;      // null until the baseline poll has answered
    var failures = 0;
    var stopped = false;
    var busy = false;
    var timer = null;

    try {
        var raw = window.sessionStorage.getItem(KEY);
        if (raw) { JSON.parse(raw).forEach(function (id) { seen[id] = true; }); }
    } catch (e) { /* storage blocked — the in-memory set still stops repeats on this page */ }

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

    function stamp(item) {
        var t = Date.parse(item && item.created_at);
        return isNaN(t) ? 0 : t;
    }

    function titleOf(item) { return item.title || 'New alert'; }

    function handle(payload) {
        if (payload && payload.cursor) { cursor = payload.cursor; }
        var items = (payload && payload.items) ? payload.items : [];
        var fresh = items.filter(function (item) { return !seen[item.id]; });
        items.forEach(function (item) { seen[item.id] = true; });
        saveSeen();

        if (!fresh.length || !payload || payload.in_app !== true || typeof window.showToast !== 'function') { return; }

        fresh.sort(function (a, b) { return stamp(a) - stamp(b); });
        var total = fresh.length + (payload.has_more ? 1 : 0);
        var shown = total > 3 ? fresh.slice(0, 2) : fresh;
        shown.forEach(function (item) { window.showToast(titleOf(item), 'info', 9000, item.action_url || null); });
        var rest = fresh.length - shown.length;
        if (rest > 0) {
            window.showToast(rest + (payload.has_more ? '+' : '') + ' more new alerts', 'info', 9000, INBOX);
        } else if (payload.has_more) {
            window.showToast('More new alerts are waiting', 'info', 9000, INBOX);
        }
    }

    function getJson(url) {
        if (window.CoreX && window.CoreX.api) { return window.CoreX.api.fetch(url); }
        return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }).then(function (res) {
            if (!res.ok) { var err = new Error('alerts ' + res.status); err.status = res.status; throw err; }
            return res.json();
        });
    }

    function delay() {
        return Math.min(MAX_DELAY_MS, INTERVAL_MS * Math.pow(2, Math.min(failures, 6)));
    }

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

    function poll() {
        if (stopped || busy) { return Promise.resolve(); }
        busy = true;
        return getJson(cursor ? FEED + '?after=' + encodeURIComponent(cursor) : FEED).then(function (payload) {
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

    function onVisible() {
        if (!document.hidden && !stopped) { poll(); }
    }

    poll(); // the baseline: the server answers with its clock; whatever is already waiting is never popped up
    document.addEventListener('visibilitychange', onVisible);
})();
</script>
@endauth
