/**
 * CoreX WhatsApp Capture — background service worker.
 *
 * Receives message batches from the content script and POSTs them to the CoreX
 * ingest endpoint with the per-device Bearer token. Mirrors the portal-capture
 * background.js Bearer-POST shape. Sends NOTHING to WhatsApp.
 */
chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  if (!msg) return;
  if (msg.type === 'WA_CAPTURE_BATCH') {
    post('/communications/wa/ingest', { messages: msg.messages })
      .then(sendResponse).catch((e) => sendResponse({ ok: false, error: String(e) }));
    return true; // async response
  }
  if (msg.type === 'WA_CONTACT_CHECK') {
    // AT-44: ask the server which numbers are CoreX contacts (read-only lookup;
    // the contact list never reaches the browser — only yes/no per number).
    post('/communications/wa/contact-check', { numbers: msg.numbers })
      .then(sendResponse).catch((e) => sendResponse({ ok: false, error: String(e) }));
    return true; // async response
  }
  if (msg.type === 'WA_PING') {
    // Liveness heartbeat — authenticated no-op; stamps last_seen_at server-side.
    post('/communications/wa/ping', {})
      .then(sendResponse).catch((e) => sendResponse({ ok: false, error: String(e) }));
    return true; // async response
  }
  if (msg.type === 'WA_BACKFILL_TARGETS') {
    // AT-135: which numbers still have unreadable bodies (read-only GET). Tells the
    // backfill sweep which chats to open. The contact list never reaches the browser.
    get('/communications/wa/backfill-targets')
      .then(sendResponse).catch((e) => sendResponse({ ok: false, error: String(e) }));
    return true; // async response
  }
});

async function get(path) {
  const cfg = await chrome.storage.local.get(['baseUrl', 'deviceToken']);
  const baseUrl = (cfg.baseUrl || '').replace(/\/+$/, '');
  const token = cfg.deviceToken || '';
  const url = baseUrl + path;
  if (!baseUrl || !token) return { ok: false, error: 'not_configured', url: url };
  try {
    const res = await fetch(url, {
      method: 'GET',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Authorization': 'Bearer ' + token },
    });
    let body = null;
    try { body = await res.json(); } catch (e) { /* ignore */ }
    return { ok: res.ok, status: res.status, body: body, url: url };
  } catch (e) {
    return { ok: false, status: 0, error: 'network: ' + String(e), url: url };
  }
}

async function post(path, payload) {
  const cfg = await chrome.storage.local.get(['baseUrl', 'deviceToken']);
  const baseUrl = (cfg.baseUrl || '').replace(/\/+$/, '');
  const token = cfg.deviceToken || '';
  const url = baseUrl + path;

  if (!baseUrl || !token) {
    return { ok: false, error: 'not_configured', url: url };
  }

  try {
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'Authorization': 'Bearer ' + token,
      },
      body: JSON.stringify(payload),
    });

    let body = null;
    try { body = await res.json(); } catch (e) { /* ignore */ }
    // url echoed back so the content-script console shows EXACTLY where it went
    // (catches a misconfigured baseUrl pointing at the wrong host).
    return { ok: res.ok, status: res.status, body: body, url: url };
  } catch (e) {
    // Network/DNS/CORS failure never reaches a response — surface it with the URL.
    return { ok: false, status: 0, error: 'network: ' + String(e), url: url };
  }
}
