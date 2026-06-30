/**
 * CoreX WhatsApp Capture — content script (READ-ONLY).
 *
 * v1.4.0 (AT-135): message BODY via DOM fallback. WhatsApp stores bodies encrypted-
 * at-rest in IndexedDB (msgRowOpaqueData), so idbExtract often gets no plaintext.
 * When a message has no plaintext IDB body and isn't media, we read the rendered
 * bubble text from the OPEN chat (READ-ONLY scrape, AT-44 §8) and fill it — joined
 * by message_id (data-id), with a direction+minute fallback. Bubble not in DOM →
 * body_unreadable:true (server marks body_status=unreadable; never a silent blank).
 *
 * v1.3.0 (AT-133): @lid → phone AUTO-RESOLUTION. Q1 proved 26/26 @lid chats resolve
 * to a real …@c.us via the contact store (phoneNumber), so every message now POSTs
 * counterpart_phone (resolved …@c.us) + counterpart_lid (audit) + resolved flag —
 * the server matches on the real number. The read-only lidResolve probe is retained.
 *
 * v1.2.1 (AT-133): one-time, READ-ONLY @lid→phone resolution probe — [CoreX WA]
 * lidResolve line per @lid chat (Q1: is the phone reachable from the @lid?).
 *
 * v1.2.0: PRIMARY message source is WhatsApp Web's `model-storage` IndexedDB
 * (durable cleartext metadata + plaintext body), read READ-ONLY — see the
 * "IndexedDB reader" section below. DOM scraping is retained ONLY as a body
 * fallback for any non-plaintext message. It NEVER sends a message, never
 * touches the compose box, never writes to the DB — capture only.
 *
 * ── AT-44 detection (v1.1.2 — re-pinned to current WA Web) ───────────────────
 * WhatsApp Web obfuscated ALL message class names (no more .message-in/.message-out)
 * and no longer puts a data-id on the message bubble, so every class/data-id based
 * selector matched NOTHING and the build captured zero messages (last_seen "never").
 *
 * Verified against Johan's LIVE web.whatsapp.com DOM (both directions):
 *   #main
 *     └ div[role="application"]                 ← message list
 *         └ div[role="row"] (when present)      ← per-message wrapper
 *             └ div.copyable-text[data-pre-plain-text="[HH:MM, M/D/YYYY] Sender: "]
 *                 └ span[data-testid="selectable-text"]  ← the message text
 *                 └ div[data-testid="msg-meta"]          ← time + (outbound) read-receipt tick
 *
 * Stable anchors used (NOT the obfuscated classes):
 *   • .copyable-text[data-pre-plain-text]  → THE message anchor; holds time + sender.
 *   • span[data-testid="selectable-text"]  → message text.
 *   • [data-icon="msg-dblcheck"|"msg-check"|…] inside the bubble → OUTBOUND marker
 *     (inbound has a timestamp but no tick). We also LEARN the account owner's
 *     display name from a ticked message and treat that sender as outbound.
 *   • dedup id: a true_/false_<jid>_<id> data-id on an ancestor if WA still emits
 *     one (gives exact direction + jid); else a stable hash of sender+time+text.
 *
 * Selector churn is isolated to the SELECTORS block on purpose (spec §6). When WA
 * changes the DOM again, update SELECTORS / extractMessage here; nothing else in
 * CoreX is affected. Re-verify with the jsdom harness against a fresh DOM sample.
 */

/* ── Debug logging ───────────────────────────────────────────────────────────
 * Always-on, prefixed, low-volume by default; flip DEBUG (or set
 * chrome.storage.local { waDebug: true }) for per-message verbosity. This is
 * the guard against another silent failure: the console always tells you the
 * script loaded, how many rows it matched, and the result of every POST.
 */
let DEBUG = false;
const TAG = '[CoreX WA]';
// Version straight from the loaded manifest — logged on load so it's instantly
// obvious whether a reload actually took (vs Chrome running stale code).
const VERSION = (typeof chrome !== 'undefined' && chrome.runtime && chrome.runtime.getManifest)
  ? (chrome.runtime.getManifest().version || '?') : '?';
function log(...a) { try { console.log(TAG, ...a); } catch (e) {} }
function vlog(...a) { if (DEBUG) { try { console.log(TAG, ...a); } catch (e) {} } }
function warn(...a) { try { console.warn(TAG, ...a); } catch (e) {} }
let _lastDiagAt = 0;

const SELECTORS = {
  main: '#main',
  // The scrollable message list inside the open conversation.
  messageList: '#main div[role="application"], #main .copyable-area, #main',
  // PRIMARY message anchor (AT-44 / v1.1.2). WA Web obfuscated all class names
  // (no more .message-in/.message-out) and no longer puts data-id on the bubble.
  // Every rendered TEXT message still carries a `.copyable-text` node with the
  // time + sender in `data-pre-plain-text` — that data-* hook is stable. We
  // anchor on it and walk outward for direction / id.
  messageMeta: '#main .copyable-text[data-pre-plain-text]',
  // The message body. data-testid is stable; keep the legacy class as fallback.
  textSpan: 'span[data-testid="selectable-text"], span.selectable-text',
  // Outbound marker: only SENT messages render a delivery/read status tick.
  // Inbound messages have a timestamp but no tick.
  outboundTick: '[data-icon="msg-check"], [data-icon="msg-dblcheck"], [data-icon="msg-dblcheck-ack"], [data-icon="msg-time"], [aria-label="Sent"], [aria-label="Delivered"], [aria-label="Read"]',
  // Optional enrichment: a true_/false_<jid>_<id> data-id may still live on an
  // ancestor row — when present it gives exact direction + jid + message id.
  dataIdEl: '[data-id]',
  // Left chat list (history sweep).
  chatListPane: '#pane-side',
  chatListItem: '#pane-side div[role="listitem"]',
};

// data-id of a real message starts with the fromMe flag: true_ / false_.
// Status/system rows (date separators, "encrypted" notice) do not match.
const DATA_ID_RE = /^(true|false)_/;

const SWEEP_INTERVAL_MS = 30000;     // fallback periodic sweep (the observer is primary)
const FIRST_SWEEP_MS = 4000;         // first sweep shortly after load
const OBSERVER_DEBOUNCE_MS = 600;    // coalesce rapid DOM mutations
const HEARTBEAT_INTERVAL_MS = 120000; // liveness ping → stamps last_seen_at

// History sweep pacing (human-like, ToS-conservative — spec §8).
const HISTORY_SWEEP_INTERVAL_MS = 120000; // re-evaluate every 2 min
const IDLE_BEFORE_SWEEP_MS = 12000;       // only auto-walk when the agent is idle
const OPEN_CHAT_RENDER_MS = 1500;         // let an opened chat render
const BETWEEN_CHATS_MIN_MS = 6000;        // randomised gap between chats
const BETWEEN_CHATS_MAX_MS = 14000;
const HISTORY_SCROLL_STEPS = 25;          // max scroll-up steps per chat backfill
const HISTORY_SCROLL_PAUSE_MS = 700;

const lastSeenByChat = {};   // chatId -> last message id captured this session (mirror of storage)
const contactCache = {};     // number -> bool (resolved via the contact-check endpoint)
let ownerName = null;        // this account's own display name (learned from outbound ticks)
let historySweepEnabled = true;
let backfillEnabled = true;  // AT-135 — agency toggle (from ping); read-only body backfill sweep
let backfillTargets = null;  // AT-135 — Set of last-9 numbers with unreadable bodies (server)
const MAX_BACKFILL_CHATS_PER_RUN = 6; // AT-135 — cap chats opened per idle run (paced/ToS)
let lastUserInteractionAt = 0;
let sweepRunning = false;
let observer = null;
let observedList = null;
let debounceTimer = null;

/* ── Init ────────────────────────────────────────────────────────────────── */
init();

async function init() {
  try {
    const cfg = await chrome.storage.local.get(['waDebug', 'historySweepEnabled', 'lastSeenByChat', 'waOwnerName']);
    DEBUG = !!cfg.waDebug;
    if (typeof cfg.historySweepEnabled === 'boolean') historySweepEnabled = cfg.historySweepEnabled;
    if (cfg.waOwnerName) ownerName = cfg.waOwnerName;
    if (cfg.lastSeenByChat && typeof cfg.lastSeenByChat === 'object') {
      Object.assign(lastSeenByChat, cfg.lastSeenByChat);
    }
  } catch (e) { /* storage may be unavailable very early; defaults are fine */ }

  log('v' + VERSION + ' content script loaded on', location.host, '— debug:', DEBUG, '| history sweep:', historySweepEnabled);

  // Heartbeat FIRST — proves the whole pipe (injection → background → network →
  // CORS → auth → DB) independent of WhatsApp's DOM. It hits an authenticated
  // endpoint, so the auth.wa_capture middleware stamps last_seen_at on success.
  // If this logs an error/401/wrong-URL, the break is config/token — NOT message
  // detection. This is the diagnostic that ends "is it even sending?" forever.
  heartbeat('load');
  setInterval(() => heartbeat('interval'), HEARTBEAT_INTERVAL_MS);

  // Track user activity so the history walk only runs when the agent is idle
  // (never hijacks an active session — "built for agents, not screens").
  ['click', 'keydown', 'mousemove', 'wheel', 'touchstart'].forEach((ev) => {
    document.addEventListener(ev, markInteraction, { passive: true, capture: true });
  });
  markInteraction();

  // The MutationObserver is just a cheap "something changed, re-read the DB now"
  // trigger; the actual read is from IndexedDB (model-storage), not the DOM.
  attachObserver();
  setInterval(attachObserver, 8000);                 // re-attach if WA re-mounts the pane
  setInterval(() => sweep('interval'), SWEEP_INTERVAL_MS); // catches background-chat messages
  setTimeout(() => sweep('first-load'), FIRST_SWEEP_MS);

  // AT-135 — RE-ENABLED, idle-gated, agency-toggled, READ-ONLY body backfill.
  // The envelope of every message is already captured from IndexedDB (no
  // navigation). WhatsApp stores bodies encrypted-at-rest, so older/unopened
  // chats archive body_status=unreadable; this sweep opens ONLY those chats
  // (server tells us which), strictly read-only (open + scroll + read rendered
  // text — never compose/send), while the agent is idle, capped per run, to
  // recover the bodies for FICA retention. Gated by the agency toggle (ping).
  setInterval(maybeRunHistorySweep, HISTORY_SWEEP_INTERVAL_MS);
}

/**
 * Liveness ping. Sends an authenticated no-op to the server so last_seen_at
 * stamps the moment the extension is correctly configured — regardless of
 * whether any message has been detected yet. Logs the EXACT target URL and
 * outcome so a wrong baseUrl or a stale/revoked token is obvious at a glance.
 */
function heartbeat(reason) {
  chrome.runtime.sendMessage({ type: 'WA_PING' }, (resp) => {
    if (chrome.runtime.lastError) { warn('heartbeat[' + reason + '] no background:', chrome.runtime.lastError.message); return; }
    if (!resp) { warn('heartbeat[' + reason + '] no response'); return; }
    if (resp.error === 'not_configured') {
      warn('heartbeat[' + reason + '] NOT CONFIGURED — set CoreX URL + device token in the extension popup.');
      return;
    }
    if (resp.ok) {
      // AT-135 — pick up the agency's read-only backfill toggle from the ping.
      if (resp.body && typeof resp.body.backfill_enabled === 'boolean') {
        backfillEnabled = resp.body.backfill_enabled;
      }
      log('heartbeat[' + reason + '] OK ' + resp.status + ' → ' + resp.url + ' | device #' + ((resp.body && resp.body.device_id) || '?') + ' last_seen stamped | backfill:' + backfillEnabled);
    } else {
      warn('heartbeat[' + reason + '] FAILED status=' + resp.status + ' → ' + resp.url +
           (resp.status === 401 ? ' | TOKEN REJECTED — paste the CURRENT token from My Portal → WhatsApp Capture (you may be holding a revoked one).'
            : ' | error=' + (resp.error || (resp.body && resp.body.error))));
    }
  });
}

function markInteraction() { lastUserInteractionAt = Date.now(); }
function agentIsIdle() { return Date.now() - lastUserInteractionAt >= IDLE_BEFORE_SWEEP_MS; }

/* ── MutationObserver (primary detection) ────────────────────────────────── */
function attachObserver() {
  const list = document.querySelector(SELECTORS.messageList) || document.querySelector(SELECTORS.main);
  if (!list) return;
  if (observer && observedList === list) return; // already watching this node

  if (observer) observer.disconnect();
  observedList = list;
  observer = new MutationObserver(() => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => sweep('observer'), OBSERVER_DEBOUNCE_MS);
  });
  observer.observe(list, { childList: true, subtree: true });
  vlog('observer attached to', list.getAttribute('role') || list.id || list.className);
  sweep('observer-attach');
}

/* ── Chat id + message extraction ────────────────────────────────────────── */
function currentChatId() {
  const main = document.querySelector(SELECTORS.main);
  if (!main) return null;
  // Prefer the chat jid from any message data-id (true_/false_<jid>_<id>) if WA
  // still exposes it on an ancestor — exact and stable across contact renames.
  for (const el of main.querySelectorAll(SELECTORS.dataIdEl)) {
    const did = el.getAttribute('data-id') || '';
    const parts = did.split('_');
    if (DATA_ID_RE.test(did) && parts.length >= 2 && parts[1]) return parts[1];
  }
  // Fallback: the header title (contact name or number). Groups threads stably
  // even now that WA no longer exposes the jid on the bubble. Never blocks capture.
  const header = main.querySelector('header');
  const title = header ? ((header.innerText || header.textContent || '').split('\n')[0].trim()) : '';
  return title ? 'title:' + title : null;
}

/** Stable djb2 hash → short hex. Used to derive a dedup key when no data-id exists. */
function hashStr(s) {
  let h = 5381;
  for (let i = 0; i < s.length; i++) h = (((h << 5) + h) + s.charCodeAt(i)) >>> 0;
  return h.toString(16);
}

/** Walk UP from a node to the nearest ancestor carrying a data-id attribute. */
function dataIdAncestor(el) {
  for (let n = el, i = 0; n && i < 14; n = n.parentElement, i++) {
    if (n.getAttribute && n.getAttribute('data-id')) return n;
  }
  return null;
}

/**
 * All message-bearing elements in the open chat, in DOM order. Anchored on the
 * `.copyable-text[data-pre-plain-text]` node that every rendered text message
 * carries (WA's obfuscated classes are unusable; this data-* hook is stable).
 */
function messageEls(root) {
  return Array.from(root.querySelectorAll(SELECTORS.messageMeta));
}

/** @param metaEl a `.copyable-text[data-pre-plain-text]` node (see messageEls). */
function extractMessage(metaEl, chatId) {
  const meta = metaEl.getAttribute('data-pre-plain-text') || '';
  // meta: "[13:57, 6/15/2026] Johan Reichel: " — bracketed time+date, then
  // "Sender Name: ". Sender is the LAST "...: " segment before end.
  const mm = meta.match(/^\s*\[[^\]]*\]\s*([\s\S]*?):\s*$/);
  const sender = mm ? mm[1].trim() : '';
  const timestamp = parseTimestamp(meta);

  const textEl = metaEl.querySelector(SELECTORS.textSpan);
  const text = textEl ? (textEl.innerText || textEl.textContent || '') : '';

  // Scope for direction/media detection. Prefer the message's own role="row"
  // wrapper (isolates one message in real WA Web). NEVER widen to the message
  // list — that leaks one message's read-receipt tick onto its neighbours and
  // marks everything outbound. The tick/meta lives inside .copyable-text anyway,
  // so metaEl itself is a safe tight fallback.
  const scope = metaEl.closest('[role="row"]') || metaEl;

  // Direction + ids. Best case: a true_/false_<jid>_<id> data-id on an ancestor
  // (exact direction, jid and message id). Otherwise fall back to the tick +
  // a derived stable key.
  let direction = '', messageId = '', chatJid = '';
  const idEl = dataIdAncestor(metaEl);
  if (idEl) {
    const did = idEl.getAttribute('data-id') || '';
    const parts = did.split('_');
    if (DATA_ID_RE.test(did) && parts.length >= 3) {
      direction = parts[0] === 'true' ? 'out' : 'in';
      chatJid = parts[1];
      messageId = parts.slice(2).join('_');
    } else if (did) {
      messageId = did; // some other stable data-id — use as the dedup key
    }
  }
  if (!direction) {
    // No usable data-id. Two complementary signals, no hardcoded names:
    //  1) a delivery/read status tick → outbound (inbound has none); this also
    //     LEARNS the account owner's name (the sender of a ticked message).
    //  2) once learned, any message whose sender == owner is outbound too
    //     (covers ticks that render differently on older/edge messages).
    const hasTick = !!scope.querySelector(SELECTORS.outboundTick);
    if (hasTick) {
      direction = 'out';
      if (sender && sender !== ownerName) { ownerName = sender; try { chrome.storage.local.set({ waOwnerName: ownerName }); } catch (e) {} }
    } else if (ownerName && sender === ownerName) {
      direction = 'out';
    } else {
      direction = 'in';
    }
  }
  if (!messageId) {
    // Derive a STABLE dedup key from the immutable parts of the message so the
    // server still dedups across sweeps even when WA exposes no message id.
    messageId = 'wa_' + hashStr((chatId || '') + '|' + (timestamp || meta) + '|' + direction + '|' + sender + '|' + text);
  }

  const hasMedia = !!scope.querySelector(
    'img[src^="blob:"], video, [data-icon="audio-play"], [data-testid="audio-play"], [data-testid="media-content"]'
  );

  return {
    message_id: messageId,
    chat_id: chatJid || chatId,
    direction: direction,
    sender: direction === 'out' ? '' : sender,
    timestamp: timestamp,
    text: text || '',
    has_media: hasMedia,
    // Media bytes are intentionally NOT scraped in v1 (read-only, privacy
    // conservative). has_media flags presence; blob upload is a follow-up.
    media: [],
  };
}

/**
 * Best-effort ISO timestamp from WA's "[HH:MM, YYYY/MM/DD]" (or locale
 * "[HH:MM, DD/MM/YYYY]") data-pre-plain-text prefix. Date() can't parse this
 * format in any engine, so we parse it explicitly. Returns '' if unsure — the
 * server then stamps occurred_at = now() (forward capture is near-real-time).
 */
function parseTimestamp(meta) {
  const m = meta.match(/\[([^\]]+)\]/);
  if (!m) return '';
  const inner = m[1].trim();
  const tm = inner.match(/(\d{1,2}):(\d{2})(?::(\d{2}))?/);
  const dm = inner.match(/(\d{1,4})[\/\-.](\d{1,2})[\/\-.](\d{1,4})/);
  if (!tm || !dm) {
    const d = new Date(inner);
    return isNaN(d.getTime()) ? '' : d.toISOString();
  }
  let a = +dm[1], b = +dm[2], c = +dm[3], year, month, day;
  if (dm[1].length === 4) {            // YYYY/MM/DD
    year = a; month = b; day = c;
  } else {
    year = c < 100 ? c + 2000 : c;     // 2-digit year → 20xx
    // Disambiguate M/D vs D/M by which value can't be a month. WA's
    // data-pre-plain-text follows the account locale (Johan's renders
    // M/D/YYYY, e.g. "6/15/2026"); default to M/D when ambiguous.
    if (a > 12 && b <= 12) { day = a; month = b; }   // D/M/YYYY
    else { month = a; day = b; }                      // M/D/YYYY (default)
  }
  const hh = +tm[1], mn = +tm[2], ss = tm[3] ? +tm[3] : 0;
  if (month < 1 || month > 12 || day < 1 || day > 31) return '';
  const d = new Date(year, month - 1, day, hh, mn, ss);
  return isNaN(d.getTime()) ? '' : d.toISOString();
}

/**
 * LOUD diagnostic — logged on (almost) every sweep, match or no match, so we can
 * SEE where the messages are vs where we're looking. Throttled to ~2s so the
 * MutationObserver can't flood the console. This is the instrument that ends
 * blind selector-guessing: the counts say definitively whether #main exists,
 * whether .copyable-text / [data-pre-plain-text] are present at all, and whether
 * they're inside #main or somewhere else (e.g. a shadow root / different host).
 */
function sweepDiag(reason) {
  const now = Date.now();
  if (now - _lastDiagAt < 2000) return;
  _lastDiagAt = now;

  const main = document.querySelector('#main');
  const docCopyable = document.querySelectorAll('.copyable-text').length;
  const docPre = document.querySelectorAll('[data-pre-plain-text]').length;
  const docAnchor = document.querySelectorAll('.copyable-text[data-pre-plain-text]').length;
  const inMain = main ? main.querySelectorAll('.copyable-text[data-pre-plain-text]').length : 0;

  log('DIAG[' + reason + '] #main=' + (!!main) +
      ' | .copyable-text(doc)=' + docCopyable +
      ' | [data-pre-plain-text](doc)=' + docPre +
      ' | .copyable-text[data-pre-plain-text](doc)=' + docAnchor +
      ' | inMain=' + inMain + ' outsideMain=' + (docAnchor - inMain));

  const first = (main && main.querySelector('.copyable-text[data-pre-plain-text]'))
    || document.querySelector('.copyable-text[data-pre-plain-text]')
    || document.querySelector('[data-pre-plain-text]');
  if (first) {
    log('DIAG first anchor outerHTML[0:400]:', (first.outerHTML || '').slice(0, 400));
  } else if (docCopyable === 0 && docPre === 0) {
    warn('DIAG no .copyable-text and no [data-pre-plain-text] ANYWHERE in document — ' +
         'WA may have renamed them OR the messages are inside a shadow DOM / iframe (querySelector can\'t see those).');
  }
}

/* ── DOM sweep (RETAINED as the encrypted-body fallback + DIAG; not scheduled
 *    in v1.2.0 — the IndexedDB reader below is the primary path) ──────────── */
function domSweep(reason) {
  sweepDiag(reason); // always (throttled) — runs even when nothing matches

  const root = document.querySelector(SELECTORS.main);
  if (!root) { vlog('sweep skipped — no #main (no chat open)'); return; }

  const chatId = currentChatId();
  if (!chatId) { vlog('sweep skipped — no chat id'); return; }

  const els = messageEls(root);
  log('sweep[' + reason + '] chat ' + chatId + ' — matched ' + els.length + ' message rows');
  if (!els.length) {
    if (reason !== 'observer') warn('0 message rows matched for', chatId, '— see DIAG line above for where .copyable-text actually is.');
    return;
  }

  const seen = lastSeenByChat[chatId] || null;
  const batch = [];
  let newLast = seen;
  let passedSeen = !seen;

  for (const el of els) {
    const msg = extractMessage(el, chatId);
    if (!msg) continue;
    if (!passedSeen) {
      if (msg.message_id === seen) passedSeen = true;
      continue; // skip up to and including the last-seen message
    }
    batch.push(msg);
    newLast = msg.message_id;
  }

  if (batch.length) {
    recordLastSeen(chatId, newLast);
    log('sweep[' + reason + '] sending', batch.length, 'new message(s) for', chatId);
    send(batch);
  } else if (!seen && els.length) {
    // First time on this chat with no stored cursor: mark the latest as seen so
    // we only forward-capture (history backfill is the sweep's job, contact-gated).
    const last = extractMessage(els[els.length - 1], chatId);
    if (last) { recordLastSeen(chatId, last.message_id); vlog('first sight of', chatId, '— cursor set, no backfill (forward-only)'); }
  }
}

function recordLastSeen(chatId, id) {
  lastSeenByChat[chatId] = id;
  try { chrome.storage.local.set({ lastSeenByChat }); } catch (e) {}
}

function send(messages) {
  chrome.runtime.sendMessage({ type: 'WA_CAPTURE_BATCH', messages }, (resp) => {
    if (chrome.runtime.lastError) { warn('POST failed (runtime):', chrome.runtime.lastError.message); return; }
    if (!resp) { warn('POST: no response from background'); return; }
    if (resp.ok) log('POST ok — status', resp.status, '| stats', JSON.stringify(resp.body && resp.body.stats || {}));
    else warn('POST not ok — status', resp.status, '| error', resp.error || (resp.body && resp.body.error));
  });
}

/* ════════════════════════════════════════════════════════════════════════════
 * IndexedDB reader (v1.2.0) — the PRIMARY, durable message source.
 *
 * WhatsApp Web keeps its data in the `model-storage` IndexedDB. A content script
 * shares the page origin, so it can open that DB and read it — READ-ONLY, never
 * a write. The `message` store's record `id` is the canonical
 * `{true|false}_{chatJid}_{msgId}` (direction + chat + id, no obfuscated classes),
 * with cleartext `t` (unix seconds), `from`/`author`, `type`, and `body`.
 * `sweep()` now delegates here; the DOM path above is kept only as a body
 * fallback for any message whose body is not plaintext in the DB.
 * ════════════════════════════════════════════════════════════════════════════ */
const MODEL_DB = 'model-storage';
const MAX_MSG_SCAN = 1500;        // newest-N messages read per sweep
let idbSweeping = false;
let schemaDumped = false;
let lidProbeDone = false;         // AT-133: one-time @lid→phone resolution probe
let contactNameByJid = {};        // jid -> display name (from the contact store)
let phoneByJid = {};              // AT-133: jid (@lid or @c.us) -> resolved …@c.us phone jid

function sweep(reason) { idbSweep(reason); } // the scheduled entry point

/* ── AT-133 — @lid → phone resolution PROBE (read-only, one-time) ─────────────
 * Q1: does WA Web's model-storage map an @lid (e.g. 222758646611979@lid) to the
 * real …@c.us phone number? We read the contact/lid/wid stores (NOT the message
 * store), try to resolve each @lid seen as a chat_id, and print the result in the
 * standard [CoreX WA] style — Johan just reads the line, no console pasting.
 * READ-ONLY: reads IndexedDB + console.log only. No writes, no POST. ───────────*/
const RE_CUS = /^\d{5,}@c\.us$/;
function isLid(j) { return typeof j === 'string' && j.endsWith('@lid'); }
/** Recursively find the first …@c.us phone jid anywhere in a record (depth-capped). */
function findPhoneJid(obj, depth) {
  depth = depth || 0;
  if (obj == null || depth > 5) return '';
  if (typeof obj === 'string') return RE_CUS.test(obj) ? obj : '';
  if (typeof obj === 'object') {
    for (const k of ['phoneNumber', 'pnJid', 'pn', 'wid', 'jid', 'id']) {
      const s = serId(obj[k]); if (RE_CUS.test(s)) return s;
    }
    for (const k in obj) { try { const f = findPhoneJid(obj[k], depth + 1); if (f) return f; } catch (e) {} }
  }
  return '';
}

/** Resolve any jid to its …@c.us phone jid: identity for @c.us, contact-store map for @lid. */
function resolvePhoneJid(jid) {
  if (RE_CUS.test(jid)) return jid;
  if (isLid(jid)) return phoneByJid[jid] || '';
  return '';
}

function serId(x) {
  if (typeof x === 'string') return x;
  if (x && typeof x._serialized === 'string') return x._serialized;
  return '';
}

function idbOpen(name) {
  return new Promise((res) => {
    let r;
    try { r = indexedDB.open(name); } catch (e) { return res(null); }
    r.onsuccess = () => res(r.result);
    r.onerror = () => res(null);
    r.onblocked = () => res(null);
  });
}

function pickStore(db, preferred, re) {
  const names = Array.from(db.objectStoreNames);
  for (const n of preferred) if (names.includes(n)) return n;
  return names.find((n) => re.test(n)) || null;
}

/** Read newest-first via a `t` (timestamp) index if present; else scan + sort. */
function idbReadNewest(db, store, limit) {
  return new Promise((res) => {
    const recs = [];
    try {
      const os = db.transaction(store, 'readonly').objectStore(store);
      const hasT = os.indexNames && os.indexNames.contains('t');
      if (hasT) {
        const cur = os.index('t').openCursor(null, 'prev');
        cur.onsuccess = (e) => { const c = e.target.result; if (c && recs.length < limit) { recs.push({ key: c.primaryKey, value: c.value }); c.continue(); } else res({ recs, indexed: true }); };
        cur.onerror = () => res({ recs, indexed: true });
      } else {
        const cap = limit * 8; // read more, then sort by t desc in memory
        const cur = os.openCursor();
        cur.onsuccess = (e) => {
          const c = e.target.result;
          if (c && recs.length < cap) { recs.push({ key: c.primaryKey, value: c.value }); c.continue(); }
          else { recs.sort((a, b) => ((b.value && b.value.t) || 0) - ((a.value && a.value.t) || 0)); res({ recs: recs.slice(0, limit), indexed: false }); }
        };
        cur.onerror = () => { recs.sort((a, b) => ((b.value && b.value.t) || 0) - ((a.value && a.value.t) || 0)); res({ recs: recs.slice(0, limit), indexed: false }); };
      }
    } catch (e) { res({ recs, indexed: false }); }
  });
}

function idbReadAll(db, store, limit) {
  return new Promise((res) => {
    const recs = [];
    try {
      const cur = db.transaction(store, 'readonly').objectStore(store).openCursor();
      cur.onsuccess = (e) => { const c = e.target.result; if (c && recs.length < limit) { recs.push({ key: c.primaryKey, value: c.value }); c.continue(); } else res(recs); };
      cur.onerror = () => res(recs);
    } catch (e) { res(recs); }
  });
}

/** Map a message record → our payload shape (cleartext metadata + body). */
function idbExtract(rec) {
  const v = rec.value || {};
  let idStr = serId(v.id) || (typeof rec.key === 'string' ? rec.key : serId(rec.key));
  if (!idStr) return null;
  const parts = idStr.split('_');
  const fromMe = parts[0] === 'true';
  const chatJid = parts.length >= 2 ? parts[1] : '';
  const msgId = parts.length >= 3 ? parts.slice(2).join('_') : idStr;
  if (!chatJid || !msgId) return null;

  const t = typeof v.t === 'number' ? v.t : (typeof v.ts === 'number' ? v.ts : 0);
  const timestamp = t ? new Date(t * 1000).toISOString() : '';

  let text = '';
  if (typeof v.body === 'string') text = v.body;
  else if (typeof v.caption === 'string') text = v.caption;

  const type = v.type || 'chat';
  const hasMedia = ['image', 'video', 'audio', 'ptt', 'document', 'sticker', 'gif'].indexOf(type) >= 0;

  const senderJid = fromMe ? '' : (serId(v.author) || serId(v.from) || chatJid);
  const sender = senderJid ? (contactNameByJid[senderJid] || senderJid.split('@')[0]) : '';

  // AT-133 — resolve the counterpart's REAL …@c.us phone. In a 1:1 chat the chat
  // jid IS the counterpart (both directions); fall back to the sender jid. For an
  // @lid chat this is the only way a real number reaches the server.
  let counterpartPhone = resolvePhoneJid(chatJid);
  if (!counterpartPhone && senderJid) counterpartPhone = resolvePhoneJid(senderJid);
  const counterpartLid = isLid(chatJid) ? chatJid : (isLid(senderJid) ? senderJid : '');

  return {
    message_id: msgId,
    chat_id: chatJid,
    direction: fromMe ? 'out' : 'in',
    sender: sender,
    timestamp: timestamp,
    text: text || '',
    has_media: hasMedia,
    media: [],
    // AT-133 — resolved real number for server matching; original @lid for audit;
    // resolved flag so the server distinguishes a resolution failure from a
    // genuine non-contact.
    counterpart_phone: counterpartPhone || '',
    counterpart_lid: counterpartLid || '',
    resolved: !!counterpartPhone,
    _t: t,
    _bodyReadable: !!text,
    // AT-135 — fallback join key for the DOM body index (when WA omits data-id):
    // direction + the message's minute (UTC). message_id is the primary join.
    _domKey: (fromMe ? 'out' : 'in') + '|' + (timestamp ? timestamp.slice(0, 16) : ''),
  };
}

/* ── AT-135 — DOM body fallback ───────────────────────────────────────────────
 * WhatsApp stores message bodies ENCRYPTED-AT-REST in IndexedDB (msgRowOpaqueData),
 * so idbExtract gets no plaintext for many messages (_bodyReadable=false). The
 * rendered bubble in the OPEN chat IS plaintext, so we read it READ-ONLY (scrape
 * the already-rendered text; never compose/click/send — AT-44 spec §8) and fill
 * the body before POSTing. Joins to the IndexedDB message by message_id (the
 * data-id's <msgid>, identical to the IndexedDB id) with a direction+minute
 * fallback when WA omits data-id. Only the currently-open chat is in the DOM. */
function buildDomTextIndex() {
  const byId = {}, byKey = {};
  let count = 0;
  const root = document.querySelector(SELECTORS.main);
  if (!root) return { byId, byKey, count };
  for (const metaEl of messageEls(root)) {
    const textEl = metaEl.querySelector(SELECTORS.textSpan);
    const text = textEl ? (textEl.innerText || textEl.textContent || '').trim() : '';
    if (!text) continue;
    count++;
    // primary key: the message id from a data-id ancestor (exact IndexedDB join).
    const idEl = dataIdAncestor(metaEl);
    if (idEl) {
      const parts = (idEl.getAttribute('data-id') || '').split('_');
      if (DATA_ID_RE.test(parts[0] + '_') && parts.length >= 3) {
        const mid = parts.slice(2).join('_');
        if (mid && !byId[mid]) byId[mid] = text;
      }
    }
    // fallback key: direction + minute (UTC), matching idbExtract._domKey.
    const meta = metaEl.getAttribute('data-pre-plain-text') || '';
    const ts = parseTimestamp(meta);
    if (ts) {
      const scope = metaEl.closest('[role="row"]') || metaEl;
      const sm = meta.match(/^\s*\[[^\]]*\]\s*([\s\S]*?):\s*$/);
      const sndr = sm ? sm[1].trim() : '';
      const dir = (scope.querySelector(SELECTORS.outboundTick) || (ownerName && sndr === ownerName)) ? 'out' : 'in';
      const k = dir + '|' + ts.slice(0, 16);
      if (!byKey[k]) byKey[k] = text;
    }
  }
  return { byId, byKey, count };
}

/** Fill an unreadable-body message from the DOM index; flag body_unreadable if not found. */
function applyDomBodyFallback(m, domIndex) {
  if (m._bodyReadable || m.has_media) return; // plaintext/media — fast path unchanged
  const fromDom = domIndex.byId[m.message_id] || domIndex.byKey[m._domKey] || '';
  if (fromDom) {
    m.text = fromDom;
    m._bodyReadable = true;
    m._domFilled = true;
  } else {
    m.body_unreadable = true; // bubble not in the DOM (scrolled out / chat not open)
  }
}

/**
 * AT-133 — read-only @lid → phone resolution probe. For every @lid seen as a
 * chat_id this sweep, scan the contact/lid/wid stores for a record tied to that
 * @lid and report whether a real …@c.us phone (or a phone-ish field) is reachable.
 * Logs in [CoreX WA] style; reads IndexedDB + console only (no writes, no POST).
 */
async function lidResolveProbe(db, chatJids) {
  const lids = Array.from(new Set((chatJids || []).filter(isLid)));
  const stores = Array.from(db.objectStoreNames).filter((s) => /contact|lid|wid/i.test(s));
  log('lidResolve candidate stores=[' + stores.join(',') + '] | @lid chats this sweep=' + lids.length);
  if (!lids.length) { log('lidResolve: no @lid chats seen this sweep — nothing to resolve'); return; }

  const info = {}; // lid -> { pn, store, fields, hints }
  for (const s of stores) {
    const recs = await idbReadAll(db, s, 20000);
    for (const r of recs) {
      const v = r.value || {};
      const keyJid = serId(v.id) || (typeof r.key === 'string' ? r.key : '');
      const recLids = new Set();
      if (isLid(keyJid)) recLids.add(keyJid);
      for (const k of ['lid', 'lidJid', 'id']) { const sj = serId(v[k]); if (isLid(sj)) recLids.add(sj); }
      if (!recLids.size) continue;
      const pn = findPhoneJid(v);
      const hints = ['phoneNumber', 'pn', 'pnJid', 'number', 'formattedNumber', 'wid', 'jid']
        .map((k) => { const val = serId(v[k]); return val ? (k + '=' + val) : null; })
        .filter(Boolean).join(' ');
      for (const L of recLids) {
        if (!info[L]) info[L] = { pn: '', store: s, fields: Object.keys(v).join(','), hints: '' };
        if (pn && !info[L].pn) { info[L].pn = pn; info[L].store = s; }
        if (!info[L].hints && hints) info[L].hints = hints;
      }
    }
  }

  let resolved = 0;
  for (const lid of lids) {
    const it = info[lid];
    if (it && it.pn) {
      resolved++;
      log('lidResolve lid=' + lid + ' → pn=' + it.pn + ' (RESOLVED via store ' + it.store + ' fields=[' + it.fields + '])');
    } else if (it) {
      log('lidResolve lid=' + lid + ' → NO @c.us found. record store=' + it.store + ' fields=[' + it.fields + ']' + (it.hints ? ' hints{' + it.hints + '}' : ' (no phone-ish field)'));
    } else {
      log('lidResolve lid=' + lid + ' → NO matching record in [' + stores.join(',') + '] (masked / not stored)');
    }
  }
  log('lidResolve SUMMARY ' + resolved + '/' + lids.length + ' resolved → ' + (resolved ? 'Q1=YES (auto-resolution viable)' : 'Q1=NO (no reachable phone → manual-link path)'));
}

async function idbSweep(reason) {
  if (idbSweeping) return;
  idbSweeping = true;
  const db = await idbOpen(MODEL_DB);
  try {
    if (!db) { warn('idbSweep[' + reason + '] could NOT open ' + MODEL_DB + ' — is this web.whatsapp.com, logged in?'); return; }
    const msgStore = pickStore(db, ['message', 'messages'], /mess/i);
    const contactStore = pickStore(db, ['contact', 'contacts'], /contact/i);
    if (!msgStore) { warn('idbSweep[' + reason + '] no message store; stores=[' + Array.from(db.objectStoreNames).join(',') + ']'); return; }

    // contact index: jid -> display name (best-effort) + AT-133 jid -> …@c.us phone.
    if (contactStore) {
      const crecs = await idbReadAll(db, contactStore, 5000);
      const idx = {}, phones = {};
      for (const r of crecs) {
        const c = r.value || {};
        const j = serId(c.id) || (typeof r.key === 'string' ? r.key : '');
        const nm = c.name || c.pushname || c.notify || c.shortName || c.verifiedName || c.displayName || '';
        if (j && nm) idx[j] = nm;
        // AT-133 — pair this record's jid with the real …@c.us reachable from it
        // (Q1 proved 26/26 resolve via the contact store's phoneNumber field).
        const pn = findPhoneJid(c);
        if (RE_CUS.test(j)) phones[j] = j;          // a phone contact maps to itself
        if (pn && !phones[j] && j) phones[j] = pn;  // @lid (or other) → its …@c.us
      }
      // A dedicated lid↔pn store on newer WA Web builds (if present) — extra coverage.
      const lidStore = pickStore(db, ['lid-mapping', 'lidmapping', 'lid_pn_map', 'lid'], /lid/i);
      if (lidStore) {
        const lrecs = await idbReadAll(db, lidStore, 20000);
        for (const r of lrecs) {
          const v = r.value || {};
          const keyJid = (typeof r.key === 'string' ? r.key : '') || serId(v.lid) || serId(v.id);
          const lidJid = isLid(keyJid) ? keyJid : (isLid(serId(v.lid)) ? serId(v.lid) : '');
          const pn = findPhoneJid(v);
          if (lidJid && pn && !phones[lidJid]) phones[lidJid] = pn;
        }
      }
      contactNameByJid = idx;
      phoneByJid = phones;
    }

    const { recs, indexed } = await idbReadNewest(db, msgStore, MAX_MSG_SCAN);

    // ONE-TIME schema dump (field names only, no body content) — confirms the
    // real message-store shape so we know we're building against reality.
    if (!schemaDumped && recs.length) {
      schemaDumped = true;
      const v0 = recs[0].value || {};
      log('SCHEMA message store "' + msgStore + '" (newest-via-t-index=' + indexed + ') key=' + JSON.stringify(recs[0].key).slice(0, 120));
      log('SCHEMA fields=[' + Object.keys(v0).join(',') + ']');
      log('SCHEMA body: present=' + ('body' in v0) + ' type=' + typeof v0.body + ' readable=' + (typeof v0.body === 'string' && v0.body.length > 0) +
          ' | msgRowOpaqueData present=' + ('msgRowOpaqueData' in v0) + ' | id sample=' + JSON.stringify(serId(v0.id) || recs[0].key).slice(0, 80));
    }

    // AT-135 — read the OPEN chat's rendered bubbles once (READ-ONLY), to fill the
    // body of any message WhatsApp stored encrypted-at-rest (no plaintext in IDB).
    const domIndex = buildDomTextIndex();

    // group newest-N by chat, send messages after each chat's stored cursor.
    const byChat = {};
    for (const r of recs) {
      const m = idbExtract(r);
      if (!m) continue;
      applyDomBodyFallback(m, domIndex); // AT-135 — fill body from DOM when IDB body is opaque
      (byChat[m.chat_id] = byChat[m.chat_id] || []).push(m);
    }

    // AT-133 — one-time, read-only @lid→phone resolution probe (Q1). Prints a
    // [CoreX WA] lidResolve line per @lid chat seen, so Johan reads it like the
    // idbSweep lines (no console pasting). Reads contact/lid/wid stores only.
    if (!lidProbeDone) { lidProbeDone = true; try { await lidResolveProbe(db, Object.keys(byChat)); } catch (e) { warn('lidResolve outer error: ' + String(e)); } }

    let sent = 0, chatsWithNew = 0, firstSeen = 0, unreadableBodies = 0, domFilled = 0;
    for (const jid of Object.keys(byChat)) {
      const msgs = byChat[jid].sort((a, b) => (a._t - b._t)); // oldest -> newest
      const key = jid; // store cursor keyed by jid
      const seen = lastSeenByChat[key] || null;

      if (!seen) {
        // First sight of this chat: set the cursor to newest and DON'T backfill the
        // whole history (forward-only baseline). Contact-aware history backfill is
        // the next increment; the server still gates archive vs pending per contact.
        const newest = msgs[msgs.length - 1];
        if (newest) recordLastSeen(key, newest.message_id);
        firstSeen++;
        continue;
      }

      const batch = [];
      let newLast = seen, passed = false;
      for (const m of msgs) {
        if (!passed) { if (m.message_id === seen) passed = true; continue; }
        if (m._domFilled) domFilled++;
        if (m.body_unreadable) unreadableBodies++; // AT-135: IDB opaque AND not in the open-chat DOM
        batch.push({ message_id: m.message_id, chat_id: String(m.chat_id), direction: m.direction, sender: m.sender, timestamp: m.timestamp, text: m.text, has_media: m.has_media, media: [], counterpart_phone: m.counterpart_phone || '', counterpart_lid: m.counterpart_lid || '', resolved: !!m.resolved, body_unreadable: !!m.body_unreadable });
        newLast = m.message_id;
      }
      // If the cursor message wasn't in the newest-N window (long-idle chat), the
      // loop sent nothing and passed stayed false — leave the cursor as-is.
      if (batch.length) { recordLastSeen(key, newLast); sent += batch.length; chatsWithNew++; send(batch); }
    }

    log('idbSweep[' + reason + '] store=' + msgStore + ' scanned=' + recs.length +
        ' chats=' + Object.keys(byChat).length + ' firstSeen=' + firstSeen +
        ' sent=' + sent + ' across ' + chatsWithNew + ' chats' +
        ' | AT-135 domBodyFilled=' + domFilled + ' (domBubbles=' + domIndex.count + ')' +
        (unreadableBodies ? ' | ' + unreadableBodies + ' bodies unreadable (IDB opaque + bubble not in open DOM)' : ''));
  } catch (e) {
    warn('idbSweep[' + reason + '] error: ' + String(e));
  } finally {
    if (db) { try { db.close(); } catch (e) {} }
    idbSweeping = false;
  }
}

/* ── History sweep (chat list, contact-aware, paced, READ-ONLY) ──────────────
 * Walks the left chat list one chat at a time. For each chat it resolves the
 * counterpart number against CoreX contacts (lightweight endpoint — the server
 * never ships the contact list to the browser; the extension only learns yes/no
 * about numbers already visible in the agent's own WhatsApp):
 *   • contact     → backfill full visible history (scroll the pane up, capture).
 *   • not contact → forward-only (cursor set to latest; no history backfill —
 *                    POPIA data-minimisation at the source).
 * Read-only: opening a chat is the same click the agent would make by hand; the
 * compose box is never touched and nothing is ever sent. Idle-gated and paced so
 * it never hijacks an active session (spec §8 ToS mitigation).
 */
async function maybeRunHistorySweep() {
  if (!historySweepEnabled || !backfillEnabled || sweepRunning) return; // AT-135 agency toggle
  if (!document.querySelector(SELECTORS.chatListPane)) return; // not on the chat UI yet
  if (!agentIsIdle()) { vlog('history sweep deferred — agent active'); return; }
  await runHistorySweep();
}

/** AT-135 — last-9 (SA core) of a number/jid, for matching against backfill targets. */
function last9(s) {
  const d = String(s || '').replace(/\D/g, '');
  return d.length >= 9 ? d.slice(-9) : '';
}

/** AT-135 — fetch the set of numbers (last-9) with unreadable bodies to backfill. */
async function fetchBackfillTargets() {
  try {
    const resp = await sendAsync({ type: 'WA_BACKFILL_TARGETS' });
    const nums = (resp && resp.ok && resp.body && Array.isArray(resp.body.numbers)) ? resp.body.numbers : [];
    return new Set(nums.map(last9).filter(Boolean));
  } catch (e) { vlog('backfill-targets fetch failed:', String(e)); return new Set(); }
}

async function runHistorySweep() {
  sweepRunning = true;
  const originalChatId = currentChatId();
  let walked = 0, backfilled = 0, skipped = 0;
  try {
    backfillTargets = await fetchBackfillTargets();
    const items = Array.from(document.querySelectorAll(SELECTORS.chatListItem));
    log('history sweep (AT-135 body backfill) — ' + items.length + ' chats; ' + backfillTargets.size +
        ' numbers pending body; cap ' + MAX_BACKFILL_CHATS_PER_RUN + '/run (idle, paced, READ-ONLY)');
    if (!backfillTargets.size) { log('history sweep — nothing pending body backfill; done'); return; }

    for (const item of items) {
      if (backfilled >= MAX_BACKFILL_CHATS_PER_RUN) { log('history sweep — per-run cap reached, will resume next run'); break; }
      if (!historySweepEnabled || !backfillEnabled) break;
      if (!agentIsIdle()) { log('history sweep paused — agent became active'); break; }

      openChat(item);                                  // navigation only (read-only)
      await wait(OPEN_CHAT_RENDER_MS);
      walked++;

      const chatId = currentChatId();
      if (!chatId || chatId.includes('@g.us')) { await wait(betweenChats()); continue; } // skip groups

      // Resolve the chat's REAL number (an @lid chat resolves via AT-133's map).
      const resolved = resolvePhoneJid(chatId) || chatId;
      const n9 = last9(numberFromChatId(resolved) || numberFromChatId(chatId));

      // Only backfill a chat that actually has pending bodies (server-targeted) AND
      // is a CoreX contact (POPIA: never backfill non-contacts).
      if (n9 && backfillTargets.has(n9)) {
        const isContact = await checkContact(numberFromChatId(resolved) || n9);
        if (isContact) {
          await backfillOpenChat(chatId);
          backfilled++;
        } else { skipped++; sweep('history-forward'); }
      } else {
        skipped++;
      }
      await wait(betweenChats());
    }
  } catch (e) {
    warn('history sweep error:', String(e));
  } finally {
    // Restore the agent's original chat so the sweep is invisible to them.
    if (originalChatId) restoreChat(originalChatId);
    sweepRunning = false;
    log('history sweep done — walked ' + walked + ', backfilled ' + backfilled + ', skipped ' + skipped + ' (READ-ONLY: never sent)');
  }
}

async function backfillOpenChat(chatId) {
  const scroller = document.querySelector(SELECTORS.messageList);
  // Scroll up in steps to pull older history into the DOM, sweeping as we go.
  for (let i = 0; i < HISTORY_SCROLL_STEPS; i++) {
    sweep('history-backfill');
    if (!scroller) break;
    const before = scroller.scrollTop;
    scroller.scrollTop = 0; // request older messages
    await wait(HISTORY_SCROLL_PAUSE_MS);
    if (scroller.scrollTop >= before) break; // reached the top — no more history
  }
  sweep('history-backfill-final');
}

function openChat(item) {
  try {
    const target = item.querySelector('[role="gridcell"]') || item.firstElementChild || item;
    target.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
    target.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));
    target.click();
  } catch (e) { vlog('openChat failed:', String(e)); }
}

function restoreChat(chatId) {
  // Re-open the chat that was active before the sweep by matching its list item.
  const items = Array.from(document.querySelectorAll(SELECTORS.chatListItem));
  for (const item of items) {
    openChat(item);
    if (currentChatId() === chatId) return;
  }
}

function numberFromChatId(chatId) {
  if (!chatId || chatId.startsWith('title:')) return '';
  // Group chats (@g.us) have no single counterpart number — skip backfill.
  if (chatId.includes('@g.us')) return '';
  return chatId.includes('@') ? chatId.slice(0, chatId.indexOf('@')) : chatId;
}

/** Ask the server whether a number is a CoreX contact (cached per session). */
async function checkContact(number) {
  if (number in contactCache) return contactCache[number];
  try {
    const resp = await sendAsync({ type: 'WA_CONTACT_CHECK', numbers: [number] });
    const match = !!(resp && resp.ok && resp.body && resp.body.matches && resp.body.matches[number]);
    contactCache[number] = match;
    return match;
  } catch (e) {
    vlog('contact-check failed for', number, '—', String(e));
    return false; // fail closed: no backfill of unverified numbers (data-minimisation)
  }
}

function sendAsync(msg) {
  return new Promise((resolve) => {
    chrome.runtime.sendMessage(msg, (resp) => {
      if (chrome.runtime.lastError) return resolve({ ok: false, error: chrome.runtime.lastError.message });
      resolve(resp);
    });
  });
}

function betweenChats() {
  return BETWEEN_CHATS_MIN_MS + Math.floor(Math.random() * (BETWEEN_CHATS_MAX_MS - BETWEEN_CHATS_MIN_MS));
}
function wait(ms) { return new Promise((r) => setTimeout(r, ms)); }

// React to popup toggles (debug / history sweep) without a reload.
try {
  chrome.storage.onChanged.addListener((changes, area) => {
    if (area !== 'local') return;
    if (changes.waDebug) { DEBUG = !!changes.waDebug.newValue; log('debug →', DEBUG); }
    if (changes.historySweepEnabled) { historySweepEnabled = !!changes.historySweepEnabled.newValue; log('history sweep →', historySweepEnabled); }
  });
} catch (e) { /* storage events optional */ }

/* ── DOM NOTES (verification anchor — v1.1.2, Johan's LIVE DOM 2026-06-15) ─────
 * Real current WA Web text message (classes obfuscated; NO data-id on the bubble):
 *
 *   <div class="x9f619 x1hx0egp …">                          ← obfuscated wrapper
 *     <div class="copyable-text" data-pre-plain-text="[13:57, 6/15/2026] Johan Reichel: ">
 *       <div class="x1n2onr6 …">
 *         <span data-testid="selectable-text" dir="ltr"><span>next test on v1.1.0</span></span>
 *         <div data-testid="msg-meta" role="button"> 13:57 <span data-icon="msg-dblcheck"></span> </div>
 *       </div>
 *     </div>
 *   </div>
 *
 *   Inbound is identical minus the read-receipt tick, with the COUNTERPART's name:
 *     data-pre-plain-text="[13:54, 6/15/2026] Elize Reichel: ".
 *
 * Detector: messageEls() = `#main .copyable-text[data-pre-plain-text]`;
 * extractMessage() parses sender+time from the attr, text from
 * span[data-testid="selectable-text"], direction from the read-receipt tick
 * (which also learns the owner name), and a stable hashed dedup key (no data-id).
 * Verified end-to-end by tests/wa jsdom harness against THIS markup (12/12).
 */
