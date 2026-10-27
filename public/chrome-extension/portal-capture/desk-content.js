/**
 * CoreX Desk (extension) - the in-page half. Injected by desk-ext.js into a tab the agent chose to share (never automatically).
 * Spec .ai/specs/corex-desk.md section 13 (protocol 7.6, version 1.1).
 *
 *   - records ONLY this tab with rrweb and hands the events to the service worker (which whispers them to the console);
 *   - masks passwords, [data-desk-mask] and any input that looks like a card / ID / bank / secret field, in THIS browser, before the
 *     events leave the page (desk-lib.js isSensitiveField);
 *   - shows the red "CoreX Desk" bar, support's labelled pointer and highlights. All of it lives in a closed shadow root marked
 *     .rr-block, so it is never part of what support sees and the site's own CSS cannot touch it.
 * Idempotent: injecting twice does nothing the second time.
 */
/* global chrome, CoreXDeskLib, rrwebRecord */
(function () {
  'use strict';
  if (window.__corexDeskExt) return;

  var Lib = window.CoreXDeskLib;
  var rr = window.rrwebRecord && window.rrwebRecord.record;
  var S = window.__corexDeskExt = { ctl: { state: 'none', exec: null, reporter: null, idle: null }, support: 'CoreX support', key: null, stop: null, buf: [], timer: null, host: null, root: null, ui: {}, hl: null, ptrTimer: null, raf: 0 };
  if (!Lib || !rr) { delete window.__corexDeskExt; return; }

  var ALL_TYPES = { color: true, date: true, 'datetime-local': true, email: true, month: true, number: true, range: true, search: true, tel: true, text: true, time: true, url: true, week: true, textarea: true, password: true };   // (not select: rrweb then drops option.selected and the mirror shows the wrong choice)

  // ── Messages from the service worker ────────────────────────────────────────────

  chrome.runtime.onMessage.addListener(function (msg, sender, respond) {
    if (!msg || typeof msg.type !== 'string') return false;
    switch (msg.type) {
      case 'desk.ping': respond({ ok: true, key: S.key }); return false;
      case 'desk.start': start(msg.key, msg.support); return false;
      case 'desk.resync': resync(); return false;
      case 'desk.pointer': movePointer(msg.x, msg.y, msg.who, msg.vw, msg.vh); return false;
      case 'desk.pointer_hide': hidePointer(); return false;
      case 'desk.highlight': showHighlight(msg.selector, msg.note); return false;
      case 'desk.highlight_clear': clearHighlight(); return false;
      case 'desk.message': addLine(msg.m, true); return false;
      case 'desk.thread': (msg.lines || []).forEach(function (l) { addLine(l, false); }); return false;
      case 'desk.control_ask': controlAsk(msg.support); return false;
      case 'desk.control_on': if (msg.resumed) S.ctl.state = 'asking'; controlOn(msg.idle); return false;
      case 'desk.control_off': controlOff(msg.why); return false;
      case 'desk.action': runAction(msg.a); return false;
      case 'desk.stop': stop(); return false;
      default: return false;
    }
  });

  // ── Recording ───────────────────────────────────────────────────────────────────

  function start(key, support) {
    if (S.stop) return;
    S.key = key;
    buildUi(support);
    S.stop = rr({
      emit: function (event) { S.buf.push(event); if (!S.timer) S.timer = setTimeout(flush, 60); },   // short: a keystroke reaches the console in well under a second
      blockClass: 'rr-block',
      maskInputOptions: ALL_TYPES,
      maskInputFn: Lib.maskInputFn,
      maskTextSelector: '[data-desk-mask]',
      inlineStylesheet: true,
      recordCanvas: false,
      collectFonts: false,
      sampling: { mousemove: 50, scroll: 50 },
      checkoutEveryNms: 45000,   // a fresh full snapshot now and then: script-set properties raise no mutation, so a mirror can drift
    });
    setTimeout(function () { if (S.stop) resync(); }, 2500);   // once the page has finished drawing itself
    sendViewport();
    window.addEventListener('resize', onViewport);
    if (window.visualViewport) window.visualViewport.addEventListener('resize', onViewport);
  }

  /** This tab's own window size, whispered on start / resync / every resize or zoom: the console lays the mirror out at it (spec section 4e). */
  function sendViewport() {
    send({ type: 'desk.viewport', w: window.innerWidth, h: window.innerHeight, dpr: window.devicePixelRatio || 1 });
  }
  function onViewport() { clearTimeout(S.vpTimer); S.vpTimer = setTimeout(sendViewport, 80); }

  function flush() {
    S.timer = null;
    if (!S.buf.length) return;
    var events = S.buf; S.buf = [];
    try { chrome.runtime.sendMessage({ type: 'desk.frames', events: events }, function () { void chrome.runtime.lastError; }); } catch (e) { /* extension reloaded */ }
  }

  function resync() {
    sendViewport();
    try { rr.takeFullSnapshot(true); } catch (e) { /* not recording */ }
  }

  function stop() {
    window.removeEventListener('resize', onViewport);
    if (window.visualViewport) window.visualViewport.removeEventListener('resize', onViewport);
    if (S.stop) { try { S.stop(); } catch (e) { /* ignore */ } }
    if (S.ctl.reporter) S.ctl.reporter.flush();
    if (S.ctl.idle) S.ctl.idle.stop();
    clearTimeout(S.timer); cancelAnimationFrame(S.raf); clearTimeout(S.ptrTimer);
    if (S.root && S.root.parentNode) S.root.parentNode.removeChild(S.root);
    window.removeEventListener('scroll', place, true);
    window.removeEventListener('resize', place, true);
    delete window.__corexDeskExt;
  }

  // ── UI (closed shadow root, blocked from the recording) ─────────────────────────

  function buildUi(support) {
    var host = document.createElement('div');
    host.id = 'corex-desk-ext-root';
    host.className = 'rr-block';
    host.style.cssText = 'all:initial;position:fixed;top:0;left:0;width:0;height:0;z-index:2147483647;';
    var sh = host.attachShadow({ mode: 'closed' });
    var name = String(support || 'CoreX support').replace(/[<>&"]/g, '');
    sh.innerHTML =
      '<style>' +
      '.bar{position:fixed;top:0;left:0;right:0;height:26px;display:flex;align-items:center;justify-content:center;gap:12px;background:#b91c1c;color:#fff;font:600 12px/26px system-ui,sans-serif;box-shadow:0 1px 4px rgba(0,0,0,.3)}' +
      '.bar[data-mode="ask"]{background:#9a3412}.bar[data-mode="control"]{background:#7f1d1d;box-shadow:inset 0 -3px 0 #fbbf24}' +
      '.bar button{all:unset;cursor:pointer;padding:0 10px;border:1px solid rgba(255,255,255,.6);border-radius:4px;background:rgba(255,255,255,.18);font:700 11px/20px system-ui,sans-serif}' +
      '.bar button:hover{background:rgba(255,255,255,.3)}' +
      '.ptr{position:fixed;pointer-events:none;transform:translate(-2px,-2px)}' +
      '.ptr span{display:inline-block;margin-left:14px;padding:0 6px;border-radius:4px;background:#b91c1c;color:#fff;font:700 10px/16px system-ui,sans-serif}' +
      '.ring{position:fixed;pointer-events:none;border-radius:8px;outline:3px solid #0ea5e9;outline-offset:3px;box-shadow:0 0 0 9999px rgba(3,6,12,.35)}' +
      '.note{position:fixed;pointer-events:none;max-width:280px;padding:10px 12px;border-radius:12px;background:#fff;color:#111827;border:1px solid rgba(0,0,0,.1);box-shadow:0 18px 50px rgba(0,0,0,.35);font:13px/1.45 system-ui,sans-serif}' +
      '.note b{display:block;margin-bottom:2px;font-size:14px}' +
      '.th{position:fixed;top:26px;left:50%;transform:translateX(-50%);width:min(46rem,96vw);box-sizing:border-box;padding:6px 12px 8px;background:rgba(127,29,29,.97);color:#fff;border-radius:0 0 8px 8px;box-shadow:0 6px 18px rgba(0,0,0,.35);font:500 12px/1.4 system-ui,sans-serif}' +
      '.th .l{overflow-wrap:anywhere}.th .l b{color:#fde68a}.th .l.me b{color:#bbf7d0}' +
      '.th .rp{display:flex;gap:6px;align-items:center;margin-top:5px}.th input{all:unset;box-sizing:border-box;flex:1 1 auto;min-width:0;height:22px;padding:0 8px;border-radius:4px;border:1px solid rgba(255,255,255,.55);background:rgba(255,255,255,.95);color:#111827;font:500 12px/22px system-ui,sans-serif}' +
      '.th button{all:unset;cursor:pointer;padding:0 10px;border:1px solid rgba(255,255,255,.6);border-radius:4px;background:rgba(255,255,255,.18);font:700 11px/20px system-ui,sans-serif}.th button.more{border:0;background:none;text-decoration:underline;padding:0}' +
      '[hidden]{display:none!important}' +
      '</style>' +
      '<div class="bar" id="bar"><span>CoreX Desk — ' + name + ' <span id="what">is viewing this window</span></span>' +
      '<button type="button" id="allow" hidden>Allow</button><button type="button" id="decline" hidden>Decline</button><button type="button" id="takeback" hidden>Take back control</button>' +
      '<button type="button" id="stop">Stop sharing</button></div>' +
      '<div class="th" id="th" hidden><div id="thl"></div><div class="rp"><input id="rin" type="text" maxlength="300" placeholder="Reply to CoreX support - Enter sends" autocomplete="off"><button type="button" id="rbtn">Reply</button><button type="button" class="more" id="rmore" hidden></button></div></div>' +
      '<div class="ptr" hidden><svg width="18" height="18" viewBox="0 0 24 24" fill="#b91c1c" stroke="#fff" stroke-width="1.5" style="position:absolute;left:0;top:0"><path d="M4 3l16 7-7 2-2 7z"/></svg><span>Support</span></div>' +
      '<div class="ring" hidden></div>' +
      '<div class="note" hidden><b id="who">CoreX support</b><span></span></div>';
    S.support = name;
    sh.getElementById('allow').addEventListener('click', function () { send({ type: 'desk.controlRespond', allow: true }); });
    sh.getElementById('decline').addEventListener('click', function () { send({ type: 'desk.controlRespond', allow: false }); controlOff('declined'); });
    sh.getElementById('takeback').addEventListener('click', function () { controlOff('agent'); send({ type: 'desk.controlRevoke', reason: 'agent' }); });
    sh.getElementById('stop').addEventListener('click', function () {
      try { chrome.runtime.sendMessage({ type: 'desk.stopMe' }, function () { void chrome.runtime.lastError; }); } catch (e) { /* ignore */ }
    });
    var rin = sh.getElementById('rin');
    var doReply = function () { var t = rin.value.replace(/\s+/g, ' ').trim(); if (!t) return; rin.value = ''; send({ type: 'desk.reply', text: t }); };
    sh.getElementById('rbtn').addEventListener('click', doReply);
    // (the page's own keyboard shortcuts must not see what is typed here)
    ['keydown', 'keyup', 'keypress'].forEach(function (n) { rin.addEventListener(n, function (e) { e.stopPropagation(); if (n === 'keydown' && e.key === 'Enter') { e.preventDefault(); doReply(); } }); });
    sh.getElementById('rmore').addEventListener('click', function () { S.threadAll = !S.threadAll; paintThread(); });
    S.root = host;
    S.sh = sh;
    S.ui = { bar: sh.querySelector('.bar'), ptr: sh.querySelector('.ptr'), ring: sh.querySelector('.ring'), note: sh.querySelector('.note'), noteText: sh.querySelector('.note span') };
    document.documentElement.appendChild(host);
    window.addEventListener('scroll', place, true);
    window.addEventListener('resize', place, true);
  }

  // ── The message strip (spec section 4h): the last three lines on the red bar, a reply box, and the line on the highlight card ──────

  function paintThread() {
    var box = S.sh && S.sh.getElementById('th');
    if (!box) return;
    box.hidden = !S.thread || !S.thread.length;
    var shown = S.threadAll ? S.thread : S.thread.slice(-3);
    var host = S.sh.getElementById('thl');
    host.textContent = '';
    shown.forEach(function (l) {
      var d = document.createElement('div'); d.className = 'l' + (l.from === 'agent' ? ' me' : '');
      var b = document.createElement('b'); b.textContent = (l.from === 'agent' ? 'You' : (l.name || S.support || 'CoreX support')) + ': ';
      d.appendChild(b); d.appendChild(document.createTextNode(l.text)); host.appendChild(d);
    });
    var more = S.sh.getElementById('rmore');
    more.hidden = S.thread.length <= 3;
    more.textContent = S.threadAll ? 'Show fewer' : 'Show all ' + S.thread.length;
  }

  function addLine(l, live) {
    if (!l || !S.sh) return;
    S.thread = S.thread || [];
    if (S.thread.some(function (x) { return x.id === l.id; })) return;
    S.thread.push(l);
    if (S.thread.length > 30) S.thread.shift();
    paintThread();
    // A highlight showing here carries support's line too, on its card.
    if (live && l.from === 'support' && S.hl && (l.tab === S.key) && S.ui.note) { S.hl.note = l.text; S.ui.noteText.textContent = l.text; var wb = S.sh.getElementById('who'); if (wb) wb.textContent = S.support || 'CoreX support'; S.ui.note.hidden = false; place(); }
  }

  // ── Take-over (phase 2, spec section 14): the agent decides here, on this page ─────────────────

  function send(msg) {
    try { chrome.runtime.sendMessage(msg, function () { void chrome.runtime.lastError; }); } catch (e) { /* extension reloaded */ }
  }

  function setBar(mode) {
    if (!S.ui.bar) return;
    S.ui.bar.setAttribute('data-mode', mode);
    S.sh.getElementById('what').textContent = { view: 'is viewing this window', ask: 'wants to take control of this window', control: 'is in control of this window' }[mode];
    S.sh.getElementById('allow').hidden = mode !== 'ask';
    S.sh.getElementById('decline').hidden = mode !== 'ask';
    S.sh.getElementById('takeback').hidden = mode !== 'control';
    S.sh.getElementById('stop').hidden = mode === 'ask';
  }

  /** Support asked. Nothing moves until the agent presses Allow in the bar. */
  function controlAsk() {
    if (!S.ui.bar || S.ctl.state === 'granted') return;
    S.ctl.state = 'asking';
    setBar('ask');
  }

  /** The server confirmed the agent's Allow (or this page was reloaded mid-control): start replaying support's actions. */
  function controlOn(idleSeconds) {
    if (!S.ui.bar || !window.CoreXDeskExec) return;
    if (S.ctl.state !== 'asking' && S.ctl.state !== 'granted') return;   // only an Allow given here starts control
    var E = window.CoreXDeskExec;
    S.ctl.state = 'granted';
    S.ctl.exec = E.create({ doc: document, win: window, isBlocked: Lib.isSensitiveField });
    S.ctl.reporter = E.createReporter(function (actions) { send({ type: 'desk.controlActions', actions: actions }); }, 1500);
    if (S.ctl.idle) S.ctl.idle.stop();
    S.ctl.idle = E.createIdleTimer(Math.max(60, idleSeconds || 300), function () { controlOff('idle'); send({ type: 'desk.controlRevoke', reason: 'idle' }); });
    S.ctl.idle.start();
    setBar('control');
  }

  function controlOff() {
    if (S.ctl.reporter) S.ctl.reporter.flush();
    if (S.ctl.idle) S.ctl.idle.stop();
    S.ctl = { state: 'none', exec: null, reporter: null, idle: null };
    if (S.ui.bar) setBar('view');
  }

  /** One action whispered by support, forwarded by the service worker. Replayed only while control is granted on THIS tab. */
  function runAction(a) {
    if (S.ctl.state !== 'granted' || !S.ctl.exec) return;
    var res = S.ctl.exec.run(a);
    S.ctl.reporter.add(res.report);
    if (res.ok) S.ctl.idle.touch();
    else send({ type: 'desk.actionResult', id: a.id, reason: res.reason });   // the console is told it could not be done
  }

  // ── Pointer ─────────────────────────────────────────────────────────────────────

  function movePointer(x, y, who, vw, vh) {
    if (!S.ui.ptr || typeof x !== 'number' || typeof y !== 'number') return;
    if ((typeof vw === 'number' && Math.abs(vw - window.innerWidth) > 2) || (typeof vh === 'number' && Math.abs(vh - window.innerHeight) > 2)) sendViewport();   // the console's idea of our window is out of date
    S.ui.ptr.hidden = false;
    var label = S.ui.ptr.querySelector('span');
    if (label) label.textContent = String(who || S.support || 'Support').slice(0, 90);
    S.ui.ptr.style.left = Math.round(Math.min(1, Math.max(0, x)) * window.innerWidth) + 'px';
    S.ui.ptr.style.top = Math.round(Math.min(1, Math.max(0, y)) * window.innerHeight) + 'px';
    clearTimeout(S.ptrTimer);
    S.ptrTimer = setTimeout(hidePointer, 4000);
  }

  function hidePointer() { if (S.ui.ptr) S.ui.ptr.hidden = true; }

  // ── Highlight (the Guided Tour's "Spot Help" look: blue ring, dimmed page that stays clickable, a note bubble) ──

  function showHighlight(selector, note) {
    clearHighlight();
    var el = null;
    try { el = document.querySelector(selector); } catch (e) { return; }   // an invalid selector is ignored, never evaluated
    if (!el) return;
    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    if (!note && S.thread) { var rc = S.thread.filter(function (l) { return l.from === 'support' && l.tab === S.key && Date.now() - l.at < 60000; }).pop(); if (rc) note = rc.text; }   // the line support just wrote rides on the card
    S.hl = { el: el, note: note || '' };
    S.ui.ring.hidden = false;
    if (note) { S.ui.noteText.textContent = note; var wb = S.sh.getElementById('who'); if (wb) wb.textContent = S.support || 'CoreX support'; S.ui.note.hidden = false; }
    place();
    setTimeout(place, 450);   // after the smooth scroll
  }

  function place() {
    if (!S.hl) return;
    cancelAnimationFrame(S.raf);
    S.raf = requestAnimationFrame(function () {
      if (!S.hl) return;
      var r = S.hl.el.getBoundingClientRect();
      var ring = S.ui.ring;
      ring.style.left = (r.left - 6) + 'px'; ring.style.top = (r.top - 6) + 'px';
      ring.style.width = (r.width + 12) + 'px'; ring.style.height = (r.height + 12) + 'px';
      if (S.hl.note) {
        var note = S.ui.note;
        var below = r.bottom + 14;
        note.style.left = Math.max(8, Math.min(window.innerWidth - 300, r.left)) + 'px';
        note.style.top = (below + 90 > window.innerHeight ? Math.max(34, r.top - 14 - note.offsetHeight) : below) + 'px';
      }
    });
  }

  function clearHighlight() {
    S.hl = null;
    if (S.ui.ring) S.ui.ring.hidden = true;
    if (S.ui.note) S.ui.note.hidden = true;
  }
})();
