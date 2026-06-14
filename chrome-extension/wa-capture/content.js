/**
 * CoreX WhatsApp Capture — content script (READ-ONLY).
 *
 * Reads the already-rendered (already-decrypted) WhatsApp Web DOM of the open
 * chat and extracts new messages. It NEVER sends a message, never touches the
 * compose box, never automates anything outbound — it only observes the DOM the
 * user has already loaded and POSTs (via the background worker) to CoreX.
 *
 * WhatsApp Web selectors churn frequently — they are isolated here, in this
 * sibling extension, on purpose (per spec §6). Update SELECTORS when WA ships a
 * DOM change; nothing else in CoreX is affected.
 */
const SELECTORS = {
  chatHeader: 'header [data-testid="conversation-info-header"], #main header',
  messageRows: '#main div.message-in, #main div.message-out',
  copyableText: '.copyable-text',
  textSpan: 'span.selectable-text',
};

const SWEEP_INTERVAL_MS = 20000; // human-paced; we read the loaded DOM, not WA servers
const lastSeenByChat = {};       // chatId -> last message id sent this session

function currentChatId() {
  // The active chat id is exposed on several elements across WA versions; try a
  // few, falling back to a hash of the header title so threads still group.
  const main = document.querySelector('#main');
  if (!main) return null;
  const header = main.querySelector('header');
  const title = header ? (header.innerText || '').split('\n')[0].trim() : '';
  // data-id on a message row looks like "false_27821234567@c.us_3EB0..." — the
  // middle segment is the chat jid.
  const row = main.querySelector('[data-id]');
  if (row) {
    const parts = (row.getAttribute('data-id') || '').split('_');
    if (parts.length >= 2 && parts[1]) return parts[1];
  }
  return title ? 'title:' + title : null;
}

function extractMessage(row, chatId) {
  const dataId = row.getAttribute('data-id') || '';
  // data-id: "{fromMe}_{chatJid}_{messageId}"
  const parts = dataId.split('_');
  const fromMe = parts[0] === 'true';
  const messageId = parts.length >= 3 ? parts.slice(2).join('_') : dataId;
  if (!messageId) return null;

  const copyable = row.querySelector(SELECTORS.copyableText);
  const meta = copyable ? (copyable.getAttribute('data-pre-plain-text') || '') : '';
  // meta looks like "[HH:MM, YYYY/MM/DD] Sender Name: "
  let sender = '';
  const senderMatch = meta.match(/\]\s*([^:]+):\s*$/);
  if (senderMatch) sender = senderMatch[1].trim();

  const textEl = row.querySelector(SELECTORS.textSpan);
  const text = textEl ? textEl.innerText : '';

  const hasMedia = !!row.querySelector('img[src^="blob:"], [data-testid="audio-play"], [data-testid="media-content"], video');

  return {
    message_id: messageId,
    chat_id: chatId,
    direction: fromMe ? 'out' : 'in',
    sender: fromMe ? '' : sender,
    timestamp: '', // WA renders local time only; server stamps captured_at, occurred_at best-effort
    text: text || '',
    has_media: hasMedia,
    // NOTE: media bytes are intentionally NOT scraped here in v1 (read-only,
    // privacy-conservative). has_media flags presence; blob upload is a follow-up.
    media: [],
  };
}

function sweep() {
  const chatId = currentChatId();
  if (!chatId) return;

  const rows = Array.from(document.querySelectorAll(SELECTORS.messageRows));
  if (!rows.length) return;

  const seen = lastSeenByChat[chatId] || null;
  const batch = [];
  let newLast = seen;
  let passedSeen = !seen;

  for (const row of rows) {
    const msg = extractMessage(row, chatId);
    if (!msg) continue;
    if (!passedSeen) {
      if (msg.message_id === seen) passedSeen = true;
      continue; // skip everything up to and including the last-seen message
    }
    batch.push(msg);
    newLast = msg.message_id;
  }

  if (batch.length) {
    lastSeenByChat[chatId] = newLast;
    chrome.runtime.sendMessage({ type: 'WA_CAPTURE_BATCH', messages: batch });
  } else if (!seen && rows.length) {
    // First time on this chat: mark the latest as seen so we don't backfill the
    // entire history on every load (only forward capture going on).
    const last = extractMessage(rows[rows.length - 1], chatId);
    if (last) lastSeenByChat[chatId] = last.message_id;
  }
}

setInterval(sweep, SWEEP_INTERVAL_MS);
// Also sweep shortly after load so the first new messages flow without waiting.
setTimeout(sweep, 5000);
