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

    // Load-once. The guard is now mounted GLOBALLY (layouts.partials._session-guard
    // on every authenticated screen) AND still self-included by the legacy editor
    // pages. A second <script> include must be a no-op so it can't reset the single
    // heartbeat / token-sink registry below.
    if (window.CoreXSessionGuard) return;

    var DEFAULT_MESSAGE =
        'Your connection to CoreX has been lost. Your work is safe in this tab — ' +
        'log in to CoreX in a new tab, come back here, and press Save again.';

    var Guard = {
        tokenUrl: '/api/v1/csrf-token',
        _heartbeat: null,
        // Every fresh token is fanned out to ALL registered sinks — the global
        // one updates <meta name="csrf-token"> (so the whole page's AJAX stays
        // valid); a long-lived editor registers its own to update its in-memory
        // token too. One heartbeat, many consumers.
        _tokenSinks: [],

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
                        self.setIndicatorState('offline');
                        if (!opts.silent) self.showConnectionLost(opts.onRetry || function () { self.reconnect(); });
                        if (opts.onSessionDead) opts.onSessionDead();
                        var err = new Error('session-expired');
                        err.sessionDead = true;
                        throw err;
                    }
                    if (opts.setToken) opts.setToken(fresh);
                    self._applyToken(fresh); // also refresh the global meta + other sinks
                    self.hideConnectionLost();
                    self.setIndicatorState('ok');
                    return doRequest(fresh); // resubmit the SAME payload, fresh token
                });
            });
        },

        /**
         * Keep the session + token alive while the tab is open, and drive the
         * persistent connection indicator (mounted automatically) off each tick.
         * @param {function(string)} setToken  persist refreshed token onto the page
         * @param {number} intervalMs  default 10 min (SESSION_LIFETIME is 120)
         */
        startHeartbeat: function (setToken, intervalMs) {
            var self = this;
            this.registerTokenSink(setToken);
            this.mountIndicator();

            // Idempotent: the FIRST caller owns the single interval; later callers
            // (e.g. the editor after the global mount) only add their token sink.
            if (this._heartbeat) return;

            var beat = function () {
                self.refreshToken().then(function (t) {
                    if (t) {
                        self._applyToken(t);
                        self.setIndicatorState('ok');   // silent green
                    } else {
                        // Couldn't reach/authenticate — surface it on the indicator
                        // (unobtrusive), but NOT the banner (that's save-time only).
                        self.setIndicatorState('offline');
                    }
                });
            };
            beat(); // immediate first check so the dot reflects truth on load
            this._heartbeat = setInterval(beat, intervalMs || 10 * 60 * 1000);
        },

        /** Register a callback that receives every freshly-refreshed token. */
        registerTokenSink: function (fn) {
            if (typeof fn === 'function' && this._tokenSinks.indexOf(fn) === -1) {
                this._tokenSinks.push(fn);
            }
        },

        /** Fan a fresh token out to every registered sink (never throws). */
        _applyToken: function (t) {
            if (!t) return;
            this._tokenSinks.forEach(function (fn) {
                try { fn(t); } catch (e) { /* a sink must never break the guard */ }
            });
        },

        /* ----- Persistent connection indicator (session-truth, reusable) ----- */

        /** Create the small fixed indicator once (top-right, out of the way). */
        mountIndicator: function () {
            if (document.getElementById('corex-conn-indicator')) return;
            var self = this;

            if (!document.getElementById('corex-conn-style')) {
                var st = document.createElement('style');
                st.id = 'corex-conn-style';
                st.textContent = '@keyframes corexPulse{0%,100%{opacity:1}50%{opacity:.35}}';
                document.head.appendChild(st);
            }

            var wrap = document.createElement('div');
            wrap.id = 'corex-conn-indicator';
            wrap.setAttribute('role', 'status');
            wrap.style.cssText =
                'position:fixed;top:10px;right:12px;z-index:99998;display:inline-flex;align-items:center;' +
                'gap:.4rem;font-family:Figtree,-apple-system,Segoe UI,Roboto,sans-serif;font-size:.75rem;' +
                'font-weight:600;padding:.2rem .5rem;border-radius:9999px;background:rgba(255,255,255,.9);' +
                'box-shadow:0 1px 4px rgba(0,0,0,.12);cursor:default;user-select:none;transition:opacity .2s;';

            var dot = document.createElement('span');
            dot.id = 'corex-conn-dot';
            dot.style.cssText = 'width:8px;height:8px;border-radius:9999px;flex-shrink:0;background:#9ca3af;';

            var label = document.createElement('span');
            label.id = 'corex-conn-label';
            label.style.cssText = 'color:#374151;display:none;'; // hidden when healthy (silent)

            wrap.appendChild(dot);
            wrap.appendChild(label);
            wrap.addEventListener('click', function () {
                if (self._connState === 'offline') self.reconnect();
            });
            document.body.appendChild(wrap);
            this.setIndicatorState('ok');
        },

        /**
         * @param {'ok'|'offline'|'reconnecting'} state
         */
        setIndicatorState: function (state) {
            this._connState = state;
            var dot = document.getElementById('corex-conn-dot');
            var label = document.getElementById('corex-conn-label');
            var wrap = document.getElementById('corex-conn-indicator');
            if (!dot || !label || !wrap) return;

            if (state === 'ok') {
                dot.style.background = '#059669';           // green
                dot.style.animation = '';
                label.style.display = 'none';               // silent when healthy
                wrap.style.cursor = 'default';
                wrap.title = 'Connected to CoreX';
            } else if (state === 'reconnecting') {
                dot.style.background = '#f59e0b';           // amber, pulsing
                dot.style.animation = 'corexPulse 1s ease-in-out infinite';
                label.style.display = '';
                label.textContent = 'Reconnecting…';
                label.style.color = '#92400e';
                wrap.style.cursor = 'wait';
            } else { // offline
                dot.style.background = '#c41e3a';           // red
                dot.style.animation = '';
                label.style.display = '';
                label.textContent = 'Offline — click to reconnect';
                label.style.color = '#8a1c2b';
                wrap.style.cursor = 'pointer';
                wrap.title = 'Your connection to CoreX dropped — click to reconnect';
            }
        },

        /** Manual reconnect (indicator click or from the banner). */
        reconnect: function () {
            var self = this;
            this.setIndicatorState('reconnecting');
            return this.refreshToken().then(function (t) {
                if (t) {
                    self._applyToken(t);
                    self.setIndicatorState('ok');
                    self.hideConnectionLost();
                    self._toast('Back online ✓');
                    return true;
                }
                // Still dead — needs a real re-login in another tab.
                self.setIndicatorState('offline');
                self.showConnectionLost(function () { self.reconnect(); });
                return false;
            });
        },

        _toast: function (msg) {
            if (typeof window.showToast === 'function') { window.showToast(msg, 'success'); return; }
            var t = document.createElement('div');
            t.textContent = msg;
            t.style.cssText =
                'position:fixed;top:44px;right:12px;z-index:99999;background:#059669;color:#fff;' +
                'font-family:Figtree,sans-serif;font-size:.8rem;font-weight:600;padding:.5rem .8rem;' +
                'border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.2);';
            document.body.appendChild(t);
            setTimeout(function () { t.remove(); }, 3000);
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
