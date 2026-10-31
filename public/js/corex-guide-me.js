/*
 * CoreX narrated guided tour - the "Guide me" player (AT-526, spec .ai/specs/training-videos.md 9.23).
 *
 * One step at a time on the REAL screen: a driver.js spotlight (same library and `corex-tour` look as the plain guided tour), the words in the bubble, the same
 * words spoken from a pre-made clip, and then the tour WAITS until the agent has really done the step. "Done" comes from the script step (`done`):
 *   none            just read it - a "Got it" button
 *   click           the target was clicked
 *   input           the field holds a valid value (typed and left, or already right and confirmed with the button)
 *   change          the list was changed
 *   appear:<css>    that element is now on screen
 *   url:<path>      the page moved to that path
 * Controls: Pause, Replay, Skip, Voice on/off (remembered in the browser), Close; "Step 3 of 9".
 * Off the path (the box vanishes, or the agent leaves the page) the tour stops quietly and asks "Carry on where you left off?" when they come back to the screen.
 * The first press of "Guide me" is the click that lets the browser play sound.
 *
 * No framework and no build step: a plain script, so the same file runs on the page and in the headless check (scripts/guide-me-check.mjs).
 */
(function () {
    'use strict';

    var VOICE_KEY = 'corex.guide.voice';          // 'off' = text only (remembered per browser, like the CC switch)
    var STATE_KEY = 'corex.guide.narrated';       // {screen, i, at} - where the agent got to, for "Carry on where you left off?"
    var STATE_TTL = 6 * 3600 * 1000;
    var POLL = 250;
    var SLOW_AFTER = 3000;                        // "still loading" hint for a wait_for box
    var LOAD_WAIT = 12000;                        // how long a box may take to appear before the step is skipped
    var GONE_AFTER = 1400;                        // a box that WAS there and has vanished this long = the agent is off the path
    var SILENT = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEAQB8AAIA+AAACABAAZGF0YQAAAAA=';

    function store(kind) { try { return kind === 'voice' ? window.localStorage : window.sessionStorage; } catch (e) { return null; } }
    function getJson(key, kind) { try { var s = store(kind); var v = s && s.getItem(key); return v ? JSON.parse(v) : null; } catch (e) { return null; } }
    function setItem(key, val, kind) { try { var s = store(kind); if (s) { if (val === null) s.removeItem(key); else s.setItem(key, typeof val === 'string' ? val : JSON.stringify(val)); } } catch (e) { /* private mode: carry on without memory */ } }

    function q(sel) { if (!sel) return null; try { return document.querySelector(sel); } catch (e) { return null; } }
    function isVisible(el) {
        if (!el || !el.getBoundingClientRect) return false;
        var r = el.getBoundingClientRect();
        if (r.width <= 0 || r.height <= 0) return false;
        var cs = window.getComputedStyle(el);
        return cs.visibility !== 'hidden' && cs.display !== 'none' && cs.opacity !== '0';
    }
    function controlIn(el) {
        if (!el) return null;
        if (el.matches && el.matches('input, select, textarea')) return el;
        return el.querySelector('input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit]), select, textarea');
    }
    function hasValidValue(c) {
        if (!c) return false;
        var v = String(c.value == null ? '' : c.value).trim();
        if (v === '') return false;
        try { return typeof c.checkValidity === 'function' ? c.checkValidity() : true; } catch (e) { return true; }
    }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function sleep(ms) { return new Promise(function (r) { window.setTimeout(r, ms); }); }
    function parseDone(raw) {
        raw = String(raw || 'none');
        var i = raw.indexOf(':');
        return i < 0 ? { kind: raw, arg: null } : { kind: raw.slice(0, i), arg: raw.slice(i + 1) };
    }
    function pathOf(url) { try { return new URL(url, window.location.origin).pathname.replace(/\/+$/, '') || '/'; } catch (e) { return url; } }

    function Player() {
        this.cfg = null; this.data = null; this.steps = []; this.i = -1; this.runId = 0;
        this.audio = null; this.driver = null; this.paused = false; this.active = false;
        this.cleanups = []; this.prompt = null;
    }

    Player.prototype.voiceOn = function () { return store('voice') ? store('voice').getItem(VOICE_KEY) !== 'off' : true; };

    // ---- start / resume ---------------------------------------------------------------------------------------------------------------------------------
    Player.prototype.start = function (cfg, fromIndex) {
        var self = this;
        this.cfg = cfg;
        this.closePrompt();
        // The click that got us here is the user gesture: unlock one audio element now and reuse it for every clip.
        if (!this.audio) { this.audio = new Audio(); }
        try { this.audio.src = SILENT; var p = this.audio.play(); if (p && p.catch) p.catch(function () {}); } catch (e) { /* no audio support: text only */ }

        return fetch(cfg.api, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) {
            if (!r.ok) throw new Error('tour ' + r.status);
            return r.json();
        }).then(function (data) {
            if (!window.driver || !window.driver.js) throw new Error('driver.js missing');
            self.data = data;
            self.steps = data.steps || [];
            if (!self.steps.length) return;
            self.active = true; self.paused = false;
            self.driver = window.driver.js.driver({
                allowClose: false, allowKeyboardControl: false, animate: true, overlayOpacity: 0.35, stagePadding: 6, stageRadius: 8, popoverClass: 'corex-tour corex-narrated',
                overlayColor: '#0f172a',
                onPopoverRender: function (popover) { self.decorate(popover); },
            });
            document.body.classList.add('corex-guide-live');
            window.dispatchEvent(new CustomEvent('corex-guide:started', { detail: { key: data.key, mode: 'narrated' } }));
            self.show(Math.max(0, Math.min(fromIndex || 0, self.steps.length - 1)), 1, !fromIndex);
        }).catch(function (e) {
            self.active = false;
            if (window.console) console.warn('[corex-guide-me]', e && e.message);
            window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Sorry, the guide could not start. Try again in a moment.', type: 'error' } }));
        });
    };

    // ---- one step ----------------------------------------------------------------------------------------------------------------------------------------
    Player.prototype.clear = function () {
        this.cleanups.forEach(function (fn) { try { fn(); } catch (e) {} });
        this.cleanups = [];
        if (this.audio) { try { this.audio.pause(); } catch (e) {} }
    };

    Player.prototype.waitUntil = function (test, ms, run, onSlow) {
        var self = this;
        return new Promise(function (resolve) {
            var t0 = Date.now(), slowed = false;
            (function tick() {
                if (run !== self.runId || !self.active) return resolve(false);
                if (!self.paused && test()) return resolve(true);
                var waited = Date.now() - t0;
                if (!slowed && onSlow && waited > SLOW_AFTER) { slowed = true; onSlow(); }
                if (waited > ms) return resolve(false);
                window.setTimeout(tick, POLL);
            })();
        });
    };

    Player.prototype.show = async function (i, dir, withIntro) {
        var self = this;
        var run = ++this.runId;
        this.clear();
        if (i < 0) i = 0;
        if (i >= this.steps.length) return this.finish(true);
        this.i = i;
        this.saveState();
        var step = this.steps[i];

        if (step.wait_for) {
            var ok = await this.waitUntil(function () { return isVisible(q(step.wait_for)); }, LOAD_WAIT, run, function () { self.note('Still loading. You can press Skip.'); });
            if (run !== this.runId) return;
            if (!ok) return this.show(i + (dir || 1), dir || 1);
        }
        var sel = step.selector || step.target;
        var found = await this.waitUntil(function () { return isVisible(q(sel)); }, LOAD_WAIT, run, null);
        if (run !== this.runId) return;
        if (!found) return this.show(i + (dir || 1), dir || 1);   // not on this screen (a box only some roles see): never dead-end

        var el = q(sel);
        this.driver.highlight({
            element: el,
            popover: { title: 'Step ' + (i + 1) + ' of ' + this.steps.length, description: this.bodyHtml(step, withIntro && i === 0), showButtons: [], popoverClass: 'corex-tour corex-narrated' },
        });
        this.speak(step.audio_url, withIntro && i === 0 ? this.data.intro && this.data.intro.audio_url : null);
        this.arm(step, run);
    };

    Player.prototype.bodyHtml = function (step, intro) {
        var h = '';
        if (intro && this.data.intro && this.data.intro.narration) h += '<div class="corex-guide-hint" style="display:block;margin-bottom:6px;">' + esc(this.data.intro.narration) + '</div>';
        h += '<div style="font-weight:600;">' + esc(step.narration) + '</div>';
        if (step.why) h += '<div style="margin-top:4px;">' + esc(step.why) + '</div>';
        if (step.value && (step.action === 'type' || step.action === 'select')) h += '<div class="corex-guide-do" data-cgm-example>For example: ' + esc(step.value) + '</div>';
        h += '<div class="corex-guide-hint" data-cgm-note style="display:none;"></div>';
        return h;
    };

    Player.prototype.note = function (msg) {
        var n = document.querySelector('.driver-popover [data-cgm-note]');
        if (!n) return;
        n.textContent = msg || '';
        n.style.display = msg ? '' : 'none';
    };

    // ---- the popover's own controls ----------------------------------------------------------------------------------------------------------------------
    Player.prototype.decorate = function (popover) {
        var self = this, step = this.steps[this.i];
        if (!step) return;
        var bar = document.createElement('div');
        bar.setAttribute('data-cgm-bar', '');
        bar.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;align-items:center;';
        function btn(label, attr, fn, primary) {
            var b = document.createElement('button');
            b.type = 'button'; b.textContent = label; b.setAttribute(attr, '');
            b.style.cssText = 'font-size:12px;padding:4px 9px;border-radius:6px;cursor:pointer;border:1px solid var(--border,#d1d5db);background:' + (primary ? 'var(--brand-icon,#0ea5e9);color:#fff;border-color:transparent;' : 'var(--surface-2,#f8fafc);color:inherit;');
            b.addEventListener('click', function (ev) { ev.preventDefault(); ev.stopPropagation(); fn(); });
            bar.appendChild(b);
            return b;
        }
        var done = parseDone(step.done);
        if (done.kind === 'none') btn(this.i === this.steps.length - 1 ? 'Finish' : 'Got it →', 'data-cgm-next', function () { self.advance(); }, true);
        this.confirmBtn = null;
        if (done.kind === 'input') {
            this.confirmBtn = btn('This is right →', 'data-cgm-confirm', function () { self.advance(); }, true);
            this.confirmBtn.style.display = hasValidValue(controlIn(q(step.target || step.selector))) ? '' : 'none';
        }
        btn(this.paused ? 'Resume' : 'Pause', 'data-cgm-pause', function () { self.togglePause(); });
        btn('Replay', 'data-cgm-replay', function () { self.speak(step.audio_url, null); });
        btn('Skip', 'data-cgm-skip', function () { self.advance(); });
        btn(this.voiceOn() ? 'Voice: on' : 'Voice: off', 'data-cgm-mute', function () { self.toggleVoice(); });
        btn('Close', 'data-cgm-close', function () { self.close(); });
        var host = popover.footer && popover.footer.parentNode ? popover.footer.parentNode : popover.wrapper;
        if (popover.footer) popover.footer.style.display = 'none';
        host.appendChild(bar);
        this.bar = bar;
        if (this.paused) this.note('Paused. Press Resume when you are ready.');
    };

    // ---- sound ------------------------------------------------------------------------------------------------------------------------------------------
    Player.prototype.speak = function (url, introUrl) {
        var self = this, a = this.audio;
        if (!a) return;
        try { a.pause(); } catch (e) {}
        if (!this.voiceOn() || this.paused) return;
        var queue = [introUrl, url].filter(Boolean);
        if (!queue.length) return;
        var play = function () {
            var next = queue.shift();
            if (!next) return;
            a.onended = play;
            a.src = next;
            var p = a.play();
            if (p && p.catch) p.catch(function () { self.note('Press Replay to hear this step.'); });
        };
        play();
    };

    Player.prototype.toggleVoice = function () {
        var on = this.voiceOn();
        setItem(VOICE_KEY, on ? 'off' : 'on', 'voice');
        if (on && this.audio) { try { this.audio.pause(); } catch (e) {} }
        var b = document.querySelector('[data-cgm-mute]');
        if (b) b.textContent = this.voiceOn() ? 'Voice: on' : 'Voice: off';
        if (!on) this.speak(this.steps[this.i] && this.steps[this.i].audio_url, null);
    };

    Player.prototype.togglePause = function () {
        this.paused = !this.paused;
        if (this.paused && this.audio) { try { this.audio.pause(); } catch (e) {} }
        var b = document.querySelector('[data-cgm-pause]');
        if (b) b.textContent = this.paused ? 'Resume' : 'Pause';
        this.note(this.paused ? 'Paused. Press Resume when you are ready.' : '');
        if (!this.paused) this.speak(this.steps[this.i] && this.steps[this.i].audio_url, null);
    };

    // ---- waiting for the agent to DO the step ---------------------------------------------------------------------------------------------------------------
    Player.prototype.arm = function (step, run) {
        var self = this;
        var done = parseDone(step.done);
        var target = q(step.target || step.selector);
        var fired = false;
        function complete() {
            if (fired || run !== self.runId || self.paused) return;
            fired = true;
            window.setTimeout(function () { if (run === self.runId) self.advance(); }, 350);
        }
        function on(el, ev, fn, capture) { el.addEventListener(ev, fn, !!capture); self.cleanups.push(function () { el.removeEventListener(ev, fn, !!capture); }); }
        function every(fn) { var t = window.setInterval(fn, POLL); self.cleanups.push(function () { window.clearInterval(t); }); }

        if (done.kind === 'click' && target) {
            on(target, 'click', function () {
                // A click that leaves the page: remember we are one step on, so the agent can carry on there (nothing to carry on after the last step).
                if (self.i + 1 < self.steps.length) self.saveState(self.i + 1); else setItem(STATE_KEY, null, 'session');
                complete();
            }, true);
        } else if (done.kind === 'input') {
            var c = controlIn(target);
            if (c) {
                var settle = function () {
                    if (self.confirmBtn) self.confirmBtn.style.display = hasValidValue(c) ? '' : 'none';
                };
                on(c, 'input', settle);
                on(c, 'change', function () { settle(); if (hasValidValue(c)) complete(); });
                on(c, 'blur', function () { settle(); if (hasValidValue(c) && c.dataset.cgmTouched === '1') complete(); });
                on(c, 'input', function () { c.dataset.cgmTouched = '1'; });
                on(c, 'keydown', function (e) { if (e.key === 'Enter' && hasValidValue(c)) complete(); });
            }
        } else if (done.kind === 'change') {
            var s = controlIn(target);
            if (s) on(s, 'change', function () { if (String(s.value || '') !== '') complete(); });
        } else if (done.kind === 'appear' && done.arg) {
            every(function () { if (isVisible(q(done.arg))) complete(); });
        } else if (done.kind === 'url' && done.arg) {
            every(function () { if (pathOf(window.location.href).indexOf(pathOf(done.arg)) === 0) complete(); });
        }

        // Off the path: the box the tour is pointing at has gone (a box closed, a page rebuilt). Pause and ask.
        var goneSince = null;
        every(function () {
            if (self.paused || fired || run !== self.runId) return;
            if (isVisible(q(step.target || step.selector))) { goneSince = null; return; }
            goneSince = goneSince || Date.now();
            if (Date.now() - goneSince > GONE_AFTER) self.offPath();
        });

        // keep the spotlight on its box if the page moves under it
        every(function () { try { self.driver && self.driver.refresh(); } catch (e) {} });
    };

    Player.prototype.advance = function () { this.show(this.i + 1, 1); };

    // ---- off the path / carry on ---------------------------------------------------------------------------------------------------------------------------
    Player.prototype.saveState = function (index) {
        if (!this.data) return;
        setItem(STATE_KEY, { screen: this.data.key, i: index == null ? this.i : index, at: Date.now() }, 'session');
    };

    Player.prototype.offPath = function () {
        var i = this.i;
        this.saveState(i);
        this.teardown();
        this.askToCarryOn(this.cfg, i);
    };

    // The card is the same one used when the agent comes back to the screen later.
    Player.prototype.askToCarryOn = function (cfg, index) {
        var self = this;
        this.closePrompt();
        var card = document.createElement('div');
        card.setAttribute('data-cgm-prompt', '');
        card.className = 'driver-popover corex-tour corex-narrated';
        card.style.cssText = 'position:fixed;right:16px;bottom:72px;z-index:100001;max-width:300px;padding:14px;border-radius:10px;box-shadow:0 10px 30px rgba(15,23,42,.35);background:var(--surface,#fff);color:var(--text,#0f172a);border:1px solid var(--border,#e5e7eb);';
        card.innerHTML = '<div style="font-weight:700;margin-bottom:4px;">Carry on where you left off?</div><div style="font-size:13px;margin-bottom:10px;">You are on step ' + (index + 1) + ' of the guide. Do you want to carry on?</div>';
        var row = document.createElement('div');
        row.style.cssText = 'display:flex;gap:8px;';
        var yes = document.createElement('button'); yes.type = 'button'; yes.textContent = 'Carry on'; yes.setAttribute('data-cgm-carry-on', '');
        yes.style.cssText = 'padding:6px 12px;border-radius:6px;border:0;background:var(--brand-icon,#0ea5e9);color:#fff;cursor:pointer;font-size:13px;';
        var no = document.createElement('button'); no.type = 'button'; no.textContent = 'Stop the guide'; no.setAttribute('data-cgm-stop', '');
        no.style.cssText = 'padding:6px 12px;border-radius:6px;border:1px solid var(--border,#d1d5db);background:var(--surface-2,#f8fafc);color:inherit;cursor:pointer;font-size:13px;';
        yes.addEventListener('click', function () { self.closePrompt(); self.start(cfg, index); });
        no.addEventListener('click', function () { self.closePrompt(); setItem(STATE_KEY, null, 'session'); });
        row.appendChild(yes); row.appendChild(no); card.appendChild(row);
        document.body.appendChild(card);
        this.prompt = card;
    };

    Player.prototype.closePrompt = function () {
        if (this.prompt && this.prompt.parentNode) this.prompt.parentNode.removeChild(this.prompt);
        this.prompt = null;
    };

    // ---- the end -----------------------------------------------------------------------------------------------------------------------------------------
    Player.prototype.teardown = function () {
        this.runId++; this.clear(); this.active = false;
        try { if (this.driver) this.driver.destroy(); } catch (e) {}
        this.driver = null;
        document.body.classList.remove('corex-guide-live');
    };

    Player.prototype.finish = function (completed) {
        var outro = this.data && this.data.outro;
        this.teardown();
        setItem(STATE_KEY, null, 'session');
        if (completed) {
            if (outro && outro.audio_url && this.voiceOn() && this.audio) { try { this.audio.src = outro.audio_url; var p = this.audio.play(); if (p && p.catch) p.catch(function () {}); } catch (e) {} }
            window.dispatchEvent(new CustomEvent('toast', { detail: { message: (outro && outro.narration) || 'Guide complete. Nice work.', type: 'success' } }));
        }
        window.dispatchEvent(new CustomEvent('corex-guide:ended', { detail: { key: this.data && this.data.key, completed: !!completed, mode: 'narrated' } }));
    };

    Player.prototype.close = function () { this.finish(false); };

    // ---- boot --------------------------------------------------------------------------------------------------------------------------------------------
    var player = new Player();
    window.CoreXGuideMe = {
        player: player,
        start: function (cfg) { return player.start(cfg, 0); },
        /** On a page load: if the agent was part-way through THIS screen's guide, offer to carry on. Never plays sound without a click. */
        offerResume: function (cfg) {
            var st = getJson(STATE_KEY, 'session');
            if (!st || st.screen !== cfg.screen || (Date.now() - (st.at || 0)) > STATE_TTL) { if (st && (Date.now() - (st.at || 0)) > STATE_TTL) setItem(STATE_KEY, null, 'session'); return false; }
            player.cfg = cfg;
            player.askToCarryOn(cfg, st.i || 0);
            return true;
        },
        _parseDone: parseDone,
    };
})();
