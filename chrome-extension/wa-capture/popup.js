/** CoreX WhatsApp Capture — popup config + visible connection status. */
document.addEventListener('DOMContentLoaded', function () {
  const baseUrl = document.getElementById('base-url');
  const token = document.getElementById('device-token');
  const historySweep = document.getElementById('history-sweep');
  const waDebug = document.getElementById('wa-debug');
  const status = document.getElementById('status');
  const conn = document.getElementById('conn');
  const ver = document.getElementById('ver');

  const version = (chrome.runtime.getManifest && chrome.runtime.getManifest().version) || '?';
  ver.textContent = 'Extension v' + version;

  function setConn(text, cls) {
    conn.textContent = text;
    conn.className = 'conn' + (cls ? ' ' + cls : '');
  }

  /**
   * Live connection check — pings the CoreX ping endpoint via the background
   * worker. Shows at a glance whether the extension reaches CoreX AT ALL,
   * independent of WhatsApp message detection. (This ping also stamps the
   * device's last_seen_at server-side.)
   */
  function refreshConnection() {
    setConn('Checking connection…');
    chrome.runtime.sendMessage({ type: 'WA_PING' }, function (resp) {
      if (chrome.runtime.lastError) { setConn('⚠ ' + chrome.runtime.lastError.message, 'bad'); return; }
      if (!resp) { setConn('⚠ No response from background worker.', 'bad'); return; }
      if (resp.error === 'not_configured') { setConn('⚠ Not configured — enter CoreX URL + device token below and Save.'); return; }
      if (resp.ok) {
        const d = resp.body || {};
        setConn('✓ Connected to CoreX — device #' + (d.device_id || '?') +
          (d.last_seen_at ? '\nLast seen: ' + d.last_seen_at : '\nLast seen: just now'), 'ok');
      } else if (resp.status === 401) {
        setConn('✗ Token rejected (401). Paste the CURRENT token from My Portal → WhatsApp Capture (you may be holding a revoked one).\n→ ' + (resp.url || ''), 'bad');
      } else {
        setConn('✗ Failed (' + (resp.status || 'network') + ') ' + (resp.error || '') + '\n→ ' + (resp.url || ''), 'bad');
      }
    });
  }

  chrome.storage.local.get(['baseUrl', 'deviceToken', 'historySweepEnabled', 'waDebug'], function (items) {
    if (items.baseUrl) baseUrl.value = items.baseUrl;
    if (items.deviceToken) token.value = items.deviceToken;
    historySweep.checked = items.historySweepEnabled !== false; // defaults ON
    waDebug.checked = !!items.waDebug;
    status.textContent = (items.baseUrl && items.deviceToken) ? 'Configured.' : 'Not configured yet.';
    if (items.baseUrl && items.deviceToken) refreshConnection();
    else setConn('⚠ Not configured — enter CoreX URL + device token below and Save.');
  });

  document.getElementById('save').addEventListener('click', function () {
    chrome.storage.local.set({
      baseUrl: baseUrl.value.trim(),
      deviceToken: token.value.trim(),
      historySweepEnabled: historySweep.checked,
      waDebug: waDebug.checked,
    }, function () {
      status.textContent = 'Saved. Open web.whatsapp.com to start capturing.';
      refreshConnection(); // re-test with the new settings immediately
    });
  });
});
