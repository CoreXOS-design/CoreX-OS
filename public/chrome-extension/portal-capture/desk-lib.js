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

  /** A host is allowed when it equals an allowed host or is a subdomain of one. The server list is the authority; this is a courtesy check. */
  function hostAllowed(host, allowed) {
    host = String(host || '').toLowerCase();
    if (!host) return false;
    return (allowed || []).some(function (a) {
      a = String(a || '').toLowerCase();
      return a && (host === a || host.slice(-(a.length + 1)) === '.' + a);
    });
  }

  function tabKey(tabId) { return 'x' + tabId; }

  // ── Masking (third-party sites) ─────────────────────────────────────────────────
  // Passwords, anything in [data-desk-mask], and any input that looks like a card, ID, bank or secret field - by its name, id,
  // autocomplete, placeholder, aria-label or label text. Deliberately generous: a field masked by mistake costs support one question;
  // a field NOT masked can leak an ID or card number. Displayed text is not masked (support sees what the agent sees on screen).

  var SENSITIVE = /(card|cvv|cvc|ccnum|cc-?(number|exp|csc|name)|iban|swift|routing|sort[-_ ]?code|account|acc[-_ ]?(no|num)|branch[-_ ]?code|\bid[-_ ]?(no|num)|identity|passport|id[-_ ]number|tax[-_ ]?(no|num|ref)|sa[-_ ]?id|\bpin\b|\botp\b|secret|\bssn\b|national)/i;

  function isSensitiveField(el) {
    if (!el || el.nodeType !== 1) return false;
    if (String(el.type || '').toLowerCase() === 'password') return true;
    if (el.closest && el.closest('[data-desk-mask]')) return true;
    var ac = (el.getAttribute && el.getAttribute('autocomplete')) || '';
    if (/^cc-/i.test(ac) || /current-password|new-password|one-time-code/i.test(ac)) return true;
    var bits = [];
    ['name', 'id', 'placeholder', 'aria-label', 'title'].forEach(function (a) { var v = el.getAttribute && el.getAttribute(a); if (v) bits.push(v); });
    if (el.labels && el.labels.length) { for (var i = 0; i < el.labels.length; i++) bits.push(el.labels[i].textContent || ''); }
    return SENSITIVE.test(bits.join(' '));
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
    CHUNK: CHUNK, hostOf: hostOf, hostAllowed: hostAllowed, tabKey: tabKey, isSensitiveField: isSensitiveField, maskInputFn: maskInputFn,
    encodeBatch: encodeBatch, channelName: channelName, parseFrame: parseFrame, socketUrl: socketUrl,
  };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.CoreXDeskLib = api;
})(typeof self !== 'undefined' ? self : this);
