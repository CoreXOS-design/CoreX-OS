/**
 * CoreX WhatsApp Capture — background service worker.
 *
 * Receives message batches from the content script and POSTs them to the CoreX
 * ingest endpoint with the per-device Bearer token. Mirrors the portal-capture
 * background.js Bearer-POST shape. Sends NOTHING to WhatsApp.
 */
chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  if (!msg || msg.type !== 'WA_CAPTURE_BATCH') return;
  postBatch(msg.messages).then(sendResponse).catch((e) => sendResponse({ ok: false, error: String(e) }));
  return true; // async response
});

async function postBatch(messages) {
  const cfg = await chrome.storage.local.get(['baseUrl', 'deviceToken']);
  const baseUrl = (cfg.baseUrl || '').replace(/\/+$/, '');
  const token = cfg.deviceToken || '';

  if (!baseUrl || !token) {
    return { ok: false, error: 'not_configured' };
  }

  const res = await fetch(baseUrl + '/communications/wa/ingest', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'Authorization': 'Bearer ' + token,
    },
    body: JSON.stringify({ messages: messages }),
  });

  let body = null;
  try { body = await res.json(); } catch (e) { /* ignore */ }
  return { ok: res.ok, status: res.status, body: body };
}
