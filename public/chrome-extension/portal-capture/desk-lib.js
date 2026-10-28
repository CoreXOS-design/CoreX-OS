/**
 * CoreX Desk (extension) - pure helpers shared by the service worker, the in-page recorder and the unit tests.
 * Spec .ai/specs/corex-desk.md sections 7.6 (protocol) and 13 (extension tabs).
 *
 * No chrome.* and no DOM access at load time, so it runs in a service worker (importScripts), a content script, and plain node.
 * The frame codec here is the SAME wire format as resources/js/desk/frames.js (the CoreX page and the console): a batch of rrweb
 * events -> JSON -> gzip (when the browser can) -> base64 -> chunks of at most CHUNK characters. Protocol version 1.1 adds one
 * optional field, `t`, naming the tab a chunk belongs to ("x<chrome tab id>"; absent = the CoreX page).
 */
(function (root) {
  'use strict';

  var CHUNK = 7000;
  var batchSeq = 0;

  // ── Sites ───────────────────────────────────────────────────────────────────────

  function hostOf(url) {
    try { return new URL(url).hostname.toLowerCase(); } catch (e) { return ''; }
  }

  /**
   * A host is allowed when it equals an allowed host or is a subdomain of one - or equals one of the `exact` hosts (the agency's own website: that host
   * and nothing else, no subdomains). The server list is the authority; this is a courtesy check.
   */
  function hostAllowed(host, allowed, exact) {
    host = String(host || '').toLowerCase();
    if (!host) return false;
    if ((exact || []).some(function (e) { return String(e || '').toLowerCase() === host; })) return true;
    return (allowed || []).some(function (a) {
      a = String(a || '').toLowerCase();
      return a && (host === a || host.slice(-(a.length + 1)) === '.' + a);
    });
  }

  /** Only https pages can be shared (a page served over http can be rewritten by anyone on the path), and only allowed hosts. */
  function urlAllowed(url, allowed, exact) {
    var u;
    try { u = new URL(url); } catch (e) { return false; }
    return u.protocol === 'https:' && hostAllowed(u.hostname, allowed, exact);
  }

  function tabKey(tabId) { return 'x' + tabId; }

  // ── Masking (third-party sites) ─────────────────────────────────────────────────
  // Passwords, anything in [data-desk-mask], and any input that looks like a card, ID, bank or secret field - by its name, id,
  // autocomplete, placeholder, aria-label or label text. Deliberately generous: a field masked by mistake costs support one question;
  // a field NOT masked can leak an ID or card number. Displayed text is not masked (support sees what the agent sees on screen).

  // The text is normalised first - camelCase split ("userIdNumber" -> user_id_number, "SAIdNumber" -> sa_id_number), lower-cased, every run of other
  // characters one "_" - so the patterns never depend on \b anchors (which do not see a "_" or a camelCase hump as a boundary) or on how a site spells a
  // separator. LONG terms match anywhere inside the text; SHORT ones (that would also sit inside ordinary words) only as whole "_"-separated words.
  var LONG = /(card|cvv|cvc|ccnum|cc_?(number|num|exp|csc|name)|iban|swift|routing|sort_?code|account|acc_?(no|num|number)|branch_?code|idnumber|identity|passport|secret|password|passwd|national|bank|birth|credential)/;
  var SHORT = /(^|_)(pin|otp|ssn|tax|taxno|taxnum|taxref|vat|token|key|dob|sa_id|id_no|id_num|id_number|idno|idnum)(_|$)/;

  function norm(text) {
    return String(text || '')
      .replace(/([A-Z]+)([A-Z][a-z])/g, '$1_$2')
      .replace(/([a-z0-9])([A-Z])/g, '$1_$2')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '_');
  }

  function looksSensitive(text) {
    var t = norm(text);
    return LONG.test(t) || SHORT.test(t);
  }

  function isSensitiveField(el) {
    if (!el || el.nodeType !== 1) return false;
    var type = String(el.type || '').toLowerCase();
    if (type === 'password' || type === 'hidden') return true;   // (a hidden input carries tokens, ids and CSRF values: never shown to support)
    if (el.closest && el.closest('[data-desk-mask]')) return true;
    var ac = (el.getAttribute && el.getAttribute('autocomplete')) || '';
    if (/^cc-/i.test(ac) || /current-password|new-password|one-time-code/i.test(ac)) return true;
    var bits = [];
    ['name', 'id', 'placeholder', 'aria-label', 'title'].forEach(function (a) { var v = el.getAttribute && el.getAttribute(a); if (v) bits.push(v); });
    if (el.labels && el.labels.length) { for (var i = 0; i < el.labels.length; i++) bits.push(el.labels[i].textContent || ''); }
    for (var j = 0; j < bits.length; j++) { if (looksSensitive(bits[j])) return true; }
    return false;
  }

  /** rrweb maskInputFn: the real value unless the field is sensitive. (rrweb only calls it for input types switched on in maskInputOptions.) */
  function maskInputFn(text, el) {
    return isSensitiveField(el) ? new Array((text || '').length + 1).join('*') : text;
  }

  // ── Frame codec ─────────────────────────────────────────────────────────────────

  function toBase64(bytes) {
    var bin = '';
    var step = 0x8000;
    for (var i = 0; i < bytes.length; i += step) bin += String.fromCharCode.apply(null, bytes.subarray(i, i + step));
    return btoa(bin);
  }

  async function gzip(bytes) {
    var out = new Blob([bytes]).stream().pipeThrough(new CompressionStream('gzip'));
    return new Uint8Array(await new Response(out).arrayBuffer());
  }

  /** @returns {Promise<Array<{id:string,i:number,n:number,z:number,d:string,t:string}>>} the chunks to whisper, in order */
  async function encodeBatch(events, senderTag, tab) {
    var json = new TextEncoder().encode(JSON.stringify(events));
    var z = 0;
    var bytes = json;
    if (typeof CompressionStream !== 'undefined') {
      try { bytes = await gzip(json); z = 1; } catch (e) { bytes = json; z = 0; }
    }
    var b64 = toBase64(bytes);
    var id = (senderTag || 'e') + Date.now().toString(36) + (batchSeq++).toString(36);
    var n = Math.max(1, Math.ceil(b64.length / CHUNK));
    var chunks = [];
    for (var i = 0; i < n; i++) chunks.push({ id: id, i: i, n: n, z: z, d: b64.slice(i * CHUNK, (i + 1) * CHUNK), t: tab });
    return chunks;
  }

  // ── Take-over signing (spec section 14b) ────────────────────────────────────────
  // A client-action whisper is { t, seq, p, sig }: p = the action as a JSON string, sig = HMAC-SHA256(grant key, seq + "|" + p). The grant key is derived by
  // CoreX per take-over and given only to the agent and the support person who holds control; the worker replays nothing whose signature does not verify
  // or whose seq is not above the last one it accepted. Same format as resources/js/desk/sign.js (the console and the CoreX page) - keep them in step.
  // No WebCrypto (not a secure context) = no take-over: nothing is accepted without it.

  function subtle() {
    var c = root.crypto || (typeof crypto !== 'undefined' ? crypto : null);
    return c && c.subtle ? c.subtle : null;
  }

  function hexToBytes(hex) {
    if (typeof hex !== 'string' || !hex.length || hex.length % 2 || !/^[0-9a-f]+$/i.test(hex)) return null;
    var out = new Uint8Array(hex.length / 2);
    for (var i = 0; i < out.length; i++) out[i] = parseInt(hex.substr(i * 2, 2), 16);
    return out;
  }

  async function hmacKey(keyHex, usage) {
    var raw = hexToBytes(keyHex), sc = subtle();
    if (!raw || raw.length < 16 || !sc) return null;
    return sc.importKey('raw', raw, { name: 'HMAC', hash: 'SHA-256' }, false, [usage]);
  }

  /** @returns {Promise<{seq:number, p:string, sig:string}|null>} */
  async function signAction(keyHex, seq, payload) {
    var key = await hmacKey(keyHex, 'sign');
    if (!key) return null;
    var p = JSON.stringify(payload);
    var sig = await subtle().sign('HMAC', key, new TextEncoder().encode(seq + '|' + p));
    return { seq: seq, p: p, sig: Array.prototype.map.call(new Uint8Array(sig), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('') };
  }

  /** @returns {Promise<{ok:true, seq:number, action:object}|{ok:false, reason:string}>} */
  async function verifyAction(keyHex, msg, lastSeq) {
    if (!msg || typeof msg !== 'object') return { ok: false, reason: 'shape' };
    var seq = msg.seq, p = msg.p, sig = msg.sig;
    if (!Number.isSafeInteger(seq) || seq <= lastSeq) return { ok: false, reason: 'seq' };
    if (typeof p !== 'string' || !p.length || p.length > 20000) return { ok: false, reason: 'shape' };
    var sigBytes = typeof sig === 'string' && sig.length === 64 ? hexToBytes(sig) : null;
    if (!sigBytes) return { ok: false, reason: 'shape' };
    var key = await hmacKey(keyHex, 'verify');
    if (!key) return { ok: false, reason: 'nocrypto' };
    var good = await subtle().verify('HMAC', key, sigBytes, new TextEncoder().encode(seq + '|' + p));
    if (!good) return { ok: false, reason: 'sig' };
    var action;
    try { action = JSON.parse(p); } catch (e) { return { ok: false, reason: 'shape' }; }
    if (!action || typeof action !== 'object' || Array.isArray(action)) return { ok: false, reason: 'shape' };
    return { ok: true, seq: seq, action: action };
  }

  // ── Pusher protocol ─────────────────────────────────────────────────────────────

  function channelName(sessionId) { return 'private-desk.session.' + sessionId; }

  /** One raw websocket frame -> {event, data}. Server events carry data as a JSON string; whispers may arrive as either. */
  function parseFrame(raw) {
    var m = JSON.parse(raw);
    var data = m.data;
    if (typeof data === 'string') { try { data = JSON.parse(data); } catch (e) { /* leave as text */ } }
    return { event: m.event, channel: m.channel, data: data };
  }

  function socketUrl(reverb) {
    var scheme = reverb.scheme === 'http' ? 'ws' : 'wss';
    var port = reverb.port && !((scheme === 'wss' && reverb.port === 443) || (scheme === 'ws' && reverb.port === 80)) ? ':' + reverb.port : '';
    return scheme + '://' + reverb.host + port + '/app/' + reverb.key + '?protocol=7&client=corex-extension&version=1.1';
  }

  var api = {
    CHUNK: CHUNK, hostOf: hostOf, hostAllowed: hostAllowed, urlAllowed: urlAllowed, looksSensitive: looksSensitive, tabKey: tabKey, isSensitiveField: isSensitiveField, maskInputFn: maskInputFn, signAction: signAction, verifyAction: verifyAction,
    encodeBatch: encodeBatch, channelName: channelName, parseFrame: parseFrame, socketUrl: socketUrl,
  };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.CoreXDeskLib = api;
})(typeof self !== 'undefined' ? self : this);
