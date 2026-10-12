{{--
    AT-451 — live popup for a NEW alert (Johan, 12 Oct 2026).

    A quiet poll of the unread-alerts endpoint (/api/v1/notifications?unread=1). An unread alert that appears AFTER the
    page loaded, that this browser session has not shown yet, pops up through the app's own toast (window.showToast,
    components/toast-notifications) and clicks through to the alert's own link.

    Rules this honours:
      - how often it asks is the agency's setting (Settings -> Dashboard -> "Check for new alerts every", default 30s,
        never below 15s) — AlertSwitchService::pollSecondsFor();
      - alerts already unread when the page loaded are never popped up, and an alert is never shown twice in a session
        (the ids seen are kept in sessionStorage, per user);
      - nothing is polled while the tab is hidden, and it catches up the moment the tab is visible again;
      - a 401/419 (logged out / session ended) stops the poll cleanly, with no console noise;
      - the user's own in-app switch wins: the endpoint says whether in-app alerts are on, and if not, nothing pops up;
      - staff layouts only (layouts/corex.blade.php) — never the public portal or public signing pages — and never
        inside an embedded frame;
      - more than three new alerts at once show two and a "N more" line, so a burst never buries the screen.
    The unread count is broadcast as a `corex:alerts-unread` window event for anything that wants to show it.
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

    var ENDPOINT = @json(route('v1.notifications.index', ['unread' => 1, 'limit' => 20]));
    var INBOX = @json(route('command-center.notifications'));
    var INTERVAL_MS = Math.max(15, {{ (int) $__alertPopupSeconds }}) * 1000;
    var KEY = 'corex_alerts_seen_{{ (int) $__alertPopupUser->id }}';

    var seen = {};
    var baselineMax = 0;
    var baselineDone = false;
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

    function titleOf(item) {
        return item.title || (item.data && (item.data.title || item.data.message)) || 'New alert';
    }

    function linkOf(item) {
        return item.action_url || (item.data && (item.data.action_url || item.data.url)) || null;
    }

    function handle(payload) {
        var items = (payload && payload.items) ? payload.items : [];
        var unread = items.filter(function (item) { return !item.read_at; });

        try {
            window.dispatchEvent(new CustomEvent('corex:alerts-unread', { detail: { unread: (payload && payload.unread) || 0 } }));
        } catch (e) { /* ignore */ }

        // New = not seen, and not older than what was already waiting when the page loaded (so an older unread alert
        // sliding into the 20-item window after something is read is never mistaken for a new one).
        var fresh = baselineDone
            ? unread.filter(function (item) { return !seen[item.id] && stamp(item) >= baselineMax; })
            : [];

        unread.forEach(function (item) {
            seen[item.id] = true;
            if (!baselineDone) { baselineMax = Math.max(baselineMax, stamp(item)); }
        });
        baselineDone = true;
        saveSeen();

        if (!fresh.length || !payload || payload.in_app !== true || typeof window.showToast !== 'function') { return; }

        fresh.sort(function (a, b) { return stamp(a) - stamp(b); });
        var shown = fresh.length > 3 ? fresh.slice(0, 2) : fresh;
        shown.forEach(function (item) { window.showToast(titleOf(item), 'info', 9000, linkOf(item)); });
        if (fresh.length > shown.length) {
            window.showToast((fresh.length - shown.length) + ' more new alerts', 'info', 9000, INBOX);
        }
    }

    function stop() {
        stopped = true;
        if (timer) { clearInterval(timer); timer = null; }
        document.removeEventListener('visibilitychange', onVisible);
    }

    function poll() {
        if (stopped || busy) { return; }
        busy = true;
        var request = (window.CoreX && window.CoreX.api)
            ? window.CoreX.api.fetch(ENDPOINT)
            : fetch(ENDPOINT, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (res) {
                if (!res.ok) { var err = new Error('alerts ' + res.status); err.status = res.status; throw err; }
                return res.json();
            });
        request.then(handle).catch(function (err) {
            if (err && (err.status === 401 || err.status === 419)) { stop(); }
        }).then(function () { busy = false; });
    }

    function onVisible() {
        if (!document.hidden) { poll(); }
    }

    poll(); // the baseline: whatever is already waiting is never popped up
    timer = setInterval(function () { if (!document.hidden) { poll(); } }, INTERVAL_MS);
    document.addEventListener('visibilitychange', onVisible);
})();
</script>
@endauth
