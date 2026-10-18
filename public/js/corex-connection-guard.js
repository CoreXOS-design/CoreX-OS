/**
 * CoreX Connection Guard  (AT-263 — Johan + Andre design, 2026-07-14)
 * ---------------------------------------------------------------------------
 * Replaces the AT-220 header "connection light" (removed) with an ACTIVE guard:
 *
 *   1. INSTANT DISCONNECT POPUP — the browser `offline` event triggers a modal
 *      "You got disconnected — click here to reconnect". The browser event is the
 *      instant signal; a pre-flight PING (GET /api/v1/csrf-token) is the TRUTH.
 *   2. PRE-FLIGHT ON EVERY SAVE — three global choke points gate MUTATING writes:
 *        (a) native <form> submit (capture-phase document delegate),
 *        (b) window.fetch wrapper,
 *        (c) window.axios request interceptor.
 *      Offline → the write is BLOCKED, the user's typed work is preserved
 *      (preventDefault, nothing navigates), and the popup shows. Kills the dino.
 *      Online → the write proceeds (native re-submit via requestSubmit(submitter)
 *      so the clicked button's value survives).
 *   3. FAIL-OPEN — if OUR OWN check errors or times out, the write is ALLOWED.
 *      The guard must never be the reason a save is lost.
 *   4. QUIET "Back online ✓" — a small toast when connectivity returns.
 *
 * Session keep-alive (the heartbeat + CSRF-token refresh from AT-220) is UNCHANGED
 * underneath — it still slides the session so an open tab never expires.
 *
 * AT-454 follow-up (13 Oct 2026, Sherry demo) - THE SAVE NEVER DIES ON A STALE TOKEN:
 *   Every mutating same-origin request (fetch, XMLHttpRequest, native form) is sent with the LIVE CSRF token, not the one the
 *   page was rendered with. A page's token can go stale for reasons that are not the agent's doing (another tab signed in or
 *   out, the session was renewed). fetch: on a 419 the token is refreshed and the request is sent ONCE more; if the session
 *   is really gone the caller gets a plain JSON message (status 419) instead of a raw error, and its screen keeps what was
 *   typed / captured. XHR (photo uploads): the token is refreshed just before the send. Forms: the hidden _token is synced
 *   on submit. The tab also refreshes its token when it comes back to the foreground.
 *
 * Exception families that OPT OUT of the save-block (documented, see shouldGuard):
 *   • GET forms / read requests — not mutating, never gated.
 *   • data-noguard on a <form>, or the fetch/axios `X-CoreX-NoGuard` header — an
 *     explicit escape hatch for flows that manage their own offline handling
 *     (file uploads that stream, target=_blank downloads, third-party embeds).
 */
(function () {
    'use strict';
    if (window.CoreXConnectionGuard) return; // load-once

    var PING_URL     = '/api/v1/csrf-token';
    var PING_TIMEOUT = 3000; // ms — fail-OPEN past this; never hold a save hostage
    var MUTATING     = { POST: 1, PUT: 1, PATCH: 1, DELETE: 1 };
    // AT-336 restyle — the guard's own chrome (popup + toast) is built in JS, so it
    // carries the design-system tokens inline: the Inter stack, and var(--surface/
    // --border/--text-*/--shadow/--brand-button) so the popup follows the live
    // html.dark theme instead of being hardcoded light. Fallbacks keep it readable
    // if the guard ever loads on a page without corex.css.
    var FONT_STACK = 'font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;';

    var Guard = {
        _online: (typeof navigator !== 'undefined' && 'onLine' in navigator) ? navigator.onLine : true,
        _tokenSinks: [],
        _heartbeat: null,
        _freshAt: 0,
        _freshPromise: null,
        _visAt: 0,
        EXPIRED_MESSAGE: 'Your session has ended. Sign in again in a new tab, then send this again - what you captured is still on this screen.',

        /* ── The live CSRF token: the page-wide <meta>, kept fresh by every ping / heartbeat / refresh ── */
        liveToken: function () {
            var m = (typeof document !== 'undefined' && document.querySelector) ? document.querySelector('meta[name="csrf-token"]') : null;
            return (m && m.getAttribute && m.getAttribute('content')) || null;
        },
        /* Refresh the token now (de-duplicated for 2 s so a batch of uploads costs one call). Fail-open: resolves the
           meta token if the refresh itself fails. */
        freshToken: function () {
            var self = this, now = Date.now();
            if (this._freshPromise && now - this._freshAt < 2000) return this._freshPromise;
            this._freshAt = now;
            this._freshPromise = this.refreshToken().then(function (t) { if (t) self._applyToken(t); return t || self.liveToken(); });
            return this._freshPromise;
        },
        _sameOrigin: function (input) {
            var u = (input && typeof input === 'object' && input.href) ? input.href : (typeof input === 'string' ? input : '');
            if (!u) return false;
            if (u.charAt(0) === '/' && u.charAt(1) !== '/') return true;
            var origin = (typeof location !== 'undefined' && location.origin) ? location.origin : null;
            if (origin && u.indexOf(origin) === 0) return true;
            return !/^(?:[a-z][a-z0-9+.-]*:)?\/\//i.test(u); // a bare relative path
        },
        /* Return a copy of fetch's init carrying `token` in X-CSRF-TOKEN (replacing a stale one; `force` adds it). */
        _withToken: function (init, token, force) {
            if (!token) return init;
            var out = {}, k;
            if (init) { for (k in init) { if (Object.prototype.hasOwnProperty.call(init, k)) out[k] = init[k]; } }
            var h = out.headers;
            if (!h) { if (force) out.headers = { 'X-CSRF-TOKEN': token }; }
            else if (typeof h.get === 'function' && typeof h.set === 'function') {
                if (h.get('X-CSRF-TOKEN') || force) { var c = new Headers(h); c.set('X-CSRF-TOKEN', token); out.headers = c; }
            } else if (Array.isArray(h)) {
                var found = false;
                var arr = h.map(function (pair) { if (String(pair[0]).toLowerCase() === 'x-csrf-token') { found = true; return [pair[0], token]; } return pair; });
                if (!found && force) arr.push(['X-CSRF-TOKEN', token]);
                out.headers = arr;
            } else {
                var o = {}, f = false;
                Object.keys(h).forEach(function (key) { if (key.toLowerCase() === 'x-csrf-token') { o[key] = token; f = true; } else o[key] = h[key]; });
                if (!f && force) o['X-CSRF-TOKEN'] = token;
                out.headers = o;
            }
            if (typeof FormData !== 'undefined' && out.body instanceof FormData && out.body.has('_token')) out.body.set('_token', token);
            return out;
        },
        _expired: function () {
            try { window.dispatchEvent(new CustomEvent('corex:session-expired')); } catch (e) {}
            var msg = JSON.stringify({ message: this.EXPIRED_MESSAGE });
            if (typeof Response !== 'undefined') return new Response(msg, { status: 419, headers: { 'Content-Type': 'application/json' } });
            return { status: 419, ok: false, json: function () { return Promise.resolve({ message: Guard.EXPIRED_MESSAGE }); } };
        },
        /* A mutating same-origin fetch: live token on the way out; on 419 refresh and send once more. */
        _sendGuarded: function (input, init) {
            var self = this;
            var isReq = (typeof Request !== 'undefined') && input instanceof Request;
            if (isReq || !this._sameOrigin(input)) return _nativeFetch(input, init);
            return _nativeFetch(input, this._withToken(init, this.liveToken(), false)).then(function (res) {
                if (res.status !== 419) return res;
                return self.refreshToken().then(function (fresh) {
                    if (!fresh) return self._expired();
                    self._applyToken(fresh);
                    return _nativeFetch(input, self._withToken(init, fresh, true)).then(function (r2) { return r2.status === 419 ? self._expired() : r2; });
                });
            });
        },
        _syncFormToken: function (form) {
            var t = this.liveToken();
            var inp = t && form && form.querySelector ? form.querySelector('input[name="_token"]') : null;
            if (inp && inp.value !== t) inp.value = t;
        },

        /* ── Pre-flight ping (the TRUTH). Resolves true=reachable, false=down. ── */
        ping: function () {
            var self = this;
            var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, PING_TIMEOUT) : null;
            return _nativeFetch(PING_URL, {
                method: 'GET',
                headers: { 'Accept': 'application/json', 'X-CoreX-NoGuard': '1' },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: ctrl ? ctrl.signal : undefined
            }).then(function (r) {
                if (timer) clearTimeout(timer);
                if (r.status === 200 && typeof r.json === 'function') {
                    // the ping IS a token refresh: keep the page-wide token live before the save goes out
                    return r.json().then(function (j) { if (j && j.token) self._applyToken(j.token); return true; }, function () { return true; });
                }
                return r.status === 200; // 200 = reachable AND authed; 401/302/5xx = treat as down
            }).catch(function () {
                if (timer) clearTimeout(timer);
                return null; // OUR failure (network/abort) → null = "unknown" → callers FAIL OPEN
            });
        },

        /**
         * Gate a save. Resolves true = proceed (online, OR unknown → fail-open),
         * false = BLOCK (confirmed offline). Shows the popup when it blocks.
         */
        preflight: function () {
            var self = this;
            return this.ping().then(function (ok) {
                if (ok === false) { self._online = false; self.showDisconnectPopup(); return false; }
                if (ok === true)  { self._markOnline(); }
                return true; // true or null(unknown) → proceed (fail-open)
            });
        },

        /* ── The disconnect popup (replaces the AT-220 light + banner) ── */
        showDisconnectPopup: function () {
            if (document.getElementById('corex-conn-popup')) return;
            var self = this;
            var overlay = document.createElement('div');
            overlay.id = 'corex-conn-popup';
            overlay.setAttribute('role', 'alertdialog');
            overlay.style.cssText =
                'position:fixed;inset:0;z-index:2147483646;display:flex;align-items:center;justify-content:center;' +
                'background:rgba(2,6,23,.55);' + FONT_STACK;
            var card = document.createElement('div');
            card.style.cssText =
                'background:var(--surface,#ffffff);border:1px solid var(--border,rgba(0,0,0,0.07));' +
                'max-width:420px;width:calc(100% - 2rem);border-radius:8px;padding:1.5rem 1.5rem 1.25rem;' +
                'box-shadow:0 20px 60px var(--shadow,rgba(0,0,0,.35));text-align:center;';
            card.innerHTML =
                '<div style="font-weight:700;font-size:1rem;line-height:1.25;color:var(--text-primary,#111827);margin-bottom:.35rem;">You got disconnected</div>' +
                '<div style="font-size:.8125rem;color:var(--text-secondary,#4b5563);line-height:1.5;margin-bottom:1.1rem;">' +
                'Your work is safe on this screen — nothing was lost. Click below to reconnect, then try again.</div>';
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = 'Click here to reconnect';
            btn.style.cssText =
                'background:var(--brand-button,#0ea5e9);color:#fff;border:0;border-radius:6px;padding:.6rem 1rem;' +
                'font-weight:600;font-size:.8125rem;cursor:pointer;width:100%;' +
                'box-shadow:0 4px 12px color-mix(in srgb, var(--brand-button,#0ea5e9) 25%, transparent);' + FONT_STACK;
            btn.addEventListener('click', function () {
                btn.disabled = true; btn.textContent = 'Reconnecting…';
                self.ping().then(function (ok) {
                    if (ok === true) { self._markOnline(); self.hideDisconnectPopup(); }
                    else { btn.disabled = false; btn.textContent = 'Still offline — try again'; }
                });
            });
            card.appendChild(btn);
            // Secondary fallback guidance — if reconnect keeps failing (e.g. after a
            // long time offline), opening CoreX in a NEW browser tab and signing in
            // restores the connection without disturbing this screen's work.
            var note = document.createElement('div');
            note.style.cssText =
                'font-size:.78rem;color:#64748b;line-height:1.45;margin-top:.85rem;padding-top:.85rem;' +
                'border-top:1px solid #e2e8f0;text-align:left;';
            note.innerHTML =
                'Still not reconnecting? Open CoreX in a <strong>new browser tab</strong> and sign in ' +
                'to restore your connection. Your work on this screen stays safe — just don’t refresh this tab.';
            card.appendChild(note);
            overlay.appendChild(card);
            document.body.appendChild(overlay);
        },
        hideDisconnectPopup: function () {
            var el = document.getElementById('corex-conn-popup');
            if (el) el.remove();
        },

        _markOnline: function () {
            var was = this._online;
            this._online = true;
            this.hideDisconnectPopup();
            if (was === false) this._toast('Back online ✓');
        },

        _toast: function (msg) {
            if (typeof window.showToast === 'function') { window.showToast(msg, 'success'); return; }
            var t = document.createElement('div');
            t.textContent = msg;
            t.style.cssText =
                'position:fixed;top:16px;right:16px;z-index:2147483647;background:#059669;color:#fff;' +
                'font-size:.8125rem;font-weight:600;padding:.5rem .85rem;' +
                'border-radius:6px;box-shadow:0 4px 12px var(--shadow,rgba(0,0,0,.2));' + FONT_STACK;
            document.body.appendChild(t);
            setTimeout(function () { t.remove(); }, 3000);
        },

        /* ── The 3 choke points ── */
        installInterceptors: function () {
            var self = this;

            // (a) Native form submit — capture phase so we run BEFORE Alpine @submit / onsubmit.
            document.addEventListener('submit', function (e) {
                var form = e.target;
                if (!(form instanceof HTMLFormElement)) return;
                self._syncFormToken(form);
                if (form.__coreXGuardPassed) { form.__coreXGuardPassed = false; return; } // our own re-submit
                if (!self._isMutatingForm(form) || self._optedOut(form)) return;

                var submitter = e.submitter || null;
                e.preventDefault();               // typed work preserved (no navigation)
                e.stopImmediatePropagation();      // hold Alpine/onsubmit until we clear it
                self.preflight().then(function (proceed) {
                    if (!proceed) return;          // blocked → popup already shown
                    form.__coreXGuardPassed = true;
                    if (form.requestSubmit) form.requestSubmit(submitter); // keeps the clicked button
                    else form.submit();
                });
            }, true);

            // (b) fetch wrapper — mutating requests only, fail-open.
            window.fetch = function (input, init) {
                var method = ((init && init.method) || (input && input.method) || 'GET').toUpperCase();
                var noguard = init && init.headers && _hasHeader(init.headers, 'X-CoreX-NoGuard');
                if (!MUTATING[method] || noguard) return _nativeFetch(input, init);
                return self.preflight().then(function (proceed) {
                    if (!proceed) { var e = new Error('offline'); e.coreXOffline = true; throw e; }
                    return self._sendGuarded(input, init);
                });
            };

            // (d) XMLHttpRequest (photo uploads stream through XHR for progress): token refreshed just before the send.
            var XHR = window.XMLHttpRequest;
            if (XHR && XHR.prototype && !XHR.prototype.__coreXToken) {
                var _open = XHR.prototype.open, _send = XHR.prototype.send, _setH = XHR.prototype.setRequestHeader, _abort = XHR.prototype.abort;
                XHR.prototype.__coreXToken = true;
                XHR.prototype.open = function (m, u, a) {
                    this.__cx = { method: String(m || 'GET').toUpperCase(), url: u, async: a !== false, csrf: false, noguard: false, aborted: false };
                    return _open.apply(this, arguments);
                };
                XHR.prototype.setRequestHeader = function (k, v) {
                    var cx = this.__cx, name = String(k).toLowerCase();
                    if (cx && name === 'x-csrf-token') { cx.csrf = true; return; }  // set at send time, with the live token
                    if (cx && name === 'x-corex-noguard') cx.noguard = true;
                    return _setH.call(this, k, v);
                };
                XHR.prototype.abort = function () { if (this.__cx) this.__cx.aborted = true; return _abort.apply(this, arguments); };
                XHR.prototype.send = function (body) {
                    var x = this, cx = this.__cx;
                    if (!cx || !MUTATING[cx.method] || !cx.async || cx.noguard || !self._sameOrigin(cx.url)) {
                        if (cx && cx.csrf) { try { _setH.call(x, 'X-CSRF-TOKEN', self.liveToken() || ''); } catch (e) {} }
                        return _send.call(x, body);
                    }
                    self.freshToken().then(function (t) {
                        if (cx.aborted) return;
                        try { _setH.call(x, 'X-CSRF-TOKEN', t || self.liveToken() || ''); } catch (e) {}
                        _send.call(x, body);
                    });
                };
            }

            // (e) Back in the foreground (e.g. after a portal tab): bring this tab's token up to date.
            var wake = function () {
                if (typeof document !== 'undefined' && document.hidden) return;
                var now = Date.now();
                if (now - self._visAt < 20000) return;
                self._visAt = now;
                self.freshToken();
            };
            if (typeof document !== 'undefined' && document.addEventListener) document.addEventListener('visibilitychange', wake);
            if (window.addEventListener) window.addEventListener('focus', wake);

            // (c) axios request interceptor — single shared instance (bootstrap.js).
            if (window.axios && window.axios.interceptors) {
                window.axios.interceptors.request.use(function (config) {
                    var method = (config.method || 'get').toUpperCase();
                    var noguard = config.headers && _hasHeader(config.headers, 'X-CoreX-NoGuard');
                    if (!MUTATING[method] || noguard) return config;
                    return self.preflight().then(function (proceed) {
                        if (!proceed) { var e = new Error('offline'); e.coreXOffline = true; return Promise.reject(e); }
                        return config;
                    });
                });
            }
        },

        _isMutatingForm: function (form) {
            var m = (form.getAttribute('method') || 'GET').toUpperCase();
            if (m !== 'POST') return false; // GET forms are reads
            var override = form.querySelector('input[name="_method"]');
            var eff = override ? override.value.toUpperCase() : 'POST';
            return !!MUTATING[eff];
        },
        _optedOut: function (form) {
            return form.hasAttribute('data-noguard') || form.getAttribute('target') === '_blank';
        },

        /* ── Session keep-alive (AT-220 — UNCHANGED underneath) ── */
        refreshToken: function () {
            return _nativeFetch(PING_URL, {
                headers: { 'Accept': 'application/json', 'X-CoreX-NoGuard': '1' },
                credentials: 'same-origin', cache: 'no-store'
            }).then(function (r) { return r.status === 200 ? r.json().then(function (j) { return (j && j.token) || null; }) : null; })
              .catch(function () { return null; });
        },
        registerTokenSink: function (fn) {
            if (typeof fn === 'function' && this._tokenSinks.indexOf(fn) === -1) this._tokenSinks.push(fn);
        },
        _applyToken: function (t) {
            if (!t) return;
            var m = (typeof document !== 'undefined' && document.querySelector) ? document.querySelector('meta[name="csrf-token"]') : null;
            if (m && m.setAttribute) m.setAttribute('content', t);
            this._tokenSinks.forEach(function (fn) { try { fn(t); } catch (e) {} });
        },
        startHeartbeat: function (setToken, intervalMs) {
            var self = this;
            this.registerTokenSink(setToken);
            if (this._heartbeat) return;
            var beat = function () { self.refreshToken().then(function (t) { if (t) self._applyToken(t); }); };
            beat();
            this._heartbeat = setInterval(beat, intervalMs || 10 * 60 * 1000);
        },

        /* ── guardedSubmit — kept for the DocuPerfect editors (AT-220 callers) ── */
        guardedSubmit: function (doRequest, opts) {
            opts = opts || {};
            var self = this;
            var token = opts.getToken ? opts.getToken() : null;
            return doRequest(token).then(function (r) {
                if (r.status !== 419) return r;
                return self.refreshToken().then(function (fresh) {
                    if (!fresh) { if (!opts.silent) self.showDisconnectPopup(); var err = new Error('session-expired'); err.sessionDead = true; throw err; }
                    if (opts.setToken) opts.setToken(fresh);
                    self._applyToken(fresh);
                    return doRequest(fresh);
                });
            });
        }
    };

    // Keep a pristine reference to the native fetch BEFORE we wrap it.
    var _nativeFetch = window.fetch.bind(window);
    function _hasHeader(h, name) {
        if (!h) return false;
        if (typeof h.get === 'function') return !!h.get(name);
        return Object.keys(h).some(function (k) { return k.toLowerCase() === name.toLowerCase(); });
    }

    // Browser connectivity events → instant popup / recovery (ping is the truth).
    window.addEventListener('offline', function () { Guard._online = false; Guard.showDisconnectPopup(); });
    window.addEventListener('online',  function () { Guard.ping().then(function (ok) { if (ok === true) Guard._markOnline(); }); });

    Guard.installInterceptors();
    window.CoreXConnectionGuard = Guard;
    // Back-compat alias — AT-220 callers referenced CoreXSessionGuard.
    window.CoreXSessionGuard = window.CoreXSessionGuard || Guard;
})();
