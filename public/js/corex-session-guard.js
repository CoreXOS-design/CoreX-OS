/**
 * CoreX Session Guard  (AT-220)
 * ---------------------------------------------------------------------------
 * Reusable "session armour" for LONG-LIVED authoring screens (document editor,
 * template editor, compiler studio, e-sign wizard, …). It makes CSRF/session
 * expiry a non-event:
 *
 *   1. PREVENTION — a heartbeat GET (well under SESSION_LIFETIME) both refreshes
 *      the page's CSRF token AND slides the session's activity, so an actively
 *      open tab never expires.
 *   2. AUTO-RECOVERY — guardedSubmit() retries a 419-rejected write ONCE with a
 *      freshly-fetched token; if the session is still alive (token just rotated)
 *      the retry succeeds silently and the user never notices.
 *   3. HONEST WORST-CASE — if recovery is genuinely impossible (session dead),
 *      the user NEVER sees an HTTP status code. They see a plain-language banner
 *      telling them their work is safe and how to get back in. No "419", ever.
 *
 * DOCTRINE: every long-lived screen should route its saves through this, not
 * hand-roll a fetch + toast. Reuse the banner; do not print error codes at users.
 */
(function () {
    'use strict';

    var DEFAULT_MESSAGE =
        'Your connection to CoreX has been lost. Your work is safe in this tab — ' +
        'log in to CoreX in a new tab, come back here, and press Save again.';

    var Guard = {
        tokenUrl: '/api/v1/csrf-token',
        _heartbeat: null,

        /**
         * Fetch a fresh CSRF token from the server.
         * @returns {Promise<string|null>} token, or null if the session is dead.
         */
        refreshToken: function () {
            return fetch(this.tokenUrl, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store'
            }).then(function (r) {
                if (r.status === 200) {
                    return r.json().then(function (j) { return (j && j.token) || null; });
                }
                return null; // 401 / 419 / 302→login → session is gone
            }).catch(function () {
                return null; // network blip → treat as "couldn't refresh"
            });
        },

        /**
         * Submit a guarded write. The caller owns the actual request so this works
         * for any endpoint/payload.
         *
         * @param {function(string):Promise<Response>} doRequest  called with the
         *        current token; must POST and resolve the fetch Response.
         * @param {object} opts
         *        - getToken(): current token
         *        - setToken(t): persist a refreshed token back onto the page
         *        - silent: if true, suppress the banner on hard failure (autosave)
         *        - onSessionDead(): optional callback when recovery is impossible
         * @returns {Promise<Response>} resolves on success (incl. after 1 retry),
         *          rejects with {sessionDead:true} when recovery is impossible.
         */
        guardedSubmit: function (doRequest, opts) {
            opts = opts || {};
            var self = this;
            var token = opts.getToken ? opts.getToken() : null;

            return doRequest(token).then(function (r) {
                if (r.status !== 419) return r; // success or a non-CSRF error → caller handles

                // 419 → the token/session was rejected. Try to recover once.
                return self.refreshToken().then(function (fresh) {
                    if (!fresh) {
                        // Session is genuinely gone — cannot recover in the moment.
                        if (!opts.silent) self.showConnectionLost(opts.onRetry);
                        if (opts.onSessionDead) opts.onSessionDead();
                        var err = new Error('session-expired');
                        err.sessionDead = true;
                        throw err;
                    }
                    if (opts.setToken) opts.setToken(fresh);
                    self.hideConnectionLost();
                    return doRequest(fresh); // resubmit the SAME payload, fresh token
                });
            });
        },

        /**
         * Keep the session + token alive while the tab is open.
         * @param {function(string)} setToken  persist refreshed token onto the page
         * @param {number} intervalMs  default 10 min (SESSION_LIFETIME is 120)
         */
        startHeartbeat: function (setToken, intervalMs) {
            var self = this;
            if (this._heartbeat) clearInterval(this._heartbeat);
            this._heartbeat = setInterval(function () {
                self.refreshToken().then(function (t) {
                    if (t && setToken) setToken(t);
                    // On failure: stay silent. The next real save surfaces the banner
                    // if the session is truly dead — avoids false alarms on blips.
                });
            }, intervalMs || 10 * 60 * 1000);
        },

        /* ----- The reusable "connection lost" banner (no error codes) ----- */

        showConnectionLost: function (onRetry, message) {
            if (document.getElementById('corex-session-banner')) return;

            var bar = document.createElement('div');
            bar.id = 'corex-session-banner';
            bar.setAttribute('role', 'alert');
            bar.style.cssText =
                'position:fixed;top:0;left:0;right:0;z-index:99999;' +
                'background:#8a1c2b;color:#fff;font-family:Figtree,-apple-system,Segoe UI,Roboto,sans-serif;' +
                'padding:.75rem 1rem;display:flex;align-items:center;gap:.75rem;justify-content:center;' +
                'box-shadow:0 2px 10px rgba(0,0,0,.25);font-size:.9rem;line-height:1.4;';

            var text = document.createElement('span');
            text.textContent = message || DEFAULT_MESSAGE;
            bar.appendChild(text);

            if (onRetry) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = 'Try saving again';
                btn.style.cssText =
                    'flex-shrink:0;background:#fff;color:#8a1c2b;border:0;border-radius:6px;' +
                    'padding:.4rem .8rem;font-weight:700;cursor:pointer;font-size:.85rem;';
                btn.addEventListener('click', function () { onRetry(); });
                bar.appendChild(btn);
            }

            document.body.appendChild(bar);
        },

        hideConnectionLost: function () {
            var el = document.getElementById('corex-session-banner');
            if (el) el.remove();
        }
    };

    window.CoreXSessionGuard = Guard;
})();
