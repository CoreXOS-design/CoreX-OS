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
    hb: null, retry: 0, reconnect: null, titleTimers: new Map(), stopped: true,
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
    if (r.json.session && r.json.session.control) D.control = { state: r.json.session.control.state === 'granted' ? 'granted' : (r.json.session.control.state === 'requested' ? 'requested' : 'none'), tab: r.json.session.control.tab };
    if (r.json.control_idle_seconds) D.idleSeconds = r.json.control_idle_seconds;
    return r.json;
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
      var t2 = tabIdFor(m.data && m.data.t); if (t2 !== null) toTab(t2, { type: 'desk.pointer', x: m.data.x, y: m.data.y });
    } else if (ev === 'desk.highlight') {
      var t3 = tabIdFor(m.data && m.data.tab); if (t3 !== null) toTab(t3, { type: 'desk.highlight', selector: m.data.selector, note: m.data.note });
    } else if (ev === 'desk.highlight_cleared') {
      var t4 = tabIdFor(m.data && m.data.tab); if (t4 !== null) toTab(t4, { type: 'desk.highlight_clear' });
    } else if (ev === 'desk.pointer_state') {
      var t5 = tabIdFor(m.data && m.data.tab); if (t5 !== null && m.data && !m.data.on) toTab(t5, { type: 'desk.pointer_hide' });
    } else if (ev === 'client-action') {
      // Take-over (protocol 1.2): an action for one of our tabs. The page replays it only if the agent has pressed Allow THERE.
      var ta = tabIdFor(m.data && m.data.t); if (ta !== null) toTab(ta, { type: 'desk.action', a: m.data });
    } else if (ev === 'desk.control_requested') {
      var c1 = tabIdFor(m.data && m.data.tab); D.control = { state: c1 !== null ? 'requested' : 'none', tab: m.data && m.data.tab };
      if (c1 !== null) toTab(c1, { type: 'desk.control_ask', support: m.data.support_name });
    } else if (ev === 'desk.control_granted') {
      var c2 = tabIdFor(m.data && m.data.tab); if (c2 !== null) { D.control = { state: 'granted', tab: m.data.tab }; D.idleSeconds = m.data.idle_seconds || D.idleSeconds; toTab(c2, { type: 'desk.control_on', idle: D.idleSeconds }); }
    } else if (ev === 'desk.control_declined' || ev === 'desk.control_revoked' || ev === 'desk.control_ended') {
      var c3 = tabIdFor(m.data && m.data.tab); if (c3 !== null) { D.control = { state: 'none', tab: null }; toTab(c3, { type: 'desk.control_off', why: ev.replace('desk.control_', '') }); }
    } else if (ev === 'desk.ended') {
      stopAll('session ended');
    }
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
    toTab(tabId, { type: 'desk.start', key: Lib.tabKey(tabId), support: supportName || 'CoreX support' });
    // A full page load discards the page's consent state: hand it back (a request still open, or a take-over in progress).
    var key = Lib.tabKey(tabId);
    if (D.control.tab === key && D.control.state === 'requested') toTab(tabId, { type: 'desk.control_ask', support: supportName });
    if (D.control.tab === key && D.control.state === 'granted') toTab(tabId, { type: 'desk.control_on', idle: D.idleSeconds, resumed: true });
  }

  async function shareTab(tabId) {
    var tab;
    try { tab = await chrome.tabs.get(tabId); } catch (e) { return { ok: false, error: 'That tab is gone.' }; }
    var st = await fetchStatus();
    if (st.error) return { ok: false, error: st.error };
    if (!st.enabled) return { ok: false, error: 'CoreX Desk is not available for your agency.' };
    if (!st.session) return { ok: false, error: 'No Desk session is open. Press Get help in CoreX first.' };
    if (st.session.status !== 'live') return { ok: false, error: 'CoreX support has not joined yet.' };
    var host = Lib.hostOf(tab.url);
    if (!Lib.hostAllowed(host, st.allowed_hosts)) return { ok: false, error: 'This site cannot be shared with CoreX support.' };

    var info = { key: Lib.tabKey(tabId), host: host, title: tab.title || '', url: tab.url };
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
    if (!quiet) await tabEvent('leave', tabId, info);
    if (!D.tabs.size) closeSocket();
    persist();
    return { ok: true };
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
    ids.forEach(function (id) { toTab(id, { type: 'desk.stop' }); });
    D.tabs.clear();
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
      if (!Lib.hostAllowed(host, D.status && D.status.allowed_hosts)) { stopTab(tabId, false); return; }   // moved off the allowed sites
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
    return { ok: r.ok, message: r.json && r.json.message };
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
    if (msg.type === 'desk.controlRespond' && sender.tab) { controlCall(sender.tab.id, 'respond', { allow: !!msg.allow }).then(respond); return true; }
    if (msg.type === 'desk.controlRevoke' && sender.tab) { controlCall(sender.tab.id, 'revoke', { reason: msg.reason === 'idle' ? 'idle' : 'agent' }).then(respond); return true; }
    if (msg.type === 'desk.controlActions' && sender.tab) { controlCall(sender.tab.id, 'actions', { tab: Lib.tabKey(sender.tab.id), actions: msg.actions }).then(respond); return true; }
    if (msg.type === 'desk.stopMe' && sender.tab) { stopTab(sender.tab.id, false).then(function () { respond({ ok: true }); }); return true; }

    if (msg.type === 'desk.status') {
      fetchStatus().then(function (st) {
        var info = D.tabs.get(msg.tabId);
        respond({
          error: st.error || null, enabled: !!st.enabled, session: st.session || null,
          shared: !!info, sharedCount: D.tabs.size,
          allowedHere: Lib.hostAllowed(Lib.hostOf(msg.url), st.allowed_hosts), host: Lib.hostOf(msg.url),
        });
      });
      return true;
    }
    if (msg.type === 'desk.share') { shareTab(msg.tabId).then(respond); return true; }
    if (msg.type === 'desk.stop') { stopTab(msg.tabId, false).then(respond); return true; }
    return false;
  });

  restore();
})();
