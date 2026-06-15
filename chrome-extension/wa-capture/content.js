/**
 * CoreX WhatsApp Capture — content script (READ-ONLY).
 *
 * Reads the already-rendered (already-decrypted) WhatsApp Web DOM and extracts
 * messages. It NEVER sends a message, never touches the compose box, never
 * automates anything outbound — it only observes the DOM the user already
 * loaded (and, for the history sweep, opens chats the user could open by hand)
 * and POSTs (via the background worker) to CoreX.
 *
 * ── AT-44 rewrite ──────────────────────────────────────────────────────────
 * WhatsApp Web shipped a DOM change: the old `#main div.message-in/.message-out`
 * selectors and reading `data-id` off the bubble match NOTHING in current WA
 * Web, so the previous build silently captured zero messages forever
 * (last_seen_at stayed "never"). This rewrite re-pins detection to the current
 * structure and adds debug logging so a silent failure can never recur.
 *
 * Current WA Web structure (verified against a live DOM sample, see DOM NOTES
 * at the bottom of this file):
 *   #main                                    ← open conversation panel
 *     └ div[role="application"]              ← message list (scroll container)
 *         └ div[role="row"]                  ← one wrapper per message (+ system rows)
 *             └ div[data-id="false_<jid>_<id>"]   ← data-id lives HERE, on the
 *                                                   wrapper child, NOT the bubble
 *
 * The `data-id` value is the stable anchor (class names are obfuscated and
 * churn; data-id format has been stable for years):
 *   1:1   :  "{fromMe}_{chatJid}_{msgId}"            false_27821234567@c.us_3EB0…
 *   group :  "{fromMe}_{groupJid}_{msgId}_{authorJid}"
 * fromMe = "true" (outbound) | "false" (inbound) → direction needs no class.
 *
 * Selector churn is isolated to the SELECTORS block on purpose (spec §6). When
 * WA changes the DOM again, update SELECTORS / DATA_ID_RE here; nothing else in
 * CoreX is affected.
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
  messageList: '#main div[role="application"], #main .copyable-area',
  // One wrapper per row; we then look for the data-id child inside.
  messageRow: 'div[role="row"]',
  // The element that actually carries the message data-id.
  dataIdEl: '[data-id]',
  // Sender + timestamp metadata + the text body.
  copyableText: '.copyable-text',
  textSpan: 'span.selectable-text',
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
    const cfg = await chrome.storage.local.get(['waDebug', 'historySweepEnabled', 'lastSeenByChat']);
    DEBUG = !!cfg.waDebug;
    if (typeof cfg.historySweepEnabled === 'boolean') historySweepEnabled = cfg.historySweepEnabled;
    if (cfg.lastSeenByChat && typeof cfg.lastSeenByChat === 'object') {
      Object.assign(lastSeenByChat, cfg.lastSeenByChat);
    }
  } catch (e) { /* storage may be unavailable very early; defaults are fine */ }

  log('content script loaded on', location.host, '— debug:', DEBUG, '| history sweep:', historySweepEnabled);

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
  // Prefer the chat jid from any message data-id — exact and version-stable.
  const el = firstDataIdEl(main);
  if (el) {
    const parts = (el.getAttribute('data-id') || '').split('_');
    if (parts.length >= 2 && parts[1]) return parts[1];
  }
  // Fallback: hash the header title so threads still group if no message is
  // rendered yet (rare). Never blocks capture.
  const header = main.querySelector('header');
  const title = header ? (header.innerText || '').split('\n')[0].trim() : '';
  return title ? 'title:' + title : null;
}

function firstDataIdEl(root) {
  const els = root.querySelectorAll(SELECTORS.dataIdEl);
  for (const el of els) {
    if (DATA_ID_RE.test(el.getAttribute('data-id') || '')) return el;
  }
  return null;
}

/** Return the data-id-bearing element for a row (the row itself or a descendant). */
function dataIdElForRow(row) {
  if (DATA_ID_RE.test(row.getAttribute('data-id') || '')) return row;
  const els = row.querySelectorAll(SELECTORS.dataIdEl);
  for (const el of els) {
    if (DATA_ID_RE.test(el.getAttribute('data-id') || '')) return el;
  }
  return null;
}

/** All message-bearing elements currently in the open chat, in DOM order. */
function messageEls(root) {
  const out = [];
  const seen = new Set();
  // Primary: walk role="row" wrappers and resolve their data-id child.
  const rows = root.querySelectorAll(SELECTORS.messageRow);
  for (const row of rows) {
    const el = dataIdElForRow(row);
    if (el && !seen.has(el)) { seen.add(el); out.push(el); }
  }
  // Fallback: if the role="row" structure ever changes, grab matching data-ids
  // directly so we still capture rather than silently failing.
  if (!out.length) {
    const els = root.querySelectorAll(SELECTORS.dataIdEl);
    for (const el of els) {
      if (DATA_ID_RE.test(el.getAttribute('data-id') || '') && !seen.has(el)) {
        seen.add(el); out.push(el);
      }
    }
  }
  return out;
}

function extractMessage(el, chatId) {
  const dataId = el.getAttribute('data-id') || '';
  const parts = dataId.split('_');                 // {fromMe}_{chatJid}_{msgId}[_{authorJid}]
  const fromMe = parts[0] === 'true';
  // msgId = 3rd segment; for groups a 4th author segment exists — fold it in so
  // the id stays globally unique and stable for server-side dedup.
  const messageId = parts.length >= 3 ? parts.slice(2).join('_') : dataId;
  if (!messageId) return null;

  const copyable = el.querySelector(SELECTORS.copyableText)
    || el.closest('[role="row"]')?.querySelector(SELECTORS.copyableText)
    || null;
  const meta = copyable ? (copyable.getAttribute('data-pre-plain-text') || '') : '';
  // meta: "[HH:MM, YYYY/MM/DD] Sender Name: " (locale-dependent date order).
  let sender = '';
  const senderMatch = meta.match(/\]\s*([^:]+):\s*$/);
  if (senderMatch) sender = senderMatch[1].trim();

  const textEl = el.querySelector(SELECTORS.textSpan)
    || el.closest('[role="row"]')?.querySelector(SELECTORS.textSpan)
    || null;
  // innerText respects WA's line breaks in the browser; textContent is a safe
  // fallback if innerText is unavailable.
  const text = textEl ? (textEl.innerText || textEl.textContent || '') : '';

  const scope = el.closest('[role="row"]') || el;
  const hasMedia = !!scope.querySelector(
    'img[src^="blob:"], [data-testid="audio-play"], [data-testid="media-content"], video, [data-icon="audio-play"]'
  );

  return {
    message_id: messageId,
    chat_id: chatId,
    direction: fromMe ? 'out' : 'in',
    sender: fromMe ? '' : sender,
    timestamp: parseTimestamp(meta),
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
  let year, month, day;
  if (dm[1].length === 4) {            // YYYY/MM/DD
    year = +dm[1]; month = +dm[2]; day = +dm[3];
  } else {                             // DD/MM/YYYY (SA locale) — 2-digit year → 20xx
    day = +dm[1]; month = +dm[2]; year = +dm[3];
    if (year < 100) year += 2000;
  }
  const hh = +tm[1], mm = +tm[2], ss = tm[3] ? +tm[3] : 0;
  if (month < 1 || month > 12 || day < 1 || day > 31) return '';
  const d = new Date(year, month - 1, day, hh, mm, ss);
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

/* ── DOM NOTES (verification anchor) ──────────────────────────────────────────
 * Sample of a current WA Web inbound text message (trimmed):
 *
 *   <div role="row">
 *     <div class="_amjv _aotl" data-id="false_27821234567@c.us_3EB0F1A2B3C4D5">
 *       <div class="message-in focusable-list-item ...">
 *         <div class="copyable-text" data-pre-plain-text="[10:42, 2026/06/15] John Doe: ">
 *           <div class="..."><span class="selectable-text copyable-text"><span>Hi, is the house still available?</span></span></div>
 *         </div>
 *       </div>
 *     </div>
 *   </div>
 *
 * Outbound is identical with data-id="true_…" and class "message-out". The
 * detector keys off role="row" → child [data-id] matching /^(true|false)_/,
 * which both shapes satisfy. Verified: messageEls() returns one element per
 * such row and extractMessage() yields {message_id:"3EB0F1A2B3C4D5",
 * direction:"in", sender:"John Doe", text:"Hi, is the house still available?"}.
 */
