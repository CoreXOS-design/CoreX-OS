/**
 * CoreX WhatsApp Capture — content script (READ-ONLY).
 *
 * Reads the already-rendered (already-decrypted) WhatsApp Web DOM and extracts
 * messages. It NEVER sends a message, never touches the compose box, never
 * automates anything outbound — it only observes the DOM the user already
 * loaded (and, for the history sweep, opens chats the user could open by hand)
 * and POSTs (via the background worker) to CoreX.
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
function log(...a) { try { console.log(TAG, ...a); } catch (e) {} }
function vlog(...a) { if (DEBUG) { try { console.log(TAG, ...a); } catch (e) {} } }
function warn(...a) { try { console.warn(TAG, ...a); } catch (e) {} }

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

  log('content script loaded on', location.host, '— debug:', DEBUG, '| history sweep:', historySweepEnabled);

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

  attachObserver();
  setInterval(attachObserver, 8000);            // re-attach if WA re-mounts the list
  setInterval(() => sweep('interval'), SWEEP_INTERVAL_MS);
  setTimeout(() => sweep('first-load'), FIRST_SWEEP_MS);
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
      log('heartbeat[' + reason + '] OK ' + resp.status + ' → ' + resp.url + ' | device #' + ((resp.body && resp.body.device_id) || '?') + ' last_seen stamped');
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

/* ── Sweep (focused chat) ────────────────────────────────────────────────── */
function sweep(reason) {
  const root = document.querySelector(SELECTORS.main);
  if (!root) { vlog('sweep skipped — no #main (no chat open)'); return; }

  const chatId = currentChatId();
  if (!chatId) { vlog('sweep skipped — no chat id'); return; }

  const els = messageEls(root);
  vlog('sweep[' + reason + '] chat', chatId, '— matched', els.length, 'message rows');
  if (!els.length) {
    if (reason !== 'observer') warn('0 message rows matched for', chatId, '— if a chat is open, WA DOM may have changed (check SELECTORS).');
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
  if (!historySweepEnabled || sweepRunning) return;
  if (!document.querySelector(SELECTORS.chatListPane)) return; // not on the chat UI yet
  if (!agentIsIdle()) { vlog('history sweep deferred — agent active'); return; }
  await runHistorySweep();
}

async function runHistorySweep() {
  sweepRunning = true;
  const originalChatId = currentChatId();
  let walked = 0, backfilled = 0;
  try {
    const items = Array.from(document.querySelectorAll(SELECTORS.chatListItem));
    log('history sweep starting —', items.length, 'chats in list (idle, paced, read-only)');
    for (const item of items) {
      if (!historySweepEnabled) break;
      if (!agentIsIdle()) { log('history sweep paused — agent became active'); break; }

      openChat(item);                                  // navigation only (read-only)
      await wait(OPEN_CHAT_RENDER_MS);
      walked++;

      const chatId = currentChatId();
      if (!chatId) { await wait(betweenChats()); continue; }

      const number = numberFromChatId(chatId);
      const isContact = number ? await checkContact(number) : false;
      vlog('history sweep chat', chatId, 'number', number, '→ contact:', isContact);

      if (isContact && !lastSeenByChat[chatId]) {
        await backfillOpenChat(chatId);
        backfilled++;
      } else {
        sweep('history-forward'); // contact already cursored, or non-contact: forward-only
      }
      await wait(betweenChats());
    }
  } catch (e) {
    warn('history sweep error:', String(e));
  } finally {
    // Restore the agent's original chat so the sweep is invisible to them.
    if (originalChatId) restoreChat(originalChatId);
    sweepRunning = false;
    log('history sweep done — walked', walked, 'chats, backfilled', backfilled);
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
