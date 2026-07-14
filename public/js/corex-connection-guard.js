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

    var Guard = {
        _online: (typeof navigator !== 'undefined' && 'onLine' in navigator) ? navigator.onLine : true,
        _tokenSinks: [],
        _heartbeat: null,

        /* ── Pre-flight ping (the TRUTH). Resolves true=reachable, false=down. ── */
        ping: function () {
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
                'background:rgba(15,23,42,.55);font-family:Figtree,-apple-system,Segoe UI,Roboto,sans-serif;';
            var card = document.createElement('div');
            card.style.cssText =
                'background:#fff;max-width:420px;width:calc(100% - 2rem);border-radius:12px;padding:1.5rem 1.5rem 1.25rem;' +
                'box-shadow:0 20px 60px rgba(0,0,0,.35);text-align:center;';
            card.innerHTML =
                '<div style="font-size:2rem;line-height:1;margin-bottom:.5rem;">📶</div>' +
                '<div style="font-weight:700;font-size:1.05rem;color:#0f172a;margin-bottom:.35rem;">You got disconnected</div>' +
                '<div style="font-size:.9rem;color:#475569;line-height:1.5;margin-bottom:1.1rem;">' +
                'Your work is safe on this screen — nothing was lost. Click below to reconnect, then try again.</div>';
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = 'Click here to reconnect';
            btn.style.cssText =
                'background:#0ea5e9;color:#fff;border:0;border-radius:8px;padding:.6rem 1rem;font-weight:700;' +
                'font-size:.9rem;cursor:pointer;width:100%;';
            btn.addEventListener('click', function () {
                btn.disabled = true; btn.textContent = 'Reconnecting…';
                self.ping().then(function (ok) {
                    if (ok === true) { self._markOnline(); self.hideDisconnectPopup(); }
                    else { btn.disabled = false; btn.textContent = 'Still offline — try again'; }
                });
            });
            card.appendChild(btn);
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
                'font-family:Figtree,sans-serif;font-size:.85rem;font-weight:600;padding:.5rem .85rem;' +
                'border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.2);';
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
                    return _nativeFetch(input, init);
                });
            };

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
