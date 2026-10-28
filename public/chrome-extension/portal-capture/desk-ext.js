/**
 * CoreX Desk (extension) - the service-worker half: shares the agent's chosen Chrome tabs into their open Desk session.
 * Spec .ai/specs/corex-desk.md section 13; protocol section 7.6 (version 1.1).
 *
 * The agent opens the popup on a tab and presses "Share this tab". From then on:
 *   - this worker holds ONE websocket to Reverb (the Pusher protocol, private channel desk.session.{id}); the channel handshake is
 *     authorised by CoreX with the extension's Sanctum token, exactly like every other call the extension makes;
 *   - desk-content.js (rrweb) is injected into the tab and sends its DOM events here; they are chunked and whispered as client-frame
 *     with t = "x<tabId>"; client-resync / client-pointer / desk.highlight addressed to that tab are passed to the page;
 *   - the server is told when a tab joins, changes site, or leaves (audited), and the session is kept alive with a heartbeat.
 * Nothing is recorded or sent for a tab until the agent shares it, and only while support is in the session.
 */
/* global chrome, CoreXDeskLib */
importScripts('desk-lib.js');

(function () {
  'use strict';
  var Lib = self.CoreXDeskLib;

  var D = {
    status: null,        // the last GET /api/v1/desk/ext/session answer
    tabs: new Map(),     // chrome tabId -> {key, host, title, url}
    ws: null, socketId: null, subscribed: false, channel: null,
    pending: [],         // chunks waiting for the channel
    control: { state: 'none', tab: null }, idleSeconds: 300,
    grant: null,         // the take-over's signing key for the tab control is granted on: {tab, key, requestId, lastSeq, q}. Memory only (plus the last seq, see persistSeq); gone when control ends.
    grantLoading: null, dropped: 0,   // actions refused (unsigned / bad signature / out of order / wrong tab / no key) - dropped silently, counted
    hb: null, retry: 0, reconnect: null, titleTimers: new Map(), stopped: true,
    thread: [], seen: new Set(),   // the message strip: the last lines (only what the SERVER broadcast), each handled once by its id
  };

  // ── CoreX API (Bearer token) ────────────────────────────────────────────────────

  async function settings() {
    var s = await new Promise(function (r) { chrome.storage.local.get(['apiUrl', 'apiToken'], r); });
    return { base: (s.apiUrl || 'https://www.corexos.co.za').replace(/\/+$/, ''), token: s.apiToken || '' };
  }

  async function api(method, path, body) {
    var s = await settings();
    if (!s.token) return { ok: false, status: 0, json: { message: 'Not connected - add your API token in the CoreX extension Settings.' } };
    try {
      var res = await fetch(s.base + path, {
        method: method,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: 'Bearer ' + s.token },
        body: body ? JSON.stringify(body) : undefined,
      });
      var json = await res.json().catch(function () { return {}; });
      return { ok: res.ok, status: res.status, json: json, base: s.base };
    } catch (e) {
      return { ok: false, status: 0, json: { message: 'CoreX could not be reached.' } };
    }
  }

  async function fetchStatus() {
    var r = await api('GET', '/api/v1/desk/ext/session');
    if (!r.ok) return { error: r.json.message || 'CoreX said no (' + r.status + ').', status: r.status };
    D.status = r.json;
    if (r.json.session && r.json.session.control) D.control = { state: r.json.session.control.state === 'granted' ? 'granted' : (r.json.session.control.state === 'requested' ? 'requested' : 'none'), tab: r.json.session.control.tab, requestId: r.json.session.control.request_id || null };
    if (r.json.control_idle_seconds) D.idleSeconds = r.json.control_idle_seconds;
    if (D.grant && !(D.control.state === 'granted' && D.control.tab === D.grant.tab)) dropGrant();   // the take-over the key belonged to is over
    return r.json;
  }

  /** May this URL be shared? https only, and an allowed site (the platform sites and their subdomains; the agency's own website exactly). The server decides again. */
  function urlOk(url) {
    var st = D.status || {};
    return Lib.urlAllowed(url, st.allowed_hosts, st.allowed_hosts_exact);
  }

  // ── The websocket (Pusher protocol, no library) ─────────────────────────────────

  function connect() {
    if (D.ws && (D.ws.readyState === 0 || D.ws.readyState === 1)) return;
    var st = D.status;
    if (!st || !st.reverb || !st.session) return;
    D.stopped = false;
    D.subscribed = false;
    D.channel = Lib.channelName(st.session.id);
    var ws = new WebSocket(Lib.socketUrl(st.reverb));
    D.ws = ws;

    ws.onmessage = function (ev) {
      var m;
      try { m = Lib.parseFrame(ev.data); } catch (e) { return; }
      handle(m);
    };
    ws.onclose = function () {
      D.subscribed = false;
      if (D.ws === ws) D.ws = null;
      if (!D.stopped && D.tabs.size) scheduleReconnect();
    };
    ws.onerror = function () { /* onclose follows */ };
  }

  function scheduleReconnect() {
    clearTimeout(D.reconnect);
    var wait = Math.min(15000, 1000 * Math.pow(2, D.retry++));
    D.reconnect = setTimeout(async function () {
      var st = await fetchStatus();
      if (!st || st.error || !st.session || st.session.status !== 'live') { stopAll('session closed'); return; }
      connect();
    }, wait);
  }

  function send(obj) {
    if (D.ws && D.ws.readyState === 1) D.ws.send(JSON.stringify(obj));
  }

  async function handle(m) {
    var ev = m.event;
    if (ev === 'pusher:connection_established') {
      D.socketId = m.data.socket_id;
      var r = await api('POST', '/api/v1/desk/ext/broadcasting-auth', { socket_id: D.socketId, channel_name: D.channel });
      if (!r.ok || !r.json.auth) { stopAll('channel refused'); return; }
      send({ event: 'pusher:subscribe', data: { channel: D.channel, auth: r.json.auth } });
    } else if (ev === 'pusher_internal:subscription_succeeded') {
      D.subscribed = true;
      D.retry = 0;
      // A fresh snapshot of every shared tab: the console may have joined, or we just reconnected.
      D.tabs.forEach(function (info, tabId) { toTab(tabId, { type: 'desk.resync' }); });
      var queued = D.pending; D.pending = [];
      queued.forEach(send);
    } else if (ev === 'pusher:ping') {
      send({ event: 'pusher:pong', data: {} });
    } else if (ev === 'pusher:error') {
      console.warn('[corex-desk] pusher error', m.data);
    } else if (ev === 'client-resync') {
      var t1 = tabIdFor(m.data && m.data.t); if (t1 !== null) toTab(t1, { type: 'desk.resync' });
    } else if (ev === 'client-pointer') {
      var t2 = tabIdFor(m.data && m.data.t); if (t2 !== null) toTab(t2, { type: 'desk.pointer', x: m.data.x, y: m.data.y, who: m.data.who, vw: m.data.vw, vh: m.data.vh });
    } else if (ev === 'desk.highlight') {
      var t3 = tabIdFor(m.data && m.data.tab); if (t3 !== null) toTab(t3, { type: 'desk.highlight', selector: m.data.selector, note: m.data.note });
    } else if (ev === 'desk.highlight_cleared') {
      var t4 = tabIdFor(m.data && m.data.tab); if (t4 !== null) toTab(t4, { type: 'desk.highlight_clear' });
    } else if (ev === 'desk.pointer_state') {
      var t5 = tabIdFor(m.data && m.data.tab); if (t5 !== null && m.data && !m.data.on) toTab(t5, { type: 'desk.pointer_hide' });
    } else if (ev === 'desk.message') {
      // The message strip: only the SERVER's broadcast is a message (a whispered line could carry any name). Every shared tab shows it.
      onMessage(m.data);
    } else if (ev === 'desk.supporter_joined') {
      // Another support person joined the live session: every shared tab's bar names everyone who is in it and flashes.
      if (m.data && Array.isArray(m.data.supporters)) {
        if (D.status && D.status.session) D.status.session.supporters = m.data.supporters;
        D.tabs.forEach(function (info, tabId) { toTab(tabId, { type: 'desk.supporters', names: m.data.supporters, who: m.data.support_name }); });
      }
    } else if (ev === 'client-action') {
      // Take-over: a SIGNED action for one of our tabs. It is verified here (the key never enters the page), in order, before the page is told to replay it.
      var ta = tabIdFor(m.data && m.data.t); if (ta !== null) onAction(ta, m.data); else D.dropped++;
    } else if (ev === 'desk.control_requested') {
      var c1 = tabIdFor(m.data && m.data.tab); D.control = { state: c1 !== null ? 'requested' : 'none', tab: m.data && m.data.tab, requestId: (m.data && m.data.request_id) || null };
      if (c1 !== null) {
        toTab(c1, { type: 'desk.control_ask', support: m.data.support_name, requestId: m.data.request_id });
        send({ event: 'client-control_ack', channel: D.channel, data: { t: m.data.tab } });   // tells the console the question is on the agent's screen
      }
    } else if (ev === 'desk.control_granted') {
      var c2 = tabIdFor(m.data && m.data.tab);
      if (c2 !== null) {
        D.control = { state: 'granted', tab: m.data.tab }; D.idleSeconds = m.data.idle_seconds || D.idleSeconds;
        // (the page starts only if the agent pressed Allow on THAT page; the key is the one in the Allow answer, or fetched from CoreX)
        ensureGrant(m.data.tab).then(function (ok) { if (ok) toTab(c2, { type: 'desk.control_on', idle: D.idleSeconds }); });
      }
    } else if (ev === 'desk.control_declined' || ev === 'desk.control_revoked' || ev === 'desk.control_ended') {
      var c3 = tabIdFor(m.data && m.data.tab); if (c3 !== null) { D.control = { state: 'none', tab: null }; dropGrant(); toTab(c3, { type: 'desk.control_off', why: ev.replace('desk.control_', '') }); }
    } else if (ev === 'desk.ended') {
      stopAll('session ended');
    }
  }

  // ── The take-over's signing key (spec section 14b) ──────────────────────────────

  function dropGrant() { D.grant = null; D.grantLoading = null; try { if (chrome.storage.session) chrome.storage.session.remove('deskSeq'); } catch (e) { /* ignore */ } }

  function persistSeq() {
    try { if (chrome.storage.session && D.grant) chrome.storage.session.set({ deskSeq: { req: D.grant.requestId, tab: D.grant.tab, seq: D.grant.lastSeq } }); } catch (e) { /* ignore */ }
  }

  /** Remember the grant from the Allow answer (or the key call). The last accepted seq survives a worker restart, so a recorded action cannot be replayed after one. */
  async function setGrant(tabKey, g) {
    var seq = 0;
    try {
      var got = chrome.storage.session ? await new Promise(function (r) { chrome.storage.session.get('deskSeq', r); }) : null;
      if (got && got.deskSeq && got.deskSeq.req === g.request_id && got.deskSeq.tab === tabKey) seq = got.deskSeq.seq || 0;
    } catch (e) { /* ignore */ }
    D.grant = { tab: tabKey, key: g.key, requestId: g.request_id, lastSeq: seq, q: Promise.resolve() };
  }

  /** Is there a key for this tab's take-over? If not (a worker restart, or the Allow answer not back yet), ask CoreX for it - the agent's own token only gets it while control is granted on that tab. */
  async function ensureGrant(tabKey) {
    if (D.grant && D.grant.tab === tabKey) return true;
    if (D.grantLoading) return D.grantLoading;
    var st = D.status;
    if (!st || !st.session) return false;
    D.grantLoading = (async function () {
      var r = await api('POST', '/api/v1/desk/ext/sessions/' + st.session.id + '/control/key', { tab: tabKey });
      if (r.ok && r.json.grant && r.json.grant.key && D.control.state === 'granted' && D.control.tab === tabKey) { await setGrant(tabKey, r.json.grant); return true; }
      return false;
    })();
    var ok = false;
    try { ok = await D.grantLoading; } catch (e) { ok = false; }
    D.grantLoading = null;
    return ok;
  }

  /** A whispered action: verified here, in the order sent, and only then handed to the page. Anything else is dropped (and counted). */
  async function onAction(tabId, msg) {
    var key = Lib.tabKey(tabId);
    if (D.control.state !== 'granted' || D.control.tab !== key) { D.dropped++; return; }
    if (!(D.grant && D.grant.tab === key) && !(await ensureGrant(key))) { D.dropped++; return; }
    var g = D.grant;
    if (!g || g.tab !== key) { D.dropped++; return; }
    g.q = g.q.then(function () {
      return Lib.verifyAction(g.key, msg, g.lastSeq).then(function (v) {
        if (D.grant !== g) return;
        if (!v.ok || !v.action || v.action.t !== key) { D.dropped++; return; }
        g.lastSeq = v.seq;
        persistSeq();
        toTab(tabId, { type: 'desk.action', a: v.action });
      });
    }).catch(function () { D.dropped++; });
  }

  /** A line of the message strip: keep it (last 30), and show it on every shared tab's red bar. */
  function onMessage(msg) {
    if (!msg || typeof msg.text !== 'string' || !msg.text.trim() || !msg.id || D.seen.has(msg.id)) return null;
    var line = { id: String(msg.id), from: msg.from === 'agent' ? 'agent' : 'support', name: String(msg.name || '').slice(0, 120), text: msg.text.slice(0, 300), tab: msg.tab || 'main', at: msg.at || Date.now() };
    D.seen.add(line.id);
    D.thread.push(line);
    if (D.thread.length > 30) D.seen.delete(D.thread.shift().id);
    D.tabs.forEach(function (info, tabId) { toTab(tabId, { type: 'desk.message', m: line }); });
    return line;
  }

  /** The agent's reply from a shared tab's red bar: recorded (audited) by CoreX, which broadcasts it - the line shows from that broadcast, like everyone else's. No whisper. */
  function reply(tabId, text) {
    var info = D.tabs.get(tabId), st = D.status;
    text = String(text || '').replace(/\s+/g, ' ').trim().slice(0, 300);
    if (!info || !st || !st.session || !text) return;
    api('POST', '/api/v1/desk/ext/sessions/' + st.session.id + '/message', { text: text, tab: info.key, id: Date.now().toString(36) + Math.random().toString(36).slice(2, 6) });
  }

  /** A whisper/event names a tab by key ("x123"); only tabs this browser is sharing answer. Absent / "main" = the CoreX page, not ours. */
  function tabIdFor(key) {
    if (!key || key === 'main') return null;
    var found = null;
    D.tabs.forEach(function (info, tabId) { if (info.key === key) found = tabId; });
    return found;
  }

  function toTab(tabId, msg) {
    try { chrome.tabs.sendMessage(tabId, msg, function () { void chrome.runtime.lastError; }); } catch (e) { /* tab gone */ }
  }

  // ── Frames from a shared page ───────────────────────────────────────────────────

  async function onFrames(tabId, events) {
    var info = D.tabs.get(tabId);
    if (!info || !events || !events.length) return;
    var chunks = await Lib.encodeBatch(events, 'e' + tabId, info.key);
    chunks.forEach(function (c) {
      var frame = { event: 'client-frame', channel: D.channel, data: c };
      if (D.subscribed) send(frame);
      else if (D.pending.length < 400) D.pending.push(frame);   // ~2.8 MB cap while the channel opens
    });
  }

  // ── Sharing and un-sharing a tab ────────────────────────────────────────────────

  async function tabEvent(action, tabId, info) {
    var st = D.status;
    if (!st || !st.session) return { ok: false, json: { message: 'No Desk session.' } };
    return api('POST', '/api/v1/desk/ext/sessions/' + st.session.id + '/tabs', { action: action, tab: info.key, url: info.url, title: info.title });
  }

  async function inject(tabId, supportName) {
    await chrome.scripting.executeScript({ target: { tabId: tabId }, files: ['desk-lib.js', 'vendor/rrweb-record.min.js', 'desk-exec.js', 'desk-content.js'] });
    var sup = D.status && D.status.session && D.status.session.supporters;
    toTab(tabId, { type: 'desk.start', key: Lib.tabKey(tabId), support: supportName || 'CoreX support', supporters: Array.isArray(sup) ? sup : null });
    if (D.thread.length) toTab(tabId, { type: 'desk.thread', lines: D.thread });
    // A full page load discards the page's consent state: hand it back (a request still open, or a take-over in progress).
    var key = Lib.tabKey(tabId);
    if (D.control.tab === key && D.control.state === 'requested') toTab(tabId, { type: 'desk.control_ask', support: supportName, requestId: D.control.requestId });
    if (D.control.tab === key && D.control.state === 'granted') toTab(tabId, { type: 'desk.control_on', idle: D.idleSeconds, resumed: true });
  }

  async function shareTab(tabId, requestedOrigin) {
    var tab;
    try { tab = await chrome.tabs.get(tabId); } catch (e) { return { ok: false, error: 'That tab is gone.' }; }
    var st = await fetchStatus();
    if (st.error) return { ok: false, error: st.error };
    if (!st.enabled) return { ok: false, error: 'CoreX Desk is not available for your agency.' };
    if (!st.session) return { ok: false, error: 'No Desk session is open. Press Get help in CoreX first.' };
    if (st.session.status !== 'live') return { ok: false, error: 'CoreX support has not joined yet.' };
    var host = Lib.hostOf(tab.url);
    if (!/^https:/i.test(String(tab.url || ''))) return { ok: false, error: 'Only secure (https) pages can be shared with CoreX support.' };
    if (!urlOk(tab.url)) return { ok: false, error: 'This site cannot be shared with CoreX support.' };

    var info = { key: Lib.tabKey(tabId), host: host, title: tab.title || '', url: tab.url, requestedOrigin: requestedOrigin || null };
    var r = await tabEvent('join', tabId, info);
    if (!r.ok) return { ok: false, error: (r.json && r.json.message) || 'CoreX refused to share this tab.' };
    D.tabs.set(tabId, info);
    try {
      await inject(tabId, st.session.support_name);
    } catch (e) {
      D.tabs.delete(tabId);
      await tabEvent('leave', tabId, info);
      return { ok: false, error: 'Chrome would not let CoreX read this tab. Allow the site when asked, then try again.' };
    }
    connect();
    startHeartbeat();
    persist();
    return { ok: true };
  }

  async function stopTab(tabId, quiet) {
    var info = D.tabs.get(tabId);
    if (!info) return { ok: true };
    D.tabs.delete(tabId);
    clearTimeout(D.titleTimers.get(tabId));
    toTab(tabId, { type: 'desk.stop' });
    if (D.grant && D.grant.tab === info.key) dropGrant();
    if (!quiet) await tabEvent('leave', tabId, info);
    if (!D.tabs.size) closeSocket();
    persist();
    releaseOrigin(info);
    return { ok: true };
  }

  /** The site access Chrome gave for a shared tab (only if the share itself had to ask for it) is handed back once no shared tab uses that site any more. */
  function releaseOrigin(info) {
    var origin = info && info.requestedOrigin;
    if (!origin) return;
    var stillUsed = false;
    D.tabs.forEach(function (other) { if (other.requestedOrigin === origin) stillUsed = true; });
    if (stillUsed) return;
    try { chrome.permissions.remove({ origins: [origin] }, function () { void chrome.runtime.lastError; }); } catch (e) { /* a permission that is part of the manifest cannot be removed */ }
  }

  function closeSocket() {
    D.stopped = true;
    clearTimeout(D.reconnect);
    clearInterval(D.hb); D.hb = null;
    D.pending = [];
    if (D.ws) { try { D.ws.close(); } catch (e) { /* already closing */ } D.ws = null; }
    D.subscribed = false;
  }

  /** The session is over (or unusable): stop every shared tab. The server already knows the session ended; no leave events needed. */
  function stopAll(reason) {
    var ids = Array.from(D.tabs.keys());
    var infos = Array.from(D.tabs.values());
    ids.forEach(function (id) { toTab(id, { type: 'desk.stop' }); });
    D.tabs.clear();
    dropGrant();
    infos.forEach(releaseOrigin);
    closeSocket();
    persist();
    console.info('[corex-desk] sharing stopped: ' + reason);
  }

  function startHeartbeat() {
    if (D.hb) return;
    var secs = (D.status && D.status.heartbeat_seconds) || 30;
    D.hb = setInterval(async function () {
      if (!D.tabs.size || !D.status || !D.status.session) return;
      var r = await api('POST', '/api/v1/desk/ext/sessions/' + D.status.session.id + '/heartbeat', {});
      if (r.ok && r.json.status === 'ended') stopAll('session ended');
    }, Math.max(5, secs) * 1000);
  }

  // ── Tab lifecycle: navigation, title, close ─────────────────────────────────────

  chrome.tabs.onRemoved.addListener(function (tabId) { if (D.tabs.has(tabId)) stopTab(tabId, false); });

  chrome.tabs.onUpdated.addListener(async function (tabId, change, tab) {
    var info = D.tabs.get(tabId);
    if (!info) return;
    if (change.title !== undefined) {
      info.title = change.title || info.title;
      clearTimeout(D.titleTimers.get(tabId));
      D.titleTimers.set(tabId, setTimeout(function () { var i = D.tabs.get(tabId); if (i) tabEvent('update', tabId, i); }, 800));
    }
    if (change.status === 'complete') {
      var host = Lib.hostOf(tab.url);
      if (!urlOk(tab.url)) { stopTab(tabId, false); return; }   // moved off the allowed sites (or onto a page that is not https)
      info.host = host; info.url = tab.url; info.title = tab.title || info.title;
      tabEvent('update', tabId, info);
      // A full page load discards the recorder: is it still there? If not, put it back (it sends a fresh snapshot).
      chrome.tabs.sendMessage(tabId, { type: 'desk.ping' }, function (res) {
        if (chrome.runtime.lastError || !res) {
          inject(tabId, D.status && D.status.session && D.status.session.support_name).catch(function () { stopTab(tabId, false); });
        }
      });
    }
  });

  // ── Take-over answers and the report of what ran, over the token ───────────────

  async function controlCall(tabId, what, body) {
    var st = D.status;
    if (!st || !st.session || !D.tabs.has(tabId)) return { ok: false };
    var r = await api('POST', '/api/v1/desk/ext/sessions/' + st.session.id + '/control/' + what, body);
    return { ok: r.ok, message: r.json && r.json.message, grant: r.json && r.json.grant };
  }

  // ── Persistence across a worker restart ─────────────────────────────────────────

  function persist() {
    try {
      var rows = Array.from(D.tabs.entries());
      if (chrome.storage.session) chrome.storage.session.set({ deskTabs: rows });
    } catch (e) { /* storage.session unavailable */ }
  }

  async function restore() {
    if (!chrome.storage.session) return;
    var got = await new Promise(function (r) { chrome.storage.session.get('deskTabs', r); });
    var rows = (got && got.deskTabs) || [];
    if (!rows.length) return;
    var st = await fetchStatus();
    if (st.error || !st.session || st.session.status !== 'live') { persist(); return; }
    rows.forEach(function (row) { D.tabs.set(row[0], row[1]); });
    connect();
    startHeartbeat();
  }

  // ── Messages from the popup and the shared pages ────────────────────────────────

  chrome.runtime.onMessage.addListener(function (msg, sender, respond) {
    if (!msg || typeof msg.type !== 'string' || msg.type.indexOf('desk.') !== 0) return false;

    if (msg.type === 'desk.frames' && sender.tab) { onFrames(sender.tab.id, msg.events); return false; }
    if (msg.type === 'desk.controlRespond' && sender.tab) {
      var rtab = sender.tab.id;
      controlCall(rtab, 'respond', { allow: !!msg.allow, request_id: String(msg.requestId || '') }).then(function (r) {
        // An Allow answer carries the grant (the signing key): this is what lets the page start. Nothing starts without it.
        if (r.ok && msg.allow && r.grant && r.grant.key) { setGrant(Lib.tabKey(rtab), r.grant).then(function () { toTab(rtab, { type: 'desk.control_on', idle: D.idleSeconds }); }); }
        respond({ ok: r.ok, message: r.message });
      });
      return true;
    }
    if (msg.type === 'desk.controlRevoke' && sender.tab) { dropGrant(); controlCall(sender.tab.id, 'revoke', { reason: msg.reason === 'idle' ? 'idle' : 'agent' }).then(respond); return true; }
    if (msg.type === 'desk.indicatorGone' && sender.tab) { stopTab(sender.tab.id, false); return false; }   // the page removed or hid the red bar: sharing stops
    if (msg.type === 'desk.viewport' && sender.tab) { send({ event: 'client-viewport', channel: D.channel, data: { t: Lib.tabKey(sender.tab.id), w: msg.w, h: msg.h, dpr: msg.dpr } }); return false; }
    if (msg.type === 'desk.actionResult' && sender.tab) { send({ event: 'client-action_result', channel: D.channel, data: { t: Lib.tabKey(sender.tab.id), id: msg.id, ok: false, reason: msg.reason } }); return false; }
    if (msg.type === 'desk.controlActions' && sender.tab) { controlCall(sender.tab.id, 'actions', { tab: Lib.tabKey(sender.tab.id), actions: msg.actions }).then(respond); return true; }
    if (msg.type === 'desk.reply' && sender.tab) { reply(sender.tab.id, msg.text); return false; }
    if (msg.type === 'desk.stopMe' && sender.tab) { stopTab(sender.tab.id, false).then(function () { respond({ ok: true }); }); return true; }

    if (msg.type === 'desk.status') {
      fetchStatus().then(function (st) {
        var info = D.tabs.get(msg.tabId);
        respond({
          error: st.error || null, enabled: !!st.enabled, session: st.session || null,
          shared: !!info, sharedCount: D.tabs.size,
          allowedHere: Lib.urlAllowed(msg.url, st.allowed_hosts, st.allowed_hosts_exact), host: Lib.hostOf(msg.url),
        });
      });
      return true;
    }
    if (msg.type === 'desk.share') { shareTab(msg.tabId, msg.requestedOrigin).then(respond); return true; }
    if (msg.type === 'desk.stop') { stopTab(msg.tabId, false).then(respond); return true; }
    return false;
  });

  restore();
})();
